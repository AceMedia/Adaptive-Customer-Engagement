<?php
/** Standalone tests: php tests/shared-ai-connection-test.php (no credentials/network). */
define( 'ABSPATH', __DIR__ );
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['site_records'] = array(); $GLOBALS['network_records'] = array(); $GLOBALS['blog'] = 1; $GLOBALS['multisite'] = true; $GLOBALS['caps'] = array( 'manage_options' => true, 'manage_network_options' => false );
function get_option( $key, $default = false ) { return $GLOBALS['site_records'][$GLOBALS['blog']][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['site_records'][$GLOBALS['blog']][$key] = $value; return true; }
function get_network_option( $network, $key, $default = false ) { return $GLOBALS['network_records'][$network][$key] ?? $default; }
function update_network_option( $network, $key, $value ) { $GLOBALS['network_records'][$network][$key] = $value; return true; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function get_site( $id ) { return (object) array( 'network_id' => $id >= 100 ? 2 : 1 ); }
function get_current_network_id() { return 1; }
function is_multisite() { return $GLOBALS['multisite']; }
function current_user_can( $cap ) { return $GLOBALS['caps'][$cap] ?? false; }
function wp_salt( $scheme ) { return 'fixture-salt-only'; }
require __DIR__ . '/../includes/shared-ai/service.php';
use AceMedia\SharedAI\V1\Service;
$count = 0;
function check( $value, $message ) { global $count; ++$count; if ( ! $value ) { throw new RuntimeException( $message ); } }
$s = new Service();
check( 'unmanaged' === $s->status()['status'], 'Fresh install preserves explicit legacy settings' );
check( 'legacy-key' === $s->api_key( 'text', 'legacy-key' ), 'Only unmanaged may use legacy key' );
check( is_wp_error( $s->save( 'own', 'sk-network', array( 'text' ), true ) ), 'Site admin cannot write network connection' );
$GLOBALS['caps']['manage_network_options'] = true;
check( true === $s->save( 'own', 'sk-network', array( 'text' ), true ), 'Network admin saves connection' );
check( 'network' === $s->status()['scope'], 'Absent site record inherits network' );
check( 'sk-network' === $s->api_key( 'text' ), 'Inherited text key decrypts' );
check( false === strpos( json_encode( $GLOBALS['network_records'] ), 'sk-network' ), 'Secret encrypted at rest' );
check( false === strpos( json_encode( $s->status() ), 'secret' ), 'Status excludes secrets' );
check( is_wp_error( $s->api_key( 'audio', 'paid-legacy' ) ), 'Text permission does not grant audio' );
check( is_wp_error( $s->api_key( 'images' ) ), 'Text permission does not grant images' );
check( is_wp_error( $s->api_key( 'decisions' ) ), 'No inferred Decisions entitlement' );
$GLOBALS['caps']['manage_network_options'] = false;
check( true === $s->save( 'disabled', '', array() ), 'Site admin can disable inherited access' );
check( is_wp_error( $s->api_key( 'text', 'paid-legacy' ) ), 'Disabled hard stop ignores paid legacy' );
$GLOBALS['blog'] = 2;
check( 'sk-network' === $s->api_key( 'text' ), 'Blog switch resolves new scope without cached disable' );
check( true === $s->save( 'own', '', array( 'text' ) ), 'Empty override can be saved as disconnected' );
check( 'disconnected' === $s->status()['status'], 'Empty override never inherits network' );
check( is_wp_error( $s->api_key( 'text', 'legacy' ) ), 'Disconnected override does not fall back' );
check( true === $s->save( 'own', 'sk-local', array( 'text', 'audio', 'unrecognised' ) ), 'Own connection saves' );
check( 'sk-local' === $s->api_key( 'audio' ), 'Audio explicitly enabled' );
check( is_wp_error( $s->api_key( 'unrecognised' ) ), 'Unknown feature discarded' );
check( true === $s->save( 'own', '', array( 'text' ) ), 'Blank key preserves same scope secret' );
check( 'sk-local' === $s->api_key( 'text' ), 'Preserved own key' );
$record = get_option( Service::OPTION ); $record['expires_at'] = time() - 1; update_option( Service::OPTION, $record );
check( 'expired' === $s->status()['status'], 'Expired override explicitly reported' );
check( is_wp_error( $s->api_key( 'text', 'legacy' ) ), 'Expired override cannot fall back' );
$record['expires_at'] = 0; $record['secret'] = 'corrupt'; update_option( Service::OPTION, $record );
check( is_wp_error( $s->api_key( 'text' ) ), 'Corrupt encrypted data cannot be used' );
$record['provider'] = 'chatgpt'; update_option( Service::OPTION, $record );
check( is_wp_error( $s->api_key( 'text' ) ), 'OAuth token cannot enter API-key transport' );
check( true === $s->save( 'inherit', '', array() ), 'Explicit return to network' );
check( 'sk-network' === $s->api_key( 'text' ), 'Inherited key after explicit change' );
check( ! isset( get_option( Service::OPTION )['secret'] ), 'Inheritance removes obsolete own credential' );
$GLOBALS['caps']['manage_options'] = false;
check( is_wp_error( $s->save( 'disabled', '', array() ) ), 'Non-admin cannot change site policy' );
check( 'sk-network' === $s->api_key( 'text' ), 'Cron/no-user use authorised stored site policy' );
$GLOBALS['blog'] = 100;
check( 'unmanaged' === $s->status()['status'], 'Different network never borrows first network connection' );
$GLOBALS['caps']['manage_options'] = true; $GLOBALS['multisite'] = false; $GLOBALS['blog'] = 3;
check( is_wp_error( $s->save( 'inherit', '', array() ) ), 'Single site cannot inherit nonexistent network' );
check( true === $s->save( 'own', 'sk-single', array( 'text' ) ), 'Single-site shared connection works' );
check( 'sk-single' === $s->api_key( 'text' ), 'Single-site whole-site use' );
check( is_wp_error( $s->save( 'bogus', '', array() ) ), 'Unknown mode rejected' );
check( is_wp_error( $s->save( 'own', "sk-key\r\nInjected", array() ) ), 'Invalid key rejected' );
echo "$count shared AI connection checks passed\n";
