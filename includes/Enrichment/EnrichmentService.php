<?php
/**
 * Enrichment workflow service.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\EnrichmentCacheRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\IpCompanyMemoryRepository;
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
	 * IP-to-company memory repository.
	 *
	 * @var IpCompanyMemoryRepository|null
	 */
	private $ip_memory;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry               $providers Provider registry.
	 * @param EnrichmentCacheRepository      $cache     Cache repository.
	 * @param CompanyRepository              $companies Company repository.
	 * @param SessionRepository              $sessions  Session repository.
	 * @param Privacy                        $privacy   Privacy helper.
	 * @param IpCompanyMemoryRepository|null $ip_memory Locally learned IP-to-company hints.
	 */
	public function __construct( ProviderRegistry $providers, EnrichmentCacheRepository $cache, CompanyRepository $companies, SessionRepository $sessions, Privacy $privacy, ?IpCompanyMemoryRepository $ip_memory = null ) {
		$this->providers = $providers;
		$this->cache     = $cache;
		$this->companies = $companies;
		$this->sessions  = $sessions;
		$this->privacy   = $privacy;
		$this->ip_memory = $ip_memory;
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

		if ( ! $session ) {
			return null;
		}

		// Deterministic local knowledge (form submissions, orders) beats any
		// provider lookup: a returning visitor from a known IP is relabelled
		// with the company we have already confirmed.
		$recalled = $this->recall_from_memory( $session_id, $ip, $is_bot, $session );

		if ( null !== $recalled ) {
			return $recalled;
		}

		if ( ! $this->should_enrich( $ip, $is_bot, $session ) ) {
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
	 * Re-enrich a session whose earlier result was weak or unknown, using a
	 * stronger provider than the one that produced it. Bypasses the
	 * "already has a company" gate but keeps bot/private-IP rules and the
	 * memory-recall precedence. Never downgrades confirmed sessions.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $ip         IP address.
	 * @return array<string, mixed>|null
	 */
	public function reenrich_session( int $session_id, string $ip ): ?array {
		$session = $this->sessions->get_session_detail( $session_id );

		if ( ! $session || 'confirmed' === (string) ( $session['company_confidence'] ?? '' ) ) {
			return null;
		}

		$recalled = $this->recall_from_memory( $session_id, $ip, false, array_merge( $session, array( 'company_id' => null ) ) );

		if ( null !== $recalled ) {
			return $recalled;
		}

		$settings = Settings::get();
		$provider = $this->providers->get_active_provider();

		if ( ! $provider->is_configured() || 'none' === $provider->get_name() ) {
			return null;
		}

		if ( $this->privacy->is_private_ip( $ip ) && empty( $settings['enrichment']['enrich_private_ips'] ) ) {
			return null;
		}

		$result = $this->lookup( $ip );

		if ( ! $result || isset( $result->raw['error'] ) ) {
			return null;
		}

		$company    = $this->companies->create_or_touch_from_result( $result );
		$company_id = is_array( $company ) ? (int) $company['id'] : 0;
		$previous   = (int) ( $session['company_id'] ?? 0 );

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

		if ( $company_id > 0 && $company_id !== $previous ) {
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
	 * Assign a company remembered against this IP from a previous form
	 * submission, order, or chat declaration.
	 *
	 * @param int                  $session_id Session ID.
	 * @param string               $ip         IP address.
	 * @param bool                 $is_bot     Bot flag.
	 * @param array<string, mixed> $session    Session row.
	 * @return array<string, mixed>|null Assignment summary, or null when no memory applies.
	 */
	private function recall_from_memory( int $session_id, string $ip, bool $is_bot, array $session ): ?array {
		if ( null === $this->ip_memory || $is_bot || ! empty( $session['company_id'] ) ) {
			return null;
		}

		if ( 'confirmed' === (string) ( $session['company_confidence'] ?? '' ) ) {
			return null;
		}

		$ip_hash = $this->privacy->hash_ip( $ip );

		if ( '' === $ip_hash ) {
			return null;
		}

		$hint       = $this->ip_memory->find_by_ip_hash( $ip_hash );
		$company_id = is_array( $hint ) ? (int) ( $hint['company_id'] ?? 0 ) : 0;

		if ( $company_id <= 0 ) {
			return null;
		}

		$confidence = sanitize_key( (string) ( $hint['confidence'] ?? '' ) ) ?: 'likely';

		$this->sessions->assign_company( $session_id, $company_id, $confidence );
		$this->companies->increment_session_totals( $company_id, (int) ( $session['event_count'] ?? 0 ) );

		return array(
			'provider'       => 'ip_memory',
			'company_id'     => $company_id,
			'company_name'   => (string) ( $hint['company_name'] ?? '' ),
			'company_domain' => (string) ( $hint['company_domain'] ?? '' ),
			'confidence'     => $confidence,
			'source'         => (string) ( $hint['source'] ?? '' ),
		);
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
			// Re-augment cached results so classification improvements apply
			// to entries cached before the rules changed; augment() is
			// deterministic and cheap on a cached payload.
			return $this->augment( $cached, $ip );
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

		// RDAP registrant contacts sometimes hold a private individual's
		// name rather than an organisation; never store those as companies.
		if ( 'keyless' === $result->provider && $result->company_name && DnsIntel::looks_like_person( $result->company_name ) ) {
			$result->company_name              = null;
			$result->raw['scrubbed_person']    = true;

			if ( 'likely' === $result->confidence ) {
				$result->confidence = $result->isp ? 'weak' : 'unknown';
			}
		}

		// PeeringDB knows what the network says it is (consumer ISP, transit,
		// hosting, enterprise); that authoritative typing beats name keywords.
		$asn_type = AsnIntel::network_type( DnsIntel::parse_asn( $result->asn ) );

		if ( null !== $asn_type ) {
			$result->raw['asn_type'] = $asn_type;
		}

		if ( 'hosting' === $asn_type && true !== $result->is_hosting ) {
			$result->is_hosting = true;

			if ( 'likely' === $result->confidence ) {
				$result->confidence = 'weak';
			}
		}

		// The org that owns an IP range is usually the network operator, not
		// the business browsing through it: type ISPs/carriers and security
		// gateways and keep them out of the likely-business bucket.
		$kind = ( 'isp' === strtolower( (string) $result->company_type ) )
			? 'isp'
			: DnsIntel::network_kind( $result->company_name, $result->isp, $result->company_domain );

		if ( 'proxy' !== $kind ) {
			if ( 'isp' === $asn_type ) {
				$kind = 'isp';
			} elseif ( null !== $asn_type && 'hosting' !== $asn_type ) {
				// PeeringDB says end-user organisation — clear a keyword
				// false positive such as a business with "mobile" in its name.
				$kind = null;
			}
		}

		if ( null !== $kind ) {
			$result->raw['network_kind'] = $kind;

			if ( null === $result->company_type || '' === $result->company_type ) {
				$result->company_type = $kind;
			}

			if ( 'likely' === $result->confidence ) {
				$result->confidence = 'weak';
			}
		}

		// An end-user organisation browsing from its own registered network
		// is the strongest keyless business signal there is.
		if ( null === $kind
			&& in_array( $asn_type, array( 'business', 'education', 'government', 'organisation' ), true )
			&& $result->company_name
			&& true !== $result->is_hosting
			&& true !== $result->is_vpn
			&& true !== $result->is_proxy ) {
			if ( null === $result->company_type || '' === $result->company_type ) {
				$result->company_type = $asn_type;
			}

			if ( 'weak' === $result->confidence ) {
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
