<?php
/**
 * Uninstall handler.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/adaptive-customer-engagement.php';

use ACE\AdaptiveCustomerEngagement\Database\Schema;
use ACE\AdaptiveCustomerEngagement\Security\Capabilities;
use ACE\AdaptiveCustomerEngagement\Settings;

/**
 * Remove all plugin data for a single site.
 *
 * @return void
 */
function ace_adaptive_customer_engagement_uninstall_site(): void {
	Schema::drop_tables();
	delete_option( Settings::OPTION_NAME );
	delete_option( Settings::HASH_SALT_OPTION );
	delete_option( Settings::REPORTING_SEGMENTS_OPTION );
	delete_option( Settings::CONNECT_IMPORT_STATUS_OPTION );
	delete_option( Schema::SCHEMA_VERSION_OPTION );
	wp_clear_scheduled_hook( 'ace_purge_expired_raw_data' );
}

if ( is_multisite() ) {
	$ace_sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

	foreach ( $ace_sites as $ace_site_id ) {
		switch_to_blog( (int) $ace_site_id );
		ace_adaptive_customer_engagement_uninstall_site();
		restore_current_blog();
	}
} else {
	ace_adaptive_customer_engagement_uninstall_site();
}

// Capabilities are added to roles globally; remove once.
Capabilities::remove();
