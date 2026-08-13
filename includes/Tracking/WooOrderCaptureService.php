<?php
/**
 * WooCommerce order identity capture.
 *
 * Every completed checkout carries declared identity — billing company,
 * business email domain, name and phone — which is the strongest company
 * signal a shop ever sees. Orders promote that identity into the company
 * records and remember it against the buyer's IP so future visits from the
 * same network are recognised without any enrichment provider.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\IpCompanyMemoryRepository;
use ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository;
use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

final class WooOrderCaptureService {
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
	 * Privacy helper.
	 *
	 * @var Privacy
	 */
	private $privacy;

	/**
	 * Constructor.
	 *
	 * @param SessionRepository         $sessions  Session repository.
	 * @param CompanyRepository         $companies Company repository.
	 * @param IpCompanyMemoryRepository $ip_memory IP memory repository.
	 * @param Privacy                   $privacy   Privacy helper.
	 */
	public function __construct( SessionRepository $sessions, CompanyRepository $companies, IpCompanyMemoryRepository $ip_memory, Privacy $privacy ) {
		$this->sessions  = $sessions;
		$this->companies = $companies;
		$this->ip_memory = $ip_memory;
		$this->privacy   = $privacy;
	}

	/**
	 * Hook both checkout flows. Harmless when WooCommerce is absent — the
	 * actions simply never fire.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_classic' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_order' ), 20, 1 );
	}

	/**
	 * Capture from the classic (shortcode) checkout.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $posted   Posted checkout data (unused).
	 * @param mixed $order    Order object when supplied.
	 * @return void
	 */
	public function capture_classic( $order_id, $posted = array(), $order = null ): void {
		if ( ! $order instanceof \WC_Order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order_id );
		}

		$this->capture_order( $order );
	}

	/**
	 * Capture declared identity from an order.
	 *
	 * @param mixed $order WC_Order instance.
	 * @return void
	 */
	public function capture_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$company = sanitize_text_field( (string) $order->get_billing_company() );
		$email   = sanitize_email( (string) $order->get_billing_email() );
		$name    = sanitize_text_field( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
		$phone   = sanitize_text_field( (string) $order->get_billing_phone() );
		$domain  = $this->business_domain( $email );

		$company_row = null;

		if ( '' !== $company || '' !== $domain ) {
			$company_row = $this->companies->create_or_touch_local(
				array(
					'name'       => '' !== $company ? $company : $domain,
					'domain'     => $domain,
					'confidence' => 'confirmed',
					'source'     => 'woocommerce_order',
				)
			);
		}

		$session = $this->resolve_session();

		if ( is_array( $company_row ) && ! empty( $company_row['id'] ) && is_array( $session ) && 'confirmed' !== (string) ( $session['company_confidence'] ?? '' ) ) {
			$this->sessions->assign_company( (int) $session['id'], (int) $company_row['id'], 'confirmed' );
		}

		$ip_hash = $this->privacy->hash_ip( (string) $order->get_customer_ip_address() );

		if ( '' === $ip_hash && is_array( $session ) && ! empty( $session['ip_hash'] ) ) {
			$ip_hash = (string) $session['ip_hash'];
		}

		if ( '' === $ip_hash || ( '' === $company && '' === $domain && '' === $name && '' === $email && '' === $phone ) ) {
			return;
		}

		$this->ip_memory->upsert(
			$ip_hash,
			array(
				'company_id'     => is_array( $company_row ) ? (int) ( $company_row['id'] ?? 0 ) : 0,
				'company_name'   => $company,
				'company_domain' => $domain,
				'contact_name'   => $name,
				'contact_email'  => $email,
				'contact_phone'  => $phone,
				'source'         => 'woocommerce_order',
				'confidence'     => 'confirmed',
				'evidence'       => array(
					'order_id'   => (int) $order->get_id(),
					'session_id' => is_array( $session ) ? (int) $session['id'] : 0,
				),
			)
		);
	}

	/**
	 * Resolve the buyer's tracked session from the tracking cookie.
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

		return in_array( $domain, FormCaptureService::GENERIC_EMAIL_DOMAINS, true ) ? '' : sanitize_text_field( $domain );
	}
}
