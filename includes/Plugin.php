<?php
/**
 * Main plugin loader.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement;

use ACE\AdaptiveCustomerEngagement\AI\ChatClientFactory;
use ACE\AdaptiveCustomerEngagement\AI\FrontendChatService;
use ACE\AdaptiveCustomerEngagement\AI\LeadProfileService;
use ACE\AdaptiveCustomerEngagement\AI\SiteContextService;
use ACE\AdaptiveCustomerEngagement\AI\TextToSpeechService;
use ACE\AdaptiveCustomerEngagement\AI\SpeechToTextService;
use ACE\AdaptiveCustomerEngagement\AmazonConnect\Client as AmazonConnectClient;
use ACE\AdaptiveCustomerEngagement\Admin\SampleDataSeeder;
use ACE\AdaptiveCustomerEngagement\Admin\Menu;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\CallRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\ChatConversationRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\ChatMessageRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository;
use ACE\AdaptiveCustomerEngagement\Database\Schema;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\EnrichmentCacheRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\EventRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\IpCompanyMemoryRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\NumberRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
use ACE\AdaptiveCustomerEngagement\Enrichment\EnrichmentService;
use ACE\AdaptiveCustomerEngagement\Enrichment\ProviderRegistry;
use ACE\AdaptiveCustomerEngagement\REST\AdminController;
use ACE\AdaptiveCustomerEngagement\REST\TrackingController;
use ACE\AdaptiveCustomerEngagement\Security\Capabilities;
use ACE\AdaptiveCustomerEngagement\Security\RateLimiter;
use ACE\AdaptiveCustomerEngagement\Tracking\BotDetector;
use ACE\AdaptiveCustomerEngagement\Tracking\EventLogger;
use ACE\AdaptiveCustomerEngagement\Tracking\NumberResolver;
use ACE\AdaptiveCustomerEngagement\Tracking\Privacy;
use ACE\AdaptiveCustomerEngagement\Tracking\SessionManager;
use ACE\AdaptiveCustomerEngagement\Tracking\WooCommerceContext;

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
		if ( get_option( Schema::SCHEMA_VERSION_OPTION ) !== Schema::SCHEMA_VERSION ) {
			Schema::install();
		}

		$session_repository = new SessionRepository();
		$event_repository   = new EventRepository();
		$number_repository  = new NumberRepository();
		$company_repository = new CompanyRepository();
		$call_repository    = new CallRepository();
		$chat_conversations = new ChatConversationRepository();
		$chat_messages      = new ChatMessageRepository();
		$ip_company_memory  = new IpCompanyMemoryRepository();
		$privacy            = new Privacy();
		$enrichment_service = new EnrichmentService(
			new ProviderRegistry(),
			new EnrichmentCacheRepository(),
			$company_repository,
			$session_repository,
			$privacy
		);
		$menu               = new Menu();
		$sample_data        = new SampleDataSeeder();
		$connect_client     = new AmazonConnectClient();
		$site_context       = new SiteContextService();
		$lead_profiles      = new LeadProfileService( $session_repository, $company_repository, $chat_conversations, $ip_company_memory );
		$tracking           = new TrackingController(
			new SessionManager( $session_repository, $privacy ),
			new EventLogger( $event_repository ),
			new NumberResolver( $number_repository ),
			new RateLimiter(),
			$privacy,
			new BotDetector(),
			$enrichment_service,
			$site_context,
			new FrontendChatService( $site_context, $session_repository, $chat_conversations, $chat_messages, $lead_profiles, $number_repository )
		);
		$admin              = new AdminController( $session_repository, $event_repository, $number_repository, $company_repository, $call_repository, $chat_conversations, $chat_messages, $privacy, $enrichment_service, $sample_data, $connect_client, $site_context );
		
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $tracking, 'register_routes' ) );
		add_action( 'rest_api_init', array( $admin, 'register_routes' ) );
		add_action( 'admin_post_ace_export_sessions', array( $admin, 'export_sessions' ) );
		add_action( 'admin_post_ace_export_companies', array( $admin, 'export_companies' ) );
		add_action( 'admin_post_ace_export_calls', array( $admin, 'export_calls' ) );
		add_action( 'admin_post_ace_export_chats', array( $admin, 'export_chats' ) );
		add_action( 'admin_post_ace_export_commerce', array( $admin, 'export_commerce' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'ace_purge_expired_raw_data', array( $privacy, 'purge_expired_raw_data' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'allow_public_endpoints_without_nonce' ), 101 );

		$menu->register();
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'adaptive-customer-engagement', false, dirname( plugin_basename( ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Allow the plugin's public REST endpoints to run even when a logged-in
	 * visitor presents a stale REST cookie nonce.
	 *
	 * WordPress fails the whole request with `rest_cookie_invalid_nonce` when an
	 * auth cookie is present but the nonce is invalid — even for routes whose
	 * permission callback is public. A page served from full-page cache can carry
	 * an expired nonce, which breaks the frontend chat for logged-in users while
	 * anonymous visitors are unaffected. These endpoints are intentionally public
	 * and perform no privileged action, so the stale-nonce failure is cleared for
	 * them only.
	 *
	 * @param mixed $result Current authentication result.
	 * @return mixed
	 */
	public function allow_public_endpoints_without_nonce( $result ) {
		if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
			return $result;
		}

		$path = '';

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path .= (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		}

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
			$path .= ' ' . (string) wp_unslash( $_GET['rest_route'] );
		}

		$public_segments = array(
			'adaptive-customer-engagement/v1/ai/chat/',
			'adaptive-customer-engagement/v1/ai/voice/',
			'adaptive-customer-engagement/v1/track',
			'adaptive-customer-engagement/v1/number/',
		);

		foreach ( $public_segments as $segment ) {
			if ( false !== strpos( $path, $segment ) ) {
				return true;
			}
		}

		return $result;
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		$settings    = Settings::get();
		$woo_context = new WooCommerceContext();
		$ai_chat     = $this->get_frontend_ai_chat_config( $settings, new ChatMessageRepository() );

		if ( empty( $settings['enabled'] ) && empty( $ai_chat['enabled'] ) ) {
			return;
		}

		$asset_file = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR . 'assets/build/frontend.asset.php';
		$script_src = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_URL . 'assets/build/frontend.js';
		$style_file = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR . 'assets/build/style-frontend.css';
		$style_src  = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_URL . 'assets/build/style-frontend.css';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_VERSION,
		);

		if ( file_exists( $style_file ) ) {
			wp_enqueue_style( 'ace-frontend', $style_src, array(), $asset['version'] );
		}

		wp_enqueue_script( 'ace-frontend', $script_src, $asset['dependencies'], $asset['version'], true );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'ace-frontend', 'adaptive-customer-engagement' );
		}

		wp_add_inline_script(
			'ace-frontend',
			'window.ACEFrontendConfig = ' . wp_json_encode(
				array(
					'root'      => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url() ) ),
					'namespace' => 'adaptive-customer-engagement/v1',
					'enabled'   => (bool) $settings['enabled'],
					'tracking'  => $settings['tracking'],
					'page'      => $woo_context->get_frontend_context(),
					'aiChat'    => $ai_chat,
				)
			),
			'before'
		);
	}

	/**
	 * Build frontend AI chat config.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>
	 */
	private function get_frontend_ai_chat_config( array $settings, ChatMessageRepository $chat_messages ): array {
		$ai_agent   = isset( $settings['ai_agent'] ) && is_array( $settings['ai_agent'] ) ? $settings['ai_agent'] : array();
		$admin_only = ! empty( $ai_agent['frontend_chat_admin_only'] );
		$can_view   = ! $admin_only || current_user_can( Capabilities::MANAGE );
		$bot_name   = sanitize_text_field( (string) ( $ai_agent['frontend_chat_bot_name'] ?? $ai_agent['frontend_chat_title'] ?? '' ) );
		$bot_name   = '' !== $bot_name ? $bot_name : __( 'Site assistant', 'adaptive-customer-engagement' );
		$greeting   = sanitize_textarea_field( (string) ( $ai_agent['frontend_chat_greeting'] ?? '' ) );

		if ( '' === $greeting ) {
			$greeting = sprintf(
				/* translators: %s: chatbot name. */
				__( 'Hello, I am %s. Ask me about the company, products, or services and I will do my best to help.', 'adaptive-customer-engagement' ),
				$bot_name
			);
		}

		$enabled    = ! empty( $ai_agent['enabled'] )
			&& ! empty( $ai_agent['frontend_chat_enabled'] )
			&& '' !== trim( (string) ChatClientFactory::resolve( $ai_agent )['api_key'] )
			&& $can_view;

		return array(
			'enabled'           => $enabled,
			'adminOnly'         => $admin_only,
			'endpoint'          => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/chat/respond' ) ) ),
			'syncEndpoint'      => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/chat/conversation' ) ) ),
			'typingEndpoint'    => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/chat/typing' ) ) ),
			'endEndpoint'       => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/chat/end' ) ) ),
			'availabilityEndpoint' => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/chat/availability' ) ) ),
			'contactEndpoint'   => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/chat/contact' ) ) ),
			'restNonce'         => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'title'             => $bot_name,
			'botName'           => $bot_name,
			'botAvatarUrl'      => esc_url_raw( get_site_icon_url( 96 ) ?: '' ),
			'greeting'          => $greeting,
			'starterQuestions'  => $chat_messages->get_common_user_questions( 3 ),
			'placeholder'       => sanitize_text_field( (string) ( $ai_agent['frontend_chat_placeholder'] ?? '' ) ),
			'showSources'       => ! empty( $ai_agent['show_source_links'] ),
			'keepHistory'       => ! empty( $ai_agent['keep_history'] ),
			'maxHistoryMessages'=> max( 1, min( 12, absint( $ai_agent['max_history_messages'] ?? 8 ) ) ),
			'pollIntervalMs'    => 5000,
			'availabilityPollIntervalMs' => 15000,
			'handoffEnabled'    => ! empty( $ai_agent['handoff_to_human'] ),
			'voiceInput'        => ! empty( $ai_agent['frontend_voice_input'] ),
			'voiceReplies'      => ! empty( $ai_agent['frontend_voice_replies'] ),
			'voiceAutospeak'    => ! empty( $ai_agent['frontend_voice_autospeak'] ),
			'voiceHandsFree'    => ! empty( $ai_agent['frontend_voice_hands_free'] ),
			'voiceLang'         => sanitize_text_field( (string) ( $ai_agent['frontend_voice_lang'] ?? 'en-GB' ) ),
			'voiceProvider'     => sanitize_key( (string) ( $ai_agent['frontend_voice_provider'] ?? 'browser' ) ),
			'voiceTtsEnabled'   => ! empty( $ai_agent['frontend_voice_replies'] ) && ( new TextToSpeechService() )->is_configured( $ai_agent ),
			'voiceTtsEndpoint'  => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/voice/tts' ) ) ),
			'voiceSttEnabled'   => ! empty( $ai_agent['frontend_voice_input'] ) && ( new SpeechToTextService() )->is_configured( $ai_agent ),
			'voiceTranscribeEndpoint' => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/voice/transcribe' ) ) ),
		);
	}
}
