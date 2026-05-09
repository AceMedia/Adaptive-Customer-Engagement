<?php
/**
 * Deactivation handler.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement;

use ACE\AdaptiveCustomerEngagement\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Deactivator {
	/**
	 * Deactivate the plugin.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Capabilities::remove();
		wp_clear_scheduled_hook( 'ace_purge_expired_raw_data' );
	}
}
