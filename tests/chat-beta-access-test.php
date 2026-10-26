<?php
/** Focused access and grant checks without a WordPress bootstrap. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$blog_id  = 1;
$admin    = false;
$front    = true;
$home     = 'https://iegemea.test';
$checks   = 0;

function is_multisite() { return true; }
function is_main_site() { global $blog_id; return 1 === $blog_id; }
function is_front_page() { global $front; return $front; }
function get_current_blog_id() { global $blog_id; return $blog_id; }
function current_user_can( $capability ) { global $admin; return $admin && 'manage_ace_engagement' === $capability; }
function wp_salt( $scheme ) { return 'test-auth-salt-' . $scheme; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path ) { global $home; return $home . $path; }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function nocache_headers() {}

class WP_REST_Request {
	private $headers;
	public function __construct( array $headers = array() ) { $this->headers = $headers; }
	public function get_header( $name ) { return $this->headers[ $name ] ?? ''; }
}

require_once dirname( __DIR__ ) . '/includes/Security/Capabilities.php';
require_once dirname( __DIR__ ) . '/includes/Security/ChatAccess.php';

use ACE\AdaptiveCustomerEngagement\Security\ChatAccess;

function check( $passed, $message ) {
	global $checks;
	++$checks;
	if ( ! $passed ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$settings = array( 'frontend_chat_admin_only' => true, 'frontend_chat_beta_enabled' => true );
$_SERVER['REQUEST_URI'] = '/?beta';
$_GET = array( 'beta' => '' );
check( ChatAccess::is_beta_preview_page( $settings ), 'Bare beta homepage qualifies' );
check( ! ChatAccess::can_use_chat( $settings, new WP_REST_Request() ), 'Plain guest REST request denied' );
$grant = ChatAccess::issue_grant();
check( ChatAccess::valid_grant( $grant ), 'Fresh grant verifies' );
check( ChatAccess::can_use_chat( $settings, new WP_REST_Request( array( 'x-ace-chat-beta' => $grant ) ) ), 'Beta grant allows guest' );
check( ! ChatAccess::valid_grant( substr( $grant, 0, -1 ) . ( 'a' === substr( $grant, -1 ) ? 'b' : 'a' ) ), 'Tampered grant denied' );
$parts = explode( '.', $grant );
$expired = time() - 1;
$body = 'v1.' . $expired . '.' . $parts[2];
$expired_grant = $body . '.' . hash_hmac( 'sha256', '1|https://iegemea.test/|' . $body, wp_salt( 'auth' ) );
check( ! ChatAccess::valid_grant( $expired_grant ), 'Expired signed grant denied' );
$expires_now = time();
$body = 'v1.' . $expires_now . '.' . $parts[2];
$expires_now_grant = $body . '.' . hash_hmac( 'sha256', '1|https://iegemea.test/|' . $body, wp_salt( 'auth' ) );
check( ! ChatAccess::valid_grant( $expires_now_grant ), 'Grant expiring now denied' );
$home = 'https://preview.iegemea.test';
check( ! ChatAccess::valid_grant( $grant ), 'Grant cannot cross cloned site origins' );
$home = 'https://iegemea.test';
$blog_id = 7;
check( ! ChatAccess::valid_grant( $grant ), 'Grant cannot cross blog boundaries' );
check( ! ChatAccess::beta_enabled( $settings ), 'Beta cannot be enabled on a subsite' );
$blog_id = 1;
$admin = true;
check( ChatAccess::can_use_chat( $settings, new WP_REST_Request() ), 'Admin access stays available without a grant' );
$admin = false;
check( ! ChatAccess::can_use_chat( array( 'frontend_chat_admin_only' => true, 'frontend_chat_beta_enabled' => false ), new WP_REST_Request( array( 'x-ace-chat-beta' => $grant ) ) ), 'Disabled beta rejects even a valid grant' );
$_SERVER['REQUEST_URI'] = '/products/?beta';
check( ! ChatAccess::is_beta_query_request(), 'Inner-page beta query denied' );
$_SERVER['REQUEST_URI'] = '/?beta%5B%5D=1';
$_GET = array( 'beta' => array( '1' ) );
check( ! ChatAccess::is_beta_query_request(), 'Array beta query denied' );
$_SERVER['REQUEST_URI'] = '/?beta=0';
$_GET = array( 'beta' => '0' );
check( ! ChatAccess::is_beta_query_request(), 'Wrong beta value denied' );
echo "{$checks} chat beta access checks passed\n";
