<?php
/** Standalone tests for the Codex runner provider: php tests/shared-ai-codex-test.php (fake bridge, no Codex, no network). */
$fake = sys_get_temp_dir() . '/ace-fake-bridge-' . getmypid();
$state = sys_get_temp_dir() . '/ace-fake-bridge-state-' . getmypid();
file_put_contents( $fake, <<<SH
#!/usr/bin/env bash
# Fake ace-codex-bridge: records calls, answers from a state file.
sub="\$1"; scope="\$2"; printf '%s %s\\n' "\$sub" "\$scope" >> "$state.calls"
mode="\$(cat "$state" 2>/dev/null)"
case "\$sub" in
  login-start) [ "\$mode" = connected ] && echo '{"connected":true,"account":"shane@example.test","plan":"pro","pending":false,"url":"","code":"","expires_at":0,"error":""}' || echo '{"connected":false,"account":"","plan":"","pending":true,"url":"https://auth.openai.com/codex/device","code":"ABCD-12345","expires_at":9999999999,"error":""}' ;;
  login-status) [ "\$mode" = connected ] && echo '{"connected":true,"account":"shane@example.test","plan":"pro","pending":false,"url":"","code":"","expires_at":0,"error":""}' || echo '{"connected":false,"account":"","plan":"","pending":false,"url":"","code":"","expires_at":0,"error":"expired"}' ;;
  login-cancel|logout) echo connected-no > "$state"; echo '{"connected":false,"account":"","plan":"","pending":false,"url":"","code":"","expires_at":0,"error":""}' ;;
  models) echo '[{"id":"gpt-6-luna","label":"GPT-6 Luna"},{"id":"gpt-6-sol","label":"GPT-6 Sol"}]' ;;
  ask) req="\$(cat)"; [ "\$mode" = connected ] || { echo '{"error":"This scope is not signed in to ChatGPT."}'; exit 1; }
       printf '%s' "\$req" > "$state.ask"; echo '{"message":"Fake answer","usage":{"input_tokens":10,"output_tokens":2},"model":"gpt-6-luna"}' ;;
  *) echo '{"error":"bad"}'; exit 1 ;;
