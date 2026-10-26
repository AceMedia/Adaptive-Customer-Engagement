<?php
/** Run with wp eval-file on a local site; all fixture writes roll back. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'WP_LOCAL_DEV' ) || ! WP_LOCAL_DEV ) { exit; }
use ACE\AdaptiveCustomerEngagement\Database\Repositories\BusinessAddressRepository;
use ACE\AdaptiveCustomerEngagement\Database\Schema;
use ACE\AdaptiveCustomerEngagement\Tracking\Privacy;

function ace_places_privacy_check( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException( $message ); }
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
	$companies = Schema::table_name( 'companies' );
	$now = current_time( 'mysql', true );
	$wpdb->insert( $companies, array( 'name' => 'Fictional Places Fixture A', 'confidence' => 'confirmed', 'source_provider' => 'form_submission', 'created_at' => $now, 'updated_at' => $now ) );
	$first = (int) $wpdb->insert_id;
	$wpdb->insert( $companies, array( 'name' => 'Fictional Places Fixture B', 'confidence' => 'confirmed', 'source_provider' => 'form_submission', 'created_at' => $now, 'updated_at' => $now ) );
	$second = (int) $wpdb->insert_id;
	$repo = new BusinessAddressRepository();
	$address = array( 'line_1' => '12 Sample Street', 'postcode' => 'AB1 2CD', 'country_code' => 'GB' );
	$id = $repo->capture( $first, $address, 'form_submission', 'fixture-101', 'fixture@example.test' );
	ace_places_privacy_check( $id > 0 && ! $repo->verify( $id, $second, 1 ) && ! $repo->link_place( $id, $second, 'ChIJexample12345' ), 'Wrong company cannot verify or link' );
	ace_places_privacy_check( $repo->verify( $id, $first, 1 ) && $repo->link_place( $id, $first, 'ChIJexample12345' ), 'Correct company can link a reviewed ID' );
	$privacy = new Privacy();
	$export = $privacy->export_addresses( 'fixture@example.test' );
	ace_places_privacy_check( count( $export['data'] ) === 1 && false !== strpos( wp_json_encode( $export ), '12 Sample Street' ), 'Address copy included in privacy export' );
	$erased = $privacy->erase_addresses( 'fixture@example.test' );
	ace_places_privacy_check( $erased['items_removed'] && null === $repo->find( $id ), 'Privacy erasure removes verified address and linked ID' );
	$company = $wpdb->get_row( $wpdb->prepare( "SELECT name, confidence FROM {$companies} WHERE id = %d", $first ), ARRAY_A );
	ace_places_privacy_check( 'Fictional Places Fixture A' === $company['name'] && 'confirmed' === $company['confidence'], 'Company identity unchanged' );
	$id = $repo->capture( $first, $address, 'woocommerce_billing', 'fixture-102', 'fixture@example.test' );
	ace_places_privacy_check( 1 === $repo->delete_by_source( 'woocommerce_billing', 'fixture-102' ) && null === $repo->find( $id ), 'Source deletion removes copied address' );
	echo "Places privacy fixtures passed\n";
} finally {
	$wpdb->query( 'ROLLBACK' );
}
