<?php
/**
 * Frontend OpenAI chat orchestration.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use ACE\AdaptiveCustomerEngagement\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class FrontendChatService {
	/**
	 * OpenAI client.
	 *
	 * @var OpenAIClient
	 */
	private $openai;

	/**
	 * Site context helper.
	 *
	 * @var SiteContextService
	 */
	private $site_context;

	/**
	 * Constructor.
	 *
	 * @param OpenAIClient      $openai       OpenAI client.
	 * @param SiteContextService $site_context Site context helper.
	 */
	public function __construct( OpenAIClient $openai, SiteContextService $site_context ) {
		$this->openai       = $openai;
		$this->site_context = $site_context;
	}

	/**
	 * Build a frontend reply.
	 *
	 * @param array<string, mixed> $payload Request payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function respond( array $payload ) {
		$settings = Settings::get();
		$ai_agent = is_array( $settings['ai_agent'] ?? null ) ? $settings['ai_agent'] : array();
		$message  = sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) );

		if ( '' === $message ) {
			return new WP_Error( 'ace_ai_message_required', __( 'Please enter a message before sending it to the assistant.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$site     = $this->site_context->get_site_identity();
		$sources  = array();
		$context  = array();
		$limit    = max( 1, min( 8, absint( $ai_agent['max_context_documents'] ?? 4 ) ) );
		$page_url = esc_url_raw( (string) ( $payload['page_url'] ?? '' ) );
		$page_title = sanitize_text_field( (string) ( $payload['page_title'] ?? '' ) );

		$context[] = sprintf(
			"Site name: %s\nSite description: %s\nSite URL: %s\nSite language: %s",
			sanitize_text_field( (string) ( $site['name'] ?? '' ) ),
			sanitize_text_field( (string) ( $site['description'] ?? '' ) ),
			esc_url_raw( (string) ( $site['url'] ?? '' ) ),
			sanitize_text_field( (string) ( $site['language'] ?? '' ) )
		);

		if ( '' !== $page_title || '' !== $page_url ) {
			$context[] = sprintf(
				"Current page title: %s\nCurrent page URL: %s",
				$page_title ?: __( 'Unknown page', 'adaptive-customer-engagement' ),
				$page_url ?: __( 'Unavailable', 'adaptive-customer-engagement' )
			);
		}

		if ( ! empty( $ai_agent['use_live_site_context'] ) ) {
			$answer  = $this->site_context->answer_question( $message, $limit );
			$sources = is_array( $answer['sources'] ?? null ) ? $answer['sources'] : array();

			if ( ! empty( $answer['answer'] ) ) {
				$context[] = "Live site context summary:\n" . sanitize_textarea_field( (string) $answer['answer'] );
			}

			if ( ! empty( $sources ) ) {
				$context[] = "Relevant source documents:\n" . $this->format_sources( $sources );
			}
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => sanitize_textarea_field( (string) ( $ai_agent['system_prompt'] ?? '' ) ),
			),
			array(
				'role'    => 'system',
				'content' => implode( "\n\n", array_filter( $context ) ),
			),
		);

		$context_instructions = sanitize_textarea_field( (string) ( $ai_agent['context_instructions'] ?? '' ) );

		if ( '' !== $context_instructions ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $context_instructions,
			);
		}

		if ( ! empty( $ai_agent['keep_history'] ) ) {
			$messages = array_merge( $messages, $this->sanitize_history( $payload['history'] ?? array(), (int) ( $ai_agent['max_history_messages'] ?? 8 ) ) );
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		$response = $this->openai->create_chat_completion(
			$messages,
			array(
				'api_key'             => $ai_agent['openai_api_key'] ?? '',
				'model'               => $ai_agent['openai_model'] ?? 'gpt-4.1-mini',
				'temperature'         => $ai_agent['openai_temperature'] ?? 0.2,
				'max_response_tokens' => $ai_agent['openai_max_response_tokens'] ?? 700,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'message' => sanitize_textarea_field( (string) ( $response['message'] ?? '' ) ),
			'sources' => ! empty( $ai_agent['show_source_links'] ) ? $this->normalise_sources( $sources ) : array(),
			'model'   => sanitize_text_field( (string) ( $response['model'] ?? '' ) ),
		);
	}

	/**
	 * Sanitize prior conversation history.
	 *
	 * @param mixed $history Raw history payload.
	 * @param int   $limit   Maximum number of messages.
	 * @return array<int, array<string, string>>
	 */
	private function sanitize_history( $history, int $limit ): array {
		if ( ! is_array( $history ) ) {
			return array();
		}

		$limit     = max( 1, min( 12, $limit ) );
		$sanitised = array();

		foreach ( array_slice( $history, -1 * $limit ) as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = sanitize_key( (string) ( $message['role'] ?? '' ) );
			$content = sanitize_textarea_field( (string) ( $message['content'] ?? '' ) );

			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) || '' === $content ) {
				continue;
			}

			$sanitised[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $sanitised;
	}

	/**
	 * Format sources into a compact prompt block.
	 *
	 * @param array<int, array<string, mixed>> $sources Source documents.
	 * @return string
	 */
	private function format_sources( array $sources ): string {
		$lines = array();

		foreach ( array_slice( $sources, 0, 8 ) as $source ) {
			$lines[] = sprintf(
				"- %s (%s)\n  URL: %s\n  Summary: %s",
				sanitize_text_field( (string) ( $source['title'] ?? '' ) ),
				sanitize_text_field( (string) ( $source['source_label'] ?? $source['source_type'] ?? '' ) ),
				esc_url_raw( (string) ( $source['url'] ?? '' ) ),
				sanitize_textarea_field( (string) ( $source['summary'] ?? '' ) )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Normalise sources for the frontend response.
	 *
	 * @param array<int, array<string, mixed>> $sources Raw sources.
	 * @return array<int, array<string, string>>
	 */
	private function normalise_sources( array $sources ): array {
		return array_values(
			array_map(
				static function ( array $source ): array {
					return array(
						'title' => sanitize_text_field( (string) ( $source['title'] ?? '' ) ),
						'url'   => esc_url_raw( (string) ( $source['url'] ?? '' ) ),
						'label' => sanitize_text_field( (string) ( $source['source_label'] ?? $source['source_type'] ?? '' ) ),
					);
				},
				array_slice( $sources, 0, 5 )
			)
		);
	}
}
