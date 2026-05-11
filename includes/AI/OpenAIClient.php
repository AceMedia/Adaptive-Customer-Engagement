<?php
/**
 * OpenAI API client.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class OpenAIClient {
	/**
	 * Create a chat completion.
	 *
	 * @param array<int, array<string, string>> $messages Conversation messages.
	 * @param array<string, mixed>              $options  Request options.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$api_key = sanitize_text_field( (string) ( $options['api_key'] ?? '' ) );
		$model   = sanitize_text_field( (string) ( $options['model'] ?? 'gpt-4.1-mini' ) );

		if ( '' === $api_key ) {
			return new WP_Error( 'ace_openai_api_key_missing', __( 'Save an OpenAI API key before using the frontend assistant.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'ace_openai_model_missing', __( 'Choose an OpenAI model before using the frontend assistant.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$sanitised_messages = array_values(
			array_filter(
				array_map( array( $this, 'sanitize_message' ), $messages )
			)
		);

		if ( empty( $sanitised_messages ) ) {
			return new WP_Error( 'ace_openai_messages_missing', __( 'The chat request did not contain any usable messages.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 35,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'                 => $model,
						'messages'              => $sanitised_messages,
						'temperature'           => max( 0, min( 2, (float) ( $options['temperature'] ?? 0.2 ) ) ),
						'max_completion_tokens' => max( 200, min( 4000, absint( $options['max_response_tokens'] ?? 700 ) ) ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ace_openai_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $body ) && ! empty( $body['error']['message'] )
				? sanitize_text_field( (string) $body['error']['message'] )
				: __( 'OpenAI returned an unexpected response.', 'adaptive-customer-engagement' );

			return new WP_Error( 'ace_openai_bad_response', $message, array( 'status' => 502 ) );
		}

		$content = $this->extract_message_content( $body['choices'][0]['message']['content'] ?? '' );

		if ( '' === $content ) {
			return new WP_Error( 'ace_openai_empty_response', __( 'OpenAI did not return a reply for this message.', 'adaptive-customer-engagement' ), array( 'status' => 502 ) );
		}

		return array(
			'message' => $content,
			'model'   => $model,
			'usage'   => is_array( $body['usage'] ?? null ) ? $body['usage'] : array(),
		);
	}

	/**
	 * Sanitize a single outgoing message.
	 *
	 * @param mixed $message Raw message.
	 * @return array<string, string>|null
	 */
	private function sanitize_message( $message ): ?array {
		if ( ! is_array( $message ) ) {
			return null;
		}

		$role    = sanitize_key( (string) ( $message['role'] ?? '' ) );
		$content = sanitize_textarea_field( (string) ( $message['content'] ?? '' ) );

		if ( ! in_array( $role, array( 'system', 'user', 'assistant' ), true ) || '' === $content ) {
			return null;
		}

		return array(
			'role'    => $role,
			'content' => $content,
		);
	}

	/**
	 * Extract text content from an OpenAI message payload.
	 *
	 * @param mixed $content Raw content payload.
	 * @return string
	 */
	private function extract_message_content( $content ): string {
		if ( is_string( $content ) ) {
			return trim( sanitize_textarea_field( $content ) );
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$parts = array();

		foreach ( $content as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$text = $item['text'] ?? $item['content'] ?? '';

			if ( is_string( $text ) && '' !== trim( $text ) ) {
				$parts[] = sanitize_textarea_field( $text );
			}
		}

		return trim( implode( "\n\n", $parts ) );
	}
}
