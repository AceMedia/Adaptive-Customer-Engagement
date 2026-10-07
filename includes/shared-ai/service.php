<?php
/** Site/network connection policy. No account credentials are scoped to WP users. */
namespace AceMedia\SharedAI\V1;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/oauth.php';
require_once __DIR__ . '/responses.php';
require_once __DIR__ . '/session.php';
final class Service {
	const OPTION = 'ace_ai_connection_v1';
	private $force_network;
	public function __construct( bool $force_network = false ) { $this->force_network = $force_network; }

	/** Re-resolve on every call: switch_to_blog() and cron must not reuse another site's state. */
	public function resolve(): array {
		$site = $this->force_network ? null : get_option( self::OPTION, null );
		$network_id = is_multisite() ? (int) get_site( get_current_blog_id() )->network_id : 0;
		$network = is_multisite() ? get_network_option( $network_id, self::OPTION, null ) : null;
		return self::resolve_records( $site, $network, is_multisite(), time() );
	}

	/** Pure policy also used by regression checks. Secrets remain internal to server-side consumers. */
	public static function resolve_records( $site, $network, bool $multisite, int $now ): array {
		if ( null === $site && null === $network ) {
			return array( 'status' => 'unmanaged', 'scope' => 'site', 'mode' => 'legacy' );
		}
		$mode = is_array( $site ) ? ( $site['mode'] ?? 'disabled' ) : 'inherit';
		$scope = 'site';
		if ( 'disabled' === $mode ) {
			return array( 'status' => 'disabled', 'scope' => $scope, 'mode' => $mode );
		}
		if ( 'inherit' === $mode && $multisite ) {
			$scope = 'network';
			$record = $network;
		} elseif ( 'own' === $mode ) {
			$record = $site;
		} else {
			return array( 'status' => 'unavailable', 'scope' => $scope, 'mode' => $mode );
		}
		$base = array( 'scope' => $scope, 'mode' => $mode );
		if ( is_array( $record ) && 'disabled' === ( $record['mode'] ?? '' ) ) {
			return $base + array( 'status' => 'disabled' );
		}
		if ( ! is_array( $record ) || 'own' !== ( $record['mode'] ?? '' ) ) {
			return $base + array( 'status' => 'unavailable' );
		}
		if ( ! empty( $record['expires_at'] ) && $now >= (int) $record['expires_at'] ) {
			return $base + array( 'status' => 'expired' );
		}
		if ( ! in_array( $record['provider'] ?? '', array( 'api_key', 'chatgpt' ), true ) ) {
			return $base + array( 'status' => 'unsupported' );
		}
		if ( ! is_string( $record['secret'] ?? null ) || '' === $record['secret'] || ! is_array( $record['features'] ?? null ) ) {
			return $base + array( 'status' => 'disconnected' );
		}
		return $base + array( 'status' => 'configured', 'record' => $record );
	}

	/** No secret material in UI, REST or logs. Configured does not imply verified by OpenAI. */
	public function status(): array {
		$resolved = $this->resolve();
		$resolved['provider'] = $resolved['record']['provider'] ?? '';
		$resolved['account_label'] = $resolved['record']['account_label'] ?? '';
		$resolved['model'] = $resolved['record']['model'] ?? '';
		unset( $resolved['record'] );
		return $resolved;
	}

	/** Existing explicit plugin settings continue only before shared management is configured. */
	public function api_key( string $feature, string $legacy = '' ) {
		$resolved = $this->resolve();
		if ( 'unmanaged' === $resolved['status'] ) {
			return $legacy;
		}
		if ( 'configured' !== $resolved['status'] ) {
			return new \WP_Error( 'ace_ai_' . $resolved['status'], 'The shared Ace AI connection is ' . $resolved['status'] . '. Check Ace AI connection settings.' );
		}
		if ( 'chatgpt' === ( $resolved['record']['provider'] ?? '' ) ) {
			return new \WP_Error( 'ace_ai_subscription_transport', 'Use the ChatGPT Responses connection for this request.' );
		}
		if ( ! in_array( $feature, $resolved['record']['features'] ?? array(), true ) ) {
			return new \WP_Error( 'ace_ai_feature_disabled', 'This AI feature is not enabled for the selected connection.' );
		}
		return $this->decrypt( $resolved['record']['secret'] );
	}

