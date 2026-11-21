<?php
/** Real repository/stream helpers with synthetic WordPress HTTP and data. No bootstrap or delivery. */
if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    public $code; public $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
$http_calls = 0; $http_reply = null; $http_request = null; $checks = 0;
function wp_remote_post( $url, $options ) {
    global $http_calls, $http_reply, $http_request;
    ++$http_calls; $http_request = array( $url, $options ); return $http_reply;
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function check( $value, $label ) {
    global $checks;
    if ( ! $value ) { throw new RuntimeException( 'FAIL ' . $label ); }
    ++$checks; echo 'PASS ', $label, "\n";
}
set_error_handler( static function ( $level, $message, $file, $line ) { throw new ErrorException( $message, 0, $level, $file, $line ); } );
try {
    require dirname( __DIR__ ) . '/includes/shared-ai/responses.php';
    require dirname( __DIR__ ) . '/includes/Enrichment/EnrichmentResult.php';
    require dirname( __DIR__ ) . '/includes/Database/Repositories/CompanyRepository.php';
    $parse = array( 'AceMedia\\SharedAI\\V1\\Responses', 'parse' );
    $partial = 'data: {"type":"response.output_text.delta","delta":"Synthetic private text"}' . "\n\n";
    $malformed = array( '{broken', '{"type":', "\xB1\x31", 'null', '42', '"synthetic-secret"', '[]' );
    foreach ( $malformed as $index => $body ) {
        $result = call_user_func( $parse, 'data: ' . $body );
        check( is_wp_error( $result ), 'invalid or incomplete stream rejected ' . $index );
        check( false === strpos( json_encode( $result ), 'synthetic-secret' ), 'stream diagnostic redacted ' . $index );
    }
    check( is_wp_error( call_user_func( $parse, $partial ) ), 'partial output never becomes success' );
    foreach ( array( 'response.failed', 'response.incomplete', 'error' ) as $type ) {
        $result = call_user_func( $parse, $partial . 'data: ' . json_encode( array( 'type' => $type, 'message' => 'synthetic-secret' ) ) );
        check( is_wp_error( $result ) && false === strpos( json_encode( $result ), 'synthetic-secret' ) && false === strpos( json_encode( $result ), 'Synthetic private text' ), 'failure discards text and private diagnostic: ' . $type );
    }
    $unicode = 'Café and a friendly wave 👋';
    $body = 'data: ' . json_encode( array( 'type' => 'response.output_text.delta', 'delta' => $unicode ) ) . "\r\n\r\n" . 'data: {"type":"response.completed","response":{"usage":{"input_tokens":2}}}';
    $result = call_user_func( $parse, $body );
    check( is_array( $result ) && $result['message'] === $unicode && $result['usage']['input_tokens'] === 2, 'Unicode and CRLF stream completion retained' );
    check( is_wp_error( call_user_func( $parse, $body . "\n\ndata: {broken" ) ), 'malformed final frame invalidates earlier completion' );
    $result = call_user_func( $parse, 'data: {"type":"response.completed","response":{"usage":"synthetic-secret"}}' );
    check( is_array( $result ) && $result['usage'] === array(), 'wrong usage schema becomes an empty usage array' );
    check( is_wp_error( call_user_func( $parse, $partial . "data: [DONE]\n\n" ) ), 'DONE does not accept a partial answer' );
    $request = array( 'AceMedia\\SharedAI\\V1\\Responses', 'request' );
    check( is_wp_error( call_user_func( $request, 'synthetic-key', '', array() ) ) && 0 === $http_calls, 'missing model makes no HTTP call' );
    $http_reply = array( 'response' => array( 'code' => 200 ), 'body' => $body );
    $result = call_user_func( $request, 'synthetic-key', 'synthetic-model', array( array( 'role' => 'user', 'content' => 'Synthetic prompt' ) ) );
    check( $result['message'] === $unicode && 1 === $http_calls, 'synthetic HTTP success uses real parser' );
    $sent = json_decode( $http_request[1]['body'], true );
    check( false === $sent['store'] && true === $sent['stream'] && 0 === $http_request[1]['redirection'] && 2097152 === $http_request[1]['limit_response_size'], 'provider privacy and response bounds retained' );
    $http_reply = array( 'response' => array( 'code' => 401 ), 'body' => 'synthetic-secret' );
    $result = call_user_func( $request, 'synthetic-key', 'synthetic-model', array() );
    check( is_wp_error( $result ) && false === strpos( json_encode( $result ), 'synthetic-secret' ) && false === strpos( json_encode( $result ), 'synthetic-key' ), 'HTTP failure retains neither response body nor key' );
    $http_reply = new WP_Error( 'transport', 'synthetic-secret' );
    $result = call_user_func( $request, 'synthetic-key', 'synthetic-model', array() );
    check( is_wp_error( $result ) && false === strpos( json_encode( $result ), 'synthetic-secret' ), 'transport failure does not reuse provider messages' );
    $repo = new \ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository();
    $safe = new ReflectionMethod( $repo, 'safe_row' );
    if ( PHP_VERSION_ID < 80100 ) { $safe->setAccessible( true ); }
    foreach ( array( '', '{"ip":', "\xB1\x31", 'null', '42', '[]', '{"ip":"203.0.113.10","token":"synthetic-secret","email":"private@example.invalid"}' ) as $index => $payload ) {
        $row = $safe->invoke( $repo, array( 'id' => 7, 'name' => 'Synthetic company', 'source_provider' => 'ipinfo', 'source_payload' => $payload ) );
        check( $row['id'] === 7 && $row['name'] === 'Synthetic company' && is_array( json_decode( $row['source_payload'], true ) ), 'repository identity and JSON shape retained ' . $index );
        check( false === strpos( $row['source_payload'], '203.0.113.10' ) && false === strpos( $row['source_payload'], 'synthetic-secret' ) && false === strpos( $row['source_payload'], 'private@example.invalid' ), 'provider payload redacted ' . $index );
    }
    $payload = '{"consent":true,"company":"Synthetic company"}';
    $row = $safe->invoke( $repo, array( 'source_provider' => 'form_submission', 'source_payload' => $payload ) );
    check( $row['source_payload'] === $payload, 'declared non-provider data contract retained' );
    echo "Completed $checks CRM JSON checks on PHP ", PHP_VERSION, "; no WordPress bootstrap, database, network or mail\n";
} finally { restore_error_handler(); }
