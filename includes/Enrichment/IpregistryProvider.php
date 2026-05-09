<?php
/**
 * Ipregistry enrichment provider.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

use ACE\AdaptiveCustomerEngagement\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class IpregistryProvider implements ProviderInterface {
	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ipregistry';
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
		$url      = sprintf( 'https://api.ipregistry.co/%s?key=%s', rawurlencode( $ip ), rawurlencode( $api_key ) );
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

		$result               = new EnrichmentResult();
		$result->provider     = $this->get_name();
		$result->company_name = isset( $data['company']['name'] ) ? sanitize_text_field( (string) $data['company']['name'] ) : null;
		$result->company_domain = isset( $data['company']['domain'] ) ? sanitize_text_field( (string) $data['company']['domain'] ) : null;
		$result->company_type = isset( $data['company']['type'] ) ? sanitize_text_field( (string) $data['company']['type'] ) : null;
		$result->country_code = isset( $data['location']['country']['code'] ) ? sanitize_text_field( (string) $data['location']['country']['code'] ) : null;
		$result->region       = isset( $data['location']['region']['name'] ) ? sanitize_text_field( (string) $data['location']['region']['name'] ) : null;
		$result->city         = isset( $data['location']['city'] ) ? sanitize_text_field( (string) $data['location']['city'] ) : null;
		$result->asn          = isset( $data['connection']['asn'] ) ? sanitize_text_field( (string) $data['connection']['asn'] ) : null;
		$result->isp          = isset( $data['connection']['organization'] ) ? sanitize_text_field( (string) $data['connection']['organization'] ) : null;
		$result->is_hosting   = isset( $data['connection']['type'] ) ? 'hosting' === sanitize_key( (string) $data['connection']['type'] ) : null;
		$result->is_vpn       = isset( $data['security']['is_vpn'] ) ? (bool) $data['security']['is_vpn'] : null;
		$result->is_proxy     = isset( $data['security']['is_proxy'] ) ? (bool) $data['security']['is_proxy'] : null;
		$result->confidence   = $this->map_confidence( $result );
		$result->raw          = $data;

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
