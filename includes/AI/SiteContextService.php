<?php
/**
 * Live site-context retrieval for bot runtimes.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

defined( 'ABSPATH' ) || exit;

final class SiteContextService {
	/**
	 * Get the supported content types for live bot context.
	 *
	 * @return array<int, string>
	 */
	public function get_supported_post_types(): array {
		$post_types = array( 'page', 'post' );

		if ( post_type_exists( 'product' ) ) {
			$post_types[] = 'product';
		}

		return $post_types;
	}

	/**
	 * Get basic site metadata for bot runtimes.
	 *
	 * @return array<string, string>
	 */
	public function get_site_identity(): array {
		return array(
			'name'        => sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'description' => sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ) ),
			'url'         => esc_url_raw( home_url( '/' ) ),
			'language'    => sanitize_text_field( get_bloginfo( 'language' ) ),
		);
	}

	/**
	 * Search live site content for the most relevant documents.
	 *
	 * @param string            $query      User query.
	 * @param int               $limit      Result limit.
	 * @param array<int,string> $post_types Optional post-type filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $query, int $limit = 5, array $post_types = array() ): array {
		$limit          = max( 1, min( 8, $limit ) );
		$query          = $this->normalise_text( $query );
		$post_types     = $this->sanitise_post_types( $post_types );
		$query_terms    = $this->extract_terms( $query );
		$candidate_size = max( 8, min( 30, $limit * 5 ) );

		$args = array(
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'posts_per_page'      => $candidate_size,
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'suppress_filters'    => false,
			'ignore_sticky_posts' => true,
		);

		if ( '' !== $query ) {
			$args['s'] = $query;
		}

		$posts = get_posts( $args );

		if ( empty( $posts ) && '' !== $query ) {
			unset( $args['s'] );
			$posts = get_posts( $args );
		}

		$documents = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$document = $this->build_document( $post, $query_terms, false );

			if ( empty( $document ) ) {
				continue;
			}

			$documents[] = $document;
		}

		usort(
			$documents,
			static function ( array $left, array $right ): int {
				$left_score  = (float) ( $left['score'] ?? 0 );
				$right_score = (float) ( $right['score'] ?? 0 );

				if ( $left_score === $right_score ) {
					return strcmp( (string) ( $right['modified_gmt'] ?? '' ), (string) ( $left['modified_gmt'] ?? '' ) );
				}

				return $right_score <=> $left_score;
			}
		);

		if ( '' !== $query ) {
			$documents = array_values(
				array_filter(
					$documents,
					static function ( array $document ): bool {
						return (float) ( $document['score'] ?? 0 ) > 0;
					}
				)
			);
		}

		if ( empty( $documents ) ) {
			foreach ( get_posts( $args ) as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}

				$document = $this->build_document( $post, array(), false );

				if ( empty( $document ) ) {
					continue;
				}

				$documents[] = $document;

				if ( count( $documents ) >= $limit ) {
					break;
				}
			}
		}

		return array_slice( $documents, 0, $limit );
	}

	/**
	 * Build a direct answer from the site's live content.
	 *
	 * @param string $question User question.
	 * @param int    $limit    Number of source documents to use.
	 * @return array<string, mixed>
	 */
	public function answer_question( string $question, int $limit = 3 ): array {
		$question = $this->normalise_text( $question );
		$site     = $this->get_site_identity();

		if ( '' === $question ) {
			return array(
				'answer'     => sprintf(
					/* translators: %s: site name */
					__( 'Hello from %s. Ask me about the site, a page, a post, or one of the products and I will look it up from the live content.', 'adaptive-customer-engagement' ),
					$site['name']
				),
				'sources'    => array(),
				'confidence' => 'low',
				'fallback'   => false,
			);
		}

		if ( preg_match( '/^(hi|hello|hey|hiya|good morning|good afternoon|good evening)\b/i', $question ) ) {
			return array(
				'answer'     => sprintf(
					/* translators: %s: site name */
					__( 'Hello, I am the %s site assistant. Ask me about products, pages, posts, or anything published on the site and I will look it up live.', 'adaptive-customer-engagement' ),
					$site['name']
				),
				'sources'    => array(),
				'confidence' => 'low',
				'fallback'   => false,
			);
		}

		$documents = $this->search( $this->expand_query_for_search( $question ), $limit );

		if ( empty( $documents ) ) {
			return array(
				'answer'     => __( 'I could not find a clear answer in the current site content. Please try naming the page, post, or product you want to know about.', 'adaptive-customer-engagement' ),
				'sources'    => array(),
				'confidence' => 'low',
				'fallback'   => true,
			);
		}

		$top        = $documents[0];
		$top_score  = (float) ( $top['score'] ?? 0 );
		$confidence = $top_score >= 18 ? 'high' : ( $top_score >= 8 ? 'medium' : 'low' );
		$answer     = $this->build_answer_text( $question, $documents );

		return array(
			'answer'     => $answer,
			'sources'    => array_map(
				static function ( array $document ): array {
					return array(
						'id'           => (int) ( $document['id'] ?? 0 ),
						'title'        => sanitize_text_field( (string) ( $document['title'] ?? '' ) ),
						'url'          => esc_url_raw( (string) ( $document['url'] ?? '' ) ),
						'source_type'  => sanitize_key( (string) ( $document['source_type'] ?? '' ) ),
						'source_label' => sanitize_text_field( (string) ( $document['source_label'] ?? '' ) ),
						'summary'      => sanitize_textarea_field( (string) ( $document['summary'] ?? '' ) ),
					);
				},
				$documents
			),
			'confidence' => $confidence,
			'fallback'   => false,
		);
	}

	/**
	 * Read a single published document in a bot-friendly format.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	public function get_document( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		if ( ! in_array( $post->post_type, $this->get_supported_post_types(), true ) ) {
			return null;
		}

		$document = $this->build_document( $post, array(), true );

		return empty( $document ) ? null : $document;
	}

	/**
	 * Export a compact site snapshot for runtimes that cannot reach the WordPress host directly.
	 *
	 * @param int $limit Maximum number of documents to include.
	 * @return array<string, mixed>
	 */
	public function export_snapshot( int $limit = 50 ): array {
		$limit = max( 1, min( 100, $limit ) );
		$posts = get_posts(
			array(
				'post_type'           => $this->get_supported_post_types(),
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			)
		);

		$documents = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$document = $this->build_document( $post, array(), true );

			if ( empty( $document ) ) {
				continue;
			}

			$documents[] = $document;
		}

		return array(
			'site'         => $this->get_site_identity(),
			'generated_at' => gmdate( 'c' ),
			'documents'    => $documents,
		);
	}

	/**
	 * Build a bot-friendly document payload for a post.
	 *
	 * @param \WP_Post          $post            Post.
	 * @param array<int,string> $query_terms     Search terms.
	 * @param bool              $include_content Whether to include fuller content.
	 * @return array<string, mixed>
	 */
	private function build_document( \WP_Post $post, array $query_terms, bool $include_content ): array {
		$title = sanitize_text_field( get_the_title( $post ) );

		if ( '' === $title ) {
			return array();
		}

		$plain_content = $this->build_plain_content( $post );

		if ( '' === $plain_content ) {
			return array();
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		$type_label    = $post_type_obj && ! empty( $post_type_obj->labels->singular_name ) ? sanitize_text_field( $post_type_obj->labels->singular_name ) : ucfirst( $post->post_type );
		$summary       = wp_trim_words( $plain_content, 55, '…' );
		$excerpt       = $this->extract_relevant_excerpt( $plain_content, $query_terms );
		$score         = $this->calculate_score( $title, $plain_content, $query_terms );
		$document      = array(
			'id'           => (int) $post->ID,
			'title'        => $title,
			'url'          => esc_url_raw( get_permalink( $post ) ?: '' ),
			'source_type'  => sanitize_key( $post->post_type ),
			'source_label' => sprintf( '%s: %s', $type_label, $title ),
			'summary'      => sanitize_textarea_field( $summary ),
			'excerpt'      => sanitize_textarea_field( $excerpt ),
			'score'        => $score,
			'modified_gmt' => sanitize_text_field( (string) $post->post_modified_gmt ),
			'terms'        => $this->get_post_terms( $post ),
		);

		$commerce = $this->get_product_details( $post );

		if ( ! empty( $commerce ) ) {
			$document['commerce'] = $commerce;
		}

		if ( $include_content ) {
			$document['content'] = sanitize_textarea_field( $this->trim_content( $plain_content, 3000 ) );
		}

		return $document;
	}

	/**
	 * Build a human-readable answer from matching documents.
	 *
	 * @param string                            $question  User question.
	 * @param array<int, array<string, mixed>> $documents Matching documents.
	 * @return string
	 */
	private function build_answer_text( string $question, array $documents ): string {
		$top          = $documents[0];
		$title        = sanitize_text_field( (string) ( $top['title'] ?? '' ) );
		$summary      = sanitize_textarea_field( (string) ( $top['summary'] ?? '' ) );
		$source_type  = sanitize_key( (string) ( $top['source_type'] ?? '' ) );
		$product_text = '';

		if ( 'product' === $source_type && ! empty( $top['commerce'] ) && is_array( $top['commerce'] ) ) {
			$price = sanitize_text_field( (string) ( $top['commerce']['price'] ?? '' ) );

			if ( '' !== $price ) {
				$product_text = sprintf(
					/* translators: %s: price text. */
					__( ' Current price: %s.', 'adaptive-customer-engagement' ),
					$price
				);
			}
		}

		$answer = sprintf(
			/* translators: 1: title, 2: summary, 3: optional product text */
			__( 'I found this on %1$s: %2$s%3$s', 'adaptive-customer-engagement' ),
			$title,
			$summary,
			$product_text
		);

		if ( count( $documents ) > 1 ) {
			$related_titles = array();

			foreach ( array_slice( $documents, 1, 2 ) as $document ) {
				$related_title = sanitize_text_field( (string) ( $document['title'] ?? '' ) );

				if ( '' !== $related_title ) {
					$related_titles[] = $related_title;
				}
			}

			if ( ! empty( $related_titles ) ) {
				$answer .= ' ' . sprintf(
					/* translators: %s: comma-separated related content titles. */
					__( 'You may also want to look at %s.', 'adaptive-customer-engagement' ),
					implode( ', ', $related_titles )
				);
			}
		}

		if ( preg_match( '/\b(contact|phone|email|address|get in touch)\b/i', $question ) ) {
			$answer .= ' ' . __( 'If you need the formal contact details, ask specifically about the contact page and I will look that up too.', 'adaptive-customer-engagement' );
		}

		return sanitize_textarea_field( trim( $answer ) );
	}

	/**
	 * Expand generic user queries so live site search is more forgiving.
	 *
	 * @param string $query User question.
	 * @return string
	 */
	private function expand_query_for_search( string $query ): string {
		$expanded = $query;

		if ( preg_match( '/\b(what do you sell|what do you offer|products|services|what can you help with)\b/i', $query ) ) {
			$expanded .= ' products services solutions about';
		}

		if ( preg_match( '/\b(contact|phone|email|address|get in touch)\b/i', $query ) ) {
			$expanded .= ' contact phone email address';
		}

		if ( preg_match( '/\b(price|cost|pricing|quote)\b/i', $query ) ) {
			$expanded .= ' price pricing quote product';
		}

		return $expanded;
	}

	/**
	 * Sanitise a post-type filter.
	 *
	 * @param array<int,string> $post_types Raw post types.
	 * @return array<int, string>
	 */
	private function sanitise_post_types( array $post_types ): array {
		$supported = $this->get_supported_post_types();
		$post_types = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					$post_types
				),
				static function ( string $post_type ) use ( $supported ): bool {
					return in_array( $post_type, $supported, true );
				}
			)
		);

		return ! empty( $post_types ) ? $post_types : $supported;
	}

	/**
	 * Build plain searchable content from a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function build_plain_content( \WP_Post $post ): string {
		$parts = array();

		if ( has_excerpt( $post ) ) {
			$parts[] = (string) $post->post_excerpt;
		}

		$parts[] = (string) $post->post_content;

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );

			if ( $product ) {
				$short_description = method_exists( $product, 'get_short_description' ) ? (string) $product->get_short_description() : '';
				$description       = method_exists( $product, 'get_description' ) ? (string) $product->get_description() : '';
				$price_text        = method_exists( $product, 'get_price_html' ) ? wp_strip_all_tags( (string) $product->get_price_html() ) : '';
				$sku               = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';

				if ( '' !== $short_description ) {
					$parts[] = $short_description;
				}

				if ( '' !== $description ) {
					$parts[] = $description;
				}

				if ( '' !== $price_text ) {
					$parts[] = $price_text;
				}

				if ( '' !== $sku ) {
					$parts[] = $sku;
				}
			}
		}

		$parts   = array_filter(
			array_map(
				static function ( string $part ): string {
					return trim( wp_strip_all_tags( strip_shortcodes( $part ) ) );
				},
				$parts
			)
		);
		$content = implode( ' ', $parts );
		$content = preg_replace( '/\s+/', ' ', $content );

		return is_string( $content ) ? trim( $content ) : '';
	}

	/**
	 * Extract a relevant excerpt for the given search terms.
	 *
	 * @param string            $content Content.
	 * @param array<int,string> $terms   Search terms.
	 * @return string
	 */
	private function extract_relevant_excerpt( string $content, array $terms ): string {
		$content = trim( $content );

		if ( '' === $content ) {
			return '';
		}

		if ( empty( $terms ) ) {
			return $this->trim_content( $content, 420 );
		}

		$content_lc = $this->normalise_text( $content );
		$position   = null;

		foreach ( $terms as $term ) {
			$match = strpos( $content_lc, $term );

			if ( false !== $match ) {
				$position = (int) $match;
				break;
			}
		}

		if ( null === $position ) {
			return $this->trim_content( $content, 420 );
		}

		$start = max( 0, $position - 120 );
		$chunk = substr( $content, $start, 420 );
		$chunk = is_string( $chunk ) ? trim( $chunk ) : '';

		if ( $start > 0 ) {
			$chunk = '…' . ltrim( $chunk );
		}

		if ( strlen( $content ) > ( $start + strlen( $chunk ) ) ) {
			$chunk = rtrim( $chunk, " \t\n\r\0\x0B.,;:" ) . '…';
		}

		return $chunk;
	}

	/**
	 * Calculate a simple relevance score for a post.
	 *
	 * @param string            $title   Title.
	 * @param string            $content Plain content.
	 * @param array<int,string> $terms   Search terms.
	 * @return float
	 */
	private function calculate_score( string $title, string $content, array $terms ): float {
		if ( empty( $terms ) ) {
			return 1.0;
		}

		$title_lc   = $this->normalise_text( $title );
		$content_lc = $this->normalise_text( $content );
		$score      = 0.0;
		$phrase     = implode( ' ', $terms );

		if ( '' !== $phrase && false !== strpos( $title_lc, $phrase ) ) {
			$score += 20;
		}

		if ( '' !== $phrase && false !== strpos( $content_lc, $phrase ) ) {
			$score += 10;
		}

		foreach ( $terms as $term ) {
			if ( false !== strpos( $title_lc, $term ) ) {
				$score += 8;
			}

			if ( false !== strpos( $content_lc, $term ) ) {
				$score += 3;
			}
		}

		return $score;
	}

	/**
	 * Extract meaningful search terms from a question.
	 *
	 * @param string $text Raw text.
	 * @return array<int, string>
	 */
	private function extract_terms( string $text ): array {
		$text  = $this->normalise_text( $text );
		$parts = preg_split( '/\s+/', $text ) ?: array();

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( string $part ): string {
							return preg_replace( '/[^a-z0-9-]+/', '', $part ) ?: '';
						},
						$parts
					),
					static function ( string $part ): bool {
						return strlen( $part ) >= 3 && ! in_array( $part, array( 'what', 'when', 'where', 'with', 'that', 'this', 'from', 'your', 'about', 'have', 'just', 'site', 'page' ), true );
					}
				)
			)
		);
	}

	/**
	 * Get taxonomy terms for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, string>
	 */
	private function get_post_terms( \WP_Post $post ): array {
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		$terms      = array();

		foreach ( $taxonomies as $taxonomy ) {
			$post_terms = get_the_terms( $post, $taxonomy );

			if ( is_wp_error( $post_terms ) || empty( $post_terms ) ) {
				continue;
			}

			foreach ( $post_terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$terms[] = sanitize_text_field( $term->name );
			}
		}

		return array_values( array_unique( array_filter( $terms ) ) );
	}

	/**
	 * Get product-specific fields when WooCommerce is available.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function get_product_details( \WP_Post $post ): array {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product ) {
			return array();
		}

		return array_filter(
			array(
				'price'       => sanitize_text_field( trim( wp_strip_all_tags( (string) $product->get_price_html() ) ) ),
				'sku'         => sanitize_text_field( (string) $product->get_sku() ),
				'in_stock'    => $product->is_in_stock(),
				'stock_status'=> sanitize_key( (string) $product->get_stock_status() ),
			),
			static function ( $value ): bool {
				return '' !== (string) $value && null !== $value;
			}
		);
	}

	/**
	 * Normalise text for comparisons.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private function normalise_text( string $text ): string {
		$text = remove_accents( wp_strip_all_tags( $text ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = is_string( $text ) ? trim( $text ) : '';

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/**
	 * Trim text to a safe runtime length.
	 *
	 * @param string $content Content.
	 * @param int    $limit   Character limit.
	 * @return string
	 */
	private function trim_content( string $content, int $limit ): string {
		$content = trim( $content );

		if ( strlen( $content ) <= $limit ) {
			return $content;
		}

		return rtrim( substr( $content, 0, $limit - 1 ), " \t\n\r\0\x0B.,;:" ) . '…';
	}
}
