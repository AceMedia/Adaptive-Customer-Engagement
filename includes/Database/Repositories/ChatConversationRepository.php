<?php
/**
 * Chat conversation repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\DateRange;
use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ChatConversationRepository {
	public const STATUS_OPEN     = 'open';
	public const STATUS_HANDOVER = 'handover';
	public const STATUS_ENDED    = 'ended';
	public const COMMERCIAL_STATUS_NEW      = 'new';
	public const COMMERCIAL_STATUS_WORKING  = 'working';
	public const COMMERCIAL_STATUS_WAITING  = 'waiting';
	public const COMMERCIAL_STATUS_QUALIFIED = 'qualified';
	public const COMMERCIAL_STATUS_CLOSED   = 'closed';
	public const PRIORITY_LOW    = 'low';
	public const PRIORITY_NORMAL = 'normal';
	public const PRIORITY_HIGH   = 'high';
	public const PRIORITY_URGENT = 'urgent';

	/**
	 * Create or update a conversation shell.
	 *
	 * @param string               $conversation_uuid Conversation UUID.
	 * @param array<string, mixed> $data              Conversation data.
	 * @return array<string, mixed>|null
	 */
	public function create_or_touch( string $conversation_uuid, array $data ): ?array {
		global $wpdb;

		if ( '' === $conversation_uuid ) {
			return null;
		}

		$table    = Schema::table_name( 'chat_conversations' );
		$existing = $this->find_by_uuid( $conversation_uuid );
		$now      = current_time( 'mysql', true );
		$payload  = array(
			'session_id'   => ! empty( $data['session_id'] ) ? absint( $data['session_id'] ) : null,
			'session_uuid' => sanitize_text_field( (string) ( $data['session_uuid'] ?? '' ) ),
			'visitor_uuid' => sanitize_text_field( (string) ( $data['visitor_uuid'] ?? '' ) ),
			'company_id'   => ! empty( $data['company_id'] ) ? absint( $data['company_id'] ) : null,
			'page_url'     => esc_url_raw( (string) ( $data['page_url'] ?? '' ) ),
			'page_title'   => sanitize_text_field( (string) ( $data['page_title'] ?? '' ) ),
			'provider'     => sanitize_key( (string) ( $data['provider'] ?? 'openai' ) ) ?: 'openai',
			'model'        => sanitize_text_field( (string) ( $data['model'] ?? '' ) ),
			'updated_at'   => $now,
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'session_id'   => $payload['session_id'] ?: $existing['session_id'],
					'session_uuid' => $payload['session_uuid'] ?: $existing['session_uuid'],
					'visitor_uuid' => $payload['visitor_uuid'] ?: $existing['visitor_uuid'],
					'company_id'   => $payload['company_id'] ?: $existing['company_id'],
					'page_url'     => $payload['page_url'] ?: $existing['page_url'],
					'page_title'   => $payload['page_title'] ?: $existing['page_title'],
					'provider'     => $payload['provider'],
					'model'        => $payload['model'] ?: $existing['model'],
					'updated_at'   => $now,
				),
				array( 'id' => (int) $existing['id'] )
			);

			return $this->find( (int) $existing['id'] );
		}

		$wpdb->insert(
			$table,
			array_merge(
				$payload,
				array(
					'conversation_uuid'      => $conversation_uuid,
					'message_count'          => 0,
					'user_message_count'     => 0,
					'assistant_message_count'=> 0,
					'operator_message_count' => 0,
					'status'                 => self::STATUS_OPEN,
					'commercial_status'      => self::COMMERCIAL_STATUS_NEW,
					'commercial_outcome'     => '',
					'priority'               => self::PRIORITY_NORMAL,
					'owner_user_id'          => null,
					'handover_requested'     => 0,
					'handover_requested_at'  => null,
					'follow_up_requested'    => 0,
					'follow_up_at'           => null,
					'contact_name'           => '',
					'contact_email'          => '',
					'contact_phone'          => '',
					'contact_company'        => '',
					'contact_role'           => '',
					'lead_summary'           => '',
					'company_prompted_at'    => null,
					'contact_prompted_at'    => null,
					'contact_captured_at'    => null,
					'internal_notes'         => '',
					'handover_enabled'       => 0,
					'started_at'             => $now,
					'last_message_at'        => $now,
					'created_at'             => $now,
				)
			)
		);

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Increment conversation message totals.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $role            Message role.
	 * @param string $model           Active model.
	 * @return void
	 */
	public function record_message( int $conversation_id, string $role, string $model = '' ): void {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return;
		}

		$role = sanitize_key( $role );
		$now  = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table_name( 'chat_conversations' ) . ' SET message_count = message_count + 1, user_message_count = user_message_count + %d, assistant_message_count = assistant_message_count + %d, operator_message_count = operator_message_count + %d, model = %s, last_message_at = %s, updated_at = %s WHERE id = %d',
				'user' === $role ? 1 : 0,
				'assistant' === $role ? 1 : 0,
				'operator' === $role ? 1 : 0,
				sanitize_text_field( $model ),
				$now,
				$now,
				$conversation_id
			)
		);
	}

	/**
	 * Find a conversation by ID.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $conversation_id ): ?array {
		global $wpdb;

		$conversations = Schema::table_name( 'chat_conversations' );
		$sessions      = Schema::table_name( 'sessions' );
		$companies     = Schema::table_name( 'companies' );
		$users         = $wpdb->users;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cc.*, s.landing_path, s.utm_source, s.utm_campaign, c.name AS company_name, c.domain AS company_domain, u.display_name AS owner_name
				FROM {$conversations} cc
				LEFT JOIN {$sessions} s ON s.id = cc.session_id
				LEFT JOIN {$companies} c ON c.id = cc.company_id
				LEFT JOIN {$users} u ON u.ID = cc.owner_user_id
				WHERE cc.id = %d
				LIMIT 1",
				$conversation_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update a conversation state.
	 *
	 * @param int                  $conversation_id Conversation ID.
	 * @param array<string, mixed> $data            State payload.
	 * @return array<string, mixed>|null
	 */
	public function update_state( int $conversation_id, array $data ): ?array {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return null;
		}

		$status = sanitize_key( (string) ( $data['status'] ?? self::STATUS_OPEN ) );

		if ( ! in_array( $status, array( self::STATUS_OPEN, self::STATUS_HANDOVER, self::STATUS_ENDED ), true ) ) {
			$status = self::STATUS_OPEN;
		}

		$handover_enabled = ! empty( $data['handover_enabled'] );
		$ended_at         = isset( $data['ended_at'] ) ? sanitize_text_field( (string) $data['ended_at'] ) : '';
		$ended_by         = isset( $data['ended_by'] ) ? sanitize_key( (string) $data['ended_by'] ) : '';
		$handover_requested = ! empty( $data['handover_requested'] );
		$handover_requested_at = isset( $data['handover_requested_at'] ) ? sanitize_text_field( (string) $data['handover_requested_at'] ) : '';
		$now              = current_time( 'mysql', true );

		$wpdb->update(
			Schema::table_name( 'chat_conversations' ),
			array(
				'status'           => $status,
				'handover_enabled' => $handover_enabled ? 1 : 0,
				'handover_requested' => $handover_requested ? 1 : 0,
				'handover_requested_at' => '' !== $handover_requested_at ? $handover_requested_at : null,
				'ended_at'         => '' !== $ended_at ? $ended_at : null,
				'ended_by'         => '' !== $ended_by ? $ended_by : null,
				'updated_at'       => $now,
			),
			array( 'id' => $conversation_id )
		);

		return $this->find( $conversation_id );
	}

	/**
	 * Update the commercial workflow metadata for a conversation.
	 *
	 * @param int                  $conversation_id Conversation ID.
	 * @param array<string, mixed> $data            Workflow payload.
	 * @return array<string, mixed>|null
	 */
	public function update_workflow( int $conversation_id, array $data ): ?array {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return null;
		}

		$commercial_status = sanitize_key( (string) ( $data['commercial_status'] ?? self::COMMERCIAL_STATUS_NEW ) );
		$allowed_statuses  = self::get_commercial_statuses();

		if ( ! in_array( $commercial_status, $allowed_statuses, true ) ) {
			$commercial_status = self::COMMERCIAL_STATUS_NEW;
		}

		$priority          = sanitize_key( (string) ( $data['priority'] ?? self::PRIORITY_NORMAL ) );
		$allowed_priorities = self::get_priorities();

		if ( ! in_array( $priority, $allowed_priorities, true ) ) {
			$priority = self::PRIORITY_NORMAL;
		}

		$commercial_outcome = sanitize_key( (string) ( $data['commercial_outcome'] ?? '' ) );
		$allowed_outcomes   = self::get_commercial_outcomes();

		if ( '' !== $commercial_outcome && ! in_array( $commercial_outcome, $allowed_outcomes, true ) ) {
			$commercial_outcome = '';
		}

		$follow_up_at = sanitize_text_field( (string) ( $data['follow_up_at'] ?? '' ) );
		$owner_user_id = ! empty( $data['owner_user_id'] ) ? absint( $data['owner_user_id'] ) : 0;
		$notes         = sanitize_textarea_field( (string) ( $data['internal_notes'] ?? '' ) );
		$now           = current_time( 'mysql', true );

		$wpdb->update(
			Schema::table_name( 'chat_conversations' ),
			array(
				'commercial_status'  => $commercial_status,
				'commercial_outcome' => '' !== $commercial_outcome ? $commercial_outcome : null,
				'priority'           => $priority,
				'owner_user_id'      => $owner_user_id > 0 ? $owner_user_id : null,
				'follow_up_at'       => '' !== $follow_up_at ? $follow_up_at : null,
				'internal_notes'     => $notes,
				'updated_at'         => $now,
			),
			array( 'id' => $conversation_id )
		);

		return $this->find( $conversation_id );
	}

	/**
	 * Store visitor follow-up contact details against a conversation.
	 *
	 * @param int                  $conversation_id Conversation ID.
	 * @param array<string, mixed> $data            Contact payload.
	 * @return array<string, mixed>|null
	 */
	public function record_follow_up_request( int $conversation_id, array $data ): ?array {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return null;
		}

		$contact_name  = sanitize_text_field( (string) ( $data['contact_name'] ?? '' ) );
		$contact_email = sanitize_email( (string) ( $data['contact_email'] ?? '' ) );
		$contact_phone = sanitize_text_field( (string) ( $data['contact_phone'] ?? '' ) );
		$contact_company = sanitize_text_field( (string) ( $data['contact_company'] ?? '' ) );
		$contact_role  = sanitize_text_field( (string) ( $data['contact_role'] ?? '' ) );
		$follow_up_at  = sanitize_text_field( (string) ( $data['follow_up_at'] ?? current_time( 'mysql', true ) ) );
		$notes         = sanitize_textarea_field( (string) ( $data['internal_notes'] ?? '' ) );
		$now           = current_time( 'mysql', true );

		$wpdb->update(
			Schema::table_name( 'chat_conversations' ),
			array(
				'follow_up_requested' => 1,
				'follow_up_at'        => '' !== $follow_up_at ? $follow_up_at : $now,
				'contact_name'        => '' !== $contact_name ? $contact_name : null,
				'contact_email'       => '' !== $contact_email ? $contact_email : null,
				'contact_phone'       => '' !== $contact_phone ? $contact_phone : null,
				'contact_company'     => '' !== $contact_company ? $contact_company : null,
				'contact_role'        => '' !== $contact_role ? $contact_role : null,
				'contact_captured_at' => $now,
				'commercial_status'   => self::COMMERCIAL_STATUS_WAITING,
				'internal_notes'      => $notes,
				'updated_at'          => $now,
			),
			array( 'id' => $conversation_id )
		);

		return $this->find( $conversation_id );
	}

	/**
	 * Update captured lead details for a conversation.
	 *
	 * @param int                  $conversation_id Conversation ID.
	 * @param array<string, mixed> $data            Lead payload.
	 * @return array<string, mixed>|null
	 */
	public function update_lead_profile( int $conversation_id, array $data ): ?array {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return null;
		}

		$existing = $this->find( $conversation_id );

		if ( ! $existing ) {
			return null;
		}

		$contact_name    = sanitize_text_field( (string) ( $data['contact_name'] ?? '' ) );
		$contact_email   = sanitize_email( (string) ( $data['contact_email'] ?? '' ) );
		$contact_phone   = sanitize_text_field( (string) ( $data['contact_phone'] ?? '' ) );
		$contact_company = sanitize_text_field( (string) ( $data['contact_company'] ?? '' ) );
		$contact_role    = sanitize_text_field( (string) ( $data['contact_role'] ?? '' ) );
		$lead_summary    = sanitize_textarea_field( (string) ( $data['lead_summary'] ?? '' ) );
		$company_id      = ! empty( $data['company_id'] ) ? absint( $data['company_id'] ) : 0;
		$follow_up_requested = ! empty( $data['follow_up_requested'] ) || ! empty( $existing['follow_up_requested'] );
		$follow_up_at    = sanitize_text_field( (string) ( $data['follow_up_at'] ?? ( $existing['follow_up_at'] ?? '' ) ) );
		$contact_captured_at = sanitize_text_field( (string) ( $data['contact_captured_at'] ?? ( $existing['contact_captured_at'] ?? '' ) ) );
		$now             = current_time( 'mysql', true );

		if ( $follow_up_requested && '' === $follow_up_at ) {
			$follow_up_at = $now;
		}

		if ( $follow_up_requested && '' === $contact_captured_at ) {
			$contact_captured_at = $now;
		}

		$wpdb->update(
			Schema::table_name( 'chat_conversations' ),
			array(
				'company_id'         => $company_id > 0 ? $company_id : $existing['company_id'],
				'contact_name'       => '' !== $contact_name ? $contact_name : $existing['contact_name'],
				'contact_email'      => '' !== $contact_email ? $contact_email : $existing['contact_email'],
				'contact_phone'      => '' !== $contact_phone ? $contact_phone : $existing['contact_phone'],
				'contact_company'    => '' !== $contact_company ? $contact_company : $existing['contact_company'],
				'contact_role'       => '' !== $contact_role ? $contact_role : $existing['contact_role'],
				'lead_summary'       => '' !== $lead_summary ? $lead_summary : $existing['lead_summary'],
				'follow_up_requested'=> $follow_up_requested ? 1 : 0,
				'follow_up_at'       => '' !== $follow_up_at ? $follow_up_at : $existing['follow_up_at'],
				'contact_captured_at'=> '' !== $contact_captured_at ? $contact_captured_at : $existing['contact_captured_at'],
				'commercial_status'  => $follow_up_requested && self::COMMERCIAL_STATUS_CLOSED !== (string) ( $existing['commercial_status'] ?? '' )
					? self::COMMERCIAL_STATUS_WAITING
					: $existing['commercial_status'],
				'updated_at'         => $now,
			),
			array( 'id' => $conversation_id )
		);

		return $this->find( $conversation_id );
	}

	/**
	 * Mark one of the AI lead prompts as already asked.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $prompt_type     Prompt type.
	 * @return array<string, mixed>|null
	 */
	public function mark_prompted( int $conversation_id, string $prompt_type ): ?array {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return null;
		}

		$field = 'contact' === sanitize_key( $prompt_type ) ? 'contact_prompted_at' : 'company_prompted_at';

		$wpdb->update(
			Schema::table_name( 'chat_conversations' ),
			array(
				$field      => current_time( 'mysql', true ),
				'updated_at'=> current_time( 'mysql', true ),
			),
			array( 'id' => $conversation_id )
		);

		return $this->find( $conversation_id );
	}

	/**
	 * Start or stop human handover for a conversation.
	 *
	 * @param int  $conversation_id Conversation ID.
	 * @param bool $enabled         Whether handover is enabled.
	 * @return array<string, mixed>|null
	 */
	public function set_handover( int $conversation_id, bool $enabled ): ?array {
		return $this->update_state(
			$conversation_id,
			array(
				'status'           => $enabled ? self::STATUS_HANDOVER : self::STATUS_OPEN,
				'handover_enabled' => $enabled,
				'handover_requested' => $enabled,
				'handover_requested_at' => $enabled ? current_time( 'mysql', true ) : '',
				'ended_at'         => '',
				'ended_by'         => '',
			)
		);
	}

	/**
	 * Mark a conversation as explicitly requesting a human handover.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array<string, mixed>|null
	 */
	public function request_handover( int $conversation_id ): ?array {
		return $this->update_state(
			$conversation_id,
			array(
				'status'                => self::STATUS_OPEN,
				'handover_enabled'      => false,
				'handover_requested'    => true,
				'handover_requested_at' => current_time( 'mysql', true ),
				'ended_at'              => '',
				'ended_by'              => '',
			)
		);
	}

	/**
	 * Clear an outstanding handover request flag.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array<string, mixed>|null
	 */
	public function clear_handover_request( int $conversation_id ): ?array {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return null;
		}

		$wpdb->update(
			Schema::table_name( 'chat_conversations' ),
			array(
				'handover_requested'    => 0,
				'handover_requested_at' => null,
				'updated_at'            => current_time( 'mysql', true ),
			),
			array( 'id' => $conversation_id )
		);

		return $this->find( $conversation_id );
	}

	/**
	 * Mark a conversation as ended.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $ended_by        Actor ending the conversation.
	 * @return array<string, mixed>|null
	 */
	public function end_conversation( int $conversation_id, string $ended_by ): ?array {
		return $this->update_state(
			$conversation_id,
			array(
				'status'           => self::STATUS_ENDED,
				'handover_enabled' => false,
				'ended_at'         => current_time( 'mysql', true ),
				'ended_by'         => $ended_by,
			)
		);
	}

	/**
	 * Find a conversation by UUID.
	 *
	 * @param string $conversation_uuid Conversation UUID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_uuid( string $conversation_uuid ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'chat_conversations' ) . ' WHERE conversation_uuid = %s LIMIT 1',
				$conversation_uuid
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Count stored conversations.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return int
	 */
	public function count_conversations( array $filters = array() ): int {
		global $wpdb;

		$filter_fragments = $this->build_filters( $filters );
		$query            = 'SELECT COUNT(DISTINCT cc.id) FROM ' . Schema::table_name( 'chat_conversations' ) . ' cc LEFT JOIN ' . Schema::table_name( 'companies' ) . ' c ON c.id = cc.company_id ' . $filter_fragments['where'];
		$total            = empty( $filter_fragments['params'] )
			? $wpdb->get_var( $query ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $query, $filter_fragments['params'] ) );

		return (int) $total;
	}

	/**
	 * Count stored messages.
	 *
	 * @return int
	 */
	public function count_messages(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name( 'chat_messages' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get conversations for reporting.
	 *
	 * @param int                  $limit   Row limit.
	 * @param array<string, string> $filters Filters.
	 * @param int                  $offset  Row offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_conversations( int $limit = 25, array $filters = array(), int $offset = 0 ): array {
		global $wpdb;

		$conversations     = Schema::table_name( 'chat_conversations' );
		$companies         = Schema::table_name( 'companies' );
		$messages          = Schema::table_name( 'chat_messages' );
		$users             = $wpdb->users;
		$filter_fragments  = $this->build_filters( $filters );
		$params            = $filter_fragments['params'];
		$params[]          = $limit;
		$params[]          = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cc.*, c.name AS company_name, c.domain AS company_domain, u.display_name AS owner_name,
					(
						SELECT cm.message_text
						FROM {$messages} cm
						WHERE cm.conversation_id = cc.id AND cm.message_role = 'user'
						ORDER BY cm.id ASC
						LIMIT 1
					) AS first_user_message
				FROM {$conversations} cc
				LEFT JOIN {$companies} c ON c.id = cc.company_id
				LEFT JOIN {$users} u ON u.ID = cc.owner_user_id
				{$filter_fragments['where']}
				ORDER BY cc.last_message_at DESC, cc.id DESC
				LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get conversations linked to a session.
	 *
	 * @param int $session_id Session ID.
	 * @param int $limit      Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_by_session( int $session_id, int $limit = 12 ): array {
		return $this->get_conversations(
			$limit,
			array(
				'session_id' => (string) $session_id,
			),
			0
		);
	}

	/**
	 * Get conversations linked to a company.
	 *
	 * @param int $company_id Company ID.
	 * @param int $limit      Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_by_company( int $company_id, int $limit = 12 ): array {
		return $this->get_conversations(
			$limit,
			array(
				'company_id' => (string) $company_id,
			),
			0
		);
	}

	/**
	 * Get distinct provider names.
	 *
	 * @return array<int, string>
	 */
	public function get_providers(): array {
		global $wpdb;

		$results = $wpdb->get_col( 'SELECT DISTINCT provider FROM ' . Schema::table_name( 'chat_conversations' ) . " WHERE provider IS NOT NULL AND provider != '' ORDER BY provider ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_values( array_filter( array_map( 'strval', is_array( $results ) ? $results : array() ) ) );
	}

	/**
	 * Get distinct model names.
	 *
	 * @return array<int, string>
	 */
	public function get_models(): array {
		global $wpdb;

		$results = $wpdb->get_col( 'SELECT DISTINCT model FROM ' . Schema::table_name( 'chat_conversations' ) . " WHERE model IS NOT NULL AND model != '' ORDER BY model ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_values( array_filter( array_map( 'strval', is_array( $results ) ? $results : array() ) ) );
	}

	/**
	 * Get allowed commercial workflow statuses.
	 *
	 * @return array<int, string>
	 */
	public static function get_commercial_statuses(): array {
		return array(
			self::COMMERCIAL_STATUS_NEW,
			self::COMMERCIAL_STATUS_WORKING,
			self::COMMERCIAL_STATUS_WAITING,
			self::COMMERCIAL_STATUS_QUALIFIED,
			self::COMMERCIAL_STATUS_CLOSED,
		);
	}

	/**
	 * Get allowed commercial outcomes.
	 *
	 * @return array<int, string>
	 */
	public static function get_commercial_outcomes(): array {
		return array(
			'quote_requested',
			'qualified',
			'support',
			'nurture',
			'won',
			'lost',
			'no_action',
		);
	}

	/**
	 * Get allowed conversation priorities.
	 *
	 * @return array<int, string>
	 */
	public static function get_priorities(): array {
		return array(
			self::PRIORITY_LOW,
			self::PRIORITY_NORMAL,
			self::PRIORITY_HIGH,
			self::PRIORITY_URGENT,
		);
	}

	/**
	 * Count stored conversations matching filters.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return int
	 */
	public function count_due_follow_ups( array $filters = array() ): int {
		$filters['due_only'] = '1';

		return $this->count_conversations( $filters );
	}

	/**
	 * Get recent handover alerts for online admins.
	 *
	 * @param string $since_gmt Optional ISO/mysql timestamp lower bound.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_handover_alerts( string $since_gmt = '' ): array {
		global $wpdb;

		$conversations = Schema::table_name( 'chat_conversations' );
		$messages      = Schema::table_name( 'chat_messages' );
		$params        = array();
		$where         = array(
			'cc.handover_requested = 1',
			'cc.ended_at IS NULL',
		);

		if ( '' !== $since_gmt ) {
			$where[]  = 'cc.handover_requested_at > %s';
			$params[] = sanitize_text_field( $since_gmt );
		}

		$query = "SELECT cc.id, cc.conversation_uuid, cc.page_title, cc.page_url, cc.handover_requested_at, cc.last_message_at,
			(
				SELECT cm.message_text
				FROM {$messages} cm
				WHERE cm.conversation_id = cc.id AND cm.message_role = 'user'
				ORDER BY cm.id DESC
				LIMIT 1
			) AS latest_user_message
			FROM {$conversations} cc
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY cc.handover_requested_at DESC
			LIMIT 20';

		$rows = empty( $params )
			? $wpdb->get_results( $query, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build conversation filter SQL.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return array{where:string,params:array<int, mixed>}
	 */
	private function build_filters( array $filters ): array {
		global $wpdb;

		$where    = array();
		$params   = array();
		$messages = Schema::table_name( 'chat_messages' );

		if ( ! empty( $filters['provider'] ) ) {
			$where[]  = 'cc.provider = %s';
			$params[] = sanitize_key( $filters['provider'] );
		}

		if ( ! empty( $filters['model'] ) ) {
			$where[]  = 'cc.model = %s';
			$params[] = sanitize_text_field( $filters['model'] );
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'cc.status = %s';
			$params[] = sanitize_key( $filters['status'] );
		}

		if ( ! empty( $filters['commercial_status'] ) ) {
			$where[]  = 'cc.commercial_status = %s';
			$params[] = sanitize_key( $filters['commercial_status'] );
		}

		if ( ! empty( $filters['commercial_outcome'] ) ) {
			$where[]  = 'cc.commercial_outcome = %s';
			$params[] = sanitize_key( $filters['commercial_outcome'] );
		}

		if ( ! empty( $filters['priority'] ) ) {
			$where[]  = 'cc.priority = %s';
			$params[] = sanitize_key( $filters['priority'] );
		}

		if ( ! empty( $filters['owner_user_id'] ) ) {
			$where[]  = 'cc.owner_user_id = %d';
			$params[] = absint( $filters['owner_user_id'] );
		}

		if ( ! empty( $filters['session_id'] ) ) {
			$where[]  = 'cc.session_id = %d';
			$params[] = absint( $filters['session_id'] );
		}

		if ( ! empty( $filters['company_id'] ) ) {
			$where[]  = 'cc.company_id = %d';
			$params[] = absint( $filters['company_id'] );
		}

		DateRange::append_filters( $where, $params, 'cc.last_message_at', (string) ( $filters['date_from'] ?? '' ), (string) ( $filters['date_to'] ?? '' ) );

		if ( ! empty( $filters['due_only'] ) ) {
			$where[] = "cc.follow_up_at IS NOT NULL AND cc.follow_up_at != '' AND cc.follow_up_at <= UTC_TIMESTAMP() AND cc.commercial_status != 'closed'";
		}

		if ( ! empty( $filters['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = "(cc.conversation_uuid LIKE %s OR cc.session_uuid LIKE %s OR cc.visitor_uuid LIKE %s OR cc.page_title LIKE %s OR cc.page_url LIKE %s OR cc.contact_name LIKE %s OR cc.contact_email LIKE %s OR cc.contact_phone LIKE %s OR c.name LIKE %s OR EXISTS (SELECT 1 FROM {$messages} cm_search WHERE cm_search.conversation_id = cc.id AND cm_search.message_text LIKE %s))";
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		return array(
			'where'  => $where ? 'WHERE ' . implode( ' AND ', $where ) : '',
			'params' => $params,
		);
	}
}
