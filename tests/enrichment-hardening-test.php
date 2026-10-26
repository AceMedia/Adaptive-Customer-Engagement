<?php
/** Run with wp eval-file, --skip-plugins --skip-themes and a local --url. */
use ACE\AdaptiveCustomerEngagement\Enrichment\EnrichmentResult;
use ACE\AdaptiveCustomerEngagement\Enrichment\IpregistryProvider;
use ACE\AdaptiveCustomerEngagement\Enrichment\IpinfoProvider;
use ACE\AdaptiveCustomerEngagement\Tracking\Privacy;

$ace_root = dirname( __DIR__ );
foreach ( array( 'Settings.php', 'Enrichment/DnsIntel.php', 'Enrichment/EnrichmentResult.php', 'Enrichment/ProviderInterface.php', 'Enrichment/IpregistryProvider.php', 'Enrichment/IpinfoProvider.php', 'Tracking/Privacy.php', 'Database/Schema.php', 'Database/Repositories/EnrichmentCacheRepository.php', 'Database/Repositories/CompanyRepository.php', 'Database/Repositories/SessionRepository.php', 'Database/Repositories/IpCompanyMemoryRepository.php', 'Enrichment/ProviderRegistry.php', 'Enrichment/EnrichmentService.php' ) as $ace_file ) {
	require_once $ace_root . '/includes/' . $ace_file;
}

