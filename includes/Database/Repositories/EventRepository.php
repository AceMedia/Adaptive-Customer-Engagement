<?php
/**
 * Event repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class EventRepository {
	/**
	 * Insert an event.
	 *
	 * @param int                  $session_id Session ID.
	 * @param array<string, mixed> $data       Event payload.
	 * @return int
	 */
	public function insert( int $session_id, array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::table_name( 'events' ),
			array(
				'event_uuid'       => $data['event_uuid'],
				'session_id'       => $session_id,
				'event_type'       => $data['event_type'],
				'event_name'       => $data['event_name'],
				'url'              => $data['url'],
				'path'             => $data['path'],
				'page_title'       => $data['page_title'],
				'post_id'          => $data['post_id'],
				'post_type'        => $data['post_type'],
				'taxonomy_context' => $data['taxonomy_context'],
				'product_area'     => $data['product_area'],
				'brand_context'    => $data['brand_context'],
				'number_id'        => $data['number_id'],
				'metadata'         => wp_json_encode( $data['metadata'] ),
				'occurred_at'      => current_time( 'mysql', true ),
				'created_at'       => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Count click-to-call events today.
	 *
	 * @return int
	 */
	public function count_click_to_call_today(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name( 'events' ) . " WHERE DATE(occurred_at) = UTC_DATE() AND event_type = 'click_to_call'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Top pageview paths.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_top_pages( int $limit = 5 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT path, COUNT(*) AS total FROM ' . Schema::table_name( 'events' ) . " WHERE event_type = 'pageview' AND path IS NOT NULL AND path != '' GROUP BY path ORDER BY total DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
