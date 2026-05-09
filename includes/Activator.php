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
	 * @return void
	 */
	public static function activate(): void {
		Schema::install();
		Capabilities::add();

		if ( ! wp_next_scheduled( 'ace_purge_expired_raw_data' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ace_purge_expired_raw_data' );
		}

		Settings::update( Settings::get() );
		Settings::get_hash_salt();
	}
}