function ace_check( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
}
add_filter( 'pre_option_ace_settings', static function () {
	return array( 'enrichment' => array( 'provider' => 'ipregistry', 'api_key' => 'fixture', 'enrich_private_ips' => true ) );
} );
add_filter( 'pre_option_ace_hash_salt', static function () { return 'fixture-salt'; } );
$ace_http = null;
add_filter( 'pre_http_request', static function () use ( &$ace_http ) { return $ace_http; } );
$ace_fixture = static function ( int $status, string $body, array $headers = array() ) use ( &$ace_http ): void {
	$ace_http = array( 'headers' => $headers, 'body' => $body, 'response' => array( 'code' => $status, 'message' => '' ), 'cookies' => array() );
};
$ace_registry = new IpregistryProvider();
$ace_info = new IpinfoProvider();
foreach ( array( 401, 403, 402, 429, 500, 503 ) as $ace_status ) {
	$ace_fixture( $ace_status, '{"code":"ACCESS_DENIED","message":"secret 203.0.113.5"}', array( 'retry-after' => '120' ) );
	$ace_result = $ace_registry->lookup( '203.0.113.5' );
	ace_check( $ace_result->is_error() && 120 === $ace_result->retry_after && false === strpos( wp_json_encode( $ace_result->to_array() ), '203.0.113.5' ), 'ipregistry HTTP failure ' . $ace_status );
}
$ace_fixture( 400, '{"code":"RESERVED_IP_ADDRESS","message":"Reserved"}' );
ace_check( 'RESERVED_IP_ADDRESS' === $ace_registry->lookup( '203.0.113.5' )->error_code, 'Reserved address is an error' );
$ace_fixture( 500, '<html>failure</html>' );
ace_check( $ace_registry->lookup( '203.0.113.5' )->is_error(), 'HTML failure is an error' );
$ace_fixture( 200, '{broken' );
ace_check( $ace_registry->lookup( '203.0.113.5' )->is_error(), 'Malformed JSON is an error' );
$ace_fixture( 200, '{}' );
ace_check( $ace_registry->lookup( '203.0.113.5' )->is_error(), 'Empty payload is an error' );
$ace_http = new WP_Error( 'transport', 'secret 203.0.113.5' );
ace_check( false === strpos( wp_json_encode( $ace_registry->lookup( '203.0.113.5' )->to_array() ), '203.0.113.5' ), 'Transport message is not retained' );
$ace_fixture( 429, '{"error":{"title":"Rate limit exceeded","message":"secret"}}', array( 'retry-after' => '600' ) );
ace_check( $ace_info->lookup( '203.0.113.5' )->is_error(), 'IPinfo error response' );
$ace_fixture( 403, '{"error":"Forbidden"}' );
ace_check( $ace_info->lookup( '203.0.113.5' )->is_error(), 'IPinfo string error response' );
$ace_fixture( 200, '{"ip":"203.0.113.5","company":{"name":"Example Ltd","domain":"example.test","type":"business"},"connection":{"type":"business"}}' );
$ace_result = $ace_registry->lookup( '203.0.113.5' );
ace_check( ! $ace_result->is_error() && 'likely' === $ace_result->confidence, 'Untyped network fallback is available' );
ace_check( false === strpos( wp_json_encode( $ace_result->to_array() ), '203.0.113.5' ), 'Provider IP is absent from cache payload' );
$ace_fixture( 200, '{"company":{"name":"Example ISP","domain":"example.test"},"connection":{"type":"isp"}}' );
ace_check( 'weak' === $ace_registry->lookup( '203.0.113.5' )->confidence, 'ISP remains weak' );
$ace_fixture( 200, '{"company":{"name":"Example Ltd"},"location":{"country":{"code":"GB"}}}' );
ace_check( ! $ace_registry->lookup( '203.0.113.5' )->is_error(), 'Partial valid payload succeeds' );
$ace_legacy = EnrichmentResult::from_array( array( 'raw' => array( 'ip' => '203.0.113.5', 'ptr' => 'host.example.test', 'nested' => array( 'ip' => '203.0.113.6' ), 'network_kind' => 'isp' ) ) );
ace_check( isset( $ace_legacy->raw['network_kind'], $ace_legacy->raw['ptr_checked'] ) && ! isset( $ace_legacy->raw['ptr'] ) && false === strpos( wp_json_encode( $ace_legacy->raw ), '203.0.113' ), 'Legacy identifiers are hidden and checked PTR is remembered' );
ace_check( EnrichmentResult::from_array( array( 'raw' => array( 'code' => 'RESERVED_IP_ADDRESS', 'message' => 'secret' ) ) )->is_error(), 'Legacy code/message is an error' );
$ace_privacy = new Privacy();
$_SERVER['REMOTE_ADDR'] = '198.51.100.11';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 9.9.9.9';
ace_check( '198.51.100.11' === $ace_privacy->get_client_ip(), 'Spoofed proxy headers ignored' );
$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
ace_check( '2001:db8::1' === $ace_privacy->get_client_ip(), 'IPv6 remote supported' );
$_SERVER['REMOTE_ADDR'] = 'invalid';
ace_check( '' === $ace_privacy->get_client_ip(), 'Malformed remote rejected' );
add_filter( 'ace_client_ip', static function () { return 'not-an-ip'; } );
ace_check( '' === $ace_privacy->get_client_ip(), 'Invalid explicit override rejected' );
remove_all_filters( 'ace_client_ip' );
add_filter( 'ace_client_ip', static function () { return '8.8.8.8'; } );
ace_check( '8.8.8.8' === $ace_privacy->get_client_ip(), 'Explicit validated resolver is available' );
remove_all_filters( 'ace_client_ip' );

