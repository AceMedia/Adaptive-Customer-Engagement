<?php
/**
 * Event repository.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database\Repositories;

use ACE\AdaptiveCustomerEngagement\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class EventRepository {
	/**
	 * Insert an event.
	 *
	 * @param int                  $session_id Session ID.
	 * @param array<string, mixed> $data       Event payload.
	 * @return int
	 */
	public function insert( int $session_id, array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::table_name( 'events' ),
			array(
				'event_uuid'       => $data['event_uuid'],
				'session_id'       => $session_id,
				'event_type'       => $data['event_type'],
				'event_name'       => $data['event_name'],
				'url'              => $data['url'],
				'path'             => $data['path'],
				'page_title'       => $data['page_title'],
				'post_id'          => $data['post_id'],
				'post_type'        => $data['post_type'],
				'taxonomy_context' => $data['taxonomy_context'],
				'product_area'     => $data['product_area'],
				'brand_context'    => $data['brand_context'],
				'number_id'        => $data['number_id'],
				'metadata'         => wp_json_encode( $data['metadata'] ),
				'occurred_at'      => current_time( 'mysql', true ),
				'created_at'       => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Count click-to-call events today.
	 *
	 * @return int
	 */
	public function count_click_to_call_today(): int {
		return $this->count_today_by_type( 'click_to_call' );
	}

	/**
	 * Count events of a given type today.
	 *
	 * @param string $event_type Event type.
	 * @return int
	 */
	public function count_today_by_type( string $event_type ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table_name( 'events' ) . ' WHERE DATE(occurred_at) = UTC_DATE() AND event_type = %s',
				$event_type
			)
		);
	}

	/**
	 * Top pageview paths.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_top_pages( int $limit = 5 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT path, COUNT(*) AS total FROM ' . Schema::table_name( 'events' ) . " WHERE event_type = 'pageview' AND path IS NOT NULL AND path != '' GROUP BY path ORDER BY total DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get all events for a session.
	 *
	 * @param int $session_id Session ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_by_session( int $session_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table_name( 'events' ) . ' WHERE session_id = %d ORDER BY occurred_at ASC, id ASC',
				$session_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				$row['metadata'] = json_decode( (string) $row['metadata'], true );
				$row['metadata'] = is_array( $row['metadata'] ) ? $row['metadata'] : array();

				return $row;
			},
			$rows
		);
	}

	/**
	 * Get top paths that drove call intent.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_top_call_paths( int $limit = 8, array $filters = array() ): array {
		global $wpdb;
		$where  = array( "event_type = 'click_to_call'", "path IS NOT NULL", "path != ''" );
		$params = array();

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'DATE(occurred_at) >= %s';
			$params[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'DATE(occurred_at) <= %s';
			$params[] = $filters['date_to'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = '(path LIKE %s OR event_name LIKE %s OR metadata LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT path, COUNT(*) AS total FROM ' . Schema::table_name( 'events' ) . ' WHERE ' . implode( ' AND ', $where ) . ' GROUP BY path ORDER BY total DESC LIMIT %d',
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get recent sessions with call intent.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_call_intent_sessions( int $limit = 20, array $filters = array() ): array {
		global $wpdb;

		$events    = Schema::table_name( 'events' );
		$sessions  = Schema::table_name( 'sessions' );
		$companies = Schema::table_name( 'companies' );

		$where  = array();
		$params = array();

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'DATE(e.occurred_at) >= %s';
			$params[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'DATE(e.occurred_at) <= %s';
			$params[] = $filters['date_to'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = '(s.session_uuid LIKE %s OR s.landing_path LIKE %s OR s.referrer LIKE %s OR c.name LIKE %s OR e.path LIKE %s OR e.event_name LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.session_uuid, s.visitor_uuid, s.landing_path, s.referrer, s.utm_source, s.utm_campaign, s.company_confidence, s.is_bot, s.ignored, s.last_seen, c.name AS company_name,
					COUNT(e.id) AS event_count,
					SUM(CASE WHEN e.event_type = 'click_to_call' THEN 1 ELSE 0 END) AS call_clicks,
					SUM(CASE WHEN e.event_type = 'download' THEN 1 ELSE 0 END) AS download_count,
					SUM(CASE WHEN e.event_type = 'form_submit' THEN 1 ELSE 0 END) AS form_count
				FROM {$sessions} s
				INNER JOIN {$events} e ON e.session_id = s.id AND e.event_type = 'click_to_call'
				LEFT JOIN {$companies} c ON c.id = s.company_id
				" . ( $where ? 'WHERE ' . implode( ' AND ', $where ) : '' ) . "
				GROUP BY s.id
				ORDER BY MAX(e.occurred_at) DESC, s.last_seen DESC
				LIMIT %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get a WooCommerce repeat-interest reporting snapshot.
	 *
	 * @param int $limit Rows per report section.
	 * @return array<string, mixed>
	 */
	public function get_woocommerce_interest_report( array $filters = array(), int $limit = 10 ): array {
		$report      = $this->aggregate_woocommerce_interest( $this->get_woocommerce_interest_rows( 0, 0, 2000, $filters ) );
		$repeat_only = ! empty( $filters['repeat_only'] );
		$sessions    = array_values(
			array_filter(
				$report['sessions'],
				static function ( array $session ) use ( $repeat_only ): bool {
					return ! $repeat_only || ! empty( $session['has_repeat_interest'] );
				}
			)
		);
		$companies   = array_values(
			array_filter(
				$report['companies'],
				static function ( array $company ) use ( $repeat_only ): bool {
					return ! $repeat_only || ! empty( $company['has_repeat_interest'] );
				}
			)
		);

		return array(
			'metrics'          => array(
				'sessions_with_interest'       => count( $sessions ),
				'sessions_with_repeat_interest'=> count(
					array_filter(
						$sessions,
						static function ( array $session ): bool {
							return ! empty( $session['has_repeat_interest'] );
						}
					)
				),
				'companies_with_interest'      => count( $companies ),
				'companies_with_repeat_interest' => count(
					array_filter(
						$companies,
						static function ( array $company ): bool {
							return ! empty( $company['has_repeat_interest'] );
						}
					)
				),
				'products_tracked'             => count( $report['products'] ),
				'categories_tracked'           => count( $report['categories'] ),
			),
			'top_products'     => array_slice( array_values( $report['products'] ), 0, $limit ),
			'top_categories'   => array_slice( array_values( $report['categories'] ), 0, $limit ),
			'repeat_sessions'  => array_slice( $sessions, 0, $limit ),
			'repeat_companies' => array_slice( $companies, 0, $limit ),
		);
	}

	/**
	 * Get WooCommerce interest summary for a single session.
	 *
	 * @param int $session_id Session ID.
	 * @return array<string, mixed>
	 */
	public function get_session_woocommerce_interest( int $session_id ): array {
		$report = $this->aggregate_woocommerce_interest( $this->get_woocommerce_interest_rows( $session_id, 0, 250 ) );

		return $report['session_details'][ $session_id ] ?? $this->empty_woocommerce_interest_summary();
	}

	/**
	 * Get WooCommerce interest summary for a company across its sessions.
	 *
	 * @param int $company_id Company ID.
	 * @return array<string, mixed>
	 */
	public function get_company_woocommerce_interest( int $company_id ): array {
		$report = $this->aggregate_woocommerce_interest( $this->get_woocommerce_interest_rows( 0, $company_id, 1000 ) );

		return $report['company_details'][ $company_id ] ?? $this->empty_woocommerce_interest_summary();
	}

	/**
	 * Read WooCommerce-interest events with session and company context.
	 *
	 * @param int $session_id Optional session ID.
	 * @param int $company_id Optional company ID.
	 * @param int $limit      Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_woocommerce_interest_rows( int $session_id = 0, int $company_id = 0, int $limit = 2000, array $filters = array() ): array {
		global $wpdb;

		$events     = Schema::table_name( 'events' );
		$sessions   = Schema::table_name( 'sessions' );
		$companies  = Schema::table_name( 'companies' );
		$where      = array(
			"e.event_type = 'pageview'",
			"(e.post_type = 'product' OR e.post_type = 'product_cat' OR e.product_area IS NOT NULL AND e.product_area != '' OR e.taxonomy_context IS NOT NULL AND e.taxonomy_context != '')",
		);
		$params     = array();

		if ( $session_id > 0 ) {
			$where[]  = 'e.session_id = %d';
			$params[] = $session_id;
		}

		if ( $company_id > 0 ) {
			$where[]  = 's.company_id = %d';
			$params[] = $company_id;
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'DATE(e.occurred_at) >= %s';
			$params[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'DATE(e.occurred_at) <= %s';
			$params[] = $filters['date_to'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(s.session_uuid LIKE %s OR s.landing_path LIKE %s OR c.name LIKE %s OR e.path LIKE %s OR e.product_area LIKE %s OR e.taxonomy_context LIKE %s OR e.metadata LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, s.session_uuid, s.visitor_uuid, s.landing_path, s.referrer, s.utm_source, s.utm_campaign, s.company_id, s.company_confidence, s.is_bot, s.ignored, s.last_seen,
					c.name AS company_name, c.domain AS company_domain, c.type AS company_type, c.confidence AS company_record_confidence, c.total_sessions, c.total_events, c.total_calls, c.first_seen AS company_first_seen, c.last_seen AS company_last_seen
				FROM {$events} e
				INNER JOIN {$sessions} s ON s.id = e.session_id
				LEFT JOIN {$companies} c ON c.id = s.company_id
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY e.occurred_at DESC, e.id DESC
				LIMIT %d',
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate WooCommerce-interest report data.
	 *
	 * @param array<int, array<string, mixed>> $rows Relevant events.
	 * @return array<string, mixed>
	 */
	private function aggregate_woocommerce_interest( array $rows ): array {
		$products        = array();
		$categories      = array();
		$sessions        = array();
		$companies       = array();
		$session_details = array();
		$company_details = array();

		foreach ( $rows as $row ) {
			$metadata         = json_decode( (string) ( $row['metadata'] ?? '' ), true );
			$metadata         = is_array( $metadata ) ? $metadata : array();
			$session_id       = (int) ( $row['session_id'] ?? 0 );
			$company_id       = (int) ( $row['company_id'] ?? 0 );
			$product_interest = $this->extract_product_interest( $row, $metadata );
			$category_interest = $this->extract_category_interest( $row, $metadata );

			if ( $session_id > 0 && ! isset( $sessions[ $session_id ] ) ) {
				$sessions[ $session_id ] = array(
					'id'                  => $session_id,
					'session_uuid'        => (string) ( $row['session_uuid'] ?? '' ),
					'visitor_uuid'        => (string) ( $row['visitor_uuid'] ?? '' ),
					'landing_path'        => (string) ( $row['landing_path'] ?? '' ),
					'referrer'            => (string) ( $row['referrer'] ?? '' ),
					'utm_source'          => (string) ( $row['utm_source'] ?? '' ),
					'utm_campaign'        => (string) ( $row['utm_campaign'] ?? '' ),
					'company_confidence'  => (string) ( $row['company_confidence'] ?? 'unknown' ),
					'is_bot'              => (int) ( $row['is_bot'] ?? 0 ),
					'ignored'             => (int) ( $row['ignored'] ?? 0 ),
					'last_seen'           => (string) ( $row['last_seen'] ?? '' ),
					'company_name'        => (string) ( $row['company_name'] ?? '' ),
					'event_count'         => 0,
					'call_clicks'         => 0,
					'download_count'      => 0,
					'form_count'          => 0,
					'product_repeat_max'  => 0,
					'category_repeat_max' => 0,
					'products'            => array(),
					'categories'          => array(),
				);
			}

			if ( $company_id > 0 && ! isset( $companies[ $company_id ] ) ) {
				$companies[ $company_id ] = array(
					'id'                  => $company_id,
					'name'                => (string) ( $row['company_name'] ?? '' ),
					'domain'              => (string) ( $row['company_domain'] ?? '' ),
					'type'                => (string) ( $row['company_type'] ?? '' ),
					'confidence'          => (string) ( $row['company_record_confidence'] ?? $row['company_confidence'] ?? 'unknown' ),
					'total_sessions'      => (int) ( $row['total_sessions'] ?? 0 ),
					'total_events'        => (int) ( $row['total_events'] ?? 0 ),
					'total_calls'         => (int) ( $row['total_calls'] ?? 0 ),
					'first_seen'          => (string) ( $row['company_first_seen'] ?? '' ),
					'last_seen'           => (string) ( $row['company_last_seen'] ?? $row['last_seen'] ?? '' ),
					'product_repeat_max'  => 0,
					'category_repeat_max' => 0,
					'products'            => array(),
					'categories'          => array(),
				);
			}

			if ( $session_id > 0 ) {
				++$sessions[ $session_id ]['event_count'];
			}

			if ( $product_interest ) {
				$this->record_woocommerce_interest( $products, $product_interest );

				if ( $session_id > 0 ) {
					$this->record_woocommerce_interest( $sessions[ $session_id ]['products'], $product_interest );
					$sessions[ $session_id ]['product_repeat_max'] = max( $sessions[ $session_id ]['product_repeat_max'], (int) $product_interest['repeat_views'] );
				}

				if ( $company_id > 0 ) {
					$this->record_woocommerce_interest( $companies[ $company_id ]['products'], $product_interest );
					$companies[ $company_id ]['product_repeat_max'] = max( $companies[ $company_id ]['product_repeat_max'], (int) $product_interest['repeat_views'] );
				}
			}

			if ( $category_interest ) {
				$this->record_woocommerce_interest( $categories, $category_interest );

				if ( $session_id > 0 ) {
					$this->record_woocommerce_interest( $sessions[ $session_id ]['categories'], $category_interest );
					$sessions[ $session_id ]['category_repeat_max'] = max( $sessions[ $session_id ]['category_repeat_max'], (int) $category_interest['repeat_views'] );
				}

				if ( $company_id > 0 ) {
					$this->record_woocommerce_interest( $companies[ $company_id ]['categories'], $category_interest );
					$companies[ $company_id ]['category_repeat_max'] = max( $companies[ $company_id ]['category_repeat_max'], (int) $category_interest['repeat_views'] );
				}
			}
		}

		foreach ( $sessions as $session_id => $session ) {
			$session['products']            = $this->normalise_interest_list( $session['products'] );
			$session['categories']          = $this->normalise_interest_list( $session['categories'] );
			$session['has_repeat_interest'] = $session['product_repeat_max'] > 1 || $session['category_repeat_max'] > 1;
			$session['repeat_interest_summary'] = $this->build_interest_summary( $session['products'], $session['categories'], $session['has_repeat_interest'] );
			$session_details[ $session_id ] = array(
				'summary'             => $session['repeat_interest_summary'],
				'has_repeat_interest' => $session['has_repeat_interest'],
				'products'            => array_slice( $session['products'], 0, 5 ),
				'categories'          => array_slice( $session['categories'], 0, 5 ),
			);
			$sessions[ $session_id ]        = $session;
		}

		foreach ( $companies as $company_id => $company ) {
			$company['products']            = $this->normalise_interest_list( $company['products'] );
			$company['categories']          = $this->normalise_interest_list( $company['categories'] );
			$company['has_repeat_interest'] = $company['product_repeat_max'] > 1 || $company['category_repeat_max'] > 1;
			$company['repeat_interest_summary'] = $this->build_interest_summary( $company['products'], $company['categories'], $company['has_repeat_interest'] );
			$company_details[ $company_id ] = array(
				'summary'             => $company['repeat_interest_summary'],
				'has_repeat_interest' => $company['has_repeat_interest'],
				'products'            => array_slice( $company['products'], 0, 5 ),
				'categories'          => array_slice( $company['categories'], 0, 5 ),
			);
			$companies[ $company_id ]       = $company;
		}

		uasort( $products, array( $this, 'sort_interest_rows' ) );
		uasort( $categories, array( $this, 'sort_interest_rows' ) );
		uasort(
			$sessions,
			static function ( array $left, array $right ): int {
				return ( $right['product_repeat_max'] + $right['category_repeat_max'] ) <=> ( $left['product_repeat_max'] + $left['category_repeat_max'] );
			}
		);
		uasort(
			$companies,
			static function ( array $left, array $right ): int {
				return ( $right['product_repeat_max'] + $right['category_repeat_max'] ) <=> ( $left['product_repeat_max'] + $left['category_repeat_max'] );
			}
		);

		return array(
			'products'        => $this->normalise_interest_list( $products ),
			'categories'      => $this->normalise_interest_list( $categories ),
			'sessions'        => array_values( $sessions ),
			'companies'       => array_values( $companies ),
			'session_details' => $session_details,
			'company_details' => $company_details,
		);
	}

	/**
	 * Extract product interest data from an event row.
	 *
	 * @param array<string, mixed> $row      Event row.
	 * @param array<string, mixed> $metadata Event metadata.
	 * @return array<string, mixed>|null
	 */
	private function extract_product_interest( array $row, array $metadata ): ?array {
		$product_id   = isset( $metadata['product_id'] ) ? (int) $metadata['product_id'] : (int) ( $row['post_id'] ?? 0 );
		$product_slug = (string) ( $metadata['product_slug'] ?? $row['product_area'] ?? '' );
		$product_name = (string) ( $metadata['product_name'] ?? '' );

		if ( $product_id <= 0 && '' === $product_slug && '' === $product_name ) {
			return null;
		}

		return array(
			'key'          => 'product:' . ( $product_id > 0 ? (string) $product_id : $product_slug ),
			'id'           => $product_id,
			'slug'         => $product_slug,
			'name'         => $product_name ?: $product_slug,
			'views'        => 1,
			'repeat_views' => max( 1, (int) ( $metadata['product_view_count'] ?? 1 ) ),
		);
	}

	/**
	 * Extract category interest data from an event row.
	 *
	 * @param array<string, mixed> $row      Event row.
	 * @param array<string, mixed> $metadata Event metadata.
	 * @return array<string, mixed>|null
	 */
	private function extract_category_interest( array $row, array $metadata ): ?array {
		$category_id   = isset( $metadata['category_id'] ) ? (int) $metadata['category_id'] : 0;
		$category_slug = (string) ( $metadata['category_slug'] ?? '' );
		$category_name = (string) ( $metadata['category_name'] ?? '' );

		if ( '' === $category_slug && ! empty( $row['taxonomy_context'] ) ) {
			$category_slug = trim( explode( ',', (string) $row['taxonomy_context'] )[0] );
		}

		if ( '' === $category_name && ! empty( $metadata['product_category_names'] ) ) {
			$category_name = trim( explode( ',', (string) $metadata['product_category_names'] )[0] );
		}

		if ( $category_id <= 0 && '' === $category_slug && '' === $category_name ) {
			return null;
		}

		return array(
			'key'          => 'category:' . ( $category_id > 0 ? (string) $category_id : $category_slug ),
			'id'           => $category_id,
			'slug'         => $category_slug,
			'name'         => $category_name ?: $category_slug,
			'views'        => 1,
			'repeat_views' => max( 1, (int) ( $metadata['category_view_count'] ?? 1 ) ),
		);
	}

	/**
	 * Record an interest item into an aggregate bucket.
	 *
	 * @param array<string, array<string, mixed>> $bucket Aggregate bucket.
	 * @param array<string, mixed>                 $item   Interest item.
	 * @return void
	 */
	private function record_woocommerce_interest( array &$bucket, array $item ): void {
		$key = (string) $item['key'];

		if ( ! isset( $bucket[ $key ] ) ) {
			$bucket[ $key ] = $item;
			return;
		}

		$bucket[ $key ]['views']        += (int) $item['views'];
		$bucket[ $key ]['repeat_views'] = max( (int) $bucket[ $key ]['repeat_views'], (int) $item['repeat_views'] );

		if ( empty( $bucket[ $key ]['name'] ) && ! empty( $item['name'] ) ) {
			$bucket[ $key ]['name'] = $item['name'];
		}
	}

	/**
	 * Normalise an interest map into a sorted list.
	 *
	 * @param array<string, array<string, mixed>> $items Interest map.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalise_interest_list( array $items ): array {
		uasort( $items, array( $this, 'sort_interest_rows' ) );

		return array_values( $items );
	}

	/**
	 * Sort interest rows by repeat strength and view count.
	 *
	 * @param array<string, mixed> $left  Left row.
	 * @param array<string, mixed> $right Right row.
	 * @return int
	 */
	private function sort_interest_rows( array $left, array $right ): int {
		$left_score  = ( (int) $left['repeat_views'] * 100 ) + (int) $left['views'];
		$right_score = ( (int) $right['repeat_views'] * 100 ) + (int) $right['views'];

		return $right_score <=> $left_score;
	}

	/**
	 * Build a compact interest summary.
	 *
	 * @param array<int, array<string, mixed>> $products   Product interests.
	 * @param array<int, array<string, mixed>> $categories Category interests.
	 * @param bool                             $is_repeat  Whether repeat interest exists.
	 * @return string
	 */
	private function build_interest_summary( array $products, array $categories, bool $is_repeat ): string {
		$labels = array();

		if ( ! empty( $products[0]['name'] ) ) {
			$labels[] = (string) $products[0]['name'];
		}

		if ( ! empty( $categories[0]['name'] ) ) {
			$labels[] = (string) $categories[0]['name'];
		}

		if ( ! $labels ) {
			return 'No WooCommerce interest recorded yet';
		}

		if ( $is_repeat ) {
			return 'Repeat interest around ' . implode( ' and ', array_slice( $labels, 0, 2 ) );
		}

		return 'Viewed ' . implode( ' and ', array_slice( $labels, 0, 2 ) );
	}

	/**
	 * Empty WooCommerce interest summary shape.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_woocommerce_interest_summary(): array {
		return array(
			'summary'             => 'No WooCommerce interest recorded yet',
			'has_repeat_interest' => false,
			'products'            => array(),
			'categories'          => array(),
		);
	}

	/**
	 * Get export-ready WooCommerce reporting rows.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function get_woocommerce_interest_exports( array $filters = array() ): array {
		$report = $this->get_woocommerce_interest_report( $filters, 500 );

		return array(
			'products'   => $report['top_products'],
			'categories' => $report['top_categories'],
			'sessions'   => $report['repeat_sessions'],
			'companies'  => $report['repeat_companies'],
		);
	}
}
