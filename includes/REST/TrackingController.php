<?php
/**
 * Public tracking REST controller.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\REST;

use ACE\AdaptiveCustomerEngagement\AI\FrontendChatService;
use ACE\AdaptiveCustomerEngagement\AI\SiteContextService;
use ACE\AdaptiveCustomerEngagement\AI\TextToSpeechService;
use ACE\AdaptiveCustomerEngagement\AI\SpeechToTextService;
use ACE\AdaptiveCustomerEngagement\Enrichment\EnrichmentService;
use ACE\AdaptiveCustomerEngagement\Security\Capabilities;
use ACE\AdaptiveCustomerEngagement\Security\RateLimiter;
use ACE\AdaptiveCustomerEngagement\Settings;
use ACE\AdaptiveCustomerEngagement\Tracking\BotDetector;
use ACE\AdaptiveCustomerEngagement\Tracking\EventLogger;
use ACE\AdaptiveCustomerEngagement\Tracking\NumberResolver;
use ACE\AdaptiveCustomerEngagement\Tracking\Privacy;
use ACE\AdaptiveCustomerEngagement\Tracking\SessionManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class TrackingController {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private $namespace = 'adaptive-customer-engagement/v1';

	/**
	 * Session manager.
	 *
	 * @var SessionManager
	 */
	private $session_manager;

	/**
	 * Event logger.
	 *
	 * @var EventLogger
	 */
	private $event_logger;

	/**
	 * Number resolver.
	 *
	 * @var NumberResolver
	 */
	private $number_resolver;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $rate_limiter;

	/**
	 * Privacy helper.
	 *
	 * @var Privacy
	 */
	private $privacy;

	/**
	 * Bot detector.
	 *
	 * @var BotDetector
	 */
	private $bot_detector;

	/**
	 * Enrichment workflow service.
	 *
	 * @var EnrichmentService
	 */
	private $enrichment_service;

	/**
	 * Live site-context helper.
	 *
	 * @var SiteContextService
	 */
	private $site_context;

	/**
	 * Frontend AI chat service.
	 *
	 * @var FrontendChatService
	 */
	private $frontend_chat;

	/**
	 * Constructor.
	 *
	 * @param SessionManager $session_manager Session manager.
	 * @param EventLogger    $event_logger    Event logger.
	 * @param NumberResolver $number_resolver Number resolver.
	 * @param RateLimiter    $rate_limiter    Rate limiter.
	 * @param Privacy        $privacy         Privacy helper.
	 * @param BotDetector    $bot_detector    Bot detector.
	 */
	public function __construct( SessionManager $session_manager, EventLogger $event_logger, NumberResolver $number_resolver, RateLimiter $rate_limiter, Privacy $privacy, BotDetector $bot_detector, EnrichmentService $enrichment_service, SiteContextService $site_context, FrontendChatService $frontend_chat ) {
		$this->session_manager = $session_manager;
		$this->event_logger    = $event_logger;
		$this->number_resolver = $number_resolver;
		$this->rate_limiter    = $rate_limiter;
		$this->privacy         = $privacy;
		$this->bot_detector    = $bot_detector;
		$this->enrichment_service = $enrichment_service;
		$this->site_context    = $site_context;
		$this->frontend_chat   = $frontend_chat;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/track',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'track' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/number/resolve',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'resolve_number' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/chat/respond',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_chat_respond' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/voice/tts',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_voice_tts' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/voice/transcribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_voice_transcribe' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/cart/add',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_cart_add' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/chat/conversation',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_chat_conversation' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/chat/typing',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_chat_typing' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/chat/end',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_chat_end' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/chat/availability',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_chat_availability' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/chat/contact',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_chat_contact' ),
			)
		);
	}

	/**
	 * Track a public event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function track( WP_REST_Request $request ) {
		$settings = Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'ace_tracking_disabled', __( 'Tracking is disabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( $this->privacy->should_respect_dnt() ) {
			return new WP_REST_Response( array( 'tracked' => false, 'reason' => 'dnt' ), 202 );
		}

		if ( $this->session_manager->should_ignore_request() ) {
			return new WP_REST_Response( array( 'tracked' => false, 'reason' => 'admin_ignored' ), 202 );
		}

		$payload = $this->sanitize_track_payload( $request->get_json_params() );
		$bucket  = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|track';

		if ( ! $this->rate_limiter->allow( $bucket, 120, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many requests.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$is_bot     = $this->bot_detector->is_bot( $user_agent );
		$session    = $this->session_manager->touch_session( $payload, $is_bot, $is_bot );
		$event_id   = $is_bot ? 0 : $this->event_logger->log( (int) $session['id'], $payload );

		if ( ! $is_bot ) {
			$this->enrichment_service->enrich_session( (int) $session['id'], $this->privacy->get_client_ip(), $is_bot );
		}

		return new WP_REST_Response(
			array(
				'tracked'      => ! $is_bot,
				'ignored'      => $is_bot,
				'session_id'   => (int) $session['id'],
				'session_uuid' => $payload['session_uuid'],
				'visitor_uuid' => $payload['visitor_uuid'],
				'event_id'     => $event_id,
			)
		);
	}

	/**
	 * Resolve a phone number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function resolve_number( WP_REST_Request $request ): WP_REST_Response {
		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|resolve';

		if ( ! $this->rate_limiter->allow( $bucket, 240, MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response( array(), 429 );
		}

		$number = $this->number_resolver->resolve(
			array(
				'path'         => sanitize_text_field( (string) $request->get_param( 'path' ) ),
				'utm_source'   => sanitize_text_field( (string) $request->get_param( 'utm_source' ) ),
				'utm_campaign' => sanitize_text_field( (string) $request->get_param( 'utm_campaign' ) ),
			)
		);

		if ( ! $number ) {
			return new WP_REST_Response( array(), 200 );
		}

		return new WP_REST_Response(
			array(
				'number_id'      => (int) $number['id'],
				'display_number' => (string) $number['display_number'],
				'e164_number'    => (string) $number['e164_number'],
				'label'          => (string) $number['label'],
			)
		);
	}

	/**
	 * Respond to a frontend AI chat message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_chat_respond( WP_REST_Request $request ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_chat_enabled'] ) ) {
			return new WP_Error( 'ace_ai_chat_disabled', __( 'The frontend assistant is not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $ai_agent['frontend_chat_admin_only'] ) && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_ai_chat_admin_only', __( 'The frontend assistant is currently restricted to site administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|ai-chat';

		if ( ! $this->rate_limiter->allow( $bucket, 30, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many chat messages have been sent just now.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		// Site-wide ceiling so a spoofed per-IP key cannot run up unbounded spend on the paid AI provider.
		if ( ! $this->rate_limiter->allow( 'ace-ai-chat-global', (int) apply_filters( 'ace_ai_chat_global_rate_limit', 600 ), MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'The assistant is handling a lot of requests right now. Please try again shortly.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$payload  = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$response = $this->frontend_chat->respond( $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * Synthesise spoken-reply audio with the configured premium voice provider.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_voice_tts( WP_REST_Request $request ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_chat_enabled'] ) || empty( $ai_agent['frontend_voice_replies'] ) ) {
			return new WP_Error( 'ace_tts_disabled', __( 'Spoken replies are not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $ai_agent['frontend_chat_admin_only'] ) && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_tts_admin_only', __( 'The frontend assistant is currently restricted to site administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$service = new TextToSpeechService();

		if ( ! $service->is_configured( $ai_agent ) ) {
			return new WP_Error( 'ace_tts_unconfigured', __( 'No premium voice provider is configured.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|ai-voice-tts';

		if ( ! $this->rate_limiter->allow( $bucket, 60, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many voice requests have been made just now.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		// Site-wide ceiling so a spoofed per-IP key cannot run up unbounded spend on the paid voice provider.
		if ( ! $this->rate_limiter->allow( 'ace-ai-voice-global', (int) apply_filters( 'ace_ai_voice_global_rate_limit', 600 ), MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Voice playback is busy right now. Please try again shortly.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$payload = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$result  = $service->synthesize( (string) ( $payload['text'] ?? '' ), $ai_agent );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Add a product (or a specific variation) to the WooCommerce cart from chat.
	 *
	 * Loads the cart in this REST request and forces a customer session so a
	 * guest's first add actually persists, adds via WC()->cart->add_to_cart(),
	 * then returns cart fragments — the robust pattern used elsewhere on the network.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_cart_add( WP_REST_Request $request ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_chat_enabled'] ) ) {
			return new WP_Error( 'ace_cart_disabled', __( 'The assistant is not available.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( array_key_exists( 'frontend_add_to_cart_enabled', $ai_agent ) && empty( $ai_agent['frontend_add_to_cart_enabled'] ) ) {
			return new WP_Error( 'ace_cart_disabled', __( 'Adding to the basket from chat is disabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $ai_agent['frontend_chat_admin_only'] ) && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_cart_admin_only', __( 'The frontend assistant is currently restricted to site administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'ace_cart_no_woo', __( 'WooCommerce is not available on this site.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|ai-cart';

		if ( ! $this->rate_limiter->allow( $bucket, 60, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many cart requests just now. Please try again shortly.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$payload = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();

		// Accept either a single item ({product_id,...}) or a bulk list
		// ({"items":[{...},...]}) so several bins/variations add in one request.
		$raw_items = ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) ? $payload['items'] : array( $payload );
		$items     = array();

		foreach ( $raw_items as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$pid = absint( $raw['product_id'] ?? 0 );

			if ( $pid ) {
				$items[] = array(
					'product_id'   => $pid,
					'variation_id' => absint( $raw['variation_id'] ?? 0 ),
					'quantity'     => max( 1, absint( $raw['quantity'] ?? 1 ) ),
				);
			}
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'ace_cart_product_required', __( 'A product is required.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		// The cart/session are not initialised in a REST request by default.
		if ( function_exists( 'wc_load_cart' ) && is_null( WC()->cart ) ) {
			wc_load_cart();
		}

		if ( is_null( WC()->cart ) ) {
			return new WP_Error( 'ace_cart_unavailable', __( 'The basket is unavailable right now.', 'adaptive-customer-engagement' ), array( 'status' => 500 ) );
		}

		// Force a customer session so a guest's first add persists (otherwise the
		// add succeeds but the cart cookie is never set and the basket looks empty).
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		WC()->cart->get_cart();

		$added_names = array();
		$last_name   = '';

		foreach ( $items as $item ) {
			$product_id   = $item['product_id'];
			$variation_id = $item['variation_id'];
			$quantity     = $item['quantity'];

			$reference = wc_get_product( $variation_id ? $variation_id : $product_id );

			if ( ! $reference || ! $reference->is_purchasable() ) {
				continue;
			}

			$variation = array();

			if ( $variation_id ) {
				$variation_product = wc_get_product( $variation_id );

				if ( $variation_product && $variation_product->is_type( 'variation' ) ) {
					$variation = $variation_product->get_variation_attributes();
				}
			}

			$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variation );
			$added  = $passed ? WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation ) : false;

			if ( false !== $added ) {
				do_action( 'woocommerce_ajax_added_to_cart', $product_id );
				$added_names[] = $reference->get_name();
				$last_name     = $reference->get_name();
			}
		}

		if ( empty( $added_names ) ) {
			$message = '';

			if ( function_exists( 'wc_get_notices' ) ) {
				$notices = wc_get_notices( 'error' );
				$message = ! empty( $notices ) ? wp_strip_all_tags( (string) ( $notices[0]['notice'] ?? '' ) ) : '';

				if ( function_exists( 'wc_clear_notices' ) ) {
					wc_clear_notices();
				}
			}

			return new WP_Error( 'ace_cart_failed', '' !== $message ? $message : __( 'Sorry, that could not be added to your basket.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		WC()->cart->calculate_totals();

		if ( WC()->session ) {
			WC()->session->save_data();
		}

		if ( function_exists( 'wc_setcookie' ) ) {
			wc_setcookie( 'woocommerce_items_in_cart', 1 );
			wc_setcookie( 'woocommerce_cart_hash', WC()->cart->get_cart_hash() );
		}

		do_action( 'woocommerce_set_cart_cookies', true );

		$mini_cart = '';

		if ( function_exists( 'wc_get_template' ) ) {
			ob_start();
			wc_get_template( 'cart/mini-cart.php' );
			$mini_cart = ob_get_clean();
		}

		$fragments = apply_filters(
			'woocommerce_add_to_cart_fragments',
			array(
				'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
			)
		);

		return new WP_REST_Response(
			array(
				'added'      => true,
				'cart_count' => (int) WC()->cart->get_cart_contents_count(),
				'cart_hash'  => WC()->cart->get_cart_hash(),
				'cart_url'   => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
				'fragments'  => $fragments,
				'name'       => $last_name,
				'names'      => $added_names,
			)
		);
	}

	/**
	 * Transcribe a recorded audio clip to text with the configured provider.
	 *
	 * Lets the chat microphone work in browsers without the Web Speech API.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_voice_transcribe( WP_REST_Request $request ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_chat_enabled'] ) || empty( $ai_agent['frontend_voice_input'] ) ) {
			return new WP_Error( 'ace_stt_disabled', __( 'Voice input is not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $ai_agent['frontend_chat_admin_only'] ) && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_stt_admin_only', __( 'The frontend assistant is currently restricted to site administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$service = new SpeechToTextService();

		if ( ! $service->is_configured( $ai_agent ) ) {
			return new WP_Error( 'ace_stt_unconfigured', __( 'No transcription provider is configured.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|ai-voice-stt';

		if ( ! $this->rate_limiter->allow( $bucket, 60, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many voice requests have been made just now.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		if ( ! $this->rate_limiter->allow( 'ace-ai-voice-global', (int) apply_filters( 'ace_ai_voice_global_rate_limit', 600 ), MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Voice is busy right now. Please try again shortly.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$payload = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$result  = $service->transcribe( (string) ( $payload['audio'] ?? '' ), (string) ( $payload['mime'] ?? '' ), $ai_agent );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Read the latest stored state for a frontend chat conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_chat_conversation( WP_REST_Request $request ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_chat_enabled'] ) ) {
			return new WP_Error( 'ace_ai_chat_disabled', __( 'The frontend assistant is not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $ai_agent['frontend_chat_admin_only'] ) && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_ai_chat_admin_only', __( 'The frontend assistant is currently restricted to site administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$bucket = $this->build_ai_chat_bucket(
			'ai-chat-sync',
			sanitize_text_field( (string) $request->get_param( 'conversation_uuid' ) ),
			sanitize_text_field( (string) $request->get_param( 'session_uuid' ) ),
			sanitize_text_field( (string) $request->get_param( 'visitor_uuid' ) )
		);

		if ( ! $this->rate_limiter->allow( $bucket, 360, MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'ace_rate_limited',
				__( 'Too many chat refresh requests have been made just now.', 'adaptive-customer-engagement' ),
				array(
					'status'      => 429,
					'retry_after' => 30,
				)
			);
		}

		$payload = array(
			'conversation_uuid' => sanitize_text_field( (string) $request->get_param( 'conversation_uuid' ) ),
			'session_uuid'      => sanitize_text_field( (string) $request->get_param( 'session_uuid' ) ),
			'visitor_uuid'      => sanitize_text_field( (string) $request->get_param( 'visitor_uuid' ) ),
		);
		$response = $this->frontend_chat->get_conversation_snapshot( $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * Update visitor typing state for a frontend chat conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_chat_typing( WP_REST_Request $request ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_chat_enabled'] ) ) {
			return new WP_Error( 'ace_ai_chat_disabled', __( 'The frontend assistant is not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $ai_agent['frontend_chat_admin_only'] ) && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_ai_chat_admin_only', __( 'The frontend assistant is currently restricted to site administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$payload = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$bucket  = $this->build_ai_chat_bucket(
			'ai-chat-typing',
			sanitize_text_field( (string) ( $payload['conversation_uuid'] ?? '' ) ),
			sanitize_text_field( (string) ( $payload['session_uuid'] ?? '' ) ),
			sanitize_text_field( (string) ( $payload['visitor_uuid'] ?? '' ) )
		);

		if ( ! $this->rate_limiter->allow( $bucket, 240, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many typing updates have been sent just now.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$response = $this->frontend_chat->update_typing( $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * End a frontend chat conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_chat_end( WP_REST_Request $request ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_chat_enabled'] ) ) {
			return new WP_Error( 'ace_ai_chat_disabled', __( 'The frontend assistant is not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $ai_agent['frontend_chat_admin_only'] ) && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_ai_chat_admin_only', __( 'The frontend assistant is currently restricted to site administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|ai-chat-end';

		if ( ! $this->rate_limiter->allow( $bucket, 12, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many chat end requests have been made just now.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$payload  = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$response = $this->frontend_chat->end_conversation( $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * Get current admin watcher availability for frontend chats.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_chat_availability( WP_REST_Request $request ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_chat_enabled'] ) ) {
			return new WP_Error( 'ace_ai_chat_disabled', __( 'The frontend assistant is not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $ai_agent['frontend_chat_admin_only'] ) && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_ai_chat_admin_only', __( 'The frontend assistant is currently restricted to site administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|ai-chat-availability';

		if ( ! $this->rate_limiter->allow( $bucket, 120, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many chat availability checks have been made just now.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		return new WP_REST_Response( $this->frontend_chat->get_availability() );
	}

	/**
	 * Store a visitor follow-up request for the chat team.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_chat_contact( WP_REST_Request $request ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_chat_enabled'] ) ) {
			return new WP_Error( 'ace_ai_chat_disabled', __( 'The frontend assistant is not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $ai_agent['frontend_chat_admin_only'] ) && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_ai_chat_admin_only', __( 'The frontend assistant is currently restricted to site administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|ai-chat-contact';

		if ( ! $this->rate_limiter->allow( $bucket, 10, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many follow-up requests have been made just now.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$payload  = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$response = $this->frontend_chat->submit_follow_up_request( $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * Search live site context for bot runtimes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bot_site_context_search( WP_REST_Request $request ) {
		$authorised = $this->authorise_bot_runtime_request( $request );

		if ( is_wp_error( $authorised ) ) {
			return $authorised;
		}

		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|bot-context-search';

		if ( ! $this->rate_limiter->allow( $bucket, 120, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many requests.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$payload   = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$query     = sanitize_text_field( (string) ( $payload['query'] ?? '' ) );
		$limit     = max( 1, min( 8, absint( $payload['limit'] ?? 5 ) ) );
		$post_types = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					is_array( $payload['post_types'] ?? null ) ? $payload['post_types'] : array()
				)
			)
		);

		return new WP_REST_Response(
			array(
				'site'                => $this->site_context->get_site_identity(),
				'query'               => $query,
				'documents'           => $this->site_context->search( $query, $limit, $post_types ),
				'context_instructions'=> sanitize_textarea_field( (string) ( Settings::get()['ai_agent']['context_instructions'] ?? '' ) ),
				'generated_at'        => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Build a direct live-content answer for bot runtimes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bot_site_context_answer( WP_REST_Request $request ) {
		$authorised = $this->authorise_bot_runtime_request( $request );

		if ( is_wp_error( $authorised ) ) {
			return $authorised;
		}

		$bucket = $this->privacy->hash_ip( $this->privacy->get_client_ip() ) . '|bot-context-answer';

		if ( ! $this->rate_limiter->allow( $bucket, 120, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ace_rate_limited', __( 'Too many requests.', 'adaptive-customer-engagement' ), array( 'status' => 429 ) );
		}

		$payload = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$query   = sanitize_text_field( (string) ( $payload['query'] ?? '' ) );
		$limit   = max( 1, min( 5, absint( $payload['limit'] ?? 3 ) ) );
		$answer  = $this->site_context->answer_question( $query, $limit );

		return new WP_REST_Response(
			array(
				'site'                => $this->site_context->get_site_identity(),
				'query'               => $query,
				'answer'              => $answer['answer'],
				'sources'             => $answer['sources'],
				'confidence'          => $answer['confidence'],
				'fallback'            => $answer['fallback'],
				'context_instructions'=> sanitize_textarea_field( (string) ( Settings::get()['ai_agent']['context_instructions'] ?? '' ) ),
				'generated_at'        => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Fetch a single site document for bot runtimes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bot_site_context_document( WP_REST_Request $request ) {
		$authorised = $this->authorise_bot_runtime_request( $request );

		if ( is_wp_error( $authorised ) ) {
			return $authorised;
		}

		$document = $this->site_context->get_document( absint( $request['id'] ) );

		if ( empty( $document ) ) {
			return new WP_Error( 'ace_bot_document_not_found', __( 'The requested site document could not be found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'site'                => $this->site_context->get_site_identity(),
				'document'            => $document,
				'context_instructions'=> sanitize_textarea_field( (string) ( Settings::get()['ai_agent']['context_instructions'] ?? '' ) ),
				'generated_at'        => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Sanitize a tracking payload.
	 *
	 * @param mixed $payload Raw payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_track_payload( $payload ): array {
		$payload       = is_array( $payload ) ? $payload : array();
		$allowed_types = array( 'pageview', 'click_to_call', 'download', 'form_submit', 'chat_start', 'chat_message' );
		$event_type    = sanitize_key( (string) ( $payload['event_type'] ?? 'pageview' ) );
		$metadata      = isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ? array_slice( $payload['metadata'], 0, 20, true ) : array();

		if ( ! in_array( $event_type, $allowed_types, true ) ) {
			$event_type = 'pageview';
		}

		return array(
			'session_uuid'     => sanitize_text_field( (string) ( $payload['session_uuid'] ?? wp_generate_uuid4() ) ),
			'visitor_uuid'     => sanitize_text_field( (string) ( $payload['visitor_uuid'] ?? wp_generate_uuid4() ) ),
			'event_uuid'       => sanitize_text_field( (string) ( $payload['event_uuid'] ?? wp_generate_uuid4() ) ),
			'event_type'       => $event_type,
			'event_name'       => sanitize_text_field( (string) ( $payload['event_name'] ?? '' ) ),
			'url'              => esc_url_raw( (string) ( $payload['url'] ?? '' ) ),
			'path'             => sanitize_text_field( (string) ( $payload['path'] ?? '' ) ),
			'page_title'       => sanitize_text_field( (string) ( $payload['page_title'] ?? '' ) ),
			'post_id'          => absint( $payload['post_id'] ?? 0 ),
			'post_type'        => sanitize_key( (string) ( $payload['post_type'] ?? '' ) ),
			'taxonomy_context' => sanitize_text_field( (string) ( $payload['taxonomy_context'] ?? '' ) ),
			'product_area'     => sanitize_text_field( (string) ( $payload['product_area'] ?? '' ) ),
			'brand_context'    => sanitize_text_field( (string) ( $payload['brand_context'] ?? '' ) ),
			'number_id'        => absint( $payload['number_id'] ?? 0 ),
			'referrer'         => esc_url_raw( (string) ( $payload['referrer'] ?? '' ) ),
			'utm'              => array(
				'source'   => sanitize_text_field( (string) ( $payload['utm']['source'] ?? '' ) ),
				'medium'   => sanitize_text_field( (string) ( $payload['utm']['medium'] ?? '' ) ),
				'campaign' => sanitize_text_field( (string) ( $payload['utm']['campaign'] ?? '' ) ),
				'term'     => sanitize_text_field( (string) ( $payload['utm']['term'] ?? '' ) ),
				'content'  => sanitize_text_field( (string) ( $payload['utm']['content'] ?? '' ) ),
			),
			'metadata'         => array_map(
				static function ( $value ): string {
					return sanitize_text_field( (string) $value );
				},
				$metadata
			),
		);
	}

	/**
	 * Authorise a bot-runtime request by capability or shared secret.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function authorise_bot_runtime_request( WP_REST_Request $request ) {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		$settings = Settings::get();
		$secret   = sanitize_text_field( (string) ( $settings['amazon_connect']['webhook_secret'] ?? '' ) );

		if ( '' === $secret ) {
			return new WP_Error( 'ace_bot_runtime_secret_missing', __( 'Save a webhook secret before exposing the live site-context bot API.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$provided_secret = sanitize_text_field( (string) ( $request->get_header( 'x-ace-webhook-secret' ) ?: $request->get_param( 'secret' ) ) );

		if ( '' === $provided_secret || ! hash_equals( $secret, $provided_secret ) ) {
			return new WP_Error( 'ace_bot_runtime_forbidden', __( 'The bot runtime secret is invalid.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Build a rate-limit bucket for frontend chat routes.
	 *
	 * @param string $suffix            Bucket suffix.
	 * @param string $conversation_uuid Conversation UUID.
	 * @param string $session_uuid      Session UUID.
	 * @param string $visitor_uuid      Visitor UUID.
	 * @return string
	 */
	private function build_ai_chat_bucket( string $suffix, string $conversation_uuid = '', string $session_uuid = '', string $visitor_uuid = '' ): string {
		$parts   = array_filter(
			array(
				$this->privacy->hash_ip( $this->privacy->get_client_ip() ),
				$suffix,
				$conversation_uuid,
				$session_uuid,
				$visitor_uuid,
			)
		);
		$parts[] = 'shared';

		return implode( '|', $parts );
	}
}
