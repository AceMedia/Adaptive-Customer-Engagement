<?php
/**
 * Call repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\DateRange;
use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class CallRepository {
	/**
	 * Marker used for imported Amazon Connect calls.
	 */
	public const CONNECT_IMPORT_MARKER = 'amazon_connect_import';

	/**
	 * Count calls started today.
	 *
	 * @return int
	 */
	public function count_today(): int {
		global $wpdb;

		$bounds = DateRange::today_bounds();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table_name( 'calls' ) . ' WHERE started_at >= %s AND started_at < %s',
				$bounds['start'],
				$bounds['end']
			)
		);
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
			$bounds = DateRange::today_bounds();
			$where .= $wpdb->prepare( ' AND started_at >= %s AND started_at < %s', $bounds['start'], $bounds['end'] );
		}

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name( 'calls' ) . ' ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count matched calls with optional filters.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return int
	 */
	public function count_matched_filtered( array $filters = array() ): int {
		global $wpdb;

		$filter_fragments = $this->build_filters( $filters );
		$query            = 'SELECT COUNT(*) FROM ' . Schema::table_name( 'calls' ) . ' c ' . $filter_fragments['where'];
		$query           .= $filter_fragments['where'] ? ' AND ' : ' WHERE ';
		$query           .= '(c.matched_session_id IS NOT NULL OR c.matched_company_id IS NOT NULL)';

		$total = empty( $filter_fragments['params'] )
			? $wpdb->get_var( $query ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $query, $filter_fragments['params'] ) );

		return (int) $total;
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
	 * Get a detailed call record.
	 *
	 * @param int $call_id Call ID.
	 * @return array<string, mixed>|null
	 */
	public function get_call_detail( int $call_id ): ?array {
		global $wpdb;

		$calls     = Schema::table_name( 'calls' );
		$numbers   = Schema::table_name( 'numbers' );
		$sessions  = Schema::table_name( 'sessions' );
		$companies = Schema::table_name( 'companies' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.*, n.label AS number_label, n.display_number AS tracking_display_number, n.e164_number AS tracking_e164_number,
					n.source_type AS number_source_type, n.source_value AS number_source_value, n.page_match_type, n.page_match_value,
					n.campaign_match, n.is_default AS number_is_default, n.is_active AS number_is_active, s.session_uuid,
					s.landing_path AS session_landing_path, s.utm_source AS session_source, s.last_seen AS session_last_seen,
					co.name AS company_name, co.domain AS company_domain, co.confidence AS company_confidence
				FROM {$calls} c
				LEFT JOIN {$numbers} n ON n.id = c.number_id
				LEFT JOIN {$sessions} s ON s.id = c.matched_session_id
				LEFT JOIN {$companies} co ON co.id = c.matched_company_id
				WHERE c.id = %d
				LIMIT 1",
				$call_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate_call_row( $row ) : null;
	}

	/**
	 * Find a call by Amazon Connect contact ID.
	 *
	 * @param string $contact_id Amazon Connect contact ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_amazon_contact_id( string $contact_id ): ?array {
		global $wpdb;

		if ( '' === $contact_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'calls' ) . ' WHERE amazon_contact_id = %s LIMIT 1',
				$contact_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create or update an imported Amazon Connect call.
	 *
	 * @param array<string, mixed> $data Sanitized call data.
	 * @return array<string, mixed>|null
	 */
	public function upsert_imported_call( array $data ): ?array {
		global $wpdb;

		$table      = Schema::table_name( 'calls' );
		$contact_id = sanitize_text_field( (string) ( $data['amazon_contact_id'] ?? '' ) );

		if ( '' === $contact_id ) {
			return null;
		}

		$existing = $this->find_by_amazon_contact_id( $contact_id );
		$now      = current_time( 'mysql', true );
		$payload  = array(
			'call_uuid'              => sanitize_text_field( (string) ( $data['call_uuid'] ?? '' ) ),
			'amazon_contact_id'      => $contact_id,
			'number_id'              => ! empty( $data['number_id'] ) ? absint( $data['number_id'] ) : null,
			'called_number'          => sanitize_text_field( (string) ( $data['called_number'] ?? '' ) ),
			'caller_number_hash'     => sanitize_text_field( (string) ( $data['caller_number_hash'] ?? '' ) ),
			'caller_number_raw'      => sanitize_text_field( (string) ( $data['caller_number_raw'] ?? '' ) ),
			'caller_number_expires_at' => sanitize_text_field( (string) ( $data['caller_number_expires_at'] ?? '' ) ),
			'started_at'             => sanitize_text_field( (string) ( $data['started_at'] ?? '' ) ),
			'ended_at'               => sanitize_text_field( (string) ( $data['ended_at'] ?? '' ) ),
			'duration_seconds'       => max( 0, (int) ( $data['duration_seconds'] ?? 0 ) ),
			'direction'              => sanitize_key( (string) ( $data['direction'] ?? '' ) ),
			'status'                 => sanitize_text_field( (string) ( $data['status'] ?? '' ) ),
			'queue_name'             => sanitize_text_field( (string) ( $data['queue_name'] ?? '' ) ),
			'agent_name'             => sanitize_text_field( (string) ( $data['agent_name'] ?? '' ) ),
			'matched_session_id'     => ! empty( $data['matched_session_id'] ) ? absint( $data['matched_session_id'] ) : null,
			'matched_company_id'     => ! empty( $data['matched_company_id'] ) ? absint( $data['matched_company_id'] ) : null,
			'match_confidence'       => sanitize_key( (string) ( $data['match_confidence'] ?? 'unknown' ) ),
			'attributes'             => wp_json_encode( $data['attributes'] ?? array() ),
			'notes'                  => self::CONNECT_IMPORT_MARKER,
			'updated_at'             => $now,
		);

		if ( $existing ) {
			unset( $payload['call_uuid'] );
			$wpdb->update( $table, $payload, array( 'id' => (int) $existing['id'] ) );

			return $this->get_call_detail( (int) $existing['id'] );
		}

		$payload['created_at'] = $now;
		$wpdb->insert( $table, $payload );

		return $this->get_call_detail( (int) $wpdb->insert_id );
	}

	/**
	 * Get summary data for imported Amazon Connect calls.
	 *
	 * @return array<string, mixed>
	 */
	public function get_connect_import_summary(): array {
		global $wpdb;

		$table = Schema::table_name( 'calls' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS imported_total,
					SUM(CASE WHEN matched_session_id IS NOT NULL OR matched_company_id IS NOT NULL THEN 1 ELSE 0 END) AS matched_total,
					SUM(CASE WHEN number_id IS NOT NULL AND number_id > 0 THEN 1 ELSE 0 END) AS number_matched_total,
					MAX(updated_at) AS last_imported_at,
					MAX(started_at) AS latest_call_started_at
				FROM {$table}
				WHERE notes = %s",
				self::CONNECT_IMPORT_MARKER
			),
			ARRAY_A
		);

		return array(
			'imported_total'       => (int) ( $row['imported_total'] ?? 0 ),
			'matched_total'        => (int) ( $row['matched_total'] ?? 0 ),
			'number_matched_total' => (int) ( $row['number_matched_total'] ?? 0 ),
			'unmatched_total'      => max( 0, (int) ( $row['imported_total'] ?? 0 ) - (int) ( $row['matched_total'] ?? 0 ) ),
			'last_imported_at'     => sanitize_text_field( (string) ( $row['last_imported_at'] ?? '' ) ),
			'latest_call_started_at' => sanitize_text_field( (string) ( $row['latest_call_started_at'] ?? '' ) ),
		);
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

		return is_array( $rows ) ? array_map( array( $this, 'hydrate_call_row' ), $rows ) : array();
	}

	/**
	 * Get recent calls for a specific tracking number.
	 *
	 * @param int $number_id Number ID.
	 * @param int $limit     Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_calls_by_number( int $number_id, int $limit = 20 ): array {
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
				WHERE c.number_id = %d
				ORDER BY c.started_at DESC, c.id DESC
				LIMIT %d",
				$number_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'hydrate_call_row' ), $rows ) : array();
	}

	/**
	 * Get recent calls matched to a specific session.
	 *
	 * @param int $session_id Session ID.
	 * @param int $limit      Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_calls_by_session( int $session_id, int $limit = 20 ): array {
		return $this->get_calls_by_match_column( 'matched_session_id', $session_id, $limit );
	}

	/**
	 * Get recent calls matched to a specific company.
	 *
	 * @param int $company_id Company ID.
	 * @param int $limit      Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_calls_by_company( int $company_id, int $limit = 20 ): array {
		return $this->get_calls_by_match_column( 'matched_company_id', $company_id, $limit );
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

		if ( ! empty( $filters['connect_import_only'] ) ) {
			$where[]  = 'c.notes = %s';
			$params[] = self::CONNECT_IMPORT_MARKER;
		}

		DateRange::append_filters( $where, $params, 'c.started_at', (string) ( $filters['date_from'] ?? '' ), (string) ( $filters['date_to'] ?? '' ) );

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

	/**
	 * Get recent calls by a matched entity column.
	 *
	 * @param string $column   Match column name.
	 * @param int    $entity_id Entity ID.
	 * @param int    $limit    Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_calls_by_match_column( string $column, int $entity_id, int $limit ): array {
		global $wpdb;

		if ( ! in_array( $column, array( 'matched_session_id', 'matched_company_id' ), true ) ) {
			return array();
		}

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
				WHERE c.{$column} = %d
				ORDER BY c.started_at DESC, c.id DESC
				LIMIT %d",
				$entity_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'hydrate_call_row' ), $rows ) : array();
	}

	/**
	 * Hydrate derived values on a stored call row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function hydrate_call_row( array $row ): array {
		$attributes = json_decode( (string) ( $row['attributes'] ?? '' ), true );
		$attributes = is_array( $attributes ) ? $attributes : array();

		$row['attributes']             = $attributes;
		$row['is_connect_import']      = self::CONNECT_IMPORT_MARKER === (string) ( $row['notes'] ?? '' );
		$row['connect_import_s3_key']  = sanitize_text_field( (string) ( $attributes['s3_key'] ?? '' ) );
		$row['connect_import_channel'] = sanitize_text_field( (string) ( $attributes['channel'] ?? '' ) );

		return $row;
	}
}
