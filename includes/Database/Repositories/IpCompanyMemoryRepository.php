<?php
/**
 * Local IP-to-company memory repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class IpCompanyMemoryRepository {
	/**
	 * Find the latest saved memory for an IP hash.
	 *
	 * @param string $ip_hash Hashed IP address.
	 * @return array<string, mixed>|null
	 */
	public function find_by_ip_hash( string $ip_hash ): ?array {
		global $wpdb;

		if ( '' === $ip_hash ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'ip_company_memory' ) . ' WHERE ip_hash = %s LIMIT 1',
				$ip_hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create or update a local organisation hint for an IP hash.
	 *
	 * @param string               $ip_hash Hashed IP address.
	 * @param array<string, mixed> $data    Memory payload.
	 * @return array<string, mixed>|null
	 */
	public function upsert( string $ip_hash, array $data ): ?array {
		global $wpdb;

		$ip_hash      = sanitize_text_field( $ip_hash );
		$company_name = sanitize_text_field( (string) ( $data['company_name'] ?? '' ) );

		if ( '' === $ip_hash || '' === $company_name ) {
			return null;
		}

		$table    = Schema::table_name( 'ip_company_memory' );
		$existing = $this->find_by_ip_hash( $ip_hash );
		$now      = current_time( 'mysql', true );
		$payload  = array(
			'company_id'     => ! empty( $data['company_id'] ) ? absint( $data['company_id'] ) : null,
			'company_name'   => $company_name,
			'company_domain' => sanitize_text_field( (string) ( $data['company_domain'] ?? '' ) ),
			'contact_name'   => sanitize_text_field( (string) ( $data['contact_name'] ?? '' ) ),
			'contact_email'  => sanitize_email( (string) ( $data['contact_email'] ?? '' ) ),
			'contact_phone'  => sanitize_text_field( (string) ( $data['contact_phone'] ?? '' ) ),
			'contact_role'   => sanitize_text_field( (string) ( $data['contact_role'] ?? '' ) ),
			'source'         => sanitize_key( (string) ( $data['source'] ?? 'chat' ) ) ?: 'chat',
			'confidence'     => sanitize_key( (string) ( $data['confidence'] ?? 'likely' ) ) ?: 'likely',
			'evidence'       => wp_json_encode( is_array( $data['evidence'] ?? null ) ? $data['evidence'] : array() ),
			'last_seen'      => $now,
			'updated_at'     => $now,
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'company_id'     => $payload['company_id'] ?: $existing['company_id'],
					'company_name'   => $payload['company_name'],
					'company_domain' => $payload['company_domain'] ?: $existing['company_domain'],
					'contact_name'   => $payload['contact_name'] ?: $existing['contact_name'],
					'contact_email'  => $payload['contact_email'] ?: $existing['contact_email'],
					'contact_phone'  => $payload['contact_phone'] ?: $existing['contact_phone'],
					'contact_role'   => $payload['contact_role'] ?: $existing['contact_role'],
					'source'         => $payload['source'],
					'confidence'     => $payload['confidence'],
					'evidence'       => $payload['evidence'],
					'last_seen'      => $now,
					'updated_at'     => $now,
				),
				array( 'id' => (int) $existing['id'] )
			);

			return $this->find_by_ip_hash( $ip_hash );
		}

		$wpdb->insert(
			$table,
			array_merge(
				$payload,
				array(
					'ip_hash'     => $ip_hash,
					'first_seen'  => $now,
					'created_at'  => $now,
				)
			)
		);

		return $this->find_by_ip_hash( $ip_hash );
	}
}
