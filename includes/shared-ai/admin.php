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
			} elseif ( 'model' === $intent ) {
				$model = isset( $_POST['subscription_model'] ) && is_string( $_POST['subscription_model'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_model'] ) ) : '';
				$result = $service->save_subscription_model( $model, $network );
			} else {
				$result = $service->save( $mode, $key, $features, $network );
			}
			$message = is_wp_error( $result ) ? $result->get_error_message() : 'Connection settings saved. No inference request was made.';
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
		<?php if ( ! empty( $status['account_label'] ) ) { ?><p>Connected account: <?php echo esc_html( $status['account_label'] ); ?>. Model: <?php echo esc_html( $status['model'] ?: 'Choose a model' ); ?>.</p><?php } ?>
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
		<h2>Continue with ChatGPT</h2>
		<p>For an open-source installation on your own server, complete sign-in on the computer running your browser, then import its protected credential file here. This is a separate route from a registered commercial website callback.</p>
		<p>Host ID: <code><?php echo esc_html( $service->host_id() ); ?></code></p>
		<p><a class="button" download="ace-chatgpt-login.mjs" href="<?php echo esc_url( plugins_url( 'bin/ace-chatgpt-login.mjs', dirname( __DIR__, 2 ) . '/bootstrap.php' ) ); ?>">Download sign-in helper</a></p>
		<details><summary>Set up on a self-hosted server</summary><ol><li>Download the helper to the computer running your browser. It needs Node.js 20 or newer.</li><li>Run this command in a private folder, open the one-time link it prints, and authorise ChatGPT plan access.</li><li>Import the resulting credential file below, then choose a text model. Remove the transfer file when the import is complete.</li></ol><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html( 'node ace-chatgpt-login.mjs ' . $service->host_id() . ' ace-chatgpt-credentials.json' ); ?></pre><p>Keep credentials private. Never paste them into chat or tickets. The companion registration file can be retained privately for later sign-in to the same account.</p></details>
		<form method="post" enctype="multipart/form-data">
		<?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?>
		<input type="hidden" name="intent" value="import">
		<p><label>Protected credential file <input type="file" name="credential_file" accept="application/json,.json" required></label></p>
		<p><label><input type="checkbox" name="authorise_scope" value="1" required> I authorise this ChatGPT connection for Ace plugins and their scheduled jobs on <?php echo esc_html( $network ? 'this network, except sites with their own override or AI switched off' : 'this site' ); ?>.</label></p>
		<?php submit_button( 'Import ChatGPT connection', 'secondary' ); ?></form>
		<?php if ( $service->subscription_selected() && 'chatgpt' === ( $record['provider'] ?? '' ) ) {
			?><form method="post"><?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?><input type="hidden" name="intent" value="disconnect"><?php submit_button( 'Disconnect ChatGPT at this scope', 'secondary' ); ?></form><?php
			$models = $service->subscription_models();
			if ( is_wp_error( $models ) ) { echo '<p>' . esc_html( $models->get_error_message() ) . '</p>'; }
			else { ?>
		<form method="post"><?php wp_nonce_field( 'ace-ai-connection-' . ( $network ? 'network' : 'site' ) ); ?><input type="hidden" name="intent" value="model">
		<p><label>ChatGPT text model <select name="subscription_model"><?php foreach ( $models as $model ) { ?><option value="<?php echo esc_attr( $model['id'] ); ?>" <?php selected( $record['model'] ?? '', $model['id'] ); ?>><?php echo esc_html( $model['label'] ); ?></option><?php } ?></select></label></p>
		<?php submit_button( 'Save text model', 'secondary' ); ?></form>
		<?php } } ?>
		<p>Subscription text requests use the connected account’s available models. Images, audio and Decisions are not enabled by this connection. No paid API-key fallback is used. Review access and limits in <a href="https://chatgpt.com/settings/usage" target="_blank" rel="noopener noreferrer">ChatGPT usage settings</a>.</p>
		</div>
		<?php
	}
}
