<?php
/**
 * Live chat monitor: admin-bar traffic light + iOS-style take-over notifications.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Admin;

use ACE\AdaptiveCustomerEngagement\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces live chat activity to capable users on the front end and in wp-admin.
 */
final class LiveMonitor {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 90 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the monitor should load for the current user.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::MANAGE ) ) {
			return false;
		}

		return (bool) apply_filters( 'ace_adaptive_customer_engagement_live_monitor_enabled', true );
	}

	/**
	 * Add the traffic-light node to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function add_admin_bar_node( $wp_admin_bar ): void {
		if ( ! $this->is_enabled() || ! is_object( $wp_admin_bar ) ) {
			return;
		}

		$title = '<span class="ace-live-bar" data-ace-live-bar>'
			. '<span class="ace-live-bar__dot" data-ace-live-dot data-state="idle"></span>'
			. '<span class="ace-live-bar__label">' . esc_html__( 'Live chats', 'adaptive-customer-engagement' ) . '</span>'
			. '<span class="ace-live-bar__count" data-ace-live-count>0</span>'
			. '</span>';

		$wp_admin_bar->add_node(
			array(
				'id'    => 'ace-live-chats',
				'title' => $title,
				'href'  => admin_url( 'admin.php?page=adaptive-customer-engagement-dashboard#chats' ),
				'meta'  => array( 'title' => __( 'Visitors currently chatting with the assistant', 'adaptive-customer-engagement' ) ),
			)
		);
	}

	/**
	 * Enqueue the monitor script and styles.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$dir        = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR;
		$url        = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_URL;
		$asset_file = $dir . 'assets/build/monitor.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_VERSION,
		);

		if ( ! file_exists( $dir . 'assets/build/monitor.js' ) ) {
			return;
		}

		if ( file_exists( $dir . 'assets/build/style-monitor.css' ) ) {
			wp_enqueue_style( 'ace-monitor', $url . 'assets/build/style-monitor.css', array(), $asset['version'] );
		}

		wp_enqueue_script( 'ace-monitor', $url . 'assets/build/monitor.js', $asset['dependencies'], $asset['version'], true );

		wp_localize_script(
			'ace-monitor',
			'aceLiveMonitor',
			array(
				'endpoint'     => rest_url( 'adaptive-customer-engagement/v1/admin/monitor' ),
				'restBase'     => rest_url( 'adaptive-customer-engagement/v1' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'pollInterval' => (int) apply_filters( 'ace_adaptive_customer_engagement_monitor_poll_ms', 12000 ),
				'chatPollInterval' => (int) apply_filters( 'ace_adaptive_customer_engagement_monitor_chat_poll_ms', 4000 ),
				'i18n'         => array(
					'takeOver'    => __( 'Take over', 'adaptive-customer-engagement' ),
					'dismiss'     => __( 'Dismiss', 'adaptive-customer-engagement' ),
					'liveChats'   => __( 'Live chats', 'adaptive-customer-engagement' ),
					'justNow'     => __( 'just now', 'adaptive-customer-engagement' ),
					'visitor'     => __( 'Website visitor', 'adaptive-customer-engagement' ),
					'needsHuman'  => __( 'is asking to talk to a person', 'adaptive-customer-engagement' ),
					'newMessage'  => __( 'New message', 'adaptive-customer-engagement' ),
					'takingOver'  => __( 'Taking over…', 'adaptive-customer-engagement' ),
					'youLabel'    => __( 'You', 'adaptive-customer-engagement' ),
					'assistant'   => __( 'Assistant', 'adaptive-customer-engagement' ),
					'replyPlaceholder' => __( 'Type your reply…', 'adaptive-customer-engagement' ),
					'send'        => __( 'Send', 'adaptive-customer-engagement' ),
					'suggestions' => __( 'Suggested replies', 'adaptive-customer-engagement' ),
					'openConsole' => __( 'Open full console', 'adaptive-customer-engagement' ),
					'handBack'    => __( 'Hand back to assistant', 'adaptive-customer-engagement' ),
					'close'       => __( 'Close', 'adaptive-customer-engagement' ),
					'sendFailed'  => __( 'Could not send — try again.', 'adaptive-customer-engagement' ),
					'connectFailed' => __( 'Could not open this chat just now.', 'adaptive-customer-engagement' ),
				),
			)
		);
	}
}
