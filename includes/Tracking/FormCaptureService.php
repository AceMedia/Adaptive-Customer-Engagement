<?php
/**
 * Form submission capture.
 *
 * Listens for the `ace_form_submitted` action fired by the Adaptive Content
 * Enhancer form plugin, stores the submission against the visitor's tracked
 * session, and promotes declared identity (name, email, business) into the
 * company/lead records — declared data outranks IP enrichment.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\FormSubmissionRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\IpCompanyMemoryRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

final class FormCaptureService {
	/**
	 * Freemail domains that never identify a business.
	 *
	 * @var string[]
	 */
	public const GENERIC_EMAIL_DOMAINS = array(
		'gmail.com',
		'googlemail.com',
		'outlook.com',
		'hotmail.com',
		'hotmail.co.uk',
		'live.com',
		'live.co.uk',
		'yahoo.com',
		'yahoo.co.uk',
		'icloud.com',
		'me.com',
		'aol.com',
		'proton.me',
		'protonmail.com',
		'btinternet.com',
		'sky.com',
		'virginmedia.com',
		'talktalk.net',
	);

	/**
	 * Submission repository.
	 *
	 * @var FormSubmissionRepository
	 */
	private $submissions;

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
	 * IP-to-company memory repository.
	 *
	 * @var IpCompanyMemoryRepository
	 */
	private $ip_memory;

	/**
	 * Constructor.
	 *
	 * @param FormSubmissionRepository  $submissions Submission repository.
	 * @param SessionRepository         $sessions    Session repository.
	 * @param CompanyRepository         $companies   Company repository.
	 * @param IpCompanyMemoryRepository $ip_memory   IP memory repository.
	 */
	public function __construct( FormSubmissionRepository $submissions, SessionRepository $sessions, CompanyRepository $companies, IpCompanyMemoryRepository $ip_memory ) {
		$this->submissions = $submissions;
		$this->sessions    = $sessions;
		$this->companies   = $companies;
		$this->ip_memory   = $ip_memory;
	}

	/**
	 * Hook the capture listener.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'ace_form_submitted', array( $this, 'capture' ), 10, 4 );
	}

	/**
	 * Capture a form submission.
	 *
	 * @param mixed $fields  Normalised field values keyed by underscored field name.
	 * @param mixed $config  Verified form configuration.
	 * @param mixed $request The submission REST request.
	 * @param mixed $sent    Whether the notification email was sent.
	 * @return void
	 */
	public function capture( $fields, $config = array(), $request = null, $sent = true ): void {
		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return;
		}

		$config  = is_array( $config ) ? $config : array();
		$contact = $this->extract_contact( $fields );
		$session = $this->resolve_session();

		$page_url = '';

		if ( $request instanceof \WP_REST_Request ) {
			$page_url = esc_url_raw( (string) $request->get_param( 'page_url' ) );
		}

		$submission_id = $this->submissions->insert(
			array(
				'session_id'      => is_array( $session ) ? (int) $session['id'] : 0,
				'form_key'        => sanitize_text_field( (string) ( $config['subject'] ?? ( $config['recipient'] ?? '' ) ) ),
				'page_url'        => $page_url,
				'contact_name'    => $contact['name'],
				'contact_email'   => $contact['email'],
				'contact_phone'   => $contact['phone'],
				'contact_company' => $contact['company'],
				'fields'          => $fields,
				'mail_sent'       => (bool) $sent,
			)
		);

		$company = $this->resolve_company( $contact );

		if ( is_array( $company ) && ! empty( $company['id'] ) ) {
			if ( $submission_id > 0 ) {
				$this->submissions->set_company( $submission_id, (int) $company['id'] );
			}

			if ( is_array( $session ) && 'confirmed' !== (string) ( $session['company_confidence'] ?? '' ) ) {
				$this->sessions->assign_company( (int) $session['id'], (int) $company['id'], 'confirmed' );
			}
		}

		if ( is_array( $session ) && ! empty( $session['ip_hash'] ) && $this->has_identity( $contact ) ) {
			$this->ip_memory->upsert(
				sanitize_text_field( (string) $session['ip_hash'] ),
				array(
					'company_id'     => is_array( $company ) ? (int) $company['id'] : 0,
					'company_name'   => $contact['company'],
					'company_domain' => $this->business_domain( $contact['email'] ),
					'contact_name'   => $contact['name'],
					'contact_email'  => $contact['email'],
					'contact_phone'  => $contact['phone'],
					'source'         => 'form_submission',
					'confidence'     => 'confirmed',
					'evidence'       => array(
						'submission_id' => $submission_id,
						'session_id'    => (int) $session['id'],
						'page_url'      => $page_url,
					),
				)
			);
		}
	}

	/**
	 * Resolve the visitor's tracked session from the tracking cookie sent
	 * with the form's REST request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve_session(): ?array {
		$settings    = Settings::get();
		$cookie_name = sanitize_key( (string) ( $settings['tracking']['cookie_name'] ?? 'ace_sid' ) );

		if ( '' === $cookie_name || empty( $_COOKIE[ $cookie_name ] ) ) {
			return null;
		}

		$session_uuid = sanitize_text_field( wp_unslash( (string) $_COOKIE[ $cookie_name ] ) );

		return '' !== $session_uuid ? $this->sessions->find_by_uuid( $session_uuid ) : null;
	}

	/**
	 * Extract contact identity from normalised form fields.
	 *
	 * Field keys are underscored versions of the form labels, so identity is
	 * matched on key keywords. The map is filterable for unusual forms.
	 *
	 * @param array<string, mixed> $fields Normalised fields.
	 * @return array{name: string, email: string, phone: string, company: string}
	 */
	private function extract_contact( array $fields ): array {
		$keywords = apply_filters(
			'ace_form_capture_field_keywords',
			array(
				'email'   => array( 'email', 'e_mail' ),
				'phone'   => array( 'phone', 'telephone', 'tel', 'mobile' ),
				'company' => array( 'company', 'business', 'organisation', 'organization', 'employer' ),
				'name'    => array( 'name' ),
			)
		);

		$contact    = array(
			'name'    => '',
			'email'   => '',
			'phone'   => '',
			'company' => '',
		);
		$first_name = '';
		$last_name  = '';

		foreach ( $fields as $key => $value ) {
			$key   = strtolower( (string) $key );
			$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;

			if ( '' === $value ) {
				continue;
			}

			if ( '' === $contact['email'] && ( $this->key_matches( $key, $keywords['email'] ) || is_email( $value ) ) ) {
				$email = sanitize_email( $value );

				if ( is_email( $email ) ) {
					$contact['email'] = $email;
					continue;
				}
			}

			if ( '' === $contact['phone'] && $this->key_matches( $key, $keywords['phone'] ) ) {
				$contact['phone'] = sanitize_text_field( $value );
				continue;
			}

			if ( '' === $contact['company'] && $this->key_matches( $key, $keywords['company'] ) ) {
				$contact['company'] = sanitize_text_field( $value );
				continue;
			}

			if ( $this->key_matches( $key, $keywords['name'] ) && ! $this->key_matches( $key, $keywords['company'] ) ) {
				if ( false !== strpos( $key, 'first' ) ) {
					$first_name = sanitize_text_field( $value );
				} elseif ( false !== strpos( $key, 'last' ) || false !== strpos( $key, 'surname' ) ) {
					$last_name = sanitize_text_field( $value );
				} elseif ( '' === $contact['name'] ) {
					$contact['name'] = sanitize_text_field( $value );
				}
			}
		}

		if ( '' === $contact['name'] && ( '' !== $first_name || '' !== $last_name ) ) {
			$contact['name'] = trim( $first_name . ' ' . $last_name );
		}

		return $contact;
	}

	/**
	 * Resolve or create the company a submission belongs to.
	 *
	 * @param array{name: string, email: string, phone: string, company: string} $contact Contact details.
	 * @return array<string, mixed>|null
	 */
	private function resolve_company( array $contact ): ?array {
		$domain = $this->business_domain( $contact['email'] );
		$name   = $contact['company'];

		if ( '' === $name && '' === $domain ) {
			return null;
		}

		return $this->companies->create_or_touch_local(
			array(
				'name'       => '' !== $name ? $name : $domain,
				'domain'     => $domain,
				'confidence' => 'confirmed',
				'source'     => 'form_submission',
			)
		);
	}

	/**
	 * Get the business domain from an email address, ignoring freemail.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function business_domain( string $email ): string {
		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return '';
		}

		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) ?: '' );

		return in_array( $domain, self::GENERIC_EMAIL_DOMAINS, true ) ? '' : sanitize_text_field( $domain );
	}

	/**
	 * Whether any identity detail was captured.
	 *
	 * @param array{name: string, email: string, phone: string, company: string} $contact Contact details.
	 * @return bool
	 */
	private function has_identity( array $contact ): bool {
		return '' !== $contact['name'] || '' !== $contact['email'] || '' !== $contact['phone'] || '' !== $contact['company'];
	}

	/**
	 * Match a field key against keyword fragments.
	 *
	 * @param string   $key      Field key.
	 * @param string[] $keywords Keyword fragments.
	 * @return bool
	 */
	private function key_matches( string $key, array $keywords ): bool {
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $key, $keyword ) ) {
				return true;
			}
		}

		return false;
	}
}
