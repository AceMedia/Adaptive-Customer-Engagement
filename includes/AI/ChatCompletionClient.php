<?php
/**
 * Shared contract for chat-completion providers (OpenAI, Anthropic, …).
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface ChatCompletionClient {
	/**
	 * List chat-capable models for an API key.
	 *
	 * @param string $api_key Provider API key.
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_models( string $api_key );

	/**
	 * Create a chat completion.
	 *
	 * @param array<int, array<string, string>> $messages Conversation messages (roles: system|user|assistant).
	 * @param array<string, mixed>              $options  Request options (api_key, model, temperature, max_response_tokens).
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_chat_completion( array $messages, array $options = array() );
}
