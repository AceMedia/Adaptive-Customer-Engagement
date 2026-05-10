<?php
/**
 * Admin REST controller.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\REST;

use ACE\AdaptiveCustomerEngagement\AmazonConnect\Client as AmazonConnectClient;
use ACE\AdaptiveCustomerEngagement\Admin\SampleDataSeeder;
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
	 * Sample data seeder.
	 *
	 * @var SampleDataSeeder
	 */
	private $sample_data;

	/**
	 * Amazon Connect client.
	 *
	 * @var AmazonConnectClient
	 */
	private $connect;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository $sessions Session repository.
	 * @param EventRepository   $events   Event repository.
	 * @param NumberRepository  $numbers  Number repository.
	 * @param Privacy           $privacy  Privacy helper.
	 */
	public function __construct( SessionRepository $sessions, EventRepository $events, NumberRepository $numbers, CompanyRepository $companies, CallRepository $calls, Privacy $privacy, EnrichmentService $enrichment_service, SampleDataSeeder $sample_data, AmazonConnectClient $connect ) {
		$this->sessions           = $sessions;
		$this->events             = $events;
		$this->numbers            = $numbers;
		$this->companies          = $companies;
		$this->calls              = $calls;
		$this->privacy            = $privacy;
		$this->lead_scorer        = new LeadScorer();
		$this->enrichment_service = $enrichment_service;
		$this->sample_data        = $sample_data;
		$this->connect            = $connect;
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
			'/admin/commerce',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'commerce' ),
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
			'/admin/calls/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'call_detail' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/numbers/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'number_detail' ),
				),
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
			'/chat-widget/token',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'create_connect_widget_token' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect-readiness',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'connect_readiness' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/resources',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'connect_resources' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/assistants',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'connect_assistants' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'create_connect_assistant' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/contact-flows',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'connect_contact_flows' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'create_connect_contact_flow' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/queue-flows',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'connect_queue_flows' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/queues',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'connect_queues' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/phone-numbers/search',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'search_connect_phone_numbers' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/phone-numbers/claim',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'claim_connect_phone_number' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/phone-numbers/sync',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'sync_connect_phone_numbers' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/calls/import-status',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'connect_call_import_status' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/connect/calls/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'import_connect_calls' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/numbers/(?P<id>\d+)/connect-sync',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'sync_number_from_connect' ),
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

		register_rest_route(
			$this->namespace,
			'/admin/sample-data',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'sample_data_status' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'seed_sample_data' ),
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => array( $this, 'reset_sample_data' ),
				),
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
	public function dashboard( WP_REST_Request $request ): WP_REST_Response {
		$filters         = $this->get_list_filters_from_array(
			array(
				'date_from' => $request->get_param( 'date_from' ),
				'date_to'   => $request->get_param( 'date_to' ),
			)
		);
		$call_filters    = $this->get_call_filters_from_array(
			array(
				'date_from' => $request->get_param( 'date_from' ),
				'date_to'   => $request->get_param( 'date_to' ),
			)
		);
		$recent_sessions = array_map( array( $this, 'decorate_session_summary' ), array_slice( $this->sessions->get_recent_sessions( 10, $filters ), 0, 10 ) );
		$numbers         = $this->numbers->all();

		return new WP_REST_Response(
			array(
				'filters'         => $filters,
				'metrics'         => array(
					'sessions'               => $this->sessions->count_recent_sessions( $filters ),
					'returning_sessions'     => $this->sessions->count_returning( $filters ),
					'click_to_call_events'   => $this->events->count_by_type( 'click_to_call', $filters ),
					'download_events'        => $this->events->count_by_type( 'download', $filters ),
					'form_submissions'       => $this->events->count_by_type( 'form_submit', $filters ),
					'likely_business_visits' => $this->sessions->count_likely_business( $filters ),
					'stored_calls'           => $this->calls->count_calls( $call_filters ),
					'matched_calls'          => $this->calls->count_matched_filtered( $call_filters ),
				),
				'top_pages'          => $this->events->get_top_pages( 6, $filters ),
				'top_sources'        => $this->sessions->get_top_sources( 8, $filters ),
				'top_call_paths'     => $this->events->get_top_call_paths( 8, $call_filters ),
				'recent_sessions'    => $recent_sessions,
				'recent_calls'       => $this->calls->get_calls( 20, $call_filters ),
				'numbers'            => $numbers,
				'hot_companies'      => array_map( array( $this, 'decorate_company_summary' ), $this->companies->get_hot_companies() ),
				'sample_data'        => $this->sample_data->get_status(),
				'segment_shortcuts'  => array(
					'sessions'  => Settings::get_reporting_segments( 'sessions' ),
					'companies' => Settings::get_reporting_segments( 'companies' ),
					'calls'     => Settings::get_reporting_segments( 'calls' ),
					'commerce'  => Settings::get_reporting_segments( 'commerce' ),
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
				'session'  => $this->decorate_session_summary( $session ),
				'events'   => $events,
				'calls'    => $this->calls->get_calls_by_session( $session_id, 12 ),
				'commerce' => $this->events->get_session_woocommerce_interest( $session_id ),
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

		$company = $this->decorate_company_summary( $company );
		$company['recent_calls'] = $this->calls->get_calls_by_company( (int) $company['id'], 12 );
		$company['commerce'] = $this->events->get_company_woocommerce_interest( (int) $company['id'] );

		return new WP_REST_Response( $company );
	}

	/**
	 * WooCommerce reporting data.
	 *
	 * @return WP_REST_Response
	 */
	public function commerce(): WP_REST_Response {
		$filters = $this->get_commerce_filters_from_array(
			array(
				'search'      => isset( $_GET['search'] ) ? wp_unslash( $_GET['search'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'date_from'   => isset( $_GET['date_from'] ) ? wp_unslash( $_GET['date_from'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'date_to'     => isset( $_GET['date_to'] ) ? wp_unslash( $_GET['date_to'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'repeat_only' => isset( $_GET['repeat_only'] ) ? wp_unslash( $_GET['repeat_only'] ) : '1', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			)
		);
		$report  = $this->events->get_woocommerce_interest_report( $filters );

		return new WP_REST_Response(
			array(
				'metrics'          => $report['metrics'],
				'filters'          => $filters,
				'segments'         => Settings::get_reporting_segments( 'commerce' ),
				'top_products'     => $report['top_products'],
				'top_categories'   => $report['top_categories'],
				'repeat_sessions'  => array_map( array( $this, 'decorate_session_summary' ), $report['repeat_sessions'] ),
				'repeat_companies' => array_map( array( $this, 'decorate_company_summary' ), $report['repeat_companies'] ),
			)
		);
	}

	/**
	 * Calls data.
	 *
	 * @return WP_REST_Response
	 */
	public function calls( WP_REST_Request $request ): WP_REST_Response {
		$filters       = $this->get_call_filters( $request );
		$total_calls   = $this->calls->count_all();
		$matched_calls = $this->calls->count_matched();
		$filtered_total = $this->calls->count_calls( $filters );
		$import_summary = $this->calls->get_connect_import_summary();

		return new WP_REST_Response(
			array(
				'metrics'              => array(
					'click_to_call_today' => $this->events->count_click_to_call_today(),
					'stored_calls_today'  => $this->calls->count_today(),
					'matched_calls_today' => $this->calls->count_matched( true ),
					'stored_calls_total'  => $total_calls,
					'matched_calls_total' => $matched_calls,
					'unmatched_calls'     => max( 0, $total_calls - $matched_calls ),
					'connect_imported_total' => $import_summary['imported_total'],
					'filtered_calls'      => $filtered_total,
				),
				'filters'              => array(
					'statuses' => $this->calls->get_statuses(),
					'active'   => $filters,
				),
				'segments'             => Settings::get_reporting_segments( 'calls' ),
				'top_call_paths'       => $this->events->get_top_call_paths( 8, $filters ),
				'call_intent_sessions' => array_map( array( $this, 'decorate_session_summary' ), $this->events->get_recent_call_intent_sessions( 20, $filters ) ),
				'recent_calls'         => $this->calls->get_calls( 50, $filters ),
			)
		);
	}

	/**
	 * Call detail data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function call_detail( WP_REST_Request $request ) {
		$call = $this->calls->get_call_detail( absint( $request['id'] ) );

		if ( ! $call ) {
			return new WP_Error( 'ace_call_not_found', __( 'Call not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'call'          => $call,
				'number_calls'  => ! empty( $call['number_id'] ) ? $this->calls->get_calls_by_number( (int) $call['number_id'], 12 ) : array(),
				'session_events'=> ! empty( $call['matched_session_id'] ) ? $this->events->get_by_session( (int) $call['matched_session_id'] ) : array(),
			)
		);
	}

	/**
	 * Number detail data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function number_detail( WP_REST_Request $request ) {
		$number = $this->numbers->get_detail( absint( $request['id'] ) );

		if ( ! $number ) {
			return new WP_Error( 'ace_number_not_found', __( 'Number not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		$connect_number       = null;
		$connect_number_error = '';

		if ( ! empty( $number['amazon_connect_phone_number_id'] ) ) {
			$connect_number = $this->connect->describe_phone_number( (string) $number['amazon_connect_phone_number_id'] );

			if ( is_wp_error( $connect_number ) ) {
				$connect_number_error = $connect_number->get_error_message();
				$connect_number       = null;
			} else {
				$connect_number = $this->sanitize_connect_phone_number( $connect_number );
			}
		}

		return new WP_REST_Response(
			array(
				'number'               => $number,
				'connect_number'       => $connect_number,
				'connect_number_error' => $connect_number_error,
				'recent_calls'         => $this->calls->get_calls_by_number( (int) $number['id'], 20 ),
			)
		);
	}

	/**
	 * Create a number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_number( WP_REST_Request $request ) {
		$payload = $this->sanitize_number_payload( $request->get_json_params() );
		$sync    = $this->maybe_sync_connect_number_flow( $payload );

		if ( is_wp_error( $sync ) ) {
			return $sync;
		}

		return new WP_REST_Response( $this->numbers->create( $payload ), 201 );
	}

	/**
	 * Update a number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_number( WP_REST_Request $request ) {
		$payload = $this->sanitize_number_payload( $request->get_json_params() );
		$sync    = $this->maybe_sync_connect_number_flow( $payload );

		if ( is_wp_error( $sync ) ) {
			return $sync;
		}

		return new WP_REST_Response( $this->numbers->update( absint( $request['id'] ), $payload ) );
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
		$payload = is_array( $payload ) ? $payload : array();
		$payload = $this->maybe_enrich_connect_settings( $payload );

		return new WP_REST_Response( Settings::update( $payload ) );
	}

	/**
	 * Create a JWT for a secured Amazon Connect chat widget.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_connect_widget_token( WP_REST_Request $request ) {
		$settings       = Settings::get();
		$amazon_connect = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();
		$ai_agent       = isset( $settings['ai_agent'] ) && is_array( $settings['ai_agent'] ) ? $settings['ai_agent'] : array();
		$admin_only     = ! empty( $ai_agent['frontend_test_admin_only'] );

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_test_enabled'] ) ) {
			return new WP_Error( 'ace_connect_widget_disabled', __( 'The frontend test chat is not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( $admin_only && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_connect_widget_forbidden', __( 'This chat token is only available to logged-in administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$widget_id    = sanitize_text_field( (string) ( $amazon_connect['chat_widget_id'] ?? '' ) );
		$security_key = sanitize_text_field( (string) ( $amazon_connect['chat_widget_security_key'] ?? '' ) );

		if ( '' === $widget_id || '' === $security_key ) {
			return new WP_Error( 'ace_connect_widget_security_missing', __( 'The chat widget ID or security key is missing.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$payload    = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$attributes = $this->sanitize_connect_widget_attributes(
			array(
				'ace_session_id' => $payload['session_uuid'] ?? '',
				'ace_visitor_id' => $payload['visitor_uuid'] ?? '',
				'ace_page_url'   => $payload['page_url'] ?? '',
				'ace_page_title' => $payload['page_title'] ?? '',
			)
		);
		$token      = $this->build_connect_widget_token( $widget_id, $security_key, $attributes );

		return new WP_REST_Response(
			array(
				'token' => $token,
			)
		);
	}

	/**
	 * Read live Connect resources for setup screens.
	 *
	 * @return WP_REST_Response
	 */
	public function connect_resources(): WP_REST_Response {
		$phone_numbers = $this->connect->list_phone_numbers();
		$assistants    = $this->connect->list_assistants();
		$contact_flows = $this->connect->list_contact_flows( array( 'CONTACT_FLOW' ) );
		$queue_flows   = $this->connect->list_contact_flows( array( 'CUSTOMER_QUEUE' ) );
		$queues        = $this->connect->list_queues();

		return new WP_REST_Response(
			array(
				'phone_numbers' => is_wp_error( $phone_numbers ) ? array() : array_map( array( $this, 'sanitize_connect_phone_number' ), $phone_numbers ),
				'assistants'    => is_wp_error( $assistants ) ? array() : array_map( array( $this, 'sanitize_connect_assistant' ), $assistants ),
				'contact_flows' => is_wp_error( $contact_flows ) ? array() : array_map( array( $this, 'sanitize_connect_contact_flow' ), $contact_flows ),
				'queue_flows'   => is_wp_error( $queue_flows ) ? array() : array_map( array( $this, 'sanitize_connect_contact_flow' ), $queue_flows ),
				'queues'        => is_wp_error( $queues ) ? array() : array_map( array( $this, 'sanitize_connect_queue' ), $queues ),
				'errors'        => array(
					'phone_numbers' => is_wp_error( $phone_numbers ) ? $phone_numbers->get_error_message() : '',
					'assistants'    => is_wp_error( $assistants ) ? $assistants->get_error_message() : '',
					'contact_flows' => is_wp_error( $contact_flows ) ? $contact_flows->get_error_message() : '',
					'queue_flows'   => is_wp_error( $queue_flows ) ? $queue_flows->get_error_message() : '',
					'queues'        => is_wp_error( $queues ) ? $queues->get_error_message() : '',
				),
			)
		);
	}

	/**
	 * Read Amazon Q in Connect assistants only.
	 *
	 * @return WP_REST_Response
	 */
	public function connect_assistants(): WP_REST_Response {
		$assistants = $this->connect->list_assistants();

		return new WP_REST_Response(
			array(
				'items' => is_wp_error( $assistants ) ? array() : array_map( array( $this, 'sanitize_connect_assistant' ), $assistants ),
				'error' => is_wp_error( $assistants ) ? $assistants->get_error_message() : '',
			)
		);
	}

	/**
	 * Create an Amazon Q in Connect assistant.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_connect_assistant( WP_REST_Request $request ) {
		$payload         = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$name            = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
		$description     = sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) );
		$link_to_ai_agent = ! array_key_exists( 'link_to_ai_agent', $payload ) || rest_sanitize_boolean( $payload['link_to_ai_agent'] );

		if ( '' === $name ) {
			return new WP_Error( 'ace_connect_assistant_name_required', __( 'Please provide an assistant name.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$assistant = $this->connect->create_assistant( $name, $description );

		if ( is_wp_error( $assistant ) ) {
			return $assistant;
		}

		$sanitized = $this->sanitize_connect_assistant( $assistant );
		$settings  = null;

		if ( $link_to_ai_agent && ! empty( $sanitized['assistantId'] ) ) {
			$settings                                  = Settings::get();
			$settings['ai_agent']['provider']          = 'amazon_q_connect';
			$settings['ai_agent']['assistant_id']      = $sanitized['assistantId'];
			$settings['ai_agent']['assistant_arn']     = $sanitized['assistantArn'];
			$settings                                  = Settings::update( $settings );
		}

		$assistants = $this->connect->list_assistants();

		return new WP_REST_Response(
			array(
				'item'     => $sanitized,
				'items'    => is_wp_error( $assistants ) ? array( $sanitized ) : array_map( array( $this, 'sanitize_connect_assistant' ), $assistants ),
				'settings' => $settings,
			),
			201
		);
	}

	/**
	 * Read Amazon Connect contact flows.
	 *
	 * @return WP_REST_Response
	 */
	public function connect_contact_flows(): WP_REST_Response {
		$flows = $this->connect->list_contact_flows( array( 'CONTACT_FLOW' ) );

		return new WP_REST_Response(
			array(
				'items' => is_wp_error( $flows ) ? array() : array_map( array( $this, 'sanitize_connect_contact_flow' ), $flows ),
				'error' => is_wp_error( $flows ) ? $flows->get_error_message() : '',
			)
		);
	}

	/**
	 * Read Amazon Connect customer queue flows.
	 *
	 * @return WP_REST_Response
	 */
	public function connect_queue_flows(): WP_REST_Response {
		$flows = $this->connect->list_contact_flows( array( 'CUSTOMER_QUEUE' ) );

		return new WP_REST_Response(
			array(
				'items' => is_wp_error( $flows ) ? array() : array_map( array( $this, 'sanitize_connect_contact_flow' ), $flows ),
				'error' => is_wp_error( $flows ) ? $flows->get_error_message() : '',
			)
		);
	}

	/**
	 * Read Amazon Connect queues.
	 *
	 * @return WP_REST_Response
	 */
	public function connect_queues(): WP_REST_Response {
		$queues = $this->connect->list_queues();

		return new WP_REST_Response(
			array(
				'items' => is_wp_error( $queues ) ? array() : array_map( array( $this, 'sanitize_connect_queue' ), $queues ),
				'error' => is_wp_error( $queues ) ? $queues->get_error_message() : '',
			)
		);
	}

	/**
	 * Create a standard Amazon Connect contact flow.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_connect_contact_flow( WP_REST_Request $request ) {
		$payload        = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$template_type  = sanitize_key( (string) ( $payload['template_type'] ?? 'message_disconnect' ) );
		$name           = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
		$description    = sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) );
		$message        = sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) );
		$queue_id       = sanitize_text_field( (string) ( $payload['queue_id'] ?? '' ) );
		$queue_flow_id  = sanitize_text_field( (string) ( $payload['queue_flow_id'] ?? '' ) );
		$failure_message = sanitize_textarea_field( (string) ( $payload['failure_message'] ?? '' ) );
		$target_phone_number = sanitize_text_field( (string) ( $payload['target_phone_number'] ?? '' ) );
		$caller_id_number    = sanitize_text_field( (string) ( $payload['caller_id_number'] ?? '' ) );
		$timeout_seconds     = max( 5, min( 180, absint( $payload['timeout_seconds'] ?? 30 ) ) );
		$dtmf_sequence       = sanitize_text_field( (string) ( $payload['dtmf_sequence'] ?? '' ) );
		$resume_after_disconnect = rest_sanitize_boolean( $payload['resume_after_disconnect'] ?? false );
		$set_as_default = rest_sanitize_boolean( $payload['set_as_default'] ?? false );

		if ( '' === $name ) {
			return new WP_Error( 'ace_connect_flow_name_required', __( 'Please provide a flow name.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( '' === $message ) {
			return new WP_Error( 'ace_connect_flow_message_required', __( 'Please provide the spoken message for the flow.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( 'queue_transfer' === $template_type && '' === $queue_id ) {
			return new WP_Error( 'ace_connect_queue_required', __( 'Please choose the Amazon Connect queue for this template.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( 'call_forward' === $template_type && ! preg_match( '/^\+[1-9]\d{1,14}$/', $target_phone_number ) ) {
			return new WP_Error( 'ace_connect_forward_number_required', __( 'Please provide a valid E.164 destination number for the call-forwarding template.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( '' !== $caller_id_number && ! preg_match( '/^\+[1-9]\d{1,14}$/', $caller_id_number ) ) {
			return new WP_Error( 'ace_connect_invalid_caller_id', __( 'Please provide a valid E.164 caller ID number or leave it blank.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( 'queue_transfer' === $template_type ) {
			$content = $this->build_queue_transfer_contact_flow_content( $message, $queue_id, $failure_message, $queue_flow_id );
		} elseif ( 'customer_queue' === $template_type ) {
			$content = $this->build_customer_queue_flow_content( $message );
		} elseif ( 'call_forward' === $template_type ) {
			$content = $this->build_call_forward_contact_flow_content( $message, $target_phone_number, $failure_message, $caller_id_number, $timeout_seconds, $dtmf_sequence, $resume_after_disconnect );
		} else {
			$content = $this->build_standard_contact_flow_content( $message );
		}

		$flow_type = 'customer_queue' === $template_type ? 'CUSTOMER_QUEUE' : 'CONTACT_FLOW';

		$flow = $this->connect->create_contact_flow(
			$name,
			$description,
			$content,
			$flow_type
		);

		if ( is_wp_error( $flow ) ) {
			return $flow;
		}

		$sanitized_flow = $this->sanitize_connect_contact_flow( $flow );

		if ( $set_as_default && ! empty( $sanitized_flow['Id'] ) ) {
			$settings                                      = Settings::get();
			$settings['amazon_connect']['default_contact_flow_id'] = $sanitized_flow['Id'];
			Settings::update( $settings );
		}

		return new WP_REST_Response(
			array(
				'item'     => $sanitized_flow,
				'settings' => $set_as_default ? Settings::get() : null,
			),
			201
		);
	}

	/**
	 * Search available Connect phone numbers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_connect_phone_numbers( WP_REST_Request $request ) {
		$payload      = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$country_code = sanitize_text_field( (string) ( $payload['country_code'] ?? 'GB' ) );
		$type         = sanitize_text_field( (string) ( $payload['phone_number_type'] ?? 'TOLL_FREE' ) );
		$prefix       = sanitize_text_field( (string) ( $payload['phone_number_prefix'] ?? '' ) );
		$items        = $this->connect->search_available_phone_numbers( $country_code, $type, $prefix );

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		return new WP_REST_Response(
			array(
				'items' => array_map( array( $this, 'sanitize_available_connect_phone_number' ), $items ),
			)
		);
	}

	/**
	 * Claim a Connect phone number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function claim_connect_phone_number( WP_REST_Request $request ) {
		$payload      = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$phone_number = sanitize_text_field( (string) ( $payload['phone_number'] ?? '' ) );
		$description  = sanitize_text_field( (string) ( $payload['description'] ?? '' ) );
		$auto_link    = ! array_key_exists( 'auto_link', $payload ) || rest_sanitize_boolean( $payload['auto_link'] );

		if ( ! preg_match( '/^\+[1-9]\d{1,14}$/', $phone_number ) ) {
			return new WP_Error( 'ace_connect_invalid_phone_number', __( 'Please provide a valid E.164 phone number to claim.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$item = $this->connect->claim_phone_number( $phone_number, $description );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$local_number = null;

		if ( $auto_link ) {
			$local_number = $this->upsert_local_connect_number( $item );

			if ( is_wp_error( $local_number ) ) {
				return $local_number;
			}
		}

		return new WP_REST_Response(
			array(
				'item'   => $this->sanitize_connect_phone_number( $item ),
				'number' => is_array( $local_number ) ? $local_number['number'] : null,
			),
			201
		);
	}

	/**
	 * Sync all visible claimed Connect phone numbers into local tracking records.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_connect_phone_numbers() {
		$items = $this->connect->list_phone_numbers();

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$summary = array(
			'created' => 0,
			'updated' => 0,
		);
		$synced  = array();

		foreach ( $items as $item ) {
			$result = $this->upsert_local_connect_number( $item );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( empty( $result['number'] ) || ! is_array( $result['number'] ) ) {
				continue;
			}

			$summary[ $result['action'] ] = isset( $summary[ $result['action'] ] ) ? ( $summary[ $result['action'] ] + 1 ) : 1;
			$synced[]                     = $result['number'];
		}

		return new WP_REST_Response(
			array(
				'items'   => array_map( array( $this, 'sanitize_connect_phone_number' ), $items ),
				'numbers' => $synced,
				'summary' => $summary,
			)
		);
	}

	/**
	 * Refresh a linked tracking number from Amazon Connect.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_number_from_connect( WP_REST_Request $request ) {
		$number = $this->numbers->find( absint( $request['id'] ) );

		if ( ! $number ) {
			return new WP_Error( 'ace_number_not_found', __( 'Number not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		$phone_number_id = sanitize_text_field( (string) ( $number['amazon_connect_phone_number_id'] ?? '' ) );

		if ( '' === $phone_number_id ) {
			return new WP_Error( 'ace_number_not_connect_linked', __( 'This tracking rule is not linked to a live Amazon Connect number yet.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$connect_number = $this->connect->describe_phone_number( $phone_number_id );

		if ( is_wp_error( $connect_number ) ) {
			return $connect_number;
		}

		$result = $this->upsert_local_connect_number( $connect_number, $number );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'action'         => $result['action'],
				'number'         => $this->numbers->get_detail( (int) $result['number']['id'] ),
				'connect_number' => $this->sanitize_connect_phone_number( $connect_number ),
			)
		);
	}

	/**
	 * Read the current Amazon Connect call-import summary.
	 *
	 * @return WP_REST_Response
	 */
	public function connect_call_import_status(): WP_REST_Response {
		$settings       = Settings::get();
		$amazon_connect = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();

		return new WP_REST_Response(
			array(
				'summary'  => $this->calls->get_connect_import_summary(),
				'last_run' => Settings::get_connect_import_status(),
				'config'  => array(
					's3_bucket'      => sanitize_text_field( (string) ( $amazon_connect['s3_bucket'] ?? '' ) ),
					's3_prefix'      => sanitize_text_field( (string) ( $amazon_connect['s3_prefix'] ?? '' ) ),
					'default_window' => 72,
				),
			)
		);
	}

	/**
	 * Import recent Amazon Connect call records from the configured S3 export path.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_connect_calls( WP_REST_Request $request ) {
		$payload        = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$lookback_hours = max( 1, min( 720, absint( $payload['lookback_hours'] ?? 72 ) ) );
		$max_objects    = max( 1, min( 200, absint( $payload['max_objects'] ?? 50 ) ) );
		$started_at     = current_time( 'mysql', true );
		$settings       = Settings::get();
		$amazon_connect = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();
		$bucket         = sanitize_text_field( (string) ( $amazon_connect['s3_bucket'] ?? '' ) );
		$prefix         = sanitize_text_field( (string) ( $amazon_connect['s3_prefix'] ?? '' ) );

		if ( '' === $bucket || '' === $prefix ) {
			Settings::update_connect_import_status(
				array(
					'status'         => 'error',
					'started_at'     => $started_at,
					'completed_at'   => current_time( 'mysql', true ),
					'lookback_hours' => $lookback_hours,
					'max_objects'    => $max_objects,
					'errors'         => array( __( 'Please save the Amazon Connect export bucket and prefix before importing calls.', 'adaptive-customer-engagement' ) ),
				)
			);

			return new WP_Error( 'ace_connect_import_not_configured', __( 'Please save the Amazon Connect export bucket and prefix before importing calls.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$objects = $this->get_recent_connect_export_objects( $bucket, $prefix, $max_objects, $lookback_hours );

		if ( is_wp_error( $objects ) ) {
			Settings::update_connect_import_status(
				array(
					'status'         => 'error',
					'started_at'     => $started_at,
					'completed_at'   => current_time( 'mysql', true ),
					'lookback_hours' => $lookback_hours,
					'max_objects'    => $max_objects,
					'errors'         => array( $objects->get_error_message() ),
				)
			);

			return $objects;
		}

		$summary = array(
			'status'          => 'success',
			'started_at'      => $started_at,
			'completed_at'    => '',
			'lookback_hours'  => $lookback_hours,
			'max_objects'     => $max_objects,
			'objects_scanned' => 0,
			'records_found'   => 0,
			'created'         => 0,
			'updated'         => 0,
			'matched'         => 0,
			'number_matched'  => 0,
			'errors'          => array(),
		);
		$items    = array();
		$company_ids = array();

		foreach ( $objects as $object ) {
			$content = $this->connect->get_s3_object( $bucket, (string) $object['Key'] );

			if ( is_wp_error( $content ) ) {
				$summary['errors'][] = sprintf(
					/* translators: 1: object key, 2: error message */
					__( '%1$s could not be imported: %2$s', 'adaptive-customer-engagement' ),
					(string) $object['Key'],
					$content->get_error_message()
				);
				continue;
			}

			$records = $this->extract_connect_contact_trace_records( $content );
			++$summary['objects_scanned'];
			$summary['records_found'] += count( $records );

			foreach ( $records as $record ) {
				$prepared = $this->prepare_connect_call_import_payload( $record, (string) $object['Key'] );

				if ( null === $prepared ) {
					continue;
				}

				$existing = $this->calls->find_by_amazon_contact_id( $prepared['amazon_contact_id'] );
				$call     = $this->calls->upsert_imported_call( $prepared );

				if ( ! $call ) {
					continue;
				}

				$summary[ $existing ? 'updated' : 'created' ]++;

				if ( ! empty( $call['number_id'] ) ) {
					++$summary['number_matched'];
				}

				if ( ! empty( $call['matched_session_id'] ) || ! empty( $call['matched_company_id'] ) ) {
					++$summary['matched'];
				}

				if ( ! empty( $call['matched_company_id'] ) ) {
					$company_ids[] = (int) $call['matched_company_id'];
				}

				if ( $existing && ! empty( $existing['matched_company_id'] ) ) {
					$company_ids[] = (int) $existing['matched_company_id'];
				}

				if ( count( $items ) < 10 ) {
					$items[] = $call;
				}
			}
		}

		foreach ( array_unique( array_filter( array_map( 'intval', $company_ids ) ) ) as $company_id ) {
			$this->companies->sync_call_total( $company_id );
		}

		$summary['completed_at'] = current_time( 'mysql', true );
		$summary['status']       = empty( $summary['errors'] ) ? 'success' : 'warning';
		$last_run                = Settings::update_connect_import_status( $summary );

		return new WP_REST_Response(
			array(
				'summary'  => $summary,
				'items'    => $items,
				'status'   => $this->calls->get_connect_import_summary(),
				'last_run' => $last_run,
			)
		);
	}

	/**
	 * Read Amazon Connect testing readiness.
	 *
	 * @return WP_REST_Response
	 */
	public function connect_readiness(): WP_REST_Response {
		$settings               = Settings::get();
		$amazon_connect         = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();
		$number_summary         = $this->numbers->get_setup_summary();
		$tracking_enabled       = ! empty( $settings['enabled'] ) && ! empty( $settings['tracking']['track_pageviews'] );
		$has_instance_details   = ! empty( $amazon_connect['region'] ) && ! empty( $amazon_connect['instance_id'] );
		$has_storage_target     = ! empty( $amazon_connect['s3_bucket'] ) && ! empty( $amazon_connect['s3_prefix'] );
		$has_flow_logs          = ! empty( $amazon_connect['flow_logs_group'] );
		$has_contact_flow       = ! empty( $amazon_connect['default_contact_flow_id'] );
		$has_credentials_mode   = ! empty( $amazon_connect['use_iam_role'] ) || ( ! empty( $amazon_connect['access_key_id'] ) && ! empty( $amazon_connect['secret_access_key'] ) );
		$has_number_mapping     = $number_summary['numbers_with_connect_phone_id'] > 0 || $number_summary['numbers_with_connect_flow_id'] > 0;
		$has_active_number      = $number_summary['active_numbers'] > 0;
		$has_default_number     = $number_summary['active_default_numbers'] > 0;
		$ready_for_testing      = $tracking_enabled && $has_active_number && $has_default_number && $has_instance_details && $has_storage_target && $has_flow_logs && $has_credentials_mode;
		$checklist              = array(
			array(
				'key'         => 'tracking',
				'label'       => __( 'Tracking is enabled', 'adaptive-customer-engagement' ),
				'status'      => $tracking_enabled ? 'complete' : 'attention',
				'description' => __( 'Phase 1 only really counts as done if the first-party tracking spine is still switched on.', 'adaptive-customer-engagement' ),
			),
			array(
				'key'         => 'active-number',
				'label'       => __( 'At least one active tracking number exists', 'adaptive-customer-engagement' ),
				'status'      => $has_active_number ? 'complete' : 'attention',
				'description' => __( 'Connect testing is much easier once there is already a live number-routing baseline in place.', 'adaptive-customer-engagement' ),
			),
			array(
				'key'         => 'default-number',
				'label'       => __( 'An active default number is configured', 'adaptive-customer-engagement' ),
				'status'      => $has_default_number ? 'complete' : 'attention',
				'description' => __( 'Keep one safe fallback number in place before testing imported numbers or flow-specific routing.', 'adaptive-customer-engagement' ),
			),
			array(
				'key'         => 'instance',
				'label'       => __( 'Amazon Connect instance details are saved', 'adaptive-customer-engagement' ),
				'status'      => $has_instance_details ? 'complete' : 'attention',
				'description' => __( 'Save the region and instance ID here so the later import and sync work has a single home.', 'adaptive-customer-engagement' ),
			),
			array(
				'key'         => 'storage',
				'label'       => __( 'S3 export destination is noted', 'adaptive-customer-engagement' ),
				'status'      => $has_storage_target ? 'complete' : 'attention',
				'description' => __( 'Keep the bucket and prefix recorded now so contact trace records and later import work point at the right place.', 'adaptive-customer-engagement' ),
			),
			array(
				'key'         => 'flow-logs',
				'label'       => __( 'Flow log group is recorded', 'adaptive-customer-engagement' ),
				'status'      => $has_flow_logs ? 'complete' : 'attention',
				'description' => __( 'Store the CloudWatch log group now so flow-level debugging has a clear starting point once the instance is live.', 'adaptive-customer-engagement' ),
			),
			array(
				'key'         => 'credentials',
				'label'       => __( 'A credentials strategy is chosen', 'adaptive-customer-engagement' ),
				'status'      => $has_credentials_mode ? 'complete' : 'attention',
				'description' => __( 'Either IAM role usage or stored access keys should be decided before the first connection tests begin.', 'adaptive-customer-engagement' ),
			),
			array(
				'key'         => 'contact-flow',
				'label'       => __( 'A default contact flow is ready', 'adaptive-customer-engagement' ),
				'status'      => $has_contact_flow ? 'complete' : 'recommended',
				'description' => __( 'This is not mandatory for the first connection check, but it will help as soon as I start aligning numbers and flow behaviour.', 'adaptive-customer-engagement' ),
			),
			array(
				'key'         => 'number-mapping',
				'label'       => __( 'At least one number already carries Connect identifiers', 'adaptive-customer-engagement' ),
				'status'      => $has_number_mapping ? 'complete' : 'recommended',
				'description' => __( 'This helps the later matching and sync work, but it can be added after the initial connection is proven.', 'adaptive-customer-engagement' ),
			),
		);

		return new WP_REST_Response(
			array(
				'summary'   => array(
					'tracking_enabled'       => $tracking_enabled,
					'total_numbers'          => $number_summary['total_numbers'],
					'active_numbers'         => $number_summary['active_numbers'],
					'active_default_numbers' => $number_summary['active_default_numbers'],
					'connect_phone_ids'      => $number_summary['numbers_with_connect_phone_id'],
					'connect_flow_ids'       => $number_summary['numbers_with_connect_flow_id'],
					'stored_calls_total'     => $this->calls->count_all(),
					'matched_calls_total'    => $this->calls->count_matched(),
					'company_records_total'  => $this->companies->count_companies(),
					'is_ready_for_testing'   => $ready_for_testing,
				),
				'checklist' => $checklist,
			)
		);
	}

	/**
	 * Read sample-data status.
	 *
	 * @return WP_REST_Response
	 */
	public function sample_data_status(): WP_REST_Response {
		return new WP_REST_Response( $this->sample_data->get_status() );
	}

	/**
	 * Seed local sample data.
	 *
	 * @return WP_REST_Response
	 */
	public function seed_sample_data(): WP_REST_Response {
		return new WP_REST_Response( $this->sample_data->seed(), 201 );
	}

	/**
	 * Remove local sample data.
	 *
	 * @return WP_REST_Response
	 */
	public function reset_sample_data(): WP_REST_Response {
		return new WP_REST_Response( $this->sample_data->reset() );
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
	 * Export filtered stored calls as CSV.
	 *
	 * @return void
	 */
	public function export_calls(): void {
		$this->assert_export_access();

		$filters = $this->get_call_filters_from_array( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total   = $this->calls->count_calls( $filters );
		$items   = $total > 0 ? $this->calls->get_calls( $total, $filters, 0 ) : array();

		$this->stream_csv(
			'ace-calls-export-' . gmdate( 'Ymd-His' ) . '.csv',
			array(
				'started_at'        => 'Started',
				'status'            => 'Status',
				'called_number'     => 'Called number',
				'number_label'      => 'Tracking number',
				'company_name'      => 'Company',
				'session_uuid'      => 'Session UUID',
				'duration_seconds'  => 'Duration seconds',
				'match_confidence'  => 'Match confidence',
				'queue_name'        => 'Queue',
				'agent_name'        => 'Agent',
			),
			$items
		);
	}

	/**
	 * Export WooCommerce reporting datasets as CSV.
	 *
	 * @return void
	 */
	public function export_commerce(): void {
		$this->assert_export_access();

		$dataset = sanitize_key( (string) ( $_GET['dataset'] ?? 'products' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters = $this->get_commerce_filters_from_array( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$exports = $this->events->get_woocommerce_interest_exports( $filters );

		switch ( $dataset ) {
			case 'categories':
				$this->stream_csv(
					'ace-woocommerce-categories-' . gmdate( 'Ymd-His' ) . '.csv',
					array(
						'name'         => 'Category',
						'slug'         => 'Slug',
						'views'        => 'Views',
						'repeat_views' => 'Highest repeat count',
					),
					$exports['categories']
				);
				break;
			case 'sessions':
				$this->stream_csv(
					'ace-woocommerce-sessions-' . gmdate( 'Ymd-His' ) . '.csv',
					array(
						'session_uuid'             => 'Session UUID',
						'landing_path'             => 'Landing page',
						'company_name'             => 'Company',
						'utm_source'               => 'Source',
						'utm_campaign'             => 'Campaign',
						'repeat_interest_summary'  => 'WooCommerce summary',
						'product_repeat_max'       => 'Highest product repeat count',
						'category_repeat_max'      => 'Highest category repeat count',
						'last_seen'                => 'Last seen',
					),
					array_map( array( $this, 'decorate_session_summary' ), $exports['sessions'] )
				);
				break;
			case 'companies':
				$this->stream_csv(
					'ace-woocommerce-companies-' . gmdate( 'Ymd-His' ) . '.csv',
					array(
						'name'                     => 'Company',
						'domain'                   => 'Domain',
						'confidence'               => 'Confidence',
						'repeat_interest_summary'  => 'WooCommerce summary',
						'product_repeat_max'       => 'Highest product repeat count',
						'category_repeat_max'      => 'Highest category repeat count',
						'total_sessions'           => 'Sessions',
						'total_events'             => 'Events',
						'last_seen'                => 'Last seen',
					),
					array_map( array( $this, 'decorate_company_summary' ), $exports['companies'] )
				);
				break;
			case 'products':
			default:
				$this->stream_csv(
					'ace-woocommerce-products-' . gmdate( 'Ymd-His' ) . '.csv',
					array(
						'name'         => 'Product',
						'slug'         => 'Slug',
						'views'        => 'Views',
						'repeat_views' => 'Highest repeat count',
					),
					$exports['products']
				);
		}
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

		if ( ! in_array( $segment['view'], array( 'sessions', 'companies', 'calls', 'commerce' ), true ) ) {
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
		$source_type  = sanitize_key( (string) ( $payload['source_type'] ?? 'default' ) );
		$source_value = sanitize_text_field( (string) ( $payload['source_value'] ?? '' ) );

		return array(
			'label'                          => sanitize_text_field( (string) ( $payload['label'] ?? '' ) ),
			'display_number'                 => sanitize_text_field( (string) ( $payload['display_number'] ?? '' ) ),
			'e164_number'                    => sanitize_text_field( (string) ( $payload['e164_number'] ?? '' ) ),
			'source_type'                    => $source_type,
			'source_value'                   => $this->get_default_number_source_value( $source_type, $source_value ),
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
	 * Associate a Connect-linked number with its chosen contact flow when both IDs are present.
	 *
	 * @param array<string, mixed> $payload Number payload.
	 * @return true|WP_Error
	 */
	private function maybe_sync_connect_number_flow( array $payload ) {
		$phone_number_id = sanitize_text_field( (string) ( $payload['amazon_connect_phone_number_id'] ?? '' ) );
		$contact_flow_id = sanitize_text_field( (string) ( $payload['amazon_connect_contact_flow_id'] ?? '' ) );

		if ( '' === $phone_number_id || '' === $contact_flow_id ) {
			return true;
		}

		return $this->connect->associate_phone_number_contact_flow( $phone_number_id, $contact_flow_id );
	}

	/**
	 * Get the configured default Connect flow ID.
	 *
	 * @return string
	 */
	private function get_default_connect_contact_flow_id(): string {
		$settings = Settings::get();

		return sanitize_text_field( (string) ( $settings['amazon_connect']['default_contact_flow_id'] ?? '' ) );
	}

	/**
	 * Create or update a local tracking number from a live Connect phone number.
	 *
	 * @param array<string, mixed>      $connect_number Raw/sanitized Connect phone number.
	 * @param array<string, mixed>|null $existing       Optional existing local number.
	 * @return array<string, mixed>|WP_Error
	 */
	private function upsert_local_connect_number( array $connect_number, ?array $existing = null ) {
		$phone_number_id = sanitize_text_field( (string) ( $connect_number['PhoneNumberId'] ?? '' ) );
		$phone_number    = sanitize_text_field( (string) ( $connect_number['PhoneNumber'] ?? '' ) );

		if ( '' === $phone_number_id || '' === $phone_number ) {
			return new WP_Error( 'ace_connect_phone_number_invalid', __( 'Amazon Connect did not return a complete phone-number record.', 'adaptive-customer-engagement' ) );
		}

		if ( ! $existing ) {
			$existing = $this->numbers->find_by_connect_phone_number_id( $phone_number_id );
		}

		if ( ! $existing ) {
			$existing = $this->numbers->find_by_e164_number( $phone_number );
		}

		$payload = $this->build_local_number_payload_from_connect_number( $connect_number, $existing );
		$sync    = $this->maybe_sync_connect_number_flow( $payload );

		if ( is_wp_error( $sync ) ) {
			return $sync;
		}

		if ( ! empty( $existing['id'] ) ) {
			$number = $this->numbers->update( (int) $existing['id'], $payload );

			return array(
				'action' => 'updated',
				'number' => $number,
			);
		}

		$number = $this->numbers->create( $payload );

		return array(
			'action' => 'created',
			'number' => $number,
		);
	}

	/**
	 * Build a local number payload from a live Connect phone number.
	 *
	 * @param array<string, mixed>      $connect_number Raw/sanitized Connect phone number.
	 * @param array<string, mixed>|null $existing       Optional existing number record.
	 * @return array<string, mixed>
	 */
	private function build_local_number_payload_from_connect_number( array $connect_number, ?array $existing = null ): array {
		$existing             = is_array( $existing ) ? $existing : array();
		$default_contact_flow = $this->get_default_connect_contact_flow_id();
		$description         = sanitize_text_field( (string) ( $connect_number['PhoneNumberDescription'] ?? '' ) );
		$phone_number        = sanitize_text_field( (string) ( $connect_number['PhoneNumber'] ?? '' ) );
		$phone_number_id     = sanitize_text_field( (string) ( $connect_number['PhoneNumberId'] ?? '' ) );
		$existing_source     = sanitize_key( (string) ( $existing['source_type'] ?? 'default' ) );
		$label               = sanitize_text_field( (string) ( $existing['label'] ?? '' ) );

		if ( '' === $label ) {
			$label = '' !== $description ? $description : $phone_number;
		}

		return array(
			'label'                          => $label,
			'display_number'                 => $phone_number,
			'e164_number'                    => $phone_number,
			'source_type'                    => $existing_source ?: 'default',
			'source_value'                   => $this->get_default_number_source_value(
				$existing_source ?: 'default',
				sanitize_text_field( (string) ( $existing['source_value'] ?? '' ) )
			),
			'page_match_type'                => sanitize_key( (string) ( $existing['page_match_type'] ?? 'contains' ) ),
			'page_match_value'               => sanitize_text_field( (string) ( $existing['page_match_value'] ?? '' ) ),
			'campaign_match'                 => sanitize_text_field( (string) ( $existing['campaign_match'] ?? '' ) ),
			'amazon_connect_phone_number_id' => $phone_number_id,
			'amazon_connect_contact_flow_id' => sanitize_text_field( (string) ( $existing['amazon_connect_contact_flow_id'] ?? $default_contact_flow ) ),
			'is_default'                     => ! empty( $existing['is_default'] ) ? 1 : 0,
			'is_active'                      => array_key_exists( 'is_active', $existing ) ? ( ! empty( $existing['is_active'] ) ? 1 : 0 ) : 1,
			'priority'                       => isset( $existing['priority'] ) ? absint( $existing['priority'] ) : 10,
		);
	}

	/**
	 * Get a sensible default source value for a number rule.
	 *
	 * @param string $source_type  Source type.
	 * @param string $source_value Current source value.
	 * @return string
	 */
	private function get_default_number_source_value( string $source_type, string $source_value ): string {
		if ( '' !== $source_value ) {
			return $source_value;
		}

		$defaults = array(
			'website'                 => 'website',
			'campaign'                => 'newsletter',
			'google_business_profile' => 'google_business_profile',
			'bing'                    => 'bing',
			'social'                  => 'social',
			'product_page'            => 'product_page',
			'brand_page'              => 'brand_page',
			'brochure_qr'             => 'brochure_qr',
		);

		return $defaults[ $source_type ] ?? '';
	}

	/**
	 * Get recent eligible Connect export objects from S3.
	 *
	 * @param string $bucket         Bucket name.
	 * @param string $prefix         Object prefix.
	 * @param int    $max_objects    Maximum objects to return.
	 * @param int    $lookback_hours Lookback window in hours.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function get_recent_connect_export_objects( string $bucket, string $prefix, int $max_objects, int $lookback_hours ) {
		$collected   = array();
		$next_token  = '';
		$cutoff_time = time() - ( max( 1, $lookback_hours ) * HOUR_IN_SECONDS );
		$max_collect = max( 100, $max_objects * 4 );

		for ( $page = 0; $page < 10 && count( $collected ) < $max_collect; $page++ ) {
			$response = $this->connect->list_s3_objects( $bucket, $prefix, min( 250, $max_collect ), $next_token );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			foreach ( $response['items'] as $item ) {
				$key = sanitize_text_field( (string) ( $item['Key'] ?? '' ) );

				if ( '' === $key || '/' === substr( $key, -1 ) ) {
					continue;
				}

				$last_modified = $this->normalise_connect_timestamp( (string) ( $item['LastModified'] ?? '' ) );

				if ( '' !== $last_modified ) {
					$timestamp = strtotime( $last_modified . ' UTC' );

					if ( false !== $timestamp && $timestamp < $cutoff_time ) {
						continue;
					}
				}

				$collected[] = array(
					'Key'          => $key,
					'LastModified' => $last_modified,
					'Size'         => (int) ( $item['Size'] ?? 0 ),
				);
			}

			$next_token = sanitize_text_field( (string) ( $response['next_token'] ?? '' ) );

			if ( '' === $next_token ) {
				break;
			}
		}

		usort(
			$collected,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $right['LastModified'] ?? '' ), (string) ( $left['LastModified'] ?? '' ) );
			}
		);

		return array_slice( $collected, 0, $max_objects );
	}

	/**
	 * Extract contact-trace records from a Connect export object body.
	 *
	 * @param string $content Raw object body.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_connect_contact_trace_records( string $content ): array {
		$content = $this->maybe_decode_connect_export_content( $content );
		$content = trim( $content );

		if ( '' === $content ) {
			return array();
		}

		$data = json_decode( $content, true );

		if ( is_array( $data ) ) {
			if ( isset( $data['Records'] ) && is_array( $data['Records'] ) ) {
				return array_values( array_filter( $data['Records'], 'is_array' ) );
			}

			if ( isset( $data['ContactTraceRecords'] ) && is_array( $data['ContactTraceRecords'] ) ) {
				return array_values( array_filter( $data['ContactTraceRecords'], 'is_array' ) );
			}

			if ( isset( $data['ContactId'] ) ) {
				return array( $data );
			}

			if ( $this->is_list_array( $data ) ) {
				return array_values( array_filter( $data, 'is_array' ) );
			}
		}

		$records = array();
		$lines   = preg_split( '/\r\n|\r|\n/', $content );

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$item = json_decode( $line, true );

			if ( is_array( $item ) && isset( $item['ContactId'] ) ) {
				$records[] = $item;
			}
		}

		return $records;
	}

	/**
	 * Decode a Connect export body when S3 returns gzip-compressed CTR content.
	 *
	 * @param string $content Raw object body.
	 * @return string
	 */
	private function maybe_decode_connect_export_content( string $content ): string {
		if ( '' === $content || ! function_exists( 'gzdecode' ) ) {
			return $content;
		}

		if ( 0 === strpos( $content, "\x1f\x8b" ) ) {
			$decoded = gzdecode( $content );

			if ( false !== $decoded ) {
				return $decoded;
			}
		}

		return $content;
	}

	/**
	 * Prepare a stored call payload from a Connect contact-trace record.
	 *
	 * @param array<string, mixed> $record Raw contact-trace record.
	 * @param string               $s3_key Source S3 key.
	 * @return array<string, mixed>|null
	 */
	private function prepare_connect_call_import_payload( array $record, string $s3_key ): ?array {
		$contact_id = sanitize_text_field( (string) ( $record['ContactId'] ?? $record['contactId'] ?? '' ) );
		$channel    = strtoupper( sanitize_text_field( (string) ( $record['Channel'] ?? $record['channel'] ?? '' ) ) );

		if ( '' === $contact_id || ( '' !== $channel && 'VOICE' !== $channel ) ) {
			return null;
		}

		$started_at = $this->normalise_connect_timestamp(
			(string) (
				$record['InitiationTimestamp']
				?? $record['ConnectedToSystemTimestamp']
				?? $record['ConnectedToAgentTimestamp']
				?? $record['CreationTimestamp']
				?? ''
			)
		);

		if ( '' === $started_at ) {
			return null;
		}

		$ended_at      = $this->normalise_connect_timestamp( (string) ( $record['DisconnectTimestamp'] ?? $record['LastUpdateTimestamp'] ?? '' ) );
		$called_number = $this->normalise_phone_number_for_storage(
			(string) (
				$this->get_connect_record_value( $record, 'SystemEndpoint.Address' )
				?? $this->get_connect_record_value( $record, 'Endpoint.Address' )
				?? $record['CalledNumber']
				?? ''
			)
		);
		$caller_number = $this->normalise_phone_number_for_storage(
			(string) (
				$this->get_connect_record_value( $record, 'CustomerEndpoint.Address' )
				?? $record['CustomerNumber']
				?? ''
			)
		);
		$number        = '' !== $called_number ? $this->numbers->find_by_e164_number( $called_number ) : null;
		$event_match   = ! empty( $number['id'] ) ? $this->events->find_best_call_intent_match( (int) $number['id'], $started_at ) : null;
		$session_id    = ! empty( $event_match['session_id'] ) ? (int) $event_match['session_id'] : 0;
		$company_id    = ! empty( $event_match['company_id'] ) ? (int) $event_match['company_id'] : 0;
		$queue_name    = sanitize_text_field(
			(string) (
				$this->get_connect_record_value( $record, 'Queue.Name' )
				?? $this->get_connect_record_value( $record, 'QueueInfo.Name' )
				?? ''
			)
		);
		$agent_name    = sanitize_text_field(
			(string) (
				$this->get_connect_record_value( $record, 'Agent.Username' )
				?? $this->get_connect_record_value( $record, 'Agent.Name' )
				?? ''
			)
		);
		$attributes    = isset( $record['Attributes'] ) && is_array( $record['Attributes'] ) ? $record['Attributes'] : array();
		$duration      = 0;

		if ( '' !== $ended_at ) {
			$duration = max( 0, strtotime( $ended_at . ' UTC' ) - strtotime( $started_at . ' UTC' ) );
		}

		return array(
			'call_uuid'                => $this->build_deterministic_uuid( $contact_id ),
			'amazon_contact_id'        => $contact_id,
			'number_id'                => ! empty( $number['id'] ) ? (int) $number['id'] : null,
			'called_number'            => $called_number,
			'caller_number_hash'       => '' !== $caller_number ? $this->privacy->hash_value( $caller_number ) : '',
			'caller_number_raw'        => $caller_number,
			'caller_number_expires_at' => '' !== $caller_number ? $this->privacy->get_raw_phone_expiry() : '',
			'started_at'               => $started_at,
			'ended_at'                 => $ended_at,
			'duration_seconds'         => $duration,
			'direction'                => $this->derive_connect_call_direction( (string) ( $record['InitiationMethod'] ?? '' ) ),
			'status'                   => sanitize_text_field( (string) ( $record['DisconnectReason'] ?? $record['ContactState'] ?? ( '' !== $ended_at ? 'completed' : 'active' ) ) ),
			'queue_name'               => $queue_name,
			'agent_name'               => $agent_name,
			'matched_session_id'       => $session_id ?: null,
			'matched_company_id'       => $company_id ?: null,
			'match_confidence'         => $company_id ? 'confirmed' : ( $session_id ? 'likely' : ( ! empty( $number['id'] ) ? 'weak' : 'unknown' ) ),
			'attributes'               => array(
				'source'            => CallRepository::CONNECT_IMPORT_MARKER,
				's3_key'            => $s3_key,
				'channel'           => $channel ?: 'VOICE',
				'initiation_method' => sanitize_text_field( (string) ( $record['InitiationMethod'] ?? '' ) ),
				'event_match'       => $event_match ? array(
					'event_id'     => (int) ( $event_match['event_id'] ?? 0 ),
					'occurred_at'  => sanitize_text_field( (string) ( $event_match['occurred_at'] ?? '' ) ),
					'session_uuid' => sanitize_text_field( (string) ( $event_match['session_uuid'] ?? '' ) ),
					'company_name' => sanitize_text_field( (string) ( $event_match['company_name'] ?? '' ) ),
				) : null,
				'contact_attributes' => $this->sanitize_text_map( $attributes ),
			),
		);
	}

	/**
	 * Read a nested value from a Connect payload using dot notation.
	 *
	 * @param array<string, mixed> $record Raw record.
	 * @param string               $path   Dot-notated path.
	 * @return mixed|null
	 */
	private function get_connect_record_value( array $record, string $path ) {
		$segments = explode( '.', $path );
		$value    = $record;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Normalise a Connect timestamp to a UTC MySQL datetime string.
	 *
	 * @param string $value Raw timestamp.
	 * @return string
	 */
	private function normalise_connect_timestamp( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Normalise a phone number for storage and matching.
	 *
	 * @param string $value Raw phone number.
	 * @return string
	 */
	private function normalise_phone_number_for_storage( string $value ): string {
		$value = trim( preg_replace( '/^tel:/i', '', $value ) );
		$value = preg_replace( '/[^\d+]/', '', $value );
		$value = is_string( $value ) ? $value : '';

		if ( 0 === strpos( $value, '00' ) ) {
			$value = '+' . substr( $value, 2 );
		}

		if ( '' !== $value && '+' !== $value[0] ) {
			$value = preg_replace( '/\+/', '', $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Derive a simple inbound/outbound direction from a Connect initiation method.
	 *
	 * @param string $method Initiation method.
	 * @return string
	 */
	private function derive_connect_call_direction( string $method ): string {
		$method = strtoupper( sanitize_text_field( $method ) );

		if ( in_array( $method, array( 'OUTBOUND', 'API', 'TRANSFER', 'CALLBACK' ), true ) ) {
			return 'outbound';
		}

		return 'inbound';
	}

	/**
	 * Build a deterministic UUID-like identifier from a stable string.
	 *
	 * @param string $seed Source string.
	 * @return string
	 */
	private function build_deterministic_uuid( string $seed ): string {
		$hash = md5( $seed );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			substr( $hash, 12, 4 ),
			substr( $hash, 16, 4 ),
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * Sanitize a flat map of text values.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, string>
	 */
	private function sanitize_text_map( array $values ): array {
		$sanitized = array();

		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$sanitized[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Determine whether an array is a sequential list.
	 *
	 * @param array<mixed> $value Array value.
	 * @return bool
	 */
	private function is_list_array( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Sanitize a live Connect phone number payload.
	 *
	 * @param array<string, mixed> $item Raw number.
	 * @return array<string, mixed>
	 */
	private function sanitize_connect_phone_number( array $item ): array {
		$status = isset( $item['PhoneNumberStatus'] ) && is_array( $item['PhoneNumberStatus'] ) ? $item['PhoneNumberStatus'] : array();

		return array(
			'PhoneNumberId'          => sanitize_text_field( (string) ( $item['PhoneNumberId'] ?? '' ) ),
			'PhoneNumberArn'         => sanitize_text_field( (string) ( $item['PhoneNumberArn'] ?? '' ) ),
			'PhoneNumber'            => sanitize_text_field( (string) ( $item['PhoneNumber'] ?? '' ) ),
			'PhoneNumberCountryCode' => sanitize_text_field( (string) ( $item['PhoneNumberCountryCode'] ?? '' ) ),
			'PhoneNumberType'        => sanitize_text_field( (string) ( $item['PhoneNumberType'] ?? '' ) ),
			'PhoneNumberDescription' => sanitize_text_field( (string) ( $item['PhoneNumberDescription'] ?? '' ) ),
			'TargetArn'              => sanitize_text_field( (string) ( $item['TargetArn'] ?? '' ) ),
			'InstanceId'             => sanitize_text_field( (string) ( $item['InstanceId'] ?? '' ) ),
			'Status'                 => sanitize_text_field( (string) ( $status['Status'] ?? '' ) ),
			'StatusMessage'          => sanitize_text_field( (string) ( $status['Message'] ?? '' ) ),
		);
	}

	/**
	 * Sanitize a Connect contact flow payload.
	 *
	 * @param array<string, mixed> $item Raw flow.
	 * @return array<string, mixed>
	 */
	private function sanitize_connect_contact_flow( array $item ): array {
		return array(
			'Id'                => sanitize_text_field( (string) ( $item['Id'] ?? '' ) ),
			'Arn'               => sanitize_text_field( (string) ( $item['Arn'] ?? '' ) ),
			'Name'              => sanitize_text_field( (string) ( $item['Name'] ?? '' ) ),
			'Description'       => sanitize_textarea_field( (string) ( $item['Description'] ?? '' ) ),
			'ContactFlowType'   => sanitize_text_field( (string) ( $item['ContactFlowType'] ?? $item['Type'] ?? '' ) ),
			'ContactFlowStatus' => sanitize_text_field( (string) ( $item['ContactFlowStatus'] ?? $item['Status'] ?? '' ) ),
			'ContactFlowState'  => sanitize_text_field( (string) ( $item['ContactFlowState'] ?? $item['State'] ?? '' ) ),
		);
	}

	/**
	 * Sanitize a Connect queue payload.
	 *
	 * @param array<string, mixed> $item Raw queue.
	 * @return array<string, mixed>
	 */
	private function sanitize_connect_queue( array $item ): array {
		return array(
			'Id'        => sanitize_text_field( (string) ( $item['Id'] ?? '' ) ),
			'Arn'       => sanitize_text_field( (string) ( $item['Arn'] ?? '' ) ),
			'Name'      => sanitize_text_field( (string) ( $item['Name'] ?? '' ) ),
			'QueueType' => sanitize_text_field( (string) ( $item['QueueType'] ?? '' ) ),
		);
	}

	/**
	 * Build a safe standard flow-language payload for in-plugin flow creation.
	 *
	 * @param string $message Spoken prompt text.
	 * @return string
	 */
	private function build_standard_contact_flow_content( string $message ): string {
		$message_action_id    = wp_generate_uuid4();
		$disconnect_action_id = wp_generate_uuid4();
		$content              = array(
			'Version'     => '2019-10-30',
			'StartAction' => $message_action_id,
			'Metadata'    => array(
				'EntryPointPosition' => array(
					'x' => 88,
					'y' => 100,
				),
				'ActionMetadata'     => array(
					$message_action_id    => array(
						'Position' => array(
							'x' => 270,
							'y' => 98,
						),
					),
					$disconnect_action_id => array(
						'Position' => array(
							'x' => 545,
							'y' => 92,
						),
					),
				),
			),
			'Actions'     => array(
				array(
					'Identifier'  => $message_action_id,
					'Type'        => 'MessageParticipant',
					'Transitions' => array(
						'NextAction' => $disconnect_action_id,
						'Errors'     => array(),
						'Conditions' => array(),
					),
					'Parameters'  => array(
						'Text' => $message,
					),
				),
				array(
					'Identifier'  => $disconnect_action_id,
					'Type'        => 'DisconnectParticipant',
					'Transitions' => new \stdClass(),
					'Parameters'  => new \stdClass(),
				),
			),
		);

		return wp_json_encode( $content );
	}

	/**
	 * Build a queue-routing contact flow.
	 *
	 * @param string $message         Greeting text.
	 * @param string $queue_id        Queue ID.
	 * @param string $failure_message Fallback spoken text.
	 * @return string
	 */
	private function build_queue_transfer_contact_flow_content( string $message, string $queue_id, string $failure_message, string $queue_flow_id = '' ): string {
		$greeting_action_id      = wp_generate_uuid4();
		$set_queue_action_id     = wp_generate_uuid4();
		$set_queue_flow_action_id = wp_generate_uuid4();
		$transfer_queue_action_id = wp_generate_uuid4();
		$fallback_action_id      = wp_generate_uuid4();
		$disconnect_action_id    = wp_generate_uuid4();
		$fallback_text           = '' !== $failure_message ? $failure_message : __( 'Sorry, nobody is available just now. Please try again later.', 'adaptive-customer-engagement' );
		$content                 = array(
			'Version'     => '2019-10-30',
			'StartAction' => $greeting_action_id,
			'Metadata'    => array(
				'EntryPointPosition' => array(
					'x' => 88,
					'y' => 100,
				),
				'ActionMetadata'     => array(
					$greeting_action_id       => array( 'Position' => array( 'x' => 220, 'y' => 98 ) ),
					$set_queue_action_id      => array( 'Position' => array( 'x' => 460, 'y' => 98 ) ),
					$set_queue_flow_action_id => array( 'Position' => array( 'x' => 700, 'y' => 98 ) ),
					$transfer_queue_action_id => array( 'Position' => array( 'x' => 940, 'y' => 98 ) ),
					$fallback_action_id       => array( 'Position' => array( 'x' => 940, 'y' => 280 ) ),
					$disconnect_action_id     => array( 'Position' => array( 'x' => 1180, 'y' => 280 ) ),
				),
			),
			'Actions'     => array(
				array(
					'Identifier'  => $greeting_action_id,
					'Type'        => 'MessageParticipant',
					'Transitions' => array(
						'NextAction' => $set_queue_action_id,
						'Errors'     => array(
							array(
								'NextAction' => $fallback_action_id,
								'ErrorType'  => 'NoMatchingError',
							),
						),
						'Conditions' => array(),
					),
					'Parameters'  => array(
						'Text' => $message,
					),
				),
				array(
					'Identifier'  => $set_queue_action_id,
					'Type'        => 'UpdateContactTargetQueue',
					'Transitions' => array(
						'NextAction' => '' !== $queue_flow_id ? $set_queue_flow_action_id : $transfer_queue_action_id,
						'Errors'     => array(
							array(
								'NextAction' => $fallback_action_id,
								'ErrorType'  => 'NoMatchingError',
							),
						),
						'Conditions' => array(),
					),
					'Parameters'  => array(
						'QueueId' => $queue_id,
					),
				),
				array(
					'Identifier'  => $set_queue_flow_action_id,
					'Type'        => 'UpdateContactQueuedFlow',
					'Transitions' => array(
						'NextAction' => $transfer_queue_action_id,
						'Errors'     => array(
							array(
								'NextAction' => $fallback_action_id,
								'ErrorType'  => 'NoMatchingError',
							),
						),
						'Conditions' => array(),
					),
					'Parameters'  => array(
						'QueueFlowId' => $queue_flow_id,
					),
				),
				array(
					'Identifier'  => $transfer_queue_action_id,
					'Type'        => 'TransferContactToQueue',
					'Transitions' => array(
						'NextAction' => '',
						'Errors'     => array(
							array(
								'NextAction' => $fallback_action_id,
								'ErrorType'  => 'QueueAtCapacity',
							),
							array(
								'NextAction' => $fallback_action_id,
								'ErrorType'  => 'NoMatchingError',
							),
						),
						'Conditions' => array(),
					),
					'Parameters'  => new \stdClass(),
				),
				array(
					'Identifier'  => $fallback_action_id,
					'Type'        => 'MessageParticipant',
					'Transitions' => array(
						'NextAction' => $disconnect_action_id,
						'Errors'     => array(),
						'Conditions' => array(),
					),
					'Parameters'  => array(
						'Text' => $fallback_text,
					),
				),
				array(
					'Identifier'  => $disconnect_action_id,
					'Type'        => 'DisconnectParticipant',
					'Transitions' => new \stdClass(),
					'Parameters'  => new \stdClass(),
				),
			),
		);

		if ( '' === $queue_flow_id ) {
			$content['Actions'] = array_values(
				array_filter(
					$content['Actions'],
					static function ( array $action ) use ( $set_queue_flow_action_id ): bool {
						return $action['Identifier'] !== $set_queue_flow_action_id;
					}
				)
			);
			unset( $content['Metadata']['ActionMetadata'][ $set_queue_flow_action_id ] );
		}

		return wp_json_encode( $content );
	}

	/**
	 * Build a customer queue flow that plays a waiting message.
	 *
	 * @param string $message Queue/hold message.
	 * @return string
	 */
	private function build_customer_queue_flow_content( string $message ): string {
		$message_action_id = wp_generate_uuid4();
		$content           = array(
			'Version'     => '2019-10-30',
			'StartAction' => $message_action_id,
			'Metadata'    => array(
				'EntryPointPosition' => array(
					'x' => 88,
					'y' => 100,
				),
				'ActionMetadata'     => array(
					$message_action_id => array(
						'Position' => array(
							'x' => 270,
							'y' => 98,
						),
					),
				),
			),
			'Actions'     => array(
				array(
					'Identifier'  => $message_action_id,
					'Type'        => 'MessageParticipant',
					'Transitions' => array(
						'NextAction' => '',
						'Errors'     => array(),
						'Conditions' => array(),
					),
					'Parameters'  => array(
						'Text' => $message,
					),
				),
			),
		);

		return wp_json_encode( $content );
	}

	/**
	 * Build a call-forwarding contact flow.
	 *
	 * @param string $message                 Greeting text.
	 * @param string $target_phone_number     Forward target in E.164.
	 * @param string $failure_message         Fallback spoken text.
	 * @param string $caller_id_number        Optional caller ID number.
	 * @param int    $timeout_seconds         Transfer timeout.
	 * @param string $dtmf_sequence           Optional DTMF sequence.
	 * @param bool   $resume_after_disconnect Whether to resume the flow after disconnect.
	 * @return string
	 */
	private function build_call_forward_contact_flow_content( string $message, string $target_phone_number, string $failure_message, string $caller_id_number, int $timeout_seconds, string $dtmf_sequence, bool $resume_after_disconnect ): string {
		$greeting_action_id   = wp_generate_uuid4();
		$transfer_action_id   = wp_generate_uuid4();
		$fallback_action_id   = wp_generate_uuid4();
		$disconnect_action_id = wp_generate_uuid4();
		$fallback_text        = '' !== $failure_message ? $failure_message : __( 'Sorry, the forwarding destination is unavailable just now. Please try again later.', 'adaptive-customer-engagement' );
		$transfer_parameters  = array(
			'PhoneNumber' => $target_phone_number,
			'Timeout'     => $timeout_seconds,
		);

		if ( '' !== $caller_id_number ) {
			$transfer_parameters['CallerIdNumber'] = $caller_id_number;
		}

		if ( '' !== $dtmf_sequence ) {
			$transfer_parameters['DTMF'] = $dtmf_sequence;
		}

		if ( $resume_after_disconnect ) {
			$transfer_parameters['ResumeFlowAfterDisconnect'] = true;
		}

		$content = array(
			'Version'     => '2019-10-30',
			'StartAction' => $greeting_action_id,
			'Metadata'    => array(
				'EntryPointPosition' => array(
					'x' => 88,
					'y' => 100,
				),
				'ActionMetadata'     => array(
					$greeting_action_id   => array( 'Position' => array( 'x' => 220, 'y' => 98 ) ),
					$transfer_action_id   => array( 'Position' => array( 'x' => 470, 'y' => 98 ) ),
					$fallback_action_id   => array( 'Position' => array( 'x' => 700, 'y' => 280 ) ),
					$disconnect_action_id => array( 'Position' => array( 'x' => 940, 'y' => 280 ) ),
				),
			),
			'Actions'     => array(
				array(
					'Identifier'  => $greeting_action_id,
					'Type'        => 'MessageParticipant',
					'Transitions' => array(
						'NextAction' => $transfer_action_id,
						'Errors'     => array(
							array(
								'NextAction' => $fallback_action_id,
								'ErrorType'  => 'NoMatchingError',
							),
						),
						'Conditions' => array(),
					),
					'Parameters'  => array(
						'Text' => $message,
					),
				),
				array(
					'Identifier'  => $transfer_action_id,
					'Type'        => 'TransferParticipantToPhoneNumber',
					'Transitions' => array(
						'NextAction' => $resume_after_disconnect ? $disconnect_action_id : '',
						'Errors'     => array(
							array(
								'NextAction' => $fallback_action_id,
								'ErrorType'  => 'NoMatchingError',
							),
						),
						'Conditions' => array(),
					),
					'Parameters'  => $transfer_parameters,
				),
				array(
					'Identifier'  => $fallback_action_id,
					'Type'        => 'MessageParticipant',
					'Transitions' => array(
						'NextAction' => $disconnect_action_id,
						'Errors'     => array(),
						'Conditions' => array(),
					),
					'Parameters'  => array(
						'Text' => $fallback_text,
					),
				),
				array(
					'Identifier'  => $disconnect_action_id,
					'Type'        => 'DisconnectParticipant',
					'Transitions' => new \stdClass(),
					'Parameters'  => new \stdClass(),
				),
			),
		);

		return wp_json_encode( $content );
	}

	/**
	 * Sanitize an available Connect phone number payload.
	 *
	 * @param array<string, mixed> $item Raw number.
	 * @return array<string, mixed>
	 */
	private function sanitize_available_connect_phone_number( array $item ): array {
		return array(
			'PhoneNumber'            => sanitize_text_field( (string) ( $item['PhoneNumber'] ?? '' ) ),
			'PhoneNumberCountryCode' => sanitize_text_field( (string) ( $item['PhoneNumberCountryCode'] ?? '' ) ),
			'PhoneNumberType'        => sanitize_text_field( (string) ( $item['PhoneNumberType'] ?? '' ) ),
		);
	}

	/**
	 * Sanitize an Amazon Q in Connect assistant payload.
	 *
	 * @param array<string, mixed> $item Raw assistant.
	 * @return array<string, mixed>
	 */
	private function sanitize_connect_assistant( array $item ): array {
		return array(
			'assistantId'   => sanitize_text_field( (string) ( $item['assistantId'] ?? '' ) ),
			'assistantArn'  => sanitize_text_field( (string) ( $item['assistantArn'] ?? '' ) ),
			'name'          => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
			'description'   => sanitize_text_field( (string) ( $item['description'] ?? '' ) ),
			'status'        => sanitize_text_field( (string) ( $item['status'] ?? '' ) ),
			'type'          => sanitize_text_field( (string) ( $item['type'] ?? '' ) ),
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
				'status'     => sanitize_text_field( (string) ( $filters['status'] ?? '' ) ),
				'date_from'  => sanitize_text_field( (string) ( $filters['date_from'] ?? '' ) ),
				'date_to'    => sanitize_text_field( (string) ( $filters['date_to'] ?? '' ) ),
				'match_only' => rest_sanitize_boolean( $filters['match_only'] ?? false ) ? '1' : '',
				'repeat_only'=> rest_sanitize_boolean( $filters['repeat_only'] ?? false ) ? '1' : '',
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
	 * Sanitize WooCommerce report filters from a raw array.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, string>
	 */
	private function get_commerce_filters_from_array( array $values ): array {
		return array(
			'search'      => sanitize_text_field( (string) ( $values['search'] ?? '' ) ),
			'date_from'   => sanitize_text_field( (string) ( $values['date_from'] ?? '' ) ),
			'date_to'     => sanitize_text_field( (string) ( $values['date_to'] ?? '' ) ),
			'repeat_only' => rest_sanitize_boolean( $values['repeat_only'] ?? true ) ? '1' : '0',
		);
	}

	/**
	 * Read call filters from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, string>
	 */
	private function get_call_filters( WP_REST_Request $request ): array {
		return $this->get_call_filters_from_array(
			array(
				'search'     => $request->get_param( 'search' ),
				'status'     => $request->get_param( 'status' ),
				'date_from'  => $request->get_param( 'date_from' ),
				'date_to'    => $request->get_param( 'date_to' ),
				'match_only' => $request->get_param( 'match_only' ),
				'connect_import_only' => $request->get_param( 'connect_import_only' ),
			)
		);
	}

	/**
	 * Sanitize call filters from a raw array.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, string>
	 */
	private function get_call_filters_from_array( array $values ): array {
		return array(
			'search'     => sanitize_text_field( (string) ( $values['search'] ?? '' ) ),
			'status'     => sanitize_text_field( (string) ( $values['status'] ?? '' ) ),
			'date_from'  => sanitize_text_field( (string) ( $values['date_from'] ?? '' ) ),
			'date_to'    => sanitize_text_field( (string) ( $values['date_to'] ?? '' ) ),
			'match_only' => rest_sanitize_boolean( $values['match_only'] ?? false ) ? '1' : '',
			'connect_import_only' => rest_sanitize_boolean( $values['connect_import_only'] ?? false ) ? '1' : '',
		);
	}

	/**
	 * Backfill Connect instance details from saved credentials and alias hints.
	 *
	 * @param array<string, mixed> $payload Settings payload.
	 * @return array<string, mixed>
	 */
	private function maybe_enrich_connect_settings( array $payload ): array {
		if ( ! isset( $payload['amazon_connect'] ) || ! is_array( $payload['amazon_connect'] ) ) {
			return $payload;
		}

		$amazon_connect = $payload['amazon_connect'];

		if ( empty( $amazon_connect['region'] ) || ! empty( $amazon_connect['use_iam_role'] ) ) {
			return $payload;
		}

		if ( empty( $amazon_connect['access_key_id'] ) || empty( $amazon_connect['secret_access_key'] ) ) {
			return $payload;
		}

		$instance_id  = sanitize_text_field( (string) ( $amazon_connect['instance_id'] ?? '' ) );
		$instance_url = esc_url_raw( (string) ( $amazon_connect['instance_url'] ?? '' ) );

		if ( '' === $instance_id && '' === $instance_url ) {
			return $payload;
		}

		$discovered = array();

		if ( '' !== $instance_id ) {
			$discovered = $this->connect->describe_instance( $instance_id, $amazon_connect );
		} else {
			$instances = $this->connect->list_instances( $amazon_connect );

			if ( ! is_wp_error( $instances ) ) {
				$matched = $this->match_connect_instance_from_url( $instance_url, $instances );

				if ( ! empty( $matched['Id'] ) ) {
					$discovered = $this->connect->describe_instance( sanitize_text_field( (string) $matched['Id'] ), $amazon_connect );
				}
			}
		}

		if ( is_wp_error( $discovered ) || empty( $discovered ) ) {
			return $payload;
		}

		$resolved_instance_id  = sanitize_text_field( (string) ( $discovered['Id'] ?? $instance_id ) );
		$resolved_instance_url = esc_url_raw( (string) ( $discovered['InstanceAccessUrl'] ?? $instance_url ) );

		if ( '' !== $resolved_instance_id ) {
			$payload['amazon_connect']['instance_id'] = $resolved_instance_id;
		}

		if ( '' !== $resolved_instance_url ) {
			$payload['amazon_connect']['instance_url'] = $resolved_instance_url;
		}

		return $payload;
	}

	/**
	 * Match a Connect instance from a saved access URL.
	 *
	 * @param string                          $instance_url Candidate URL.
	 * @param array<int, array<string, mixed>> $instances   Visible instances.
	 * @return array<string, mixed>
	 */
	private function match_connect_instance_from_url( string $instance_url, array $instances ): array {
		$host = strtolower( (string) wp_parse_url( $instance_url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return array();
		}

		foreach ( $instances as $instance ) {
			$access_url  = esc_url_raw( (string) ( $instance['InstanceAccessUrl'] ?? '' ) );
			$access_host = strtolower( (string) wp_parse_url( $access_url, PHP_URL_HOST ) );
			$alias       = strtolower( sanitize_text_field( (string) ( $instance['InstanceAlias'] ?? '' ) ) );

			if ( '' !== $access_host && $access_host === $host ) {
				return $instance;
			}

			if ( '' !== $alias && 0 === strpos( $host, $alias . '.' ) ) {
				return $instance;
			}
		}

		return array();
	}

	/**
	 * Build a signed JWT for the hosted Connect widget.
	 *
	 * @param string               $widget_id    Widget ID.
	 * @param string               $security_key Widget security key.
	 * @param array<string,string> $attributes   Optional contact attributes.
	 * @return string
	 */
	private function build_connect_widget_token( string $widget_id, string $security_key, array $attributes = array() ): string {
		$issued_at = time();
		$payload   = array(
			'sub' => $widget_id,
			'iat' => $issued_at,
			'exp' => $issued_at + 300,
		);

		if ( ! empty( $attributes ) ) {
			$payload['attributes'] = $attributes;
		}

		$header            = array(
			'typ' => 'JWT',
			'alg' => 'HS256',
		);
		$encoded_header    = $this->base64_url_encode( wp_json_encode( $header ) ?: '{}' );
		$encoded_payload   = $this->base64_url_encode( wp_json_encode( $payload ) ?: '{}' );
		$signature         = hash_hmac( 'sha256', $encoded_header . '.' . $encoded_payload, $security_key, true );
		$encoded_signature = $this->base64_url_encode( $signature );

		return $encoded_header . '.' . $encoded_payload . '.' . $encoded_signature;
	}

	/**
	 * Sanitise widget attributes for JWT payloads.
	 *
	 * @param array<string, mixed> $attributes Raw attributes.
	 * @return array<string, string>
	 */
	private function sanitize_connect_widget_attributes( array $attributes ): array {
		$sanitized = array();

		foreach ( $attributes as $key => $value ) {
			$attribute_key   = sanitize_key( (string) $key );
			$attribute_value = sanitize_text_field( (string) $value );

			if ( '' === $attribute_key ) {
				continue;
			}

			$sanitized[ $attribute_key ] = $attribute_value;
		}

		return $sanitized;
	}

	/**
	 * Base64 URL-safe encoding.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function base64_url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
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
