<?php
/**
 * Phone number repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class NumberRepository {
	/**
	 * Get all numbers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::table_name( 'numbers' ) . ' ORDER BY is_default DESC, priority ASC, id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get active numbers in match order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function active(): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::table_name( 'numbers' ) . ' WHERE is_active = 1 ORDER BY is_default ASC, priority ASC, id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find a number.
	 *
	 * @param int $number_id Number ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $number_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'numbers' ) . ' WHERE id = %d LIMIT 1',
				$number_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create a number.
	 *
	 * @param array<string, mixed> $data Number data.
	 * @return array<string, mixed>|null
	 */
	public function create( array $data ): ?array {
		global $wpdb;

		$this->reset_default_flag( $data );

		$wpdb->insert(
			Schema::table_name( 'numbers' ),
			array_merge(
				$data,
				array(
					'created_at' => current_time( 'mysql', true ),
					'updated_at' => current_time( 'mysql', true ),
				)
			)
		);

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Update a number.
	 *
	 * @param int                  $number_id Number ID.
	 * @param array<string, mixed> $data      Number data.
	 * @return array<string, mixed>|null
	 */
	public function update( int $number_id, array $data ): ?array {
		global $wpdb;

		$this->reset_default_flag( $data, $number_id );

		$wpdb->update(
			Schema::table_name( 'numbers' ),
			array_merge(
				$data,
				array(
					'updated_at' => current_time( 'mysql', true ),
				)
			),
			array( 'id' => $number_id )
		);

		return $this->find( $number_id );
	}

	/**
	 * Delete a number.
	 *
	 * @param int $number_id Number ID.
	 * @return bool
	 */
	public function delete( int $number_id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Schema::table_name( 'numbers' ), array( 'id' => $number_id ) );
	}

	/**
	 * Ensure only one default number exists.
	 *
	 * @param array<string, mixed> $data       Number data.
	 * @param int                  $exclude_id Excluded number ID.
	 * @return void
	 */
	private function reset_default_flag( array $data, int $exclude_id = 0 ): void {
		global $wpdb;

		if ( empty( $data['is_default'] ) ) {
			return;
		}

		$sql = 'UPDATE ' . Schema::table_name( 'numbers' ) . ' SET is_default = 0 WHERE is_default = 1';

		if ( $exclude_id > 0 ) {
			$sql = $wpdb->prepare( "{$sql} AND id != %d", $exclude_id );
		}

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
