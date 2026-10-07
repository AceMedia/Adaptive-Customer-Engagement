<?php
require __DIR__ . '/shared-ai-connection-test.php';
use AceMedia\SharedAI\V1\OAuth;
use AceMedia\SharedAI\V1\Responses;
use AceMedia\SharedAI\V1\Session;
$start = $count;
function b64( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); }
$randfile = sys_get_temp_dir() . '/ace-test-rand-' . getmypid();
putenv( 'RANDFILE=' . $randfile );
$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
$details = openssl_pkey_get_details( $key );
$jwks = array( 'keys' => array( array( 'kid' => 'fixture', 'kty' => 'RSA', 'alg' => 'RS256', 'n' => b64( $details['rsa']['n'] ), 'e' => b64( $details['rsa']['e'] ) ) ) );
$claims = array( 'iss' => 'https://auth.openai.com', 'aud' => 'issued-fixture', 'exp' => 2000, 'iat' => 900, 'nonce' => 'nonce-fixture', 'sub' => 'user-fixture' );
$sign = function( $claims, $alg = 'RS256' ) use( $key ) { $payload = b64( json_encode( array( 'alg' => $alg, 'kid' => 'fixture' ) ) ) . '.' . b64( json_encode( $claims ) ); openssl_sign( $payload, $sig, $key, OPENSSL_ALGO_SHA256 ); return $payload . '.' . b64( $sig ); };
check( is_array( OAuth::validate( $sign( $claims ), 'issued-fixture', 'nonce-fixture', $jwks, 1000 ) ), 'Valid RS256 identity accepted' );
foreach( array( 'iss' => 'https://other.example', 'aud' => 'other-client', 'exp' => 999, 'nonce' => 'wrong', 'sub' => '', 'iat' => 9999, 'nbf' => 9999 ) as $field => $value ) {
 $bad = $claims; $bad[$field] = $value;
 check( is_wp_error( OAuth::validate( $sign( $bad ), 'issued-fixture', 'nonce-fixture', $jwks, 1000 ) ), 'Reject invalid claim ' . $field );
}
check( is_wp_error( OAuth::validate( $sign( $claims, 'none' ), 'issued-fixture', 'nonce-fixture', $jwks, 1000 ) ), 'Reject algorithm substitution' );
check( is_wp_error( OAuth::validate( $sign( $claims ), 'issued-fixture', 'nonce-fixture', $jwks, 1000, 'other-user' ) ), 'Returning identity mismatch rejected' );
check( is_wp_error( OAuth::validate( $sign( $claims ), 'dynamic_agent_client', 'nonce-fixture', $jwks, 1000 ) ), 'Registration placeholder never authenticates token' );
$bad = $claims; $bad['aud'] = array( 'issued-fixture', 'other-client' );
check( is_wp_error( OAuth::validate( $sign( $bad ), 'issued-fixture', 'nonce-fixture', $jwks, 1000 ) ), 'Multiple audiences require matching authorised party' );
$body = 'data: {"type":"response.output_text.delta","delta":"A useful answer"}' . "\n\n";
check( is_wp_error( Responses::parse( $body ) ), 'Partial stream not a success' );
check( is_wp_error( Responses::parse( $body . 'data: {"type":"response.failed"}' ) ), 'Late failure discards partial answer' );
check( is_wp_error( Responses::parse( $body . 'data: {"type":"response.incomplete"}' ) ), 'Incomplete answer rejected' );
check( is_wp_error( Responses::parse( 'data: {broken' ) ), 'Malformed event rejected' );
check( 'A useful answer' === Responses::parse( $body . 'data: {"type":"response.completed"}' )['message'], 'Terminal completion required' );
check( is_wp_error( Responses::parse( $body . "data: [DONE]\n\n" ) ), 'DONE alone is not completion' );
$tokens = array( 'client_id'=>'issued-fixture', 'access_token'=>'old-access', 'refresh_token'=>'old-refresh', 'expires_at'=>990, 'scopes'=>array('chatgpt.tokens.use.direct') );
$stored = $tokens; $locked = false; $refreshes = 0;
$load = function() use(&$stored) {return $stored;};
$cas = function($old,$next) use(&$stored) { if($old!==$stored)return false; $stored=$next;return true; };
$lock = function() use(&$locked) { if($locked)return false; return $locked='owner'; };
$unlock = function($owner) use(&$locked) { if($locked===$owner)$locked=false; };
$refresh = function($record) use(&$refreshes) {++$refreshes;return array('access_token'=>'new-access','refresh_token'=>'new-refresh','expires_in'=>3600,'token_type'=>'Bearer');};
check('new-access'===Session::access($load,$cas,$lock,$unlock,$refresh,1000),'Expired token refreshes');
check($stored['refresh_token']==='new-refresh' && $stored['expires_at']===4600,'Rotation and expiry saved together');
check(!$locked,'Refresh lock released');
check('new-access'===Session::access($load,$cas,$lock,$unlock,$refresh,1000) && $refreshes===1,'Fresh session not refreshed twice');
$stored=$tokens;$locked='another-owner';
check(is_wp_error(Session::access($load,$cas,$lock,$unlock,$refresh,1000)) && $refreshes===1,'Competing worker cannot rotate token');
$locked=false;
check(is_wp_error(Session::access($load,static function(){return false;},$lock,$unlock,$refresh,1000)),'Concurrent disconnect/reconnect CAS fails closed');
check(!$locked,'CAS failure releases lock');
$stored=$tokens;
$denied=static function(){return array('error'=>'invalid_grant');};
check(is_wp_error(Session::access($load,$cas,$lock,$unlock,$denied,1000)) && !isset($stored['refresh_token']),'Revoked session clears tokens');
$stored=$tokens;
$failed=static function(){return new WP_Error('transport','Unavailable');};
check(is_wp_error(Session::access($load,$cas,$lock,$unlock,$failed,1000)) && $stored===$tokens,'Network failure preserves renewable state for retry');
$wrong=static function(){return array('access_token'=>'new','refresh_token'=>'rotated','expires_in'=>3600,'token_type'=>'Bearer','scope'=>'openid');};
check(is_wp_error(Session::access($load,$cas,$lock,$unlock,$wrong,1000)),'Missing plan grant after refresh cannot infer');
check(!$locked,'Failed grant releases lock');
echo ($count-$start) . " OAuth, stream and refresh checks passed\n";

if ( file_exists( $randfile ) ) { unlink( $randfile ); }
