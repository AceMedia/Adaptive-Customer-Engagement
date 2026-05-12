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
	private const TOP_LEVEL_SLUG = 'adaptive-customer-engagement-dashboard';
	private const PAGE_SLUG_PREFIX = 'adaptive-customer-engagement-';

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

		$top_slug                = self::TOP_LEVEL_SLUG;
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
			$slug                 = 'dashboard' === $page ? $top_slug : self::PAGE_SLUG_PREFIX . $page;
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

		$asset_file = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR . 'assets/build/admin.asset.php';
		$script_src = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_URL . 'assets/build/admin.js';
		$style_src  = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_URL . 'assets/build/style-admin.css';
		$style_file = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR . 'assets/build/style-admin.css';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			'version'      => ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_VERSION,
		);

		if ( file_exists( $style_file ) ) {
			wp_enqueue_style( 'ace-admin', $style_src, array( 'wp-components' ), $asset['version'] );
		}

		wp_enqueue_script( 'ace-admin', $script_src, $asset['dependencies'], $asset['version'], true );
		wp_add_inline_script(
			'ace-admin',
			'window.ACEAdminConfig = ' . wp_json_encode(
				array(
					'root'         => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url() ) ),
					'namespace'    => 'adaptive-customer-engagement/v1',
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'exportNonce'  => wp_create_nonce( 'ace_export_report' ),
					'adminUrl'     => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( admin_url( 'admin.php' ) ) ),
					'adminPostUrl' => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( admin_url( 'admin-post.php' ) ) ),
					'page'         => sanitize_key( (string) str_replace( self::PAGE_SLUG_PREFIX, '', $_GET['page'] ?? self::TOP_LEVEL_SLUG ) ),
					'logoUrl'      => esc_url_raw( ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_URL . 'assets/images/ace-media-logo.png' ),
					'siteIconUrl'  => esc_url_raw( get_site_icon_url( 96 ) ?: '' ),
				)
			),
			'before'
		);
		wp_add_inline_script(
			'ace-admin',
			"(function(){
				if (!document.body.classList.contains('ace-admin-screen')) {
					return;
				}

				const selectors = [
					'#wpbody-content > .notice',
					'#wpbody-content > .updated',
					'#wpbody-content > .error',
					'#wpbody-content > .update-nag',
					'#wpbody-content > .is-dismissible',
					'#wpbody-content > .fs-notice',
					'#wpbody-content > .woocommerce-message',
					'#wpbody-content > .woocommerce-error',
					'#wpbody-content > .woocommerce-info',
					'#wpbody-content > .notice-wrapper',
					'#wpbody-content > div[class*=\"notice\"]',
					'#wpbody-content > .wrap > .notice:not(.components-notice)',
					'#wpbody-content > .wrap > .updated',
					'#wpbody-content > .wrap > .error',
					'#wpbody-content > .wrap > .update-nag',
					'#wpbody-content > .wrap > div[class*=\"notice\"]:not(.components-notice)'
				];

				const hideNoticeNode = (node) => {
					if (!node || !(node instanceof HTMLElement)) {
						return;
					}

					if (node.closest('#ace-admin-root')) {
						return;
					}

					node.style.setProperty('display', 'none', 'important');
					node.setAttribute('aria-hidden', 'true');
				};

				const hideNotices = () => {
					selectors.forEach((selector) => {
						document.querySelectorAll(selector).forEach(hideNoticeNode);
					});
				};

				hideNotices();

				const target = document.getElementById('wpbody-content');

				if (!target || typeof MutationObserver === 'undefined') {
					return;
				}

				new MutationObserver(() => hideNotices()).observe(target, {
					childList: true,
					subtree: true
				});
			})();",
			'after'
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
			wp_safe_redirect( ace_adaptive_customer_engagement_make_local_url( admin_url( 'admin.php?page=' . self::TOP_LEVEL_SLUG . '#' . rawurlencode( $page ) ) ) );
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
