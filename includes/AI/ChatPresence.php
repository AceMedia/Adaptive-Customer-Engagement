<?php
/**
 * Ephemeral chat typing / thinking state.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

defined( 'ABSPATH' ) || exit;

final class ChatPresence {
	private const TTL = 12;

	/**
	 * Update typing state for a chat actor.
	 *
	 * @param int                  $conversation_id Conversation ID.
	 * @param string               $actor           Actor key.
	 * @param bool                 $is_typing       Whether actor is typing/thinking.
	 * @param array<string,string> $data            Optional display data.
	 * @return void
	 */
	public static function set_typing( int $conversation_id, string $actor, bool $is_typing, array $data = array() ): void {
		$conversation_id = absint( $conversation_id );
		$actor           = self::sanitise_actor( $actor );

		if ( $conversation_id <= 0 || '' === $actor ) {
			return;
		}

		$key = self::build_key( $conversation_id, $actor );

		if ( ! $is_typing ) {
			delete_transient( $key );
			return;
		}

		set_transient(
			$key,
			array(
				'actor'      => $actor,
				'label'      => sanitize_text_field( (string) ( $data['label'] ?? self::get_default_label( $actor ) ) ),
				'name'       => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'status'     => self::sanitise_status( (string) ( $data['status'] ?? '' ) ),
				'updated_at' => current_time( 'mysql', true ),
			),
			self::TTL
		);
	}

	/**
	 * Read active typing state for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array<string, array<string, string>>
	 */
	public static function get_typing_state( int $conversation_id ): array {
		$conversation_id = absint( $conversation_id );

		if ( $conversation_id <= 0 ) {
			return array();
		}

		$state = array();

		foreach ( array( 'customer', 'agent', 'assistant' ) as $actor ) {
			$value = get_transient( self::build_key( $conversation_id, $actor ) );

			if ( ! is_array( $value ) ) {
				continue;
			}

			$state[ $actor ] = array(
				'actor'      => $actor,
				'label'      => sanitize_text_field( (string) ( $value['label'] ?? self::get_default_label( $actor ) ) ),
				'name'       => sanitize_text_field( (string) ( $value['name'] ?? '' ) ),
				'status'     => self::sanitise_status( (string) ( $value['status'] ?? '' ) ),
				'updated_at' => sanitize_text_field( (string) ( $value['updated_at'] ?? '' ) ),
			);
		}

		return $state;
	}

	/**
	 * Clear all typing state for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	public static function clear_conversation( int $conversation_id ): void {
		foreach ( array( 'customer', 'agent', 'assistant' ) as $actor ) {
			self::set_typing( $conversation_id, $actor, false );
		}
	}

	/**
	 * Build transient key.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $actor           Actor key.
	 * @return string
	 */
	private static function build_key( int $conversation_id, string $actor ): string {
		return 'ace_chat_presence_' . $conversation_id . '_' . $actor;
	}

	/**
	 * Sanitise an actor value.
	 *
	 * @param string $actor Raw actor.
	 * @return string
	 */
	private static function sanitise_actor( string $actor ): string {
		$actor = sanitize_key( $actor );

		return in_array( $actor, array( 'customer', 'agent', 'assistant' ), true ) ? $actor : '';
	}

	/**
	 * Default label for an actor.
	 *
	 * @param string $actor Actor key.
	 * @return string
	 */
	private static function get_default_label( string $actor ): string {
		switch ( $actor ) {
			case 'customer':
				return __( 'Customer', 'adaptive-customer-engagement' );
			case 'agent':
				return __( 'Agent', 'adaptive-customer-engagement' );
			case 'assistant':
				return __( 'Assistant', 'adaptive-customer-engagement' );
			default:
				return __( 'Participant', 'adaptive-customer-engagement' );
		}
	}

	/**
	 * Sanitise a presence status value.
	 *
	 * @param string $status Raw status value.
	 * @return string
	 */
	private static function sanitise_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, array( 'typing', 'thinking' ), true ) ? $status : '';
	}
}
