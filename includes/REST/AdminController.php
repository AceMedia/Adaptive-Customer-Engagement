<?php
/**
 * Admin REST controller.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\REST;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\EventRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\NumberRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
use ACE\AdaptiveCustomerEngagement\Security\Capabilities;
use ACE\AdaptiveCustomerEngagement\Settings;
use ACE\AdaptiveCustomerEngagement\Tracking\Privacy;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class AdminController {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private $namespace = 'adaptive-customer-engagement/v1';

	/**
	 * Session repository.
	 *
	 * @var SessionRepository
	 */
	private $sessions;

	/**
	 * Event repository.
	 *
	 * @var EventRepository
	 */
	private $events;

	/**
	 * Number repository.
	 *
	 * @var NumberRepository
	 */
	private $numbers;

	/**
	 * Privacy helper.
	 *
	 * @var Privacy
	 */
	private $privacy;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository $sessions Session repository.
	 * @param EventRepository   $events   Event repository.
	 * @param NumberRepository  $numbers  Number repository.
	 * @param Privacy           $privacy  Privacy helper.
	 */
	public function __construct( SessionRepository $sessions, EventRepository $events, NumberRepository $numbers, Privacy $privacy ) {
		$this->sessions = $sessions;
		$this->events   = $events;
		$this->numbers  = $numbers;
		$this->privacy  = $privacy;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/admin/dashboard',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'dashboard' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/sessions',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'sessions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/numbers',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'numbers' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'create_number' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/numbers/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'update_number' ),
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'delete_number' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/settings',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'get_settings' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'save_settings' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/privacy/purge',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'purge_privacy' ),
			)
		);
	}

	/**
	 * Capability check.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Dashboard data.
	 *
	 * @return WP_REST_Response
	 */
	public function dashboard(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'metrics'         => array(
					'sessions_today'       => $this->sessions->count_today(),
					'returning_sessions'   => $this->sessions->count_returning_today(),
					'click_to_call_events' => $this->events->count_click_to_call_today(),
					'likely_business_visits' => 0,
					'ignored_traffic'      => $this->sessions->count_ignored_today(),
				),
				'top_pages'       => $this->events->get_top_pages(),
				'recent_sessions' => array_slice( $this->sessions->get_recent_sessions( 10 ), 0, 10 ),
				'hot_companies'   => array(),
			)
		);
	}

	/**
	 * Sessions data.
	 *
	 * @return WP_REST_Response
	 */
	public function sessions(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => $this->sessions->get_recent_sessions() ) );
	}

	/**
	 * Numbers data.
	 *
	 * @return WP_REST_Response
	 */
	public function numbers(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => $this->numbers->all() ) );
	}

	/**
	 * Create a number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_number( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->numbers->create( $this->sanitize_number_payload( $request->get_json_params() ) ), 201 );
	}

	/**
	 * Update a number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_number( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->numbers->update( absint( $request['id'] ), $this->sanitize_number_payload( $request->get_json_params() ) ) );
	}

	/**
	 * Delete a number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_number( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'deleted' => $this->numbers->delete( absint( $request['id'] ) ) ) );
	}

	/**
	 * Read settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( Settings::get() );
	}

	/**
	 * Save settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();

		return new WP_REST_Response( Settings::update( is_array( $payload ) ? $payload : array() ) );
	}

	/**
	 * Purge privacy data.
	 *
	 * @return WP_REST_Response
	 */
	public function purge_privacy(): WP_REST_Response {
		return new WP_REST_Response( $this->privacy->purge_expired_raw_data() );
	}

	/**
	 * Sanitize number payloads.
	 *
	 * @param mixed $payload Raw payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_number_payload( $payload ): array {
		$payload = is_array( $payload ) ? $payload : array();

		return array(
			'label'                          => sanitize_text_field( (string) ( $payload['label'] ?? '' ) ),
			'display_number'                 => sanitize_text_field( (string) ( $payload['display_number'] ?? '' ) ),
			'e164_number'                    => sanitize_text_field( (string) ( $payload['e164_number'] ?? '' ) ),
			'source_type'                    => sanitize_key( (string) ( $payload['source_type'] ?? 'default' ) ),
			'source_value'                   => sanitize_text_field( (string) ( $payload['source_value'] ?? '' ) ),
			'page_match_type'                => sanitize_key( (string) ( $payload['page_match_type'] ?? 'contains' ) ),
			'page_match_value'               => sanitize_text_field( (string) ( $payload['page_match_value'] ?? '' ) ),
			'campaign_match'                 => sanitize_text_field( (string) ( $payload['campaign_match'] ?? '' ) ),
			'amazon_connect_phone_number_id' => sanitize_text_field( (string) ( $payload['amazon_connect_phone_number_id'] ?? '' ) ),
			'amazon_connect_contact_flow_id' => sanitize_text_field( (string) ( $payload['amazon_connect_contact_flow_id'] ?? '' ) ),
			'is_default'                     => rest_sanitize_boolean( $payload['is_default'] ?? false ) ? 1 : 0,
			'is_active'                      => rest_sanitize_boolean( $payload['is_active'] ?? true ) ? 1 : 0,
			'priority'                       => absint( $payload['priority'] ?? 10 ),
		);
	}
}
