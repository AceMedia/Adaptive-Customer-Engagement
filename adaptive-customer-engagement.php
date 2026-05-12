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

if ( ! function_exists( 'ace_adaptive_customer_engagement_make_local_url' ) ) {
	/**
	 * Convert a local WordPress URL to a root-relative URL so mapped multisite subsites
	 * do not accidentally inherit the primary site domain for plugin assets or AJAX calls.
	 *
	 * @param string $url Absolute or relative URL.
	 * @return string
	 */
	function ace_adaptive_customer_engagement_make_local_url( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$relative = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			$relative .= '?' . (string) $parts['query'];
		}

		if ( isset( $parts['fragment'] ) && '' !== (string) $parts['fragment'] ) {
			$relative .= '#' . (string) $parts['fragment'];
		}

		return '' !== $relative ? $relative : '/';
	}
}

define( 'ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_FILE', __FILE__ );
define( 'ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_VERSION', '0.1.0' );
define( 'ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_URL', ace_adaptive_customer_engagement_make_local_url( plugin_dir_url( __FILE__ ) ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'ACE\\AdaptiveCustomerEngagement\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$file           = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'ACE\\AdaptiveCustomerEngagement\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ACE\\AdaptiveCustomerEngagement\\Deactivator', 'deactivate' ) );

\ACE\AdaptiveCustomerEngagement\Plugin::instance()->init();
