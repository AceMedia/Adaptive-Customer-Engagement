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

	/**
	 * Get companies ordered by activity.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_companies( int $limit = 100 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'companies' ) . ' WHERE ignored = 0 ORDER BY last_seen DESC, total_sessions DESC, total_events DESC LIMIT %d',
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get recent hot companies for the dashboard.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_hot_companies( int $limit = 10 ): array {
		global $wpdb;

		$companies = Schema::table_name( 'companies' );
		$sessions  = Schema::table_name( 'sessions' );
		$events    = Schema::table_name( 'events' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.name, c.domain, c.type, c.confidence, c.first_seen, c.last_seen, c.total_sessions, c.total_events, c.total_calls,
					COUNT(DISTINCT s.id) AS session_count,
					COUNT(e.id) AS page_views
				FROM {$companies} c
				LEFT JOIN {$sessions} s ON s.company_id = c.id
				LEFT JOIN {$events} e ON e.session_id = s.id AND e.event_type = 'pageview'
				WHERE c.ignored = 0
				GROUP BY c.id
				ORDER BY c.last_seen DESC, c.total_sessions DESC, c.total_events DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get a company detail view including recent sessions.
	 *
	 * @param int $company_id Company ID.
	 * @return array<string, mixed>|null
	 */
	public function get_company_detail( int $company_id ): ?array {
		$company = $this->find( $company_id );

		if ( ! $company ) {
			return null;
		}

		global $wpdb;

		$sessions_table = Schema::table_name( 'sessions' );
		$events_table   = Schema::table_name( 'events' );
		$sessions       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.session_uuid, s.visitor_uuid, s.landing_path, s.referrer, s.utm_source, s.utm_campaign, s.company_confidence, s.is_bot, s.ignored, s.last_seen,
					COUNT(e.id) AS event_count,
					SUM(CASE WHEN e.event_type = 'click_to_call' THEN 1 ELSE 0 END) AS call_clicks,
					SUM(CASE WHEN e.event_type = 'download' THEN 1 ELSE 0 END) AS download_count,
					SUM(CASE WHEN e.event_type = 'form_submit' THEN 1 ELSE 0 END) AS form_count
				FROM {$sessions_table} s
				LEFT JOIN {$events_table} e ON e.session_id = s.id
				WHERE s.company_id = %d
				GROUP BY s.id
				ORDER BY s.last_seen DESC
				LIMIT 20",
				$company_id
			),
			ARRAY_A
		);

		$company['recent_sessions'] = is_array( $sessions ) ? $sessions : array();

		return $company;
	}
}
