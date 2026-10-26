<?php
/** Run with wp eval-file, --skip-plugins --skip-themes and a local --url. No external calls. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'WP_LOCAL_DEV' ) || ! WP_LOCAL_DEV ) { exit; }
require_once dirname( __DIR__ ) . '/includes/Enrichment/PlacesLookupService.php';

use ACE\AdaptiveCustomerEngagement\Enrichment\PlacesLookupService as Places;

function ace_places_check( bool $ok, string $label ): void {
	if ( ! $ok ) { throw new RuntimeException( $label ); }
}

$address = array( 'line_1' => '12 Sample Street', 'line_2' => 'Unit B', 'postcode' => 'AB1 2CD', 'country_code' => 'GB', 'public_premises' => 1 );
$base = array(
	'id' => 'ChIJexample123456',
	'displayName' => array( 'text' => 'Fictional Bins Ltd' ),
	'formattedAddress' => '12 Sample Street Unit B, Sampletown AB1 2CD, UK',
	'addressComponents' => array(
		array( 'types' => array( 'country' ), 'shortText' => 'GB' ),
		array( 'types' => array( 'postal_code' ), 'longText' => 'AB1 2CD' ),
	),
	'businessStatus' => 'OPERATIONAL',
);
$match = Places::parse_response( 'Fictional Bins Ltd', $address, array( 'places' => array( $base ) ) );
ace_places_check( ! is_wp_error( $match ) && 1 === count( $match['candidates'] ) && false !== strpos( $match['candidates'][0]['assessment'], 'Possible match' ), 'Exact fixture candidate' );
ace_places_check( 'Google Maps' === $match['attribution'], 'Google Maps attribution' );
ace_places_check( array() === Places::parse_response( 'Fictional Bins Ltd', $address, array( 'places' => array() ) )['candidates'], 'Zero results' );
ace_places_check( is_wp_error( Places::parse_response( 'Fictional Bins Ltd', $address, array( 'places' => 'bad' ) ) ), 'Invalid provider shape' );
ace_places_check( is_wp_error( Places::parse_response( 'Fictional Bins Ltd', $address, array( 'places' => array( array( 'id' => 'bad' ) ) ) ) ), 'Incomplete candidate rejected' );
$old_key = array( 'enabled' => true, 'key' => 'fixture-old-key', 'generation' => 'generation-old' );
$old_status = array( 'key_hash' => hash( 'sha256', 'fixture-old-key|generation-old' ) );
ace_places_check( 'last_lookup_succeeded' === Places::status_for_settings( $old_key, $old_status ), 'Successful key generation status' );
ace_places_check( 'untested' === Places::status_for_settings( array( 'enabled' => true, 'key' => 'fixture-new-key', 'generation' => 'generation-new' ), $old_status ), 'In-flight old lookup cannot verify replacement key' );
ace_places_check( 'untested' === Places::status_for_settings( array( 'enabled' => false, 'key' => 'fixture-old-key', 'generation' => 'generation-old' ), $old_status ), 'Disabling clears visible success' );
$changed = $base;
$changed['displayName']['text'] = 'Other Branch Ltd';
ace_places_check( false !== strpos( Places::assess( 'Fictional Bins Ltd', $address, $changed ), 'Name differs' ), 'Shared building or chain branch remains uncertain' );
$changed = $base;
$changed['formattedAddress'] = '12 Sample Street Unit C, Sampletown AB1 2CD, UK';
ace_places_check( false !== strpos( Places::assess( 'Fictional Bins Ltd', $address, $changed ), 'unit differs' ), 'Unit mismatch remains uncertain' );
$changed = $base;
$changed['businessStatus'] = 'CLOSED_PERMANENTLY';
ace_places_check( false !== strpos( Places::assess( 'Fictional Bins Ltd', $address, $changed ), 'Closed' ), 'Closed business not promoted' );
$changed = $base;
$changed['addressComponents'][1]['longText'] = 'AB1 2CE';
ace_places_check( false !== strpos( Places::assess( 'Fictional Bins Ltd', $address, $changed ), 'Postcode differs' ), 'Postcode mismatch' );
add_filter( 'pre_option_ace_places_settings', static function () { return array( 'enabled' => false, 'key' => 'fictional-secret-key' ); } );
ace_places_check( false === strpos( wp_json_encode( Places::public_settings() ), 'fictional-secret-key' ), 'Key hidden from REST shape' );
ace_places_check( is_wp_error( ( new Places() )->lookup( 'Fictional Bins Ltd', $address ) ), 'Disabled lookup blocked without HTTP' );
remove_all_filters( 'pre_option_ace_places_settings' );
add_filter( 'pre_option_ace_places_settings', static function () { return array( 'enabled' => true, 'key' => 'fictional-secret-key' ); } );
ace_places_check( Places::is_staging() && is_wp_error( ( new Places() )->lookup( 'Fictional Bins Ltd', $address ) ), 'Local staging lookup blocked without HTTP' );
remove_all_filters( 'pre_option_ace_places_settings' );
echo "Places fixtures passed\n";
