<?php
/**
 * Uninstall handler.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/adaptive-customer-engagement.php';

\ACE\AdaptiveCustomerEngagement\Database\Schema::drop_tables();
delete_option( \ACE\AdaptiveCustomerEngagement\Settings::OPTION_NAME );
delete_option( \ACE\AdaptiveCustomerEngagement\Settings::HASH_SALT_OPTION );
delete_option( \ACE\AdaptiveCustomerEngagement\Database\Schema::SCHEMA_VERSION_OPTION );
