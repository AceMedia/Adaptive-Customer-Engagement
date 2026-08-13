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
use ACE\AdaptiveCustomerEngagement\Database\Repositories\FormSubmissionRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\IpCompanyMemoryRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\NumberRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
use ACE\AdaptiveCustomerEngagement\Enrichment\AsnIntel;
use ACE\AdaptiveCustomerEngagement\Enrichment\DnsIntel;
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
use ACE\AdaptiveCustomerEngagement\Tracking\FormCaptureService;
use ACE\AdaptiveCustomerEngagement\Tracking\SessionManager;
use ACE\AdaptiveCustomerEngagement\Tracking\WooCommerceContext;
use ACE\AdaptiveCustomerEngagement\Tracking\WooOrderCaptureService;

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
		$form_submissions   = new FormSubmissionRepository();
		$privacy            = new Privacy();
		$enrichment_service = new EnrichmentService(
			new ProviderRegistry(),
			new EnrichmentCacheRepository(),
			$company_repository,
			$session_repository,
			$privacy,
			$ip_company_memory
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
		( new FormCaptureService( $form_submissions, $session_repository, $company_repository, $ip_company_memory ) )->register();
		( new WooOrderCaptureService( $session_repository, $company_repository, $ip_company_memory, $privacy ) )->register();
		add_filter( 'rest_authentication_errors', array( $this, 'allow_public_endpoints_without_nonce' ), 101 );
		$this->maybe_migrate_voice_provider();
		$this->maybe_backfill_company_classification();
		$this->maybe_backfill_company_classification_v2();
		// Deferred: WooCommerce loads after this plugin, so wc_get_orders()
		// does not exist yet at include time.
		add_action(
			'plugins_loaded',
			function (): void {
				$this->maybe_backfill_order_identity();
			},
			20
		);

		$menu->register();
		( new \ACE\AdaptiveCustomerEngagement\Admin\LiveMonitor() )->register();
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
	 * One-time migration: the voice provider previously defaulted to 'browser',
	 * which was never a deliberate choice (the voice feature is new). Flip that
	 * legacy default to 'auto' so voice leans on the configured OpenAI key without
	 * a second setup step. Runs once per site; an explicit 'browser' chosen later
	 * is preserved because the flag is already set.
	 *
	 * @return void
	 */
	private function maybe_migrate_voice_provider(): void {
		if ( '1' === (string) get_option( 'ace_voice_provider_auto', '' ) ) {
			return;
		}

		$settings = get_option( Settings::OPTION_NAME );

		if ( is_array( $settings ) && isset( $settings['ai_agent']['frontend_voice_provider'] ) && 'browser' === $settings['ai_agent']['frontend_voice_provider'] ) {
			$settings['ai_agent']['frontend_voice_provider'] = 'auto';
			update_option( Settings::OPTION_NAME, $settings );
		}

		update_option( 'ace_voice_provider_auto', '1', false );
	}

	/**
	 * One-time backfill: apply the network-operator classification and the
	 * personal-name scrub to companies created before those rules existed,
	 * so historical reporting matches what new sessions now record.
	 *
	 * ISPs/carriers and security gateways are typed and downgraded out of
	 * the likely-business bucket (their sessions follow); companies that
	 * were really RDAP registrant persons are deleted and their sessions
	 * detached.
	 *
	 * @return void
	 */
	private function maybe_backfill_company_classification(): void {
		if ( '1' === (string) get_option( 'ace_company_classification_backfill', '' ) ) {
			return;
		}

		global $wpdb;

		$companies = Schema::table_name( 'companies' );
		$sessions  = Schema::table_name( 'sessions' );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema, not user input.
				"SELECT id, name, domain, type, confidence, source_provider FROM {$companies} WHERE source_provider IN ( %s, %s, %s )",
				'keyless',
				'ipregistry',
				'ipinfo'
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$id = (int) $row['id'];

			if ( 'keyless' === (string) $row['source_provider'] && DnsIntel::looks_like_person( (string) $row['name'] ) ) {
				$wpdb->delete( $companies, array( 'id' => $id ) );
				$wpdb->update(
					$sessions,
					array(
						'company_id'         => null,
						'company_confidence' => 'unknown',
					),
					array( 'company_id' => $id )
				);
				continue;
			}

			$kind = ( 'isp' === strtolower( (string) $row['type'] ) )
				? 'isp'
				: DnsIntel::network_kind( (string) $row['name'], (string) $row['domain'] );

			if ( null === $kind ) {
				continue;
			}

			$update = array();

			if ( '' === (string) $row['type'] ) {
				$update['type'] = $kind;
			}

			if ( 'likely' === (string) $row['confidence'] ) {
				$update['confidence'] = 'weak';
			}

			if ( ! empty( $update ) ) {
				$wpdb->update( $companies, $update, array( 'id' => $id ) );
			}

			$wpdb->update(
				$sessions,
				array( 'company_confidence' => 'weak' ),
				array(
					'company_id'         => $id,
					'company_confidence' => 'likely',
				)
			);
		}

		update_option( 'ace_company_classification_backfill', '1', false );
	}

	/**
	 * Second classification backwash: re-type enrichment-sourced companies
	 * with PeeringDB ASN intelligence and align their sessions. Runs once,
	 * and only under WP-CLI — the PeeringDB warm-up is deliberately slow
	 * and must never happen inside a web request.
	 *
	 * @return void
	 */
	private function maybe_backfill_company_classification_v2(): void {
		if ( ! defined( 'WP_CLI' ) || '1' === (string) get_option( 'ace_company_classification_backfill_2', '' ) ) {
			return;
		}

		global $wpdb;

		$companies = Schema::table_name( 'companies' );
		$sessions  = Schema::table_name( 'sessions' );

		// Warm the PeeringDB transient cache politely: fresh fetches only.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		$asns = $wpdb->get_col( "SELECT DISTINCT asn FROM {$sessions} WHERE asn IS NOT NULL AND asn <> ''" );

		foreach ( (array) $asns as $raw_asn ) {
			// Rate-limited: stop fetching, apply what we have, resume on a
			// later invocation once the back-off has expired.
			if ( get_transient( 'ace_peeringdb_backoff' ) ) {
				break;
			}

			$asn = DnsIntel::parse_asn( $raw_asn );

			if ( null === $asn || false !== get_transient( 'ace_asn_type_' . $asn ) ) {
				continue;
			}

			AsnIntel::network_type( $asn );
			usleep( 1200000 );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
				"SELECT id, name, domain, type, confidence FROM {$companies} WHERE source_provider IN ( %s, %s, %s )",
				'keyless',
				'ipregistry',
				'ipinfo'
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$id       = (int) $row['id'];
			$raw_asn  = $wpdb->get_var( $wpdb->prepare( "SELECT asn FROM {$sessions} WHERE company_id = %d AND asn IS NOT NULL AND asn <> '' GROUP BY asn ORDER BY COUNT(*) DESC LIMIT 1", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$asn      = DnsIntel::parse_asn( $raw_asn );
			$asn_type = AsnIntel::network_type( $asn );
			$hosting  = DnsIntel::is_hosting_asn( $asn ) || 'hosting' === $asn_type;

			$kind = ( 'isp' === strtolower( (string) $row['type'] ) )
				? 'isp'
				: DnsIntel::network_kind( (string) $row['name'], (string) $row['domain'] );

			if ( 'proxy' !== $kind ) {
				if ( 'isp' === $asn_type ) {
					$kind = 'isp';
				} elseif ( null !== $asn_type && 'hosting' !== $asn_type ) {
					$kind = null;
				}
			}

			if ( null !== $kind || $hosting ) {
				$label  = $kind ?: 'hosting';
				$update = array( 'type' => $label );

				if ( 'likely' === (string) $row['confidence'] ) {
					$update['confidence'] = 'weak';
				}

				$wpdb->update( $companies, $update, array( 'id' => $id ) );
				$wpdb->update(
					$sessions,
					array( 'company_confidence' => 'weak' ),
					array(
						'company_id'         => $id,
						'company_confidence' => 'likely',
					)
				);
				continue;
			}

			if ( in_array( $asn_type, array( 'business', 'education', 'government', 'organisation' ), true )
				&& '' !== (string) $row['name']
				&& 'weak' === (string) $row['confidence'] ) {
				$update = array( 'confidence' => 'likely' );

				if ( '' === (string) $row['type'] ) {
					$update['type'] = $asn_type;
				}

				$wpdb->update( $companies, $update, array( 'id' => $id ) );
				$wpdb->update(
					$sessions,
					array( 'company_confidence' => 'likely' ),
					array(
						'company_id'         => $id,
						'company_confidence' => 'weak',
					)
				);
			}
		}

		// Only mark the backwash complete after a full pass; a rate-limited
		// run keeps the flag unset so the next CLI invocation resumes it.
		if ( ! get_transient( 'ace_peeringdb_backoff' ) ) {
			update_option( 'ace_company_classification_backfill_2', '1', false );
		}
	}

	/**
	 * One-off order-history backwash: every past WooCommerce order carries
	 * declared first-party identity — billing company, email domain, and
	 * the customer's IP. Promote all of it into confirmed companies and
	 * IP memory, then retro-match tracked sessions by IP hash. CLI-only:
	 * it walks the whole order book.
	 *
	 * @return void
	 */
	private function maybe_backfill_order_identity(): void {
		if ( ! defined( 'WP_CLI' )
			|| ! function_exists( 'wc_get_orders' )
			|| '1' === (string) get_option( 'ace_order_identity_backfill', '' ) ) {
			return;
		}

		global $wpdb;

		$sessions_table = Schema::table_name( 'sessions' );
		$privacy        = new Privacy();
		$companies      = new CompanyRepository();
		$ip_memory      = new IpCompanyMemoryRepository();
		$orders         = wc_get_orders(
			array(
				'limit'   => -1,
				'status'  => array( 'completed', 'processing', 'on-hold' ),
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$company = sanitize_text_field( (string) $order->get_billing_company() );
			$email   = sanitize_email( (string) $order->get_billing_email() );
			$name    = sanitize_text_field( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
			$phone   = sanitize_text_field( (string) $order->get_billing_phone() );
			$domain  = '';

			if ( '' !== $email && false !== strpos( $email, '@' ) ) {
				$candidate = strtolower( substr( strrchr( $email, '@' ), 1 ) ?: '' );
				$domain    = in_array( $candidate, FormCaptureService::GENERIC_EMAIL_DOMAINS, true ) ? '' : $candidate;
			}

			$company_row = null;

			if ( '' !== $company || '' !== $domain ) {
				$company_row = $companies->create_or_touch_local(
					array(
						'name'       => '' !== $company ? $company : $domain,
						'domain'     => $domain,
						'confidence' => 'confirmed',
						'source'     => 'woocommerce_order',
					)
				);
			}

			$ip_hash = $privacy->hash_ip( (string) $order->get_customer_ip_address() );

			if ( '' === $ip_hash ) {
				continue;
			}

			if ( '' !== $company || '' !== $domain || '' !== $name || '' !== $email || '' !== $phone ) {
				$ip_memory->upsert(
					$ip_hash,
					array(
						'company_id'     => is_array( $company_row ) ? (int) ( $company_row['id'] ?? 0 ) : 0,
						'company_name'   => $company,
						'company_domain' => $domain,
						'contact_name'   => $name,
						'contact_email'  => $email,
						'contact_phone'  => $phone,
						'source'         => 'woocommerce_order',
						'confidence'     => 'confirmed',
						'evidence'       => array( 'order_id' => (int) $order->get_id() ),
					)
				);
			}

			if ( is_array( $company_row ) && ! empty( $company_row['id'] ) ) {
				$matched = (int) $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
						"UPDATE {$sessions_table} SET company_id = %d, company_confidence = 'confirmed' WHERE ip_hash = %s AND company_confidence <> 'confirmed'",
						(int) $company_row['id'],
						$ip_hash
					)
				);

				if ( $matched > 0 ) {
					$wpdb->query(
						$wpdb->prepare(
							'UPDATE ' . Schema::table_name( 'companies' ) . ' SET total_sessions = total_sessions + %d, last_seen = %s, updated_at = %s WHERE id = %d',
							$matched,
							current_time( 'mysql', true ),
							current_time( 'mysql', true ),
							(int) $company_row['id']
						)
					);
				}
			}
		}

		update_option( 'ace_order_identity_backfill', '1', false );
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
			'adaptive-customer-engagement/v1/ai/cart/',
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
			'maxHistoryMessages'=> max( 1, min( 40, absint( $ai_agent['max_history_messages'] ?? 20 ) ) ),
			'pollIntervalMs'    => 5000,
			'availabilityPollIntervalMs' => 15000,
			'handoffEnabled'    => ! empty( $ai_agent['handoff_to_human'] ),
			'voiceInput'        => ! empty( $ai_agent['frontend_voice_input'] ),
			'voiceReplies'      => ! empty( $ai_agent['frontend_voice_replies'] ),
			'voiceAutospeak'    => ! empty( $ai_agent['frontend_voice_autospeak'] ),
			'voiceHandsFree'    => ! empty( $ai_agent['frontend_voice_hands_free'] ),
			'voiceLang'         => sanitize_text_field( (string) ( $ai_agent['frontend_voice_lang'] ?? 'en-GB' ) ),
			'voiceProvider'     => ChatClientFactory::effective_voice_provider( $ai_agent ),
			'placement'         => sanitize_key( (string) ( $ai_agent['frontend_chat_placement'] ?? 'bottom-right' ) ),
			'dockEnabled'       => ! empty( $ai_agent['frontend_chat_dock_enabled'] ),
			'addToCartEnabled'  => function_exists( 'wc_get_product' ) && ! empty( $ai_agent['frontend_add_to_cart_enabled'] ),
			'voiceTtsEnabled'   => ! empty( $ai_agent['frontend_voice_replies'] ) && ( new TextToSpeechService() )->is_configured( $ai_agent ),
			'voiceTtsEndpoint'  => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/voice/tts' ) ) ),
			'voiceSttEnabled'   => ! empty( $ai_agent['frontend_voice_input'] ) && ( new SpeechToTextService() )->is_configured( $ai_agent ),
			'voiceTranscribeEndpoint' => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/voice/transcribe' ) ) ),
			'cartAddEndpoint'   => esc_url_raw( ace_adaptive_customer_engagement_make_local_url( rest_url( 'adaptive-customer-engagement/v1/ai/cart/add' ) ) ),
		);
	}
}
