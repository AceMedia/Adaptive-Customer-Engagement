<?php
/** Standalone service harness. Run: php tests/places-service-test.php. No network or WordPress DB. */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ );
class WP_Error {
	public $code;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
}
$ace_options = array();
$ace_http_calls = 0;
$ace_http_reply = array();
$ace_on_http = null;
function get_option( $name, $default = false ) { global $ace_options; return $ace_options[ $name ] ?? $default; }
function add_option( $name, $value ) { global $ace_options; if ( array_key_exists( $name, $ace_options ) ) return false; $ace_options[ $name ] = $value; return true; }
function update_option( $name, $value ) { global $ace_options; $ace_options[ $name ] = $value; return true; }
function delete_option( $name ) { global $ace_options; unset( $ace_options[ $name ] ); }
function wp_cache_delete() {}
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function home_url() { return 'https://example.com'; }
function wp_get_environment_type() { return 'production'; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function rest_sanitize_boolean( $value ) { return (bool) $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_generate_uuid4() { return 'fixture-uuid'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time() { return '2026-10-26 12:00:00'; }
function esc_url_raw( $url ) { return $url; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_post( $url, $args ) {
	global $ace_http_calls, $ace_http_reply, $ace_on_http;
	++$ace_http_calls;
	check( 'https://places.googleapis.com/v1/places:searchText' === $url, 'Places New endpoint' );
	check( 8 === $args['timeout'] && 0 === $args['redirection'] && 131072 === $args['limit_response_size'], 'Bounded HTTP request' );
	check( false !== strpos( $args['headers']['X-Goog-FieldMask'], 'places.attributions' ) && false === strpos( $args['headers']['X-Goog-FieldMask'], 'websiteUri' ), 'Minimal field mask' );
	if ( is_callable( $ace_on_http ) ) $ace_on_http();
	return $ace_http_reply;
}
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
class AcePlacesFakeDb {
	public $options = 'wp_options';
	public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
	public function query( $sql ) { global $ace_options; if ( false !== strpos( $sql, 'DELETE FROM wp_options' ) ) { unset( $ace_options['ace_places_lookup_lock'] ); return 1; } return 0; }
}
$wpdb = new AcePlacesFakeDb();
require_once dirname( __DIR__ ) . '/includes/Enrichment/PlacesLookupService.php';
use ACE\AdaptiveCustomerEngagement\Enrichment\PlacesLookupService as Places;
function check( bool $ok, string $label ) { if ( ! $ok ) throw new RuntimeException( $label ); }
function reply( int $status, string $body ) { global $ace_http_reply; $ace_http_reply = array( 'status' => $status, 'body' => $body ); }
$address = array( 'line_1' => '12 Sample Street', 'line_2' => '', 'postcode' => 'AB1 2CD', 'country_code' => 'GB', 'public_premises' => 1 );
$service = new Places();
check( is_wp_error( $service->lookup( 'Fictional Bins Ltd', $address ) ) && 0 === $ace_http_calls, 'Default off makes no call' );
update_option( Places::OPTION, array( 'enabled' => true, 'key' => '' ) );
check( is_wp_error( $service->lookup( 'Fictional Bins Ltd', $address ) ) && 0 === $ace_http_calls, 'Missing key makes no call' );
update_option( Places::OPTION, array( 'enabled' => true, 'key' => 'fictional-key', 'generation' => 'old', 'country' => 'GB', 'language' => 'en', 'daily_limit' => 2 ) );
$unverified = $address; $unverified['public_premises'] = 0;
check( is_wp_error( $service->lookup( 'Fictional Bins Ltd', $unverified ) ) && 0 === $ace_http_calls, 'Unverified premises make no call' );
reply( 200, '{"places":[]}' );
$empty = $service->lookup( 'Fictional Bins Ltd', $address );
check( ! is_wp_error( $empty ) && array() === $empty['candidates'] && 1 === $ace_http_calls && 'last_lookup_succeeded' === Places::public_settings()['status'], 'Zero results count and mark reachable' );
reply( 429, '{"error":{"message":"fixture-secret"}}' );
check( is_wp_error( $service->lookup( 'Fictional Bins Ltd', $address ) ) && 2 === Places::requests_today(), 'Quota failure counted' );
check( is_wp_error( $service->lookup( 'Fictional Bins Ltd', $address ) ) && 2 === $ace_http_calls, 'Cooldown blocks retry' );
delete_option( 'ace_places_cooldown_until' );
check( is_wp_error( $service->lookup( 'Fictional Bins Ltd', $address ) ) && 2 === $ace_http_calls, 'Hard daily limit blocks request' );
update_option( 'ace_places_requests', array( 'date' => gmdate( 'Y-m-d' ), 'count' => 0 ) );
$ace_on_http = static function () { Places::save( array( 'replace_key' => 'fictional-new-key' ) ); };
reply( 200, '{"places":[]}' );
$service->lookup( 'Fictional Bins Ltd', $address );
check( 'untested' === Places::public_settings()['status'], 'In-flight old success cannot verify replacement key' );
$ace_on_http = null;
reply( 500, '{"error":"server"}' );
check( is_wp_error( $service->lookup( 'Fictional Bins Ltd', $address ) ), 'Server error fails' );
delete_option( 'ace_places_cooldown_until' );
update_option( 'ace_places_requests', array( 'date' => gmdate( 'Y-m-d' ), 'count' => 0 ) );
global $ace_http_reply;
$ace_http_reply = new WP_Error( 'fixture_transport' );
check( is_wp_error( $service->lookup( 'Fictional Bins Ltd', $address ) ), 'Transport failure fails' );
check( false === strpos( json_encode( Places::public_settings() ), 'fictional-new-key' ), 'Key absent from settings response' );
echo "Places service fixtures passed\n";
