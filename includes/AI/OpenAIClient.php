<?php
/**
 * OpenAI API client.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class OpenAIClient implements ChatCompletionClient {
	/**
	 * List chat-capable models for an API key.
	 *
	 * @param string $api_key OpenAI API key.
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_models( string $api_key ) {
		$api_key = sanitize_text_field( $api_key );

		if ( '' === $api_key ) {
			return new WP_Error( 'ace_openai_api_key_missing', __( 'Enter an OpenAI API key first.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$response = $this->request(
			'https://api.openai.com/v1/models',
			array(
				'api_key' => $api_key,
				'method'  => 'GET',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$models = array();

		foreach ( is_array( $response['data'] ?? null ) ? $response['data'] : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$model_id = sanitize_text_field( (string) ( $item['id'] ?? '' ) );

			if ( '' === $model_id || ! preg_match( '/^(gpt-|o[134]|chatgpt-)/i', $model_id ) ) {
				continue;
			}

			$models[] = array(
				'id'      => $model_id,
				'label'   => $model_id,
				'created' => absint( $item['created'] ?? 0 ),
			);
		}

		usort(
			$models,
			static function ( array $left, array $right ): int {
				return strcmp( $left['id'], $right['id'] );
			}
		);

		$preferred = '';

		foreach ( array( 'gpt-4.1-mini', 'gpt-4.1', 'gpt-4o-mini' ) as $candidate ) {
			foreach ( $models as $model ) {
				if ( $candidate === $model['id'] ) {
					$preferred = $candidate;
					break 2;
				}
			}
		}

		if ( '' === $preferred && ! empty( $models[0]['id'] ) ) {
			$preferred = $models[0]['id'];
		}

		return array(
			'active'          => true,
			'models'          => $models,
			'preferred_model' => $preferred,
		);
	}

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

		$response = $this->request(
			'https://api.openai.com/v1/chat/completions',
			array(
				'api_key' => $api_key,
				'body'    => array(
					'model'                 => $model,
					'messages'              => $sanitised_messages,
					'temperature'           => max( 0, min( 2, (float) ( $options['temperature'] ?? 0.2 ) ) ),
					'max_completion_tokens' => max( 200, min( 8000, absint( $options['max_response_tokens'] ?? 700 ) ) ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = $this->extract_message_content( $response['choices'][0]['message']['content'] ?? '' );

		if ( '' === $content ) {
			return new WP_Error( 'ace_openai_empty_response', __( 'OpenAI did not return a reply for this message.', 'adaptive-customer-engagement' ), array( 'status' => 502 ) );
		}

		return array(
			'message' => $content,
			'model'   => $model,
			'usage'   => is_array( $response['usage'] ?? null ) ? $response['usage'] : array(),
		);
	}

	/**
	 * Perform an authenticated request to OpenAI.
	 *
	 * @param string               $url     OpenAI URL.
	 * @param array<string, mixed> $options Request options.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $url, array $options = array() ) {
		$api_key = sanitize_text_field( (string) ( $options['api_key'] ?? '' ) );
		$method  = strtoupper( sanitize_text_field( (string) ( $options['method'] ?? 'POST' ) ) );
		$body    = $options['body'] ?? null;
		$args    = array(
			'method'  => $method,
			'timeout' => 35,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ace_openai_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $payload ) && ! empty( $payload['error']['message'] )
				? sanitize_text_field( (string) $payload['error']['message'] )
				: __( 'OpenAI returned an unexpected response.', 'adaptive-customer-engagement' );

			return new WP_Error( 'ace_openai_bad_response', $message, array( 'status' => 502 ) );
		}

		return is_array( $payload ) ? $payload : array();
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
