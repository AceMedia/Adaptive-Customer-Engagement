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
				'operator_user_id'=> ! empty( $data['operator_user_id'] ) ? absint( $data['operator_user_id'] ) : null,
				'author_name'     => sanitize_text_field( (string) ( $data['author_name'] ?? '' ) ),
				'author_avatar_url' => esc_url_raw( (string) ( $data['author_avatar_url'] ?? '' ) ),
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
	 * Get the most common user questions for frontend starter prompts.
	 *
	 * @param int $limit Maximum number of questions to return.
	 * @return array<int, string>
	 */
	public function get_common_user_questions( int $limit = 3 ): array {
		global $wpdb;

		$limit = max( 1, min( 6, $limit ) );
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT message_text FROM ' . Schema::table_name( 'chat_messages' ) . ' WHERE message_role = %s AND message_text IS NOT NULL AND message_text != %s ORDER BY id DESC LIMIT 500',
				'user',
				''
			)
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return $this->get_default_user_questions( $limit );
		}

		$counts  = array();
		$labels  = array();
		$recency = array();

		foreach ( $rows as $index => $row ) {
			$question = sanitize_textarea_field( (string) $row );
			$prepared = $this->prepare_user_question_for_stats( $question );

			if ( empty( $prepared['key'] ) || empty( $prepared['label'] ) ) {
				continue;
			}

			$key = (string) $prepared['key'];

			$counts[ $key ] = isset( $counts[ $key ] ) ? (int) $counts[ $key ] + 1 : 1;

			if ( ! isset( $labels[ $key ] ) ) {
				$labels[ $key ] = (string) $prepared['label'];
			}

			if ( ! isset( $recency[ $key ] ) ) {
				$recency[ $key ] = $index;
			}
		}

		if ( empty( $counts ) ) {
			return $this->get_default_user_questions( $limit );
		}

		uksort(
			$counts,
			static function ( string $left, string $right ) use ( $counts, $recency ): int {
				$left_count  = (int) ( $counts[ $left ] ?? 0 );
				$right_count = (int) ( $counts[ $right ] ?? 0 );

				if ( $left_count === $right_count ) {
					return (int) ( $recency[ $left ] ?? PHP_INT_MAX ) <=> (int) ( $recency[ $right ] ?? PHP_INT_MAX );
				}

				return $right_count <=> $left_count;
			}
		);

		$questions = array();

		foreach ( array_keys( $counts ) as $key ) {
			if ( count( $questions ) >= $limit ) {
				break;
			}

			if ( ! empty( $labels[ $key ] ) ) {
				$questions[] = (string) $labels[ $key ];
			}
		}

		return ! empty( $questions ) ? $questions : $this->get_default_user_questions( $limit );
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

	/**
	 * Prepare a raw user message for question aggregation.
	 *
	 * @param string $question Raw question text.
	 * @return array{key:string,label:string}
	 */
	private function prepare_user_question_for_stats( string $question ): array {
		$question = trim( preg_replace( '/\s+/', ' ', $question ) ?: '' );

		if ( '' === $question ) {
			return array(
				'key'   => '',
				'label' => '',
			);
		}

		$question = preg_replace( '/^(hi+|hello+|hey+|hiya+)\b[\s,!.-]*/i', '', $question ) ?: $question;
		$question = trim( $question );

		if ( '' === $question || strlen( $question ) < 8 || strlen( $question ) > 180 ) {
			return array(
				'key'   => '',
				'label' => '',
			);
		}

		$is_question = (bool) preg_match( '/\?$/', $question ) || (bool) preg_match( '/^(what|which|who|where|when|why|how|do|does|did|can|could|would|will|is|are|am|have|has|should)\b/i', $question );

		if ( ! $is_question ) {
			return array(
				'key'   => '',
				'label' => '',
			);
		}

		$label = ucfirst( rtrim( $question, " \t\n\r\0\x0B" ) );

		if ( ! preg_match( '/[?.!]$/', $label ) ) {
			$label .= '?';
		}

		$key = strtolower( remove_accents( $label ) );
		$key = preg_replace( '/[^a-z0-9]+/', ' ', $key ) ?: '';
		$key = trim( preg_replace( '/\s+/', ' ', $key ) ?: '' );

		return array(
			'key'   => sanitize_text_field( $key ),
			'label' => sanitize_text_field( $label ),
		);
	}

	/**
	 * Default starter questions when there is not enough chat history yet.
	 *
	 * @param int $limit Maximum number of questions to return.
	 * @return array<int, string>
	 */
	private function get_default_user_questions( int $limit ): array {
		return array_slice(
			array(
				__( 'Do you ship to my postcode?', 'adaptive-customer-engagement' ),
				__( 'Which bin sizes are available?', 'adaptive-customer-engagement' ),
				__( 'Can you help me choose the right product?', 'adaptive-customer-engagement' ),
			),
			0,
			$limit
		);
	}
}
