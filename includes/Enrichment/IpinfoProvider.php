<?php
/**
 * Ipinfo enrichment provider.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

use ACE\AdaptiveCustomerEngagement\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class IpinfoProvider implements ProviderInterface {
	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ipinfo';
	}

	/**
	 * Determine whether the provider is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		$settings = Settings::get();

		return ! empty( $settings['enrichment']['api_key'] );
	}

	/**
	 * Look up an IP address.
	 *
	 * @param string $ip IP address.
	 * @return EnrichmentResult
	 */
	public function lookup( string $ip ): EnrichmentResult {
		$settings = Settings::get();
		$api_key  = (string) ( $settings['enrichment']['api_key'] ?? '' );
		$url      = sprintf( 'https://ipinfo.io/%s/json?token=%s', rawurlencode( $ip ), rawurlencode( $api_key ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error_result( $response );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return $this->error_result( new WP_Error( 'ace_enrichment_invalid_response', 'Invalid enrichment response.' ) );
		}

		$result                 = new EnrichmentResult();
		$result->provider       = $this->get_name();
		$result->company_name   = isset( $data['company']['name'] ) ? sanitize_text_field( (string) $data['company']['name'] ) : ( isset( $data['asn']['name'] ) ? sanitize_text_field( (string) $data['asn']['name'] ) : null );
		$result->company_domain = isset( $data['company']['domain'] ) ? sanitize_text_field( (string) $data['company']['domain'] ) : ( isset( $data['asn']['domain'] ) ? sanitize_text_field( (string) $data['asn']['domain'] ) : null );
		$result->company_type   = isset( $data['company']['type'] ) ? sanitize_text_field( (string) $data['company']['type'] ) : ( isset( $data['asn']['type'] ) ? sanitize_text_field( (string) $data['asn']['type'] ) : null );
		$result->country_code   = isset( $data['country'] ) ? sanitize_text_field( (string) $data['country'] ) : null;
		$result->region         = isset( $data['region'] ) ? sanitize_text_field( (string) $data['region'] ) : null;
		$result->city           = isset( $data['city'] ) ? sanitize_text_field( (string) $data['city'] ) : null;
		$result->asn            = isset( $data['asn']['asn'] ) ? sanitize_text_field( (string) $data['asn']['asn'] ) : null;
		$result->isp            = isset( $data['org'] ) ? sanitize_text_field( (string) $data['org'] ) : null;
		$result->is_hosting     = isset( $data['privacy']['hosting'] ) ? (bool) $data['privacy']['hosting'] : null;
		$result->is_vpn         = isset( $data['privacy']['vpn'] ) ? (bool) $data['privacy']['vpn'] : null;
		$result->is_proxy       = isset( $data['privacy']['proxy'] ) ? (bool) $data['privacy']['proxy'] : null;
		$result->confidence     = $this->map_confidence( $result );
		$result->raw            = $data;

		return $result;
	}

	/**
	 * Map confidence from the provider response.
	 *
	 * @param EnrichmentResult $result Enrichment result.
	 * @return string
	 */
	private function map_confidence( EnrichmentResult $result ): string {
		$company_type = strtolower( (string) $result->company_type );
		$org_name     = strtolower( (string) $result->company_name );
		$weak_names   = array( 'amazon', 'google', 'microsoft', 'cloudflare', 'virgin media', 'bt', 'sky' );

		if ( $result->is_proxy || $result->is_vpn ) {
			return 'ignore';
		}

		if ( $result->company_name && $result->company_domain && ! $result->is_hosting ) {
			if ( in_array( $company_type, array( 'business', 'government', 'education', 'organization', 'organisation' ), true ) ) {
				return 'likely';
			}

			foreach ( $weak_names as $weak_name ) {
				if ( false !== strpos( $org_name, $weak_name ) ) {
					return 'weak';
				}
			}

			return 'likely';
		}

		if ( $result->isp ) {
			return 'weak';
		}

		return 'unknown';
	}

	/**
	 * Build an error result.
	 *
	 * @param WP_Error $error Error.
	 * @return EnrichmentResult
	 */
	private function error_result( WP_Error $error ): EnrichmentResult {
		$result           = new EnrichmentResult();
		$result->provider = $this->get_name();
		$result->raw      = array(
			'error' => $error->get_error_message(),
		);

		return $result;
	}
}
