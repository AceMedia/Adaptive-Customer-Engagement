<?php
/**
 * Session repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class SessionRepository {
	/**
	 * Create or update a session.
	 *
	 * @param string               $session_uuid Session UUID.
	 * @param array<string, mixed> $data         Session data.
	 * @return array<string, mixed>
	 */
	public function create_or_touch( string $session_uuid, array $data ): array {
		global $wpdb;

		$table    = Schema::table_name( 'sessions' );
		$existing = $this->find_by_uuid( $session_uuid );
		$now      = current_time( 'mysql', true );

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'visitor_uuid'      => $data['visitor_uuid'] ?: $existing['visitor_uuid'],
					'last_seen'         => $now,
					'user_agent'        => $data['user_agent'],
					'browser_hash'      => $data['browser_hash'],
					'ip_hash'           => $data['ip_hash'],
					'ip_raw'            => $data['ip_raw'],
					'ip_raw_expires_at' => $data['ip_raw_expires_at'],
					'is_bot'            => $data['is_bot'],
					'ignored'           => $data['ignored'],
					'updated_at'        => $now,
				),
				array( 'id' => $existing['id'] )
			);

			return $this->find_by_uuid( $session_uuid ) ?: $existing;
		}

		$wpdb->insert(
			$table,
			array(
				'session_uuid'       => $session_uuid,
				'visitor_uuid'       => $data['visitor_uuid'],
				'first_seen'         => $now,
				'last_seen'          => $now,
				'first_url'          => $data['first_url'],
				'landing_path'       => $data['landing_path'],
				'referrer'           => $data['referrer'],
				'utm_source'         => $data['utm_source'],
				'utm_medium'         => $data['utm_medium'],
				'utm_campaign'       => $data['utm_campaign'],
				'utm_term'           => $data['utm_term'],
				'utm_content'        => $data['utm_content'],
				'user_agent'         => $data['user_agent'],
				'browser_hash'       => $data['browser_hash'],
				'ip_hash'            => $data['ip_hash'],
				'ip_raw'             => $data['ip_raw'],
				'ip_raw_expires_at'  => $data['ip_raw_expires_at'],
				'company_confidence' => 'unknown',
				'is_bot'             => $data['is_bot'],
				'ignored'            => $data['ignored'],
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);

		return $this->find_by_id( (int) $wpdb->insert_id ) ?: array();
	}

	/**
	 * Find a session by UUID.
	 *
	 * @param string $session_uuid Session UUID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_uuid( string $session_uuid ): ?array {
		global $wpdb;

		$table = Schema::table_name( 'sessions' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_uuid = %s LIMIT 1",
				$session_uuid
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find a session by ID.
	 *
	 * @param int $session_id Session ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_id( int $session_id ): ?array {
		global $wpdb;

		$table = Schema::table_name( 'sessions' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get recent sessions.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_sessions( int $limit = 50, array $filters = array() ): array {
		global $wpdb;

		$sessions  = Schema::table_name( 'sessions' );
		$events    = Schema::table_name( 'events' );
		$companies = Schema::table_name( 'companies' );
		$where     = array();
		$params    = array();

		if ( ! empty( $filters['confidence'] ) ) {
			$where[]  = 's.company_confidence = %s';
			$params[] = $filters['confidence'];
		}

		if ( ! empty( $filters['source'] ) ) {
			$where[]  = 's.utm_source = %s';
			$params[] = $filters['source'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'DATE(s.last_seen) >= %s';
			$params[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'DATE(s.last_seen) <= %s';
			$params[] = $filters['date_to'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$search    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]   = '(s.session_uuid LIKE %s OR s.landing_path LIKE %s OR s.referrer LIKE %s OR c.name LIKE %s)';
			$params[]  = $search;
			$params[]  = $search;
			$params[]  = $search;
			$params[]  = $search;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$params[]  = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.session_uuid, s.visitor_uuid, s.landing_path, s.referrer, s.utm_source, s.utm_campaign, s.company_confidence, s.is_bot, s.ignored, s.last_seen, c.name AS company_name,
					COUNT(e.id) AS event_count,
					SUM(CASE WHEN e.event_type = 'click_to_call' THEN 1 ELSE 0 END) AS call_clicks,
					SUM(CASE WHEN e.event_type = 'download' THEN 1 ELSE 0 END) AS download_count,
					SUM(CASE WHEN e.event_type = 'form_submit' THEN 1 ELSE 0 END) AS form_count
				FROM {$sessions} s
				LEFT JOIN {$events} e ON e.session_id = s.id
				LEFT JOIN {$companies} c ON c.id = s.company_id
				{$where_sql}
				GROUP BY s.id
				ORDER BY s.last_seen DESC
				LIMIT %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get distinct session sources.
	 *
	 * @return array<int, string>
	 */
	public function get_sources(): array {
		global $wpdb;

		$results = $wpdb->get_col( 'SELECT DISTINCT utm_source FROM ' . Schema::table_name( 'sessions' ) . " WHERE utm_source IS NOT NULL AND utm_source != '' ORDER BY utm_source ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_values(
			array_filter(
				array_map(
					static function ( $value ): string {
						return (string) $value;
					},
					is_array( $results ) ? $results : array()
				)
			)
		);
	}

	/**
	 * Get a detailed session view.
	 *
	 * @param int $session_id Session ID.
	 * @return array<string, mixed>|null
	 */
	public function get_session_detail( int $session_id ): ?array {
		global $wpdb;

		$sessions  = Schema::table_name( 'sessions' );
		$events    = Schema::table_name( 'events' );
		$companies = Schema::table_name( 'companies' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, c.name AS company_name, c.domain AS company_domain, c.type AS company_type,
					COUNT(e.id) AS event_count,
					SUM(CASE WHEN e.event_type = 'click_to_call' THEN 1 ELSE 0 END) AS call_clicks,
					SUM(CASE WHEN e.event_type = 'download' THEN 1 ELSE 0 END) AS download_count,
					SUM(CASE WHEN e.event_type = 'form_submit' THEN 1 ELSE 0 END) AS form_count
				FROM {$sessions} s
				LEFT JOIN {$events} e ON e.session_id = s.id
				LEFT JOIN {$companies} c ON c.id = s.company_id
				WHERE s.id = %d
				GROUP BY s.id
				LIMIT 1",
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Assign enrichment data to a session.
	 *
	 * @param int                  $session_id Session ID.
	 * @param array<string, mixed> $data       Enrichment data.
	 * @return void
	 */
	public function update_enrichment( int $session_id, array $data ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table_name( 'sessions' ),
			array(
				'country_code'       => $data['country_code'],
				'region'             => $data['region'],
				'city'               => $data['city'],
				'asn'                => $data['asn'],
				'isp'                => $data['isp'],
				'company_id'         => $data['company_id'],
				'company_confidence' => $data['company_confidence'],
				'updated_at'         => current_time( 'mysql', true ),
			),
			array( 'id' => $session_id )
		);
	}

	/**
	 * Count sessions started today.
	 *
	 * @return int
	 */
	public function count_today(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name( 'sessions' ) . ' WHERE DATE(first_seen) = UTC_DATE()' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count ignored sessions today.
	 *
	 * @return int
	 */
	public function count_ignored_today(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name( 'sessions' ) . ' WHERE DATE(first_seen) = UTC_DATE() AND (ignored = 1 OR is_bot = 1)' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count returning sessions today.
	 *
	 * @return int
	 */
	public function count_returning_today(): int {
		global $wpdb;

		$table = Schema::table_name( 'sessions' );
		$sql   = "SELECT COUNT(*) FROM {$table} s
			WHERE DATE(s.first_seen) = UTC_DATE()
			AND s.visitor_uuid IS NOT NULL
			AND EXISTS (
				SELECT 1 FROM {$table} older
				WHERE older.visitor_uuid = s.visitor_uuid
				AND older.id != s.id
			)";

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count likely or confirmed business visits today.
	 *
	 * @return int
	 */
	public function count_likely_business_today(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . Schema::table_name( 'sessions' ) . " WHERE DATE(first_seen) = UTC_DATE() AND company_confidence IN ('likely', 'confirmed')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
}
