<?php
/**
 * Enrichment cache repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;
use ACE\AdaptiveCustomerEngagement\Enrichment\EnrichmentResult;

defined( 'ABSPATH' ) || exit;

final class EnrichmentCacheRepository {
	/**
	 * Get a valid cached lookup.
	 *
	 * @param string $provider Provider name.
	 * @param string $ip_hash  IP hash.
	 * @return EnrichmentResult|null
	 */
	public function find_valid( string $provider, string $ip_hash ): ?EnrichmentResult {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT response FROM ' . Schema::table_name( 'enrichment_cache' ) . ' WHERE provider = %s AND ip_hash = %s AND expires_at >= %s LIMIT 1',
				$provider,
				$ip_hash,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row['response'] ) ) {
			return null;
		}

		$data = json_decode( (string) $row['response'], true );

		return is_array( $data ) ? EnrichmentResult::from_array( $data ) : null;
	}

	/**
	 * Upsert a cached result.
	 *
	 * @param string           $provider   Provider name.
	 * @param string           $ip_hash    IP hash.
	 * @param EnrichmentResult $result     Enrichment result.
	 * @param int              $cache_days Cache days.
	 * @return void
	 */
	public function upsert( string $provider, string $ip_hash, EnrichmentResult $result, int $cache_days ): void {
		global $wpdb;

		$wpdb->replace(
			Schema::table_name( 'enrichment_cache' ),
			array(
				'provider'       => $provider,
				'ip_hash'        => $ip_hash,
				'response'       => wp_json_encode( $result->to_array() ),
				'company_name'   => $result->company_name,
				'company_domain' => $result->company_domain,
				'company_type'   => $result->company_type,
				'asn'            => $result->asn,
				'isp'            => $result->isp,
				'confidence'     => $result->confidence,
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + ( max( 1, $cache_days ) * DAY_IN_SECONDS ) ),
				'created_at'     => current_time( 'mysql', true ),
			)
		);
	}
}
