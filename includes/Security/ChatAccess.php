<?php
/**
 * Access to the frontend assistant and its opt-in guest beta preview.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Security;

use ACE\AdaptiveCustomerEngagement\AI\ChatClientFactory;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class ChatAccess {
	private const GRANT_VERSION = 'v1';
	private const GRANT_LIFETIME = HOUR_IN_SECONDS;
	private const GRANT_HEADER = 'x-ace-chat-beta';

	/** Keep a copied site's grants separate even when its salts and blog ID match. */
	private static function grant_context(): string {
		$home = wp_parse_url( home_url( '/' ) );
		$host = strtolower( (string) ( $home['host'] ?? '' ) );
		$scheme = strtolower( (string) ( $home['scheme'] ?? '' ) );
		$port = isset( $home['port'] ) ? ':' . (int) $home['port'] : '';
		$path = '/' . trim( (string) ( $home['path'] ?? '/' ), '/' );

		return get_current_blog_id() . '|' . $scheme . '://' . $host . $port . $path;
	}

	/** The beta is deliberately limited to the network's main site. */
	public static function beta_enabled( array $ai_agent ): bool {
		return ! empty( $ai_agent['frontend_chat_beta_enabled'] )
			&& ! empty( $ai_agent['frontend_chat_admin_only'] )
			&& ( ! is_multisite() || is_main_site() );
	}

	/** A bare /?beta or /?beta=1 on the main homepage is the only guest entry. */
	public static function is_beta_query_request(): bool {
		if ( is_multisite() && ! is_main_site() ) {
			return false;
		}

		if ( ! array_key_exists( 'beta', $_GET ) || ! is_scalar( $_GET['beta'] ) || ! in_array( (string) $_GET['beta'], array( '', '1' ), true ) ) {
			return false;
		}

		$requested_path = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$home_path      = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return is_string( $requested_path ) && is_string( $home_path ) && untrailingslashit( $requested_path ) === untrailingslashit( $home_path );
	}

	public static function is_beta_preview_page( array $ai_agent ): bool {
		return self::beta_enabled( $ai_agent ) && self::is_beta_query_request() && is_front_page();
	}

	/** The theme may load the chat bundle before consent only for an active chat-only preview. */
	public static function beta_widget_ready( array $ai_agent ): bool {
		return self::is_beta_preview_page( $ai_agent )
			&& ! empty( $ai_agent['enabled'] )
			&& ! empty( $ai_agent['frontend_chat_enabled'] )
			&& '' !== trim( (string) ChatClientFactory::resolve( $ai_agent )['api_key'] );
	}

	/**
	 * Mint a signed one-hour grant for the uncached beta page. It is sent in a
	 * request header, never a URL or persistent cookie.
	 */
	public static function issue_grant(): string {
		$expires = time() + self::GRANT_LIFETIME;
		$nonce   = bin2hex( random_bytes( 12 ) );
		$body    = self::GRANT_VERSION . '.' . $expires . '.' . $nonce;
		$mac     = hash_hmac( 'sha256', self::grant_context() . '|' . $body, wp_salt( 'auth' ) );

		return $body . '.' . $mac;
	}

	public static function valid_grant( string $grant ): bool {
		if ( ! preg_match( '/^v1\.(\d{10})\.([a-f0-9]{24})\.([a-f0-9]{64})$/D', $grant, $matches ) ) {
			return false;
		}

		$expires = (int) $matches[1];
		if ( $expires <= time() || $expires > time() + self::GRANT_LIFETIME ) {
			return false;
		}

		$body = self::GRANT_VERSION . '.' . $matches[1] . '.' . $matches[2];
		$mac  = hash_hmac( 'sha256', self::grant_context() . '|' . $body, wp_salt( 'auth' ) );

		return hash_equals( $mac, $matches[3] );
	}

	/** Admins keep normal access; guests need the signed beta page grant. */
	public static function can_use_chat( array $ai_agent, ?WP_REST_Request $request = null ): bool {
		if ( empty( $ai_agent['frontend_chat_admin_only'] ) || current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return self::beta_enabled( $ai_agent )
			&& $request instanceof WP_REST_Request
			&& self::valid_grant( (string) $request->get_header( self::GRANT_HEADER ) );
	}

	/** The preview is private even if its feature toggle has not yet been saved. */
	public static function protect_preview_response(): void {
		if ( ! self::is_beta_query_request() ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}

	public static function noindex_preview( array $robots ): array {
		if ( self::is_beta_query_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}
}
