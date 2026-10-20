<?php
/**
 * Abandoned basket quote email (WooCommerce only, off by default).
 *
 * Once a shopper has typed their email on checkout we keep the basket against a token. If no order follows within
 * the configured delay, one plain email lists the basket (prices excluding VAT) as a quote valid for 14 days, with a
 * link that restores the basket and a link to opt out. Lawful basis is legitimate interest; baskets are purged after
 * 30 days and only a hash of an opted-out address is kept so it is never captured again.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\BasketRecoveryRepository;
use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

final class BasketRecoveryService {
	public const SEND_HOOK      = 'ace_basket_recovery_send';
	public const GROUP          = 'ace-basket-recovery';
	public const LOG_SOURCE     = 'ace-basket-recovery';
	public const QUOTE_DAYS     = 14;
	public const RETENTION_DAYS = 30;

	/**
	 * Repository.
	 *
	 * @var BasketRecoveryRepository
	 */
	private $baskets;

	/**
	 * Constructor.
	 *
	 * @param BasketRecoveryRepository $baskets Repository.
	 */
	public function __construct( BasketRecoveryRepository $baskets ) {
		$this->baskets = $baskets;
	}

	/**
	 * Register hooks. Everything WooCommerce-specific waits for woocommerce_loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		// Retention runs whether or not the feature is on, so switching it off still clears data.
		add_action( 'ace_purge_expired_raw_data', array( $this, 'purge' ) );

		if ( did_action( 'woocommerce_loaded' ) ) {
			$this->boot();
		} else {
			add_action( 'woocommerce_loaded', array( $this, 'boot' ) );
		}
	}

	/**
	 * Hook the WooCommerce-dependent parts.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Links already emailed keep working even if the feature is later switched off.
		add_action( 'template_redirect', array( $this, 'handle_links' ), 5 );
		add_action( self::SEND_HOOK, array( $this, 'send' ), 10, 1 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'maybe_push_conversion' ), 20, 1 );

		if ( ! $this->settings()['enabled'] ) {
			return;
		}

		add_filter( 'rest_request_after_callbacks', array( $this, 'capture_from_store_api' ), 10, 3 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_from_classic' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'order_placed' ), 5, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'order_placed' ), 5, 1 );

		if ( $this->settings()['nudge_enabled'] ) {
			add_filter( 'render_block_woocommerce/cart', array( $this, 'prepend_nudge_cart' ), 10, 1 );
			add_filter( 'render_block_woocommerce/checkout', array( $this, 'prepend_nudge_checkout' ), 10, 1 );
			add_action( 'woocommerce_before_cart', array( $this, 'echo_nudge_cart' ), 5 );
			add_action( 'woocommerce_before_checkout_form', array( $this, 'echo_nudge_checkout' ), 5 );
			add_action( 'wc_ajax_ace_basket_save', array( $this, 'ajax_save' ) );
		}
	}

	/**
	 * Basket recovery settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$settings = Settings::get();

		return $settings['basket_recovery'] ?? Settings::defaults()['basket_recovery'];
	}

	/**
	 * Capture after the block checkout updates the customer.
	 *
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response Response.
	 * @param array<string, mixed>                                $handler  Route handler.
	 * @param \WP_REST_Request                                    $request  Request.
	 * @return mixed
	 */
	public function capture_from_store_api( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request || is_wp_error( $response ) ) {
			return $response;
		}

		if ( false === strpos( $request->get_route(), '/wc/store/v1/cart/update-customer' ) ) {
			return $response;
		}

		$billing = $request->get_param( 'billing_address' );
		$email   = is_array( $billing ) && ! empty( $billing['email'] ) ? (string) $billing['email'] : '';

		$this->capture( $email, 'checkout_field' );

		return $response;
	}

	/**
	 * Capture from the classic checkout's order-review refresh.
	 *
	 * @param string $posted_data Serialised form data.
	 * @return void
	 */
	public function capture_from_classic( $posted_data ): void {
		$data = array();
		parse_str( (string) $posted_data, $data );

		$this->capture( isset( $data['billing_email'] ) ? (string) $data['billing_email'] : '', 'checkout_field' );
	}

	/**
	 * Store the basket against the shopper's email and schedule the send.
	 *
	 * @param string $email  Email entered at checkout (falls back to the customer record).
	 * @param string $source Where it was entered: checkout_field, cart_nudge or checkout_nudge.
	 * @return array<string, mixed>|null The basket row, or null when nothing was saved.
	 */
	public function capture( string $email, string $source = 'checkout_field' ): ?array {
		if ( ! $this->settings()['enabled'] ) {
			return null;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || ! WC()->customer ) {
			return null;
		}

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			$email = sanitize_email( (string) WC()->customer->get_billing_email() );
		}
		if ( ! is_email( $email ) || WC()->cart->is_empty() ) {
			return null;
		}

		$hash = $this->hash( $email );
		if ( $this->baskets->is_opted_out( $hash ) ) {
			return null;
		}

		$lines = $this->snapshot_cart();
		if ( empty( $lines ) ) {
			return null;
		}

		$row = $this->baskets->upsert( (string) WC()->session->get_customer_id(), $email, $hash, wp_json_encode( $lines ) );
		if ( ! $row ) {
			return null;
		}

		if ( ! empty( $row['is_new'] ) ) {
			$this->baskets->record_event( (int) $row['id'], 'basket_saved', $source );
		}

		if ( 'pending' !== $row['status'] ) {
			return $row;
		}

		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::SEND_HOOK, array( (int) $row['id'] ), self::GROUP ) ) {
			$delay = (int) $this->settings()['delay_hours'];
			as_schedule_single_action( time() + ( $delay * HOUR_IN_SECONDS ), self::SEND_HOOK, array( (int) $row['id'] ), self::GROUP );
		}

		return $row;
	}

	/**
	 * Basket lines with prices excluding VAT.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function snapshot_cart(): array {
		$lines = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['composite_parent'] ) || ! empty( $item['bundled_by'] ) ) {
				continue; // Child component of a composite or bundle; the parent line carries the price.
			}

			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$qty = max( 1, (int) $item['quantity'] );

			$lines[] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) $item['variation_id'],
				'variation'    => is_array( $item['variation'] ?? null ) ? $item['variation'] : array(),
				'quantity'     => $qty,
				'name'         => wp_strip_all_tags( $product->get_name() ),
				'sku'          => (string) $product->get_sku(),
				'url'          => get_permalink( (int) $item['product_id'] ),
				'composite'    => ! empty( $item['composite_data'] ),
				'line_ex_vat'  => (float) ( $item['line_subtotal'] ?? 0 ),
			);
		}

		return $lines;
	}

	/**
	 * An order was placed: close any open basket for this shopper.
	 *
	 * @param int|\WC_Order $order Order or id.
	 * @return void
	 */
	public function order_placed( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order ) {
			return;
		}

		$email   = sanitize_email( (string) $order->get_billing_email() );
		$session = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get_customer_id() : '';
		$since   = gmdate( 'Y-m-d H:i:s', time() - ( self::QUOTE_DAYS * DAY_IN_SECONDS ) );

		foreach ( $this->baskets->close_open( $session, '' !== $email ? $this->hash( $email ) : '' ) as $basket ) {
			// Converted after recovery: they came back through our link (restored) within the quote window.
			if ( $this->baskets->has_event_since( (int) $basket['id'], 'basket_restored', $since ) ) {
				$this->baskets->record_event( (int) $basket['id'], 'order_placed_after_recovery' );
				$order->update_meta_data( '_ace_basket_recovered', $basket['token'] );
				$order->save_meta_data();
			}
		}
	}

	/**
	 * Push a GA4 event on the thank-you page for an order that came through a recovery link.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function maybe_push_conversion( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || ! $order->get_meta( '_ace_basket_recovered' ) || $order->get_meta( '_ace_basket_recovered_pushed' ) ) {
			return;
		}

		$order->update_meta_data( '_ace_basket_recovered_pushed', 1 );
		$order->save_meta_data();

		echo '<script>window.dataLayer&&window.dataLayer.push({event:"basket_recovery_converted"});</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Scheduled action: send the quote email if the basket is still abandoned.
	 *
	 * @param int $id Basket row id.
	 * @return void
	 */
	public function send( $id ): void {
		$row      = $this->baskets->find( (int) $id );
		$settings = $this->settings();

		if ( ! $row || 'pending' !== $row['status'] ) {
			return;
		}

		if ( ! $settings['enabled'] ) {
			$this->log( 'info', 'Skipped basket ' . (int) $id . ': feature is switched off.' );
			return;
		}

		// The shopper changed the basket recently: wait for the full delay after the last change.
		$due = strtotime( $row['updated_at'] . ' UTC' ) + ( (int) $settings['delay_hours'] * HOUR_IN_SECONDS );
		if ( $due > time() + 60 ) {
			as_schedule_single_action( $due, self::SEND_HOOK, array( (int) $id ), self::GROUP );
			return;
		}

		if ( $this->has_later_order( $row ) ) {
			$this->baskets->set_status( (int) $id, 'ordered' );
			$this->log( 'info', 'Basket ' . (int) $id . ' skipped: an order was placed.' );
			return;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - ( self::QUOTE_DAYS * DAY_IN_SECONDS ) );
		if ( $this->baskets->was_sent_since( $row['email_hash'], $since, (int) $id ) ) {
			$this->baskets->set_status( (int) $id, 'skipped' );
			$this->log( 'info', 'Basket ' . (int) $id . ' skipped: this address was emailed in the last ' . self::QUOTE_DAYS . ' days.' );
			return;
		}

		$lines = json_decode( (string) $row['cart'], true );
		if ( ! is_array( $lines ) || empty( $lines ) || ! is_email( $row['email'] ) ) {
			$this->baskets->set_status( (int) $id, 'skipped' );
			return;
		}

		$message = $this->build_email( $row, $lines, $settings );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'List-Unsubscribe: <' . $this->link( 'ace_basket_optout', $row['token'] ) . '>',
			'From: ' . str_replace( array( "\r", "\n", '"' ), '', '' !== $settings['from_name'] ? $settings['from_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . ' <' . $this->from_email( $settings ) . '>',
		);

		$args = array(
			'to'      => $row['email'],
			'subject' => $message['subject'],
			'body'    => $message['body'],
			'headers' => $headers,
		);

		/**
		 * Short-circuit the send (tests, alternative mailers). Return true/false to bypass wp_mail().
		 *
		 * @param bool|null           $result Null to send normally.
		 * @param array<string,mixed> $args   to, subject, body, headers.
		 */
		$result = apply_filters( 'ace_basket_recovery_pre_send', null, $args );
		$result = null === $result ? wp_mail( $args['to'], $args['subject'], $args['body'], $args['headers'] ) : (bool) $result;

		if ( $result ) {
			$this->baskets->set_status( (int) $id, 'sent' );
			$this->baskets->record_event( (int) $id, 'email_sent' );
			$this->log( 'info', sprintf( 'Quote email sent for basket %d (%d lines, token %s).', (int) $id, count( $lines ), substr( $row['token'], 0, 6 ) ) );
		} else {
			$this->log( 'error', 'Quote email failed for basket ' . (int) $id . '.' );
		}
	}

	/**
	 * Has the shopper ordered since the basket was captured?
	 *
	 * @param array<string, mixed> $row Basket row.
	 * @return bool
	 */
	private function has_later_order( array $row ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'billing_email' => $row['email'],
				'date_created'  => '>=' . strtotime( $row['created_at'] . ' UTC' ),
				'status'        => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' ),
				'limit'         => 1,
				'return'        => 'ids',
			)
		);

		return ! empty( $orders );
	}

	/**
	 * Build subject and HTML body.
	 *
	 * @param array<string, mixed>             $row      Basket row.
	 * @param array<int, array<string, mixed>> $lines    Snapshot lines.
	 * @param array<string, mixed>             $settings Settings.
	 * @return array{subject:string, body:string}
	 */
	private function build_email( array $row, array $lines, array $settings ): array {
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = str_replace( '{site}', $site, (string) $settings['subject'] );
		$valid   = wp_date( 'j F Y', time() + ( self::QUOTE_DAYS * DAY_IN_SECONDS ) );
		$total   = 0.0;
		$rows    = '';

		foreach ( $lines as $line ) {
			$qty    = max( 1, (int) $line['quantity'] );
			$ex_vat = (float) $line['line_ex_vat'];
			$total += $ex_vat;
			$name   = esc_html( $line['name'] );
			if ( ! empty( $line['url'] ) ) {
				$name = '<a href="' . esc_url( $line['url'] ) . '" style="color:#225570;">' . $name . '</a>';
			}
			$rows .= '<tr><td style="padding:8px;border-bottom:1px solid #e4e7ea;">' . $name . ( ! empty( $line['sku'] ) ? '<br><span style="color:#6b747b;font-size:12px;">SKU ' . esc_html( $line['sku'] ) . '</span>' : '' ) . '</td>'
				. '<td style="padding:8px;border-bottom:1px solid #e4e7ea;text-align:center;">' . $qty . '</td>'
				. '<td style="padding:8px;border-bottom:1px solid #e4e7ea;text-align:right;">' . wp_kses_post( wc_price( $ex_vat / $qty ) ) . '</td>'
				. '<td style="padding:8px;border-bottom:1px solid #e4e7ea;text-align:right;">' . wp_kses_post( wc_price( $ex_vat ) ) . '</td></tr>';
		}

		$contact = array();
		if ( '' !== $settings['contact_phone'] ) {
			$contact[] = esc_html( $settings['contact_phone'] );
		}
		if ( is_email( $settings['contact_email'] ) ) {
			$contact[] = '<a href="mailto:' . esc_attr( $settings['contact_email'] ) . '" style="color:#225570;">' . esc_html( $settings['contact_email'] ) . '</a>';
		}

		$restore = esc_url( $this->go_url( $row['token'], 'restore', 'email' ) );
		$signup  = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ? esc_url( $this->go_url( $row['token'], 'signup', 'email' ) ) : '';
		$optout  = esc_url( $this->link( 'ace_basket_optout', $row['token'] ) );
		$pixel   = $settings['open_tracking'] ? '<img src="' . esc_url( $this->link( 'ace_basket_px', $row['token'] ) ) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">' : '';

		$body  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#111;max-width:640px;margin:0 auto;background:#fff;">';
		$body .= '<div style="background:#364048;color:#fff;padding:16px 20px;font-size:18px;font-weight:bold;">' . esc_html( $site ) . '</div>';
		$body .= '<div style="padding:20px;">';
		$body .= '<p>' . nl2br( esc_html( $settings['intro'] ) ) . '</p>';
		$body .= '<table style="width:100%;border-collapse:collapse;font-size:14px;"><thead><tr style="text-align:left;background:#f3f5f6;"><th style="padding:8px;">Item</th><th style="padding:8px;text-align:center;">Qty</th><th style="padding:8px;text-align:right;">Unit (ex VAT)</th><th style="padding:8px;text-align:right;">Total (ex VAT)</th></tr></thead><tbody>' . $rows . '</tbody>';
		$body .= '<tfoot><tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;">Subtotal excluding VAT</td><td style="padding:8px;text-align:right;font-weight:bold;">' . wp_kses_post( wc_price( $total ) ) . '</td></tr></tfoot></table>';
		$body .= '<p style="margin:24px 0;"><a href="' . $restore . '" style="background:#69B853;color:#111;text-decoration:none;font-weight:bold;padding:12px 22px;display:inline-block;">Return to your basket</a></p>';
		$body .= '<p>This is a quote valid for ' . self::QUOTE_DAYS . ' days, until ' . esc_html( $valid ) . '. Prices exclude VAT and delivery, and are subject to stock.</p>';
		if ( '' !== $signup ) {
			$body .= '<p>Or <a href="' . $signup . '" style="color:#225570;">create an account</a> to keep baskets, see quotes and reorder.</p>';
		}
		if ( $contact ) {
			$body .= '<p>Questions? Contact us on ' . implode( ' or ', $contact ) . '.</p>';
		}
		$body .= '<p style="font-size:12px;color:#6b747b;border-top:1px solid #e4e7ea;padding-top:12px;">We are sending this one-off reminder because you entered your email at checkout and did not complete your order. <a href="' . $optout . '" style="color:#6b747b;">Opt out and delete this basket</a>.</p>';
		$body .= '</div>' . $pixel . '</div>';

		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	/**
	 * Tracking pixel, signed redirect links and opt-out.
	 *
	 * @return void
	 */
	public function handle_links(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$pixel  = isset( $_GET['ace_basket_px'] ) ? sanitize_key( wp_unslash( $_GET['ace_basket_px'] ) ) : '';
		$go     = isset( $_GET['ace_basket_go'] ) ? sanitize_key( wp_unslash( $_GET['ace_basket_go'] ) ) : '';
		$optout = isset( $_REQUEST['ace_basket_optout'] ) ? sanitize_key( wp_unslash( $_REQUEST['ace_basket_optout'] ) ) : '';
		$to     = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '';
		$origin = isset( $_GET['o'] ) ? sanitize_key( wp_unslash( $_GET['o'] ) ) : '';
		$sig    = isset( $_GET['s'] ) ? sanitize_key( wp_unslash( $_GET['s'] ) ) : '';
		$confirm = isset( $_POST['ace_basket_confirm'] );
		// phpcs:enable

		if ( '' === $pixel && '' === $go && '' === $optout ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		if ( '' !== $pixel ) {
			$row = 32 === strlen( $pixel ) ? $this->baskets->find_by_token( $pixel ) : null;
			if ( $row && $this->settings()['open_tracking'] && 'optout' !== $row['status'] ) {
				$this->baskets->record_event( (int) $row['id'], 'email_opened' );
			}
			header( 'Content-Type: image/gif' );
			echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if ( '' !== $optout ) {
			$row = 32 === strlen( $optout ) ? $this->baskets->find_by_token( $optout ) : null;
			if ( $row && $confirm && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$this->baskets->record_event( (int) $row['id'], 'opt_out' );
				$this->baskets->opt_out( (int) $row['id'] );
				$this->log( 'info', 'Basket ' . (int) $row['id'] . ' opted out.' );
				wp_die( esc_html__( 'Done. Your basket has been deleted and we will not email you about it again.', 'adaptive-customer-engagement' ), esc_html__( 'Opted out', 'adaptive-customer-engagement' ), array( 'response' => 200 ) );
			}
			if ( $row ) {
				// A GET shows a button, so mail scanners that prefetch links cannot opt anyone out by accident.
				wp_die(
					'<form method="post"><p>' . esc_html__( 'Delete your saved basket and stop these reminders?', 'adaptive-customer-engagement' ) . '</p><input type="hidden" name="ace_basket_optout" value="' . esc_attr( $optout ) . '"><button type="submit" name="ace_basket_confirm" value="1">' . esc_html__( 'Yes, opt me out', 'adaptive-customer-engagement' ) . '</button></form>',
					esc_html__( 'Opt out', 'adaptive-customer-engagement' ),
					array( 'response' => 200 )
				);
			}
			wp_die( esc_html__( 'This link is no longer valid.', 'adaptive-customer-engagement' ), '', array( 'response' => 404 ) );
		}

		// Signed redirect: record the click, then land on the destination tagged for GA4.
		$row = 32 === strlen( $go ) ? $this->baskets->find_by_token( $go ) : null;
		if ( ! $row || ! in_array( $to, array( 'restore', 'signup' ), true ) || ! hash_equals( $this->sign( $go, $to, $origin ), $sig ) || 'optout' === $row['status'] ) {
			wp_safe_redirect( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) );
			exit;
		}

		$this->baskets->record_event( (int) $row['id'], 'link_clicked', $to );
		$utm = array(
			'utm_source'   => 'basket-recovery',
			'utm_medium'   => 'email' === $origin ? 'email' : 'site',
			'utm_campaign' => 'quote',
		);

		if ( 'signup' === $to ) {
			wp_safe_redirect( add_query_arg( $utm, wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}

		$started = ! empty( $row['sent_at'] ) ? $row['sent_at'] : $row['created_at'];
		if ( strtotime( $started . ' UTC' ) + ( self::QUOTE_DAYS * DAY_IN_SECONDS ) < time() ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}

		if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart ) {
			return;
		}

		$lines = json_decode( (string) $row['cart'], true );
		WC()->cart->empty_cart();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			if ( ! empty( $line['composite'] ) ) {
				continue; // Configured composites cannot be rebuilt from a token; the email links to the product.
			}
			WC()->cart->add_to_cart( (int) $line['product_id'], (int) $line['quantity'], (int) $line['variation_id'], is_array( $line['variation'] ) ? $line['variation'] : array() );
		}

		$this->baskets->record_event( (int) $row['id'], 'basket_restored', $origin );
		$this->log( 'info', 'Basket ' . (int) $row['id'] . ' restored from ' . ( '' !== $origin ? $origin : 'link' ) . '.' );
		wp_safe_redirect( add_query_arg( $utm, wc_get_cart_url() ) );
		exit;
	}

	/**
	 * Delete baskets past the retention period.
	 *
	 * @return void
	 */
	public function purge(): void {
		$days    = (int) ( $this->settings()['retention_days'] ?? self::RETENTION_DAYS );
		$deleted = $this->baskets->purge( max( 7, $days ) );
		if ( $deleted > 0 ) {
			$this->log( 'info', 'Purged ' . $deleted . ' baskets older than ' . $days . ' days.' );
		}
	}

	/**
	 * Admin stats route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'adaptive-customer-engagement/v1',
			'/basket-recovery/stats',
			array(
				'methods'             => 'GET',
				'callback'            => function ( \WP_REST_Request $request ) {
					return rest_ensure_response( $this->stats( (string) $request->get_param( 'from' ), (string) $request->get_param( 'to' ) ) );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( \ACE\AdaptiveCustomerEngagement\Security\Capabilities::MANAGE );
				},
			)
		);
	}

	/**
	 * Counts of distinct baskets per step between two dates (site timezone dates, defaulting to the last 30 days).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string, mixed>
	 */
	public function stats( string $from = '', string $to = '' ): array {
		$to_ts   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? strtotime( $to . ' 23:59:59 UTC' ) : time();
		$from_ts = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? strtotime( $from . ' 00:00:00 UTC' ) : $to_ts - ( 30 * DAY_IN_SECONDS );

		return array(
			'from'   => gmdate( 'Y-m-d', $from_ts ),
			'to'     => gmdate( 'Y-m-d', $to_ts ),
			'counts' => $this->baskets->stats( gmdate( 'Y-m-d H:i:s', $from_ts ), gmdate( 'Y-m-d H:i:s', $to_ts ) ),
		);
	}

	/**
	 * Sender address: the saved one, else enquiries@ on the admin email's domain.
	 *
	 * @param array<string, mixed> $settings Basket settings.
	 * @return string
	 */
	public static function from_email( array $settings ): string {
		if ( ! empty( $settings['from_email'] ) && is_email( $settings['from_email'] ) ) {
			return $settings['from_email'];
		}

		$admin  = (string) get_option( 'admin_email' );
		$domain = false !== strpos( $admin, '@' ) ? substr( strrchr( $admin, '@' ), 1 ) : wp_parse_url( home_url(), PHP_URL_HOST );

		return 'enquiries@' . $domain;
	}

	/**
	 * Signed redirect link.
	 *
	 * @param string $token  Basket token.
	 * @param string $to     restore or signup.
	 * @param string $origin email or nudge.
	 * @return string
	 */
	public function go_url( string $token, string $to, string $origin ): string {
		return add_query_arg(
			array(
				'ace_basket_go' => $token,
				'to'            => $to,
				'o'             => $origin,
				's'             => $this->sign( $token, $to, $origin ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Link signature.
	 *
	 * @param string $token  Token.
	 * @param string $to     Destination key.
	 * @param string $origin Origin key.
	 * @return string
	 */
	private function sign( string $token, string $to, string $origin ): string {
		return substr( hash_hmac( 'sha256', $token . '|' . $to . '|' . $origin, Settings::get_hash_salt() ), 0, 20 );
	}

	/**
	 * Prepend the nudge to the cart block.
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	public function prepend_nudge_cart( $content ) {
		return $this->nudge_html( 'cart' ) . $content;
	}

	/**
	 * Prepend the nudge to the checkout block.
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	public function prepend_nudge_checkout( $content ) {
		return $this->nudge_html( 'checkout' ) . $content;
	}

	/**
	 * Classic cart template.
	 *
	 * @return void
	 */
	public function echo_nudge_cart(): void {
		echo $this->nudge_html( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Classic checkout template.
	 *
	 * @return void
	 */
	public function echo_nudge_checkout(): void {
		echo $this->nudge_html( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Nudge markup for a guest with a basket. Rendered once per request, outside any block's React root.
	 *
	 * @param string $context cart or checkout.
	 * @return string
	 */
	private function nudge_html( string $context ): string {
		static $rendered = false;

		if ( $rendered || is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}

		$rendered = true;
		$state    = 'form';
		$restore  = '';
		$row      = WC()->session ? $this->baskets->find_by_session( (string) WC()->session->get_customer_id() ) : null;
		if ( $row && ! empty( $row['email'] ) && 'optout' !== $row['status'] ) {
			$state   = 'saved';
			$restore = $this->go_url( $row['token'], 'restore', 'nudge' );
		}

		$register = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
		$signup   = $register ? wc_get_page_permalink( 'myaccount' ) : '';
		if ( $register && $row ) {
			$signup = $this->go_url( $row['token'], 'signup', 'nudge' );
		}

		wp_enqueue_script( 'ace-basket-nudge', ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_URL . 'assets/basket-nudge.js', array(), ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );

		$css = (string) file_get_contents( ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR . 'assets/basket-nudge.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		ob_start();
		?>
		<style id="ace-basket-nudge-css"><?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
		<div class="ace-basket-nudge" data-context="<?php echo esc_attr( $context ); ?>" data-state="<?php echo esc_attr( $state ); ?>" data-ajax="<?php echo esc_url( \WC_AJAX::get_endpoint( 'ace_basket_save' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ace_basket_nudge' ) ); ?>" data-signup="<?php echo esc_url( $signup ); ?>">
			<div class="ace-basket-nudge__form-state">
				<p class="ace-basket-nudge__title"><?php esc_html_e( "Want to come back to this later? Add your email and we'll keep your basket for 14 days.", 'adaptive-customer-engagement' ); ?></p>
				<form class="ace-basket-nudge__form" novalidate>
					<label class="ace-basket-nudge__label" for="ace-basket-nudge-email-<?php echo esc_attr( $context ); ?>"><?php esc_html_e( 'Email address', 'adaptive-customer-engagement' ); ?></label>
					<input type="email" id="ace-basket-nudge-email-<?php echo esc_attr( $context ); ?>" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'Your email address', 'adaptive-customer-engagement' ); ?>">
					<button type="submit"><?php esc_html_e( 'Save my basket', 'adaptive-customer-engagement' ); ?></button>
				</form>
				<p class="ace-basket-nudge__error" role="alert" hidden></p>
				<?php if ( '' !== $signup ) : ?>
				<p class="ace-basket-nudge__account"><?php esc_html_e( 'Or', 'adaptive-customer-engagement' ); ?> <a class="ace-basket-nudge__signup" href="<?php echo esc_url( $signup ); ?>"><?php esc_html_e( 'create an account', 'adaptive-customer-engagement' ); ?></a> <?php esc_html_e( 'to keep baskets, see quotes and reorder.', 'adaptive-customer-engagement' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="ace-basket-nudge__saved-state" role="status" aria-live="polite">
				<p class="ace-basket-nudge__title"><?php esc_html_e( "Basket saved. We'll keep it for 14 days.", 'adaptive-customer-engagement' ); ?>
					<a class="ace-basket-nudge__restore" href="<?php echo esc_url( $restore ); ?>"><?php esc_html_e( 'Return to this basket', 'adaptive-customer-engagement' ); ?></a>
					<?php if ( '' !== $signup ) : ?>
					&middot; <a class="ace-basket-nudge__signup" href="<?php echo esc_url( $signup ); ?>"><?php esc_html_e( 'Create an account', 'adaptive-customer-engagement' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * wc-ajax handler for the nudge form.
	 *
	 * @return void
	 */
	public function ajax_save(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$raw   = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$src   = 'checkout_field' === $raw ? 'checkout_field' : ( 'checkout' === $raw ? 'checkout_nudge' : 'cart_nudge' );
		// phpcs:enable

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ace_basket_nudge' ) || is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please refresh the page and try again.', 'adaptive-customer-engagement' ) ), 403 );
		}
		if ( ! ( new \ACE\AdaptiveCustomerEngagement\Security\RateLimiter() )->allow( 'basket_nudge_' . $ip, 20, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many attempts. Please try again later.', 'adaptive-customer-engagement' ) ), 429 );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'adaptive-customer-engagement' ) ), 400 );
		}

		if ( ! WC()->cart || ! WC()->session ) {
			wc_load_cart();
		}
		if ( WC()->customer ) {
			WC()->customer->set_billing_email( $email ); // Pre-fills the checkout.
			WC()->customer->save();
		}

		$row = $this->capture( $email, $src );
		if ( ! $row || 'optout' === $row['status'] ) {
			wp_send_json_error( array( 'message' => __( 'We could not save your basket. Your items are still in the basket.', 'adaptive-customer-engagement' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'restore' => $this->go_url( $row['token'], 'restore', 'nudge' ),
				'signup'  => 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ? $this->go_url( $row['token'], 'signup', 'nudge' ) : '',
			)
		);
	}

	/**
	 * Build a token link on the home URL.
	 *
	 * @param string $arg   Query arg.
	 * @param string $token Token.
	 * @return string
	 */
	private function link( string $arg, string $token ): string {
		return add_query_arg( $arg, $token, home_url( '/' ) );
	}

	/**
	 * Case-insensitive email hash, salted with the plugin's site salt.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function hash( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), Settings::get_hash_salt() );
	}

	/**
	 * Write to the WooCommerce log.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @return void
	 */
	private function log( string $level, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}
}
