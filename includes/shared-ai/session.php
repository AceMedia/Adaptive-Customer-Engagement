<?php
namespace AceMedia\SharedAI\V1;
defined( 'ABSPATH' ) || exit;
/** One refresh owner per scope; compare-and-swap prevents reconnect/disconnect races. */
final class Session {
	public static function access( callable $load, callable $cas, callable $lock, callable $unlock, callable $refresh, int $now ) {
		$current = $load();
		if ( is_wp_error( $current ) ) { return $current; }
		if ( ! self::valid( $current ) ) { return new \WP_Error( 'ace_ai_reconnect', 'Reconnect the ChatGPT account.' ); }
		if ( (int) $current['expires_at'] > $now + 60 ) { return $current['access_token']; }
		$owner = $lock();
		if ( ! $owner ) { return new \WP_Error( 'ace_ai_refresh_busy', 'This connection is already refreshing. Try again shortly.' ); }
		try {
			$current = $load();
			if ( is_wp_error( $current ) || ! self::valid( $current ) ) { return new \WP_Error( 'ace_ai_reconnect', 'Reconnect the ChatGPT account.' ); }
			if ( (int) $current['expires_at'] > $now + 60 ) { return $current['access_token']; }
			$result = $refresh( $current );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( 'invalid_grant' === ( $result['error'] ?? '' ) ) {
				$invalid = $current; unset( $invalid['access_token'], $invalid['refresh_token'], $invalid['id_token'] );
				$cas( $current, $invalid );
				return new \WP_Error( 'ace_ai_reconnect', 'The renewable session has ended. Reconnect the ChatGPT account.' );
			}
			$scopes = isset( $result['scope'] ) && is_string( $result['scope'] ) ? preg_split( '/\s+/', trim( $result['scope'] ) ) : $current['scopes'];
			if ( ! is_string( $result['access_token'] ?? null ) || '' === $result['access_token'] || ! is_string( $result['refresh_token'] ?? null ) || '' === $result['refresh_token'] || 'bearer' !== strtolower( $result['token_type'] ?? '' ) || ! is_numeric( $result['expires_in'] ?? null ) || (int) $result['expires_in'] <= 0 || ! in_array( 'chatgpt.tokens.use.direct', $scopes, true ) ) {
				return new \WP_Error( 'ace_ai_refresh_invalid', 'ChatGPT did not return a usable renewed session. Reconnect before making more requests.' );
			}
			$next = $current;
			$next['access_token'] = $result['access_token']; $next['refresh_token'] = $result['refresh_token'];
			$next['expires_at'] = $now + (int) $result['expires_in']; $next['scopes'] = $scopes;
			if ( ! $cas( $current, $next ) ) { return new \WP_Error( 'ace_ai_connection_changed', 'The connection changed during refresh. No stale credentials were saved.' ); }
			return $next['access_token'];
		} finally { $unlock( $owner ); }
	}
	private static function valid( $record ): bool {
		return is_array( $record ) && is_string( $record['access_token'] ?? null ) && '' !== $record['access_token'] && is_string( $record['refresh_token'] ?? null ) && '' !== $record['refresh_token'] && is_string( $record['client_id'] ?? null ) && '' !== $record['client_id'] && 'dynamic_agent_client' !== $record['client_id'] && is_array( $record['scopes'] ?? null ) && in_array( 'chatgpt.tokens.use.direct', $record['scopes'], true );
	}
}
