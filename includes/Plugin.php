<?php
/**
 * Main plugin loader.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement;

use ACE\AdaptiveCustomerEngagement\Admin\Menu;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\EnrichmentCacheRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\EventRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\NumberRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
use ACE\AdaptiveCustomerEngagement\Enrichment\EnrichmentService;
use ACE\AdaptiveCustomerEngagement\Enrichment\ProviderRegistry;
use ACE\AdaptiveCustomerEngagement\REST\AdminController;
use ACE\AdaptiveCustomerEngagement\REST\TrackingController;
use ACE\AdaptiveCustomerEngagement\Security\RateLimiter;
use ACE\AdaptiveCustomerEngagement\Tracking\BotDetector;
use ACE\AdaptiveCustomerEngagement\Tracking\EventLogger;
use ACE\AdaptiveCustomerEngagement\Tracking\NumberResolver;
use ACE\AdaptiveCustomerEngagement\Tracking\Privacy;
use ACE\AdaptiveCustomerEngagement\Tracking\SessionManager;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialise plugin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$session_repository = new SessionRepository();
		$event_repository   = new EventRepository();
		$number_repository  = new NumberRepository();
		$company_repository = new CompanyRepository();
		$privacy            = new Privacy();
		$enrichment_service = new EnrichmentService(
			new ProviderRegistry(),
			new EnrichmentCacheRepository(),
			$company_repository,
			$session_repository,
			$privacy
		);
		$menu               = new Menu();
		$tracking           = new TrackingController(
			new SessionManager( $session_repository, $privacy ),
			new EventLogger( $event_repository ),
			new NumberResolver( $number_repository ),
			new RateLimiter(),
			$privacy,
			new BotDetector(),
			$enrichment_service
		);
		$admin              = new AdminController( $session_repository, $event_repository, $number_repository, $company_repository, $privacy, $enrichment_service );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $tracking, 'register_routes' ) );
		add_action( 'rest_api_init', array( $admin, 'register_routes' ) );
		add_action( 'admin_post_ace_export_sessions', array( $admin, 'export_sessions' ) );
		add_action( 'admin_post_ace_export_companies', array( $admin, 'export_companies' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'ace_purge_expired_raw_data', array( $privacy, 'purge_expired_raw_data' ) );

		$menu->register();
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'adaptive-customer-engagement', false, dirname( plugin_basename( ACE_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		$settings = Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$asset_file = ACE_PLUGIN_DIR . 'assets/build/frontend.asset.php';
		$script_src = ACE_PLUGIN_URL . 'assets/build/frontend.js';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => ACE_PLUGIN_VERSION,
		);

		wp_enqueue_script( 'ace-frontend', $script_src, $asset['dependencies'], $asset['version'], true );
		wp_add_inline_script(
			'ace-frontend',
			'window.ACEFrontendConfig = ' . wp_json_encode(
				array(
					'root'      => esc_url_raw( rest_url() ),
					'namespace' => 'adaptive-customer-engagement/v1',
					'enabled'   => (bool) $settings['enabled'],
					'tracking'  => $settings['tracking'],
				)
			),
			'before'
		);
	}
}
