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

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cc.*, s.landing_path, s.utm_source, s.utm_campaign, c.name AS company_name, c.domain AS company_domain
				FROM {$conversations} cc
				LEFT JOIN {$sessions} s ON s.id = cc.session_id
				LEFT JOIN {$companies} c ON c.id = cc.company_id
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
		$now              = current_time( 'mysql', true );

		$wpdb->update(
			Schema::table_name( 'chat_conversations' ),
			array(
				'status'           => $status,
				'handover_enabled' => $handover_enabled ? 1 : 0,
				'ended_at'         => '' !== $ended_at ? $ended_at : null,
				'ended_by'         => '' !== $ended_by ? $ended_by : null,
				'updated_at'       => $now,
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
				'ended_at'         => '',
				'ended_by'         => '',
			)
		);
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
		$filter_fragments  = $this->build_filters( $filters );
		$params            = $filter_fragments['params'];
		$params[]          = $limit;
		$params[]          = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cc.*, c.name AS company_name, c.domain AS company_domain,
					(
						SELECT cm.message_text
						FROM {$messages} cm
						WHERE cm.conversation_id = cc.id AND cm.message_role = 'user'
						ORDER BY cm.id ASC
						LIMIT 1
					) AS first_user_message
				FROM {$conversations} cc
				LEFT JOIN {$companies} c ON c.id = cc.company_id
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

		if ( ! empty( $filters['session_id'] ) ) {
			$where[]  = 'cc.session_id = %d';
			$params[] = absint( $filters['session_id'] );
		}

		if ( ! empty( $filters['company_id'] ) ) {
			$where[]  = 'cc.company_id = %d';
			$params[] = absint( $filters['company_id'] );
		}

		DateRange::append_filters( $where, $params, 'cc.last_message_at', (string) ( $filters['date_from'] ?? '' ), (string) ( $filters['date_to'] ?? '' ) );

		if ( ! empty( $filters['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = "(cc.conversation_uuid LIKE %s OR cc.session_uuid LIKE %s OR cc.visitor_uuid LIKE %s OR cc.page_title LIKE %s OR cc.page_url LIKE %s OR c.name LIKE %s OR EXISTS (SELECT 1 FROM {$messages} cm_search WHERE cm_search.conversation_id = cc.id AND cm_search.message_text LIKE %s))";
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
