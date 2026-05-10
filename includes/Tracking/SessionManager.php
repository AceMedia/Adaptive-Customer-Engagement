<?php
/**
 * Session service.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

final class SessionManager {
	/**
	 * Session repository.
	 *
	 * @var SessionRepository
	 */
	private $sessions;

	/**
	 * Privacy helper.
	 *
	 * @var Privacy
	 */
	private $privacy;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository $sessions Session repository.
	 * @param Privacy           $privacy  Privacy helper.
	 */
	public function __construct( SessionRepository $sessions, Privacy $privacy ) {
		$this->sessions = $sessions;
		$this->privacy  = $privacy;
	}

	/**
	 * Determine whether to ignore the current request.
	 *
	 * @return bool
	 */
	public function should_ignore_request(): bool {
		$settings = Settings::get();

		if ( ! empty( $settings['tracking']['ignore_logged_in_admins'] ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! empty( $settings['privacy']['ignore_internal_ips'] ) && $this->privacy->is_private_ip( $this->privacy->get_client_ip() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Touch a session from a tracking payload.
	 *
	 * @param array<string, mixed> $payload Tracking payload.
	 * @param bool                 $is_bot  Bot flag.
	 * @param bool                 $ignored Ignore flag.
	 * @return array<string, mixed>
	 */
	public function touch_session( array $payload, bool $is_bot, bool $ignored ): array {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$accept     = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		$ip         = $this->privacy->get_client_ip();

		return $this->sessions->create_or_touch(
			(string) $payload['session_uuid'],
			array(
				'visitor_uuid'      => sanitize_text_field( (string) ( $payload['visitor_uuid'] ?? '' ) ),
				'first_url'         => esc_url_raw( (string) ( $payload['url'] ?? '' ) ),
				'landing_path'      => sanitize_text_field( (string) ( $payload['path'] ?? '' ) ),
				'referrer'          => $this->clean_referrer( (string) ( $payload['referrer'] ?? '' ) ),
				'utm_source'        => sanitize_text_field( (string) ( $payload['utm']['source'] ?? '' ) ),
				'utm_medium'        => sanitize_text_field( (string) ( $payload['utm']['medium'] ?? '' ) ),
				'utm_campaign'      => sanitize_text_field( (string) ( $payload['utm']['campaign'] ?? '' ) ),
				'utm_term'          => sanitize_text_field( (string) ( $payload['utm']['term'] ?? '' ) ),
				'utm_content'       => sanitize_text_field( (string) ( $payload['utm']['content'] ?? '' ) ),
				'user_agent'        => $user_agent,
				'browser_hash'      => $this->privacy->hash_value( $user_agent . '|' . $accept ),
				'ip_hash'           => $this->privacy->hash_ip( $ip ),
				'ip_raw'            => $ip,
				'ip_raw_expires_at' => $this->privacy->get_raw_ip_expiry(),
				'is_bot'            => $is_bot ? 1 : 0,
				'ignored'           => $ignored ? 1 : 0,
			)
		);
	}

	/**
	 * Strip referrer query strings.
	 *
	 * @param string $referrer Referrer URL.
	 * @return string
	 */
	private function clean_referrer( string $referrer ): string {
		$referrer = esc_url_raw( $referrer );

		if ( '' === $referrer ) {
			return '';
		}

		$parts = wp_parse_url( $referrer );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$clean = $parts['scheme'] . '://' . $parts['host'];

		if ( ! empty( $parts['path'] ) ) {
			$clean .= $parts['path'];
		}

		return $clean;
	}
}
