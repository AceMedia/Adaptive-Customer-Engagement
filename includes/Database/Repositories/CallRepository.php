<?php
/**
 * Call repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class CallRepository {
	/**
	 * Count calls started today.
	 *
	 * @return int
	 */
	public function count_today(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name( 'calls' ) . ' WHERE DATE(started_at) = UTC_DATE()' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count matched calls.
	 *
	 * @param bool $today Restrict to today.
	 * @return int
	 */
	public function count_matched( bool $today = false ): int {
		global $wpdb;

		$where = 'WHERE (matched_session_id IS NOT NULL OR matched_company_id IS NOT NULL)';

		if ( $today ) {
			$where .= ' AND DATE(started_at) = UTC_DATE()';
		}

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name( 'calls' ) . ' ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count all stored calls.
	 *
	 * @return int
	 */
	public function count_all(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name( 'calls' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get recent calls.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_calls( int $limit = 20 ): array {
		return $this->get_calls( $limit );
	}

	/**
	 * Count calls matching filters.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return int
	 */
	public function count_calls( array $filters = array() ): int {
		global $wpdb;

		$filter_fragments = $this->build_filters( $filters );
		$query            = 'SELECT COUNT(*) FROM ' . Schema::table_name( 'calls' ) . ' c ' . $filter_fragments['where'];
		$total            = empty( $filter_fragments['params'] )
			? $wpdb->get_var( $query ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $query, $filter_fragments['params'] ) );

		return (int) $total;
	}

	/**
	 * Get calls with filters.
	 *
	 * @param int                  $limit   Row limit.
	 * @param array<string, string> $filters Filters.
	 * @param int                  $offset  Row offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_calls( int $limit = 20, array $filters = array(), int $offset = 0 ): array {
		global $wpdb;

		$calls     = Schema::table_name( 'calls' );
		$numbers   = Schema::table_name( 'numbers' );
		$sessions  = Schema::table_name( 'sessions' );
		$companies = Schema::table_name( 'companies' );
		$filter_fragments = $this->build_filters( $filters );
		$params           = $filter_fragments['params'];
		$params[]         = $limit;
		$params[]         = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, n.label AS number_label, s.session_uuid, co.name AS company_name
				FROM {$calls} c
				LEFT JOIN {$numbers} n ON n.id = c.number_id
				LEFT JOIN {$sessions} s ON s.id = c.matched_session_id
				LEFT JOIN {$companies} co ON co.id = c.matched_company_id
				{$filter_fragments['where']}
				ORDER BY c.started_at DESC, c.id DESC
				LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get available stored call statuses.
	 *
	 * @return array<int, string>
	 */
	public function get_statuses(): array {
		global $wpdb;

		$results = $wpdb->get_col( 'SELECT DISTINCT status FROM ' . Schema::table_name( 'calls' ) . " WHERE status IS NOT NULL AND status != '' ORDER BY status ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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
	 * Build common call filter SQL.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return array{where:string,params:array<int,mixed>}
	 */
	private function build_filters( array $filters ): array {
		global $wpdb;

		$where  = array();
		$params = array();

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'c.status = %s';
			$params[] = $filters['status'];
		}

		if ( ! empty( $filters['match_only'] ) ) {
			$where[] = '(c.matched_session_id IS NOT NULL OR c.matched_company_id IS NOT NULL)';
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'DATE(c.started_at) >= %s';
			$params[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'DATE(c.started_at) <= %s';
			$params[] = $filters['date_to'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$search    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]   = '(c.called_number LIKE %s OR c.queue_name LIKE %s OR c.agent_name LIKE %s OR n.label LIKE %s OR s.session_uuid LIKE %s OR co.name LIKE %s)';
			$params[]  = $search;
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
}
