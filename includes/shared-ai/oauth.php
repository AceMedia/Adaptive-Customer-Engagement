<?php
namespace AceMedia\SharedAI\V1;
defined( 'ABSPATH' ) || exit;
/** Strict identity validation for the documented public-client flow. */
final class OAuth {
	public static function decode( string $value ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) { return false; }
		return base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
	}
	private static function der( int $tag, string $value ): string {
		$length = strlen( $value );
		$encoded = chr( $length );
		if ( $length > 127 ) {
			$bytes = ''; for ( $n = $length; $n > 0; $n >>= 8 ) { $bytes = chr( $n & 255 ) . $bytes; }
			$encoded = chr( 128 | strlen( $bytes ) ) . $bytes;
		}
		return chr( $tag ) . $encoded . $value;
	}
	public static function validate( string $jwt, string $client, string $nonce, array $jwks, int $now, string $subject = '' ) {
		$fail = static function () { return new \WP_Error( 'ace_ai_identity_invalid', 'The ChatGPT account identity could not be verified. Start a new sign-in.' ); };
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 || '' === $client || 'dynamic_agent_client' === $client || '' === $nonce ) { return $fail(); }
		$header = json_decode( self::decode( $parts[0] ) ?: '', true );
		$claims = json_decode( self::decode( $parts[1] ) ?: '', true );
		$signature = self::decode( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $claims ) || false === $signature || 'RS256' !== ( $header['alg'] ?? '' ) || ! is_string( $header['kid'] ?? null ) ) { return $fail(); }
		$key = null;
		foreach ( $jwks['keys'] ?? array() as $candidate ) {
			if ( ( $candidate['kid'] ?? '' ) === $header['kid'] && 'RSA' === ( $candidate['kty'] ?? '' ) && in_array( $candidate['use'] ?? 'sig', array( 'sig' ), true ) && 'RS256' === ( $candidate['alg'] ?? 'RS256' ) ) { $key = $candidate; break; }
		}
		if ( ! $key ) { return $fail(); }
		$n = self::decode( $key['n'] ?? '' ); $e = self::decode( $key['e'] ?? '' );
		if ( ! $n || ! $e || strlen( $n ) < 256 ) { return $fail(); }
		$n = ( ord( $n[0] ) & 128 ? "\0" : '' ) . $n;
		$e = ( ord( $e[0] ) & 128 ? "\0" : '' ) . $e;
		$rsa = self::der( 48, self::der( 2, $n ) . self::der( 2, $e ) );
		$spki = self::der( 48, hex2bin( '300d06092a864886f70d0101010500' ) . self::der( 3, "\0" . $rsa ) );
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		if ( ! function_exists( 'openssl_verify' ) || 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256 ) ) { return $fail(); }
		$audience = $claims['aud'] ?? array(); $audience = is_array( $audience ) ? $audience : array( $audience );
		if ( 'https://auth.openai.com' !== ( $claims['iss'] ?? '' ) || ! in_array( $client, $audience, true ) || ( count( $audience ) > 1 && $client !== ( $claims['azp'] ?? '' ) ) || ( isset( $claims['azp'] ) && $client !== $claims['azp'] ) || ! is_numeric( $claims['exp'] ?? null ) || (int) $claims['exp'] <= $now || (int) ( $claims['iat'] ?? 0 ) > $now + 60 || (int) ( $claims['nbf'] ?? 0 ) > $now + 60 || ! is_string( $claims['nonce'] ?? null ) || ! hash_equals( $nonce, $claims['nonce'] ) || ! is_string( $claims['sub'] ?? null ) || '' === $claims['sub'] || ( '' !== $subject && ! hash_equals( $subject, $claims['sub'] ) ) ) { return $fail(); }
		return $claims;
	}
}