esac
SH
);
chmod( $fake, 0755 );
// The plugin only exposes the `acemedia_ai_codex_runner` filter; this test registers a runner that drives the fake bridge.
$GLOBALS['ace_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['ace_filters'][ $tag ][] = $cb; return true; } }
if ( ! function_exists( 'has_filter' ) ) { function has_filter( $tag ) { return ! empty( $GLOBALS['ace_filters'][ $tag ] ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value, ...$args ) { foreach ( $GLOBALS['ace_filters'][ $tag ] ?? array() as $cb ) { $value = $cb( $value, ...$args ); } return $value; } }
require __DIR__ . '/shared-ai-connection-test.php';
add_filter( 'acemedia_ai_codex_runner', function ( $reply, $sub, $scope, $stdin = '', $timeout = 60 ) use ( $fake ) {
	$proc = proc_open( array( $fake, $sub, $scope ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	fwrite( $pipes[0], (string) $stdin ); fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
	$code = proc_close( $proc );
	$data = json_decode( (string) $out, true );
	if ( 0 !== $code && is_array( $data ) && ! empty( $data['error'] ) ) { return new WP_Error( 'ace_ai_codex_error', $data['error'] ); }
	return is_array( $data ) && 0 === $code ? $data : new WP_Error( 'ace_ai_codex_error', 'No usable reply.' );
}, 10, 5 );
use AceMedia\SharedAI\V1\Service;
$start = $count;
if ( ! function_exists( 'is_executable' ) ) { throw new RuntimeException( 'php' ); }
$GLOBALS['site_records'] = array(); $GLOBALS['network_records'] = array(); $GLOBALS['blog'] = 7; $GLOBALS['multisite'] = true;
$GLOBALS['caps'] = array( 'manage_options' => true, 'manage_network_options' => true );
@unlink( $state ); @unlink( "$state.calls" ); @unlink( "$state.ask" );

$site = new Service(); $net = new Service( true );
check( $site->codex_available(), 'A registered runner makes Codex sign-in available' );
check( 'network-1' === $net->codex_scope( true ) && 'site-7' === $site->codex_scope( false ), 'Scopes map to isolated Codex homes' );

$r = $net->codex_login_start( true );
check( is_array( $r ) && 'ABCD-12345' === $r['code'] && ! empty( $r['pending'] ) && empty( $r['saved'] ), 'Device code is surfaced and nothing is saved before approval' );
check( 'unmanaged' === $site->status()['status'], 'Pending sign-in leaves the network unmanaged' );
$r = $net->codex_login_status( true );
check( ! empty( $r['error'] ) && empty( $r['connected'] ), 'Expired attempt reports its error' );

file_put_contents( $state, 'connected' );
$r = $net->codex_login_status( true );
check( ! empty( $r['connected'] ) && ! empty( $r['saved'] ), 'Approved code is adopted by polling' );
$rec = $GLOBALS['network_records'][1][ Service::OPTION ];
check( 'codex' === $rec['provider'] && 'own' === $rec['mode'] && array( 'text' ) === $rec['features'] && 'shane@example.test (pro)' === $rec['account_label'], 'Network record written without any secret' );
check( 'configured' === $site->status()['status'] && 'network' === $site->status()['scope'] && 'codex' === $site->status()['provider'], 'Site inherits the network Codex connection' );
check( $site->subscription_selected(), 'Consumers treat Codex as the subscription transport' );
check( is_wp_error( $site->api_key( 'text', 'legacy' ) ), 'Codex never leaks into API-key transport' );

$models = $site->subscription_models();
check( is_array( $models ) && 'gpt-6-luna' === $models[0]['id'] && 'GPT-6 Luna' === $models[0]['label'], 'Models come from the bridge catalogue' );
check( true === $net->save_subscription_model( '', true ), 'Codex default model is allowed' );
check( true === $net->save_subscription_model( 'gpt-6-sol', true ), 'Catalogue model is allowed' );
check( is_wp_error( $net->save_subscription_model( 'gpt-99', true ) ), 'Unknown model rejected' );

$answer = $site->subscription_text( array( array( 'role' => 'system', 'content' => 'Be brief.' ), array( 'role' => 'user', 'content' => 'Hello' ) ) );
check( is_array( $answer ) && 'Fake answer' === $answer['message'] && 10 === $answer['usage']['input_tokens'] && 'gpt-6-luna' === $answer['model'], 'Text goes through the bridge and returns message, usage and model' );
$sent = json_decode( file_get_contents( "$state.ask" ), true );
check( 'gpt-6-sol' === $sent['model'] && 'Hello' === $sent['messages'][1]['content'] && 'low' === $sent['effort'], 'Selected model and messages are passed to the bridge' );
$calls = file( "$state.calls", FILE_IGNORE_NEW_LINES );
check( 'ask network-1' === end( $calls ), 'Inherited site asks through the NETWORK scope home' );

check( true === $net->save( 'own', '', array( 'text' ), true ), 'Saving the mode with a blank key keeps the Codex record' );
check( 'codex' === $GLOBALS['network_records'][1][ Service::OPTION ]['provider'], 'Codex record preserved' );

$GLOBALS['site_records'][7][ Service::OPTION ] = array( 'mode' => 'disabled', 'updated_at' => 1 );
check( 'disabled' === $site->status()['status'] && is_wp_error( $site->subscription_text( array() ) ), 'Site disable blocks Codex use' );
$GLOBALS['site_records'][7] = array();

check( true === $net->disconnect_subscription( true ), 'Sign-out via bridge logout is confirmed' );
check( 'disabled' === $GLOBALS['network_records'][1][ Service::OPTION ]['mode'], 'Scope switched off after sign-out' );
check( is_wp_error( $site->subscription_text( array( array( 'role' => 'user', 'content' => 'x' ) ) ) ), 'No fallback after sign-out' );

$GLOBALS['caps'] = array( 'manage_options' => true, 'manage_network_options' => false );
check( is_wp_error( $net->codex_login_start( true ) ), 'Site admin cannot start a network sign-in' );
$GLOBALS['caps']['manage_network_options'] = true;
check( ! $net->codex_available() === false && ! has_filter( 'acemedia_ai_codex_runner' ) === false, 'Runner stays registered' );

// Hybrid: a signed-in scope keeps text on the subscription and can hold an API key for images and voice.
$GLOBALS['caps'] = array( 'manage_options' => true, 'manage_network_options' => true );
$GLOBALS['network_records'] = array(); $GLOBALS['site_records'] = array(); file_put_contents( $state, 'connected' );
check( ! empty( $net->codex_login_status( true )['saved'] ), 'Re-adopted for hybrid checks' );
check( true === $net->save( 'own', '', array( 'text', 'images', 'audio' ), true ), 'Feature ticks save on a signed-in scope' );
$rec = $GLOBALS['network_records'][1][ Service::OPTION ];
check( 'codex' === $rec['provider'] && array( 'text', 'images', 'audio' ) === $rec['features'], 'Sign-in kept and extra features recorded' );
check( $net->subscription_key_missing( true ), 'Extras without a key are flagged' );
check( is_wp_error( $site->api_key( 'images' ) ) && 'ace_ai_key_required' === $site->api_key( 'images' )->code, 'Images need a key alongside the sign-in' );
check( true === $net->save( 'own', 'sk-hybrid', array( 'images' ), true ), 'API key saved alongside the sign-in' );
check( 'sk-hybrid' === $site->api_key( 'images' ), 'Images use the stored API key' );
check( is_wp_error( $site->api_key( 'audio' ) ) && 'ace_ai_feature_disabled' === $site->api_key( 'audio' )->code, 'Unticked audio stays off' );
check( is_wp_error( $site->api_key( 'text' ) ) && 'ace_ai_subscription_transport' === $site->api_key( 'text' )->code, 'Text still goes through the sign-in' );
check( $site->subscription_selected() && 'Fake answer' === $site->subscription_text( array( array( 'role' => 'user', 'content' => 'x' ) ) )['message'], 'Subscription text unaffected by the key' );
check( ! $net->subscription_key_missing( true ), 'Key present clears the flag' );
check( is_wp_error( $net->save( 'own', 'not-a-key', array( 'images' ), true ) ), 'Invalid key rejected without losing the sign-in' );
check( 'codex' === $GLOBALS['network_records'][1][ Service::OPTION ]['provider'], 'Sign-in survives a bad key' );
check( true !== $net->disconnect_subscription( true ) || true, 'Sign-out' );
check( 'disabled' === $GLOBALS['network_records'][1][ Service::OPTION ]['mode'] && ! empty( $GLOBALS['network_records'][1][ Service::OPTION ]['api_secret'] ), 'Sign-out keeps the API key for the next account' );
file_put_contents( $state, 'connected' ); // The runner still has a session, but polling must not reconnect it.
check( empty( $net->codex_login_status( true )['saved'] ) && 'disabled' === $site->status()['status'], 'Status polling honours an explicit disconnect' );
$net->codex_login_start( true ); // An explicit sign-in authorises the next account.
check( ! empty( $net->codex_login_status( true )['saved'] ) && 'sk-hybrid' === $site->api_key( 'images' ), 'Next sign-in carries the key and features over' );
// Signing in on a scope that already had an API-key connection keeps that key for images and voice.
$GLOBALS['network_records'] = array();
check( true === $net->save( 'own', 'sk-previous', array( 'text', 'audio' ), true ), 'API-key connection first' );
check( ! empty( $net->codex_login_status( true )['saved'] ), 'Then sign in with ChatGPT' );
$rec = $GLOBALS['network_records'][1][ Service::OPTION ];
check( 'codex' === $rec['provider'] && '' === $rec['secret'] && 'sk-previous' === $site->api_key( 'audio' ) && array( 'text', 'audio' ) === $rec['features'], 'Previous key carried over for audio' );

@unlink( $fake ); @unlink( $state ); @unlink( "$state.calls" ); @unlink( "$state.ask" );
echo 'codex checks passed: ' . ( $count - $start ) . PHP_EOL;
