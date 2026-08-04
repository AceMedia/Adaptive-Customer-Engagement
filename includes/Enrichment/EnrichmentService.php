<?php
/**
 * Enrichment workflow service.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\EnrichmentCacheRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
use ACE\AdaptiveCustomerEngagement\Settings;
use ACE\AdaptiveCustomerEngagement\Tracking\Privacy;

defined( 'ABSPATH' ) || exit;

final class EnrichmentService {
	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Cache repository.
	 *
	 * @var EnrichmentCacheRepository
	 */
	private $cache;

	/**
	 * Company repository.
	 *
	 * @var CompanyRepository
	 */
	private $companies;

	/**
	 * Session repository.
	 *
	 * @var SessionRepository
	 */
	private $sessions;

	/**
	 * Privacy helper.
	 *
	 * @var Privacy
	 */
	private $privacy;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry          $providers Provider registry.
	 * @param EnrichmentCacheRepository $cache     Cache repository.
	 * @param CompanyRepository         $companies Company repository.
	 * @param SessionRepository         $sessions  Session repository.
	 * @param Privacy                   $privacy   Privacy helper.
	 */
	public function __construct( ProviderRegistry $providers, EnrichmentCacheRepository $cache, CompanyRepository $companies, SessionRepository $sessions, Privacy $privacy ) {
		$this->providers = $providers;
		$this->cache     = $cache;
		$this->companies = $companies;
		$this->sessions  = $sessions;
		$this->privacy   = $privacy;
	}

	/**
	 * Enrich a session from an IP address.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $ip         IP address.
	 * @param bool   $is_bot     Bot flag.
	 * @return array<string, mixed>|null
	 */
	public function enrich_session( int $session_id, string $ip, bool $is_bot = false ): ?array {
		$session = $this->sessions->get_session_detail( $session_id );

		if ( ! $session || ! $this->should_enrich( $ip, $is_bot, $session ) ) {
			return null;
		}

		$result = $this->lookup( $ip );

		if ( ! $result ) {
			return null;
		}

		$company    = $this->companies->create_or_touch_from_result( $result );
		$company_id = is_array( $company ) ? (int) $company['id'] : 0;

		$this->sessions->update_enrichment(
			$session_id,
			array(
				'country_code'       => $result->country_code,
				'region'             => $result->region,
				'city'               => $result->city,
				'asn'                => $result->asn,
				'isp'                => $result->isp,
				'company_id'         => $company_id ?: null,
				'company_confidence' => $result->confidence,
			)
		);

		if ( $company_id > 0 && empty( $session['company_id'] ) ) {
			$this->companies->increment_session_totals( $company_id, (int) ( $session['event_count'] ?? 0 ) );
		}

		return $this->format_result( $result, $company_id );
	}

	/**
	 * Run an enrichment test lookup.
	 *
	 * @param string $ip IP address.
	 * @return array<string, mixed>|null
	 */
	public function test_lookup( string $ip ): ?array {
		$result = $this->lookup( $ip );

		return $result ? $this->format_result( $result, 0 ) : null;
	}

	/**
	 * Determine whether enrichment should run.
	 *
	 * @param string               $ip      IP address.
	 * @param bool                 $is_bot  Bot flag.
	 * @param array<string, mixed> $session Session row.
	 * @return bool
	 */
	private function should_enrich( string $ip, bool $is_bot, array $session ): bool {
		$settings = Settings::get();
		$provider = $this->providers->get_active_provider();

		if ( ! $provider->is_configured() || 'none' === $provider->get_name() ) {
			return false;
		}

		if ( $is_bot && empty( $settings['enrichment']['enrich_bots'] ) ) {
			return false;
		}

		if ( $this->privacy->is_private_ip( $ip ) && empty( $settings['enrichment']['enrich_private_ips'] ) ) {
			return false;
		}

		if ( ! empty( $session['company_id'] ) || 'unknown' !== (string) ( $session['company_confidence'] ?? 'unknown' ) ) {
			return false;
		}

		return '' !== $this->privacy->hash_ip( $ip );
	}

	/**
	 * Look up and cache an IP address.
	 *
	 * @param string $ip IP address.
	 * @return EnrichmentResult|null
	 */
	private function lookup( string $ip ): ?EnrichmentResult {
		$provider   = $this->providers->get_active_provider();
		$settings   = Settings::get();
		$ip_hash    = $this->privacy->hash_ip( $ip );
		$cache_days = max( 1, (int) $settings['enrichment']['cache_days'] );

		if ( '' === $ip_hash || ! $provider->is_configured() ) {
			return null;
		}

		$cached = $this->cache->find_valid( $provider->get_name(), $ip_hash );

		if ( $cached ) {
			return $cached;
		}

		$result = $this->augment( $provider->lookup( $ip ), $ip );

		// Errors are cached briefly so a transient provider outage does not
		// pin an empty result against this IP for the full cache window.
		$ttl_days = isset( $result->raw['error'] ) ? 1 : $cache_days;

		$this->cache->upsert( $provider->get_name(), $ip_hash, $result, $ttl_days );

		return $result;
	}

	/**
	 * Augment a provider result with free DNS-derived signals: reverse DNS
	 * as a confidence booster and hosting-ASN/PTR flagging.
	 *
	 * @param EnrichmentResult $result Provider result.
	 * @param string           $ip     IP address.
	 * @return EnrichmentResult
	 */
	private function augment( EnrichmentResult $result, string $ip ): EnrichmentResult {
		if ( $this->privacy->is_private_ip( $ip ) ) {
			return $result;
		}

		$ptr = array_key_exists( 'ptr', $result->raw ) ? $result->raw['ptr'] : DnsIntel::ptr( $ip );

		if ( null !== $ptr ) {
			$result->raw['ptr'] = $ptr;
		}

		if ( true !== $result->is_hosting && ( DnsIntel::is_hosting_asn( DnsIntel::parse_asn( $result->asn ) ) || DnsIntel::is_hosting_host( $ptr ) ) ) {
			$result->is_hosting = true;

			if ( 'likely' === $result->confidence ) {
				$result->confidence = 'weak';
			}
		}

		// A PTR inside the company's own domain confirms the association —
		// but not for the keyless provider, whose domain came from the PTR
		// itself and would self-confirm.
		if ( $ptr && $result->company_domain && 'keyless' !== $result->provider && 'weak' === $result->confidence && true !== $result->is_hosting ) {
			$ptr_domain = DnsIntel::registrable_domain( $ptr );

			if ( $ptr_domain && strtolower( $result->company_domain ) === $ptr_domain ) {
				$result->confidence = 'likely';
			}
		}

		return $result;
	}

	/**
	 * Format a result for REST responses.
	 *
	 * @param EnrichmentResult $result     Enrichment result.
	 * @param int              $company_id Company ID.
	 * @return array<string, mixed>
	 */
	private function format_result( EnrichmentResult $result, int $company_id ): array {
		return array(
			'provider'       => $result->provider,
			'company_id'     => $company_id,
			'company_name'   => $result->company_name,
			'company_domain' => $result->company_domain,
			'company_type'   => $result->company_type,
			'country_code'   => $result->country_code,
			'region'         => $result->region,
			'city'           => $result->city,
			'asn'            => $result->asn,
			'isp'            => $result->isp,
			'confidence'     => $result->confidence,
			'is_hosting'     => $result->is_hosting,
			'is_vpn'         => $result->is_vpn,
			'is_proxy'       => $result->is_proxy,
			'raw'            => $result->raw,
		);
	}
}
