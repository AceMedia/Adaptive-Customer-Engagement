<?php
namespace AceMedia\SharedAI\V1;
defined( 'ABSPATH' ) || exit;
/** Bounded SSE decoding: partial text is never reported as a successful completion. */
final class Responses {
	public static function parse( string $body ) {
		$text = ''; $complete = false; $usage = array();
		foreach ( preg_split( '/\r?\n\r?\n/', $body ) as $frame ) {
			$data = array();
			foreach ( preg_split( '/\r?\n/', $frame ) as $line ) { if ( 0 === strpos( $line, 'data:' ) ) { $data[] = ltrim( substr( $line, 5 ), ' ' ); } }
			if ( ! $data ) { continue; }
			$json = implode( "\n", $data );
			if ( '[DONE]' === $json ) { continue; }
			$event = json_decode( $json, true );
			if ( ! is_array( $event ) ) { return new \WP_Error( 'ace_ai_stream_invalid', 'The ChatGPT response stream was invalid.' ); }
			$type = $event['type'] ?? '';
			if ( in_array( $type, array( 'response.failed', 'response.incomplete', 'error' ), true ) ) {
				return new \WP_Error( 'ace_ai_response_failed', 'ChatGPT could not complete the request. Check account access and usage limits before trying again.' );
			}
			if ( 'response.output_text.delta' === $type ) { $text .= is_string( $event['delta'] ?? null ) ? $event['delta'] : ''; }
			if ( 'response.completed' === $type ) { $complete = true; $usage = $event['response']['usage'] ?? array(); }
		}
		return $complete ? array( 'message' => $text, 'usage' => is_array( $usage ) ? $usage : array() ) : new \WP_Error( 'ace_ai_stream_incomplete', 'The ChatGPT response ended before completion. No partial answer was accepted.' );
	}
	public static function request( string $token, string $model, array $messages ) {
		if ( '' === $model ) { return new \WP_Error( 'ace_ai_model_required', 'Choose a model from this ChatGPT account first.' ); }
		$response = wp_remote_post( 'https://api.openai.com/v1/responses', array(
			'timeout' => 60, 'redirection' => 0, 'limit_response_size' => 2097152,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'text/event-stream' ),
			'body' => wp_json_encode( array( 'model' => $model, 'input' => $messages, 'store' => false, 'stream' => true ) ),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new \WP_Error( 'ace_ai_subscription_request_failed', 'The ChatGPT request failed. No alternative account or API key was used.' ); }
		return self::parse( wp_remote_retrieve_body( $response ) );
	}
}
