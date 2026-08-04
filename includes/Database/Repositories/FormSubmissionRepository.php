<?php
/**
 * Form submission repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class FormSubmissionRepository {
	/**
	 * Store a captured form submission.
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::table_name( 'form_submissions' ),
			array(
				'session_id'      => ! empty( $data['session_id'] ) ? (int) $data['session_id'] : null,
				'company_id'      => ! empty( $data['company_id'] ) ? (int) $data['company_id'] : null,
				'form_key'        => $data['form_key'] ?? null,
				'page_url'        => $data['page_url'] ?? null,
				'contact_name'    => $data['contact_name'] ?? null,
				'contact_email'   => $data['contact_email'] ?? null,
				'contact_phone'   => $data['contact_phone'] ?? null,
				'contact_company' => $data['contact_company'] ?? null,
				'fields'          => wp_json_encode( $data['fields'] ?? array() ),
				'mail_sent'       => ! empty( $data['mail_sent'] ) ? 1 : 0,
				'created_at'      => current_time( 'mysql', true ),
			)
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update the company link on a stored submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @param int $company_id    Company ID.
	 * @return void
	 */
	public function set_company( int $submission_id, int $company_id ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table_name( 'form_submissions' ),
			array( 'company_id' => $company_id ),
			array( 'id' => $submission_id )
		);
	}

	/**
	 * List recent submissions with session/company context.
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table     = Schema::table_name( 'form_submissions' );
		$companies = Schema::table_name( 'companies' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, c.name AS company_display_name
				FROM {$table} f
				LEFT JOIN {$companies} c ON c.id = f.company_id
				ORDER BY f.created_at DESC
				LIMIT %d OFFSET %d",
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$fields        = json_decode( (string) ( $row['fields'] ?? '' ), true );
				$row['fields'] = is_array( $fields ) ? $fields : array();

				return $row;
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Count all submissions.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name( 'form_submissions' ) );
	}

	/**
	 * List submissions attached to a session.
	 *
	 * @param int $session_id Session ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_for_session( int $session_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'form_submissions' ) . ' WHERE session_id = %d ORDER BY created_at DESC',
				$session_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
