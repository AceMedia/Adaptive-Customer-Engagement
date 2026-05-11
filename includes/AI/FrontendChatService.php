<?php
/**
 * Frontend OpenAI chat orchestration.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\ChatConversationRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\ChatMessageRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
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
	 * Session repository.
	 *
	 * @var SessionRepository
	 */
	private $sessions;

	/**
	 * Chat conversation repository.
	 *
	 * @var ChatConversationRepository
	 */
	private $chat_conversations;

	/**
	 * Chat message repository.
	 *
	 * @var ChatMessageRepository
	 */
	private $chat_messages;

	/**
	 * Constructor.
	 *
	 * @param OpenAIClient             $openai             OpenAI client.
	 * @param SiteContextService       $site_context       Site context helper.
	 * @param SessionRepository        $sessions           Session repository.
	 * @param ChatConversationRepository $chat_conversations Chat conversation repository.
	 * @param ChatMessageRepository    $chat_messages      Chat message repository.
	 */
	public function __construct( OpenAIClient $openai, SiteContextService $site_context, SessionRepository $sessions, ChatConversationRepository $chat_conversations, ChatMessageRepository $chat_messages ) {
		$this->openai             = $openai;
		$this->site_context       = $site_context;
		$this->sessions           = $sessions;
		$this->chat_conversations = $chat_conversations;
		$this->chat_messages      = $chat_messages;
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
		$model    = sanitize_text_field( (string) ( $ai_agent['openai_model'] ?? 'gpt-4.1-mini' ) );

		if ( '' === $message ) {
			return new WP_Error( 'ace_ai_message_required', __( 'Please enter a message before sending it to the assistant.', 'adaptive-customer-engagement' ), array( 'status' => 400 ) );
		}

		$site       = $this->site_context->get_site_identity();
		$sources    = array();
		$context    = array();
		$limit      = max( 1, min( 8, absint( $ai_agent['max_context_documents'] ?? 4 ) ) );
		$page_url   = esc_url_raw( (string) ( $payload['page_url'] ?? '' ) );
		$page_title = sanitize_text_field( (string) ( $payload['page_title'] ?? '' ) );
		$session    = ! empty( $payload['session_uuid'] ) ? $this->sessions->find_by_uuid( sanitize_text_field( (string) $payload['session_uuid'] ) ) : null;
		$thread     = $this->chat_conversations->create_or_touch(
			sanitize_text_field( (string) ( $payload['conversation_uuid'] ?? $payload['session_uuid'] ?? wp_generate_uuid4() ) ),
			array(
				'session_id'   => (int) ( $session['id'] ?? 0 ),
				'session_uuid' => sanitize_text_field( (string) ( $payload['session_uuid'] ?? '' ) ),
				'visitor_uuid' => sanitize_text_field( (string) ( $payload['visitor_uuid'] ?? '' ) ),
				'company_id'   => (int) ( $session['company_id'] ?? 0 ),
				'page_url'     => $page_url,
				'page_title'   => $page_title,
				'provider'     => 'openai',
				'model'        => $model,
			)
		);

		if ( empty( $thread['id'] ) ) {
			return new WP_Error( 'ace_ai_chat_unavailable', __( 'The chat conversation could not be created just now.', 'adaptive-customer-engagement' ), array( 'status' => 500 ) );
		}

		if ( ChatConversationRepository::STATUS_ENDED === ( $thread['status'] ?? '' ) ) {
			return new WP_Error( 'ace_ai_chat_ended', __( 'This chat session has already ended. Please start a new conversation.', 'adaptive-customer-engagement' ), array( 'status' => 409 ) );
		}

		$this->store_message(
			(int) $thread['id'],
			(int) ( $session['id'] ?? 0 ),
			(int) ( $session['company_id'] ?? 0 ),
			'user',
			$message,
			array(),
			$model,
			false
		);

		if ( ! empty( $thread['handover_enabled'] ) || ChatConversationRepository::STATUS_HANDOVER === ( $thread['status'] ?? '' ) ) {
			$thread = $this->chat_conversations->find( (int) $thread['id'] ) ?: $thread;

			return array(
				'message'      => '',
				'sources'      => array(),
				'model'        => '',
				'conversation' => $this->normalise_conversation( $thread ),
				'messages'     => $this->normalise_conversation_messages( (int) $thread['id'] ),
			);
		}

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
			array(
				'role'    => 'system',
				'content' => 'When the provided live site context includes explicit product facts such as capacities, sizes, weights, attributes, rankings, or product names, answer from those facts directly. Do not say the website does not specify those details if they are present in the supplied context.',
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
				'model'               => $model,
				'temperature'         => $ai_agent['openai_temperature'] ?? 0.2,
				'max_response_tokens' => $ai_agent['openai_max_response_tokens'] ?? 700,
			)
		);

		if ( is_wp_error( $response ) ) {
			$response = $this->normalise_provider_error( $response );

			if ( ! empty( $thread['id'] ) ) {
				$this->store_message(
					(int) $thread['id'],
					(int) ( $session['id'] ?? 0 ),
					(int) ( $session['company_id'] ?? 0 ),
					'assistant',
					$response->get_error_message(),
					array(),
					$model,
					true
				);
			}

			return $response;
		}

		$normalised_sources = $this->normalise_sources( $sources );

		$this->store_message(
			(int) $thread['id'],
			(int) ( $session['id'] ?? 0 ),
			(int) ( $session['company_id'] ?? 0 ),
			'assistant',
			sanitize_textarea_field( (string) ( $response['message'] ?? '' ) ),
			$normalised_sources,
			sanitize_text_field( (string) ( $response['model'] ?? $model ) ),
			false
		);

		$thread = $this->chat_conversations->find( (int) $thread['id'] ) ?: $thread;

		return array(
			'message'      => sanitize_textarea_field( (string) ( $response['message'] ?? '' ) ),
			'sources'      => ! empty( $ai_agent['show_source_links'] ) ? $normalised_sources : array(),
			'model'        => sanitize_text_field( (string) ( $response['model'] ?? '' ) ),
			'conversation' => $this->normalise_conversation( $thread ),
			'messages'     => $this->normalise_conversation_messages( (int) $thread['id'] ),
		);
	}

	/**
	 * Get a frontend-safe snapshot of a conversation.
	 *
	 * @param array<string, mixed> $payload Request payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_conversation_snapshot( array $payload ) {
		$conversation = $this->find_accessible_conversation( $payload );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_ai_chat_not_found', __( 'The chat conversation could not be found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		return array(
			'conversation' => $this->normalise_conversation( $conversation ),
			'messages'     => $this->normalise_conversation_messages( (int) $conversation['id'] ),
		);
	}

	/**
	 * End a frontend chat conversation.
	 *
	 * @param array<string, mixed> $payload Request payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function end_conversation( array $payload ) {
		$conversation = $this->find_accessible_conversation( $payload );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_ai_chat_not_found', __( 'The chat conversation could not be found.', 'adaptive-customer-engagement' ), array( 'status' => 404 ) );
		}

		$conversation = $this->chat_conversations->end_conversation( (int) $conversation['id'], 'visitor' );

		if ( ! $conversation ) {
			return new WP_Error( 'ace_ai_chat_end_failed', __( 'The chat conversation could not be ended.', 'adaptive-customer-engagement' ), array( 'status' => 500 ) );
		}

		return array(
			'conversation' => $this->normalise_conversation( $conversation ),
			'messages'     => $this->normalise_conversation_messages( (int) $conversation['id'] ),
		);
	}

	/**
	 * Replace raw upstream provider failures with a visitor-safe message.
	 *
	 * @param WP_Error $error Provider error.
	 * @return WP_Error
	 */
	private function normalise_provider_error( WP_Error $error ): WP_Error {
		$provider_codes = array(
			'ace_openai_api_key_missing',
			'ace_openai_model_missing',
			'ace_openai_messages_missing',
			'ace_openai_request_failed',
			'ace_openai_bad_response',
			'ace_openai_empty_response',
		);

		if ( ! in_array( $error->get_error_code(), $provider_codes, true ) ) {
			return $error;
		}

		return new WP_Error(
			'ace_ai_chat_temporarily_unavailable',
			__( 'The site assistant is currently offline for maintenance. Please try again shortly.', 'adaptive-customer-engagement' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Find a conversation the current visitor is allowed to access.
	 *
	 * @param array<string, mixed> $payload Request payload.
	 * @return array<string, mixed>|null
	 */
	private function find_accessible_conversation( array $payload ): ?array {
		$conversation_uuid = sanitize_text_field( (string) ( $payload['conversation_uuid'] ?? '' ) );
		$visitor_uuid      = sanitize_text_field( (string) ( $payload['visitor_uuid'] ?? '' ) );
		$session_uuid      = sanitize_text_field( (string) ( $payload['session_uuid'] ?? '' ) );

		if ( '' === $conversation_uuid ) {
			return null;
		}

		$conversation = $this->chat_conversations->find_by_uuid( $conversation_uuid );

		if ( ! $conversation ) {
			return null;
		}

		$matches_visitor = '' !== $visitor_uuid && $visitor_uuid === sanitize_text_field( (string) ( $conversation['visitor_uuid'] ?? '' ) );
		$matches_session = '' !== $session_uuid && $session_uuid === sanitize_text_field( (string) ( $conversation['session_uuid'] ?? '' ) );

		if ( ! $matches_visitor && ! $matches_session ) {
			return null;
		}

		return $this->chat_conversations->find( (int) $conversation['id'] );
	}

	/**
	 * Build a frontend-safe conversation snapshot.
	 *
	 * @param array<string, mixed> $conversation Raw conversation row.
	 * @return array<string, mixed>
	 */
	private function normalise_conversation( array $conversation ): array {
		return array(
			'id'               => (int) ( $conversation['id'] ?? 0 ),
			'conversation_uuid'=> sanitize_text_field( (string) ( $conversation['conversation_uuid'] ?? '' ) ),
			'status'           => sanitize_key( (string) ( $conversation['status'] ?? ChatConversationRepository::STATUS_OPEN ) ),
			'handover_enabled' => ! empty( $conversation['handover_enabled'] ),
			'ended_at'         => sanitize_text_field( (string) ( $conversation['ended_at'] ?? '' ) ),
			'ended_by'         => sanitize_key( (string) ( $conversation['ended_by'] ?? '' ) ),
			'last_message_at'  => sanitize_text_field( (string) ( $conversation['last_message_at'] ?? '' ) ),
			'message_count'    => absint( $conversation['message_count'] ?? 0 ),
		);
	}

	/**
	 * Build frontend-safe stored messages for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalise_conversation_messages( int $conversation_id ): array {
		return array_values(
			array_map(
				function ( array $message ): array {
					return array(
						'id'      => (int) ( $message['id'] ?? 0 ),
						'role'    => sanitize_key( (string) ( $message['message_role'] ?? '' ) ),
						'content' => sanitize_textarea_field( (string) ( $message['message_text'] ?? '' ) ),
						'sources' => $this->normalise_sources( is_array( $message['sources'] ?? null ) ? $message['sources'] : array() ),
						'created_at' => sanitize_text_field( (string) ( $message['created_at'] ?? '' ) ),
						'is_error' => ! empty( $message['is_error'] ),
					);
				},
				$this->chat_messages->get_by_conversation( $conversation_id )
			)
		);
	}

	/**
	 * Store a chat message and refresh conversation totals.
	 *
	 * @param int                             $conversation_id Conversation ID.
	 * @param int                             $session_id      Session ID.
	 * @param int                             $company_id      Company ID.
	 * @param string                          $role            Message role.
	 * @param string                          $text            Message text.
	 * @param array<int, array<string,string>> $sources        Sources.
	 * @param string                          $model           Model name.
	 * @param bool                            $is_error        Whether this is an error message.
	 * @return void
	 */
	private function store_message( int $conversation_id, int $session_id, int $company_id, string $role, string $text, array $sources, string $model, bool $is_error ): void {
		$this->chat_messages->create(
			array(
				'conversation_id' => $conversation_id,
				'session_id'      => $session_id,
				'company_id'      => $company_id,
				'message_role'    => $role,
				'message_text'    => $text,
				'sources'         => $sources,
				'model'           => $model,
				'is_error'        => $is_error,
			)
		);

		$this->chat_conversations->record_message( $conversation_id, $role, $model );
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
						'title'       => sanitize_text_field( (string) ( $source['title'] ?? '' ) ),
						'url'         => esc_url_raw( (string) ( $source['url'] ?? '' ) ),
						'label'       => sanitize_text_field( (string) ( $source['source_label'] ?? $source['source_type'] ?? '' ) ),
						'content_type' => sanitize_text_field( (string) ( $source['content_type'] ?? '' ) ),
						'summary'     => sanitize_textarea_field( (string) ( $source['summary'] ?? '' ) ),
						'image_url'   => esc_url_raw( (string) ( $source['image_url'] ?? '' ) ),
						'source_type' => sanitize_key( (string) ( $source['source_type'] ?? '' ) ),
						'commerce'    => array(
							'price'           => sanitize_text_field( (string) ( $source['commerce']['price'] ?? '' ) ),
							'variation_count' => absint( $source['commerce']['variation_count'] ?? 0 ),
							'can_add_to_cart' => ! empty( $source['commerce']['can_add_to_cart'] ),
							'add_to_cart_url' => esc_url_raw( (string) ( $source['commerce']['add_to_cart_url'] ?? '' ) ),
							'view_url'        => esc_url_raw( (string) ( $source['commerce']['view_url'] ?? $source['url'] ?? '' ) ),
						),
					);
				},
				array_slice( $sources, 0, 5 )
			)
		);
	}
}
