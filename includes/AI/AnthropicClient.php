<?php
/**
 * Anthropic (Claude) API client.
 *
 * Talks to the Claude Messages API over the WordPress HTTP layer, mirroring the
 * surface of {@see OpenAIClient} so the two providers are interchangeable behind
 * {@see ChatCompletionClient}.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AnthropicClient implements ChatCompletionClient {
	/**
	 * Anthropic API version pinned for every request.
	 */
	private const API_VERSION = '2023-06-01';

	/**
	 * Default model when none is configured.
	 */
	public const DEFAULT_MODEL = 'claude-opus-4-8';

	/**
	 * List chat-capable models for an API key.
	 *
	 * @param string $api_key Anthropic API key.
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_models( string $api_key ) {
		$api_key = sanitize_text_field( $api_key );

		if ( '' === $api_key ) {
			return new WP_Error( 'ace_anthropic_api_key_missing', __( 'Enter a Claude API key first.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$response = $this->request(
			'https://api.anthropic.com/v1/models?limit=1000',
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

			if ( '' === $model_id || ! preg_match( '/^claude-/i', $model_id ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $item['display_name'] ?? $model_id ) );

			$models[] = array(
				'id'      => $model_id,
				'label'   => '' !== $label ? $label : $model_id,
				'created' => strtotime( (string) ( $item['created_at'] ?? '' ) ) ?: 0,
			);
		}

		usort(
			$models,
			static function ( array $left, array $right ): int {
				return strcmp( $left['id'], $right['id'] );
			}
		);

		$preferred = '';

		foreach ( array( 'claude-opus-4-8', 'claude-sonnet-4-6', 'claude-haiku-4-5' ) as $candidate ) {
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
		$model   = sanitize_text_field( (string) ( $options['model'] ?? self::DEFAULT_MODEL ) );

		if ( '' === $api_key ) {
			return new WP_Error( 'ace_anthropic_api_key_missing', __( 'Save a Claude API key before using the frontend assistant.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'ace_anthropic_model_missing', __( 'Choose a Claude model before using the frontend assistant.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$prepared = $this->prepare_messages( $messages );

		if ( empty( $prepared['messages'] ) ) {
			return new WP_Error( 'ace_anthropic_messages_missing', __( 'The chat request did not contain any usable messages.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => max( 1, min( 8000, absint( $options['max_response_tokens'] ?? 700 ) ) ),
			'messages'   => $prepared['messages'],
		);

		// Claude reads the system prompt from a dedicated top-level field, not the
		// message list. Sampling parameters such as temperature are intentionally
		// omitted: the current Opus/Fable models reject them with a 400.
		if ( '' !== $prepared['system'] ) {
			$body['system'] = $prepared['system'];
		}

		$response = $this->request(
			'https://api.anthropic.com/v1/messages',
			array(
				'api_key' => $api_key,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = $this->extract_message_content( $response['content'] ?? '' );

		if ( '' === $content ) {
			return new WP_Error( 'ace_anthropic_empty_response', __( 'Claude did not return a reply for this message.', 'adaptive-customer-engagement' ), array( 'status' => 502 ) );
		}

		return array(
			'message' => $content,
			'model'   => sanitize_text_field( (string) ( $response['model'] ?? $model ) ),
			'usage'   => is_array( $response['usage'] ?? null ) ? $response['usage'] : array(),
		);
	}

	/**
	 * Split a mixed system/user/assistant message list into the shape the Claude
	 * Messages API expects: a single system string plus a strictly alternating
	 * user/assistant conversation that begins with a user turn.
	 *
	 * @param array<int, mixed> $messages Raw messages.
	 * @return array{system: string, messages: array<int, array<string, string>>}
	 */
	private function prepare_messages( array $messages ): array {
		$system_parts = array();
		$conversation = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = sanitize_key( (string) ( $message['role'] ?? '' ) );
			$content = sanitize_textarea_field( (string) ( $message['content'] ?? '' ) );

			if ( '' === $content ) {
				continue;
			}

			if ( 'system' === $role ) {
				$system_parts[] = $content;
				continue;
			}

			if ( 'user' !== $role && 'assistant' !== $role ) {
				continue;
			}

			$last = count( $conversation ) - 1;

			if ( $last >= 0 && $conversation[ $last ]['role'] === $role ) {
				// Collapse consecutive same-role turns — Claude requires alternation.
				$conversation[ $last ]['content'] .= "\n\n" . $content;
				continue;
			}

			$conversation[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		// The conversation must open with a user turn.
		while ( ! empty( $conversation ) && 'assistant' === $conversation[0]['role'] ) {
			array_shift( $conversation );
		}

		return array(
			'system'   => trim( implode( "\n\n", $system_parts ) ),
			'messages' => array_values( $conversation ),
		);
	}

	/**
	 * Perform an authenticated request to Anthropic.
	 *
	 * @param string               $url     Anthropic URL.
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
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VERSION,
				'Content-Type'      => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ace_anthropic_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $payload ) && ! empty( $payload['error']['message'] )
				? sanitize_text_field( (string) $payload['error']['message'] )
				: __( 'Claude returned an unexpected response.', 'adaptive-customer-engagement' );

			return new WP_Error( 'ace_anthropic_bad_response', $message, array( 'status' => 502 ) );
		}

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Extract text content from a Claude message payload.
	 *
	 * @param mixed $content Raw `content` payload (array of typed blocks).
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

			if ( isset( $item['type'] ) && 'text' !== $item['type'] ) {
				continue;
			}

			$text = $item['text'] ?? '';

			if ( is_string( $text ) && '' !== trim( $text ) ) {
				$parts[] = sanitize_textarea_field( $text );
			}
		}

		return trim( implode( "\n\n", $parts ) );
	}
}
