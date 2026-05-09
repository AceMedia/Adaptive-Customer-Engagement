<?php
/**
 * Admin REST controller.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\REST;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\CallRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\EventRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\NumberRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository;
use ACE\AdaptiveCustomerEngagement\Enrichment\EnrichmentService;
use ACE\AdaptiveCustomerEngagement\Security\Capabilities;
use ACE\AdaptiveCustomerEngagement\Settings;
use ACE\AdaptiveCustomerEngagement\Tracking\LeadScorer;
use ACE\AdaptiveCustomerEngagement\Tracking\Privacy;
use WP_Error;
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
	 * Company repository.
	 *
	 * @var CompanyRepository
	 */
	private $companies;

	/**
	 * Call repository.
	 *
	 * @var CallRepository
	 */
	private $calls;

	/**
	 * Privacy helper.
	 *
	 * @var Privacy
	 */
	private $privacy;

	/**
	 * Lead scorer.
	 *
	 * @var LeadScorer
	 */
	private $lead_scorer;

	/**
	 * Enrichment service.
	 *
	 * @var EnrichmentService
	 */
	private $enrichment_service;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository $sessions Session repository.
	 * @param EventRepository   $events   Event repository.
	 * @param NumberRepository  $numbers  Number repository.
	 * @param Privacy           $privacy  Privacy helper.
	 */
	public function __construct( SessionRepository $sessions, EventRepository $events, NumberRepository $numbers, CompanyRepository $companies, CallRepository $calls, Privacy $privacy, EnrichmentService $enrichment_service ) {
		$this->sessions     = $sessions;
		$this->events       = $events;
		$this->numbers      = $numbers;
		$this->companies    = $companies;
		$this->calls        = $calls;
		$this->privacy      = $privacy;
		$this->lead_scorer  = new LeadScorer();
		$this->enrichment_service = $enrichment_service;
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
			'/admin/sessions/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'session_detail' ),
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
			'/admin/companies',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'companies' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/companies/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'company_detail' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/calls',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'calls' ),
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
			'/admin/reporting-segments',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'reporting_segments' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'save_reporting_segment' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/reporting-segments/(?P<id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'DELETE',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'delete_reporting_segment' ),
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

		register_rest_route(
			$this->namespace,
			'/admin/enrichment/test',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'test_enrichment' ),
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
		$recent_sessions = array_map( array( $this, 'decorate_session_summary' ), array_slice( $this->sessions->get_recent_sessions( 10 ), 0, 10 ) );

		return new WP_REST_Response(
			array(
				'metrics'         => array(
					'sessions_today'         => $this->sessions->count_today(),
					'returning_sessions'     => $this->sessions->count_returning_today(),
					'click_to_call_events'   => $this->events->count_click_to_call_today(),
					'download_events'        => $this->events->count_today_by_type( 'download' ),
					'form_submissions'       => $this->events->count_today_by_type( 'form_submit' ),
					'likely_business_visits' => $this->sessions->count_likely_business_today(),
					'ignored_traffic'        => $this->sessions->count_ignored_today(),
				),
				'top_pages'          => $this->events->get_top_pages(),
				'recent_sessions'    => $recent_sessions,
				'hot_companies'      => array_map( array( $this, 'decorate_company_summary' ), $this->companies->get_hot_companies() ),
				'segment_shortcuts'  => array(
					'sessions'  => Settings::get_reporting_segments( 'sessions' ),
					'companies' => Settings::get_reporting_segments( 'companies' ),
				),
			)
		);
	}

	/**
	 * Sessions data.
	 *
	 * @return WP_REST_Response
	 */
	public function sessions( WP_REST_Request $request ): WP_REST_Response {
		$filters = $this->get_list_filters( $request );
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
		$total   = $this->sessions->count_recent_sessions( $filters );
		$pages   = max( 1, (int) ceil( $total / $per_page ) );

		return new WP_REST_Response(
			array(
				'items'   => array_map( array( $this, 'decorate_session_summary' ), $this->sessions->get_recent_sessions( $per_page, $filters, ( $page - 1 ) * $per_page ) ),
				'filters' => array(
					'sources'      => $this->sessions->get_sources(),
					'confidences'  => array( 'unknown', 'weak', 'likely', 'confirmed', 'ignore' ),
				),
				'segments' => Settings::get_reporting_segments( 'sessions' ),
				'pagination' => array(
					'page'      => $page,
					'per_page'  => $per_page,
					'total'     => $total,
					'total_pages' => $pages,
				),
			)
		);
	}

	/**
	 * Session detail data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function session_detail( WP_REST_Request $request ) {
		$session_id = absint( $request['id'] );
		$session    = $this->sessions->get_session_detail( $session_id );

		if ( ! $session ) {
			return new WP_Error( 'ace_session_not_found', __( 'Session not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		$events = $this->events->get_by_session( $session_id );

		return new WP_REST_Response(
			array(
				'session' => $this->decorate_session_summary( $session ),
				'events'  => $events,
			)
		);
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
	 * Companies data.
	 *
	 * @return WP_REST_Response
	 */
	public function companies( WP_REST_Request $request ): WP_REST_Response {
		$filters = $this->get_list_filters( $request );
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
		$total   = $this->companies->count_companies( $filters );
		$pages   = max( 1, (int) ceil( $total / $per_page ) );

		return new WP_REST_Response(
			array(
				'items'   => array_map( array( $this, 'decorate_company_summary' ), $this->companies->get_companies( $per_page, $filters, ( $page - 1 ) * $per_page ) ),
				'filters' => array(
					'providers'    => $this->companies->get_sources(),
					'confidences'  => array( 'unknown', 'weak', 'likely', 'confirmed', 'ignore' ),
				),
				'segments' => Settings::get_reporting_segments( 'companies' ),
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => $pages,
				),
			)
		);
	}

	/**
	 * Company detail data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function company_detail( WP_REST_Request $request ) {
		$company = $this->companies->get_company_detail( absint( $request['id'] ) );

		if ( ! $company ) {
			return new WP_Error( 'ace_company_not_found', __( 'Company not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		if ( ! empty( $company['recent_sessions'] ) && is_array( $company['recent_sessions'] ) ) {
			$company['recent_sessions'] = array_map( array( $this, 'decorate_session_summary' ), $company['recent_sessions'] );
		}

		return new WP_REST_Response( $this->decorate_company_summary( $company ) );
	}

	/**
	 * Calls data.
	 *
	 * @return WP_REST_Response
	 */
	public function calls(): WP_REST_Response {
		$total_calls   = $this->calls->count_all();
		$matched_calls = $this->calls->count_matched();

		return new WP_REST_Response(
			array(
				'metrics'              => array(
					'click_to_call_today' => $this->events->count_click_to_call_today(),
					'stored_calls_today'  => $this->calls->count_today(),
					'matched_calls_today' => $this->calls->count_matched( true ),
					'stored_calls_total'  => $total_calls,
					'matched_calls_total' => $matched_calls,
					'unmatched_calls'     => max( 0, $total_calls - $matched_calls ),
				),
				'top_call_paths'       => $this->events->get_top_call_paths(),
				'call_intent_sessions' => array_map( array( $this, 'decorate_session_summary' ), $this->events->get_recent_call_intent_sessions() ),
				'recent_calls'         => $this->calls->get_recent_calls(),
			)
		);
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
	 * Export filtered sessions as CSV.
	 *
	 * @return void
	 */
	public function export_sessions(): void {
		$this->assert_export_access();

		$filters = $this->get_list_filters_from_array( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total   = $this->sessions->count_recent_sessions( $filters );
		$items   = $total > 0 ? $this->sessions->get_recent_sessions( $total, $filters, 0 ) : array();
		$items   = array_map( array( $this, 'decorate_session_summary' ), $items );

		$this->stream_csv(
			'ace-sessions-export-' . gmdate( 'Ymd-His' ) . '.csv',
			array(
				'session_uuid'       => 'Session UUID',
				'visitor_uuid'       => 'Visitor UUID',
				'landing_path'       => 'Landing page',
				'referrer'           => 'Referrer',
				'utm_source'         => 'Source',
				'utm_campaign'       => 'Campaign',
				'company_name'       => 'Company',
				'company_confidence' => 'Confidence',
				'event_count'        => 'Events',
				'call_clicks'        => 'Call clicks',
				'download_count'     => 'Downloads',
				'form_count'         => 'Form submissions',
				'score'              => 'Score',
				'score_label'        => 'Score label',
				'score_summary'      => 'Score summary',
				'last_seen'          => 'Last seen',
			),
			$items
		);
	}

	/**
	 * Export filtered companies as CSV.
	 *
	 * @return void
	 */
	public function export_companies(): void {
		$this->assert_export_access();

		$filters = $this->get_list_filters_from_array( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total   = $this->companies->count_companies( $filters );
		$items   = $total > 0 ? $this->companies->get_companies( $total, $filters, 0 ) : array();

		$this->stream_csv(
			'ace-companies-export-' . gmdate( 'Ymd-His' ) . '.csv',
			array(
				'name'            => 'Company',
				'domain'          => 'Domain',
				'type'            => 'Type',
				'country_code'    => 'Country',
				'source_provider' => 'Provider',
				'confidence'      => 'Confidence',
				'total_sessions'  => 'Sessions',
				'total_events'    => 'Events',
				'total_calls'     => 'Calls',
				'priority_score'  => 'Priority score',
				'priority_label'  => 'Priority label',
				'priority_summary'=> 'Priority summary',
				'first_seen'      => 'First seen',
				'last_seen'       => 'Last seen',
			),
			array_map( array( $this, 'decorate_company_summary' ), $items )
		);
	}

	/**
	 * Read reporting segments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function reporting_segments( WP_REST_Request $request ): WP_REST_Response {
		$view = sanitize_key( (string) $request->get_param( 'view' ) );

		return new WP_REST_Response(
			array(
				'items' => Settings::get_reporting_segments( $view ),
			)
		);
	}

	/**
	 * Save a reporting segment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_reporting_segment( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$segment = $this->sanitize_reporting_segment_payload( $payload );

		if ( '' === $segment['name'] ) {
			return new WP_Error( 'ace_segment_name_required', __( 'Please provide a name for the saved segment.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $segment['view'], array( 'sessions', 'companies' ), true ) ) {
			return new WP_Error( 'ace_segment_view_invalid', __( 'Please provide a valid reporting view.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$saved = Settings::add_reporting_segment( $segment );

		return new WP_REST_Response(
			array(
				'item'  => $saved,
				'items' => Settings::get_reporting_segments( $saved['view'] ),
			),
			201
		);
	}

	/**
	 * Delete a reporting segment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_reporting_segment( WP_REST_Request $request ) {
		$segment_id = sanitize_text_field( (string) $request['id'] );
		$deleted    = Settings::delete_reporting_segment( $segment_id );

		if ( ! $deleted ) {
			return new WP_Error( 'ace_segment_not_found', __( 'Saved segment not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'items'   => Settings::get_reporting_segments(),
			)
		);
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
	 * Run an enrichment test lookup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_enrichment( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$ip      = sanitize_text_field( (string) ( $payload['ip'] ?? '' ) );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'ace_invalid_ip', __( 'Please provide a valid IP address.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$result = $this->enrichment_service->test_lookup( $ip );

		if ( ! $result ) {
			return new WP_Error( 'ace_enrichment_unavailable', __( 'Enrichment is not configured.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $result );
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

	/**
	 * Sanitize reporting segment payloads.
	 *
	 * @param mixed $payload Raw payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_reporting_segment_payload( $payload ): array {
		$payload = is_array( $payload ) ? $payload : array();
		$filters = isset( $payload['filters'] ) && is_array( $payload['filters'] ) ? $payload['filters'] : array();

		return array(
			'name'    => sanitize_text_field( (string) ( $payload['name'] ?? '' ) ),
			'view'    => sanitize_key( (string) ( $payload['view'] ?? '' ) ),
			'filters' => array(
				'search'     => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
				'confidence' => sanitize_key( (string) ( $filters['confidence'] ?? '' ) ),
				'source'     => sanitize_text_field( (string) ( $filters['source'] ?? '' ) ),
				'provider'   => sanitize_text_field( (string) ( $filters['provider'] ?? '' ) ),
				'date_from'  => sanitize_text_field( (string) ( $filters['date_from'] ?? '' ) ),
				'date_to'    => sanitize_text_field( (string) ( $filters['date_to'] ?? '' ) ),
			),
		);
	}

	/**
	 * Verify export access and nonce.
	 *
	 * @return void
	 */
	private function assert_export_access(): void {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to export this data.', 'adaptive-customer-engagement' ), 403 );
		}

		check_admin_referer( 'ace_export_report' );
	}

	/**
	 * Decorate a session row with score metadata.
	 *
	 * @param array<string, mixed> $session Session data.
	 * @return array<string, mixed>
	 */
	private function decorate_session_summary( array $session ): array {
		return array_merge( $session, $this->lead_scorer->score_session( $session ) );
	}

	/**
	 * Decorate a company row with priority metadata.
	 *
	 * @param array<string, mixed> $company Company data.
	 * @return array<string, mixed>
	 */
	private function decorate_company_summary( array $company ): array {
		return array_merge( $company, $this->lead_scorer->score_company( $company ) );
	}

	/**
	 * Read common list filters from the request.
	 *
	 * @return array<string, string>
	 */
	private function get_list_filters( WP_REST_Request $request ): array {
		return $this->get_list_filters_from_array(
			array(
				'search'     => $request->get_param( 'search' ),
				'confidence' => $request->get_param( 'confidence' ),
				'source'     => $request->get_param( 'source' ),
				'provider'   => $request->get_param( 'provider' ),
				'date_from'  => $request->get_param( 'date_from' ),
				'date_to'    => $request->get_param( 'date_to' ),
			)
		);
	}

	/**
	 * Sanitize common list filters from a raw array.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, string>
	 */
	private function get_list_filters_from_array( array $values ): array {
		return array(
			'search'     => sanitize_text_field( (string) ( $values['search'] ?? '' ) ),
			'confidence' => sanitize_key( (string) ( $values['confidence'] ?? '' ) ),
			'source'     => sanitize_text_field( (string) ( $values['source'] ?? '' ) ),
			'provider'   => sanitize_text_field( (string) ( $values['provider'] ?? '' ) ),
			'date_from'  => sanitize_text_field( (string) ( $values['date_from'] ?? '' ) ),
			'date_to'    => sanitize_text_field( (string) ( $values['date_to'] ?? '' ) ),
		);
	}

	/**
	 * Stream CSV output and end the request.
	 *
	 * @param string                              $filename Output filename.
	 * @param array<string, string>               $columns  Column map.
	 * @param array<int, array<string, mixed>>    $rows     Row data.
	 * @return void
	 */
	private function stream_csv( string $filename, array $columns, array $rows ): void {
		$stream = fopen( 'php://temp', 'r+' );

		if ( false === $stream ) {
			wp_die( esc_html__( 'The export file could not be created.', 'adaptive-customer-engagement' ), 500 );
		}

		fputcsv( $stream, array_values( $columns ) );

		foreach ( $rows as $row ) {
			$line = array();

			foreach ( array_keys( $columns ) as $key ) {
				$value  = $row[ $key ] ?? '';
				$line[] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			}

			fputcsv( $stream, $line );
		}

		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );

		echo is_string( $csv ) ? $csv : '';
		exit;
	}
}
