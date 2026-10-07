<?php
/** Site/network connection policy. No account credentials are scoped to WP users. */
namespace AceMedia\SharedAI\V1;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/oauth.php';
require_once __DIR__ . '/responses.php';
require_once __DIR__ . '/session.php';
final class Service {
	const OPTION = 'ace_ai_connection_v1';
	const BRIDGE_OPTION = 'ace_ai_codex_bridge_v1';
	const BRIDGE_DEFAULT = '/usr/local/bin/ace-codex-bridge';
	const PROVIDERS = array( 'api_key', 'chatgpt', 'codex' );
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
		if ( ! in_array( $record['provider'] ?? '', self::PROVIDERS, true ) ) {
			return $base + array( 'status' => 'unsupported' );
		}
		if ( ! is_array( $record['features'] ?? null ) || ( 'codex' !== $record['provider'] && ( ! is_string( $record['secret'] ?? null ) || '' === $record['secret'] ) ) ) {
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
		$subscription = in_array( $resolved['record']['provider'] ?? '', array( 'chatgpt', 'codex' ), true );
		if ( $subscription && 'text' === $feature ) {
			return new \WP_Error( 'ace_ai_subscription_transport', 'Use the ChatGPT connection for this request.' );
		}
		if ( ! in_array( $feature, $resolved['record']['features'] ?? array(), true ) ) {
			return new \WP_Error( 'ace_ai_feature_disabled', 'This AI feature is not enabled for the selected connection.' );
		}
		if ( $subscription ) {
			// Text comes from the ChatGPT sign-in; images and voice use the API key stored alongside it.
			if ( ! is_string( $resolved['record']['api_secret'] ?? null ) || '' === $resolved['record']['api_secret'] ) {
				return new \WP_Error( 'ace_ai_key_required', 'Images and voice need an OpenAI API key alongside the ChatGPT sign-in. Add one in Ace AI connection settings.' );
			}
			return $this->decrypt( $resolved['record']['api_secret'] );
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
			if ( in_array( $old['provider'] ?? '', array( 'chatgpt', 'codex' ), true ) ) {
				// Signed-in scope: keep the ChatGPT text connection and record which extra features its API key may provide.
				$record = $old; $record['mode'] = 'own'; $record['updated_at'] = time();
				$record['features'] = self::subscription_features( $features );
				if ( '' !== $key ) {
					if ( ! preg_match( '/^sk-[A-Za-z0-9_-]+$/D', $key ) ) { return new \WP_Error( 'ace_ai_invalid_key', 'Enter a valid OpenAI API key.' ); }
					$secret = $this->encrypt( $key ); if ( is_wp_error( $secret ) ) { return $secret; }
					$record['api_secret'] = $secret;
				}
				if ( $network ) { update_network_option( get_current_network_id(), self::OPTION, $record ); } else { update_option( self::OPTION, $record, false ); }
				return true;
			}
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


	/** Text is always provided by the sign-in; images and voice are opt-in extras backed by the API key. */
	private static function subscription_features( array $features ): array {
		return array_values( array_unique( array_merge( array( 'text' ), array_intersect( array( 'images', 'audio' ), array_filter( $features, 'is_string' ) ) ) ) );
	}
	/** Does this scope's signed-in record still need an API key for the extra features it has ticked? */
	public function subscription_key_missing( bool $network = false ): bool {
		$r = $network ? get_network_option( get_current_network_id(), self::OPTION, array() ) : get_option( self::OPTION, array() );
		return is_array( $r ) && in_array( $r['provider'] ?? '', array( 'chatgpt', 'codex' ), true ) && array_diff( $r['features'] ?? array(), array( 'text' ) ) && empty( $r['api_secret'] );
	}
	public function subscription_selected(): bool {
		$r = $this->resolve();
		return in_array( $r['record']['provider'] ?? '', array( 'chatgpt', 'codex' ), true );
	}
	public function disconnect_subscription( bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) ) { return new \WP_Error( 'ace_ai_forbidden', 'You cannot change this connection.' ); }
		$record = $network ? get_network_option( get_current_network_id(), self::OPTION, array() ) : get_option( self::OPTION, array() );
		$confirmed = false;
		if ( 'codex' === ( $record['provider'] ?? '' ) ) {
			$confirmed = ! is_wp_error( $this->codex_run( 'logout', $this->codex_scope( $network ) ) );
		} elseif ( 'chatgpt' === ( $record['provider'] ?? '' ) ) {
			$plain = $this->decrypt( $record['secret'] ?? '' ); $tokens = is_wp_error( $plain ) ? array() : json_decode( $plain, true );
			if ( ! empty( $tokens['refresh_token'] ) && ! empty( $tokens['client_id'] ) ) {
				$r = wp_remote_post( 'https://auth.openai.com/api/accounts/oauth/revoke', array( 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 8192, 'body' => array( 'token' => $tokens['refresh_token'], 'token_type_hint' => 'refresh_token', 'client_id' => $tokens['client_id'] ) ) );
				$confirmed = ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r );
			}
		}
		$disabled = array( 'mode' => 'disabled', 'updated_at' => time() );
		if ( ! empty( $record['api_secret'] ) ) { $disabled += array( 'api_secret' => $record['api_secret'], 'features' => $record['features'] ?? array( 'text' ) ); } // Kept for the next sign-in.
		if ( $network ) { update_network_option( get_current_network_id(), self::OPTION, $disabled ); } else { update_option( self::OPTION, $disabled, false ); }
		return $confirmed ? true : new \WP_Error( 'ace_ai_revocation_unconfirmed', 'Disconnected locally. Remote revocation was not confirmed; disconnect the app in ChatGPT settings as well.' );
	}

	/* ---- Codex CLI bridge: a second, isolated Codex instance on this server signed in from wp-admin. ---- */

	/** The bridge command, e.g. `sudo -H -u ace-ai /usr/local/bin/ace-codex-bridge`. Constant > option > default install path. */
	public function codex_bridge(): string {
		if ( defined( 'ACE_AI_CODEX_BRIDGE' ) && is_string( ACE_AI_CODEX_BRIDGE ) ) { return trim( ACE_AI_CODEX_BRIDGE ); }
		$cmd = is_multisite() ? get_network_option( get_current_network_id(), self::BRIDGE_OPTION, '' ) : get_option( self::BRIDGE_OPTION, '' );
		if ( is_string( $cmd ) && '' !== trim( $cmd ) ) { return trim( $cmd ); }
		return is_executable( self::BRIDGE_DEFAULT ) ? self::BRIDGE_DEFAULT : '';
	}
	public function codex_available(): bool {
		return '' !== $this->codex_bridge() && function_exists( 'proc_open' );
	}
	public function save_codex_bridge( string $cmd, bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) || ( is_multisite() && ! $network ) ) { return new \WP_Error( 'ace_ai_forbidden', 'Only a network administrator can change the bridge command.' ); }
		$cmd = trim( $cmd );
		if ( '' !== $cmd && ! preg_match( '#^[A-Za-z0-9_./=:@ -]+$#D', $cmd ) ) { return new \WP_Error( 'ace_ai_bridge_invalid', 'The bridge command may only contain a path, a sudo prefix and plain arguments.' ); }
		if ( $network ) { update_network_option( get_current_network_id(), self::BRIDGE_OPTION, $cmd ); } else { update_option( self::BRIDGE_OPTION, $cmd, false ); }
		return true;
	}
	/** One Codex home per WordPress scope, so a site's own connection never shares the network's account. */
	public function codex_scope( bool $network ): string {
		return $network ? 'network-' . (int) get_current_network_id() : 'site-' . (int) get_current_blog_id();
	}
	private function codex_run( string $sub, string $scope, string $stdin = '', int $timeout = 60 ) {
		$bridge = $this->codex_bridge();
		if ( '' === $bridge || ! function_exists( 'proc_open' ) ) { return new \WP_Error( 'ace_ai_codex_unavailable', 'The Codex bridge is not installed on this server.' ); }
		$cmd = 'timeout ' . (int) $timeout . ' ' . $bridge . ' ' . escapeshellarg( $sub ) . ' ' . escapeshellarg( $scope );
		$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $proc ) ) { return new \WP_Error( 'ace_ai_codex_unavailable', 'The Codex bridge could not be started.' ); }
		fwrite( $pipes[0], $stdin ); fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
		$err = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
		$code = proc_close( $proc );
		$data = json_decode( (string) $out, true );
		if ( 0 !== $code && is_array( $data ) && ! empty( $data['error'] ) && is_string( $data['error'] ) ) { return new \WP_Error( 'ace_ai_codex_error', $data['error'] ); }
		if ( ! is_array( $data ) || 0 !== $code ) {
			$detail = trim( (string) preg_replace( '/\s+/', ' ', substr( (string) $err, -300 ) ) );
			return new \WP_Error( 'ace_ai_codex_error', 124 === $code ? 'The Codex bridge timed out.' : 'The Codex bridge gave no usable reply' . ( $detail ? ': ' . $detail : '.' ) );
		}
		return $data;
	}
	public function codex_login_start( bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) || ( $network && ! is_multisite() ) ) { return new \WP_Error( 'ace_ai_forbidden', 'You cannot change this connection.' ); }
		$status = $this->codex_run( 'login-start', $this->codex_scope( $network ), '', 75 );
		return is_wp_error( $status ) ? $status : $this->codex_adopt( $status, $network );
	}
	public function codex_login_cancel( bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) ) { return new \WP_Error( 'ace_ai_forbidden', 'You cannot change this connection.' ); }
		return $this->codex_run( 'login-cancel', $this->codex_scope( $network ) );
	}
	/** Current bridge state; once the device code is approved the scope record is written here, so polling completes the sign-in. */
	public function codex_login_status( bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) ) { return new \WP_Error( 'ace_ai_forbidden', 'You cannot view this connection.' ); }
		$status = $this->codex_run( 'login-status', $this->codex_scope( $network ), '', 30 );
		return is_wp_error( $status ) ? $status : $this->codex_adopt( $status, $network );
	}
	private function codex_adopt( array $status, bool $network ): array {
		$record = $network ? get_network_option( get_current_network_id(), self::OPTION, array() ) : get_option( self::OPTION, array() );
		$record = is_array( $record ) ? $record : array();
		if ( ! empty( $status['connected'] ) && 'codex' !== ( $record['provider'] ?? '' ) ) {
			$previous = $record;
			$record   = array( 'mode' => 'own', 'provider' => 'codex', 'features' => array( 'text' ), 'secret' => '', 'model' => '', 'updated_at' => time() );
			// Carry an existing API key (or one kept from a previous sign-in) over so images and voice keep working.
			$carried = 'api_key' === ( $previous['provider'] ?? '' ) ? ( $previous['secret'] ?? '' ) : ( $previous['api_secret'] ?? '' );
			if ( is_string( $carried ) && '' !== $carried ) {
				$record['api_secret'] = $carried;
				$record['features']   = self::subscription_features( is_array( $previous['features'] ?? null ) ? $previous['features'] : array() );
			}
			if ( $network ) { update_network_option( get_current_network_id(), self::OPTION, $record ); } else { update_option( self::OPTION, $record, false ); }
		}
		if ( ! empty( $status['connected'] ) ) {
			$label = trim( (string) ( $status['account'] ?? '' ) ) ?: 'ChatGPT account';
			$label .= ! empty( $status['plan'] ) ? ' (' . $status['plan'] . ')' : '';
			if ( ( $record['account_label'] ?? '' ) !== $label && 'codex' === ( $record['provider'] ?? '' ) ) {
				$record['account_label'] = $label;
				if ( $network ) { update_network_option( get_current_network_id(), self::OPTION, $record ); } else { update_option( self::OPTION, $record, false ); }
			}
		}
		$status['saved'] = 'codex' === ( $record['provider'] ?? '' );
		return $status;
	}
	private function codex_selected_scope(): string {
		$r = $this->resolve();
		return 'network' === ( $r['scope'] ?? '' ) ? 'network-' . (int) get_site( get_current_blog_id() )->network_id : 'site-' . (int) get_current_blog_id();
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
		$r = $this->resolve();
		if ( 'codex' === ( $r['record']['provider'] ?? '' ) ) {
			$models = $this->codex_run( 'models', $this->codex_selected_scope(), '', 30 );
			if ( is_wp_error( $models ) ) { return $models; }
			$list = array();
			foreach ( $models as $m ) { if ( is_array( $m ) && is_string( $m['id'] ?? null ) ) { $list[] = array( 'id' => $m['id'], 'label' => (string) ( $m['label'] ?? $m['id'] ) ); } }
			return $list;
		}
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
		if ( 'codex' === ( $resolved['record']['provider'] ?? '' ) ) {
			$answer = $this->codex_run( 'ask', $this->codex_selected_scope(), (string) json_encode( array( 'model' => $model, 'effort' => 'low', 'messages' => array_values( $messages ) ) ), 180 );
			if ( is_wp_error( $answer ) ) { return $answer; }
			if ( ! is_string( $answer['message'] ?? null ) || '' === $answer['message'] ) { return new \WP_Error( 'ace_ai_response_failed', 'Codex returned no answer.' ); }
			return array( 'message' => $answer['message'], 'usage' => is_array( $answer['usage'] ?? null ) ? $answer['usage'] : array(), 'model' => (string) ( $answer['model'] ?? $model ) );
		}
		if ( '' === $model ) { return new \WP_Error( 'ace_ai_model_required', 'Choose a model from the connected ChatGPT account in Ace AI settings.' ); }
		$token = $this->subscription_token(); if ( is_wp_error( $token ) ) { return $token; }
		$answer = Responses::request( $token, $model, $messages );
		if ( ! is_wp_error( $answer ) ) { $answer['model'] = $model; }
		return $answer;
	}
	public function save_subscription_model( string $model, bool $network = false ) {
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) ) { return new \WP_Error( 'ace_ai_forbidden', 'You cannot change this connection.' ); }
		$r = $network ? get_network_option( get_current_network_id(), self::OPTION, array() ) : get_option( self::OPTION, array() );
		if ( ! in_array( $r['provider'] ?? '', array( 'chatgpt', 'codex' ), true ) ) { return new \WP_Error( 'ace_ai_no_own_subscription', 'Connect a ChatGPT account at this scope first.' ); }
		$models = ( $network ? new self( true ) : $this )->subscription_models(); if ( is_wp_error( $models ) ) { return $models; }
		if ( ( '' !== $model || 'codex' !== $r['provider'] ) && ! in_array( $model, array_column( $models, 'id' ), true ) ) { return new \WP_Error( 'ace_ai_model_unavailable', 'Choose a model available to this ChatGPT account.' ); }
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
