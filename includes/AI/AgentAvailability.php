<?php
/**
 * Chat agent availability tracking.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

defined( 'ABSPATH' ) || exit;

final class AgentAvailability {
	private const OPTION_NAME = 'ace_chat_admin_watchers';
	private const TTL_SECONDS = 45;

	/**
	 * Mark an admin user as actively watching chats.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	public static function mark_watching( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return self::get_status();
		}

		$watchers            = self::read_watchers();
		$watchers[ $user_id ] = time();
		self::write_watchers( $watchers );

		return self::get_status();
	}

	/**
	 * Stop tracking an admin user as watching chats.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	public static function stop_watching( int $user_id ): array {
		$watchers = self::read_watchers();

		if ( isset( $watchers[ $user_id ] ) ) {
			unset( $watchers[ $user_id ] );
			self::write_watchers( $watchers );
		}

		return self::get_status();
	}

	/**
	 * Get public chat availability status.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		$watchers = self::read_watchers();

		return array(
			'online'        => ! empty( $watchers ),
			'watcher_count' => count( $watchers ),
			'checked_at'    => gmdate( 'c' ),
			'ttl_seconds'   => self::TTL_SECONDS,
		);
	}

	/**
	 * Read and prune watcher state.
	 *
	 * @return array<int, int>
	 */
	private static function read_watchers(): array {
		$stored       = get_option( self::OPTION_NAME, array() );
		$watchers     = array();
		$now          = time();
		$has_changes  = false;

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		foreach ( $stored as $user_id => $last_seen ) {
			$user_id   = absint( $user_id );
			$last_seen = absint( $last_seen );

			if ( $user_id <= 0 || $last_seen <= 0 || ( $now - $last_seen ) > self::TTL_SECONDS ) {
				$has_changes = true;
				continue;
			}

			$watchers[ $user_id ] = $last_seen;
		}

		if ( $has_changes ) {
			self::write_watchers( $watchers );
		}

		return $watchers;
	}

	/**
	 * Persist watcher state.
	 *
	 * @param array<int, int> $watchers Watcher map.
	 * @return void
	 */
	private static function write_watchers( array $watchers ): void {
		if ( empty( $watchers ) ) {
			delete_option( self::OPTION_NAME );
			return;
		}

		update_option( self::OPTION_NAME, $watchers, false );
	}
}