	public function save( string $mode, string $key, array $features, bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) || ( $network && ! is_multisite() ) ) {
			return new \WP_Error( 'ace_ai_forbidden', 'You cannot change this connection.' );
		}
		$allowed = $network || ! is_multisite() ? array( 'own', 'disabled' ) : array( 'inherit', 'own', 'disabled' );
		if ( ! in_array( $mode, $allowed, true ) ) {
			return new \WP_Error( 'ace_ai_invalid_mode', 'Choose an available connection mode.' );
		}
		$record = array( 'mode' => $mode, 'updated_at' => time() );
		if ( 'own' === $mode ) {
			$old = $network ? get_network_option( get_current_network_id(), self::OPTION, array() ) : get_option( self::OPTION, array() );
			if ( '' === $key && 'chatgpt' === ( $old['provider'] ?? '' ) ) { return true; }
			if ( '' === $key ) {
				$secret = 'api_key' === ( $old['provider'] ?? '' ) ? ( $old['secret'] ?? '' ) : '';
			} else {
				if ( ! preg_match( '/^sk-[A-Za-z0-9_-]+$/D', $key ) ) {
					return new \WP_Error( 'ace_ai_invalid_key', 'Enter a valid OpenAI API key.' );
				}
				$secret = $this->encrypt( $key );
				if ( is_wp_error( $secret ) ) { return $secret; }
			}
			$record += array( 'provider' => 'api_key', 'secret' => $secret, 'features' => array_values( array_intersect( array( 'text', 'images', 'audio' ), $features ) ) );
		}
		if ( $network ) {
			update_network_option( get_current_network_id(), self::OPTION, $record );
		} else {
			update_option( self::OPTION, $record, false );
		}
		$saved = $network ? get_network_option( get_current_network_id(), self::OPTION, null ) : get_option( self::OPTION, null );
		return $saved === $record ? true : new \WP_Error( 'ace_ai_save_failed', 'The connection settings could not be saved.' );
	}


	public function subscription_selected(): bool {
		$r = $this->resolve();
		return 'chatgpt' === ( $r['record']['provider'] ?? '' );
	}
	public function disconnect_subscription( bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) ) { return new \WP_Error( 'ace_ai_forbidden', 'You cannot change this connection.' ); }
		$record = $network ? get_network_option( get_current_network_id(), self::OPTION, array() ) : get_option( self::OPTION, array() );
		$confirmed = false;
		if ( 'chatgpt' === ( $record['provider'] ?? '' ) ) {
			$plain = $this->decrypt( $record['secret'] ?? '' ); $tokens = is_wp_error( $plain ) ? array() : json_decode( $plain, true );
			if ( ! empty( $tokens['refresh_token'] ) && ! empty( $tokens['client_id'] ) ) {
				$r = wp_remote_post( 'https://auth.openai.com/api/accounts/oauth/revoke', array( 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 8192, 'body' => array( 'token' => $tokens['refresh_token'], 'token_type_hint' => 'refresh_token', 'client_id' => $tokens['client_id'] ) ) );
				$confirmed = ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r );
			}
		}
		$disabled = array( 'mode' => 'disabled', 'updated_at' => time() );
		if ( $network ) { update_network_option( get_current_network_id(), self::OPTION, $disabled ); } else { update_option( self::OPTION, $disabled, false ); }
		return $confirmed ? true : new \WP_Error( 'ace_ai_revocation_unconfirmed', 'Disconnected locally. Remote revocation was not confirmed; disconnect the app in ChatGPT settings as well.' );
	}
	public function host_id(): string {
		$key = 'ace_ai_host_id_v1';
		$network = is_multisite();
		$id = $network ? get_network_option( get_current_network_id(), $key, '' ) : get_option( $key, '' );
		if ( ! $id ) {
			$candidate = 'urn:uuid:' . wp_generate_uuid4();
			if ( $network ) { add_network_option( get_current_network_id(), $key, $candidate ); } else { add_option( $key, $candidate, '', false ); }
			$id = $network ? get_network_option( get_current_network_id(), $key, '' ) : get_option( $key, '' );
		}
		return (string) $id;
	}
	public function import_subscription( array $tokens, bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) || ( $network && ! is_multisite() ) ) { return new \WP_Error( 'ace_ai_forbidden', 'You cannot change this connection.' ); }
		foreach ( array( 'id_token', 'client_id', 'validated_nonce', 'subject', 'token_type' ) as $field ) { if ( ! is_string( $tokens[$field] ?? null ) || '' === $tokens[$field] ) { return new \WP_Error( 'ace_ai_import_invalid', 'The protected credential file is incomplete.' ); } }
		if ( $this->host_id() !== ( $tokens['ext_agent_host_id'] ?? '' ) || ! is_array( $tokens['scopes'] ?? null ) || ! in_array( 'chatgpt.tokens.use.direct', $tokens['scopes'], true ) || ! is_string( $tokens['access_token'] ?? null ) || ! is_string( $tokens['refresh_token'] ?? null ) || empty( $tokens['access_token'] ) || empty( $tokens['refresh_token'] ) || (int) ( $tokens['expires_at'] ?? 0 ) <= time() || 'bearer' !== strtolower( $tokens['token_type'] ?? '' ) ) { return new \WP_Error( 'ace_ai_import_invalid', 'Use a fresh credential file created for this host with ChatGPT plan permission.' ); }
		$j = wp_remote_get( 'https://auth.openai.com/.well-known/jwks.json', array( 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 131072 ) );
		if ( is_wp_error( $j ) || 200 !== wp_remote_retrieve_response_code( $j ) ) { return new \WP_Error( 'ace_ai_identity_unavailable', 'OpenAI identity verification is unavailable. Try importing again shortly.' ); }
		$claims = OAuth::validate( (string) ( $tokens['id_token'] ?? '' ), (string) ( $tokens['client_id'] ?? '' ), (string) ( $tokens['validated_nonce'] ?? '' ), json_decode( wp_remote_retrieve_body( $j ), true ) ?: array(), time(), (string) ( $tokens['subject'] ?? '' ) );
		if ( is_wp_error( $claims ) ) { return $claims; }
		$secret = $this->encrypt( wp_json_encode( $tokens ) );
		if ( is_wp_error( $secret ) ) { return $secret; }
		$record = array( 'mode' => 'own', 'provider' => 'chatgpt', 'features' => array( 'text' ), 'secret' => $secret, 'account_label' => sanitize_text_field( (string) ( $claims['email'] ?? 'ChatGPT account' ) ), 'updated_at' => time() );
		if ( $network ) { update_network_option( get_current_network_id(), self::OPTION, $record ); } else { update_option( self::OPTION, $record, false ); }
		return true;
	}
	/** Return the available account-specific model catalogue without exposing its token. */
	public function subscription_models() {
		$token = $this->subscription_token(); if ( is_wp_error( $token ) ) { return $token; }
		$r = wp_remote_get( 'https://api.openai.com/v1/models', array( 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 524288, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) { return new \WP_Error( 'ace_ai_models_failed', 'This ChatGPT account’s models could not be loaded.' ); }
		$data = json_decode( wp_remote_retrieve_body( $r ), true ); $models = array();
		foreach ( $data['models'] ?? array() as $model ) { if ( 'list' === ( $model['visibility'] ?? '' ) && is_string( $model['slug'] ?? null ) ) { $models[] = array( 'id' => $model['slug'], 'label' => $model['display_name'] ?? $model['slug'] ); } }
		return $models;
	}
	public function subscription_text( array $messages, string $model = '' ) {
		$resolved = $this->resolve();
		if ( ! $this->subscription_selected() ) { return new \WP_Error( 'ace_ai_not_connected', 'Select a ChatGPT connection first.' ); }
		$model = (string) ( $resolved['record']['model'] ?? '' );
		if ( '' === $model ) { return new \WP_Error( 'ace_ai_model_required', 'Choose a model from the connected ChatGPT account in Ace AI settings.' ); }
		$token = $this->subscription_token(); if ( is_wp_error( $token ) ) { return $token; }
		$answer = Responses::request( $token, $model, $messages );
		if ( ! is_wp_error( $answer ) ) { $answer['model'] = $model; }
		return $answer;
	}
	public function save_subscription_model( string $model, bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) ) { return new \WP_Error( 'ace_ai_forbidden', 'You cannot change this connection.' ); }
		$r = $network ? get_network_option( get_current_network_id(), self::OPTION, array() ) : get_option( self::OPTION, array() );
		if ( 'chatgpt' !== ( $r['provider'] ?? '' ) ) { return new \WP_Error( 'ace_ai_no_own_subscription', 'Connect a ChatGPT account at this scope first.' ); }
		$models = ( $network ? new self( true ) : $this )->subscription_models(); if ( is_wp_error( $models ) ) { return $models; }
		if ( ! in_array( $model, array_column( $models, 'id' ), true ) ) { return new \WP_Error( 'ace_ai_model_unavailable', 'Choose a model available to this ChatGPT account.' ); }
		$r['model'] = $model;
		if ( $network ) { update_network_option( get_current_network_id(), self::OPTION, $r ); } else { update_option( self::OPTION, $r, false ); }
		return true;
	}
	private function subscription_token() {
		global $wpdb;
		$resolved = $this->resolve();
		if ( 'configured' !== $resolved['status'] || 'chatgpt' !== ( $resolved['record']['provider'] ?? '' ) ) { return new \WP_Error( 'ace_ai_not_connected', 'The ChatGPT connection is unavailable. No fallback was used.' ); }
		$network = 'network' === $resolved['scope'];
		$id = $network ? (int) get_site( get_current_blog_id() )->network_id : get_current_blog_id();
		$table = $network ? $wpdb->sitemeta : $wpdb->options;
		$where = $network ? array( 'site_id' => $id, 'meta_key' => self::OPTION ) : array( 'option_name' => self::OPTION );
		$column = $network ? 'meta_value' : 'option_value';
		$load = function () use ( $wpdb, $network, $id, $table, $column ) {
			$sql = $network ? $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE site_id=%d AND meta_key=%s LIMIT 1", $id, self::OPTION ) : $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name=%s LIMIT 1", self::OPTION );
			$record = maybe_unserialize( $wpdb->get_var( $sql ) );
			if ( ! is_array( $record ) || 'own' !== ( $record['mode'] ?? '' ) || 'chatgpt' !== ( $record['provider'] ?? '' ) ) { return new \WP_Error( 'ace_ai_connection_changed', 'The connection changed.' ); }
			$plain = $this->decrypt( $record['secret'] );
			return is_wp_error( $plain ) ? $plain : json_decode( $plain, true );
		};
		$cas = function ( $old, $next ) use ( $wpdb, $table, $column, $where, $network, $id ) {
			$sql = $network ? $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE site_id=%d AND meta_key=%s LIMIT 1", $id, self::OPTION ) : $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name=%s LIMIT 1", self::OPTION );
			$raw = $wpdb->get_var( $sql ); $record = maybe_unserialize( $raw );
			if ( ! is_array( $record ) || 'chatgpt' !== ( $record['provider'] ?? '' ) ) { return false; }
			$plain = $this->decrypt( $record['secret'] ); if ( is_wp_error( $plain ) || json_decode( $plain, true ) !== $old ) { return false; }
			$secret = $this->encrypt( wp_json_encode( $next ) ); if ( is_wp_error( $secret ) ) { return false; }
			$record['secret'] = $secret;
			$updated = $wpdb->update( $table, array( $column => maybe_serialize( $record ) ), $where + array( $column => $raw ) );
			if ( $network ) { wp_cache_delete( $id . ':' . self::OPTION, 'site-options' ); } else { wp_cache_delete( self::OPTION, 'options' ); }
			return 1 === $updated;
		};
		$lock_name = 'ace_ai_refresh_' . ( $network ? 'network_' : 'site_' ) . $id;
		$locks = $wpdb->base_prefix . 'options';
		$lock = static function () use ( $wpdb, $locks, $lock_name ) {
			$owner = ( time() + 120 ) . ':' . bin2hex( random_bytes( 16 ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$locks} WHERE option_name=%s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d", $lock_name, time() ) );
			return 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$locks} (option_name, option_value, autoload) VALUES (%s,%s,'no')", $lock_name, $owner ) ) ? $owner : false;
		};
		$unlock = static function ( $owner ) use ( $wpdb, $locks, $lock_name ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$locks} WHERE option_name=%s AND option_value=%s", $lock_name, $owner ) ); };
		$refresh = static function ( $tokens ) {
			$r = wp_remote_post( 'https://auth.openai.com/api/accounts/oauth/token', array( 'timeout' => 30, 'redirection' => 0, 'limit_response_size' => 131072, 'body' => array( 'grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token'], 'resource' => 'https://api.openai.com/v1' ) ) );
			if ( is_wp_error( $r ) ) { return new \WP_Error( 'ace_ai_refresh_failed', 'The ChatGPT session could not be refreshed. Try again shortly.' ); }
			$data = json_decode( wp_remote_retrieve_body( $r ), true );
			return is_array( $data ) ? $data : new \WP_Error( 'ace_ai_refresh_failed', 'ChatGPT returned an invalid refresh response.' );
		};
		return Session::access( $load, $cas, $lock, $unlock, $refresh, time() );
	}

	/** Authenticated encryption; moving DB without its WordPress salts requires reconnecting. */
	private function encrypt( string $secret ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error( 'ace_ai_encryption_unavailable', 'OpenSSL is required to save the connection.' );
		}
		$iv = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( $secret, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) { return new \WP_Error( 'ace_ai_encrypt_failed', 'The connection could not be protected.' ); }
		return base64_encode( $iv . $tag . $cipher );
	}

	private function decrypt( string $stored ) {
		$raw = base64_decode( $stored, true );
		if ( ! function_exists( 'openssl_decrypt' ) || false === $raw || strlen( $raw ) < 29 ) {
			return new \WP_Error( 'ace_ai_secret_unavailable', 'Reconnect the shared AI account.' );
		}
		$value = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $value ? new \WP_Error( 'ace_ai_secret_unavailable', 'Reconnect the shared AI account.' ) : $value;
	}
}
