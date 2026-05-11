<?php
/**
 * Chat message repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ChatMessageRepository {
	/**
	 * Store a chat message.
	 *
	 * @param array<string, mixed> $data Message data.
	 * @return array<string, mixed>|null
	 */
	public function create( array $data ): ?array {
		global $wpdb;

		$conversation_id = absint( $data['conversation_id'] ?? 0 );

		if ( $conversation_id <= 0 ) {
			return null;
		}

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			Schema::table_name( 'chat_messages' ),
			array(
				'conversation_id' => $conversation_id,
				'session_id'      => ! empty( $data['session_id'] ) ? absint( $data['session_id'] ) : null,
				'company_id'      => ! empty( $data['company_id'] ) ? absint( $data['company_id'] ) : null,
				'message_role'    => sanitize_key( (string) ( $data['message_role'] ?? '' ) ),
				'message_text'    => sanitize_textarea_field( (string) ( $data['message_text'] ?? '' ) ),
				'sources'         => wp_json_encode( is_array( $data['sources'] ?? null ) ? $data['sources'] : array() ),
				'model'           => sanitize_text_field( (string) ( $data['model'] ?? '' ) ),
				'is_error'        => ! empty( $data['is_error'] ) ? 1 : 0,
				'created_at'      => $now,
			)
		);

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Find a message by ID.
	 *
	 * @param int $message_id Message ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $message_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'chat_messages' ) . ' WHERE id = %d LIMIT 1',
				$message_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate_message_row( $row ) : null;
	}

	/**
	 * Get messages for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_by_conversation( int $conversation_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'chat_messages' ) . ' WHERE conversation_id = %d ORDER BY id ASC',
				$conversation_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'hydrate_message_row' ), $rows ) : array();
	}

	/**
	 * Normalise stored row values.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function hydrate_message_row( array $row ): array {
		$row['sources']  = json_decode( (string) ( $row['sources'] ?? '[]' ), true );
		$row['is_error'] = ! empty( $row['is_error'] );

		return $row;
	}
}
