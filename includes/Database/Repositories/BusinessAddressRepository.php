<?php
/** First-party business address records. */
namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;
use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

final class BusinessAddressRepository {
	public function for_company( int $company_id ): array {
		global $wpdb;
		$table = Schema::table_name( 'business_addresses' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id,company_id,line_1,line_2,city,region,postcode,country_code,source,source_record,captured_at,public_premises,verified_by,verified_at,place_id FROM {$table} WHERE company_id = %d ORDER BY captured_at DESC, id DESC LIMIT 30", $company_id ), ARRAY_A );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table_name( 'business_addresses' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function capture( int $company_id, array $address, string $source, string $source_record, string $source_email = '' ): int {
		global $wpdb;
		$line = sanitize_text_field( (string) ( $address['line_1'] ?? '' ) );
		$postcode = sanitize_text_field( (string) ( $address['postcode'] ?? '' ) );
		$country = strtoupper( sanitize_text_field( (string) ( $address['country_code'] ?? '' ) ) );
		if ( $company_id < 1 || '' === $line || '' === $postcode || ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return 0;
		}
		$table = Schema::table_name( 'business_addresses' );
		$source = sanitize_key( $source );
		$source_record = substr( sanitize_text_field( $source_record ), 0, 100 );
		if ( '' !== $source_record ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE company_id = %d AND source = %s AND source_record = %s LIMIT 1", $company_id, $source, $source_record ) );
			if ( $existing ) {
				return $existing;
			}
		}
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert( $table, array(
			'company_id' => $company_id,
			'line_1' => $line,
			'line_2' => sanitize_text_field( (string) ( $address['line_2'] ?? '' ) ),
			'city' => sanitize_text_field( (string) ( $address['city'] ?? '' ) ),
			'region' => sanitize_text_field( (string) ( $address['region'] ?? '' ) ),
			'postcode' => $postcode,
			'country_code' => $country,
			'source' => $source,
			'source_record' => $source_record,
			'source_email_hash' => self::email_hash( $source_email ),
			'captured_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		) );
		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	public static function email_hash( string $email ): string {
		$email = strtolower( sanitize_email( $email ) );
		return '' === $email ? '' : hash_hmac( 'sha256', $email, Settings::get_hash_salt() );
	}

	public function delete_by_source( string $source, string $record ): int {
		global $wpdb;
		return (int) $wpdb->delete( Schema::table_name( 'business_addresses' ), array( 'source' => sanitize_key( $source ), 'source_record' => sanitize_text_field( $record ) ) );
	}

	public function verify( int $id, int $company_id, int $user_id ): bool {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row || (int) $row['company_id'] !== $company_id || $user_id < 1 ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		return false !== $wpdb->update( Schema::table_name( 'business_addresses' ), array( 'public_premises' => 1, 'verified_by' => $user_id, 'verified_at' => $now, 'updated_at' => $now ), array( 'id' => $id ) );
	}

	public function link_place( int $id, int $company_id, string $place_id ): bool {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row || (int) $row['company_id'] !== $company_id || ! (int) $row['public_premises'] || ! preg_match( '/^[A-Za-z0-9_:-]{8,255}$/', $place_id ) ) {
			return false;
		}
		return false !== $wpdb->update( Schema::table_name( 'business_addresses' ), array( 'place_id' => $place_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
	}

	public function delete( int $id, int $company_id ): bool {
		global $wpdb;
		$row = $this->find( $id );
		return $row && (int) $row['company_id'] === $company_id && 1 === $wpdb->delete( Schema::table_name( 'business_addresses' ), array( 'id' => $id, 'company_id' => $company_id ) );
	}
}
