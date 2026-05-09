<?php
/**
 * Company repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;
use ACE\AdaptiveCustomerEngagement\Enrichment\EnrichmentResult;

defined( 'ABSPATH' ) || exit;

final class CompanyRepository {
	/**
	 * Find or create a company record from enrichment data.
	 *
	 * @param EnrichmentResult $result Enrichment result.
	 * @return array<string, mixed>|null
	 */
	public function create_or_touch_from_result( EnrichmentResult $result ): ?array {
		global $wpdb;

		if ( empty( $result->company_name ) ) {
			return null;
		}

		$table = Schema::table_name( 'companies' );
		$row   = null;

		if ( ! empty( $result->company_domain ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE domain = %s LIMIT 1",
					$result->company_domain
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $row ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE name = %s LIMIT 1",
					$result->company_name
				),
				ARRAY_A
			);
		}

		$now = current_time( 'mysql', true );

		if ( is_array( $row ) ) {
			$wpdb->update(
				$table,
				array(
					'name'            => $result->company_name,
					'domain'          => $result->company_domain,
					'type'            => $result->company_type,
					'country_code'    => $result->country_code,
					'source_provider' => $result->provider,
					'source_payload'  => wp_json_encode( $result->raw ),
					'confidence'      => $result->confidence,
					'last_seen'       => $now,
					'updated_at'      => $now,
				),
				array( 'id' => (int) $row['id'] )
			);

			return $this->find( (int) $row['id'] );
		}

		$wpdb->insert(
			$table,
			array(
				'name'            => $result->company_name,
				'domain'          => $result->company_domain,
				'type'            => $result->company_type,
				'country_code'    => $result->country_code,
				'source_provider' => $result->provider,
				'source_payload'  => wp_json_encode( $result->raw ),
				'confidence'      => $result->confidence,
				'first_seen'      => $now,
				'last_seen'       => $now,
				'total_sessions'  => 0,
				'total_events'    => 0,
				'total_calls'     => 0,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Increment company session counters.
	 *
	 * @param int $company_id   Company ID.
	 * @param int $event_count  Event count.
	 * @return void
	 */
	public function increment_session_totals( int $company_id, int $event_count ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table_name( 'companies' ) . ' SET total_sessions = total_sessions + 1, total_events = total_events + %d, last_seen = %s, updated_at = %s WHERE id = %d',
				max( 0, $event_count ),
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$company_id
			)
		);
	}

	/**
	 * Find a company.
	 *
	 * @param int $company_id Company ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $company_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'companies' ) . ' WHERE id = %d LIMIT 1',
				$company_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
