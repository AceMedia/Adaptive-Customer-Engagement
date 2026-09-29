<?php
/** Idempotent transfer of historical ACE Forms entries into engagement reporting. */
namespace ACE\AdaptiveCustomerEngagement\Migration;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\FormSubmissionRepository;
use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class AceEntriesImporter {
	/** Report or import archived ACE/CFDB7 entries for the current site. */
	public static function run( bool $dry_run = true ): array {
		global $wpdb;
		$source = $wpdb->prefix . 'ace_form_entries';
		$forms = $wpdb->prefix . 'ace_forms';
		$meta = $wpdb->prefix . 'ace_form_entry_meta';
		$destination = Schema::table_name( 'form_submissions' );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $source ) ) ) {
			return array( 'error' => 'ACE Forms entry table is unavailable.' );
		}
		$report = array( 'source' => 0, 'imported' => 0, 'existing' => 0, 'skipped_recent' => 0, 'failed' => 0 );
		$repo = new FormSubmissionRepository();
		$last_id = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT e.*,f.name AS form_name FROM {$source} e LEFT JOIN {$forms} f ON f.form_id=e.form_id WHERE e.id > %d ORDER BY e.id LIMIT 200",
				$last_id
			), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$last_id = (int) $row['id'];
				++$report['source'];
				$context = json_decode( (string) ( $row['context'] ?? '' ), true );
				// Fresh ACE captures were also sent to this plugin by the former action
				// integration. Their history is already present here without a source ID.
				$is_cfdb7 = 'cfdb7' === ( $context['source'] ?? '' );
				$is_local = 'local' === ( $context['storage_provider'] ?? '' );
				if ( ! $is_cfdb7 && ! $is_local ) {
					++$report['skipped_recent'];
					continue;
				}
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$destination} WHERE source_entry_id=%d", $last_id ) );
				if ( $existing ) {
					++$report['existing'];
					continue;
				}
				if ( $dry_run ) {
					++$report['imported'];
					continue;
				}
				$fields = json_decode( (string) $row['data'], true );
				$fields = is_array( $fields ) ? $fields : array();
				$phone = '';
				$company = '';
				foreach ( $fields as $key => $value ) {
					if ( ! is_scalar( $value ) ) {
						continue;
					}
					$key = strtolower( (string) $key );
					if ( '' === $phone && preg_match( '/phone|tel|mobile|number/', $key ) ) {
						$phone = sanitize_text_field( (string) $value );
					}
					if ( '' === $company && preg_match( '/company|business|organisation/', $key ) ) {
						$company = sanitize_text_field( (string) $value );
					}
				}
				$cfdb7_id = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$meta} WHERE entry_id=%d AND meta_key='cfdb7_id' LIMIT 1", $last_id ) );
				$details = array( 'source' => $is_cfdb7 ? 'cfdb7' : 'ace-local', 'legacy_entry_id' => $last_id, 'legacy_cfdb7_id' => $cfdb7_id, 'legacy_status' => $row['status'] );
				if ( $is_local ) {
					$details['files'] = array();
					foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$meta} WHERE entry_id=%d", $last_id ), ARRAY_A ) as $record ) {
						$value = json_decode( (string) $record['meta_value'], true );
						if ( 'mail_log' === $record['meta_key'] && is_array( $value ) ) {
							$details['notification'] = $value;
						} elseif ( 'confirmation_log' === $record['meta_key'] && is_array( $value ) ) {
							$details['confirmation'] = $value;
						} elseif ( 0 === strpos( $record['meta_key'], 'file_' ) && is_array( $value ) ) {
							$details['files'][] = $value;
						}
					}
				}
				$id = $repo->insert( array(
					'form_id' => (string) $row['form_id'],
					'source_entry_id' => $last_id,
					'form_key' => sanitize_text_field( (string) ( $row['form_name'] ?: 'Legacy form' ) ),
					'page_url' => esc_url_raw( (string) ( $context['page_url'] ?? '' ) ),
					'contact_name' => mb_substr( (string) $row['contact_name'], 0, 150 ),
					'contact_email' => (string) $row['contact_email'],
					'contact_phone' => mb_substr( $phone, 0, 50 ),
					'contact_company' => mb_substr( $company, 0, 255 ),
					'fields' => $fields,
					'mail_sent' => ! empty( $details['notification']['sent'] ),
					'created_at' => (string) $row['created_at'],
					'details' => $details,
				) );
				if ( $id > 0 ) {
					++$report['imported'];
				} else {
					++$report['failed'];
					$report['failed_ids'][] = $last_id;
					$report['last_error'] = $wpdb->last_error;
				}
			}
		} while ( count( (array) $rows ) === 200 );
		return $report;
	}

	/** WP-CLI entry point. Runs as a dry run unless --apply is supplied. */
	public static function cli( array $args, array $assoc_args ): void {
		$report = self::run( ! isset( $assoc_args['apply'] ) );
		\WP_CLI::line( wp_json_encode( $report ) );
		if ( ! empty( $report['error'] ) || ! empty( $report['failed'] ) ) {
			\WP_CLI::error( 'ACE entry transfer did not finish cleanly.' );
		}
	}
}
