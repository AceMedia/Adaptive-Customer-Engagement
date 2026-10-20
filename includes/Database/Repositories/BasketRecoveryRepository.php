<?php
/**
 * Abandoned basket repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class BasketRecoveryRepository {
	/**
	 * Find a row by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		return $this->one( 'id = %d', $id );
	}

	/**
	 * Find a row by public token.
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	public function find_by_token( string $token ): ?array {
		return $this->one( 'token = %s', $token );
	}

	/**
	 * Find a row by WooCommerce session key.
	 *
	 * @param string $session_key Session key.
	 * @return array<string, mixed>|null
	 */
	public function find_by_session( string $session_key ): ?array {
		return $this->one( 'session_key = %s', $session_key );
	}

	/**
	 * Has this address opted out?
	 *
	 * @param string $email_hash Email hash.
	 * @return bool
	 */
	public function is_opted_out( string $email_hash ): bool {
		return null !== $this->one( "email_hash = %s AND status = 'optout'", $email_hash );
	}

	/**
	 * Has this address been emailed since the given time?
	 *
	 * @param string $email_hash Email hash.
	 * @param string $since      MySQL datetime (UTC).
	 * @param int    $except_id  Row id to ignore.
	 * @return bool
	 */
	public function was_sent_since( string $email_hash, string $since, int $except_id = 0 ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::table_name( 'basket_recovery' ) . " WHERE email_hash = %s AND status = 'sent' AND sent_at >= %s AND id <> %d LIMIT 1",
				$email_hash,
				$since,
				$except_id
			)
		);
	}

	/**
	 * Create or update the basket for a session.
	 *
	 * @param string $session_key Session key.
	 * @param string $email       Customer email.
	 * @param string $email_hash  Email hash.
	 * @param string $cart_json   Cart snapshot.
	 * @return array<string, mixed>|null
	 */
	public function upsert( string $session_key, string $email, string $email_hash, string $cart_json ): ?array {
		global $wpdb;

		$table = Schema::table_name( 'basket_recovery' );
		$now   = current_time( 'mysql', true );
		$row   = $this->find_by_session( $session_key );

		if ( $row ) {
			if ( 'optout' === $row['status'] ) {
				return $row;
			}
			$wpdb->update(
				$table,
				array(
					'email'      => $email,
					'email_hash' => $email_hash,
					'cart'       => $cart_json,
					'updated_at' => $now,
				),
				array( 'id' => (int) $row['id'] )
			);
			$updated           = $this->find( (int) $row['id'] );
			$updated['is_new'] = false;
			return $updated;
		}

		$wpdb->insert(
			$table,
			array(
				'token'       => bin2hex( random_bytes( 16 ) ),
				'session_key' => $session_key,
				'email'       => $email,
				'email_hash'  => $email_hash,
				'cart'        => $cart_json,
				'status'      => 'pending',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		if ( ! $wpdb->insert_id ) {
			return null;
		}

		$created           = $this->find( (int) $wpdb->insert_id );
		$created['is_new'] = true;

		return $created;
	}

	/**
	 * Set a status. Moving to "sent" stamps sent_at.
	 *
	 * @param int    $id     Row id.
	 * @param string $status Status.
	 * @return void
	 */
	public function set_status( int $id, string $status ): void {
		global $wpdb;

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( 'sent' === $status ) {
			$data['sent_at'] = $data['updated_at'];
		}
		$wpdb->update( Schema::table_name( 'basket_recovery' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Close open baskets for a shopper (by session or email hash) as ordered.
	 *
	 * @param string $session_key Session key (may be empty).
	 * @param string $email_hash  Email hash (may be empty).
	 * @return array<int, array<string, mixed>> The baskets that were closed.
	 */
	public function close_open( string $session_key, string $email_hash ): array {
		global $wpdb;

		$table = Schema::table_name( 'basket_recovery' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ( 'pending', 'sent' ) AND ( session_key = %s OR email_hash = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_key,
				$email_hash
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$this->set_status( (int) $row['id'], 'ordered' );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record a first-party event against a basket.
	 *
	 * @param int    $basket_id Basket row id.
	 * @param string $event     Event name.
	 * @param string $source    Optional source.
	 * @return void
	 */
	public function record_event( int $basket_id, string $event, string $source = '' ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table_name( 'basket_events' ),
			array(
				'basket_id'  => $basket_id,
				'event'      => sanitize_key( $event ),
				'source'     => '' !== $source ? sanitize_key( $source ) : null,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Has this basket had the event since the given time?
	 *
	 * @param int    $basket_id Basket row id.
	 * @param string $event     Event name.
	 * @param string $since     MySQL datetime (UTC).
	 * @return bool
	 */
	public function has_event_since( int $basket_id, string $event, string $since ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::table_name( 'basket_events' ) . ' WHERE basket_id = %d AND event = %s AND created_at >= %s LIMIT 1',
				$basket_id,
				$event,
				$since
			)
		);
	}

	/**
	 * Distinct baskets per event between two UTC datetimes.
	 *
	 * @param string $from MySQL datetime (UTC).
	 * @param string $to   MySQL datetime (UTC).
	 * @return array<string, int>
	 */
	public function stats( string $from, string $to ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event, COUNT(DISTINCT basket_id) AS baskets FROM ' . Schema::table_name( 'basket_events' ) . ' WHERE created_at >= %s AND created_at <= %s GROUP BY event',
				$from,
				$to
			),
			ARRAY_A
		);

		$by_event = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_event[ $row['event'] ] = (int) $row['baskets'];
		}

		$keys = array(
			'saved'     => 'basket_saved',
			'sent'      => 'email_sent',
			'opened'    => 'email_opened',
			'clicked'   => 'link_clicked',
			'restored'  => 'basket_restored',
			'converted' => 'order_placed_after_recovery',
			'opted_out' => 'opt_out',
		);

		$out = array();
		foreach ( $keys as $label => $event ) {
			$out[ $label ] = $by_event[ $event ] ?? 0;
		}

		return $out;
	}

	/**
	 * Opt out: keep only the hash so the address is not captured again.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public function opt_out( int $id ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table_name( 'basket_recovery' ),
			array(
				'status'     => 'optout',
				'email'      => null,
				'cart'       => null,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Delete everything except opt-out markers older than the retention period.
	 *
	 * @param int $days Retention days.
	 * @return int Rows deleted.
	 */
	public function purge( int $days ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Events hold no personal data (basket id, event, time) and feed the stats, so they are kept far longer.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Schema::table_name( 'basket_events' ) . ' WHERE created_at < %s',
				gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) )
			)
		);

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Schema::table_name( 'basket_recovery' ) . " WHERE status <> 'optout' AND updated_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Fetch a single row.
	 *
	 * @param string     $where Prepared WHERE fragment.
	 * @param string|int $value Value.
	 * @return array<string, mixed>|null
	 */
	private function one( string $where, $value ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table_name( 'basket_recovery' ) . ' WHERE ' . $where . ' LIMIT 1', $value ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
