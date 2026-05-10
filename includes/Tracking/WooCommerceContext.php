<?php
/**
 * WooCommerce page context helper.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

use WP_Term;

defined( 'ABSPATH' ) || exit;

final class WooCommerceContext {
	/**
	 * Build the current frontend WooCommerce context.
	 *
	 * @return array<string, mixed>
	 */
	public function get_frontend_context(): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return $this->get_product_context();
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return $this->get_product_category_context();
		}

		return array();
	}

	/**
	 * Determine whether WooCommerce page helpers are available.
	 *
	 * @return bool
	 */
	private function is_available(): bool {
		return function_exists( 'is_product' ) && function_exists( 'is_product_category' );
	}

	/**
	 * Build context for a single product page.
	 *
	 * @return array<string, mixed>
	 */
	private function get_product_context(): array {
		$product_id = get_queried_object_id();

		if ( $product_id <= 0 ) {
			return array();
		}

		$categories = $this->map_terms( get_the_terms( $product_id, 'product_cat' ) );
		$brand      = $this->get_brand_context( $product_id );

		return array(
			'is_woocommerce' => true,
			'context_type'   => 'product',
			'post_id'        => $product_id,
			'post_type'      => 'product',
			'product'        => array(
				'id'   => $product_id,
				'slug' => (string) get_post_field( 'post_name', $product_id ),
				'name' => get_the_title( $product_id ),
			),
			'categories'     => $categories,
			'brand'          => $brand,
		);
	}

	/**
	 * Build context for a product category archive.
	 *
	 * @return array<string, mixed>
	 */
	private function get_product_category_context(): array {
		$term = get_queried_object();

		if ( ! ( $term instanceof WP_Term ) ) {
			return array();
		}

		$category = array(
			'id'   => (int) $term->term_id,
			'slug' => (string) $term->slug,
			'name' => (string) $term->name,
		);

		return array(
			'is_woocommerce' => true,
			'context_type'   => 'product_category',
			'post_id'        => 0,
			'post_type'      => 'product_cat',
			'category'       => $category,
			'categories'     => array( $category ),
		);
	}

	/**
	 * Map taxonomy terms into a lightweight payload.
	 *
	 * @param mixed $terms Terms.
	 * @return array<int, array<string, mixed>>
	 */
	private function map_terms( $terms ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $term ): ?array {
						if ( ! ( $term instanceof WP_Term ) ) {
							return null;
						}

						return array(
							'id'   => (int) $term->term_id,
							'slug' => (string) $term->slug,
							'name' => (string) $term->name,
						);
					},
					$terms
				)
			)
		);
	}

	/**
	 * Read a product brand term when one is available.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>
	 */
	private function get_brand_context( int $product_id ): array {
		foreach ( array( 'product_brand', 'pa_brand' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $product_id, $taxonomy );

			if ( ! is_array( $terms ) || empty( $terms[0] ) || ! ( $terms[0] instanceof WP_Term ) ) {
				continue;
			}

			return array(
				'id'   => (int) $terms[0]->term_id,
				'slug' => (string) $terms[0]->slug,
				'name' => (string) $terms[0]->name,
			);
		}

		return array();
	}
}
