<?php
/**
 * Session repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\DateRange;
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
	public function get_recent_sessions( int $limit = 50, array $filters = array(), int $offset = 0 ): array {
		global $wpdb;

		$sessions          = Schema::table_name( 'sessions' );
		$events            = Schema::table_name( 'events' );
		$companies         = Schema::table_name( 'companies' );
		$filter_fragments  = $this->build_recent_session_filters( $filters );
		$where_sql         = $filter_fragments['where'];
		$params            = $filter_fragments['params'];
		$params[]          = $limit;
		$params[]          = $offset;

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
				LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get top traffic sources.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_top_sources( int $limit = 8, array $filters = array() ): array {
		global $wpdb;

		$filter_fragments = $this->build_recent_session_filters( $filters );
		$params           = $filter_fragments['params'];
		$params[]         = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE(NULLIF(s.utm_source, ''), '__direct__') AS source_key,
					COALESCE(NULLIF(s.utm_source, ''), 'Direct / none') AS source_label,
					COUNT(*) AS total
				FROM " . Schema::table_name( 'sessions' ) . " s
				LEFT JOIN " . Schema::table_name( 'companies' ) . " c ON c.id = s.company_id
				" . $filter_fragments['where'] . "
				GROUP BY source_key, source_label
				ORDER BY total DESC
				LIMIT %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count recent sessions matching filters.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return int
	 */
	public function count_recent_sessions( array $filters = array() ): int {
		global $wpdb;

		$filter_fragments = $this->build_recent_session_filters( $filters );
		$query            = 'SELECT COUNT(DISTINCT s.id) FROM ' . Schema::table_name( 'sessions' ) . ' s LEFT JOIN ' . Schema::table_name( 'companies' ) . ' c ON c.id = s.company_id ' . $filter_fragments['where'];
		$total            = empty( $filter_fragments['params'] )
			? $wpdb->get_var( $query ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $query, $filter_fragments['params'] ) );

		return (int) $total;
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
	 * Build common session filter SQL.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return array{where:string,params:array<int,mixed>}
	 */
	private function build_recent_session_filters( array $filters ): array {
		global $wpdb;

		$where  = array();
		$params = array();

		if ( ! empty( $filters['confidence'] ) ) {
			$where[]  = 's.company_confidence = %s';
			$params[] = $filters['confidence'];
		}

		if ( ! empty( $filters['source'] ) ) {
			if ( '__direct__' === $filters['source'] ) {
				$where[] = "(s.utm_source IS NULL OR s.utm_source = '')";
			} else {
				$where[]  = 's.utm_source = %s';
				$params[] = $filters['source'];
			}
		}

		DateRange::append_filters( $where, $params, 's.last_seen', (string) ( $filters['date_from'] ?? '' ), (string) ( $filters['date_to'] ?? '' ) );

		if ( ! empty( $filters['search'] ) ) {
			$search    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]   = '(s.session_uuid LIKE %s OR s.landing_path LIKE %s OR s.referrer LIKE %s OR c.name LIKE %s OR EXISTS (SELECT 1 FROM ' . Schema::table_name( 'events' ) . ' e_search WHERE e_search.session_id = s.id AND e_search.path LIKE %s))';
			$params[]  = $search;
			$params[]  = $search;
			$params[]  = $search;
			$params[]  = $search;
			$params[]  = $search;
		}

		return array(
			'where'  => $where ? 'WHERE ' . implode( ' AND ', $where ) : '',
			'params' => $params,
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
	 * Assign a company match to a session without overwriting location data.
	 *
	 * @param int    $session_id  Session ID.
	 * @param int    $company_id  Company ID.
	 * @param string $confidence  Match confidence.
	 * @return void
	 */
	public function assign_company( int $session_id, int $company_id, string $confidence = 'likely' ): void {
		global $wpdb;

		if ( $session_id <= 0 || $company_id <= 0 ) {
			return;
		}

		$wpdb->update(
			Schema::table_name( 'sessions' ),
			array(
				'company_id'         => $company_id,
				'company_confidence' => sanitize_key( $confidence ) ?: 'likely',
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

		$bounds = DateRange::today_bounds();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table_name( 'sessions' ) . ' WHERE first_seen >= %s AND first_seen < %s',
				$bounds['start'],
				$bounds['end']
			)
		);
	}

	/**
	 * Count ignored sessions today.
	 *
	 * @return int
	 */
	public function count_ignored_today(): int {
		global $wpdb;

		$bounds = DateRange::today_bounds();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table_name( 'sessions' ) . ' WHERE first_seen >= %s AND first_seen < %s AND (ignored = 1 OR is_bot = 1)',
				$bounds['start'],
				$bounds['end']
			)
		);
	}

	/**
	 * Count returning sessions today.
	 *
	 * @return int
	 */
	public function count_returning_today(): int {
		global $wpdb;

		$table  = Schema::table_name( 'sessions' );
		$bounds = DateRange::today_bounds();
		$sql    = "SELECT COUNT(*) FROM {$table} s
			WHERE s.first_seen >= %s
			AND s.first_seen < %s
			AND s.visitor_uuid IS NOT NULL
			AND EXISTS (
				SELECT 1 FROM {$table} older
				WHERE older.visitor_uuid = s.visitor_uuid
				AND older.id != s.id
			)";

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				$sql,
				$bounds['start'],
				$bounds['end']
			)
		);
	}

	/**
	 * Count likely or confirmed business visits today.
	 *
	 * @return int
	 */
	public function count_likely_business_today(): int {
		global $wpdb;

		$bounds = DateRange::today_bounds();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table_name( 'sessions' ) . " WHERE first_seen >= %s AND first_seen < %s AND company_confidence IN ('likely', 'confirmed')",
				$bounds['start'],
				$bounds['end']
			)
		);
	}

	/**
	 * Count ignored sessions with optional filters.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return int
	 */
	public function count_ignored( array $filters = array() ): int {
		return $this->count_by_filters( $filters, '(s.ignored = 1 OR s.is_bot = 1)' );
	}

	/**
	 * Count returning sessions with optional filters.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return int
	 */
	public function count_returning( array $filters = array() ): int {
		global $wpdb;

		$filter_fragments = $this->build_recent_session_filters( $filters );
		$query            = 'SELECT COUNT(*) FROM ' . Schema::table_name( 'sessions' ) . ' s LEFT JOIN ' . Schema::table_name( 'companies' ) . ' c ON c.id = s.company_id ' . $filter_fragments['where'];
		$query           .= $filter_fragments['where'] ? ' AND ' : ' WHERE ';
		$query           .= 's.visitor_uuid IS NOT NULL AND EXISTS (SELECT 1 FROM ' . Schema::table_name( 'sessions' ) . ' older WHERE older.visitor_uuid = s.visitor_uuid AND older.id != s.id)';

		$total = empty( $filter_fragments['params'] )
			? $wpdb->get_var( $query ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $query, $filter_fragments['params'] ) );

		return (int) $total;
	}

	/**
	 * Count likely business sessions with optional filters.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return int
	 */
	public function count_likely_business( array $filters = array() ): int {
		return $this->count_by_filters( $filters, "s.company_confidence IN ('likely', 'confirmed')" );
	}

	/**
	 * Count sessions with optional extra conditions.
	 *
	 * @param array<string, string> $filters         Filters.
	 * @param string                $extra_condition Extra SQL condition.
	 * @return int
	 */
	private function count_by_filters( array $filters, string $extra_condition ): int {
		global $wpdb;

		$filter_fragments = $this->build_recent_session_filters( $filters );
		$query            = 'SELECT COUNT(DISTINCT s.id) FROM ' . Schema::table_name( 'sessions' ) . ' s LEFT JOIN ' . Schema::table_name( 'companies' ) . ' c ON c.id = s.company_id ' . $filter_fragments['where'];
		$query           .= $filter_fragments['where'] ? ' AND ' : ' WHERE ';
		$query           .= $extra_condition;

		$total = empty( $filter_fragments['params'] )
			? $wpdb->get_var( $query ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $query, $filter_fragments['params'] ) );

		return (int) $total;
	}
}
