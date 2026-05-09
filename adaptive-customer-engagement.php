<?php
/**
 * Plugin Name:       Adaptive Customer Engagement
 * Plugin URI:        https://acemedia.ninja/
 * Description:       First-party lead tracking, phone routing, and admin insight tooling for WordPress.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Shane Rounce
 * Author URI:        https://acemedia.ninja/
 * Text Domain:       adaptive-customer-engagement
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

defined( 'ABSPATH' ) || exit;

define( 'ACE_PLUGIN_FILE', __FILE__ );
define( 'ACE_PLUGIN_VERSION', '0.1.0' );
define( 'ACE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'ACE\\AdaptiveCustomerEngagement\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$file           = ACE_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'ACE\\AdaptiveCustomerEngagement\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ACE\\AdaptiveCustomerEngagement\\Deactivator', 'deactivate' ) );

\ACE\AdaptiveCustomerEngagement\Plugin::instance()->init();
