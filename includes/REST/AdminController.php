<?php
/**
 * Admin REST controller.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\REST;

use ACE\AdaptiveCustomerEngagement\AI\AgentAvailability;
use ACE\AdaptiveCustomerEngagement\AI\ChatPresence;
use ACE\AdaptiveCustomerEngagement\AI\OpenAIClient;
use ACE\AdaptiveCustomerEngagement\AI\SiteContextService;
use ACE\AdaptiveCustomerEngagement\AmazonConnect\Client as AmazonConnectClient;
use ACE\AdaptiveCustomerEngagement\Admin\SampleDataSeeder;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\CallRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\ChatConversationRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\ChatMessageRepository;
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
	 * Chat conversation repository.
	 *
	 * @var ChatConversationRepository
	 */
	private $chat_conversations;

	/**
	 * Chat message repository.
	 *
	 * @var ChatMessageRepository
	 */
	private $chat_messages;

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
	 * Site context helper.
	 *
	 * @var SiteContextService
	 */
	private $site_context;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository $sessions           Session repository.
	 * @param EventRepository   $events             Event repository.
	 * @param NumberRepository  $numbers            Number repository.
	 * @param CompanyRepository $companies          Company repository.
	 * @param CallRepository    $calls              Call repository.
	 * @param ChatConversationRepository $chat_conversations Chat conversation repository.
	 * @param ChatMessageRepository      $chat_messages      Chat message repository.
	 * @param Privacy           $privacy            Privacy helper.
	 * @param EnrichmentService $enrichment_service Enrichment service.
	 * @param SampleDataSeeder  $sample_data        Sample data seeder.
	 * @param AmazonConnectClient $connect          Amazon Connect client.
	 * @param SiteContextService $site_context      Site context helper.
	 */
	public function __construct( SessionRepository $sessions, EventRepository $events, NumberRepository $numbers, CompanyRepository $companies, CallRepository $calls, ChatConversationRepository $chat_conversations, ChatMessageRepository $chat_messages, Privacy $privacy, EnrichmentService $enrichment_service, SampleDataSeeder $sample_data, AmazonConnectClient $connect, SiteContextService $site_context ) {
		$this->sessions           = $sessions;
		$this->events             = $events;
		$this->numbers            = $numbers;
		$this->companies          = $companies;
		$this->calls              = $calls;
		$this->chat_conversations = $chat_conversations;
		$this->chat_messages      = $chat_messages;
		$this->privacy            = $privacy;
		$this->lead_scorer        = new LeadScorer();
		$this->enrichment_service = $enrichment_service;
		$this->sample_data        = $sample_data;
		$this->connect            = $connect;
		$this->site_context       = $site_context;
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
			'/admin/chats',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'chats' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/chats/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'chat_detail' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/chats/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'chat_status' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/chats/(?P<id>\d+)/reply',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'chat_reply' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/chats/(?P<id>\d+)/workflow',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'chat_workflow' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/chats/availability',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'chat_availability' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/chats/alerts',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'chat_alerts' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/chats/(?P<id>\d+)/suggestions',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'chat_suggestions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/chats/(?P<id>\d+)/typing',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'chat_typing' ),
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
			'/admin/settings/export',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'export_settings' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/settings/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'import_settings' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/openai/models',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'openai_models' ),
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
					'chat_conversations'     => $this->chat_conversations->count_conversations(),
					'chat_messages'          => $this->chat_conversations->count_messages(),
				),
				'top_pages'          => $this->events->get_top_pages( 6, $filters ),
				'top_sources'        => $this->sessions->get_top_sources( 8, $filters ),
				'top_call_paths'     => $this->events->get_top_call_paths( 8, $call_filters ),
				'recent_sessions'    => $recent_sessions,
				'recent_calls'       => $this->calls->get_calls( 20, $call_filters ),
				'numbers'            => $numbers,
				'hot_companies'      => array_map( array( $this, 'decorate_company_summary' ), $this->companies->get_hot_companies() ),
				'recent_chats'       => $this->chat_conversations->get_conversations( 10 ),
				'sample_data'        => $this->sample_data->get_status(),
				'segment_shortcuts'  => array(
					'sessions'  => Settings::get_reporting_segments( 'sessions' ),
					'companies' => Settings::get_reporting_segments( 'companies' ),
					'calls'     => Settings::get_reporting_segments( 'calls' ),
					'chats'     => Settings::get_reporting_segments( 'chats' ),
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
				'chats'    => $this->chat_conversations->get_by_session( $session_id, 12 ),
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
		$company['recent_chats'] = $this->chat_conversations->get_by_company( (int) $company['id'], 12 );
		$company['commerce'] = $this->events->get_company_woocommerce_interest( (int) $company['id'] );

		return new WP_REST_Response( $company );
	}

	/**
	 * Chats reporting data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function chats( WP_REST_Request $request ): WP_REST_Response {
		$filters  = $this->get_chat_filters( $request );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
		$total    = $this->chat_conversations->count_conversations( $filters );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );

		return new WP_REST_Response(
			array(
				'metrics'    => array(
					'conversations' => $total,
					'messages'      => $this->chat_conversations->count_messages(),
					'handover'      => $this->chat_conversations->count_conversations( array_merge( $filters, array( 'status' => ChatConversationRepository::STATUS_HANDOVER ) ) ),
					'new'           => $this->chat_conversations->count_conversations( array_merge( $filters, array( 'commercial_status' => ChatConversationRepository::COMMERCIAL_STATUS_NEW ) ) ),
					'due_follow_up' => $this->chat_conversations->count_due_follow_ups( $filters ),
				),
				'items'      => $this->chat_conversations->get_conversations( $per_page, $filters, ( $page - 1 ) * $per_page ),
				'filters'    => array(
					'providers'           => $this->chat_conversations->get_providers(),
					'models'              => $this->chat_conversations->get_models(),
					'statuses'            => ChatConversationRepository::get_commercial_statuses(),
					'runtime_statuses'    => array( ChatConversationRepository::STATUS_OPEN, ChatConversationRepository::STATUS_HANDOVER, ChatConversationRepository::STATUS_ENDED ),
					'outcomes'            => ChatConversationRepository::get_commercial_outcomes(),
					'priorities'          => ChatConversationRepository::get_priorities(),
					'owners'              => $this->get_chat_owner_options(),
					'active'              => $filters,
				),
				'agent_availability' => AgentAvailability::get_status(),
				'segments'   => Settings::get_reporting_segments( 'chats' ),
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
	 * Chat conversation detail data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chat_detail( WP_REST_Request $request ) {
		$conversation = $this->chat_conversations->find( absint( $request['id'] ) );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_chat_not_found', __( 'Chat conversation not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->build_chat_detail_response( $conversation ) );
	}

	/**
	 * Update commercial workflow metadata for a chat conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chat_workflow( WP_REST_Request $request ) {
		$conversation = $this->chat_conversations->find( absint( $request['id'] ) );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_chat_not_found', __( 'Chat conversation not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$updated = $this->chat_conversations->update_workflow(
			(int) $conversation['id'],
			array(
				'commercial_status'  => $payload['commercial_status'] ?? '',
				'commercial_outcome' => $payload['commercial_outcome'] ?? '',
				'priority'           => $payload['priority'] ?? '',
				'owner_user_id'      => $payload['owner_user_id'] ?? 0,
				'follow_up_at'       => $payload['follow_up_at'] ?? '',
				'internal_notes'     => $payload['internal_notes'] ?? '',
			)
		);

		if ( ! $updated ) {
			return new WP_Error( 'ace_chat_workflow_update_failed', __( 'The chat workflow details could not be saved.', 'adaptive-customer-engagement' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $this->build_chat_detail_response( $updated ) );
	}

	/**
	 * Update the current admin chat-watching heartbeat.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function chat_availability( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$active  = ! isset( $payload['active'] ) || ! empty( $payload['active'] );
		$status  = $active
			? AgentAvailability::mark_watching( get_current_user_id() )
			: AgentAvailability::stop_watching( get_current_user_id() );

		return new WP_REST_Response( $status );
	}

	/**
	 * Get recent handover alerts for online admins.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function chat_alerts( WP_REST_Request $request ): WP_REST_Response {
		$since = sanitize_text_field( (string) $request->get_param( 'since' ) );
		$items = array_map(
			function ( array $item ): array {
				$item['chat_url'] = esc_url_raw( ace_adaptive_customer_engagement_make_local_url( admin_url( 'admin.php?page=adaptive-customer-engagement-dashboard#chats?ace_chat=' . absint( $item['id'] ) . '&ace_handover_request=1' ) ) );
				return $item;
			},
			$this->chat_conversations->get_handover_alerts( $since )
		);

		return new WP_REST_Response(
			array(
				'items'        => $items,
				'generated_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Build suggested agent replies for a chat conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chat_suggestions( WP_REST_Request $request ) {
		$conversation = $this->chat_conversations->find( absint( $request['id'] ) );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_chat_not_found', __( 'Chat conversation not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'suggestions' => $this->build_chat_reply_suggestions( $conversation ),
				'generated_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Update agent typing state for a chat conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chat_typing( WP_REST_Request $request ) {
		$conversation = $this->chat_conversations->find( absint( $request['id'] ) );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_chat_not_found', __( 'Chat conversation not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		$payload   = $request->get_json_params();
		$payload   = is_array( $payload ) ? $payload : array();
		$is_typing = ! empty( $payload['is_typing'] );
		$user      = wp_get_current_user();

		ChatPresence::set_typing(
			(int) $conversation['id'],
			'agent',
			$is_typing,
			array(
				'label' => __( 'Agent', 'adaptive-customer-engagement' ),
				'name'  => $user instanceof \WP_User ? (string) $user->display_name : '',
				'status'=> 'typing',
			)
		);

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'typing' => ChatPresence::get_typing_state( (int) $conversation['id'] ),
			)
		);
	}

	/**
	 * Update handover/end status for a chat conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chat_status( WP_REST_Request $request ) {
		$conversation = $this->chat_conversations->find( absint( $request['id'] ) );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_chat_not_found', __( 'Chat conversation not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$action  = sanitize_key( (string) ( $payload['action'] ?? '' ) );

		switch ( $action ) {
			case 'handover':
				$conversation = $this->chat_conversations->set_handover( (int) $conversation['id'], true );
				$conversation = $conversation ? $this->chat_conversations->clear_handover_request( (int) $conversation['id'] ) : $conversation;
				if ( $conversation ) {
					$conversation = $this->chat_conversations->update_workflow(
						(int) $conversation['id'],
						array(
							'owner_user_id'      => ! empty( $conversation['owner_user_id'] ) ? (int) $conversation['owner_user_id'] : get_current_user_id(),
							'commercial_status'  => ChatConversationRepository::COMMERCIAL_STATUS_WORKING,
							'commercial_outcome' => $conversation['commercial_outcome'] ?? '',
							'priority'           => $conversation['priority'] ?? ChatConversationRepository::PRIORITY_NORMAL,
							'follow_up_at'       => $conversation['follow_up_at'] ?? '',
							'internal_notes'     => $conversation['internal_notes'] ?? '',
						)
					);
				}
				break;
			case 'resume_ai':
				if ( ! empty( $conversation['ended_at'] ) ) {
					return new WP_Error( 'ace_chat_already_ended', __( 'This chat has already ended and cannot be handed back to the assistant.', 'adaptive-customer-engagement' ), array( 'status' => 409 ) );
				}
				$conversation = $this->chat_conversations->set_handover( (int) $conversation['id'], false );
				$conversation = $conversation ? $this->chat_conversations->clear_handover_request( (int) $conversation['id'] ) : $conversation;
				break;
			case 'end':
				$conversation = $this->chat_conversations->end_conversation( (int) $conversation['id'], 'admin' );
				$conversation = $conversation ? $this->chat_conversations->clear_handover_request( (int) $conversation['id'] ) : $conversation;
				break;
			default:
				return new WP_Error( 'ace_chat_action_invalid', __( 'The requested chat action is not supported.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( ! $conversation ) {
			return new WP_Error( 'ace_chat_update_failed', __( 'The chat conversation could not be updated.', 'adaptive-customer-engagement' ), array( 'status' => 500 ) );
		}

		if ( 'end' === $action ) {
			ChatPresence::clear_conversation( (int) $conversation['id'] );
		}

		return new WP_REST_Response( $this->build_chat_detail_response( $conversation ) );
	}

	/**
	 * Store an operator reply against a chat conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chat_reply( WP_REST_Request $request ) {
		$conversation = $this->chat_conversations->find( absint( $request['id'] ) );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_chat_not_found', __( 'Chat conversation not found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		if ( ! empty( $conversation['ended_at'] ) ) {
			return new WP_Error( 'ace_chat_already_ended', __( 'This chat has already ended, so no more replies can be sent.', 'adaptive-customer-engagement' ), array( 'status' => 409 ) );
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$message = sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) );

		if ( '' === $message ) {
			return new WP_Error( 'ace_chat_reply_required', __( 'Please enter a reply before sending it to the customer.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( empty( $conversation['handover_enabled'] ) ) {
			return new WP_Error( 'ace_chat_manual_mode_required', __( 'Switch this chat into manual agent mode before sending a team reply.', 'adaptive-customer-engagement' ), array( 'status' => 409 ) );
		}

		$conversation = $this->chat_conversations->update_workflow(
			(int) $conversation['id'],
			array(
				'owner_user_id'      => ! empty( $conversation['owner_user_id'] ) ? (int) $conversation['owner_user_id'] : get_current_user_id(),
				'commercial_status'  => ChatConversationRepository::COMMERCIAL_STATUS_WORKING,
				'commercial_outcome' => $conversation['commercial_outcome'] ?? '',
				'priority'           => $conversation['priority'] ?? ChatConversationRepository::PRIORITY_NORMAL,
				'follow_up_at'       => $conversation['follow_up_at'] ?? '',
				'internal_notes'     => $conversation['internal_notes'] ?? '',
			)
		) ?: $conversation;

		$this->chat_messages->create(
			array(
				'conversation_id' => (int) $conversation['id'],
				'session_id'      => (int) ( $conversation['session_id'] ?? 0 ),
				'company_id'      => (int) ( $conversation['company_id'] ?? 0 ),
				'message_role'    => 'operator',
				'message_text'    => $message,
				'sources'         => $this->site_context->infer_message_sources( $message, 5 ),
				'model'           => 'human',
				'operator_user_id'=> get_current_user_id(),
				'author_name'     => wp_get_current_user()->display_name,
				'author_avatar_url' => esc_url_raw( get_avatar_url( get_current_user_id(), array( 'size' => 96 ) ) ?: '' ),
				'is_error'        => false,
			)
		);
		ChatPresence::set_typing( (int) $conversation['id'], 'agent', false );
		$this->chat_conversations->record_message( (int) $conversation['id'], 'operator', 'human' );
		$conversation = $this->chat_conversations->clear_handover_request( (int) $conversation['id'] ) ?: $conversation;

		$conversation = $this->chat_conversations->find( (int) $conversation['id'] );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_chat_reply_failed', __( 'The operator reply could not be stored.', 'adaptive-customer-engagement' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $this->build_chat_detail_response( $conversation ) );
	}

	/**
	 * Build a complete chat detail payload.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return array<string, mixed>
	 */
	private function build_chat_detail_response( array $conversation ): array {
		$session_commerce = ! empty( $conversation['session_id'] ) ? $this->events->get_session_woocommerce_interest( (int) $conversation['session_id'] ) : array();
		$company_commerce = ! empty( $conversation['company_id'] ) ? $this->events->get_company_woocommerce_interest( (int) $conversation['company_id'] ) : array();

		return array(
			'conversation'      => $conversation,
			'messages'          => $this->chat_messages->get_by_conversation( (int) $conversation['id'] ),
			'session_commerce'  => $session_commerce,
			'company_commerce'  => $company_commerce,
			'typing'            => ChatPresence::get_typing_state( (int) $conversation['id'] ),
			'owner_options'     => $this->get_chat_owner_options(),
			'workflow_options'  => array(
				'statuses'   => ChatConversationRepository::get_commercial_statuses(),
				'outcomes'   => ChatConversationRepository::get_commercial_outcomes(),
				'priorities' => ChatConversationRepository::get_priorities(),
			),
		);
	}

	/**
	 * Build suggested human replies for a chat.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return array<int, string>
	 */
	private function build_chat_reply_suggestions( array $conversation ): array {
		$messages            = $this->chat_messages->get_by_conversation( (int) $conversation['id'] );
		$latest_user_text    = '';
		$latest_user_message = array();
		$conversation_id     = absint( $conversation['id'] ?? 0 );
		$current_user        = wp_get_current_user();
		$agent_name          = $current_user instanceof \WP_User && ! empty( $current_user->display_name )
			? sanitize_text_field( (string) $current_user->display_name )
			: sanitize_text_field( (string) ( $conversation['owner_name'] ?? __( 'the team', 'adaptive-customer-engagement' ) ) );
		$is_manual_mode      = ! empty( $conversation['handover_enabled'] );

		for ( $index = count( $messages ) - 1; $index >= 0; --$index ) {
			if ( 'user' === ( $messages[ $index ]['message_role'] ?? '' ) && ! empty( $messages[ $index ]['message_text'] ) ) {
				$latest_user_message = $messages[ $index ];
				$latest_user_text    = sanitize_textarea_field( (string) $latest_user_message['message_text'] );
				break;
			}
		}

		if ( '' === $latest_user_text ) {
			return array(
				sprintf( __( 'Thanks for your message — %s is picking this up now. How can I help further?', 'adaptive-customer-engagement' ), $agent_name ),
				__( 'I can help with product details, availability, or the next best option if you tell me what you need.', 'adaptive-customer-engagement' ),
				__( 'Could you tell me a bit more about what you are looking for so I can point you in the right direction?', 'adaptive-customer-engagement' ),
				__( 'If it helps, I can check the most relevant product or page details for you and summarise them here.', 'adaptive-customer-engagement' ),
			);
		}

		$cache_fragment = implode(
			'|',
			array(
				(string) absint( $latest_user_message['id'] ?? 0 ),
				sanitize_text_field( (string) ( $latest_user_message['created_at'] ?? '' ) ),
				$is_manual_mode ? 'manual' : 'ai',
				(string) ( $current_user instanceof \WP_User ? (int) $current_user->ID : 0 ),
				$latest_user_text,
			)
		);
		$cache_key      = 'ace_chat_suggestions_' . $conversation_id . '_' . md5( $cache_fragment );
		$cached         = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			$cached = array_values(
				array_slice(
					array_filter(
						array_map(
							static function ( $suggestion ): string {
								return sanitize_textarea_field( is_string( $suggestion ) ? $suggestion : '' );
							},
							$cached
						)
					),
					0,
					4
				)
			);

			if ( 4 === count( $cached ) ) {
				return $cached;
			}
		}

		$settings       = Settings::get();
		$ai_agent       = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();
		$api_key        = sanitize_text_field( (string) ( $ai_agent['openai_api_key'] ?? '' ) );
		$model          = sanitize_text_field( (string) ( $ai_agent['openai_model'] ?? 'gpt-4.1-mini' ) );
		$site_context   = new SiteContextService();
		$site_answer    = $site_context->answer_question( $latest_user_text, 3 );
		$session_brief  = ! empty( $conversation['session_id'] ) ? $this->events->get_session_woocommerce_interest( (int) $conversation['session_id'] ) : array();
		$company_brief  = ! empty( $conversation['company_id'] ) ? $this->events->get_company_woocommerce_interest( (int) $conversation['company_id'] ) : array();
		$transcript     = array_slice( $messages, -8 );
		$transcript_txt = implode(
			"\n",
			array_map(
				static function ( array $message ): string {
					$role = sanitize_key( (string) ( $message['message_role'] ?? 'message' ) );
					$name = sanitize_text_field( (string) ( $message['author_name'] ?? ucfirst( $role ) ) );
					$text = sanitize_textarea_field( (string) ( $message['message_text'] ?? '' ) );
					return sprintf( '%s (%s): %s', $name, $role, $text );
				},
				$transcript
			)
		);

		if ( '' !== $api_key ) {
			$response = ( new OpenAIClient() )->create_chat_completion(
				array(
					array(
						'role'    => 'system',
						'content' => sprintf(
							'You help a human website agent reply in live chat. The current operator is %1$s. Return exactly four short reply suggestions in British English. Each suggestion must be plain text, no numbering, no bullets, no markdown, and suitable for sending as a direct human reply. Keep them concise, warm, and grounded in the supplied context. %2$s If product or shipping context is supplied, use it. If more information is needed, one suggestion may ask a concise clarifying question.',
							$agent_name,
							$is_manual_mode
								? 'The agent has explicitly taken over from the bot, so the suggestions should read like a human taking ownership of the conversation rather than an automated assistant.'
								: 'The agent is reviewing the chat but has not taken over yet, so the suggestions should still work as sensible human takeover options if they decide to step in.'
						),
					),
					array(
						'role'    => 'user',
						'content' => "Latest visitor message:\n{$latest_user_text}\n\nRecent transcript:\n{$transcript_txt}\n\nSite answer summary:\n" . sanitize_textarea_field( (string) ( $site_answer['answer'] ?? '' ) ) . "\n\nSession buying signals:\n" . sanitize_textarea_field( (string) ( $session_brief['summary'] ?? '' ) ) . "\n\nCompany buying signals:\n" . sanitize_textarea_field( (string) ( $company_brief['summary'] ?? '' ) ) . "\n\nReturn a JSON array with exactly 4 strings.",
					),
				),
				array(
					'api_key'             => $api_key,
					'model'               => $model,
					'temperature'         => 0.4,
					'max_response_tokens' => 350,
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$suggestions = json_decode( (string) ( $response['message'] ?? '' ), true );

				if ( is_array( $suggestions ) ) {
					$suggestions = array_values(
						array_slice(
							array_filter(
								array_map(
									static function ( $suggestion ): string {
										return sanitize_textarea_field( is_string( $suggestion ) ? $suggestion : '' );
									},
									$suggestions
								)
							),
							0,
							4
						)
					);

					if ( 4 === count( $suggestions ) ) {
						set_transient( $cache_key, $suggestions, DAY_IN_SECONDS * 7 );
						return $suggestions;
					}
				}
			}
		}

		$site_summary = sanitize_textarea_field( (string) ( $site_answer['answer'] ?? '' ) );
		$session_summary = sanitize_textarea_field( (string) ( $session_brief['summary'] ?? '' ) );

		$suggestions = array(
			sprintf(
				/* translators: 1: agent display name, 2: answer summary. */
				__( 'Hi, %1$s here. Based on what you have asked, %2$s', 'adaptive-customer-engagement' ),
				$agent_name,
				'' !== $site_summary ? lcfirst( rtrim( $site_summary, '.' ) ) . '.' : __( 'I am checking the most relevant details for you now.', 'adaptive-customer-engagement' )
			),
			'' !== $session_summary
				? sprintf( __( 'I can see some related product interest already: %s', 'adaptive-customer-engagement' ), rtrim( $session_summary, '.' ) . '.' )
				: __( 'I can help narrow this down if you tell me which product, size, or use case you have in mind.', 'adaptive-customer-engagement' ),
			__( 'If you would like, I can recommend the most suitable option and explain why it fits what you need.', 'adaptive-customer-engagement' ),
			__( 'Could you tell me a little more about the requirement so I can give you the most useful answer?', 'adaptive-customer-engagement' ),
		);

		set_transient( $cache_key, $suggestions, DAY_IN_SECONDS * 7 );

		return $suggestions;
	}

	/**
	 * Get chat owner options for the commercial workflow.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_chat_owner_options(): array {
		$users   = get_users(
			array(
				'fields'     => array( 'ID', 'display_name', 'user_email' ),
				'capability' => Capabilities::MANAGE,
				'orderby'    => 'display_name',
				'order'      => 'ASC',
			)
		);
		$options = array();

		foreach ( $users as $user ) {
			$options[] = array(
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'user_email'   => $user->user_email,
			);
		}

		return $options;
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
		return new WP_REST_Response( $this->build_settings_response( Settings::get() ) );
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

		return new WP_REST_Response( $this->build_settings_response( Settings::update( $payload ) ) );
	}

	/**
	 * Add settings metadata needed by the admin UI.
	 *
	 * @param array<string, mixed> $settings Sanitised settings.
	 * @return array<string, mixed>
	 */
	private function build_settings_response( array $settings ): array {
		$settings['available_site_context_post_types'] = Settings::get_available_site_context_post_types();

		return $settings;
	}

	/**
	 * Export the saved plugin configuration.
	 *
	 * @return WP_REST_Response
	 */
	public function export_settings(): WP_REST_Response {
		$settings = Settings::get();

		return new WP_REST_Response(
			array(
				'format'             => 'adaptive-customer-engagement-settings',
				'plugin'             => 'adaptive-customer-engagement',
				'version'            => defined( 'ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_VERSION' ) ? sanitize_text_field( (string) ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_VERSION ) : '',
				'exported_at'        => gmdate( 'c' ),
				'settings'           => $settings,
				'hash_salt'          => (string) get_option( Settings::HASH_SALT_OPTION, '' ),
				'reporting_segments' => Settings::get_reporting_segments(),
				'numbers'            => $this->get_exportable_numbers(),
			)
		);
	}

	/**
	 * Import plugin configuration from an exported settings payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_settings( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$imported_settings = array();

		if ( isset( $payload['settings'] ) && is_array( $payload['settings'] ) ) {
			$imported_settings = $payload['settings'];
		} elseif ( isset( $payload['config'] ) && is_array( $payload['config'] ) ) {
			$imported_settings = $payload['config'];
		} elseif ( isset( $payload['enabled'] ) || isset( $payload['tracking'] ) || isset( $payload['privacy'] ) || isset( $payload['enrichment'] ) || isset( $payload['amazon_connect'] ) || isset( $payload['ai_agent'] ) ) {
			$imported_settings = $payload;
		}

		if ( empty( $imported_settings ) ) {
			return new WP_Error( 'ace_settings_import_invalid', __( 'The uploaded file does not contain a valid Adaptive Customer Engagement settings export.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$imported_settings = $this->maybe_enrich_connect_settings( $imported_settings );
		$updated_settings  = Settings::update( $imported_settings );
		$hash_salt         = isset( $payload['hash_salt'] ) ? substr( (string) $payload['hash_salt'], 0, 255 ) : '';
		$segments          = isset( $payload['reporting_segments'] ) && is_array( $payload['reporting_segments'] ) ? Settings::update_reporting_segments( $payload['reporting_segments'] ) : Settings::get_reporting_segments();

		if ( '' !== $hash_salt ) {
			update_option( Settings::HASH_SALT_OPTION, $hash_salt, false );
		}

		if ( isset( $payload['numbers'] ) && is_array( $payload['numbers'] ) ) {
			$this->import_number_configuration( $payload['numbers'] );
		}

		return new WP_REST_Response(
			array(
				'settings'           => $this->build_settings_response( $updated_settings ),
				'reporting_segments' => $segments,
				'imported_at'        => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Validate the OpenAI token and list available models.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function openai_models( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();
		$api_key  = sanitize_text_field( (string) ( $payload['api_key'] ?? $ai_agent['openai_api_key'] ?? '' ) );

		if ( '' === trim( $api_key ) ) {
			return new WP_REST_Response(
				array(
					'active'          => false,
					'models'          => array(),
					'preferred_model' => '',
				)
			);
		}

		$result = ( new OpenAIClient() )->list_models( $api_key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result );
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
	 * Start a hosted widget chat through the plugin backend so the selected flow is honoured.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_connect_widget_chat( WP_REST_Request $request ) {
		$settings       = Settings::get();
		$amazon_connect = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();
		$ai_agent       = isset( $settings['ai_agent'] ) && is_array( $settings['ai_agent'] ) ? $settings['ai_agent'] : array();
		$admin_only     = ! empty( $ai_agent['frontend_test_admin_only'] );

		if ( empty( $ai_agent['enabled'] ) || empty( $ai_agent['frontend_test_enabled'] ) ) {
			return new WP_Error( 'ace_connect_widget_disabled', __( 'The frontend test chat is not enabled.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( $admin_only && ! current_user_can( Capabilities::MANAGE ) ) {
			return new WP_Error( 'ace_connect_widget_forbidden', __( 'This chat starter is only available to logged-in administrators.', 'adaptive-customer-engagement' ), array( 'status' => 403 ) );
		}

		$chat_contact_flow_id = sanitize_text_field( (string) ( $amazon_connect['chat_contact_flow_id'] ?? '' ) );

		if ( '' === $chat_contact_flow_id ) {
			return new WP_Error( 'ace_connect_chat_flow_missing', __( 'Choose the Amazon Connect chat contact flow in the plugin settings before launching the widget.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$payload = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$user    = wp_get_current_user();
		$name    = '';

		if ( $user instanceof \WP_User && $user->exists() ) {
			$name = $user->display_name ?: $user->user_login;
		}

		if ( '' === $name ) {
			$name = get_bloginfo( 'name' ) ?: __( 'Website visitor', 'adaptive-customer-engagement' );
		}

		$attributes = $this->sanitize_connect_widget_attributes(
			array(
				'ace_session_id' => $payload['session_uuid'] ?? '',
				'ace_visitor_id' => $payload['visitor_uuid'] ?? '',
				'ace_page_url'   => $payload['page_url'] ?? '',
				'ace_page_title' => $payload['page_title'] ?? '',
			)
		);
		$result     = $this->connect->start_chat_contact(
			$chat_contact_flow_id,
			$name,
			$attributes,
			array(
				'text/plain',
				'text/markdown',
			),
			wp_generate_uuid4()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$start_chat_result = array(
			'ContactId'       => sanitize_text_field( (string) ( $result['ContactId'] ?? '' ) ),
			'ParticipantId'   => sanitize_text_field( (string) ( $result['ParticipantId'] ?? '' ) ),
			'ParticipantToken'=> sanitize_text_field( (string) ( $result['ParticipantToken'] ?? '' ) ),
		);

		if ( '' === $start_chat_result['ContactId'] || '' === $start_chat_result['ParticipantId'] || '' === $start_chat_result['ParticipantToken'] ) {
			return new WP_Error( 'ace_connect_start_chat_invalid', __( 'Amazon Connect did not return a complete chat session payload.', 'adaptive-customer-engagement' ), array( 'status' => 502 ) );
		}

		if ( ! empty( $result['ContinuedFromContactId'] ) ) {
			$start_chat_result['ContinuedFromContactId'] = sanitize_text_field( (string) $result['ContinuedFromContactId'] );
		}

		return new WP_REST_Response(
			array(
				'data' => array(
					'startChatResult'    => $start_chat_result,
					'featurePermissions' => array(
						'MESSAGING_MARKDOWN' => true,
						'ATTACHMENTS'        => false,
					),
				),
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
	 * Read Amazon Lex V2 bots and related bot resources.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function connect_lex_bots( WP_REST_Request $request ): WP_REST_Response {
		$settings       = Settings::get();
		$amazon_connect = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();
		$selected_bot   = sanitize_text_field( (string) ( $request->get_param( 'bot_id' ) ?: ( $amazon_connect['lex_bot_id'] ?? '' ) ) );
		$selected_locale = sanitize_text_field( (string) ( $request->get_param( 'locale_id' ) ?: ( $amazon_connect['lex_bot_locale_id'] ?? '' ) ) );
		$inventory      = $this->get_connect_lex_bot_inventory( $amazon_connect, $selected_bot, $selected_locale );

		return new WP_REST_Response(
			array(
				'items'            => $inventory['items'],
				'aliases'          => $inventory['aliases'],
				'locales'          => $inventory['locales'],
				'intents'          => $inventory['intents'],
				'selected'         => $inventory['selected'],
				'console_url'      => $inventory['console_url'],
				'connected_count'  => count( $inventory['items'] ),
				'connected_bot'    => $inventory['connected_bot'],
				'can_create'       => empty( $inventory['selected']['bot_id'] ),
				'error'            => $inventory['error'],
				'errors'           => $inventory['errors'],
			)
		);
	}

	/**
	 * Create an Amazon Lex V2 bot from the plugin knowledge base.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_connect_lex_bot( WP_REST_Request $request ) {
		$payload          = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$settings         = Settings::get();
		$amazon_connect   = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();
		$ai_agent         = isset( $settings['ai_agent'] ) && is_array( $settings['ai_agent'] ) ? $settings['ai_agent'] : array();
		$name             = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
		$description      = sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) );
		$provided_role_arn = sanitize_text_field( (string) ( $payload['role_arn'] ?? '' ) );
		$stored_role_arn   = sanitize_text_field( (string) ( $amazon_connect['lex_bot_role_arn'] ?? '' ) );
		$role_arn          = '' !== $provided_role_arn ? $provided_role_arn : $stored_role_arn;
		$stored_locale_id  = sanitize_text_field( (string) ( $amazon_connect['lex_bot_locale_id'] ?? '' ) );
		$locale_id         = sanitize_text_field( (string) ( $payload['locale_id'] ?? ( '' !== $stored_locale_id ? $stored_locale_id : 'en_GB' ) ) );
		$link_to_settings = ! array_key_exists( 'link_to_settings', $payload ) || rest_sanitize_boolean( $payload['link_to_settings'] );
		$create_chat_flow = ! array_key_exists( 'create_chat_flow', $payload ) || rest_sanitize_boolean( $payload['create_chat_flow'] );
		$chat_flow_message = sanitize_textarea_field( (string) ( $payload['chat_flow_message'] ?? __( 'Hello, I am the site assistant. Ask me anything about this website and I will do my best to help.', 'adaptive-customer-engagement' ) ) );
		$chat_flow_failure_message = sanitize_textarea_field( (string) ( $payload['chat_flow_failure_message'] ?? __( 'Sorry, the site assistant is unavailable just now. Please try again later.', 'adaptive-customer-engagement' ) ) );
		$provisioned_role = null;
		$role_source      = '' !== $provided_role_arn ? 'provided' : ( '' !== $stored_role_arn ? 'saved' : 'generated' );
		$knowledge_entries = $this->sanitize_bot_knowledge_entries_payload(
			is_array( $payload['knowledge_entries'] ?? null ) ? $payload['knowledge_entries'] : ( is_array( $ai_agent['bot_knowledge_entries'] ?? null ) ? $ai_agent['bot_knowledge_entries'] : array() )
		);

		if ( '' === $name ) {
			return new WP_Error( 'ace_connect_lex_bot_name_required', __( 'Please provide a bot name before creating the Lex bot.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( '' === $role_arn || $this->is_service_linked_lex_role_arn( $role_arn ) ) {
			if ( '' !== $role_arn && $this->is_service_linked_lex_role_arn( $role_arn ) ) {
				$role_source = 'generated';
			}

			$provisioned_role = $this->connect->ensure_lex_bot_role();

			if ( is_wp_error( $provisioned_role ) ) {
				return $provisioned_role;
			}

			$role_arn = sanitize_text_field( (string) ( $provisioned_role['role_arn'] ?? '' ) );
			$role_source = 'generated';
		}

		if ( '' === $role_arn ) {
			return new WP_Error( 'ace_connect_lex_bot_role_required', __( 'The plugin could not determine a dedicated IAM role ARN for this Lex bot.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( '' !== $provided_role_arn && $this->is_service_linked_lex_role_arn( $role_arn ) ) {
			return new WP_Error( 'ace_connect_lex_bot_role_invalid', __( 'The selected ARN is a Lex service-linked role from an existing bot. Please use a dedicated Lex bot role ARN for creating a new bot from WordPress.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( empty( $knowledge_entries ) ) {
			return new WP_Error( 'ace_connect_lex_bot_knowledge_required', __( 'Add at least one enabled knowledge entry before creating the Lex bot.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$enabled_knowledge_entries = array_values(
			array_filter(
				$knowledge_entries,
				static function ( array $entry ): bool {
					return ! empty( $entry['enabled'] );
				}
			)
		);

		if ( empty( $enabled_knowledge_entries ) ) {
			return new WP_Error( 'ace_connect_lex_bot_enabled_knowledge_required', __( 'Enable at least one knowledge entry before creating the Lex bot.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$bot = $this->connect->create_lex_bot( $name, $role_arn, $description, $locale_id );

		if ( is_wp_error( $bot ) ) {
			return $bot;
		}

		$bot_id = sanitize_text_field( (string) ( $bot['botId'] ?? '' ) );

		if ( '' === $bot_id ) {
			return new WP_Error( 'ace_connect_lex_bot_create_failed', __( 'Amazon Lex did not return a bot ID for the newly created bot.', 'adaptive-customer-engagement' ) );
		}

		$bot_ready = $this->connect->wait_for_lex_bot_status( $bot_id );

		if ( is_wp_error( $bot_ready ) ) {
			return $bot_ready;
		}

		$locale = $this->connect->create_lex_bot_locale(
			$bot_id,
			$locale_id,
			sprintf(
				/* translators: %s: site name */
				__( 'Primary %s locale managed by Adaptive Customer Engagement.', 'adaptive-customer-engagement' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			)
		);

		if ( is_wp_error( $locale ) ) {
			return $locale;
		}

		$locale_ready = $this->connect->wait_for_lex_bot_locale_status( $bot_id, $locale_id );

		if ( is_wp_error( $locale_ready ) ) {
			return $locale_ready;
		}

		$created_intents = array();

		foreach ( $enabled_knowledge_entries as $index => $entry ) {
			$intent_name = $this->build_lex_knowledge_intent_name( (string) $entry['question'], $index + 1 );
			$intent      = $this->connect->create_lex_intent(
				$bot_id,
				$locale_id,
				$intent_name,
				(string) $entry['answer'],
				$this->build_lex_sample_utterances( $entry ),
				(string) $entry['question']
			);

			if ( is_wp_error( $intent ) ) {
				return $intent;
			}

			$intent['intentName'] = $intent_name;
			$created_intents[]    = $intent;
		}

		$build = $this->connect->build_lex_bot_locale( $bot_id, $locale_id );

		if ( is_wp_error( $build ) ) {
			return new WP_Error(
				'ace_connect_lex_bot_build_failed',
				sprintf(
					/* translators: %s: AWS error message. */
					__( 'The Amazon Lex bot was created but its locale build could not be started: %s', 'adaptive-customer-engagement' ),
					$build->get_error_message()
				),
				array(
					'status' => 502,
					'bot_id' => $bot_id,
				)
			);
		}

		$locale_built = $this->connect->wait_for_lex_bot_locale_status( $bot_id, $locale_id, array( 'Built', 'ReadyExpressTesting' ), 20, 3000000 );

		if ( is_wp_error( $locale_built ) ) {
			return $locale_built;
		}

		$version = $this->connect->create_lex_bot_version(
			$bot_id,
			$locale_id,
			sprintf(
				/* translators: %s: site name */
				__( 'Published website chat version for %s.', 'adaptive-customer-engagement' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			)
		);

		if ( is_wp_error( $version ) ) {
			return $version;
		}

		$bot_version = sanitize_text_field( (string) ( $version['botVersion'] ?? '' ) );

		if ( '' === $bot_version ) {
			return new WP_Error( 'ace_connect_lex_bot_version_create_failed', __( 'Amazon Lex did not return a published bot version for the new site bot.', 'adaptive-customer-engagement' ) );
		}

		$version_ready = $this->connect->wait_for_lex_bot_version_status( $bot_id, $bot_version );

		if ( is_wp_error( $version_ready ) ) {
			return $version_ready;
		}

		$published_alias = $this->connect->create_lex_bot_alias(
			$bot_id,
			$bot_version,
			'WebsiteChat',
			$locale_id,
			array(
				'managed-by'           => 'adaptive-customer-engagement',
				'environment'          => 'wordpress-plugin',
				'AmazonConnectEnabled' => 'true',
			),
			__( 'Published website chat alias managed by Adaptive Customer Engagement.', 'adaptive-customer-engagement' )
		);

		if ( is_wp_error( $published_alias ) ) {
			return $published_alias;
		}

		$published_alias_id = sanitize_text_field( (string) ( $published_alias['botAliasId'] ?? '' ) );

		if ( '' === $published_alias_id ) {
			return new WP_Error( 'ace_connect_lex_bot_alias_create_failed', __( 'Amazon Lex did not return a published alias for the new site bot.', 'adaptive-customer-engagement' ) );
		}

		$preferred_alias = $this->connect->wait_for_lex_bot_alias_status( $bot_id, $published_alias_id );

		if ( is_wp_error( $preferred_alias ) ) {
			return $preferred_alias;
		}

		$aliases = $this->connect->list_lex_bot_aliases( $bot_id );
		$locales = $this->connect->list_lex_bot_locales( $bot_id );
		$intents = $this->connect->list_lex_intents( $bot_id, $locale_id );
		$account_id      = preg_match( '/^arn:aws:iam::([0-9]{12}):/', $role_arn, $role_matches ) ? sanitize_text_field( (string) $role_matches[1] ) : '';
		$bot_arn         = $this->connect->build_lex_v2_bot_arn(
			$bot_id,
			$account_id,
			sanitize_text_field( (string) ( $amazon_connect['region'] ?? '' ) )
		);
		$alias_arn       = $this->connect->build_lex_v2_alias_arn(
			$bot_id,
			$published_alias_id,
			$account_id,
			sanitize_text_field( (string) ( $amazon_connect['region'] ?? '' ) )
		);
		$tag_result      = '' !== $bot_arn
			? $this->connect->ensure_lex_connect_tags(
				$bot_arn,
				array(
					'managed-by'           => 'adaptive-customer-engagement',
					'environment'          => 'wordpress-plugin',
					'locale'               => $locale_id,
					'AmazonConnectEnabled' => 'true',
				),
				$alias_arn,
				array(
					'managed-by'           => 'adaptive-customer-engagement',
					'environment'          => 'wordpress-plugin',
					'AmazonConnectEnabled' => 'true',
				)
			)
			: new WP_Error( 'ace_connect_lex_bot_arn_missing', __( 'The plugin could not determine the Lex bot ARN to apply the Amazon Connect tags.', 'adaptive-customer-engagement' ) );

		if ( is_wp_error( $tag_result ) ) {
			return new WP_Error(
				'ace_connect_lex_bot_tag_failed',
				sprintf(
					/* translators: %s: AWS error message. */
					__( 'The Amazon Lex bot was created but could not be tagged for Amazon Connect: %s', 'adaptive-customer-engagement' ),
					$tag_result->get_error_message()
				),
				array(
					'status' => 502,
					'bot_id' => $bot_id,
				)
			);
		}

		$connect_association = '' !== $alias_arn ? $this->connect->associate_lex_v2_bot( $alias_arn ) : new WP_Error( 'ace_connect_lex_alias_arn_missing', __( 'The plugin could not determine the Lex alias ARN to associate with Amazon Connect.', 'adaptive-customer-engagement' ) );
		$selected_intent = isset( $created_intents[0]['intentName'] ) ? sanitize_text_field( (string) $created_intents[0]['intentName'] ) : '';
		$chat_flow          = null;
		$updated_settings = null;
		$creation_message = '';

		if ( $create_chat_flow && '' !== $alias_arn ) {
			$bot_name_for_flow = sanitize_text_field( (string) ( $bot['botName'] ?? $name ) );
			$generated_flow    = $this->connect->create_contact_flow(
				sprintf(
					/* translators: %s: bot name */
					__( '%s Website Chat', 'adaptive-customer-engagement' ),
					$bot_name_for_flow
				),
				sprintf(
					/* translators: %s: bot name */
					__( 'Website chat flow managed by Adaptive Customer Engagement for the %s bot.', 'adaptive-customer-engagement' ),
					$bot_name_for_flow
				),
				$this->build_lex_chat_contact_flow_content( $chat_flow_message, $alias_arn, $chat_flow_failure_message ),
				'CONTACT_FLOW'
			);

			$chat_flow = is_wp_error( $generated_flow )
				? array(
					'success' => false,
					'error'   => $generated_flow->get_error_message(),
				)
				: array(
					'success' => true,
					'item'    => $this->sanitize_connect_contact_flow( $generated_flow ),
				);
		}

		if ( $link_to_settings ) {
			$settings['amazon_connect']['lex_bot_role_arn']    = $role_arn;
			$settings['amazon_connect']['lex_bot_id']          = $bot_id;
			$settings['amazon_connect']['lex_bot_locale_id']   = $locale_id;
			$settings['amazon_connect']['lex_bot_alias_id']    = $published_alias_id;
			$settings['amazon_connect']['lex_bot_intent_name'] = $selected_intent;
			if ( ! empty( $chat_flow['success'] ) && ! empty( $chat_flow['item']['Id'] ) ) {
				$settings['amazon_connect']['chat_contact_flow_id'] = sanitize_text_field( (string) $chat_flow['item']['Id'] );
			}
			$settings['ai_agent']['bot_knowledge_entries']     = $knowledge_entries;
			$updated_settings                                  = Settings::update( $this->maybe_enrich_connect_lex_settings( $settings ) );
		}

		$bots = $this->connect->list_lex_bots();
		$creation_message = $this->build_lex_bot_creation_message( $chat_flow, $connect_association, $provisioned_role );
		$creation_checks  = array(
			array(
				'key'    => 'bot',
				'label'  => __( 'Bot created', 'adaptive-customer-engagement' ),
				'status' => 'complete',
				'value'  => $bot_id,
			),
			array(
				'key'    => 'locale',
				'label'  => __( 'Locale built', 'adaptive-customer-engagement' ),
				'status' => 'complete',
				'value'  => $locale_id,
			),
			array(
				'key'    => 'version',
				'label'  => __( 'Version published', 'adaptive-customer-engagement' ),
				'status' => 'complete',
				'value'  => $bot_version,
			),
			array(
				'key'    => 'alias',
				'label'  => __( 'Website alias ready', 'adaptive-customer-engagement' ),
				'status' => 'complete',
				'value'  => sanitize_text_field( (string) ( $preferred_alias['botAliasName'] ?? 'WebsiteChat' ) ),
			),
			array(
				'key'    => 'tags',
				'label'  => __( 'Connect tags applied', 'adaptive-customer-engagement' ),
				'status' => 'complete',
				'value'  => __( 'AmazonConnectEnabled=true', 'adaptive-customer-engagement' ),
			),
			array(
				'key'    => 'association',
				'label'  => __( 'Associated with Amazon Connect', 'adaptive-customer-engagement' ),
				'status' => is_wp_error( $connect_association ) ? 'warning' : 'complete',
				'value'  => is_wp_error( $connect_association ) ? $connect_association->get_error_message() : sanitize_text_field( $alias_arn ),
			),
			array(
				'key'    => 'chat_flow',
				'label'  => __( 'Website chat flow', 'adaptive-customer-engagement' ),
				'status' => empty( $create_chat_flow ) ? 'skipped' : ( ! empty( $chat_flow['success'] ) ? 'complete' : 'warning' ),
				'value'  => empty( $create_chat_flow )
					? __( 'Skipped', 'adaptive-customer-engagement' )
					: ( ! empty( $chat_flow['success'] )
						? sanitize_text_field( (string) ( $chat_flow['item']['Name'] ?? $chat_flow['item']['Id'] ?? '' ) )
						: sanitize_text_field( (string) ( $chat_flow['error'] ?? __( 'Unknown flow error.', 'adaptive-customer-engagement' ) ) ) ),
			),
		);

		return new WP_REST_Response(
			array(
				'message'          => $creation_message,
				'item'             => $this->sanitize_connect_lex_bot( $bot ),
				'items'            => $this->get_connect_lex_bot_inventory( $updated_settings['amazon_connect'] ?? $amazon_connect, $bot_id, $locale_id )['items'],
				'aliases'          => is_wp_error( $aliases ) ? array() : array_map( array( $this, 'sanitize_connect_lex_bot_alias' ), $aliases ),
				'locales'          => is_wp_error( $locales ) ? array() : array_map( array( $this, 'sanitize_connect_lex_bot_locale' ), $locales ),
				'intents'          => is_wp_error( $intents ) ? array_map( array( $this, 'sanitize_connect_lex_intent' ), $created_intents ) : array_map( array( $this, 'sanitize_connect_lex_intent' ), $intents ),
				'knowledge_entries'=> $knowledge_entries,
				'provisioned_role' => is_array( $provisioned_role ) ? $provisioned_role : null,
				'connect_association' => is_wp_error( $connect_association )
					? array(
						'success' => false,
						'error'   => $connect_association->get_error_message(),
						'alias_arn' => $alias_arn,
					)
					: array(
						'success' => true,
						'alias_arn' => $alias_arn,
					),
				'build'            => $locale_built,
				'version'          => $version_ready,
				'published_alias'  => $this->sanitize_connect_lex_bot_alias( $preferred_alias ),
				'chat_flow'        => $chat_flow,
				'creation_checks'  => $creation_checks,
				'inferred'         => array(
					'locale_id'   => $locale_id,
					'role_arn'    => $role_arn,
					'role_source' => $role_source,
					'alias_name'  => sanitize_text_field( (string) ( $preferred_alias['botAliasName'] ?? 'WebsiteChat' ) ),
					'connect_region' => sanitize_text_field( (string) ( $amazon_connect['region'] ?? '' ) ),
					'instance_id' => sanitize_text_field( (string) ( $amazon_connect['instance_id'] ?? '' ) ),
				),
				'settings'         => $updated_settings,
			),
			201
		);
	}

	/**
	 * Build a human-friendly creation summary for a managed Lex bot.
	 *
	 * @param array<string, mixed>|null $chat_flow            Generated chat flow summary.
	 * @param true|WP_Error             $connect_association Connect association result.
	 * @param array<string, mixed>|null $provisioned_role     Provisioned role details.
	 * @return string
	 */
	private function build_lex_bot_creation_message( ?array $chat_flow, $connect_association, ?array $provisioned_role ): string {
		if ( ! empty( $chat_flow['success'] ) ) {
			return __( 'Amazon Lex bot created, associated with Amazon Connect, and linked into a website chat flow.', 'adaptive-customer-engagement' );
		}

		if ( ! empty( $chat_flow['error'] ) ) {
			return sprintf(
				/* translators: %s: flow error message */
				__( 'Amazon Lex bot created, but the website chat flow could not be generated yet: %s', 'adaptive-customer-engagement' ),
				sanitize_text_field( (string) $chat_flow['error'] )
			);
		}

		if ( is_wp_error( $connect_association ) ) {
			return sprintf(
				/* translators: %s: association error message */
				__( 'Amazon Lex bot created, but Amazon Connect could not attach it to the Connect bots list yet: %s', 'adaptive-customer-engagement' ),
				$connect_association->get_error_message()
			);
		}

		if ( is_array( $provisioned_role ) && ! empty( $provisioned_role['created'] ) ) {
			return sprintf(
				/* translators: %s: IAM role name */
				__( 'Amazon Lex bot created, and Adaptive Customer Engagement also prepared the dedicated IAM role %s for it.', 'adaptive-customer-engagement' ),
				sanitize_text_field( (string) ( $provisioned_role['role_name'] ?? __( 'for this bot', 'adaptive-customer-engagement' ) ) )
			);
		}

		return __( 'Amazon Lex bot created and associated with the Amazon Connect bots list.', 'adaptive-customer-engagement' );
	}

	/**
	 * Disconnect the plugin from the currently selected Connect bot without removing it from Connect.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function disconnect_connect_lex_bot( WP_REST_Request $request ): WP_REST_Response {
		$settings = Settings::get();

		if ( ! isset( $settings['amazon_connect'] ) || ! is_array( $settings['amazon_connect'] ) ) {
			$settings['amazon_connect'] = array();
		}

		$settings['amazon_connect']['lex_bot_id']          = '';
		$settings['amazon_connect']['lex_bot_alias_id']    = '';
		$settings['amazon_connect']['lex_bot_locale_id']   = 'en_GB';
		$settings['amazon_connect']['lex_bot_intent_name'] = '';
		$settings['amazon_connect']['lex_bot_console_url'] = '';
		$settings['amazon_connect']['chat_contact_flow_id'] = '';
		$updated_settings                                   = Settings::update( $settings );
		$inventory                                          = $this->get_connect_lex_bot_inventory( $updated_settings['amazon_connect'] );

		return new WP_REST_Response(
			array(
				'message'  => __( 'The site bot has been disconnected from the plugin settings. It remains available in Amazon Connect for later reconnection or cleanup.', 'adaptive-customer-engagement' ),
				'settings' => $updated_settings,
				'items'    => $inventory['items'],
				'selected' => $inventory['selected'],
			)
		);
	}

	/**
	 * Remove all other Connect-linked Lex bots, keeping the selected site bot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cleanup_connect_lex_bots( WP_REST_Request $request ) {
		$payload        = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$settings       = Settings::get();
		$amazon_connect = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();
		$keep_bot_id    = sanitize_text_field( (string) ( $payload['keep_bot_id'] ?? ( $amazon_connect['lex_bot_id'] ?? '' ) ) );
		$configs        = $this->connect->list_connect_lex_v2_bots();

		if ( '' === $keep_bot_id ) {
			return new WP_Error( 'ace_connect_cleanup_keep_bot_required', __( 'Select the site bot to keep before cleaning up the other Amazon Connect bots.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( is_wp_error( $configs ) ) {
			return $configs;
		}

		$bot_configs   = array_values( array_filter( array_map( array( $this, 'sanitize_connect_lex_bot_config' ), $configs ) ) );
		$deleted_bots  = array();
		$kept_bots     = array();
		$errors        = array();
		$deleted_ids   = array();

		foreach ( $bot_configs as $config ) {
			if ( $keep_bot_id === $config['bot_id'] ) {
				$kept_bots[] = $config;
				continue;
			}

			$disassociated = $this->connect->disassociate_lex_v2_bot( $config['alias_arn'] );

			if ( is_wp_error( $disassociated ) ) {
				$errors[] = sprintf(
					/* translators: 1: bot ID, 2: error message */
					__( 'Could not disconnect bot %1$s from Amazon Connect: %2$s', 'adaptive-customer-engagement' ),
					$config['bot_id'],
					$disassociated->get_error_message()
				);
				continue;
			}

			if ( ! in_array( $config['bot_id'], $deleted_ids, true ) ) {
				$deleted_ids[] = $config['bot_id'];
				$deleted       = $this->connect->delete_lex_bot( $config['bot_id'] );

				if ( is_wp_error( $deleted ) ) {
					$errors[] = sprintf(
						/* translators: 1: bot ID, 2: error message */
						__( 'Could not delete bot %1$s from Amazon Lex: %2$s', 'adaptive-customer-engagement' ),
						$config['bot_id'],
						$deleted->get_error_message()
					);
					continue;
				}
			}

			$deleted_bots[] = $config;
		}

		$inventory = $this->get_connect_lex_bot_inventory( $amazon_connect, $keep_bot_id, sanitize_text_field( (string) ( $amazon_connect['lex_bot_locale_id'] ?? '' ) ) );

		return new WP_REST_Response(
			array(
				'message'      => empty( $errors )
					? __( 'Amazon Connect bot cleanup completed. Only the selected site bot remains connected.', 'adaptive-customer-engagement' )
					: __( 'Amazon Connect bot cleanup finished with some warnings.', 'adaptive-customer-engagement' ),
				'deleted_bots' => $deleted_bots,
				'kept_bots'    => $kept_bots,
				'errors'       => $errors,
				'items'        => $inventory['items'],
				'selected'     => $inventory['selected'],
			)
		);
	}

	/**
	 * Seed bot knowledge entries from the site's published content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function seed_connect_bot_knowledge( WP_REST_Request $request ): WP_REST_Response {
		$payload   = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$limit     = min( 12, max( 3, absint( $payload['limit'] ?? 8 ) ) );
		$post_types = array( 'page', 'post' );

		if ( post_type_exists( 'product' ) ) {
			$post_types[] = 'product';
		}

		$posts = get_posts(
			array(
				'post_type'           => $post_types,
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			)
		);

		$items = array();

		foreach ( $posts as $post ) {
			$entry = $this->build_seeded_bot_knowledge_entry( $post );

			if ( empty( $entry ) ) {
				continue;
			}

			$items[] = $entry;
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
			)
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
		$set_as_chat_flow = rest_sanitize_boolean( $payload['set_as_chat_flow'] ?? ( 'lex_chat' === $template_type ) );
		$settings         = Settings::get();
		$amazon_connect   = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();
		$inventory        = $this->get_connect_lex_bot_inventory(
			$amazon_connect,
			sanitize_text_field( (string) ( $amazon_connect['lex_bot_id'] ?? '' ) ),
			sanitize_text_field( (string) ( $amazon_connect['lex_bot_locale_id'] ?? '' ) )
		);
		$lex_alias_arn    = sanitize_text_field( (string) ( $payload['lex_bot_alias_arn'] ?? ( $inventory['selected']['alias_arn'] ?? '' ) ) );

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

		if ( 'lex_chat' === $template_type && '' === $lex_alias_arn ) {
			return new WP_Error( 'ace_connect_lex_chat_alias_required', __( 'Connect the site bot first so the plugin knows which Amazon Lex alias the website chat flow should invoke.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( '' !== $caller_id_number && ! preg_match( '/^\+[1-9]\d{1,14}$/', $caller_id_number ) ) {
			return new WP_Error( 'ace_connect_invalid_caller_id', __( 'Please provide a valid E.164 caller ID number or leave it blank.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( 'queue_transfer' === $template_type ) {
			$content = $this->build_queue_transfer_contact_flow_content( $message, $queue_id, $failure_message, $queue_flow_id );
		} elseif ( 'lex_chat' === $template_type ) {
			$content = $this->build_lex_chat_contact_flow_content( $message, $lex_alias_arn, $failure_message );
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

		if ( ! empty( $sanitized_flow['Id'] ) ) {
			if ( $set_as_default ) {
				$settings['amazon_connect']['default_contact_flow_id'] = $sanitized_flow['Id'];
			}
			if ( $set_as_chat_flow ) {
				$settings['amazon_connect']['chat_contact_flow_id'] = $sanitized_flow['Id'];
			}
		}

		$updated_settings = ( $set_as_default || $set_as_chat_flow ) ? Settings::update( $settings ) : null;

		return new WP_REST_Response(
			array(
				'item'     => $sanitized_flow,
				'settings' => $updated_settings,
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
	 * Export filtered chat conversations as CSV.
	 *
	 * @return void
	 */
	public function export_chats(): void {
		$this->assert_export_access();

		$filters = $this->get_chat_filters_from_array( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total   = $this->chat_conversations->count_conversations( $filters );
		$items   = $total > 0 ? $this->chat_conversations->get_conversations( $total, $filters, 0 ) : array();

		$this->stream_csv(
			'ace-chats-export-' . gmdate( 'Ymd-His' ) . '.csv',
			array(
				'conversation_uuid'       => 'Conversation UUID',
				'session_uuid'            => 'Session UUID',
				'visitor_uuid'            => 'Visitor UUID',
				'company_name'            => 'Company',
				'owner_name'              => 'Owner',
				'status'                  => 'Runtime status',
				'commercial_status'       => 'Workflow status',
				'commercial_outcome'      => 'Outcome',
				'priority'                => 'Priority',
				'follow_up_requested'     => 'Follow-up requested',
				'follow_up_at'            => 'Follow-up due',
				'contact_name'            => 'Contact name',
				'contact_email'           => 'Contact email',
				'contact_phone'           => 'Contact phone',
				'contact_company'         => 'Contact company',
				'contact_role'            => 'Contact role',
				'lead_summary'            => 'Lead summary',
				'provider'                => 'Provider',
				'model'                   => 'Model',
				'message_count'           => 'Messages',
				'user_message_count'      => 'User messages',
				'assistant_message_count' => 'Assistant messages',
				'operator_message_count'  => 'Operator messages',
				'page_title'              => 'Page title',
				'page_url'                => 'Page URL',
				'first_user_message'      => 'First user message',
				'internal_notes'          => 'Internal notes',
				'started_at'              => 'Started',
				'last_message_at'         => 'Last message',
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

		if ( ! in_array( $segment['view'], array( 'sessions', 'companies', 'calls', 'chats', 'commerce' ), true ) ) {
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
	 * Export local/connect-linked number rules as configuration payloads.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_exportable_numbers(): array {
		return array_values(
			array_map(
				static function ( array $row ): array {
					return array(
						'label'                          => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
						'display_number'                 => sanitize_text_field( (string) ( $row['display_number'] ?? '' ) ),
						'e164_number'                    => sanitize_text_field( (string) ( $row['e164_number'] ?? '' ) ),
						'source_type'                    => sanitize_key( (string) ( $row['source_type'] ?? '' ) ),
						'source_value'                   => sanitize_text_field( (string) ( $row['source_value'] ?? '' ) ),
						'page_match_type'                => sanitize_key( (string) ( $row['page_match_type'] ?? '' ) ),
						'page_match_value'               => sanitize_text_field( (string) ( $row['page_match_value'] ?? '' ) ),
						'campaign_match'                 => sanitize_text_field( (string) ( $row['campaign_match'] ?? '' ) ),
						'amazon_connect_phone_number_id' => sanitize_text_field( (string) ( $row['amazon_connect_phone_number_id'] ?? '' ) ),
						'amazon_connect_contact_flow_id' => sanitize_text_field( (string) ( $row['amazon_connect_contact_flow_id'] ?? '' ) ),
						'is_default'                     => ! empty( $row['is_default'] ) ? 1 : 0,
						'is_active'                      => ! empty( $row['is_active'] ) ? 1 : 0,
						'priority'                       => absint( $row['priority'] ?? 10 ),
					);
				},
				array_values(
					array_filter(
						$this->numbers->all(),
						static function ( array $row ): bool {
							return empty( $row['is_sample'] );
						}
					)
				)
			)
		);
	}

	/**
	 * Import number-rule configuration without disturbing historical call records.
	 *
	 * @param array<int, mixed> $numbers Number payloads.
	 * @return void
	 */
	private function import_number_configuration( array $numbers ): void {
		$existing = array_values(
			array_filter(
				$this->numbers->all(),
				static function ( array $row ): bool {
					return empty( $row['is_sample'] );
				}
			)
		);
		$touched_ids = array();

		foreach ( $numbers as $number ) {
			$payload = $this->sanitize_number_payload( $number );

			if ( '' === $payload['label'] || '' === $payload['display_number'] || '' === $payload['e164_number'] ) {
				continue;
			}

			$match = $this->find_import_number_match( $payload, $existing );

			if ( ! empty( $match['id'] ) ) {
				$updated = $this->numbers->update( (int) $match['id'], $payload );

				if ( ! empty( $updated['id'] ) ) {
					$touched_ids[] = (int) $updated['id'];
				}

				continue;
			}

			$created = $this->numbers->create( $payload );

			if ( ! empty( $created['id'] ) ) {
				$touched_ids[] = (int) $created['id'];
			}
		}

		foreach ( $existing as $row ) {
			$row_id = (int) ( $row['id'] ?? 0 );

			if ( $row_id <= 0 || in_array( $row_id, $touched_ids, true ) ) {
				continue;
			}

			$this->numbers->update(
				$row_id,
				array(
					'is_active'  => 0,
					'is_default' => 0,
				)
			);
		}
	}

	/**
	 * Match an imported number payload to an existing local number rule.
	 *
	 * @param array<string, mixed>      $payload  Imported number payload.
	 * @param array<int, array<string,mixed>> $existing Existing numbers.
	 * @return array<string, mixed>|null
	 */
	private function find_import_number_match( array $payload, array $existing ): ?array {
		foreach ( $existing as $row ) {
			if (
				'' !== (string) $payload['amazon_connect_phone_number_id']
				&& (string) ( $row['amazon_connect_phone_number_id'] ?? '' ) === (string) $payload['amazon_connect_phone_number_id']
			) {
				return $row;
			}
		}

		foreach ( $existing as $row ) {
			if ( (string) ( $row['e164_number'] ?? '' ) === (string) $payload['e164_number'] ) {
				return $row;
			}
		}

		foreach ( $existing as $row ) {
			if (
				(string) ( $row['label'] ?? '' ) === (string) $payload['label']
				&& (string) ( $row['source_type'] ?? '' ) === (string) $payload['source_type']
				&& (string) ( $row['source_value'] ?? '' ) === (string) $payload['source_value']
			) {
				return $row;
			}
		}

		return null;
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
						'NextAction' => $bot_action_id,
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
	 * Build a website chat flow that invokes the selected Lex V2 bot.
	 *
	 * @param string $message         Greeting shown before the bot takes over.
	 * @param string $alias_arn       Lex V2 alias ARN.
	 * @param string $failure_message Fallback text if the bot cannot be invoked.
	 * @return string
	 */
	private function build_lex_chat_contact_flow_content( string $message, string $alias_arn, string $failure_message ): string {
		$bot_action_id        = wp_generate_uuid4();
		$fallback_action_id   = wp_generate_uuid4();
		$disconnect_action_id = wp_generate_uuid4();
		$fallback_text        = '' !== $failure_message ? $failure_message : __( 'Sorry, the site assistant is unavailable just now. Please try again later.', 'adaptive-customer-engagement' );
		$content              = array(
			'Version'     => '2019-10-30',
			'StartAction' => $bot_action_id,
			'Metadata'    => array(
				'EntryPointPosition' => array(
					'x' => 88,
					'y' => 100,
				),
				'ActionMetadata'     => array(
					$bot_action_id        => array( 'Position' => array( 'x' => 240, 'y' => 98 ) ),
					$fallback_action_id   => array( 'Position' => array( 'x' => 780, 'y' => 280 ) ),
					$disconnect_action_id => array( 'Position' => array( 'x' => 1040, 'y' => 190 ) ),
				),
			),
			'Actions'     => array(
				array(
					'Identifier'  => $bot_action_id,
					'Type'        => 'ConnectParticipantWithLexBot',
					'Transitions' => array(
						'NextAction' => $bot_action_id,
						'Errors'     => array(
							array(
								'NextAction' => $fallback_action_id,
								'ErrorType'  => 'InputTimeLimitExceeded',
							),
							array(
								'NextAction' => $bot_action_id,
								'ErrorType'  => 'NoMatchingCondition',
							),
							array(
								'NextAction' => $fallback_action_id,
								'ErrorType'  => 'NoMatchingError',
							),
						),
						'Conditions' => array(
							array(
								'NextAction' => $disconnect_action_id,
								'Condition'  => array(
									'Operator' => 'Equals',
									'Operands' => array(
										'AMAZON.StopIntent',
									),
								),
							),
							array(
								'NextAction' => $disconnect_action_id,
								'Condition'  => array(
									'Operator' => 'Equals',
									'Operands' => array(
										'AMAZON.CancelIntent',
									),
								),
							),
						),
					),
					'Parameters'  => array(
						'Text'                 => $message,
						'LexV2Bot'             => array(
							'AliasArn' => $alias_arn,
						),
						'LexTimeoutSeconds'    => array(
							'Text' => '300',
						),
					),
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
	 * Sanitize an Amazon Lex V2 bot payload.
	 *
	 * @param array<string, mixed> $item Raw bot.
	 * @return array<string, mixed>
	 */
	private function sanitize_connect_lex_bot( array $item ): array {
		return array(
			'botId'            => sanitize_text_field( (string) ( $item['botId'] ?? '' ) ),
			'botName'          => sanitize_text_field( (string) ( $item['botName'] ?? '' ) ),
			'botStatus'        => sanitize_text_field( (string) ( $item['botStatus'] ?? '' ) ),
			'botType'          => sanitize_text_field( (string) ( $item['botType'] ?? '' ) ),
			'description'      => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
			'latestBotVersion' => sanitize_text_field( (string) ( $item['latestBotVersion'] ?? '' ) ),
			'roleArn'          => sanitize_text_field( (string) ( $item['roleArn'] ?? '' ) ),
			'aliasArn'         => sanitize_text_field( (string) ( $item['aliasArn'] ?? '' ) ),
			'connectedToConnect' => ! empty( $item['connectedToConnect'] ),
		);
	}

	/**
	 * Sanitize a Connect bot-association payload.
	 *
	 * @param array<string, mixed> $item Raw Connect bot config.
	 * @return array<string, string>
	 */
	private function sanitize_connect_lex_bot_config( array $item ): array {
		$alias_arn = sanitize_text_field( (string) ( $item['LexV2Bot']['AliasArn'] ?? '' ) );
		$parsed    = $this->parse_connect_lex_v2_alias_arn( $alias_arn );

		if ( '' === $alias_arn || '' === $parsed['bot_id'] ) {
			return array();
		}

		return array(
			'alias_arn'   => $alias_arn,
			'bot_id'      => $parsed['bot_id'],
			'alias_id'    => $parsed['alias_id'],
			'region'      => $parsed['region'],
			'account_id'  => $parsed['account_id'],
		);
	}

	/**
	 * Sanitize an Amazon Lex V2 bot alias payload.
	 *
	 * @param array<string, mixed> $item Raw alias.
	 * @return array<string, mixed>
	 */
	private function sanitize_connect_lex_bot_alias( array $item ): array {
		return array(
			'botAliasId'     => sanitize_text_field( (string) ( $item['botAliasId'] ?? '' ) ),
			'botAliasName'   => sanitize_text_field( (string) ( $item['botAliasName'] ?? '' ) ),
			'botAliasStatus' => sanitize_text_field( (string) ( $item['botAliasStatus'] ?? '' ) ),
			'botVersion'     => sanitize_text_field( (string) ( $item['botVersion'] ?? '' ) ),
			'description'    => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
		);
	}

	/**
	 * Sanitize an Amazon Lex V2 locale payload.
	 *
	 * @param array<string, mixed> $item Raw locale.
	 * @return array<string, mixed>
	 */
	private function sanitize_connect_lex_bot_locale( array $item ): array {
		return array(
			'localeId'        => sanitize_text_field( (string) ( $item['localeId'] ?? '' ) ),
			'localeName'      => sanitize_text_field( (string) ( $item['localeName'] ?? '' ) ),
			'botLocaleStatus' => sanitize_text_field( (string) ( $item['botLocaleStatus'] ?? '' ) ),
			'description'     => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
		);
	}

	/**
	 * Sanitize an Amazon Lex V2 intent payload.
	 *
	 * @param array<string, mixed> $item Raw intent.
	 * @return array<string, mixed>
	 */
	private function sanitize_connect_lex_intent( array $item ): array {
		return array(
			'intentId'              => sanitize_text_field( (string) ( $item['intentId'] ?? '' ) ),
			'intentName'            => sanitize_text_field( (string) ( $item['intentName'] ?? '' ) ),
			'intentDisplayName'     => sanitize_text_field( (string) ( $item['intentDisplayName'] ?? '' ) ),
			'description'           => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
			'parentIntentSignature' => sanitize_text_field( (string) ( $item['parentIntentSignature'] ?? '' ) ),
		);
	}

	/**
	 * Build the Connect-backed Lex bot inventory used by the admin UI.
	 *
	 * @param array<string, mixed> $amazon_connect Saved Connect settings.
	 * @param string               $selected_bot   Selected bot ID.
	 * @param string               $selected_locale Selected locale ID.
	 * @return array<string, mixed>
	 */
	private function get_connect_lex_bot_inventory( array $amazon_connect, string $selected_bot = '', string $selected_locale = '' ): array {
		$configs        = $this->connect->list_connect_lex_v2_bots();
		$items          = array();
		$aliases        = array();
		$locales        = array();
		$intents        = array();
		$bot_details    = array();
		$selected_assoc = array();
		$errors         = array(
			'bots'    => is_wp_error( $configs ) ? $configs->get_error_message() : '',
			'bot'     => '',
			'aliases' => '',
			'locales' => '',
			'intents' => '',
		);
		$bot_configs    = is_wp_error( $configs ) ? array() : array_values( array_filter( array_map( array( $this, 'sanitize_connect_lex_bot_config' ), $configs ) ) );

		if ( '' === $selected_bot && ! empty( $bot_configs ) ) {
			$preferred_bot = $this->get_preferred_lex_bot(
				array_map(
					static function ( array $config ): array {
						return array(
							'botId' => $config['bot_id'],
						);
					},
					$bot_configs
				)
			);

			$selected_bot = sanitize_text_field( (string) ( $preferred_bot['botId'] ?? $bot_configs[0]['bot_id'] ) );
		}

		foreach ( $bot_configs as $config ) {
			$details = $this->connect->describe_lex_bot( $config['bot_id'] );
			$item    = array(
				'botId'              => $config['bot_id'],
				'botName'            => $config['bot_id'],
				'botStatus'          => '',
				'botType'            => 'Bot',
				'description'        => '',
				'latestBotVersion'   => '',
				'roleArn'            => '',
				'aliasArn'           => $config['alias_arn'],
				'connectedToConnect' => true,
			);

			if ( ! is_wp_error( $details ) ) {
				$item = array_merge(
					$item,
					$this->sanitize_connect_lex_bot( $details ),
					array(
						'aliasArn'           => $config['alias_arn'],
						'connectedToConnect' => true,
					)
				);
			}

			if ( $selected_bot === $config['bot_id'] ) {
				$selected_assoc = $config;
				$bot_details    = is_wp_error( $details ) ? array() : $details;
				$errors['bot']  = is_wp_error( $details ) ? $details->get_error_message() : '';
			}

			$items[] = $item;
		}

		if ( empty( $selected_assoc ) && ! empty( $bot_configs ) ) {
			$selected_assoc = $bot_configs[0];
			$selected_bot   = $selected_assoc['bot_id'];
			$bot_details    = $this->connect->describe_lex_bot( $selected_bot );
			$errors['bot']  = is_wp_error( $bot_details ) ? $bot_details->get_error_message() : '';
			$bot_details    = is_wp_error( $bot_details ) ? array() : $bot_details;
		}

		if ( '' !== $selected_bot ) {
			$aliases          = $this->connect->list_lex_bot_aliases( $selected_bot );
			$errors['aliases'] = is_wp_error( $aliases ) ? $aliases->get_error_message() : '';
			$locales          = $this->connect->list_lex_bot_locales( $selected_bot );
			$errors['locales'] = is_wp_error( $locales ) ? $locales->get_error_message() : '';

			if ( '' === $selected_locale && ! is_wp_error( $locales ) ) {
				$selected_locale = $this->get_preferred_lex_locale_id( $locales, sanitize_text_field( (string) ( $amazon_connect['lex_bot_locale_id'] ?? '' ) ) );
			}

			if ( '' !== $selected_locale ) {
				$intents          = $this->connect->list_lex_intents( $selected_bot, $selected_locale );
				$errors['intents'] = is_wp_error( $intents ) ? $intents->get_error_message() : '';
			}
		}

		$selected_intent = sanitize_text_field( (string) ( $amazon_connect['lex_bot_intent_name'] ?? '' ) );

		if ( '' === $selected_intent && ! is_wp_error( $intents ) ) {
			$selected_intent = $this->get_preferred_lex_intent_name( $intents );
		}

		$selected_role_arn = sanitize_text_field( (string) ( $amazon_connect['lex_bot_role_arn'] ?? ( $bot_details['roleArn'] ?? '' ) ) );
		$selected_alias_id = sanitize_text_field( (string) ( $selected_assoc['alias_id'] ?? ( $amazon_connect['lex_bot_alias_id'] ?? '' ) ) );
		$selected_role_warning = '';

		if ( $this->is_service_linked_lex_role_arn( $selected_role_arn ) ) {
			$selected_role_warning = __( 'The detected role is a Lex service-linked role from an existing bot. Use a dedicated Lex bot role ARN for creating a new bot from WordPress instead of reusing that service-linked role.', 'adaptive-customer-engagement' );
			$selected_role_arn     = '';
		}

		$console_url = $this->build_lex_bot_console_url( $amazon_connect, $selected_bot, $selected_locale, $selected_intent );
		$selected    = array(
			'bot_id'      => $selected_bot,
			'alias_id'    => $selected_alias_id,
			'alias_arn'   => sanitize_text_field( (string) ( $selected_assoc['alias_arn'] ?? '' ) ),
			'locale_id'   => $selected_locale,
			'intent_name' => $selected_intent,
			'role_arn'    => $selected_role_arn,
			'role_warning'=> $selected_role_warning,
			'console_url' => $console_url,
		);

		return array(
			'items'         => array_map( array( $this, 'sanitize_connect_lex_bot' ), $items ),
			'aliases'       => is_wp_error( $aliases ) ? array() : array_map( array( $this, 'sanitize_connect_lex_bot_alias' ), $aliases ),
			'locales'       => is_wp_error( $locales ) ? array() : array_map( array( $this, 'sanitize_connect_lex_bot_locale' ), $locales ),
			'intents'       => is_wp_error( $intents ) ? array() : array_map( array( $this, 'sanitize_connect_lex_intent' ), $intents ),
			'selected'      => $selected,
			'connected_bot' => '' !== $selected_bot ? $this->sanitize_connect_lex_bot( $bot_details ) : array(),
			'console_url'   => $console_url,
			'errors'        => $errors,
			'error'         => implode( ' ', array_filter( $errors ) ),
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
	 * Read chat filters from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, string>
	 */
	private function get_chat_filters( WP_REST_Request $request ): array {
		return $this->get_chat_filters_from_array(
			array(
				'search'             => $request->get_param( 'search' ),
				'provider'           => $request->get_param( 'provider' ),
				'model'              => $request->get_param( 'model' ),
				'status'             => $request->get_param( 'status' ),
				'commercial_status'  => $request->get_param( 'commercial_status' ),
				'commercial_outcome' => $request->get_param( 'commercial_outcome' ),
				'priority'           => $request->get_param( 'priority' ),
				'owner_user_id'      => $request->get_param( 'owner_user_id' ),
				'due_only'           => $request->get_param( 'due_only' ),
				'date_from'          => $request->get_param( 'date_from' ),
				'date_to'            => $request->get_param( 'date_to' ),
			)
		);
	}

	/**
	 * Sanitize chat filters from a raw array.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, string>
	 */
	private function get_chat_filters_from_array( array $values ): array {
		return array(
			'search'             => sanitize_text_field( (string) ( $values['search'] ?? '' ) ),
			'provider'           => sanitize_key( (string) ( $values['provider'] ?? '' ) ),
			'model'              => sanitize_text_field( (string) ( $values['model'] ?? '' ) ),
			'status'             => sanitize_key( (string) ( $values['status'] ?? '' ) ),
			'commercial_status'  => sanitize_key( (string) ( $values['commercial_status'] ?? '' ) ),
			'commercial_outcome' => sanitize_key( (string) ( $values['commercial_outcome'] ?? '' ) ),
			'priority'           => sanitize_key( (string) ( $values['priority'] ?? '' ) ),
			'owner_user_id'      => (string) absint( $values['owner_user_id'] ?? 0 ),
			'due_only'           => ! empty( $values['due_only'] ) ? '1' : '',
			'date_from'          => sanitize_text_field( (string) ( $values['date_from'] ?? '' ) ),
			'date_to'            => sanitize_text_field( (string) ( $values['date_to'] ?? '' ) ),
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

		if ( empty( $amazon_connect['region'] ) ) {
			return $this->maybe_enrich_connect_lex_settings( $payload );
		}

		if ( ! $this->has_connect_request_credentials( $amazon_connect ) ) {
			return $this->maybe_enrich_connect_lex_settings( $payload );
		}

		$instance_id  = sanitize_text_field( (string) ( $amazon_connect['instance_id'] ?? '' ) );
		$instance_url = esc_url_raw( (string) ( $amazon_connect['instance_url'] ?? '' ) );

		if ( '' === $instance_id && '' === $instance_url ) {
			return $this->maybe_enrich_connect_lex_settings( $payload );
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
			return $this->maybe_enrich_connect_lex_settings( $payload );
		}

		$resolved_instance_id  = sanitize_text_field( (string) ( $discovered['Id'] ?? $instance_id ) );
		$resolved_instance_url = esc_url_raw( (string) ( $discovered['InstanceAccessUrl'] ?? $instance_url ) );

		if ( '' !== $resolved_instance_id ) {
			$payload['amazon_connect']['instance_id'] = $resolved_instance_id;
		}

		if ( '' !== $resolved_instance_url ) {
			$payload['amazon_connect']['instance_url'] = $resolved_instance_url;
		}

		return $this->maybe_enrich_connect_lex_settings( $payload );
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
	 * Backfill Lex bot details from a saved console URL and live bot metadata.
	 *
	 * @param array<string, mixed> $payload Settings payload.
	 * @return array<string, mixed>
	 */
	private function maybe_enrich_connect_lex_settings( array $payload ): array {
		if ( ! isset( $payload['amazon_connect'] ) || ! is_array( $payload['amazon_connect'] ) ) {
			return $payload;
		}

		$amazon_connect = $payload['amazon_connect'];
		$console_url    = esc_url_raw( (string) ( $amazon_connect['lex_bot_console_url'] ?? '' ) );

		if ( '' !== $console_url ) {
			$parsed = $this->parse_lex_bot_console_url( $console_url );

			if ( ! empty( $parsed['bot_id'] ) ) {
				$payload['amazon_connect']['lex_bot_id'] = $parsed['bot_id'];
			}

			if ( ! empty( $parsed['locale_id'] ) ) {
				$payload['amazon_connect']['lex_bot_locale_id'] = $parsed['locale_id'];
			}

			if ( ! empty( $parsed['intent_name'] ) ) {
				$payload['amazon_connect']['lex_bot_intent_name'] = $parsed['intent_name'];
			}
		}

		if ( empty( $amazon_connect['region'] ) ) {
			return $payload;
		}

		if ( ! $this->has_connect_request_credentials( $amazon_connect ) ) {
			return $payload;
		}

		$bot_id = sanitize_text_field( (string) ( $payload['amazon_connect']['lex_bot_id'] ?? '' ) );

		if ( '' === $bot_id ) {
			return $payload;
		}

		$bot = $this->connect->describe_lex_bot( $bot_id );

		if ( is_wp_error( $bot ) || empty( $bot['botId'] ) ) {
			return $payload;
		}

		if ( empty( $payload['amazon_connect']['lex_bot_role_arn'] ) && ! empty( $bot['roleArn'] ) && ! $this->is_service_linked_lex_role_arn( (string) $bot['roleArn'] ) ) {
			$payload['amazon_connect']['lex_bot_role_arn'] = sanitize_text_field( (string) $bot['roleArn'] );
		}

		$aliases = $this->connect->list_lex_bot_aliases( $bot_id );

		if ( ! is_wp_error( $aliases ) && empty( $payload['amazon_connect']['lex_bot_alias_id'] ) ) {
			$preferred_alias = $this->get_preferred_lex_bot_alias( $aliases );

			if ( ! empty( $preferred_alias['botAliasId'] ) ) {
				$payload['amazon_connect']['lex_bot_alias_id'] = sanitize_text_field( (string) $preferred_alias['botAliasId'] );
			}
		}

		$locales = $this->connect->list_lex_bot_locales( $bot_id );

		if ( ! is_wp_error( $locales ) && empty( $payload['amazon_connect']['lex_bot_locale_id'] ) ) {
			$payload['amazon_connect']['lex_bot_locale_id'] = $this->get_preferred_lex_locale_id( $locales, 'en_GB' );
		}

		$locale_id = sanitize_text_field( (string) ( $payload['amazon_connect']['lex_bot_locale_id'] ?? '' ) );

		if ( '' !== $locale_id && empty( $payload['amazon_connect']['lex_bot_intent_name'] ) ) {
			$intents = $this->connect->list_lex_intents( $bot_id, $locale_id );

			if ( ! is_wp_error( $intents ) ) {
				$preferred_intent = $this->get_preferred_lex_intent_name( $intents );

				if ( '' !== $preferred_intent ) {
					$payload['amazon_connect']['lex_bot_intent_name'] = $preferred_intent;
				}
			}
		}

		$payload['amazon_connect']['lex_bot_console_url'] = $this->build_lex_bot_console_url(
			$payload['amazon_connect'],
			sanitize_text_field( (string) ( $payload['amazon_connect']['lex_bot_id'] ?? '' ) ),
			sanitize_text_field( (string) ( $payload['amazon_connect']['lex_bot_locale_id'] ?? '' ) ),
			sanitize_text_field( (string) ( $payload['amazon_connect']['lex_bot_intent_name'] ?? '' ) )
		);

		return $payload;
	}

	/**
	 * Parse a Lex bot console URL.
	 *
	 * @param string $console_url Lex console URL.
	 * @return array{bot_id:string,locale_id:string,intent_name:string}
	 */
	private function parse_lex_bot_console_url( string $console_url ): array {
		$parts  = wp_parse_url( $console_url );
		$path   = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';
		$result = array(
			'bot_id'      => '',
			'locale_id'   => '',
			'intent_name' => '',
		);

		if ( '' === $path ) {
			return $result;
		}

		if ( preg_match( '#bots/configuration/([^/]+)/locale/([^/]+)/intent/([^/]+)#i', $path, $matches ) ) {
			$result['bot_id']      = sanitize_text_field( urldecode( $matches[1] ) );
			$result['locale_id']   = sanitize_text_field( urldecode( $matches[2] ) );
			$result['intent_name'] = sanitize_text_field( urldecode( $matches[3] ) );
		}

		return $result;
	}

	/**
	 * Parse a Connect-associated Lex V2 alias ARN.
	 *
	 * @param string $alias_arn Alias ARN.
	 * @return array{account_id:string,region:string,bot_id:string,alias_id:string}
	 */
	private function parse_connect_lex_v2_alias_arn( string $alias_arn ): array {
		$alias_arn = sanitize_text_field( $alias_arn );
		$result    = array(
			'account_id' => '',
			'region'     => '',
			'bot_id'     => '',
			'alias_id'   => '',
		);

		if ( preg_match( '#^arn:aws:lex:([^:]+):([0-9]{12}):bot-alias/([^/]+)/([^/]+)$#', $alias_arn, $matches ) ) {
			$result['region']     = sanitize_text_field( (string) $matches[1] );
			$result['account_id'] = sanitize_text_field( (string) $matches[2] );
			$result['bot_id']     = sanitize_text_field( (string) $matches[3] );
			$result['alias_id']   = sanitize_text_field( (string) $matches[4] );
		}

		return $result;
	}

	/**
	 * Build a Lex bot console URL from the saved Connect instance.
	 *
	 * @param array<string, mixed> $amazon_connect Saved Connect settings.
	 * @param string               $bot_id         Bot ID.
	 * @param string               $locale_id      Locale ID.
	 * @param string               $intent_name    Intent name.
	 * @return string
	 */
	private function build_lex_bot_console_url( array $amazon_connect, string $bot_id, string $locale_id, string $intent_name ): string {
		$instance_url = esc_url_raw( (string) ( $amazon_connect['instance_url'] ?? '' ) );
		$bot_id       = sanitize_text_field( $bot_id );
		$locale_id    = sanitize_text_field( $locale_id );
		$intent_name  = sanitize_text_field( $intent_name );

		if ( '' === $instance_url || '' === $bot_id ) {
			return '';
		}

		$parts  = wp_parse_url( $instance_url );
		$scheme = sanitize_text_field( (string) ( $parts['scheme'] ?? 'https' ) );
		$host   = sanitize_text_field( (string) ( $parts['host'] ?? '' ) );

		if ( '' === $host ) {
			return '';
		}

		$path = '/bots/configuration/' . rawurlencode( $bot_id );

		if ( '' !== $locale_id ) {
			$path .= '/locale/' . rawurlencode( $locale_id );
		}

		if ( '' !== $intent_name ) {
			$path .= '/intent/' . rawurlencode( $intent_name );
		}

		return esc_url_raw( $scheme . '://' . $host . $path );
	}

	/**
	 * Check whether Connect API credentials are available in either supported mode.
	 *
	 * @param array<string, mixed> $amazon_connect Saved Connect settings.
	 * @return bool
	 */
	private function has_connect_request_credentials( array $amazon_connect ): bool {
		$has_access_keys = ! empty( $amazon_connect['access_key_id'] ) && ! empty( $amazon_connect['secret_access_key'] );

		return $has_access_keys || ! empty( $amazon_connect['use_iam_role'] );
	}

	/**
	 * Check whether a role ARN is a Lex service-linked role that should not be reused for bot creation.
	 *
	 * @param string $role_arn Role ARN.
	 * @return bool
	 */
	private function is_service_linked_lex_role_arn( string $role_arn ): bool {
		$role_arn = sanitize_text_field( $role_arn );

		if ( '' === $role_arn ) {
			return false;
		}

		return false !== strpos( $role_arn, ':role/aws-service-role/lexv2.amazonaws.com/' )
			|| false !== strpos( $role_arn, ':role/aws-service-role/channels.lexv2.amazonaws.com/' )
			|| false !== strpos( $role_arn, 'AWSServiceRoleForLexV2' );
	}

	/**
	 * Pick the most useful Lex bot.
	 *
	 * @param array<int, array<string, mixed>> $bots Bot list.
	 * @return array<string, mixed>
	 */
	private function get_preferred_lex_bot( array $bots ): array {
		foreach ( $bots as $bot ) {
			$status = strtoupper( sanitize_text_field( (string) ( $bot['botStatus'] ?? '' ) ) );

			if ( 'AVAILABLE' === $status ) {
				return $bot;
			}
		}

		return isset( $bots[0] ) && is_array( $bots[0] ) ? $bots[0] : array();
	}

	/**
	 * Pick the most useful Lex bot alias.
	 *
	 * @param array<int, array<string, mixed>> $aliases Alias list.
	 * @return array<string, mixed>
	 */
	private function get_preferred_lex_bot_alias( array $aliases ): array {
		foreach ( $aliases as $alias ) {
			$status = strtoupper( sanitize_text_field( (string) ( $alias['botAliasStatus'] ?? '' ) ) );
			$alias_id = sanitize_text_field( (string) ( $alias['botAliasId'] ?? '' ) );

			if ( 'AVAILABLE' === $status && 'TSTALIASID' !== strtoupper( $alias_id ) ) {
				return $alias;
			}
		}

		foreach ( $aliases as $alias ) {
			$status = strtoupper( sanitize_text_field( (string) ( $alias['botAliasStatus'] ?? '' ) ) );

			if ( 'AVAILABLE' === $status ) {
				return $alias;
			}
		}

		return isset( $aliases[0] ) && is_array( $aliases[0] ) ? $aliases[0] : array();
	}

	/**
	 * Pick the preferred Lex locale ID.
	 *
	 * @param array<int, array<string, mixed>> $locales       Locale list.
	 * @param string                           $preferred_id Preferred locale ID.
	 * @return string
	 */
	private function get_preferred_lex_locale_id( array $locales, string $preferred_id = 'en_GB' ): string {
		$preferred_id = sanitize_text_field( $preferred_id );

		foreach ( $locales as $locale ) {
			$locale_id = sanitize_text_field( (string) ( $locale['localeId'] ?? '' ) );

			if ( '' !== $preferred_id && $locale_id === $preferred_id ) {
				return $locale_id;
			}
		}

		return isset( $locales[0]['localeId'] ) ? sanitize_text_field( (string) $locales[0]['localeId'] ) : '';
	}

	/**
	 * Pick a useful Lex intent name.
	 *
	 * @param array<int, array<string, mixed>> $intents Intent list.
	 * @return string
	 */
	private function get_preferred_lex_intent_name( array $intents ): string {
		foreach ( $intents as $intent ) {
			$name   = sanitize_text_field( (string) ( $intent['intentName'] ?? '' ) );
			$parent = sanitize_text_field( (string) ( $intent['parentIntentSignature'] ?? '' ) );

			if ( false !== stripos( $name, 'FALLB' ) || false !== stripos( $parent, 'Fallback' ) ) {
				return '' !== $name ? $name : $parent;
			}
		}

		return isset( $intents[0]['intentName'] ) ? sanitize_text_field( (string) $intents[0]['intentName'] ) : '';
	}

	/**
	 * Sanitise bot knowledge entry payloads.
	 *
	 * @param array<int, mixed> $entries Raw entries.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_bot_knowledge_entries_payload( array $entries ): array {
		$sanitized = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$question = sanitize_text_field( (string) ( $entry['question'] ?? '' ) );
			$answer   = sanitize_textarea_field( (string) ( $entry['answer'] ?? '' ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$sanitized[] = array(
				'id'           => sanitize_text_field( (string) ( $entry['id'] ?? wp_generate_uuid4() ) ),
				'question'     => $question,
				'answer'       => $answer,
				'source_type'  => sanitize_key( (string) ( $entry['source_type'] ?? 'manual' ) ),
				'source_id'    => absint( $entry['source_id'] ?? 0 ),
				'source_label' => sanitize_text_field( (string) ( $entry['source_label'] ?? '' ) ),
				'url'          => esc_url_raw( (string) ( $entry['url'] ?? '' ) ),
				'enabled'      => ! array_key_exists( 'enabled', $entry ) || rest_sanitize_boolean( $entry['enabled'] ),
			);

			if ( count( $sanitized ) >= 25 ) {
				break;
			}
		}

		return $sanitized;
	}

	/**
	 * Build a seeded knowledge entry from a published post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	private function build_seeded_bot_knowledge_entry( \WP_Post $post ): array {
		$title = sanitize_text_field( get_the_title( $post ) );

		if ( '' === $title ) {
			return array();
		}

		$answer = $this->build_seeded_bot_knowledge_answer( $post );

		if ( '' === $answer ) {
			return array();
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		$type_label    = $post_type_obj && ! empty( $post_type_obj->labels->singular_name ) ? sanitize_text_field( $post_type_obj->labels->singular_name ) : ucfirst( $post->post_type );
		$question      = 'product' === $post->post_type
			? sprintf(
				/* translators: %s: product title */
				__( 'Tell me about %s', 'adaptive-customer-engagement' ),
				$title
			)
			: sprintf(
				/* translators: %s: page or post title */
				__( 'What should I know about %s?', 'adaptive-customer-engagement' ),
				$title
			);

		return array(
			'id'           => 'seed-' . $post->ID,
			'question'     => $question,
			'answer'       => $answer,
			'source_type'  => sanitize_key( $post->post_type ),
			'source_id'    => (int) $post->ID,
			'source_label' => sprintf( '%s: %s', $type_label, $title ),
			'url'          => esc_url_raw( get_permalink( $post ) ?: '' ),
			'enabled'      => true,
		);
	}

	/**
	 * Build a concise answer from published content.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private function build_seeded_bot_knowledge_answer( \WP_Post $post ): string {
		$content = has_excerpt( $post ) ? (string) $post->post_excerpt : (string) $post->post_content;
		$content = wp_strip_all_tags( strip_shortcodes( $content ) );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) ?: '' );

		if ( '' === $content ) {
			return '';
		}

		return wp_trim_words( $content, 45, '…' );
	}

	/**
	 * Build a safe Lex intent name for a knowledge entry.
	 *
	 * @param string $question Knowledge question.
	 * @param int    $index    Entry index.
	 * @return string
	 */
	private function build_lex_knowledge_intent_name( string $question, int $index ): string {
		$question = remove_accents( $question );
		$slug     = preg_replace( '/[^A-Za-z0-9]+/', '_', $question ) ?: '';
		$slug     = trim( $slug, '_' );

		if ( '' === $slug ) {
			$slug = 'Answer';
		}

		$name = sprintf( 'Faq_%02d_%s', max( 1, $index ), $slug );

		return substr( $name, 0, 100 );
	}

	/**
	 * Build sample utterances for a knowledge entry.
	 *
	 * @param array<string, mixed> $entry Knowledge entry.
	 * @return array<int, string>
	 */
	private function build_lex_sample_utterances( array $entry ): array {
		$question     = sanitize_text_field( (string) ( $entry['question'] ?? '' ) );
		$source_label = sanitize_text_field( (string) ( $entry['source_label'] ?? '' ) );
		$plain        = trim( preg_replace( '/[?!.]+$/', '', $question ) ?: '' );
		$title        = $source_label;

		if ( false !== strpos( $source_label, ': ' ) ) {
			$parts = explode( ': ', $source_label, 2 );
			$title = sanitize_text_field( (string) ( $parts[1] ?? $source_label ) );
		}

		$utterances = array_filter(
			array(
				$question,
				$plain,
				'' !== $title ? sprintf( __( 'Tell me about %s', 'adaptive-customer-engagement' ), $title ) : '',
				'' !== $title ? sprintf( __( 'I need help with %s', 'adaptive-customer-engagement' ), $title ) : '',
				'' !== $title ? sprintf( __( 'What is %s?', 'adaptive-customer-engagement' ), $title ) : '',
			)
		);

		return array_values( array_slice( array_unique( $utterances ), 0, 5 ) );
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
