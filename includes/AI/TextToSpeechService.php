<?php
/**
 * Premium text-to-speech synthesis (OpenAI or ElevenLabs).
 *
 * Produces spoken-reply audio server-side so provider keys never reach the
 * browser. The frontend falls back to the browser's own speech synthesis when
 * no premium provider is configured or a request fails.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class TextToSpeechService {
	/**
	 * Maximum characters synthesised per request.
	 */
	private const MAX_CHARS = 2000;

	/**
	 * Whether a premium voice provider is configured and usable.
	 *
	 * @param array<string, mixed> $ai_agent The `ai_agent` settings array.
	 * @return bool
	 */
	public function is_configured( array $ai_agent ): bool {
		switch ( ChatClientFactory::effective_voice_provider( $ai_agent ) ) {
			case 'openai':
				return '' !== trim( (string) ( $ai_agent['voice_openai_api_key'] ?? '' ) )
					|| '' !== trim( (string) ( $ai_agent['openai_api_key'] ?? '' ) );
			case 'elevenlabs':
				return '' !== trim( (string) ( $ai_agent['voice_elevenlabs_api_key'] ?? '' ) )
					&& '' !== trim( (string) ( $ai_agent['voice_elevenlabs_voice_id'] ?? '' ) );
			default:
				return false;
		}
	}

	/**
	 * Synthesise speech for a snippet of text.
	 *
	 * @param string               $text     Text to speak.
	 * @param array<string, mixed> $ai_agent The `ai_agent` settings array.
	 * @return array{audio: string, mime: string}|WP_Error
	 */
	public function synthesize( string $text, array $ai_agent ) {
		$text = trim( wp_strip_all_tags( $text ) );

		if ( '' === $text ) {
			return new WP_Error( 'ace_tts_empty', __( 'There was no text to speak.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$text     = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_CHARS ) : substr( $text, 0, self::MAX_CHARS );
		$provider = ChatClientFactory::effective_voice_provider( $ai_agent );

		if ( 'openai' === $provider ) {
			return $this->synthesize_openai( $text, $ai_agent );
		}

		if ( 'elevenlabs' === $provider ) {
			return $this->synthesize_elevenlabs( $text, $ai_agent );
		}

		return new WP_Error( 'ace_tts_unconfigured', __( 'No premium voice provider is configured.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
	}

	/**
	 * Synthesise via the OpenAI speech endpoint.
	 *
	 * @param string               $text     Text to speak.
	 * @param array<string, mixed> $ai_agent Settings.
	 * @return array{audio: string, mime: string}|WP_Error
	 */
	private function synthesize_openai( string $text, array $ai_agent ) {
		$api_key = sanitize_text_field( (string) ( $ai_agent['voice_openai_api_key'] ?? '' ) );

		if ( '' === $api_key ) {
			$api_key = sanitize_text_field( (string) ( $ai_agent['openai_api_key'] ?? '' ) );
		}

		if ( '' === $api_key ) {
			return new WP_Error( 'ace_tts_key_missing', __( 'Add an OpenAI API key for voice playback.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$model = sanitize_text_field( (string) ( $ai_agent['voice_openai_model'] ?? 'gpt-4o-mini-tts' ) );
		$voice = sanitize_text_field( (string) ( $ai_agent['voice_openai_voice'] ?? 'alloy' ) );

		$response = wp_remote_post(
			'https://api.openai.com/v1/audio/speech',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => '' !== $model ? $model : 'gpt-4o-mini-tts',
						'input'           => $text,
						'voice'           => '' !== $voice ? $voice : 'alloy',
						'response_format' => 'mp3',
					)
				),
			)
		);

		return $this->handle_audio_response( $response );
	}

	/**
	 * Synthesise via the ElevenLabs text-to-speech endpoint.
	 *
	 * @param string               $text     Text to speak.
	 * @param array<string, mixed> $ai_agent Settings.
	 * @return array{audio: string, mime: string}|WP_Error
	 */
	private function synthesize_elevenlabs( string $text, array $ai_agent ) {
		$api_key  = sanitize_text_field( (string) ( $ai_agent['voice_elevenlabs_api_key'] ?? '' ) );
		$voice_id = sanitize_text_field( (string) ( $ai_agent['voice_elevenlabs_voice_id'] ?? '' ) );

		if ( '' === $api_key || '' === $voice_id ) {
			return new WP_Error( 'ace_tts_key_missing', __( 'Add an ElevenLabs API key and voice ID for voice playback.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$model = sanitize_text_field( (string) ( $ai_agent['voice_elevenlabs_model'] ?? 'eleven_turbo_v2_5' ) );

		$response = wp_remote_post(
			'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode( $voice_id ),
			array(
				'timeout' => 30,
				'headers' => array(
					'xi-api-key'   => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'audio/mpeg',
				),
				'body'    => wp_json_encode(
					array(
						'text'     => $text,
						'model_id' => '' !== $model ? $model : 'eleven_turbo_v2_5',
					)
				),
			)
		);

		return $this->handle_audio_response( $response );
	}

	/**
	 * Normalise an audio HTTP response into a base64 payload or an error.
	 *
	 * @param array<string, mixed>|WP_Error $response HTTP response.
	 * @return array{audio: string, mime: string}|WP_Error
	 */
	private function handle_audio_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ace_tts_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			$message = __( 'The voice provider returned an error.', 'adaptive-customer-engagement' );
			$payload = json_decode( (string) $body, true );

			if ( is_array( $payload ) ) {
				if ( ! empty( $payload['error']['message'] ) ) {
					$message = sanitize_text_field( (string) $payload['error']['message'] );
				} elseif ( ! empty( $payload['detail']['message'] ) ) {
					$message = sanitize_text_field( (string) $payload['detail']['message'] );
				}
			}

			return new WP_Error( 'ace_tts_bad_response', $message, array( 'status' => 502 ) );
		}

		if ( '' === (string) $body ) {
			return new WP_Error( 'ace_tts_empty_response', __( 'The voice provider did not return any audio.', 'adaptive-customer-engagement' ), array( 'status' => 502 ) );
		}

		return array(
			'audio' => base64_encode( (string) $body ),
			'mime'  => 'audio/mpeg',
		);
	}
}
