<?php
namespace AceMedia\SharedAI\V1;
defined( 'ABSPATH' ) || exit;
final class Admin {
	public static function register() {
		add_action( 'admin_menu', static function () {
			add_options_page( 'Ace AI connection', 'Ace AI connection', 'manage_options', 'ace-ai-connection', array( __CLASS__, 'render' ) );
		} );
		add_action( 'network_admin_menu', static function () {
			add_submenu_page( 'settings.php', 'Ace AI connection', 'Ace AI connection', 'manage_network_options', 'ace-ai-connection', array( __CLASS__, 'render' ) );
		} );
		add_action( 'wp_ajax_ace_ai_codex_status', array( __CLASS__, 'ajax_codex_status' ) );
	}
	/** Polled by the sign-in screen until the device code is approved; the service saves the record itself. */
	public static function ajax_codex_status() {
		$network = ! empty( $_GET['network'] );
		check_ajax_referer( 'ace-ai-codex-status-' . ( $network ? 'network' : 'site' ) );
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Forbidden' ), 403 ); }
		$service = $network ? new Service( true ) : ace_ai_connection_service();
		$status = $service->codex_login_status( $network );
		if ( is_wp_error( $status ) ) { wp_send_json_error( array( 'message' => $status->get_error_message() ) ); }
		wp_send_json_success( $status );
	}
	private static function codex_section( Service $service, bool $network, array $record ) {
		if ( ! $service->codex_available() ) {
			?><h2>Sign in with ChatGPT</h2><p>Sign in from this screen needs the Ace Codex bridge installed on this server. See <code>docs/SHARED-AI-CONNECTION.md</code> for the install steps.</p><?php
			self::bridge_form( $service, $network );
			return;
		}
		$status = $service->codex_login_status( $network );
		?><h2>Sign in with ChatGPT</h2><?php
		if ( is_wp_error( $status ) ) { ?><div class="notice notice-error inline"><p><?php echo esc_html( $status->get_error_message() ); ?></p></div><?php self::bridge_form( $service, $network ); return; }
		if ( ! empty( $status['error'] ) ) { ?><div class="notice notice-warning inline"><p><?php echo esc_html( $status['error'] ); ?></p></div><?php }
		if ( ! empty( $status['connected'] ) ) {
			?><p>Connected as <strong><?php echo esc_html( $record['account_label'] ?? ( $status['account'] ?? 'ChatGPT account' ) ); ?></strong> through this server’s own Codex instance. Text features in Ace SEO and Adaptive Customer Engagement now use this account’s allowance.</p>
			<form method="post"><?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?><input type="hidden" name="intent" value="disconnect"><?php submit_button( 'Sign out of ChatGPT at this scope', 'secondary' ); ?></form><?php
		} elseif ( ! empty( $status['pending'] ) && ! empty( $status['code'] ) ) {
			$url = esc_url( $status['url'] ?: 'https://auth.openai.com/codex/device' );
			?><ol><li>Open <a href="<?php echo $url; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $status['url'] ?: 'https://auth.openai.com/codex/device' ); ?></a> and sign in to the ChatGPT account whose subscription should power this <?php echo esc_html( $network ? 'network' : 'site' ); ?>.</li>
			<li>Enter this one-time code (it expires in 15 minutes):<br><code style="font-size:1.6em;letter-spacing:.08em"><?php echo esc_html( $status['code'] ); ?></code></li></ol>
			<p id="ace-ai-codex-wait">Waiting for approval… this page updates by itself once the code is accepted.</p>
			<form method="post" style="display:inline"><?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?><input type="hidden" name="intent" value="codex_cancel"><?php submit_button( 'Cancel sign-in', 'secondary', 'submit', false ); ?></form>
			<script>(function(){var u=<?php echo wp_json_encode( add_query_arg( array( 'action' => 'ace_ai_codex_status', 'network' => $network ? 1 : 0, '_wpnonce' => wp_create_nonce( 'ace-ai-codex-status-' . ( $network ? 'network' : 'site' ) ) ), admin_url( 'admin-ajax.php' ) ) ); ?>;function tick(){fetch(u,{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){var d=j&&j.data||{};if(d.connected){location.reload();return;}if(!d.pending){document.getElementById('ace-ai-codex-wait').textContent=d.error||'The code expired or the sign-in was cancelled. Start again.';return;}setTimeout(tick,4000);}).catch(function(){setTimeout(tick,8000);});}setTimeout(tick,4000);})();</script><?php
		} elseif ( ! empty( $status['pending'] ) ) {
			?><p id="ace-ai-codex-wait">Codex is requesting a sign-in code… <a href="">Refresh</a> in a few seconds.</p><?php
		} else {
			?><p>Sign in with the ChatGPT account whose subscription should power Ace plugins at this scope. Codex on this server shows you a one-time code; approve it in your browser and you are connected. Nothing is downloaded and no credential file is handled.</p>
			<form method="post"><?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?><input type="hidden" name="intent" value="codex_login">
			<p><label><input type="checkbox" name="authorise_scope" value="1" required> I authorise this ChatGPT connection for Ace plugins and their scheduled jobs on <?php echo esc_html( $network ? 'this network, except sites with their own override or AI switched off' : 'this site' ); ?>.</label></p>
			<?php submit_button( 'Sign in with ChatGPT', 'primary' ); ?></form><?php
		}
		self::bridge_form( $service, $network );
	}
	private static function bridge_form( Service $service, bool $network ) {
		if ( is_multisite() && ! $network ) { return; }
		$current = $service->codex_bridge();
		?><details><summary>Codex bridge settings</summary>
		<form method="post"><?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?><input type="hidden" name="intent" value="codex_bridge">
		<p><label for="ace-ai-bridge">Bridge command</label><br><input class="regular-text" type="text" id="ace-ai-bridge" name="codex_bridge" value="<?php echo esc_attr( defined( 'ACE_AI_CODEX_BRIDGE' ) ? ACE_AI_CODEX_BRIDGE : $current ); ?>" <?php disabled( defined( 'ACE_AI_CODEX_BRIDGE' ) ); ?> placeholder="sudo -H -u ace-ai /usr/local/bin/ace-codex-bridge"><br>How PHP runs <code>ace-codex-bridge</code> on this server, usually through a sudo rule to the account that owns the Codex homes. Leave blank to use <code><?php echo esc_html( Service::BRIDGE_DEFAULT ); ?></code> when it exists. <?php echo defined( 'ACE_AI_CODEX_BRIDGE' ) ? 'Fixed by the ACE_AI_CODEX_BRIDGE constant.' : ''; ?></p>
		<?php submit_button( 'Save bridge command', 'secondary' ); ?></form></details><?php
	}
	public static function render() {
		$network = is_network_admin();
		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) ) { return; }
		$service = $network ? new Service( true ) : ace_ai_connection_service();
		$message = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) );
			$mode = isset( $_POST['connection_mode'] ) && is_string( $_POST['connection_mode'] ) ? sanitize_key( wp_unslash( $_POST['connection_mode'] ) ) : '';
			$key = isset( $_POST['api_key'] ) && is_string( $_POST['api_key'] ) ? trim( wp_unslash( $_POST['api_key'] ) ) : '';
			$features = isset( $_POST['features'] ) && is_array( $_POST['features'] ) ? array_filter( wp_unslash( $_POST['features'] ), 'is_string' ) : array();
			$intent = isset( $_POST['intent'] ) && is_string( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : 'settings';
			if ( 'import' === $intent ) {
				$file = $_FILES['credential_file'] ?? array();
				$valid = is_string( $file['tmp_name'] ?? null ) && UPLOAD_ERR_OK === ( $file['error'] ?? -1 ) && (int) ( $file['size'] ?? 0 ) < 131072 && is_uploaded_file( $file['tmp_name'] ) && ! empty( $_POST['authorise_scope'] );
				$tokens = $valid ? json_decode( file_get_contents( $file['tmp_name'] ), true ) : null;
				$result = is_array( $tokens ) ? $service->import_subscription( $tokens, $network ) : new \WP_Error( 'ace_ai_import_invalid', 'Choose the protected credential file and confirm the intended scope.' );
			} elseif ( 'disconnect' === $intent ) {
				$result = $service->disconnect_subscription( $network );
			} elseif ( 'codex_login' === $intent ) {
				$result = empty( $_POST['authorise_scope'] ) ? new \WP_Error( 'ace_ai_scope_required', 'Confirm the scope this ChatGPT account should power.' ) : $service->codex_login_start( $network );
				if ( ! is_wp_error( $result ) ) { $result = ! empty( $result['connected'] ) ? true : ( ! empty( $result['code'] ) ? 'Enter the code below to finish signing in.' : ( $result['error'] ?: 'Codex is still requesting a code. Refresh in a moment.' ) ); }
			} elseif ( 'codex_cancel' === $intent ) {
				$result = $service->codex_login_cancel( $network ); $result = is_wp_error( $result ) ? $result : 'Sign-in cancelled.';
			} elseif ( 'codex_bridge' === $intent ) {
				$result = $service->save_codex_bridge( isset( $_POST['codex_bridge'] ) && is_string( $_POST['codex_bridge'] ) ? wp_unslash( $_POST['codex_bridge'] ) : '', $network );
			} elseif ( 'model' === $intent ) {
				$model = isset( $_POST['subscription_model'] ) && is_string( $_POST['subscription_model'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_model'] ) ) : '';
				$result = $service->save_subscription_model( $model, $network );
			} else {
				$result = $service->save( $mode, $key, $features, $network );
			}
			$message = is_wp_error( $result ) ? $result->get_error_message() : ( is_string( $result ) ? $result : 'Connection settings saved. No inference request was made.' );
		}
		$record = $network ? get_network_option( get_current_network_id(), Service::OPTION, array() ) : get_option( Service::OPTION, array() );
		$mode = $record['mode'] ?? ( is_multisite() && ! $network ? 'inherit' : 'own' );
		$status = $network ? Service::resolve_records( $record, null, false, time() ) : $service->status();
		if ( $network ) { $status['scope'] = 'network'; }
		$features = $record['features'] ?? array( 'text' );
		?>
		<div class="wrap"><h1>Ace AI connection</h1>
		<p><?php echo esc_html( $network ? 'One connection for Ace plugins across this network. Each site can use its own connection or switch AI off.' : 'One connection for the Ace plugins on this site, including their scheduled jobs.' ); ?></p>
		<?php if ( $message ) { ?><div class="notice notice-info"><p><?php echo esc_html( $message ); ?></p></div><?php } ?>
		<p><strong>Current state:</strong> <?php echo esc_html( $status['status'] . ' (' . $status['scope'] . ')' ); ?>. <?php echo esc_html( 'unmanaged' === $status['status'] ? 'Existing plugin keys remain in use until you configure a shared connection.' : 'These shared settings control Ace AI access. No old plugin key will be used as a fallback.' ); ?></p>
		<?php if ( ! empty( $status['account_label'] ) ) { ?><p>Connected account: <?php echo esc_html( $status['account_label'] ); ?>. Model: <?php echo esc_html( $status['model'] ?: ( 'codex' === $status['provider'] ? 'Codex default' : 'Choose a model' ) ); ?>.</p><?php } ?>
		<?php self::codex_section( $service, $network, is_array( $record ) ? $record : array() ); ?>
		<h2>Connection mode</h2>
		<form method="post">
		<?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?>
		<p><label for="ace-ai-mode">Connection</label><br><select id="ace-ai-mode" name="connection_mode">
		<?php if ( is_multisite() && ! $network ) { ?><option value="inherit" <?php selected( $mode, 'inherit' ); ?>>Use the network connection</option><?php } ?>
		<option value="own" <?php selected( $mode, 'own' ); ?>><?php echo esc_html( $network ? 'Use this network connection' : 'Use this site’s own connection' ); ?></option>
		<option value="disabled" <?php selected( $mode, 'disabled' ); ?>>Switch AI off</option></select></p>
		<h2>OpenAI API key</h2><p>This is separately billed API access. It does not use a ChatGPT subscription.</p>
		<p><label for="ace-ai-key">API key</label><br><input class="regular-text" type="password" id="ace-ai-key" name="api_key" value="" autocomplete="new-password"><br>Leave blank to keep this scope’s saved key. Keys are never shown here.</p>
		<fieldset><legend>Allow this connection to provide</legend>
		<?php foreach ( array( 'text' => 'Text suggestions', 'images' => 'Image generation', 'audio' => 'Voice and transcription' ) as $feature => $label ) { ?>
		<label style="display:block"><input type="checkbox" name="features[]" value="<?php echo esc_attr( $feature ); ?>" <?php checked( in_array( $feature, $features, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
		<?php } ?></fieldset>
		<p>Switching off or choosing inheritance removes this scope’s stored key. It does not revoke the key at OpenAI. A missing or expired override never falls back to another account.</p>
		<?php submit_button( 'Save connection settings' ); ?></form>
		<details><summary>Alternative: import a credential file from the sign-in helper</summary>
		<p>For an open-source installation without the Codex bridge, complete sign-in on the computer running your browser, then import its protected credential file here. This is a separate route from a registered commercial website callback.</p>
		<p>Host ID: <code><?php echo esc_html( $service->host_id() ); ?></code></p>
		<p><a class="button" download="ace-chatgpt-login.mjs" href="<?php echo esc_url( plugins_url( 'bin/ace-chatgpt-login.mjs', dirname( __DIR__, 2 ) . '/bootstrap.php' ) ); ?>">Download sign-in helper</a></p>
		<details><summary>Set up on a self-hosted server</summary><ol><li>Download the helper to the computer running your browser. It needs Node.js 20 or newer.</li><li>Run this command in a private folder, open the one-time link it prints, and authorise ChatGPT plan access.</li><li>Import the resulting credential file below, then choose a text model. Remove the transfer file when the import is complete.</li></ol><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html( 'node ace-chatgpt-login.mjs ' . $service->host_id() . ' ace-chatgpt-credentials.json' ); ?></pre><p>Keep credentials private. Never paste them into chat or tickets. The companion registration file can be retained privately for later sign-in to the same account.</p></details>
		<form method="post" enctype="multipart/form-data">
		<?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?>
		<input type="hidden" name="intent" value="import">
		<p><label>Protected credential file <input type="file" name="credential_file" accept="application/json,.json" required></label></p>
		<p><label><input type="checkbox" name="authorise_scope" value="1" required> I authorise this ChatGPT connection for Ace plugins and their scheduled jobs on <?php echo esc_html( $network ? 'this network, except sites with their own override or AI switched off' : 'this site' ); ?>.</label></p>
		<?php submit_button( 'Import ChatGPT connection', 'secondary' ); ?></form></details>
		<?php if ( $service->subscription_selected() && in_array( $record['provider'] ?? '', array( 'chatgpt', 'codex' ), true ) ) {
			if ( 'chatgpt' === $record['provider'] ) { ?><form method="post"><?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?><input type="hidden" name="intent" value="disconnect"><?php submit_button( 'Disconnect ChatGPT at this scope', 'secondary' ); ?></form><?php }
			$models = $service->subscription_models();
			if ( is_wp_error( $models ) ) { echo '<p>' . esc_html( $models->get_error_message() ) . '</p>'; }
			else { ?>
		<form method="post"><?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?><input type="hidden" name="intent" value="model">
		<p><label>ChatGPT text model <select name="subscription_model"><?php if ( 'codex' === $record['provider'] ) { ?><option value="" <?php selected( $record['model'] ?? '', '' ); ?>>Codex default</option><?php } foreach ( $models as $model ) { ?><option value="<?php echo esc_attr( $model['id'] ); ?>" <?php selected( $record['model'] ?? '', $model['id'] ); ?>><?php echo esc_html( $model['label'] ); ?></option><?php } ?></select></label></p>
		<?php submit_button( 'Save text model', 'secondary' ); ?></form>
		<?php } } ?>
		<p>Subscription text requests use the connected account’s available models. Images, audio and Decisions are not enabled by this connection. No paid API-key fallback is used. Review access and limits in <a href="https://chatgpt.com/settings/usage" target="_blank" rel="noopener noreferrer">ChatGPT usage settings</a>.</p>
		</div>
		<?php
	}
}
