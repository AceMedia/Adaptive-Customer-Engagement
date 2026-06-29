<?php
/**
 * Privacy helper methods.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

use ACE\AdaptiveCustomerEngagement\Database\Schema;
use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	/**
	 * Get a client IP.
	 *
	 * @return string
	 */
	public function get_client_ip(): string {
		$keys = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);

		foreach ( $keys as $key ) {
			$value = isset( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : '';

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			$ip = trim( explode( ',', $value )[0] );

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '';
	}

	/**
	 * Hash an IP address.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public function hash_ip( string $ip ): string {
		return '' === $ip ? '' : hash( 'sha256', Settings::get_hash_salt() . '|' . $ip );
	}

	/**
	 * Hash a general value.
	 *
	 * @param string $value Value to hash.
	 * @return string
	 */
	public function hash_value( string $value ): string {
		return '' === $value ? '' : hash( 'sha256', Settings::get_hash_salt() . '|' . $value );
	}

	/**
	 * Determine whether to respect DNT.
	 *
	 * @return bool
	 */
	public function should_respect_dnt(): bool {
		$settings = Settings::get();
		$dnt      = isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '';
		$gpc      = isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '';

		return ! empty( $settings['tracking']['respect_dnt'] ) && ( '1' === $dnt || '1' === $gpc );
	}

	/**
	 * Get the raw IP expiry timestamp.
	 *
	 * @return string
	 */
	public function get_raw_ip_expiry(): string {
		$settings = Settings::get();
		$days     = max( 1, (int) $settings['privacy']['raw_ip_retention_days'] );

		return gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Get the raw caller-number expiry timestamp.
	 *
	 * @return string
	 */
	public function get_raw_phone_expiry(): string {
		$settings = Settings::get();
		$days     = max( 1, (int) $settings['privacy']['raw_phone_retention_days'] );

		return gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Determine whether an IP is private or reserved.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public function is_private_ip( string $ip ): bool {
		if ( '' === $ip ) {
			return true;
		}

		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Purge expired raw data.
	 *
	 * @return array<string, int>
	 */
	public function purge_expired_raw_data(): array {
		global $wpdb;

		$now            = current_time( 'mysql', true );
		$sessions_table = Schema::table_name( 'sessions' );
		$calls_table    = Schema::table_name( 'calls' );

		$sessions = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$sessions_table} SET ip_raw = NULL WHERE ip_raw_expires_at IS NOT NULL AND ip_raw_expires_at < %s",
				$now
			)
		);

		$calls = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$calls_table} SET caller_number_raw = NULL WHERE caller_number_expires_at IS NOT NULL AND caller_number_expires_at < %s",
				$now
			)
		);

		$deleted = $this->purge_expired_records();

		return array_merge(
			array(
				'sessions' => is_int( $sessions ) ? $sessions : 0,
				'calls'    => is_int( $calls ) ? $calls : 0,
			),
			$deleted
		);
	}

	/**
	 * Enforce retention limits by deleting expired rows, plus expired cache entries.
	 *
	 * Runs in batches so a large table never produces one long lock-heavy delete.
	 *
	 * @return array<string, int>
	 */
	public function purge_expired_records(): array {
		global $wpdb;

		$settings = Settings::get();
		$privacy  = is_array( $settings['privacy'] ?? null ) ? $settings['privacy'] : array();

		$session_days = max( 30, (int) ( $privacy['session_retention_days'] ?? 365 ) );
		$bot_days     = max( 1, (int) ( $privacy['bot_retention_days'] ?? 30 ) );

		$session_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $session_days * DAY_IN_SECONDS ) );
		$bot_cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $bot_days * DAY_IN_SECONDS ) );
		$now            = current_time( 'mysql', true );

		$sessions_table   = Schema::table_name( 'sessions' );
		$events_table     = Schema::table_name( 'events' );
		$enrichment_table = Schema::table_name( 'enrichment_cache' );

		$deleted = array(
			'events_deleted'     => $this->delete_in_batches( "DELETE FROM {$events_table} WHERE occurred_at < %s", $session_cutoff ),
			'sessions_deleted'   => $this->delete_in_batches( "DELETE FROM {$sessions_table} WHERE last_seen < %s", $session_cutoff ),
			'bot_sessions_deleted' => $this->delete_in_batches( "DELETE FROM {$sessions_table} WHERE ( is_bot = 1 OR ignored = 1 ) AND last_seen < %s", $bot_cutoff ),
			'enrichment_deleted' => $this->delete_in_batches( "DELETE FROM {$enrichment_table} WHERE expires_at < %s", $now ),
		);

		return $deleted;
	}

	/**
	 * Run a parameterised single-table DELETE in capped batches.
	 *
	 * @param string $sql A DELETE statement ending before any LIMIT, with one %s placeholder.
	 * @param string $arg The bound argument (a cutoff timestamp).
	 * @return int Total rows deleted.
	 */
	private function delete_in_batches( string $sql, string $arg ): int {
		global $wpdb;

		$total      = 0;
		$batch_size = 2000;

		// Cap iterations so a runaway never blocks the cron run (250k rows/run).
		for ( $i = 0; $i < 125; $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql is a controlled internal template; $arg is bound.
			$affected = $wpdb->query( $wpdb->prepare( $sql . ' LIMIT %d', $arg, $batch_size ) );

			if ( ! is_int( $affected ) || $affected <= 0 ) {
				break;
			}

			$total += $affected;

			if ( $affected < $batch_size ) {
				break;
			}
		}

		return $total;
	}
}
