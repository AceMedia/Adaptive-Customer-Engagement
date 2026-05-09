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
		global $wpdb;

		$calls     = Schema::table_name( 'calls' );
		$numbers   = Schema::table_name( 'numbers' );
		$sessions  = Schema::table_name( 'sessions' );
		$companies = Schema::table_name( 'companies' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, n.label AS number_label, s.session_uuid, co.name AS company_name
				FROM {$calls} c
				LEFT JOIN {$numbers} n ON n.id = c.number_id
				LEFT JOIN {$sessions} s ON s.id = c.matched_session_id
				LEFT JOIN {$companies} co ON co.id = c.matched_company_id
				ORDER BY c.started_at DESC, c.id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
