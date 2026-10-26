<?php
/** Standalone maintenance fixture: php8.4 tests/enrichment-scrub-test.php. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );
define( 'ARRAY_A', 'ARRAY_A' );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_site( $id ) { return 1 === $id; }
function switch_to_blog( $id ) { return true; }
function restore_current_blog() { return true; }
class WP_CLI {
	public static $lines = array();
	public static function error( $message ) { throw new RuntimeException( $message ); }
	public static function line( $message ) { self::$lines[] = json_decode( $message, true ); }
}
class AceScrubFixtureDb {
	public $prefix = 'wp_';
	public $updates = 0;
	public $rows = array(
		'wp_ace_enrichment_cache' => array( array( 'id' => 1, 'source' => 'ipregistry', 'payload' => '{"confidence":"likely","raw":{"ip":"8.8.8.8","network_kind":"isp"}}' ) ),
		'wp_ace_companies' => array( array( 'id' => 2, 'source' => 'woocommerce_order', 'payload' => '{"ip":"8.8.8.8","company":{"name":"Example"}}' ) ),
	);
	public function prepare( $query, ...$values ) {
		if ( 1 === count( $values ) && is_array( $values[0] ) ) { $values = $values[0]; }
		return vsprintf( $query, array_map( static function ( $value ) { return is_int( $value ) ? $value : "'" . $value . "'"; }, $values ) );
	}
	public function get_results( $query, $format ) {
		$table = false !== strpos( $query, 'wp_ace_enrichment_cache' ) ? 'wp_ace_enrichment_cache' : 'wp_ace_companies';
		preg_match( '/id > (\d+)/', $query, $match );
		$after = (int) ( $match[1] ?? 0 );
		return array_values( array_filter( $this->rows[ $table ], static function ( $row ) use ( $after ) { return $row['id'] > $after; } ) );
	}
	public function update( $table, $data, $where ) {
		++$this->updates;
		foreach ( $this->rows[ $table ] as &$row ) {
			if ( $row['id'] === $where['id'] ) { $row['payload'] = reset( $data ); }
		}
		return 1;
	}
}
$wpdb = new AceScrubFixtureDb();
$script = dirname( __DIR__ ) . '/bin/ace-scrub-enrichment-payloads.php';
$run = static function ( array $arguments ) use ( $script ): array {
	$args = $arguments;
	include $script;
	return end( WP_CLI::$lines );
};
$dry = $run( array( 'blog=1', 'limit=500' ) );
if ( 0 !== $wpdb->updates || 1 !== $dry['tables']['companies']['changed'] || 1 !== $dry['tables']['enrichment_cache']['changed'] ) {
	throw new RuntimeException( 'Dry run changed data or missed provider JSON.' );
}
try {
	$run( array( 'blog=1', 'apply' ) );
	throw new RuntimeException( 'Write without backup confirmation succeeded.' );
} catch ( RuntimeException $error ) {
	if ( false === strpos( $error->getMessage(), 'backup' ) ) { throw $error; }
}
$run( array( 'blog=1', 'apply', 'backup-confirmed' ) );
if ( 2 !== $wpdb->updates || false !== strpos( json_encode( $wpdb->rows ), '8.8.8.8' ) ) {
	throw new RuntimeException( 'Apply did not scrub both payloads.' );
}
$repeat = $run( array( 'blog=1', 'apply', 'backup-confirmed' ) );
if ( 2 !== $wpdb->updates || 0 !== $repeat['tables']['companies']['changed'] || 0 !== $repeat['tables']['enrichment_cache']['changed'] ) {
	throw new RuntimeException( 'Scrub is not idempotent.' );
}
echo "Enrichment scrub dry-run, apply and idempotence checks passed\n";
