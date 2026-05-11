<?php
/**
 * Public tracking REST controller.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\REST;

use ACE\AdaptiveCustomerEngagement\AI\FrontendChatService;
use ACE\AdaptiveCustomerEngagement\AI\SiteContextService;
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

		$payload  = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$response = $this->frontend_chat->respond( $payload );

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
}
