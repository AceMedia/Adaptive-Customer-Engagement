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

		return ! empty( $settings['tracking']['respect_dnt'] ) && '1' === $dnt;
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

		return array(
			'sessions' => is_int( $sessions ) ? $sessions : 0,
			'calls'    => is_int( $calls ) ? $calls : 0,
		);
	}
}
