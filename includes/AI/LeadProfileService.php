<?php
/**
 * Lead capture and local organisation memory helpers for chat.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\ChatConversationRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\IpCompanyMemoryRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;

defined( 'ABSPATH' ) || exit;

final class LeadProfileService {
	/**
	 * Session repository.
	 *
	 * @var SessionRepository
	 */
	private $sessions;

	/**
	 * Company repository.
	 *
	 * @var CompanyRepository
	 */
	private $companies;

	/**
	 * Chat conversations repository.
	 *
	 * @var ChatConversationRepository
	 */
	private $conversations;

	/**
	 * IP memory repository.
	 *
	 * @var IpCompanyMemoryRepository
	 */
	private $ip_memory;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository         $sessions      Session repository.
	 * @param CompanyRepository         $companies     Company repository.
	 * @param ChatConversationRepository $conversations Conversation repository.
	 * @param IpCompanyMemoryRepository $ip_memory     IP memory repository.
	 */
	public function __construct( SessionRepository $sessions, CompanyRepository $companies, ChatConversationRepository $conversations, IpCompanyMemoryRepository $ip_memory ) {
		$this->sessions      = $sessions;
		$this->companies     = $companies;
		$this->conversations = $conversations;
		$this->ip_memory     = $ip_memory;
	}

	/**
	 * Apply any saved IP-based organisation hint to the current session.
	 *
	 * @param array<string, mixed>|null $session Current session.
	 * @return array{session:array<string, mixed>|null,memory:array<string, mixed>|null}
	 */
	public function apply_known_context( ?array $session ): array {
		if ( ! is_array( $session ) || empty( $session['ip_hash'] ) ) {
			return array(
				'session' => $session,
				'memory'  => null,
			);
		}

		$memory = $this->ip_memory->find_by_ip_hash( sanitize_text_field( (string) $session['ip_hash'] ) );

		if ( ! $memory ) {
			return array(
				'session' => $session,
				'memory'  => null,
			);
		}

		if ( empty( $session['company_id'] ) ) {
			$company = ! empty( $memory['company_id'] ) ? $this->companies->find( (int) $memory['company_id'] ) : null;

			if ( ! $company && ! empty( $memory['company_name'] ) ) {
				$company = $this->companies->create_or_touch_local(
					array(
						'name'       => (string) $memory['company_name'],
						'domain'     => (string) ( $memory['company_domain'] ?? '' ),
						'confidence' => (string) ( $memory['confidence'] ?? 'likely' ),
						'source'     => 'ip_chat_memory',
					)
				);
			}

			if ( is_array( $company ) && ! empty( $company['id'] ) ) {
				$this->sessions->assign_company(
					(int) $session['id'],
					(int) $company['id'],
					(string) ( $memory['confidence'] ?? 'likely' )
				);
				$session = $this->sessions->get_session_detail( (int) $session['id'] ) ?: $session;
			}
		}

		return array(
			'session' => $session,
			'memory'  => $memory,
		);
	}

	/**
	 * Capture lead signals from a visitor message.
	 *
	 * @param array<string, mixed>      $conversation Conversation row.
	 * @param array<string, mixed>|null $session      Session row.
	 * @param string                    $message      Visitor message.
	 * @return array{conversation:array<string, mixed>,session:array<string, mixed>|null,memory:array<string, mixed>|null,captured:array<string, mixed>}
	 */
	public function capture_from_message( array $conversation, ?array $session, string $message ): array {
		return $this->persist_capture(
			$conversation,
			$session,
			array_merge(
				$this->extract_from_text( $message ),
				array(
					'lead_summary' => $this->build_lead_summary( $conversation, $message ),
				)
			)
		);
	}

	/**
	 * Capture lead signals from an explicit contact payload.
	 *
	 * @param array<string, mixed>      $conversation Conversation row.
	 * @param array<string, mixed>|null $session      Session row.
	 * @param array<string, mixed>      $payload      Contact payload.
	 * @return array{conversation:array<string, mixed>,session:array<string, mixed>|null,memory:array<string, mixed>|null,captured:array<string, mixed>}
	 */
	public function capture_from_contact( array $conversation, ?array $session, array $payload ): array {
		$captured = array(
			'contact_name'    => $this->clean_person_name( (string) ( $payload['contact_name'] ?? '' ) ),
			'contact_email'   => $this->normalise_email( (string) ( $payload['contact_email'] ?? '' ) ),
			'contact_phone'   => $this->normalise_phone_number( (string) ( $payload['contact_phone'] ?? '' ) ),
			'contact_company' => sanitize_text_field( (string) ( $payload['contact_company'] ?? '' ) ),
			'contact_role'    => sanitize_text_field( (string) ( $payload['contact_role'] ?? '' ) ),
			'lead_summary'    => sanitize_textarea_field( (string) ( $payload['lead_summary'] ?? '' ) ),
		);

		if ( '' === $captured['lead_summary'] ) {
			$captured['lead_summary'] = $this->build_contact_summary( $captured );
		}

		return $this->persist_capture( $conversation, $session, $captured );
	}

	/**
	 * Build prompt-safe organisation context for the assistant.
	 *
	 * @param array<string, mixed>|null $session      Session row.
	 * @param array<string, mixed>      $conversation Conversation row.
	 * @param array<string, mixed>|null $memory       Local IP memory row.
	 * @return string
	 */
	public function build_prompt_context( ?array $session, array $conversation, ?array $memory ): string {
		$lines = array();

		if ( ! empty( $conversation['contact_company'] ) || ! empty( $conversation['contact_role'] ) || ! empty( $conversation['lead_summary'] ) ) {
			$parts = array();

			if ( ! empty( $conversation['contact_company'] ) ) {
				$parts[] = 'Organisation: ' . sanitize_text_field( (string) $conversation['contact_company'] );
			} elseif ( ! empty( $session['company_name'] ) ) {
				$parts[] = 'Organisation: ' . sanitize_text_field( (string) $session['company_name'] );
			}

			if ( ! empty( $conversation['contact_role'] ) ) {
				$parts[] = 'Role/team: ' . sanitize_text_field( (string) $conversation['contact_role'] );
			}

			if ( ! empty( $conversation['lead_summary'] ) ) {
				$parts[] = 'Lead summary: ' . sanitize_textarea_field( (string) $conversation['lead_summary'] );
			}

			if ( ! empty( $parts ) ) {
				$lines[] = 'Known lead profile: ' . implode( ' | ', $parts );
			}
		}

		if ( ! empty( $conversation['contact_name'] ) || ! empty( $conversation['contact_email'] ) || ! empty( $conversation['contact_phone'] ) ) {
			$contact_parts = array();

			if ( ! empty( $conversation['contact_name'] ) ) {
				$contact_parts[] = 'Name: ' . sanitize_text_field( (string) $conversation['contact_name'] );
			}

			if ( ! empty( $conversation['contact_email'] ) ) {
				$contact_parts[] = 'Email: ' . sanitize_email( (string) $conversation['contact_email'] );
			}

			if ( ! empty( $conversation['contact_phone'] ) ) {
				$contact_parts[] = 'Phone: ' . sanitize_text_field( (string) $conversation['contact_phone'] );
			}

			if ( ! empty( $contact_parts ) ) {
				$lines[] = 'Saved follow-up details: ' . implode( ' | ', $contact_parts ) . '. Do not ask for them again unless the visitor wants to change them.';
			}
		}

		if ( empty( $conversation['contact_company'] ) && empty( $session['company_id'] ) && is_array( $memory ) && ! empty( $memory['company_name'] ) ) {
			$lines[] = sprintf(
				'Returning IP hint: this network was previously associated with %1$s. Treat that as a likely organisation signal, not a guaranteed fact.',
				sanitize_text_field( (string) $memory['company_name'] )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Persist extracted lead data to the conversation, company, session, and IP memory.
	 *
	 * @param array<string, mixed>      $conversation Conversation row.
	 * @param array<string, mixed>|null $session      Session row.
	 * @param array<string, mixed>      $captured     Captured data.
	 * @return array{conversation:array<string, mixed>,session:array<string, mixed>|null,memory:array<string, mixed>|null,captured:array<string, mixed>}
	 */
	private function persist_capture( array $conversation, ?array $session, array $captured ): array {
		$captured     = $this->merge_captured_details( $conversation, $captured, $session );
		$company_name = sanitize_text_field( (string) ( $captured['contact_company'] ?? '' ) );
		$company      = null;
		$memory       = null;

		if ( '' !== $company_name ) {
			$company = $this->companies->create_or_touch_local(
				array(
					'name'       => $company_name,
					'domain'     => $this->get_domain_from_email( sanitize_email( (string) ( $captured['contact_email'] ?? '' ) ) ),
					'confidence' => 'confirmed',
					'source'     => 'chat_declared',
				)
			);
		}

		$has_contact_method = '' !== $this->normalise_email( (string) ( $captured['contact_email'] ?? '' ) ) || '' !== $this->normalise_phone_number( (string) ( $captured['contact_phone'] ?? '' ) );
		$lead_summary       = sanitize_textarea_field( (string) ( $captured['lead_summary'] ?? '' ) );

		if ( '' === $lead_summary ) {
			$lead_summary = $this->build_contact_summary( $captured );
		}

		$updated = $this->conversations->update_lead_profile(
			(int) ( $conversation['id'] ?? 0 ),
			array(
				'company_id'         => is_array( $company ) ? (int) $company['id'] : 0,
				'contact_name'       => (string) ( $captured['contact_name'] ?? '' ),
				'contact_email'      => (string) ( $captured['contact_email'] ?? '' ),
				'contact_phone'      => (string) ( $captured['contact_phone'] ?? '' ),
				'contact_company'    => $company_name,
				'contact_role'       => (string) ( $captured['contact_role'] ?? '' ),
				'lead_summary'       => $lead_summary,
				'follow_up_requested'=> $has_contact_method,
				'follow_up_at'       => $has_contact_method ? current_time( 'mysql', true ) : '',
				'contact_captured_at'=> $has_contact_method ? current_time( 'mysql', true ) : '',
			)
		);

		if ( is_array( $updated ) ) {
			$conversation = $updated;
		}

		if ( is_array( $session ) && is_array( $company ) && ! empty( $company['id'] ) && empty( $session['company_id'] ) ) {
			$this->sessions->assign_company( (int) $session['id'], (int) $company['id'], 'confirmed' );
			$session = $this->sessions->get_session_detail( (int) $session['id'] ) ?: $session;
		}

		if ( is_array( $session ) && ! empty( $session['ip_hash'] ) && '' !== $company_name ) {
			$memory = $this->ip_memory->upsert(
				sanitize_text_field( (string) $session['ip_hash'] ),
				array(
					'company_id'     => is_array( $company ) ? (int) $company['id'] : 0,
					'company_name'   => $company_name,
					'company_domain' => $this->get_domain_from_email( sanitize_email( (string) ( $captured['contact_email'] ?? '' ) ) ),
					'contact_name'   => (string) ( $captured['contact_name'] ?? '' ),
					'contact_email'  => (string) ( $captured['contact_email'] ?? '' ),
					'contact_phone'  => (string) ( $captured['contact_phone'] ?? '' ),
					'contact_role'   => (string) ( $captured['contact_role'] ?? '' ),
					'source'         => 'chat_declared',
					'confidence'     => 'confirmed',
					'evidence'       => array(
						'conversation_id' => (int) ( $conversation['id'] ?? 0 ),
						'session_id'      => (int) ( $session['id'] ?? 0 ),
						'lead_summary'    => $lead_summary,
					),
				)
			);
		}

		return array(
			'conversation' => $conversation,
			'session'      => $session,
			'memory'       => $memory,
			'captured'     => $captured,
		);
	}

	/**
	 * Extract lead details from free text.
	 *
	 * @param string $message Visitor message.
	 * @return array<string, string>
	 */
	private function extract_from_text( string $message ): array {
		$message = wp_strip_all_tags( $message );
		$email   = $this->extract_labelled_value( $message, array( 'email', 'e-mail', 'email address' ) );
		$phone   = $this->extract_labelled_value( $message, array( 'phone', 'telephone', 'tel', 'mobile', 'number', 'phone number' ) );
		$name    = $this->extract_labelled_value( $message, array( 'name', 'full name', 'contact name' ) );
		$company = $this->extract_labelled_value( $message, array( 'company', 'organisation', 'organization', 'business', 'organisation name', 'company name' ) );
		$role    = $this->extract_labelled_value( $message, array( 'role', 'job title', 'position', 'title' ) );

		if ( '' === $email && preg_match( '/\b(?:email(?: me)?(?: at)?|e-mail(?: me)?(?: at)?|my email(?: address)?(?: is)?|reach me at)\b[\s:,-]*([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})\b/i', $message, $email_match ) ) {
			$email = (string) $email_match[1];
		}

		if ( '' === $email && preg_match( '/\b([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})\b/i', $message, $email_match ) ) {
			$email = (string) $email_match[1];
		}

		if ( '' === $phone && preg_match( '/\b(?:phone|telephone|tel|mobile|call me(?: on)?|ring me(?: on)?|reach me(?: on)?|my number(?: is)?|number is)\b[\s:,-]*(\+?\d[\d\s().-]{7,}\d)\b/i', $message, $phone_match ) ) {
			$phone = (string) $phone_match[1];
		}

		if ( '' === $phone && preg_match( '/(?<!\w)(\+?\d[\d\s().-]{7,}\d)(?!\w)/', $message, $phone_match ) ) {
			$phone = (string) $phone_match[1];
		}

		if ( '' === $name && preg_match( '/\b(?:my name is|name is|i am|i\'m|this is|it\'s)\s+([a-z][a-z\'\-]+(?:\s+[a-z][a-z\'\-]+){0,2})\b/i', $message, $name_match ) ) {
			$candidate = trim( preg_replace( '/\s+/', ' ', (string) $name_match[1] ) ?: '' );

			if ( ! preg_match( '/\b(looking|interested|after|trying|buying|shopping|purchase|purchasing|ordering|procuring|needing|from|with|the)\b/i', $candidate ) ) {
				$name = $candidate;
			}
		}

		$company_patterns = array(
			'/\b(?:company|organisation|organization|business)(?:\s+name)?\s*(?:is|:)\s+([A-Za-z0-9&.,\' -]{2,120})/i',
			'/\b(?:shopping for|buying for|looking to buy for|purchase for|purchasing for|looking to purchase for|ordering for|procuring for|on behalf of)\s+([A-Za-z0-9&.,\' -]{3,120}?)(?:[\r\n]|[?.!,]|$)/i',
			'/\b(?:i am from|i\'m from|we are from|we\'re from|i work for|we work for|i am with|i\'m with|we are with|we\'re with)\s+([A-Za-z0-9&.,\' -]{3,120}?)(?:\s+(?:and|who|looking|interested|about|for)\b|[\r\n]|[?.!,]|$)/i',
			'/\bfrom\s+([A-Z][A-Za-z0-9&.,\' -]{3,120})(?:\s+(?:and|who|looking|interested|about|for)\b|[?.!,]|$)/',
		);

		foreach ( $company_patterns as $pattern ) {
			if ( preg_match( $pattern, $message, $company_match ) ) {
				$company = $this->clean_company_name( (string) $company_match[1] );

				if ( '' !== $company ) {
					break;
				}
			}
		}

		if ( '' === $role && preg_match( '/\b(?:i am|i\'m|i work as|my role is|i am the|i\'m the)\s+(?:a\s+|an\s+|the\s+)?([A-Za-z][A-Za-z&\/,\- ]{2,80})(?:\s+(?:at|for|with)\b|[?.!,]|$)/i', $message, $role_match ) ) {
			$candidate = sanitize_text_field( trim( preg_replace( '/\s+/', ' ', (string) $role_match[1] ) ?: '' ) );

			if ( preg_match( '/\b(manager|officer|buyer|procurement|fleet|director|head|coordinator|administrator|supervisor|consultant|engineer|team|services|waste|operations|estates|facilities)\b/i', $candidate ) ) {
				$role = $candidate;
			}
		}

		return array_filter(
			array(
				'contact_name'    => $this->clean_person_name( $name ),
				'contact_email'   => $this->normalise_email( $email ),
				'contact_phone'   => $this->normalise_phone_number( $phone ),
				'contact_company' => '' !== $company ? $this->clean_company_name( $company ) : '',
				'contact_role'    => $role,
			)
		);
	}

	/**
	 * Merge newly captured details with the current stored lead profile.
	 *
	 * @param array<string, mixed>      $conversation Conversation row.
	 * @param array<string, mixed>      $captured     Freshly captured values.
	 * @param array<string, mixed>|null $session      Session row.
	 * @return array<string, mixed>
	 */
	private function merge_captured_details( array $conversation, array $captured, ?array $session ): array {
		$merged = array(
			'contact_name'    => $this->clean_person_name(
				$this->prefer_non_empty(
					(string) ( $captured['contact_name'] ?? '' ),
					(string) ( $conversation['contact_name'] ?? '' )
				)
			),
			'contact_email'   => $this->normalise_email(
				$this->prefer_non_empty(
					(string) ( $captured['contact_email'] ?? '' ),
					(string) ( $conversation['contact_email'] ?? '' )
				)
			),
			'contact_phone'   => $this->normalise_phone_number(
				$this->prefer_non_empty(
					(string) ( $captured['contact_phone'] ?? '' ),
					(string) ( $conversation['contact_phone'] ?? '' )
				)
			),
			'contact_company' => sanitize_text_field(
				$this->prefer_non_empty(
					(string) ( $captured['contact_company'] ?? '' ),
					(string) ( $conversation['contact_company'] ?? '' ),
					(string) ( $session['company_name'] ?? '' )
				)
			),
			'contact_role'    => sanitize_text_field(
				$this->prefer_non_empty(
					(string) ( $captured['contact_role'] ?? '' ),
					(string) ( $conversation['contact_role'] ?? '' )
				)
			),
			'lead_summary'    => sanitize_textarea_field(
				$this->prefer_non_empty(
					(string) ( $captured['lead_summary'] ?? '' ),
					(string) ( $conversation['lead_summary'] ?? '' )
				)
			),
		);

		$merged['contact_company'] = '' !== $merged['contact_company'] ? $this->clean_company_name( $merged['contact_company'] ) : '';

		return $merged;
	}

	/**
	 * Build a concise lead summary from captured data.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @param string               $message      Visitor message.
	 * @return string
	 */
	private function build_lead_summary( array $conversation, string $message ): string {
		$themes = array();
		$message = strtolower( $message );

		if ( preg_match( '/\b(quote|pricing|price|cost|budget|tender)\b/', $message ) ) {
			$themes[] = __( 'Pricing or quote enquiry', 'adaptive-customer-engagement' );
		}

		if ( preg_match( '/\b(delivery|shipping|ship|postcode|lead time)\b/', $message ) ) {
			$themes[] = __( 'Delivery enquiry', 'adaptive-customer-engagement' );
		}

		if ( preg_match( '/\b(product|bin|container|basket|order|buy|purchase|spec|specification|brochure)\b/', $message ) ) {
			$themes[] = __( 'Product buying intent', 'adaptive-customer-engagement' );
		}

		if ( empty( $themes ) ) {
			$themes[] = __( 'Commercial chat enquiry', 'adaptive-customer-engagement' );
		}

		$existing = sanitize_textarea_field( (string) ( $conversation['lead_summary'] ?? '' ) );

		if ( '' !== $existing ) {
			array_unshift( $themes, $existing );
		}

		return sanitize_textarea_field( implode( ' | ', array_slice( array_values( array_unique( array_filter( $themes ) ) ), 0, 3 ) ) );
	}

	/**
	 * Build a contact-led summary.
	 *
	 * @param array<string, mixed> $captured Captured lead data.
	 * @return string
	 */
	private function build_contact_summary( array $captured ): string {
		$parts = array();

		if ( ! empty( $captured['contact_company'] ) ) {
			$parts[] = sanitize_text_field( (string) $captured['contact_company'] );
		}

		if ( ! empty( $captured['contact_role'] ) ) {
			$parts[] = sanitize_text_field( (string) $captured['contact_role'] );
		}

		if ( ! empty( $captured['contact_name'] ) ) {
			$parts[] = sanitize_text_field( (string) $captured['contact_name'] );
		}

		if ( ! empty( $captured['contact_email'] ) || ! empty( $captured['contact_phone'] ) ) {
			$parts[] = __( 'Follow-up details captured', 'adaptive-customer-engagement' );
		}

		return sanitize_textarea_field( implode( ' | ', array_filter( $parts ) ) );
	}

	/**
	 * Convert an email address to a likely company domain when it is not generic.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function get_domain_from_email( string $email ): string {
		$email = $this->normalise_email( $email );

		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return '';
		}

		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) ?: '' );
		$generic_domains = array(
			'gmail.com',
			'googlemail.com',
			'outlook.com',
			'hotmail.com',
			'live.com',
			'yahoo.com',
			'icloud.com',
			'aol.com',
			'proton.me',
			'protonmail.com',
		);

		return in_array( $domain, $generic_domains, true ) ? '' : sanitize_text_field( $domain );
	}

	/**
	 * Clean an extracted company string.
	 *
	 * @param string $company Raw company text.
	 * @return string
	 */
	private function clean_company_name( string $company ): string {
		$company = trim( preg_replace( '/\s+/', ' ', $company ) ?: '' );
		$company = trim( preg_replace( '/\b(and|who|looking|interested|about|for|because|as)\b.*$/i', '', $company ) ?: '' );
		$company = trim( $company, " \t\n\r\0\x0B.,;:-" );

		return sanitize_text_field( $company );
	}

	/**
	 * Extract a labelled field from chat text.
	 *
	 * @param string        $message Chat message.
	 * @param array<int, string> $labels Field labels.
	 * @return string
	 */
	private function extract_labelled_value( string $message, array $labels ): string {
		foreach ( $labels as $label ) {
			$pattern = '/(?:^|[\r\n])\s*' . preg_quote( $label, '/' ) . '\s*[:\-]\s*(.+)$/mi';

			if ( preg_match( $pattern, $message, $matches ) ) {
				return sanitize_text_field( trim( (string) $matches[1] ) );
			}
		}

		return '';
	}

	/**
	 * Normalise an email address for storage.
	 *
	 * @param string $email Raw email address.
	 * @return string
	 */
	private function normalise_email( string $email ): string {
		$email = strtolower( trim( wp_strip_all_tags( $email ) ) );
		$email = sanitize_email( $email );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Normalise a phone number for storage.
	 *
	 * @param string $phone Raw phone number.
	 * @return string
	 */
	private function normalise_phone_number( string $phone ): string {
		$phone = trim( wp_strip_all_tags( $phone ) );

		if ( '' === $phone ) {
			return '';
		}

		$phone = preg_replace( '/\b(?:ext|extension|x)\s*\d+$/i', '', $phone ) ?: $phone;
		$phone = trim( $phone );
		$has_plus = '+' === substr( ltrim( $phone ), 0, 1 );
		$digits   = preg_replace( '/\D+/', '', $phone ) ?: '';

		if ( '' === $digits || strlen( $digits ) < 8 ) {
			return '';
		}

		if ( ! $has_plus && 0 === strpos( $digits, '00' ) ) {
			$has_plus = true;
			$digits   = substr( $digits, 2 );
		}

		return sanitize_text_field( $has_plus ? '+' . $digits : $digits );
	}

	/**
	 * Clean a person's name for storage.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private function clean_person_name( string $name ): string {
		$name = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $name ) ) ?: '' );
		$name = trim( $name, " \t\n\r\0\x0B.,;:-" );

		if ( '' === $name ) {
			return '';
		}

		return sanitize_text_field( ucwords( strtolower( $name ) ) );
	}

	/**
	 * Return the first non-empty string candidate.
	 *
	 * @param string ...$values Candidate values.
	 * @return string
	 */
	private function prefer_non_empty( string ...$values ): string {
		foreach ( $values as $value ) {
			$value = trim( $value );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
