<?php
/**
 * Admin menu and asset loading.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Admin;

use ACE\AdaptiveCustomerEngagement\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Menu {
	/**
	 * Registered page hooks.
	 *
	 * @var array<string, string>
	 */
	private $hooks = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	/**
	 * Register menu pages.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$pages = array(
			'dashboard'      => __( 'Dashboard', 'adaptive-customer-engagement' ),
			'sessions'       => __( 'Sessions', 'adaptive-customer-engagement' ),
			'companies'      => __( 'Companies', 'adaptive-customer-engagement' ),
			'commerce'       => __( 'WooCommerce', 'adaptive-customer-engagement' ),
			'calls'          => __( 'Calls', 'adaptive-customer-engagement' ),
			'numbers'        => __( 'Phone Numbers', 'adaptive-customer-engagement' ),
			'enrichment'     => __( 'Enrichment', 'adaptive-customer-engagement' ),
			'amazon-connect' => __( 'Amazon Connect', 'adaptive-customer-engagement' ),
			'ai-agent'       => __( 'AI Agent', 'adaptive-customer-engagement' ),
			'privacy'        => __( 'Privacy', 'adaptive-customer-engagement' ),
			'settings'       => __( 'Settings', 'adaptive-customer-engagement' ),
		);

		$top_slug                = 'ace-dashboard';
		$this->hooks['dashboard'] = add_menu_page(
			__( 'Adaptive Engagement', 'adaptive-customer-engagement' ),
			__( 'Adaptive Engagement', 'adaptive-customer-engagement' ),
			Capabilities::MANAGE,
			$top_slug,
			function (): void {
				$this->render_page( 'dashboard' );
			},
			'dashicons-chart-line',
			56
		);

		foreach ( $pages as $page => $label ) {
			$slug                 = 'dashboard' === $page ? $top_slug : 'ace-' . $page;
			$this->hooks[ $page ] = add_submenu_page(
				$top_slug,
				$label,
				$label,
				Capabilities::MANAGE,
				$slug,
				function () use ( $page ): void {
					$this->render_page( $page );
				}
			);
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		$asset_file = ACE_PLUGIN_DIR . 'assets/build/admin.asset.php';
		$script_src = ACE_PLUGIN_URL . 'assets/build/admin.js';
		$style_src  = ACE_PLUGIN_URL . 'assets/build/style-admin.css';
		$style_file = ACE_PLUGIN_DIR . 'assets/build/style-admin.css';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			'version'      => ACE_PLUGIN_VERSION,
		);

		if ( file_exists( $style_file ) ) {
			wp_enqueue_style( 'ace-admin', $style_src, array( 'wp-components' ), $asset['version'] );
			wp_add_inline_style(
				'ace-admin',
				'body.ace-admin-screen #wpbody-content > .notice,
				body.ace-admin-screen #wpbody-content > .updated,
				body.ace-admin-screen #wpbody-content > .error,
				body.ace-admin-screen #wpbody-content > .update-nag,
				body.ace-admin-screen #wpbody-content > .is-dismissible,
				body.ace-admin-screen #wpbody-content > div[class*="notice"] { display: none !important; }'
			);
		}

		wp_enqueue_script( 'ace-admin', $script_src, $asset['dependencies'], $asset['version'], true );
		wp_add_inline_script(
			'ace-admin',
			'window.ACEAdminConfig = ' . wp_json_encode(
				array(
					'root'         => esc_url_raw( rest_url() ),
					'namespace'    => 'adaptive-customer-engagement/v1',
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'exportNonce'  => wp_create_nonce( 'ace_export_report' ),
					'adminUrl'     => esc_url_raw( admin_url( 'admin.php' ) ),
					'adminPostUrl' => esc_url_raw( admin_url( 'admin-post.php' ) ),
					'page'         => sanitize_key( (string) str_replace( 'ace-', '', $_GET['page'] ?? 'ace-dashboard' ) ),
					'logoUrl'      => esc_url_raw( ACE_PLUGIN_URL . 'assets/images/ace-media-logo.png' ),
				)
			),
			'before'
		);
	}

	/**
	 * Render a React root node.
	 *
	 * @param string $page Current page slug.
	 * @return void
	 */
	private function render_page( string $page ): void {
		if ( 'dashboard' !== $page ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ace-dashboard#' . rawurlencode( $page ) ) );
			exit;
		}

		?>
		<div class="wrap ace-admin-wrap">
			<div id="ace-admin-root" data-page="<?php echo esc_attr( $page ); ?>"></div>
		</div>
		<?php
	}

	/**
	 * Add a page-specific body class for the plugin UI.
	 *
	 * @param string $classes Existing admin body classes.
	 * @return string
	 */
	public function admin_body_class( string $classes ): string {
		$hook_suffix = function_exists( 'get_current_screen' ) && get_current_screen() ? get_current_screen()->id : '';

		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return $classes;
		}

		return trim( $classes . ' ace-admin-screen' );
	}
}
