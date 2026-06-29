<?php
/**
 * Premium speech-to-text (transcription) via OpenAI or ElevenLabs.
 *
 * Lets the frontend microphone work in any browser (including Firefox, which has
 * no Web Speech API) by recording audio client-side and transcribing it
 * server-side, so the provider key never reaches the browser.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SpeechToTextService {
	/**
	 * Maximum decoded audio size accepted (bytes).
	 */
	private const MAX_BYTES = 24000000;

	/**
	 * Allowed inbound audio MIME types mapped to a sensible file extension.
	 */
	private const ALLOWED_MIME = array(
		'audio/webm'  => 'webm',
		'audio/ogg'   => 'ogg',
		'audio/mp4'   => 'mp4',
		'audio/mpeg'  => 'mp3',
		'audio/mpga'  => 'mp3',
		'audio/wav'   => 'wav',
		'audio/x-wav' => 'wav',
		'audio/webm;codecs=opus' => 'webm',
		'audio/ogg;codecs=opus'  => 'ogg',
	);

	/**
	 * Whether a premium transcription provider is configured.
	 *
	 * @param array<string, mixed> $ai_agent The `ai_agent` settings array.
	 * @return bool
	 */
	public function is_configured( array $ai_agent ): bool {
		switch ( sanitize_key( (string) ( $ai_agent['frontend_voice_provider'] ?? 'browser' ) ) ) {
			case 'openai':
				return '' !== trim( (string) ( $ai_agent['voice_openai_api_key'] ?? '' ) )
					|| '' !== trim( (string) ( $ai_agent['openai_api_key'] ?? '' ) );
			case 'elevenlabs':
				return '' !== trim( (string) ( $ai_agent['voice_elevenlabs_api_key'] ?? '' ) );
			default:
				return false;
		}
	}

	/**
	 * Transcribe a base64-encoded audio clip.
	 *
	 * @param string               $audio_base64 Base64 audio payload.
	 * @param string               $mime         Reported MIME type.
	 * @param array<string, mixed> $ai_agent     The `ai_agent` settings array.
	 * @return array{text: string}|WP_Error
	 */
	public function transcribe( string $audio_base64, string $mime, array $ai_agent ) {
		$audio_base64 = preg_replace( '#^data:[^,]*,#', '', trim( $audio_base64 ) );
		$binary       = base64_decode( (string) $audio_base64, true );

		if ( false === $binary || '' === $binary ) {
			return new WP_Error( 'ace_stt_invalid_audio', __( 'The recording could not be read.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		if ( strlen( $binary ) > self::MAX_BYTES ) {
			return new WP_Error( 'ace_stt_too_large', __( 'The recording is too long. Please keep voice messages short.', 'adaptive-customer-engagement' ), array( 'status' => 413 ) );
		}

		$mime      = strtolower( trim( (string) $mime ) );
		$base_mime = trim( explode( ';', $mime )[0] );
		$extension = self::ALLOWED_MIME[ $mime ] ?? self::ALLOWED_MIME[ $base_mime ] ?? '';

		if ( '' === $extension ) {
			$extension = 'webm';
			$base_mime = 'audio/webm';
		}

		$provider = sanitize_key( (string) ( $ai_agent['frontend_voice_provider'] ?? 'browser' ) );

		if ( 'openai' === $provider ) {
			return $this->transcribe_openai( $binary, $base_mime, $extension, $ai_agent );
		}

		if ( 'elevenlabs' === $provider ) {
			return $this->transcribe_elevenlabs( $binary, $base_mime, $extension, $ai_agent );
		}

		return new WP_Error( 'ace_stt_unconfigured', __( 'No transcription provider is configured.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
	}

	/**
	 * Transcribe via the OpenAI audio transcription endpoint.
	 *
	 * @param string               $binary    Raw audio bytes.
	 * @param string               $mime      Base MIME type.
	 * @param string               $extension File extension.
	 * @param array<string, mixed> $ai_agent  Settings.
	 * @return array{text: string}|WP_Error
	 */
	private function transcribe_openai( string $binary, string $mime, string $extension, array $ai_agent ) {
		$api_key = sanitize_text_field( (string) ( $ai_agent['voice_openai_api_key'] ?? '' ) );

		if ( '' === $api_key ) {
			$api_key = sanitize_text_field( (string) ( $ai_agent['openai_api_key'] ?? '' ) );
		}

		if ( '' === $api_key ) {
			return new WP_Error( 'ace_stt_key_missing', __( 'Add an OpenAI API key for voice input.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$model = sanitize_text_field( (string) ( $ai_agent['voice_openai_transcribe_model'] ?? 'gpt-4o-mini-transcribe' ) );

		$response = $this->post_multipart(
			'https://api.openai.com/v1/audio/transcriptions',
			array( 'Authorization' => 'Bearer ' . $api_key ),
			array(
				'model'           => '' !== $model ? $model : 'gpt-4o-mini-transcribe',
				'response_format' => 'json',
			),
			$binary,
			$mime,
			'audio.' . $extension
		);

		return $this->handle_text_response( $response );
	}

	/**
	 * Transcribe via the ElevenLabs speech-to-text endpoint.
	 *
	 * @param string               $binary    Raw audio bytes.
	 * @param string               $mime      Base MIME type.
	 * @param string               $extension File extension.
	 * @param array<string, mixed> $ai_agent  Settings.
	 * @return array{text: string}|WP_Error
	 */
	private function transcribe_elevenlabs( string $binary, string $mime, string $extension, array $ai_agent ) {
		$api_key = sanitize_text_field( (string) ( $ai_agent['voice_elevenlabs_api_key'] ?? '' ) );

		if ( '' === $api_key ) {
			return new WP_Error( 'ace_stt_key_missing', __( 'Add an ElevenLabs API key for voice input.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$model = sanitize_text_field( (string) ( $ai_agent['voice_elevenlabs_transcribe_model'] ?? 'scribe_v1' ) );

		$response = $this->post_multipart(
			'https://api.elevenlabs.io/v1/speech-to-text',
			array( 'xi-api-key' => $api_key ),
			array( 'model_id' => '' !== $model ? $model : 'scribe_v1' ),
			$binary,
			$mime,
			'audio.' . $extension
		);

		return $this->handle_text_response( $response );
	}

	/**
	 * POST a multipart/form-data request with a single file part via the WP HTTP API.
	 *
	 * @param string                $url       Endpoint.
	 * @param array<string, string> $headers   Auth headers.
	 * @param array<string, string> $fields    Text fields.
	 * @param string                $file_bin  File bytes.
	 * @param string                $file_mime File MIME type.
	 * @param string                $file_name File name.
	 * @return array<string, mixed>|WP_Error
	 */
	private function post_multipart( string $url, array $headers, array $fields, string $file_bin, string $file_mime, string $file_name ) {
		$boundary = 'ace' . wp_generate_password( 24, false );
		$eol      = "\r\n";
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= $value . $eol;
		}

		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $file_name . '"' . $eol;
		$body .= 'Content-Type: ' . $file_mime . $eol . $eol;
		$body .= $file_bin . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

		return wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => $headers,
				'body'    => $body,
			)
		);
	}

	/**
	 * Normalise a transcription HTTP response into text or an error.
	 *
	 * @param array<string, mixed>|WP_Error $response HTTP response.
	 * @return array{text: string}|WP_Error
	 */
	private function handle_text_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ace_stt_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = (string) wp_remote_retrieve_body( $response );
		$payload = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = __( 'The transcription provider returned an error.', 'adaptive-customer-engagement' );

			if ( is_array( $payload ) ) {
				if ( ! empty( $payload['error']['message'] ) ) {
					$message = sanitize_text_field( (string) $payload['error']['message'] );
				} elseif ( ! empty( $payload['detail']['message'] ) ) {
					$message = sanitize_text_field( (string) $payload['detail']['message'] );
				}
			}

			return new WP_Error( 'ace_stt_bad_response', $message, array( 'status' => 502 ) );
		}

		$text = is_array( $payload ) ? (string) ( $payload['text'] ?? '' ) : '';

		return array( 'text' => sanitize_textarea_field( $text ) );
	}
}
