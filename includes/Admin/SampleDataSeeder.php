<?php
/**
 * Local sample data generator for admin previews.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Admin;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class SampleDataSeeder {
	private const CAMPAIGN_MARKER = 'ace-sample-data';
	private const COMPANY_MARKER  = '[ACE_SAMPLE_DATA]';
	private const NUMBER_MARKER   = 'ace_sample_data';

	/**
	 * Get sample-data status for the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		global $wpdb;

		$sessions_table = Schema::table_name( 'sessions' );
		$events_table   = Schema::table_name( 'events' );
		$companies_table = Schema::table_name( 'companies' );
		$calls_table    = Schema::table_name( 'calls' );
		$numbers_table  = Schema::table_name( 'numbers' );

		$session_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$sessions_table} WHERE utm_campaign = %s",
				self::CAMPAIGN_MARKER
			)
		);
		$company_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$companies_table} WHERE notes = %s",
				self::COMPANY_MARKER
			)
		);
		$event_count   = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(e.id)
				FROM {$events_table} e
				INNER JOIN {$sessions_table} s ON s.id = e.session_id
				WHERE s.utm_campaign = %s",
				self::CAMPAIGN_MARKER
			)
		);
		$call_count    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$calls_table} WHERE notes = %s",
				self::COMPANY_MARKER
			)
		);
		$number_count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$numbers_table} WHERE source_value = %s",
				self::NUMBER_MARKER
			)
		);
		$live_number_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$numbers_table} WHERE source_value != %s",
				self::NUMBER_MARKER
			)
		);
		$date_range    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT MIN(first_seen) AS first_seen, MAX(last_seen) AS last_seen
				FROM {$sessions_table}
				WHERE utm_campaign = %s",
				self::CAMPAIGN_MARKER
			),
			ARRAY_A
		);

		return array(
			'is_seeded'   => $session_count > 0,
			'companies'   => $company_count,
			'sessions'    => $session_count,
			'events'      => $event_count,
			'calls'       => $call_count,
			'numbers'     => $number_count,
			'live_numbers'=> $live_number_count,
			'first_seen'  => is_array( $date_range ) ? (string) ( $date_range['first_seen'] ?? '' ) : '',
			'last_seen'   => is_array( $date_range ) ? (string) ( $date_range['last_seen'] ?? '' ) : '',
			'description' => __( 'Local-only seeded demo activity for UK businesses and councils across roughly the last three months, using fictional reserved phone numbers so it stays separate from real telephony.', 'adaptive-customer-engagement' ),
		);
	}

	/**
	 * Reset and re-seed sample data.
	 *
	 * @return array<string, mixed>
	 */
	public function seed(): array {
		$this->reset();

		global $wpdb;

		$numbers  = $this->seed_numbers();
		$catalog  = $this->get_catalogue();
		$profiles = $this->get_company_profiles();
		$now      = time();

		foreach ( $profiles as $profile ) {
			$company_id = $this->insert_company( $profile );
			$visitors   = array_map(
				static function (): string {
					return wp_generate_uuid4();
				},
				range( 1, wp_rand( 3, 7 ) )
			);
			$session_total = wp_rand( 9, 18 );
			$timestamps    = $this->build_session_timestamps( $session_total, $now );
			$event_total   = 0;
			$call_total    = 0;
			$first_seen    = '';
			$last_seen     = '';

			foreach ( $timestamps as $index => $session_timestamp ) {
				$visitor_uuid      = $visitors[ array_rand( $visitors ) ];
				$landing_path      = $profile['paths'][ array_rand( $profile['paths'] ) ];
				$utm_source        = $profile['sources'][ array_rand( $profile['sources'] ) ];
				$company_confidence = $profile['confidence'];
				$session_id        = $this->insert_session(
					array(
						'company_id'         => $company_id,
						'visitor_uuid'       => $visitor_uuid,
						'landing_path'       => $landing_path,
						'first_url'          => 'https://' . $profile['domain'] . $landing_path,
						'referrer'           => $profile['referrer'],
						'utm_source'         => $utm_source,
						'utm_medium'         => $profile['medium'],
						'utm_campaign'       => self::CAMPAIGN_MARKER,
						'utm_term'           => $profile['sector'],
						'utm_content'        => $profile['kind'],
						'user_agent'         => $profile['user_agent'],
						'browser_hash'       => hash( 'sha256', $profile['domain'] . '|' . $visitor_uuid ),
						'ip_hash'            => hash( 'sha256', $profile['domain'] . '|' . $index ),
						'country_code'       => 'GB',
						'region'             => $profile['region'],
						'city'               => $profile['city'],
						'asn'                => $profile['asn'],
						'isp'                => $profile['isp'],
						'company_confidence' => $company_confidence,
						'first_seen'         => gmdate( 'Y-m-d H:i:s', $session_timestamp ),
						'last_seen'          => gmdate( 'Y-m-d H:i:s', $session_timestamp ),
					)
				);

				$session_events = $this->build_session_events( $profile, $catalog, $numbers, $landing_path, $session_timestamp );
				$session_last_seen = $session_timestamp;

				foreach ( $session_events as $event_offset => $event ) {
					$occurred_timestamp = min( $session_timestamp + min( $event_offset * wp_rand( 60, 480 ), 7200 ), $now );

					if ( $occurred_timestamp < $session_last_seen ) {
						$occurred_timestamp = $session_last_seen;
					}

					$occurred_at = gmdate( 'Y-m-d H:i:s', $occurred_timestamp );
					$this->insert_event( $session_id, $event, $occurred_at );
					++$event_total;
					$session_last_seen = $occurred_timestamp;
					$last_seen = $occurred_at;
				}

				if ( $last_seen ) {
					$wpdb->update(
						Schema::table_name( 'sessions' ),
						array(
							'last_seen'   => gmdate( 'Y-m-d H:i:s', $session_last_seen ),
							'updated_at'  => gmdate( 'Y-m-d H:i:s', $session_last_seen ),
						),
						array( 'id' => $session_id )
					);
				}

				if ( '' === $first_seen || $first_seen > gmdate( 'Y-m-d H:i:s', $session_timestamp ) ) {
					$first_seen = gmdate( 'Y-m-d H:i:s', $session_timestamp );
				}

				if ( wp_rand( 1, 100 ) <= ( 'council' === $profile['kind'] ? 22 : 36 ) ) {
					$call_started_at = min( $session_timestamp + wp_rand( 600, 5400 ), max( $session_timestamp, $now - 300 ) );
					$call_duration   = min( wp_rand( 64, 960 ), max( 30, $now - $call_started_at ) );

					if ( $call_duration < 30 ) {
						continue;
					}

					$call_ended_at = min( $call_started_at + $call_duration, $now );

					$this->insert_call(
						array(
							'number_id'           => $numbers[ array_rand( $numbers ) ]['id'],
							'called_number'       => $profile['main_phone'],
							'started_at'          => gmdate( 'Y-m-d H:i:s', $call_started_at ),
							'ended_at'            => gmdate( 'Y-m-d H:i:s', $call_ended_at ),
							'duration_seconds'    => max( 30, $call_ended_at - $call_started_at ),
							'status'              => $profile['call_statuses'][ array_rand( $profile['call_statuses'] ) ],
							'queue_name'          => $profile['queue'],
							'agent_name'          => $profile['agents'][ array_rand( $profile['agents'] ) ],
							'matched_session_id'  => $session_id,
							'matched_company_id'  => $company_id,
							'match_confidence'    => $company_confidence,
						)
					);
					++$call_total;
				}
			}

			$wpdb->update(
				Schema::table_name( 'companies' ),
				array(
					'total_sessions' => $session_total,
					'total_events'   => $event_total,
					'total_calls'    => $call_total,
					'first_seen'     => $first_seen,
					'last_seen'      => $last_seen ?: $first_seen,
					'updated_at'     => $last_seen ?: $first_seen,
				),
				array( 'id' => $company_id )
			);
		}

		return $this->get_status();
	}

	/**
	 * Build a session timeline that always includes activity through today.
	 *
	 * @param int $session_total Total sessions to create.
	 * @param int $now           Current timestamp.
	 * @return array<int, int>
	 */
	private function build_session_timestamps( int $session_total, int $now ): array {
		$today_start      = strtotime( gmdate( 'Y-m-d 00:00:00', $now ) . ' UTC' );
		$oldest_start     = $today_start - ( 89 * DAY_IN_SECONDS );
		$recent_start     = max( $oldest_start, $today_start - ( 6 * DAY_IN_SECONDS ) );
		$today_sessions   = min( 4, max( 2, (int) ceil( $session_total * 0.28 ) ) );
		$recent_sessions  = min( max( 2, (int) ceil( $session_total * 0.32 ) ), max( 0, $session_total - $today_sessions ) );
		$historic_sessions = max( 0, $session_total - $today_sessions - $recent_sessions );
		$timestamps       = array();

		for ( $index = 0; $index < $historic_sessions; $index++ ) {
			$timestamps[] = wp_rand( $oldest_start, max( $oldest_start, $recent_start - 1 ) );
		}

		for ( $index = 0; $index < $recent_sessions; $index++ ) {
			$timestamps[] = wp_rand( $recent_start, max( $recent_start, $today_start - 1 ) );
		}

		$today_window = max( 1, $now - $today_start );

		for ( $index = 0; $index < $today_sessions; $index++ ) {
			$offset       = wp_rand( 0, $today_window );
			$timestamps[] = min( $today_start + $offset, $now );
		}

		$timestamps[] = max( $today_start, $now - wp_rand( 120, 3600 ) );

		sort( $timestamps );

		return array_slice( $timestamps, -1 * $session_total, $session_total );
	}

	/**
	 * Remove sample data.
	 *
	 * @return array<string, mixed>
	 */
	public function reset(): array {
		global $wpdb;

		$sessions_table  = Schema::table_name( 'sessions' );
		$events_table    = Schema::table_name( 'events' );
		$calls_table     = Schema::table_name( 'calls' );
		$companies_table = Schema::table_name( 'companies' );
		$numbers_table   = Schema::table_name( 'numbers' );

		$session_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$sessions_table} WHERE utm_campaign = %s",
				self::CAMPAIGN_MARKER
			)
		);
		$company_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$companies_table} WHERE notes = %s",
				self::COMPANY_MARKER
			)
		);

		$this->delete_rows_by_ids( $events_table, 'session_id', $session_ids );
		$this->delete_rows_by_ids( $calls_table, 'matched_session_id', $session_ids );
		$this->delete_rows_by_ids( $calls_table, 'matched_company_id', $company_ids );
		$wpdb->delete( $calls_table, array( 'notes' => self::COMPANY_MARKER ) );
		$wpdb->delete( $sessions_table, array( 'utm_campaign' => self::CAMPAIGN_MARKER ) );
		$wpdb->delete( $companies_table, array( 'notes' => self::COMPANY_MARKER ) );
		$wpdb->delete( $numbers_table, array( 'source_value' => self::NUMBER_MARKER ) );

		return $this->get_status();
	}

	/**
	 * Seed baseline tracking numbers for the demo dataset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function seed_numbers(): array {
		global $wpdb;

		$table   = Schema::table_name( 'numbers' );
		$created = array();
		$now     = current_time( 'mysql', true );
		$rows    = array(
			array(
				'label'            => 'Demo main tracking line',
				'display_number'   => '01632 960 100',
				'e164_number'      => '+441632960100',
				'source_type'      => 'default',
				'source_value'     => self::NUMBER_MARKER,
				'page_match_type'  => 'contains',
				'page_match_value' => '',
				'campaign_match'   => '',
				'is_default'       => 0,
				'is_active'        => 0,
				'priority'         => 1,
			),
			array(
				'label'            => 'Demo campaign tracking line',
				'display_number'   => '01632 960 200',
				'e164_number'      => '+441632960200',
				'source_type'      => 'campaign',
				'source_value'     => self::NUMBER_MARKER,
				'page_match_type'  => 'contains',
				'page_match_value' => '/contact',
				'campaign_match'   => self::CAMPAIGN_MARKER,
				'is_default'       => 0,
				'is_active'        => 0,
				'priority'         => 5,
			),
			array(
				'label'            => 'Demo product tracking line',
				'display_number'   => '01632 960 300',
				'e164_number'      => '+441632960300',
				'source_type'      => 'product_page',
				'source_value'     => self::NUMBER_MARKER,
				'page_match_type'  => 'contains',
				'page_match_value' => '/products/',
				'campaign_match'   => '',
				'is_default'       => 0,
				'is_active'        => 0,
				'priority'         => 10,
			),
		);

		foreach ( $rows as $row ) {
			$wpdb->insert(
				$table,
				array_merge(
					$row,
					array(
						'amazon_connect_phone_number_id' => '',
						'amazon_connect_contact_flow_id' => '',
						'created_at'                     => $now,
						'updated_at'                     => $now,
					)
				)
			);

			$created[] = array(
				'id'          => (int) $wpdb->insert_id,
				'e164_number' => $row['e164_number'],
			);
		}

		return $created;
	}

	/**
	 * Insert a sample company.
	 *
	 * @param array<string, mixed> $profile Company profile.
	 * @return int
	 */
	private function insert_company( array $profile ): int {
		global $wpdb;

		$timestamp = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );

		$wpdb->insert(
			Schema::table_name( 'companies' ),
			array(
				'name'            => $profile['name'],
				'domain'          => $profile['domain'],
				'type'            => $profile['company_type'],
				'country_code'    => 'GB',
				'source_provider' => 'sample',
				'source_payload'  => wp_json_encode(
					array(
						'sector' => $profile['sector'],
						'kind'   => $profile['kind'],
					)
				),
				'confidence'      => $profile['confidence'],
				'first_seen'      => $timestamp,
				'last_seen'       => $timestamp,
				'total_sessions'  => 0,
				'total_events'    => 0,
				'total_calls'     => 0,
				'ignored'         => 0,
				'notes'           => self::COMPANY_MARKER,
				'created_at'      => $timestamp,
				'updated_at'      => $timestamp,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a sample session.
	 *
	 * @param array<string, mixed> $data Session data.
	 * @return int
	 */
	private function insert_session( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::table_name( 'sessions' ),
			array(
				'session_uuid'       => wp_generate_uuid4(),
				'visitor_uuid'       => $data['visitor_uuid'],
				'first_seen'         => $data['first_seen'],
				'last_seen'          => $data['last_seen'],
				'first_url'          => $data['first_url'],
				'landing_path'       => $data['landing_path'],
				'referrer'           => $data['referrer'],
				'utm_source'         => $data['utm_source'],
				'utm_medium'         => $data['utm_medium'],
				'utm_campaign'       => $data['utm_campaign'],
				'utm_term'           => $data['utm_term'],
				'utm_content'        => $data['utm_content'],
				'user_agent'         => $data['user_agent'],
				'browser_hash'       => $data['browser_hash'],
				'ip_hash'            => $data['ip_hash'],
				'ip_raw'             => null,
				'ip_raw_expires_at'  => null,
				'country_code'       => $data['country_code'],
				'region'             => $data['region'],
				'city'               => $data['city'],
				'asn'                => $data['asn'],
				'isp'                => $data['isp'],
				'company_id'         => $data['company_id'],
				'company_confidence' => $data['company_confidence'],
				'is_bot'             => 0,
				'ignored'            => 0,
				'created_at'         => $data['first_seen'],
				'updated_at'         => $data['last_seen'],
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a sample event.
	 *
	 * @param int                  $session_id  Session ID.
	 * @param array<string, mixed> $event       Event row.
	 * @param string               $occurred_at Event timestamp.
	 * @return void
	 */
	private function insert_event( int $session_id, array $event, string $occurred_at ): void {
		global $wpdb;

		$metadata                     = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();
		$metadata['_ace_sample_data'] = true;

		$wpdb->insert(
			Schema::table_name( 'events' ),
			array(
				'event_uuid'       => wp_generate_uuid4(),
				'session_id'       => $session_id,
				'event_type'       => $event['event_type'],
				'event_name'       => $event['event_name'],
				'url'              => $event['url'],
				'path'             => $event['path'],
				'page_title'       => $event['page_title'],
				'post_id'          => $event['post_id'],
				'post_type'        => $event['post_type'],
				'taxonomy_context' => $event['taxonomy_context'],
				'product_area'     => $event['product_area'],
				'brand_context'    => $event['brand_context'],
				'number_id'        => $event['number_id'],
				'metadata'         => wp_json_encode( $metadata ),
				'occurred_at'      => $occurred_at,
				'created_at'       => $occurred_at,
			)
		);
	}

	/**
	 * Insert a sample call.
	 *
	 * @param array<string, mixed> $call Call payload.
	 * @return void
	 */
	private function insert_call( array $call ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table_name( 'calls' ),
			array(
				'call_uuid'              => wp_generate_uuid4(),
				'amazon_contact_id'      => 'sample-' . wp_generate_uuid4(),
				'number_id'              => $call['number_id'],
				'called_number'          => $call['called_number'],
				'caller_number_hash'     => hash( 'sha256', (string) $call['called_number'] . '|' . (string) $call['started_at'] ),
				'caller_number_raw'      => null,
				'caller_number_expires_at' => null,
				'started_at'             => $call['started_at'],
				'ended_at'               => $call['ended_at'],
				'duration_seconds'       => $call['duration_seconds'],
				'direction'              => 'inbound',
				'status'                 => $call['status'],
				'queue_name'             => $call['queue_name'],
				'agent_name'             => $call['agent_name'],
				'matched_session_id'     => $call['matched_session_id'],
				'matched_company_id'     => $call['matched_company_id'],
				'match_confidence'       => $call['match_confidence'],
				'attributes'             => wp_json_encode(
					array(
						'source' => 'sample-data',
					)
				),
				'notes'                  => self::COMPANY_MARKER,
				'created_at'             => $call['started_at'],
				'updated_at'             => $call['ended_at'],
			)
		);
	}

	/**
	 * Build demo events for one session.
	 *
	 * @param array<string, mixed>              $profile       Company profile.
	 * @param array<string, array<int, mixed>>  $catalog       Demo catalogue.
	 * @param array<int, array<string, mixed>>  $numbers       Tracking numbers.
	 * @param string                            $landing_path  Landing path.
	 * @param int                               $session_start Session timestamp.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_session_events( array $profile, array $catalog, array $numbers, string $landing_path, int $session_start ): array {
		$events = array();

		$events[] = array(
			'event_type'       => 'pageview',
			'event_name'       => 'Page view',
			'url'              => 'https://' . $profile['domain'] . $landing_path,
			'path'             => $landing_path,
			'page_title'       => $profile['name'] . ' landing',
			'post_id'          => 0,
			'post_type'        => 'page',
			'taxonomy_context' => '',
			'product_area'     => '',
			'brand_context'    => '',
			'number_id'        => 0,
			'metadata'         => array(),
		);

		if ( wp_rand( 1, 100 ) <= 45 ) {
			$events[] = array(
				'event_type'       => 'pageview',
				'event_name'       => 'Case study view',
				'url'              => 'https://' . $profile['domain'] . '/case-studies/' . sanitize_title( $profile['name'] ),
				'path'             => '/case-studies/' . sanitize_title( $profile['name'] ),
				'page_title'       => $profile['name'] . ' case study',
				'post_id'          => 0,
				'post_type'        => 'page',
				'taxonomy_context' => '',
				'product_area'     => '',
				'brand_context'    => '',
				'number_id'        => 0,
				'metadata'         => array(),
			);
		}

		if ( ! empty( $profile['commerce_catalogue'] ) && wp_rand( 1, 100 ) <= 70 ) {
			$product = $catalog[ $profile['commerce_catalogue'] ]['products'][ array_rand( $catalog[ $profile['commerce_catalogue'] ]['products'] ) ];
			$repeat  = wp_rand( 1, 4 );

			$events[] = array(
				'event_type'       => 'pageview',
				'event_name'       => 'Product view',
				'url'              => 'https://' . $profile['domain'] . '/products/' . $product['slug'],
				'path'             => '/products/' . $product['slug'],
				'page_title'       => $product['name'],
				'post_id'          => $product['id'],
				'post_type'        => 'product',
				'taxonomy_context' => $product['category_slug'],
				'product_area'     => $product['slug'],
				'brand_context'    => $product['brand'],
				'number_id'        => 0,
				'metadata'         => array(
					'product_id'             => $product['id'],
					'product_slug'           => $product['slug'],
					'product_name'           => $product['name'],
					'product_view_count'     => $repeat,
					'repeat_product_interest'=> $repeat > 1,
					'category_id'            => $product['category_id'],
					'category_slug'          => $product['category_slug'],
					'category_name'          => $product['category_name'],
					'category_view_count'    => max( 1, $repeat - 1 ),
					'repeat_category_interest' => $repeat > 2,
					'product_categories'     => $product['category_slug'],
					'product_category_names' => $product['category_name'],
					'brand_slug'             => sanitize_title( $product['brand'] ),
					'brand_name'             => $product['brand'],
				),
			);

			if ( wp_rand( 1, 100 ) <= 35 ) {
				$events[] = array(
					'event_type'       => 'pageview',
					'event_name'       => 'Category browse',
					'url'              => 'https://' . $profile['domain'] . '/product-category/' . $product['category_slug'],
					'path'             => '/product-category/' . $product['category_slug'],
					'page_title'       => $product['category_name'],
					'post_id'          => $product['category_id'],
					'post_type'        => 'product_cat',
					'taxonomy_context' => $product['category_slug'],
					'product_area'     => '',
					'brand_context'    => '',
					'number_id'        => 0,
					'metadata'         => array(
						'category_id'         => $product['category_id'],
						'category_slug'       => $product['category_slug'],
						'category_name'       => $product['category_name'],
						'category_view_count' => max( 1, $repeat ),
					),
				);
			}
		}

		if ( wp_rand( 1, 100 ) <= 38 ) {
			$events[] = array(
				'event_type'       => 'click_to_call',
				'event_name'       => 'Click to call',
				'url'              => 'https://' . $profile['domain'] . '/contact',
				'path'             => '/contact',
				'page_title'       => 'Contact',
				'post_id'          => 0,
				'post_type'        => 'page',
				'taxonomy_context' => '',
				'product_area'     => '',
				'brand_context'    => '',
				'number_id'        => $numbers[ array_rand( $numbers ) ]['id'],
				'metadata'         => array(
					'cta' => 'contact',
				),
			);
		}

		if ( wp_rand( 1, 100 ) <= 28 ) {
			$events[] = array(
				'event_type'       => 'download',
				'event_name'       => 'Download brochure',
				'url'              => 'https://' . $profile['domain'] . '/downloads/' . sanitize_title( $profile['sector'] ) . '.pdf',
				'path'             => '/downloads/' . sanitize_title( $profile['sector'] ) . '.pdf',
				'page_title'       => 'Downloads',
				'post_id'          => 0,
				'post_type'        => 'attachment',
				'taxonomy_context' => '',
				'product_area'     => '',
				'brand_context'    => '',
				'number_id'        => 0,
				'metadata'         => array(
					'asset' => 'brochure',
				),
			);
		}

		if ( wp_rand( 1, 100 ) <= 24 ) {
			$events[] = array(
				'event_type'       => 'form_submit',
				'event_name'       => 'Lead form',
				'url'              => 'https://' . $profile['domain'] . '/contact',
				'path'             => '/contact',
				'page_title'       => 'Contact',
				'post_id'          => 0,
				'post_type'        => 'page',
				'taxonomy_context' => '',
				'product_area'     => '',
				'brand_context'    => '',
				'number_id'        => 0,
				'metadata'         => array(
					'form_id' => 'sample-enquiry',
				),
			);
		}

		return $events;
	}

	/**
	 * Delete rows from a table by a list of IDs.
	 *
	 * @param string          $table  Table name.
	 * @param string          $column Column name.
	 * @param array<int, mixed> $ids  IDs.
	 * @return void
	 */
	private function delete_rows_by_ids( string $table, string $column, array $ids ): void {
		global $wpdb;

		$ids = array_values(
			array_filter(
				array_map( 'absint', $ids )
			)
		);

		if ( ! $ids ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "DELETE FROM {$table} WHERE {$column} IN ({$placeholders})";
		$wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Demo catalogue used for WooCommerce activity.
	 *
	 * @return array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private function get_catalogue(): array {
		return array(
			'waste' => array(
				'products' => array(
					array( 'id' => 101, 'slug' => 'smart-street-bin', 'name' => 'Smart Street Bin', 'category_id' => 201, 'category_slug' => 'street-bins', 'category_name' => 'Street Bins', 'brand' => 'Egbert Taylor' ),
					array( 'id' => 102, 'slug' => 'recycling-container', 'name' => 'Recycling Container', 'category_id' => 202, 'category_slug' => 'recycling', 'category_name' => 'Recycling', 'brand' => 'Future Street' ),
					array( 'id' => 103, 'slug' => 'compaction-unit', 'name' => 'Compaction Unit', 'category_id' => 203, 'category_slug' => 'compact-bins', 'category_name' => 'Compact Bins', 'brand' => 'Future Street' ),
				),
			),
			'home'  => array(
				'products' => array(
					array( 'id' => 111, 'slug' => 'linen-storage-bench', 'name' => 'Linen Storage Bench', 'category_id' => 211, 'category_slug' => 'storage', 'category_name' => 'Storage', 'brand' => 'Curran Home Co.' ),
					array( 'id' => 112, 'slug' => 'oak-shelving-set', 'name' => 'Oak Shelving Set', 'category_id' => 212, 'category_slug' => 'home-organisation', 'category_name' => 'Home Organisation', 'brand' => 'Curran Home Co.' ),
				),
			),
			'wellness' => array(
				'products' => array(
					array( 'id' => 121, 'slug' => 'ginger-energy-shot', 'name' => 'Ginger Energy Shot', 'category_id' => 221, 'category_slug' => 'wellness-shots', 'category_name' => 'Wellness Shots', 'brand' => 'Herbist' ),
					array( 'id' => 122, 'slug' => 'turmeric-recovery-shot', 'name' => 'Turmeric Recovery Shot', 'category_id' => 221, 'category_slug' => 'wellness-shots', 'category_name' => 'Wellness Shots', 'brand' => 'Herbist' ),
				),
			),
			'studio' => array(
				'products' => array(
					array( 'id' => 131, 'slug' => 'tattoo-workstation-pro', 'name' => 'Tattoo Workstation Pro', 'category_id' => 231, 'category_slug' => 'studio-carts', 'category_name' => 'Studio Carts', 'brand' => 'Uni Carts' ),
					array( 'id' => 132, 'slug' => 'compact-artist-cart', 'name' => 'Compact Artist Cart', 'category_id' => 231, 'category_slug' => 'studio-carts', 'category_name' => 'Studio Carts', 'brand' => 'Uni Carts' ),
				),
			),
		);
	}

	/**
	 * Company profiles for demo data.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_company_profiles(): array {
		return array(
			array(
				'name'              => 'Sheffield City Council',
				'domain'            => 'sheffield.gov.uk',
				'company_type'      => 'Local authority',
				'kind'              => 'council',
				'sector'            => 'waste-services',
				'confidence'        => 'confirmed',
				'city'              => 'Sheffield',
				'region'            => 'South Yorkshire',
				'asn'               => 'AS2856',
				'isp'               => 'BT Public Sector',
				'paths'             => array( '/services/waste', '/contact', '/procurement' ),
				'sources'           => array( 'google', 'direct', 'linkedin' ),
				'medium'            => 'organic',
				'referrer'          => 'https://www.google.com/',
				'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
				'call_statuses'     => array( 'connected', 'completed', 'queued' ),
				'queue'             => 'Council Enquiries',
				'agents'            => array( 'A. Brown', 'M. Wilson', 'S. Patel' ),
				'main_phone'        => '0114 273 4567',
				'commerce_catalogue'=> 'waste',
			),
			array(
				'name'              => 'Leeds City Council',
				'domain'            => 'leeds.gov.uk',
				'company_type'      => 'Local authority',
				'kind'              => 'council',
				'sector'            => 'street-cleansing',
				'confidence'        => 'confirmed',
				'city'              => 'Leeds',
				'region'            => 'West Yorkshire',
				'asn'               => 'AS5089',
				'isp'               => 'Virgin Media Business',
				'paths'             => array( '/business/tenders', '/environment', '/contact' ),
				'sources'           => array( 'google', 'newsletter', 'direct' ),
				'medium'            => 'referral',
				'referrer'          => 'https://www.bing.com/',
				'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Firefox/125.0',
				'call_statuses'     => array( 'connected', 'completed', 'completed' ),
				'queue'             => 'Procurement',
				'agents'            => array( 'L. Hughes', 'J. Ali', 'C. Green' ),
				'main_phone'        => '0113 222 4444',
				'commerce_catalogue'=> 'waste',
			),
			array(
				'name'              => 'Manchester City Council',
				'domain'            => 'manchester.gov.uk',
				'company_type'      => 'Local authority',
				'kind'              => 'council',
				'sector'            => 'public-realm',
				'confidence'        => 'likely',
				'city'              => 'Manchester',
				'region'            => 'Greater Manchester',
				'asn'               => 'AS5378',
				'isp'               => 'TalkTalk Business',
				'paths'             => array( '/business', '/waste-and-recycling', '/support' ),
				'sources'           => array( 'direct', 'linkedin', 'google' ),
				'medium'            => 'direct',
				'referrer'          => 'https://www.linkedin.com/',
				'user_agent'        => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_4) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15',
				'call_statuses'     => array( 'connected', 'queued', 'completed' ),
				'queue'             => 'Major Projects',
				'agents'            => array( 'H. Ward', 'N. Jones', 'D. Singh' ),
				'main_phone'        => '0161 234 5000',
				'commerce_catalogue'=> 'waste',
			),
			array(
				'name'              => 'Egbert Taylor Group',
				'domain'            => 'egberttaylor.com',
				'company_type'      => 'Manufacturer',
				'kind'              => 'business',
				'sector'            => 'waste-management',
				'confidence'        => 'confirmed',
				'city'              => 'Corby',
				'region'            => 'North Northamptonshire',
				'asn'               => 'AS8607',
				'isp'               => 'BT Business',
				'paths'             => array( '/products', '/contact', '/case-studies' ),
				'sources'           => array( 'google', 'linkedin', 'direct' ),
				'medium'            => 'organic',
				'referrer'          => 'https://www.google.com/',
				'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/123.0 Safari/537.36',
				'call_statuses'     => array( 'completed', 'connected', 'voicemail' ),
				'queue'             => 'Sales',
				'agents'            => array( 'B. Carter', 'R. Ellis', 'P. Martin' ),
				'main_phone'        => '01536 264 400',
				'commerce_catalogue'=> 'waste',
			),
			array(
				'name'              => 'Future Street',
				'domain'            => 'futurestreet.ie',
				'company_type'      => 'Smart city supplier',
				'kind'              => 'business',
				'sector'            => 'street-furniture',
				'confidence'        => 'confirmed',
				'city'              => 'Dublin',
				'region'            => 'Leinster',
				'asn'               => 'AS5466',
				'isp'               => 'Vodafone Business',
				'paths'             => array( '/products', '/smart-cities', '/book-a-demo' ),
				'sources'           => array( 'google', 'linkedin', 'newsletter' ),
				'medium'            => 'paid-social',
				'referrer'          => 'https://www.linkedin.com/',
				'user_agent'        => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
				'call_statuses'     => array( 'completed', 'connected', 'connected' ),
				'queue'             => 'Bigbelly Enquiries',
				'agents'            => array( 'E. Doyle', 'K. Byrne', 'T. Kelly' ),
				'main_phone'        => '+353 1 522 8000',
				'commerce_catalogue'=> 'waste',
			),
			array(
				'name'              => 'Curran Home Co.',
				'domain'            => 'curranhomecompany.ie',
				'company_type'      => 'Retailer',
				'kind'              => 'business',
				'sector'            => 'home-interiors',
				'confidence'        => 'likely',
				'city'              => 'Dublin',
				'region'            => 'Leinster',
				'asn'               => 'AS5466',
				'isp'               => 'Vodafone Business',
				'paths'             => array( '/products', '/about', '/contact' ),
				'sources'           => array( 'instagram', 'google', 'direct' ),
				'medium'            => 'social',
				'referrer'          => 'https://www.instagram.com/',
				'user_agent'        => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Version/17.4 Mobile/15E148 Safari/604.1',
				'call_statuses'     => array( 'completed', 'abandoned', 'connected' ),
				'queue'             => 'Retail',
				'agents'            => array( 'A. Curran', 'S. Walsh', 'G. Murphy' ),
				'main_phone'        => '+353 1 699 2000',
				'commerce_catalogue'=> 'home',
			),
			array(
				'name'              => 'Herbist',
				'domain'            => 'herbist.co.uk',
				'company_type'      => 'eCommerce brand',
				'kind'              => 'business',
				'sector'            => 'wellness',
				'confidence'        => 'likely',
				'city'              => 'London',
				'region'            => 'Greater London',
				'asn'               => 'AS5607',
				'isp'               => 'Sky Business',
				'paths'             => array( '/products', '/stockists', '/contact' ),
				'sources'           => array( 'google', 'instagram', 'newsletter' ),
				'medium'            => 'email',
				'referrer'          => 'https://www.google.com/',
				'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/123.0 Safari/537.36',
				'call_statuses'     => array( 'connected', 'completed', 'missed' ),
				'queue'             => 'Wholesale',
				'agents'            => array( 'Z. Khan', 'E. Price', 'T. Cooper' ),
				'main_phone'        => '020 3974 8800',
				'commerce_catalogue'=> 'wellness',
			),
			array(
				'name'              => 'Uni Carts',
				'domain'            => 'uni-carts.com',
				'company_type'      => 'eCommerce brand',
				'kind'              => 'business',
				'sector'            => 'studio-equipment',
				'confidence'        => 'confirmed',
				'city'              => 'Birmingham',
				'region'            => 'West Midlands',
				'asn'               => 'AS2856',
				'isp'               => 'BT Business',
				'paths'             => array( '/products', '/wholesale', '/contact' ),
				'sources'           => array( 'google', 'youtube', 'direct' ),
				'medium'            => 'organic',
				'referrer'          => 'https://www.youtube.com/',
				'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Edge/124.0',
				'call_statuses'     => array( 'connected', 'completed', 'completed' ),
				'queue'             => 'Distributor Sales',
				'agents'            => array( 'J. Howard', 'F. Wells', 'M. Ahmed' ),
				'main_phone'        => '0121 285 9900',
				'commerce_catalogue'=> 'studio',
			),
			array(
				'name'              => 'Solidaritech',
				'domain'            => 'solidaritech.com',
				'company_type'      => 'Charity',
				'kind'              => 'business',
				'sector'            => 'charity-tech',
				'confidence'        => 'likely',
				'city'              => 'Bradford',
				'region'            => 'West Yorkshire',
				'asn'               => 'AS5089',
				'isp'               => 'Virgin Media Business',
				'paths'             => array( '/support-us', '/about', '/contact' ),
				'sources'           => array( 'google', 'direct', 'newsletter' ),
				'medium'            => 'referral',
				'referrer'          => 'https://www.google.com/',
				'user_agent'        => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_5) AppleWebKit/537.36 Chrome/122.0 Safari/537.36',
				'call_statuses'     => array( 'completed', 'connected', 'queued' ),
				'queue'             => 'Partnerships',
				'agents'            => array( 'R. Begum', 'T. Hussain', 'A. Clarke' ),
				'main_phone'        => '01274 900 180',
				'commerce_catalogue'=> '',
			),
			array(
				'name'              => 'The Poetry School',
				'domain'            => 'poetryschool.com',
				'company_type'      => 'Education provider',
				'kind'              => 'business',
				'sector'            => 'online-learning',
				'confidence'        => 'likely',
				'city'              => 'London',
				'region'            => 'Greater London',
				'asn'               => 'AS12576',
				'isp'               => 'KCOM Business',
				'paths'             => array( '/courses', '/membership', '/contact' ),
				'sources'           => array( 'google', 'newsletter', 'facebook' ),
				'medium'            => 'email',
				'referrer'          => 'https://www.facebook.com/',
				'user_agent'        => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
				'call_statuses'     => array( 'connected', 'queued', 'completed' ),
				'queue'             => 'Admissions',
				'agents'            => array( 'L. White', 'P. Morris', 'G. Evans' ),
				'main_phone'        => '020 7582 1679',
				'commerce_catalogue'=> '',
			),
			array(
				'name'              => 'Rotherham Metropolitan Borough Council',
				'domain'            => 'rotherham.gov.uk',
				'company_type'      => 'Local authority',
				'kind'              => 'council',
				'sector'            => 'waste-and-grounds',
				'confidence'        => 'likely',
				'city'              => 'Rotherham',
				'region'            => 'South Yorkshire',
				'asn'               => 'AS2856',
				'isp'               => 'BT Public Sector',
				'paths'             => array( '/business', '/recycling', '/contact-us' ),
				'sources'           => array( 'google', 'direct', 'linkedin' ),
				'medium'            => 'organic',
				'referrer'          => 'https://www.google.com/',
				'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
				'call_statuses'     => array( 'completed', 'connected', 'queued' ),
				'queue'             => 'Neighbourhood Services',
				'agents'            => array( 'S. Hughes', 'N. Cooper', 'A. Bailey' ),
				'main_phone'        => '01709 382121',
				'commerce_catalogue'=> 'waste',
			),
			array(
				'name'              => 'Barnsley Metropolitan Borough Council',
				'domain'            => 'barnsley.gov.uk',
				'company_type'      => 'Local authority',
				'kind'              => 'council',
				'sector'            => 'waste-collection',
				'confidence'        => 'confirmed',
				'city'              => 'Barnsley',
				'region'            => 'South Yorkshire',
				'asn'               => 'AS2856',
				'isp'               => 'BT Public Sector',
				'paths'             => array( '/services/environment', '/procurement', '/contact' ),
				'sources'           => array( 'google', 'newsletter', 'direct' ),
				'medium'            => 'referral',
				'referrer'          => 'https://www.bing.com/',
				'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Firefox/124.0',
				'call_statuses'     => array( 'completed', 'connected', 'connected' ),
				'queue'             => 'Procurement',
				'agents'            => array( 'I. Jackson', 'D. Turner', 'P. Gray' ),
				'main_phone'        => '01226 770770',
				'commerce_catalogue'=> 'waste',
			),
		);
	}
}