class AceFixtureDb {
	public $prefix = 'wp_fixture_';
	public $memory = null;
	public $company = null;
	public $cache_row = null;
	public $session = null;
	public $replacements = array();
	public $updates = array();
	public $queries = array();
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_row( $sql, $format = null ) {
		if ( false !== strpos( $sql, 'enrichment_cache' ) ) { return $this->cache_row; }
		if ( false !== strpos( $sql, 'FROM wp_fixture_ace_sessions' ) ) { return $this->session; }
		return false !== strpos( $sql, 'ip_company_memory' ) ? $this->memory : $this->company;
	}
	public function update( $table, $data, $where ) { $this->updates[] = array( $table, $data, $where ); return 1; }
	public function replace( $table, $data ) { $this->replacements[] = array( $table, $data ); return 1; }
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
}
global $wpdb;
$ace_original_db = $wpdb;
$wpdb = new AceFixtureDb();
$ace_memory = new ACE\AdaptiveCustomerEngagement\Database\Repositories\IpCompanyMemoryRepository();
$ace_companies = new ACE\AdaptiveCustomerEngagement\Database\Repositories\CompanyRepository();
$ace_service = new ACE\AdaptiveCustomerEngagement\Enrichment\EnrichmentService(
	new ACE\AdaptiveCustomerEngagement\Enrichment\ProviderRegistry(),
	new ACE\AdaptiveCustomerEngagement\Database\Repositories\EnrichmentCacheRepository(),
	$ace_companies,
	new ACE\AdaptiveCustomerEngagement\Database\Repositories\SessionRepository(),
	$ace_privacy,
	$ace_memory
);
$ace_recall = new ReflectionMethod( $ace_service, 'recall_from_memory' );
$ace_session = array( 'company_id' => null, 'company_confidence' => 'unknown', 'event_count' => 0 );
$wpdb->memory = array( 'company_id' => 42, 'company_name' => 'Example Ltd', 'confidence' => 'confirmed', 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
$ace_recalled = $ace_recall->invoke( $ace_service, 5, '8.8.8.8', false, $ace_session );
ace_check( 'likely' === $ace_recalled['confidence'] && 'likely' === $wpdb->updates[0][1]['company_confidence'], 'Recent IP recall is at most likely' );
add_filter( 'ace_ip_memory_max_age_days', static function () { return 0; } );
ace_check( null === $ace_recall->invoke( $ace_service, 5, '8.8.8.8', false, $ace_session ), 'IP recall can be disabled' );
remove_all_filters( 'ace_ip_memory_max_age_days' );
$wpdb->memory['last_seen'] = gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS );
ace_check( null === $ace_recall->invoke( $ace_service, 5, '8.8.8.8', false, $ace_session ), 'Old IP memory ignored' );
$wpdb->memory['last_seen'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
ace_check( null === $ace_recall->invoke( $ace_service, 5, '8.8.8.8', false, $ace_session ), 'Future IP memory ignored' );
$wpdb->memory['last_seen'] = gmdate( 'Y-m-d H:i:s' );
ace_check( null === $ace_recall->invoke( $ace_service, 5, '8.8.8.8', true, $ace_session ), 'Bot IP memory ignored' );
ace_check( null === $ace_recall->invoke( $ace_service, 5, '10.0.0.1', false, $ace_session ), 'Private IP memory ignored' );
$ace_session['company_confidence'] = 'confirmed';
ace_check( null === $ace_recall->invoke( $ace_service, 5, '8.8.8.8', false, $ace_session ), 'Direct confirmation retained' );
$wpdb->company = array( 'id' => 7, 'name' => 'Example Ltd', 'domain' => 'example.test', 'confidence' => 'confirmed', 'source_provider' => 'woocommerce_order' );
$ace_company_result = new EnrichmentResult();
$ace_company_result->company_name = 'Example Ltd';
$ace_company_result->company_domain = 'example.test';
$ace_company_result->confidence = 'weak';
$ace_company_result->provider = 'ipregistry';
$ace_company_result->raw = array( 'ip' => '8.8.8.8' );
$ace_companies->create_or_touch_from_result( $ace_company_result );
$ace_last_update = end( $wpdb->updates );
ace_check( ! isset( $ace_last_update[1]['confidence'] ) && ! isset( $ace_last_update[1]['source_payload'] ), 'Provider touch preserves declared company' );
$ace_safe_row = new ReflectionMethod( $ace_companies, 'safe_row' );
$ace_legacy_company = $ace_safe_row->invoke( $ace_companies, array( 'source_provider' => 'woocommerce_order', 'source_payload' => '{"ip":"8.8.8.8","company":{"name":"Example Ltd"}}' ) );
ace_check( array() === json_decode( $ace_legacy_company['source_payload'], true ), 'Old provider payload hidden after direct declaration' );
$ace_cache = new ACE\AdaptiveCustomerEngagement\Database\Repositories\EnrichmentCacheRepository();
$wpdb->cache_row = array( 'response' => '{"raw":{"code":"RESERVED_IP_ADDRESS","message":"reserved"}}', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
ace_check( null === $ace_cache->find_valid( 'ipregistry', 'hash' ), 'Old long lived error is retried' );
$wpdb->cache_row['created_at'] = gmdate( 'Y-m-d H:i:s' );
ace_check( $ace_cache->find_valid( 'ipregistry', 'hash' )->is_error(), 'Recent legacy error remains cached briefly' );
$wpdb->cache_row = array( 'response' => '{"company_domain":"example.test","confidence":"likely","raw":{"ptr":"mail.example.test","ip":"8.8.8.8"}}', 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
$ace_cached = $ace_cache->find_valid( 'ipregistry', 'hash' );
ace_check( ! empty( $ace_cached->raw['ptr_checked'] ) && ! isset( $ace_cached->raw['ptr'] ), 'Legacy success avoids another PTR lookup' );
$ace_hosting_cache = EnrichmentResult::from_array( array( 'raw' => array( 'ptr' => 'vm.amazonaws.com' ), 'is_hosting' => false ) );
ace_check( ! empty( $ace_hosting_cache->raw['ptr_checked'] ) && ! empty( $ace_hosting_cache->raw['ptr_hosting'] ), 'Legacy hosting PTR becomes a safe cached flag' );
$wpdb->cache_row = null;
$wpdb->session = $ace_session = array( 'id' => 5, 'company_id' => null, 'company_confidence' => 'unknown', 'event_count' => 0 );
$wpdb->memory = null;
$ace_fixture( 429, '{"code":"RATE_LIMITED","message":"secret"}', array( 'retry-after' => '1200' ) );
$ace_update_count = count( $wpdb->updates );
ace_check( null === $ace_service->enrich_session( 5, '8.8.8.8' ), 'Provider error leaves session unchanged' );
ace_check( $ace_update_count === count( $wpdb->updates ) && 1 === count( $wpdb->replacements ), 'Error only writes the short cache entry' );
$ace_expiry = strtotime( $wpdb->replacements[0][1]['expires_at'] . ' UTC' ) - time();
ace_check( $ace_expiry >= 1195 && $ace_expiry <= 1200, 'Retry-After sets bounded error cache lifetime' );
$wpdb->memory = array( 'company_id' => 42, 'company_name' => 'Example Ltd', 'confidence' => 'confirmed', 'last_seen' => gmdate( 'Y-m-d H:i:s' ) );
$wpdb->session = array( 'id' => 5, 'company_id' => 42, 'company_confidence' => 'likely', 'event_count' => 3, 'is_bot' => 0 );
$wpdb->queries = array();
$ace_update_count = count( $wpdb->updates );
ace_check( 42 === $ace_service->reenrich_session( 5, '8.8.8.8' )['company_id'], 'Same memory company still resolves' );
ace_check( 0 === count( $wpdb->queries ) && $ace_update_count === count( $wpdb->updates ), 'Repeated same-company recall does not inflate totals' );
$wpdb->session['company_confidence'] = 'weak';
ace_check( 42 === $ace_service->reenrich_session( 5, '8.8.8.8' )['company_id'], 'Same memory company can improve weak confidence' );
ace_check( 0 === count( $wpdb->queries ) && $ace_update_count + 1 === count( $wpdb->updates ), 'Confidence improvement leaves company totals alone' );
$wpdb->session['company_id'] = 7;
$wpdb->session['company_confidence'] = 'weak';
ace_check( 42 === $ace_service->reenrich_session( 5, '8.8.8.8' )['company_id'], 'Recent memory can replace a weak provider match' );
ace_check( 2 === count( $wpdb->queries ) && false !== strpos( $wpdb->queries[0], 'total_sessions - LEAST(total_sessions, 1)' ) && false !== strpos( $wpdb->queries[0], 'total_events - LEAST(total_events, %d)' ) && false !== strpos( $wpdb->queries[1], 'total_sessions + 1' ), 'Company switch balances unsigned totals safely' );
$wpdb = $ace_original_db;
echo "Enrichment provider, payload, IP trust and memory checks passed\n";
