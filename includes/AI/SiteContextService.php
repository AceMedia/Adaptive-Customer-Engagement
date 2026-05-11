<?php
/**
 * Live site-context retrieval for bot runtimes.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

final class SiteContextService {
	/**
	 * Get the supported content types for live bot context.
	 *
	 * @return array<int, string>
	 */
	public function get_supported_post_types(): array {
		$post_types = Settings::get_available_site_context_post_type_names();

		return ! empty( $post_types ) ? $post_types : array( 'page', 'post' );
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
	 * @param string            $query          User query.
	 * @param int               $limit          Result limit.
	 * @param array<int,string> $post_types     Optional post-type filter.
	 * @param bool              $allow_fallback Whether to fall back to low-confidence recency results.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $query, int $limit = 5, array $post_types = array(), bool $allow_fallback = true ): array {
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

		if ( empty( $documents ) && $allow_fallback ) {
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
					__( 'Hello from %s. Ask me about the company, services, or one of the products and I will look it up from the live content.', 'adaptive-customer-engagement' ),
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
					__( 'Hello, I am the %s assistant. Ask me about the company, products, or services and I will look it up live.', 'adaptive-customer-engagement' ),
					$site['name']
				),
				'sources'    => array(),
				'confidence' => 'low',
				'fallback'   => false,
			);
		}

		$product_comparison = $this->answer_product_comparison_question( $question, $limit );

		if ( ! empty( $product_comparison ) ) {
			return $product_comparison;
		}

		$expanded_question = $this->expand_query_for_search( $question );
		$documents         = array();

		if ( $this->should_search_content_first( $question ) ) {
			$documents = $this->search( $expanded_question, $limit, $this->get_non_product_post_types() );

			if ( empty( $documents ) ) {
				$documents = $this->search( $expanded_question, $limit );
			}
		} else {
			$documents = $this->search( $expanded_question, $limit, array( 'product' ), false );

			if ( empty( $documents ) ) {
				$documents = $this->search( $expanded_question, $limit, $this->get_non_product_post_types() );
			}

			if ( empty( $documents ) ) {
				$documents = $this->search( $expanded_question, $limit );
			}
		}

		if ( empty( $documents ) ) {
			return array(
				'answer'     => __( 'I could not find a clear answer in the current company or product information. Please try naming the product, service, or topic you want to know about.', 'adaptive-customer-engagement' ),
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
			'sources'    => array_map( array( $this, 'format_source_document' ), $documents ),
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

		if ( ! in_array( $post->post_type, $this->get_active_post_types(), true ) ) {
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
				'post_type'           => $this->get_active_post_types(),
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
			'content_type' => sanitize_text_field( $type_label ),
			'source_label' => sprintf( '%s: %s', $type_label, $title ),
			'summary'      => sanitize_textarea_field( $summary ),
			'excerpt'      => sanitize_textarea_field( $excerpt ),
			'score'        => $score,
			'modified_gmt' => sanitize_text_field( (string) $post->post_modified_gmt ),
			'terms'        => $this->get_post_terms( $post ),
			'image_url'    => $this->get_document_image_url( $post ),
		);

		$commerce = $this->get_product_details( $post );

		if ( ! empty( $commerce ) ) {
			$commerce_summary = $this->build_commerce_summary( $commerce );

			if ( '' !== $commerce_summary ) {
				$summary = trim( $commerce_summary . ' ' . $summary );
				$summary = $this->trim_content( $summary, 420 );
			}

			$document['commerce'] = $commerce;
			$document['summary']  = sanitize_textarea_field( $summary );
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
			$product_text = $this->build_commerce_summary( $top['commerce'] );
			$price        = sanitize_text_field( (string) ( $top['commerce']['price'] ?? '' ) );

			if ( '' !== $price ) {
				$product_text .= ' ' . sprintf(
					/* translators: %s: price text. */
					__( 'Current price: %s.', 'adaptive-customer-engagement' ),
					$price
				);
			}

			$product_text = '' !== trim( $product_text ) ? ' ' . trim( $product_text ) : '';
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

		if ( preg_match( '/\b(contact|phone|email|address|get in touch|about|company|history|story|team|case stud(?:y|ies)|blog|news|article|post)\b/i', $query ) ) {
			$expanded .= ' about company history story team contact phone email address page blog post article case study';
		}

		if ( preg_match( '/\b(price|cost|pricing|quote)\b/i', $query ) ) {
			$expanded .= ' price pricing quote product';
		}

		if ( preg_match( '/\b(largest|biggest|smallest|capacity|size|litre|litres)\b/i', $query ) ) {
			$expanded .= ' bin bins container containers litre litres capacity taylor metal bins';
		}

		return $expanded;
	}

	/**
	 * Get the supported non-product content types.
	 *
	 * @return array<int, string>
	 */
	private function get_non_product_post_types(): array {
		return array_values(
			array_filter(
				$this->get_supported_post_types(),
				static function ( string $post_type ): bool {
					return 'product' !== $post_type;
				}
			)
		);
	}

	/**
	 * Decide whether a question should search company/content sources before products.
	 *
	 * @param string $question User question.
	 * @return bool
	 */
	private function should_search_content_first( string $question ): bool {
		$question = $this->normalise_text( $question );

		if ( '' === $question ) {
			return false;
		}

		$is_content_question = (bool) preg_match(
			'/\b(about|about us|company|history|story|team|contact|phone|email|address|get in touch|location|locations|blog|news|article|articles|post|posts|case stud(?:y|ies)|project|projects|portfolio|faq|support|returns?|delivery|shipping|warranty)\b/',
			$question
		);
		$is_product_question = (bool) preg_match(
			'/\b(product|products|bin|bins|container|containers|capacity|size|sizes|litre|litres|price|prices|cost|quote|buy|basket|cart|model|models|range|ranges|wheelie)\b/',
			$question
		);

		return $is_content_question && ! $is_product_question;
	}

	/**
	 * Sanitise a post-type filter.
	 *
	 * @param array<int,string> $post_types Raw post types.
	 * @return array<int, string>
	 */
	private function sanitise_post_types( array $post_types ): array {
		$supported = $this->get_active_post_types();
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
	 * Get the active post types selected for live chat context.
	 *
	 * @return array<int, string>
	 */
	private function get_active_post_types(): array {
		$supported = $this->get_supported_post_types();
		$settings  = Settings::get();
		$ai_agent  = isset( $settings['ai_agent'] ) && is_array( $settings['ai_agent'] ) ? $settings['ai_agent'] : array();
		$selected  = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					is_array( $ai_agent['live_context_post_types'] ?? null ) ? $ai_agent['live_context_post_types'] : array()
				),
				static function ( string $post_type ) use ( $supported ): bool {
					return in_array( $post_type, $supported, true );
				}
			)
		);

		return ! empty( $selected ) ? $selected : $supported;
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
				$attributes        = $this->get_product_attributes( $product, $post->ID );

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

				foreach ( $attributes as $label => $value ) {
					$parts[] = sprintf( '%s: %s', sanitize_text_field( (string) $label ), sanitize_text_field( (string) $value ) );
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

		$categories = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) );
		$categories = is_wp_error( $categories ) ? array() : $categories;
		$categories = array_values(
			array_filter(
				array_map(
					'sanitize_text_field',
					is_array( $categories ) ? $categories : array()
				)
			)
		);
		$attributes = $this->get_product_attributes( $product, $post->ID );
		$product_permalink = get_permalink( $post ) ?: '';
		$is_variable       = $product->is_type( 'variable' );
		$is_simple         = $product->is_type( 'simple' );
		$variation_count   = $is_variable && method_exists( $product, 'get_children' ) ? count( $product->get_children() ) : 0;
		$price_html        = sanitize_text_field( trim( wp_strip_all_tags( (string) $product->get_price_html() ) ) );
		$can_add_to_cart   = $is_simple && ! $is_variable && $product->is_purchasable() && $product->is_in_stock() && '' !== $product_permalink;
		$add_to_cart_url   = $can_add_to_cart ? add_query_arg( 'add-to-cart', (string) $product->get_id(), $product_permalink ) : '';
		$measurements = array_merge(
			array_values( $attributes ),
			array(
				(string) $post->post_title,
				(string) $post->post_excerpt,
				(string) $post->post_content,
			)
		);
		$capacity_litres = $this->extract_measurement_value( $measurements, '/(\d+(?:\.\d+)?)\s*(?:litres?|ltr|l)\b/i' );
		$weight_kg       = $this->extract_measurement_value( $measurements, '/(\d+(?:\.\d+)?)\s*kg\b/i' );
		$product_kind    = $this->classify_product_kind( (string) $post->post_title, $categories );

		return array_filter(
			array(
				'price'            => $price_html,
				'sku'              => sanitize_text_field( (string) $product->get_sku() ),
				'in_stock'         => $product->is_in_stock(),
				'stock_status'     => sanitize_key( (string) $product->get_stock_status() ),
				'categories'       => $categories,
				'attributes'       => $attributes,
				'capacity_litres'  => $capacity_litres > 0 ? $capacity_litres : null,
				'empty_weight_kg'  => $weight_kg > 0 ? $weight_kg : null,
				'product_kind'     => $product_kind,
				'product_type'     => sanitize_key( $product->get_type() ),
				'variation_count'  => $variation_count,
				'can_add_to_cart'  => $can_add_to_cart,
				'add_to_cart_url'  => esc_url_raw( $add_to_cart_url ),
				'view_url'         => esc_url_raw( $product_permalink ),
			),
			static function ( $value ): bool {
				if ( is_array( $value ) ) {
					return ! empty( $value );
				}

				if ( is_bool( $value ) ) {
					return true;
				}

				return '' !== (string) $value && null !== $value;
			}
		);
	}

	/**
	 * Build a compact structured product summary.
	 *
	 * @param array<string, mixed> $commerce Product details.
	 * @return string
	 */
	private function build_commerce_summary( array $commerce ): string {
		$parts = array();

		if ( ! empty( $commerce['capacity_litres'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: product capacity in litres. */
				__( 'Capacity: %s litres.', 'adaptive-customer-engagement' ),
				number_format_i18n( (float) $commerce['capacity_litres'], 0 )
			);
		}

		if ( ! empty( $commerce['empty_weight_kg'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: empty weight in kilograms. */
				__( 'Empty weight: %skg.', 'adaptive-customer-engagement' ),
				number_format_i18n( (float) $commerce['empty_weight_kg'], 0 )
			);
		}

		if ( ! empty( $commerce['categories'] ) && is_array( $commerce['categories'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated product categories. */
				__( 'Categories: %s.', 'adaptive-customer-engagement' ),
				implode( ', ', array_slice( array_map( 'sanitize_text_field', $commerce['categories'] ), 0, 3 ) )
			);
		}

		return trim( implode( ' ', array_filter( $parts ) ) );
	}

	/**
	 * Build a structured answer for product comparison questions.
	 *
	 * @param string $question User question.
	 * @param int    $limit    Source document limit.
	 * @return array<string, mixed>
	 */
	private function answer_product_comparison_question( string $question, int $limit ): array {
		$comparison = $this->detect_product_comparison( $question );

		if ( empty( $comparison ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			)
		);

		if ( empty( $posts ) ) {
			return array();
		}

		$focus_terms = $this->extract_product_focus_terms( $question );
		$candidates  = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$document = $this->build_document( $post, array(), false );

			if ( empty( $document['commerce'] ) || ! is_array( $document['commerce'] ) ) {
				continue;
			}

			if ( ! $this->is_primary_bin_product( $document ) ) {
				continue;
			}

			$metric_value = (float) ( $document['commerce'][ $comparison['metric'] ] ?? 0 );

			if ( $metric_value <= 0 ) {
				continue;
			}

			if ( ! $this->matches_product_focus_terms( $document, $focus_terms ) ) {
				continue;
			}

			$document['comparison_metric_value'] = $metric_value;
			$candidates[]                        = $document;
		}

		if ( empty( $candidates ) ) {
			return array();
		}

		usort(
			$candidates,
			static function ( array $left, array $right ) use ( $comparison ): int {
				$left_value  = (float) ( $left['comparison_metric_value'] ?? 0 );
				$right_value = (float) ( $right['comparison_metric_value'] ?? 0 );

				if ( $left_value === $right_value ) {
					return strcmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) );
				}

				return 'asc' === $comparison['direction'] ? ( $left_value <=> $right_value ) : ( $right_value <=> $left_value );
			}
		);

		$winner      = $candidates[0];
		$winner_name = sanitize_text_field( (string) ( $winner['title'] ?? '' ) );
		$winner_value = (float) ( $winner['comparison_metric_value'] ?? 0 );
		$metric_label = 'capacity_litres' === $comparison['metric'] ? __( 'litres', 'adaptive-customer-engagement' ) : __( 'kg', 'adaptive-customer-engagement' );
		$answer      = sprintf(
			/* translators: 1: ranking word, 2: product title, 3: measurement value, 4: measurement unit. */
			__( 'The %1$s bin I can find in the live catalogue is %2$s at %3$s %4$s.', 'adaptive-customer-engagement' ),
			sanitize_text_field( (string) $comparison['label'] ),
			$winner_name,
			number_format_i18n( $winner_value, 0 ),
			$metric_label
		);

		$runner_ups = array();

		foreach ( array_slice( $candidates, 1, max( 0, $limit - 1 ) ) as $candidate ) {
			$runner_ups[] = sprintf(
				'%s (%s %s)',
				sanitize_text_field( (string) ( $candidate['title'] ?? '' ) ),
				number_format_i18n( (float) ( $candidate['comparison_metric_value'] ?? 0 ), 0 ),
				$metric_label
			);
		}

		if ( ! empty( $runner_ups ) ) {
			$answer .= ' ' . sprintf(
				/* translators: %s: comma-separated runner-up products. */
				__( 'The next closest options are %s.', 'adaptive-customer-engagement' ),
				implode( ', ', $runner_ups )
			);
		}

		$winner_excerpt = sanitize_textarea_field( (string) ( $winner['excerpt'] ?? '' ) );

		if ( '' !== $winner_excerpt ) {
			$answer .= ' ' . sprintf(
				/* translators: %s: excerpt text. */
				__( 'Its listing says: %s', 'adaptive-customer-engagement' ),
				$winner_excerpt
			);
		}

		return array(
			'answer'     => sanitize_textarea_field( trim( $answer ) ),
			'sources'    => array_map( array( $this, 'format_source_document' ), array_slice( $candidates, 0, $limit ) ),
			'confidence' => 'high',
			'fallback'   => false,
		);
	}

	/**
	 * Build a frontend-safe source payload from a document.
	 *
	 * @param array<string, mixed> $document Raw document.
	 * @return array<string, mixed>
	 */
	private function format_source_document( array $document ): array {
		$commerce = is_array( $document['commerce'] ?? null ) ? $document['commerce'] : array();

		return array(
			'id'           => (int) ( $document['id'] ?? 0 ),
			'title'        => sanitize_text_field( (string) ( $document['title'] ?? '' ) ),
			'url'          => esc_url_raw( (string) ( $document['url'] ?? '' ) ),
			'source_type'  => sanitize_key( (string) ( $document['source_type'] ?? '' ) ),
			'content_type' => sanitize_text_field( (string) ( $document['content_type'] ?? '' ) ),
			'source_label' => sanitize_text_field( (string) ( $document['source_label'] ?? '' ) ),
			'summary'      => sanitize_textarea_field( (string) ( $document['summary'] ?? '' ) ),
			'image_url'    => esc_url_raw( (string) ( $document['image_url'] ?? '' ) ),
			'commerce'     => array(
				'price'           => sanitize_text_field( (string) ( $commerce['price'] ?? '' ) ),
				'variation_count' => absint( $commerce['variation_count'] ?? 0 ),
				'can_add_to_cart' => ! empty( $commerce['can_add_to_cart'] ),
				'add_to_cart_url' => esc_url_raw( (string) ( $commerce['add_to_cart_url'] ?? '' ) ),
				'view_url'        => esc_url_raw( (string) ( $commerce['view_url'] ?? $document['url'] ?? '' ) ),
			),
		);
	}

	/**
	 * Detect whether a question is asking for a sortable product comparison.
	 *
	 * @param string $question User question.
	 * @return array<string, string>
	 */
	private function detect_product_comparison( string $question ): array {
		$question = $this->normalise_text( $question );

		if ( ! preg_match( '/\b(bin|bins|container|containers|product|products|capacity|size|litre|litres)\b/', $question ) ) {
			return array();
		}

		if ( preg_match( '/\b(largest|biggest|max(?:imum)?)\b/', $question ) ) {
			return array(
				'metric'    => 'capacity_litres',
				'direction' => 'desc',
				'label'     => __( 'largest', 'adaptive-customer-engagement' ),
			);
		}

		if ( preg_match( '/\b(smallest|min(?:imum)?)\b/', $question ) ) {
			return array(
				'metric'    => 'capacity_litres',
				'direction' => 'asc',
				'label'     => __( 'smallest', 'adaptive-customer-engagement' ),
			);
		}

		if ( preg_match( '/\b(heaviest)\b/', $question ) ) {
			return array(
				'metric'    => 'empty_weight_kg',
				'direction' => 'desc',
				'label'     => __( 'heaviest', 'adaptive-customer-engagement' ),
			);
		}

		if ( preg_match( '/\b(lightest)\b/', $question ) ) {
			return array(
				'metric'    => 'empty_weight_kg',
				'direction' => 'asc',
				'label'     => __( 'lightest', 'adaptive-customer-engagement' ),
			);
		}

		return array();
	}

	/**
	 * Extract non-generic focus terms from a product comparison question.
	 *
	 * @param string $question User question.
	 * @return array<int, string>
	 */
	private function extract_product_focus_terms( string $question ): array {
		$ignored = array(
			'largest',
			'biggest',
			'smallest',
			'heaviest',
			'lightest',
			'maximum',
			'minimum',
			'what',
			'which',
			'bin',
			'bins',
			'container',
			'containers',
			'product',
			'products',
			'capacity',
			'capacities',
			'size',
			'sizes',
			'litre',
			'litres',
		);

		return array_values(
			array_filter(
				$this->extract_terms( $question ),
				static function ( string $term ) use ( $ignored ): bool {
					return ! in_array( $term, $ignored, true );
				}
			)
		);
	}

	/**
	 * Determine whether a product document represents a primary bin/container.
	 *
	 * @param array<string, mixed> $document Product document.
	 * @return bool
	 */
	private function is_primary_bin_product( array $document ): bool {
		if ( 'product' !== sanitize_key( (string) ( $document['source_type'] ?? '' ) ) ) {
			return false;
		}

		if ( empty( $document['commerce'] ) || ! is_array( $document['commerce'] ) ) {
			return false;
		}

		return 'container' === sanitize_key( (string) ( $document['commerce']['product_kind'] ?? '' ) );
	}

	/**
	 * Check whether a product document matches the focused comparison terms.
	 *
	 * @param array<string, mixed> $document Product document.
	 * @param array<int, string>   $terms    Focus terms.
	 * @return bool
	 */
	private function matches_product_focus_terms( array $document, array $terms ): bool {
		if ( empty( $terms ) ) {
			return true;
		}

		$parts   = array(
			(string) ( $document['title'] ?? '' ),
			(string) ( $document['summary'] ?? '' ),
			(string) ( $document['excerpt'] ?? '' ),
		);
		$commerce = $document['commerce'] ?? array();

		if ( is_array( $commerce ) ) {
			if ( ! empty( $commerce['categories'] ) && is_array( $commerce['categories'] ) ) {
				$parts[] = implode( ' ', array_map( 'sanitize_text_field', $commerce['categories'] ) );
			}

			if ( ! empty( $commerce['attributes'] ) && is_array( $commerce['attributes'] ) ) {
				foreach ( $commerce['attributes'] as $label => $value ) {
					$parts[] = sanitize_text_field( (string) $label ) . ' ' . sanitize_text_field( (string) $value );
				}
			}
		}

		$haystack = $this->normalise_text( implode( ' ', array_filter( $parts ) ) );
		$matches  = 0;

		foreach ( $terms as $term ) {
			if ( '' !== $term && false !== strpos( $haystack, $term ) ) {
				++$matches;
			}
		}

		return $matches >= min( count( $terms ), 2 );
	}

	/**
	 * Classify the overall product kind from its title and categories.
	 *
	 * @param string             $title      Product title.
	 * @param array<int, string> $categories Product categories.
	 * @return string
	 */
	private function classify_product_kind( string $title, array $categories ): string {
		$haystack = $this->normalise_text( $title . ' ' . implode( ' ', $categories ) );

		if ( preg_match( '/\b(range)\b/', $haystack ) ) {
			return 'collection';
		}

		if ( preg_match( '/\b(spare|spares|artwork|lid|castor|lock|drainplug|din|part|parts|component|components|body|frame|housing)\b/', $haystack ) ) {
			return 'accessory';
		}

		if ( false !== strpos( $haystack, 'taylor metal bins' ) || preg_match( '/\b(bin|container|continental)\b/', $haystack ) ) {
			return 'container';
		}

		return 'product';
	}

	/**
	 * Extract readable WooCommerce product attributes.
	 *
	 * @param object $product WooCommerce product object.
	 * @param int    $post_id Product post ID.
	 * @return array<string, string>
	 */
	private function get_product_attributes( $product, int $post_id ): array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}

		$attributes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
				continue;
			}

			$name  = (string) $attribute->get_name();
			$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $name, $product ) : $name;
			$label = sanitize_text_field( (string) $label );
			$values = array();

			if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && function_exists( 'wc_get_product_terms' ) ) {
				$values = wc_get_product_terms( $post_id, $name, array( 'fields' => 'names' ) );
			} elseif ( method_exists( $attribute, 'get_options' ) ) {
				$values = $attribute->get_options();
			}

			$values = array_values(
				array_filter(
					array_map(
						static function ( $value ): string {
							return sanitize_text_field( wp_strip_all_tags( (string) $value ) );
						},
						is_array( $values ) ? $values : array()
					)
				)
			);

			if ( '' === $label || empty( $values ) ) {
				continue;
			}

			$attributes[ $label ] = implode( ', ', $values );
		}

		return $attributes;
	}

	/**
	 * Get the main image URL for a document.
	 *
	 * @param \WP_Post $post Document post.
	 * @return string
	 */
	private function get_document_image_url( \WP_Post $post ): string {
		$image_url = get_the_post_thumbnail_url( $post, 'medium' );

		return is_string( $image_url ) ? esc_url_raw( $image_url ) : '';
	}

	/**
	 * Extract a numeric measurement from a list of product strings.
	 *
	 * @param array<int, string> $values  Candidate strings.
	 * @param string             $pattern Regex pattern with the numeric capture in group 1.
	 * @return float
	 */
	private function extract_measurement_value( array $values, string $pattern ): float {
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			if ( preg_match( $pattern, wp_strip_all_tags( $value ), $matches ) && isset( $matches[1] ) ) {
				return (float) $matches[1];
			}
		}

		return 0.0;
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
