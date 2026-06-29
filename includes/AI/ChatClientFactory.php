<?php
/**
 * Resolves the active chat-completion provider from plugin settings.
 *
 * Centralises the provider/key/model selection that the frontend assistant and
 * the admin reporting tools all need, so the rest of the codebase never has to
 * branch on the provider name.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

defined( 'ABSPATH' ) || exit;

final class ChatClientFactory {
	/**
	 * Default OpenAI model when none is configured.
	 */
	private const OPENAI_DEFAULT_MODEL = 'gpt-4.1-mini';

	/**
	 * Build the client for a named provider.
	 *
	 * @param string $provider Provider key.
	 * @return ChatCompletionClient
	 */
	public static function make_for_provider( string $provider ): ChatCompletionClient {
		return 'anthropic' === sanitize_key( $provider ) ? new AnthropicClient() : new OpenAIClient();
	}

	/**
	 * Resolve which engine should produce voice (TTS/STT).
	 *
	 * Defaults to "auto": lean on OpenAI when an OpenAI key is already configured
	 * for the chatbot (no separate voice key needed), otherwise the browser. The
	 * admin can still force 'browser' or pick 'elevenlabs' explicitly.
	 *
	 * @param array<string, mixed> $ai_agent The `ai_agent` settings array.
	 * @return string One of 'openai', 'elevenlabs', or 'browser'.
	 */
	public static function effective_voice_provider( array $ai_agent ): string {
		$provider = sanitize_key( (string) ( $ai_agent['frontend_voice_provider'] ?? 'auto' ) );

		if ( in_array( $provider, array( 'browser', 'openai', 'elevenlabs' ), true ) ) {
			return $provider;
		}

		$openai_key = trim( (string) ( $ai_agent['voice_openai_api_key'] ?? '' ) );

		if ( '' === $openai_key ) {
			$openai_key = trim( (string) ( $ai_agent['openai_api_key'] ?? '' ) );
		}

		return '' !== $openai_key ? 'openai' : 'browser';
	}

	/**
	 * Resolve the active provider, client, API key, and model from AI settings.
	 *
	 * @param array<string, mixed> $ai_agent The `ai_agent` settings array.
	 * @return array{provider: string, client: ChatCompletionClient, api_key: string, model: string}
	 */
	public static function resolve( array $ai_agent ): array {
		$provider = sanitize_key( (string) ( $ai_agent['provider'] ?? 'openai' ) );

		if ( 'anthropic' === $provider ) {
			$model = sanitize_text_field( (string) ( $ai_agent['anthropic_model'] ?? '' ) );

			return array(
				'provider' => 'anthropic',
				'client'   => new AnthropicClient(),
				'api_key'  => sanitize_text_field( (string) ( $ai_agent['anthropic_api_key'] ?? '' ) ),
				'model'    => '' !== $model ? $model : AnthropicClient::DEFAULT_MODEL,
			);
		}

		$model = sanitize_text_field( (string) ( $ai_agent['openai_model'] ?? '' ) );

		return array(
			'provider' => 'openai',
			'client'   => new OpenAIClient(),
			'api_key'  => sanitize_text_field( (string) ( $ai_agent['openai_api_key'] ?? '' ) ),
			'model'    => '' !== $model ? $model : self::OPENAI_DEFAULT_MODEL,
		);
	}
}
