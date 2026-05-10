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
	private const SAMPLE_MARKER = 'ace_sample_data';

	/**
	 * Get all numbers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::table_name( 'numbers' ) . ' ORDER BY is_default DESC, priority ASC, id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $this->normalise_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Get active numbers in match order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function active(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'numbers' ) . ' WHERE is_active = 1 AND source_value != %s ORDER BY is_default ASC, priority ASC, id DESC',
				self::SAMPLE_MARKER
			),
			ARRAY_A
		);

		return $this->normalise_rows( is_array( $rows ) ? $rows : array() );
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

		return is_array( $row ) ? $this->normalise_row( $row ) : null;
	}

	/**
	 * Find a number by Amazon Connect phone number ID.
	 *
	 * @param string $phone_number_id Amazon Connect phone number ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_connect_phone_number_id( string $phone_number_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'numbers' ) . ' WHERE amazon_connect_phone_number_id = %s LIMIT 1',
				$phone_number_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalise_row( $row ) : null;
	}

	/**
	 * Find a number by exact E.164 value.
	 *
	 * @param string $e164_number E.164 phone number.
	 * @return array<string, mixed>|null
	 */
	public function find_by_e164_number( string $e164_number ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'numbers' ) . ' WHERE e164_number = %s LIMIT 1',
				$e164_number
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalise_row( $row ) : null;
	}

	/**
	 * Get a detailed number record with call aggregates.
	 *
	 * @param int $number_id Number ID.
	 * @return array<string, mixed>|null
	 */
	public function get_detail( int $number_id ): ?array {
		global $wpdb;

		$numbers = Schema::table_name( 'numbers' );
		$calls   = Schema::table_name( 'calls' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT n.*,
					COUNT(c.id) AS total_calls,
					SUM(CASE WHEN c.matched_session_id IS NOT NULL OR c.matched_company_id IS NOT NULL THEN 1 ELSE 0 END) AS matched_calls,
					COUNT(DISTINCT c.matched_company_id) AS matched_companies,
					SUM(COALESCE(c.duration_seconds, 0)) AS total_duration_seconds,
					MAX(c.started_at) AS last_call_started_at
				FROM {$numbers} n
				LEFT JOIN {$calls} c ON c.number_id = n.id
				WHERE n.id = %d
				GROUP BY n.id
				LIMIT 1",
				$number_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalise_row( $row ) : null;
	}

	/**
	 * Get setup summary counts for Connect readiness checks.
	 *
	 * @return array<string, int>
	 */
	public function get_setup_summary(): array {
		global $wpdb;

		$table = Schema::table_name( 'numbers' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_numbers,
					SUM(CASE WHEN source_value = %s THEN 1 ELSE 0 END) AS sample_numbers,
					SUM(CASE WHEN source_value != %s AND is_active = 1 THEN 1 ELSE 0 END) AS active_numbers,
					SUM(CASE WHEN source_value != %s AND is_default = 1 THEN 1 ELSE 0 END) AS default_numbers,
					SUM(CASE WHEN source_value != %s AND is_active = 1 AND is_default = 1 THEN 1 ELSE 0 END) AS active_default_numbers,
					SUM(CASE WHEN source_value != %s AND COALESCE(amazon_connect_phone_number_id, '') <> '' THEN 1 ELSE 0 END) AS numbers_with_connect_phone_id,
					SUM(CASE WHEN source_value != %s AND COALESCE(amazon_connect_contact_flow_id, '') <> '' THEN 1 ELSE 0 END) AS numbers_with_connect_flow_id
				FROM {$table}",
				self::SAMPLE_MARKER,
				self::SAMPLE_MARKER,
				self::SAMPLE_MARKER,
				self::SAMPLE_MARKER,
				self::SAMPLE_MARKER,
				self::SAMPLE_MARKER
			),
			ARRAY_A
		);

		return array(
			'total_numbers'                 => isset( $row['total_numbers'] ) ? (int) $row['total_numbers'] : 0,
			'sample_numbers'                => isset( $row['sample_numbers'] ) ? (int) $row['sample_numbers'] : 0,
			'active_numbers'                => isset( $row['active_numbers'] ) ? (int) $row['active_numbers'] : 0,
			'default_numbers'               => isset( $row['default_numbers'] ) ? (int) $row['default_numbers'] : 0,
			'active_default_numbers'        => isset( $row['active_default_numbers'] ) ? (int) $row['active_default_numbers'] : 0,
			'numbers_with_connect_phone_id' => isset( $row['numbers_with_connect_phone_id'] ) ? (int) $row['numbers_with_connect_phone_id'] : 0,
			'numbers_with_connect_flow_id'  => isset( $row['numbers_with_connect_flow_id'] ) ? (int) $row['numbers_with_connect_flow_id'] : 0,
		);
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

	/**
	 * Normalise rows with origin flags.
	 *
	 * @param array<int, array<string, mixed>> $rows Number rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalise_rows( array $rows ): array {
		return array_map( array( $this, 'normalise_row' ), $rows );
	}

	/**
	 * Normalise a number row with origin flags.
	 *
	 * @param array<string, mixed> $row Number row.
	 * @return array<string, mixed>
	 */
	private function normalise_row( array $row ): array {
		$is_sample         = isset( $row['source_value'] ) && self::SAMPLE_MARKER === (string) $row['source_value'];
		$is_connect_linked = ! empty( $row['amazon_connect_phone_number_id'] );
		$record_origin     = $is_sample ? 'sample' : ( $is_connect_linked ? 'connect_linked' : 'local' );
		$origin_label      = 'sample' === $record_origin ? __( 'Sample data', 'adaptive-customer-engagement' ) : ( 'connect_linked' === $record_origin ? __( 'Connect-linked', 'adaptive-customer-engagement' ) : __( 'Local rule', 'adaptive-customer-engagement' ) );

		$row['is_sample']         = $is_sample;
		$row['is_connect_linked'] = $is_connect_linked;
		$row['record_origin']     = $record_origin;
		$row['origin_label']      = $origin_label;

		return $row;
	}
}
