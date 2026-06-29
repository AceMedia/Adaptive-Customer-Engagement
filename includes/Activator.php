<?php
/**
 * Activation handler.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement;

use ACE\AdaptiveCustomerEngagement\Database\Schema;
use ACE\AdaptiveCustomerEngagement\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Activator {
	/**
	 * Activate the plugin.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}

			return;
		}

		self::activate_site();
	}

	/**
	 * Provision the plugin for the current site (tables, caps, cron, settings).
	 *
	 * @return void
	 */
	public static function activate_site(): void {
		Schema::install();
		Capabilities::add();

		if ( ! wp_next_scheduled( 'ace_purge_expired_raw_data' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ace_purge_expired_raw_data' );
		}

		Settings::update( Settings::get() );
		Settings::get_hash_salt();
	}
}
