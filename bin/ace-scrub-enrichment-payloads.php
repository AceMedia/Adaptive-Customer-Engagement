<?php
/** Dry-run-first maintenance for historical provider JSON. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}
require_once dirname( __DIR__ ) . '/includes/Enrichment/EnrichmentResult.php';
require_once dirname( __DIR__ ) . '/includes/Enrichment/DnsIntel.php';
use ACE\AdaptiveCustomerEngagement\Enrichment\EnrichmentResult;

$ace_options = array( 'blog' => 0, 'limit' => 500, 'cache-after-id' => 0, 'company-after-id' => 0, 'apply' => false, 'backup-confirmed' => false );
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $ace_arg ) {
	if ( preg_match( '/^(blog|limit|cache-after-id|company-after-id)=(\d+)$/', (string) $ace_arg, $ace_match ) ) {
		$ace_options[ $ace_match[1] ] = (int) $ace_match[2];
	} elseif ( 'apply' === $ace_arg || 'backup-confirmed' === $ace_arg ) {
		$ace_options[ $ace_arg ] = true;
	} else {
		WP_CLI::error( 'Unrecognised argument.' );
	}
}
if ( $ace_options['blog'] < 1 || $ace_options['limit'] < 1 || $ace_options['limit'] > 5000 ) {
	WP_CLI::error( 'Set blog to a valid site ID and limit between 1 and 5000.' );
}
if ( $ace_options['apply'] && ! $ace_options['backup-confirmed'] ) {
	WP_CLI::error( 'Take a scoped database backup, then pass backup-confirmed with apply.' );
}
if ( ! get_site( $ace_options['blog'] ) ) {
	WP_CLI::error( 'The selected site does not exist.' );
}

switch_to_blog( $ace_options['blog'] );
global $wpdb;
$ace_counts = array();
foreach ( array( 'enrichment_cache' => array( 'response', 'provider' ), 'companies' => array( 'source_payload', 'source_provider' ) ) as $ace_suffix => $ace_columns ) {
	$ace_table = $wpdb->prefix . 'ace_' . $ace_suffix;
	$ace_after_id = 'companies' === $ace_suffix ? $ace_options['company-after-id'] : $ace_options['cache-after-id'];
	$ace_where = 'companies' === $ace_suffix ? 'source_payload IS NOT NULL' : 'provider IN (%s, %s, %s)';
	$ace_params = 'companies' === $ace_suffix
		? array( $ace_after_id, $ace_options['limit'] )
		: array( $ace_after_id, 'ipregistry', 'ipinfo', 'keyless', $ace_options['limit'] );
	$ace_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, {$ace_columns[0]} AS payload, {$ace_columns[1]} AS source FROM {$ace_table} WHERE id > %d AND {$ace_where} ORDER BY id ASC LIMIT %d",
			$ace_params
		), ARRAY_A
	);
	if ( ! is_array( $ace_rows ) || '' !== (string) $wpdb->last_error ) {
		WP_CLI::error( 'Could not read the ' . $ace_suffix . ' batch. No cursor was advanced.' );
	}
	$ace_changed = 0;
	foreach ( (array) $ace_rows as $ace_row ) {
		$ace_data = json_decode( (string) $ace_row['payload'], true );
		if ( ! is_array( $ace_data ) ) {
			WP_CLI::error( 'Invalid provider JSON in ' . $ace_suffix . ' row ' . (int) $ace_row['id'] . '. No cursor was advanced.' );
		}
		if ( 'companies' === $ace_suffix && ! in_array( (string) $ace_row['source'], array( 'ipregistry', 'ipinfo', 'keyless' ), true ) && ! EnrichmentResult::looks_like_provider_raw( $ace_data ) ) {
			continue;
		}
		if ( 'response' === $ace_columns[0] ) {
			if ( ! isset( $ace_data['raw'] ) || ! is_array( $ace_data['raw'] ) ) {
				WP_CLI::error( 'Unrecognised cache JSON in row ' . (int) $ace_row['id'] . '. No cursor was advanced.' );
			}
			$ace_data['raw'] = EnrichmentResult::from_array( $ace_data )->to_array()['raw'];
		} else {
			$ace_data = EnrichmentResult::safe_raw( $ace_data );
		}
		$ace_json = wp_json_encode( $ace_data );
		if ( ! is_string( $ace_json ) ) {
			WP_CLI::error( 'Could not encode ' . $ace_suffix . ' row ' . (int) $ace_row['id'] . '. No cursor was advanced.' );
		}
		if ( $ace_json === $ace_row['payload'] ) {
			continue;
		}
		if ( $ace_options['apply'] ) {
			$ace_updated = $wpdb->update( $ace_table, array( $ace_columns[0] => $ace_json ), array( 'id' => (int) $ace_row['id'] ) );
			if ( 1 !== $ace_updated ) {
				WP_CLI::error( 'Could not update ' . $ace_suffix . ' row ' . (int) $ace_row['id'] . '. No cursor was advanced.' );
			}
		}
		++$ace_changed;
	}
	$ace_counts[ $ace_suffix ] = array( 'scanned' => count( (array) $ace_rows ), 'changed' => $ace_changed, 'last_id' => $ace_rows ? (int) end( $ace_rows )['id'] : $ace_after_id );
}
restore_current_blog();
WP_CLI::line( wp_json_encode( array( 'site' => $ace_options['blog'], 'mode' => $ace_options['apply'] ? 'apply' : 'dry-run', 'tables' => $ace_counts ) ) );
