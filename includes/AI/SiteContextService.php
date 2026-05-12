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

		$shipping_answer = $this->answer_shipping_question( $question, $limit );

		if ( ! empty( $shipping_answer ) ) {
			return $shipping_answer;
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
		$sources    = $this->select_response_sources( $question, $documents, $limit );
		$sources    = $this->enrich_sources_with_product_documents( $sources, max( $limit, 5 ) );

		return array(
			'answer'     => $answer,
			'sources'    => array_map( array( $this, 'format_source_document' ), $sources ),
			'confidence' => $confidence,
			'fallback'   => false,
		);
	}

	/**
	 * Infer relevant product-led sources from arbitrary text.
	 *
	 * Useful for manual operator replies where we still want product cards and
	 * linked PDFs to appear if a product has been mentioned explicitly.
	 *
	 * @param string $text  Free text.
	 * @param int    $limit Maximum source count.
	 * @return array<int, array<string, mixed>>
	 */
	public function infer_message_sources( string $text, int $limit = 5 ): array {
		$text  = $this->normalise_text( $text );
		$limit = max( 1, min( 8, $limit ) );

		if ( '' === $text ) {
			return array();
		}

		$product_documents = $this->search( $this->expand_query_for_search( $text ), min( 3, $limit ), array( 'product' ), false );
		$product_documents = array_values(
			array_filter(
				$product_documents,
				static function ( array $document ): bool {
					return 'product' === sanitize_key( (string) ( $document['source_type'] ?? '' ) ) && (float) ( $document['score'] ?? 0 ) > 0;
				}
			)
		);

		if ( empty( $product_documents ) ) {
			return array();
		}

		return array_map(
			array( $this, 'format_source_document' ),
			$this->enrich_sources_with_product_documents( array_slice( $product_documents, 0, min( 2, $limit ) ), $limit )
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

		if ( count( $documents ) > 1 && $this->should_mention_related_documents( $question, $documents ) ) {
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
	 * Select which sources should be shown back to the frontend.
	 *
	 * Products should stay visible for product-intent questions, while content links
	 * should only appear when they add useful further-reading context.
	 *
	 * @param string                            $question  User question.
	 * @param array<int, array<string, mixed>> $documents Matching documents.
	 * @param int                               $limit     Maximum source count.
	 * @return array<int, array<string, mixed>>
	 */
	private function select_response_sources( string $question, array $documents, int $limit ): array {
		$limit               = max( 1, min( 8, $limit ) );
		$is_product_question = $this->is_product_question( $question );

		if ( $is_product_question ) {
			$product_documents = array_values(
				array_filter(
					$documents,
					static function ( array $document ): bool {
						return 'product' === sanitize_key( (string) ( $document['source_type'] ?? '' ) );
					}
				)
			);

			return array_slice( ! empty( $product_documents ) ? $product_documents : $documents, 0, $limit );
		}

		if ( ! $this->should_offer_further_reading( $question, $documents ) ) {
			return array();
		}

		$content_documents = array_values(
			array_filter(
				$documents,
				static function ( array $document ): bool {
					return 'product' !== sanitize_key( (string) ( $document['source_type'] ?? '' ) );
				}
			)
		);

		return array_slice( ! empty( $content_documents ) ? $content_documents : $documents, 0, min( 3, $limit ) );
	}

	/**
	 * Decide whether related-document titles should be mentioned in the answer body.
	 *
	 * @param string                            $question  User question.
	 * @param array<int, array<string, mixed>> $documents Matching documents.
	 * @return bool
	 */
	private function should_mention_related_documents( string $question, array $documents ): bool {
		if ( $this->is_product_question( $question ) ) {
			return true;
		}

		return $this->should_offer_further_reading( $question, $documents );
	}

	/**
	 * Decide whether non-product links add useful further-reading context.
	 *
	 * @param string                            $question  User question.
	 * @param array<int, array<string, mixed>> $documents Matching documents.
	 * @return bool
	 */
	private function should_offer_further_reading( string $question, array $documents ): bool {
		$question = $this->normalise_text( $question );

		if ( '' === $question || empty( $documents ) ) {
			return false;
		}

		if ( preg_match( '/\b(read|reading|learn more|more detail|more details|more info|more information|further|page|pages|post|posts|article|articles|blog|case stud(?:y|ies)|news|link|links)\b/', $question ) ) {
			return true;
		}

		$non_product_documents = array_values(
			array_filter(
				$documents,
				static function ( array $document ): bool {
					return 'product' !== sanitize_key( (string) ( $document['source_type'] ?? '' ) );
				}
			)
		);

		if ( count( $non_product_documents ) < 2 ) {
			return false;
		}

		$top_score    = (float) ( $non_product_documents[0]['score'] ?? 0 );
		$second_score = (float) ( $non_product_documents[1]['score'] ?? 0 );

		return $top_score >= 8 && $second_score >= 8;
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
		$is_product_question = $this->is_product_question( $question );

		return $is_content_question && ! $is_product_question;
	}

	/**
	 * Decide whether the question is clearly product-led.
	 *
	 * @param string $question User question.
	 * @return bool
	 */
	private function is_product_question( string $question ): bool {
		return (bool) preg_match(
			'/\b(product|products|bin|bins|container|containers|capacity|size|sizes|litre|litres|price|prices|cost|quote|buy|basket|cart|model|models|range|ranges|wheelie)\b/',
			$this->normalise_text( $question )
		);
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
		$direct_weight     = method_exists( $product, 'get_weight' ) ? (float) $product->get_weight() : 0.0;
		$length_cm         = method_exists( $product, 'get_length' ) ? (float) $product->get_length() : 0.0;
		$width_cm          = method_exists( $product, 'get_width' ) ? (float) $product->get_width() : 0.0;
		$height_cm         = method_exists( $product, 'get_height' ) ? (float) $product->get_height() : 0.0;
		$stock_quantity    = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
		$shipping_class    = method_exists( $product, 'get_shipping_class' ) ? (string) $product->get_shipping_class() : '';
		$needs_shipping    = method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : ! ( method_exists( $product, 'is_virtual' ) && $product->is_virtual() );
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
		$weight_kg       = $direct_weight > 0 ? $direct_weight : $this->extract_measurement_value( $measurements, '/(\d+(?:\.\d+)?)\s*kg\b/i' );
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
				'dimensions_cm'    => array_filter(
					array(
						'length' => $length_cm > 0 ? $length_cm : null,
						'width'  => $width_cm > 0 ? $width_cm : null,
						'height' => $height_cm > 0 ? $height_cm : null,
					),
					static function ( $value ): bool {
						return null !== $value;
					}
				),
				'needs_shipping'   => $needs_shipping,
				'stock_quantity'   => null !== $stock_quantity ? (int) $stock_quantity : null,
				'shipping_class'   => sanitize_text_field( $shipping_class ),
				'tax_status'       => method_exists( $product, 'get_tax_status' ) ? sanitize_key( (string) $product->get_tax_status() ) : '',
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

		if ( ! empty( $commerce['dimensions_cm'] ) && is_array( $commerce['dimensions_cm'] ) ) {
			$dimension_parts = array();

			foreach ( array( 'length' => 'L', 'width' => 'W', 'height' => 'H' ) as $key => $label ) {
				if ( empty( $commerce['dimensions_cm'][ $key ] ) ) {
					continue;
				}

				$dimension_parts[] = $label . ' ' . number_format_i18n( (float) $commerce['dimensions_cm'][ $key ], 0 ) . 'cm';
			}

			if ( ! empty( $dimension_parts ) ) {
				$parts[] = sprintf(
					/* translators: %s: compact dimensions text. */
					__( 'Dimensions: %s.', 'adaptive-customer-engagement' ),
					implode( ', ', $dimension_parts )
				);
			}
		}

		if ( ! empty( $commerce['sku'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: product SKU. */
				__( 'SKU: %s.', 'adaptive-customer-engagement' ),
				sanitize_text_field( (string) $commerce['sku'] )
			);
		}

		if ( ! empty( $commerce['variation_count'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: variation count. */
				_n( '%s variation available.', '%s variations available.', (int) $commerce['variation_count'], 'adaptive-customer-engagement' ),
				number_format_i18n( (int) $commerce['variation_count'] )
			);
		}

		if ( ! empty( $commerce['shipping_class'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: shipping class label. */
				__( 'Shipping class: %s.', 'adaptive-customer-engagement' ),
				sanitize_text_field( (string) $commerce['shipping_class'] )
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
	 * Build a structured answer for shipping and delivery questions.
	 *
	 * @param string $question User question.
	 * @param int    $limit    Source document limit.
	 * @return array<string, mixed>
	 */
	private function answer_shipping_question( string $question, int $limit ): array {
		if ( ! $this->is_shipping_question( $question ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}

		$product_query     = $this->extract_product_query_from_shipping_question( $question );
		$product_documents = array_values(
			array_filter(
				$this->search( $this->expand_query_for_search( $product_query ), max( 1, min( 3, $limit ) ), array( 'product' ), false ),
				static function ( array $document ): bool {
					return 'product' === sanitize_key( (string) ( $document['source_type'] ?? '' ) ) && (float) ( $document['score'] ?? 0 ) > 0;
				}
			)
		);

		if ( ! $this->should_match_product_for_shipping_question( $question, $product_query, $product_documents ) ) {
			$product_documents = array();
		}

		$product_document  = ! empty( $product_documents ) ? $product_documents[0] : array();
		$shipping_context  = $this->get_shipping_context_for_question( $question, $product_document );

		if ( empty( $shipping_context ) ) {
			return array(
				'answer'     => __( 'I can see the site has live WooCommerce shipping methods configured, but I need the delivery postcode and, ideally, the product to work out the matching shipping zone and estimate the current delivery charge.', 'adaptive-customer-engagement' ),
				'sources'    => array(),
				'confidence' => 'medium',
				'fallback'   => false,
			);
		}

		$sources = ! empty( $product_documents )
			? $this->enrich_sources_with_product_documents( array_slice( $product_documents, 0, min( 2, $limit ) ), max( $limit, 5 ) )
			: array();

		return array(
			'answer'     => $this->build_shipping_answer_text( $shipping_context, $product_document ),
			'sources'    => array_map( array( $this, 'format_source_document' ), $sources ),
			'confidence' => ! empty( $shipping_context['zone_name'] ) ? 'high' : 'medium',
			'fallback'   => false,
		);
	}

	/**
	 * Remove destination/shipping wording so product lookup can stay focused.
	 *
	 * @param string $question Raw shipping question.
	 * @return string
	 */
	private function extract_product_query_from_shipping_question( string $question ): string {
		$query = wp_strip_all_tags( $question );
		$query = preg_replace( '/\b([A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}|[A-Z]{1,2}\d[A-Z\d]?)\b/i', ' ', $query ) ?: $query;
		$query = preg_replace( '/\b(ship|shipping|delivery|deliver|delivers|delivered|postcode|post code|estimate|cost|charge|charges|courier|freight|to|for|my|postcode)\b/i', ' ', $query ) ?: $query;

		foreach ( array_keys( $this->get_shipping_city_postcode_aliases() ) as $city ) {
			$query = preg_replace( '/\b' . preg_quote( $city, '/' ) . '\b/i', ' ', $query ) ?: $query;
		}

		$query = preg_replace( '/\s+/', ' ', $query ) ?: $query;
		$query = trim( $query );

		return '' !== $query ? $query : $question;
	}

	/**
	 * Decide whether a shipping question names a product clearly enough to attach one.
	 *
	 * @param string                            $question          Raw visitor question.
	 * @param string                            $product_query     Shipping-focused product query.
	 * @param array<int, array<string, mixed>> $product_documents Matched product documents.
	 * @return bool
	 */
	private function should_match_product_for_shipping_question( string $question, string $product_query, array $product_documents ): bool {
		$question      = $this->normalise_text( $question );
		$product_query = $this->normalise_text( $product_query );

		if ( '' === $question || '' === $product_query || empty( $product_documents ) ) {
			return false;
		}

		if ( ! preg_match( '/\b(product|products|bin|bins|container|containers|lid|lids|model|models|range|ranges|sku|part|parts|wheelie|body|housing|castor|lock|drainplug|din)\b/', $question ) ) {
			return false;
		}

		$top_document = $product_documents[0];
		$top_title    = $this->normalise_text( (string) ( $top_document['title'] ?? '' ) );
		$top_score    = (float) ( $top_document['score'] ?? 0 );

		if ( '' === $top_title || $top_score < 18 ) {
			return false;
		}

		if ( false !== strpos( $question, $top_title ) ) {
			return true;
		}

		$query_terms = $this->extract_shipping_product_reference_terms( $product_query );
		$title_terms = $this->extract_shipping_product_reference_terms( $top_title );

		if ( empty( $query_terms ) || empty( $title_terms ) ) {
			return false;
		}

		return count( array_intersect( $query_terms, $title_terms ) ) >= min( 2, count( $title_terms ) );
	}

	/**
	 * Extract the meaningful product-reference terms from a shipping question.
	 *
	 * @param string $text Input text.
	 * @return array<int, string>
	 */
	private function extract_shipping_product_reference_terms( string $text ): array {
		$ignored = array(
			'ship',
			'shipping',
			'delivery',
			'deliver',
			'delivers',
			'delivered',
			'cost',
			'costs',
			'charge',
			'charges',
			'quote',
			'quotes',
			'postcode',
			'postcodes',
			'post',
			'code',
			'does',
			'do',
			'you',
			'your',
			'how',
			'much',
			'what',
			'which',
			'for',
			'from',
			'with',
			'that',
			'this',
			'these',
			'those',
			'into',
			'onto',
			'area',
			'current',
			'basket',
			'cart',
			'egbert',
			'sheffield',
			'leeds',
			'uk',
			'gb',
		);

		return array_values(
			array_filter(
				$this->extract_terms( $text ),
				static function ( string $term ) use ( $ignored ): bool {
					if ( in_array( $term, $ignored, true ) ) {
						return false;
					}

					if ( preg_match( '/^\d+$/', $term ) ) {
						return false;
					}

					if ( preg_match( '/^\d+(l|kg)$/', $term ) ) {
						return true;
					}

					return strlen( $term ) >= 3;
				}
			)
		);
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
				'sku'             => sanitize_text_field( (string) ( $commerce['sku'] ?? '' ) ),
				'stock_status'    => sanitize_key( (string) ( $commerce['stock_status'] ?? '' ) ),
				'stock_quantity'  => isset( $commerce['stock_quantity'] ) ? (int) $commerce['stock_quantity'] : null,
				'empty_weight_kg' => ! empty( $commerce['empty_weight_kg'] ) ? (float) $commerce['empty_weight_kg'] : null,
				'dimensions_cm'   => ! empty( $commerce['dimensions_cm'] ) && is_array( $commerce['dimensions_cm'] ) ? array(
					'length' => ! empty( $commerce['dimensions_cm']['length'] ) ? (float) $commerce['dimensions_cm']['length'] : null,
					'width'  => ! empty( $commerce['dimensions_cm']['width'] ) ? (float) $commerce['dimensions_cm']['width'] : null,
					'height' => ! empty( $commerce['dimensions_cm']['height'] ) ? (float) $commerce['dimensions_cm']['height'] : null,
				) : array(),
				'needs_shipping'  => isset( $commerce['needs_shipping'] ) ? ! empty( $commerce['needs_shipping'] ) : null,
				'shipping_class'  => sanitize_text_field( (string) ( $commerce['shipping_class'] ?? '' ) ),
				'variation_count' => absint( $commerce['variation_count'] ?? 0 ),
				'can_add_to_cart' => ! empty( $commerce['can_add_to_cart'] ),
				'add_to_cart_url' => esc_url_raw( (string) ( $commerce['add_to_cart_url'] ?? '' ) ),
				'view_url'        => esc_url_raw( (string) ( $commerce['view_url'] ?? $document['url'] ?? '' ) ),
			),
		);
	}

	/**
	 * Detect whether the current question is about shipping or delivery.
	 *
	 * @param string $question User question.
	 * @return bool
	 */
	private function is_shipping_question( string $question ): bool {
		return (bool) preg_match(
			'/\b(ship|shipping|delivery|deliver|delivers|delivered|postage|courier|freight|postcode|post code|shipping cost|delivery cost|estimate shipping|delivery charge)\b/i',
			$question
		);
	}

	/**
	 * Build a live shipping context snapshot from WooCommerce/Flexible Shipping.
	 *
	 * @param string               $question         User question.
	 * @param array<string, mixed> $product_document Matched product document.
	 * @return array<string, mixed>
	 */
	private function get_shipping_context_for_question( string $question, array $product_document ): array {
		$destination = $this->extract_shipping_destination( $question );
		$zone_data   = $this->find_shipping_zone_for_destination( $destination );

		if ( empty( $zone_data ) ) {
			return array(
				'destination' => $destination,
				'zone_name'   => '',
				'methods'     => array(),
				'postcodes'   => array(),
				'estimate'    => array(),
			);
		}

		$quote_method = $this->get_quoteable_shipping_method( $zone_data['methods'] ?? array() );
		$estimate     = array();

		if ( ! empty( $quote_method ) && ! empty( $product_document['commerce'] ) && is_array( $product_document['commerce'] ) ) {
			$estimate = $this->estimate_shipping_cost_for_product( $quote_method, $product_document['commerce'] );
		}

		$zone_data['destination'] = $destination;
		$zone_data['estimate']    = $estimate;

		return $zone_data;
	}

	/**
	 * Build a human-readable shipping answer from live WooCommerce data.
	 *
	 * @param array<string, mixed> $shipping_context Shipping context.
	 * @param array<string, mixed> $product_document Product document.
	 * @return string
	 */
	private function build_shipping_answer_text( array $shipping_context, array $product_document ): string {
		$destination      = is_array( $shipping_context['destination'] ?? null ) ? $shipping_context['destination'] : array();
		$destination_type = sanitize_key( (string) ( $destination['type'] ?? '' ) );
		$destination_name = sanitize_text_field( (string) ( $destination['label'] ?? __( 'that area', 'adaptive-customer-engagement' ) ) );
		$zone_name        = sanitize_text_field( (string) ( $shipping_context['zone_name'] ?? '' ) );
		$estimate         = is_array( $shipping_context['estimate'] ?? null ) ? $shipping_context['estimate'] : array();
		$product_name     = sanitize_text_field( (string) ( $product_document['title'] ?? '' ) );
		$product_has_data = ! empty( $product_document['commerce'] ) && is_array( $product_document['commerce'] );
		$needs_shipping   = ! empty( $product_document['commerce']['needs_shipping'] ) || ! $product_has_data;
		$zone_fragment    = '' !== $zone_name
			? sprintf(
				/* translators: %s: shipping zone name. */
				__( 'the %s shipping zone', 'adaptive-customer-engagement' ),
				$zone_name
			)
			: __( 'the configured shipping setup', 'adaptive-customer-engagement' );
		$location_fragment = 'city' === $destination_type
			? sprintf(
				/* translators: %s: destination city. */
				__( '%s-area postcodes', 'adaptive-customer-engagement' ),
				$destination_name
			)
			: $destination_name;
		$match_verb = 'postcode' === $destination_type ? __( 'matches', 'adaptive-customer-engagement' ) : __( 'match', 'adaptive-customer-engagement' );

		if ( empty( $shipping_context['methods'] ) ) {
			return __( 'I could not find a live shipping method for that destination in the current WooCommerce setup. If you send the delivery postcode I can try a more exact check.', 'adaptive-customer-engagement' );
		}

		if ( $product_has_data && ! $needs_shipping ) {
			return sprintf(
				/* translators: 1: product title, 2: zone fragment. */
				__( '%1$s does not appear to need physical shipping in the current WooCommerce data, so a delivery estimate is not needed for %2$s.', 'adaptive-customer-engagement' ),
				$product_name,
				$zone_fragment
			);
		}

		if ( ! empty( $estimate['cost_label'] ) && ! empty( $estimate['weight_kg'] ) ) {
			return sprintf(
				/* translators: 1: destination, 2: zone name, 3: product title, 4: shipping cost, 5: weight in kg. */
				__( 'Yes — %1$s %2$s %3$s. Based on the current WooCommerce shipping table, the estimated delivery charge for %4$s is %5$s at roughly %6$skg shipping weight.', 'adaptive-customer-engagement' ),
				$location_fragment,
				$match_verb,
				$zone_fragment,
				$product_name,
				$estimate['cost_label'],
				number_format_i18n( (float) $estimate['weight_kg'], 2 )
			);
		}

		if ( '' !== $product_name && empty( $estimate ) ) {
			return sprintf(
				/* translators: 1: destination, 2: zone name, 3: product title. */
				__( 'Yes — %1$s %2$s %3$s. I can see delivery is configured there, but I cannot give a reliable estimate for %4$s from the current product data yet. If you share the exact postcode and chosen option, the team can confirm the final shipping charge.', 'adaptive-customer-engagement' ),
				$location_fragment,
				$match_verb,
				$zone_fragment,
				$product_name
			);
		}

		return sprintf(
			/* translators: 1: destination, 2: zone name. */
			__( 'Yes — %1$s %2$s %3$s in the current WooCommerce shipping setup. If you share the product and delivery postcode, I can try to estimate the configured delivery charge from the live shipping table.', 'adaptive-customer-engagement' ),
			$location_fragment,
			$match_verb,
			$zone_fragment
		);
	}

	/**
	 * Extract a shipping destination hint from the question.
	 *
	 * @param string $question User question.
	 * @return array<string, mixed>
	 */
	private function extract_shipping_destination( string $question ): array {
		$upper_question = strtoupper( wp_strip_all_tags( $question ) );
		$normalised     = $this->normalise_text( $question );

		if ( preg_match( '/\b([A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}|[A-Z]{1,2}\d[A-Z\d]?)\b/', $upper_question, $matches ) && ! empty( $matches[1] ) ) {
			$postcode = trim( preg_replace( '/\s+/', ' ', (string) $matches[1] ) ?: '' );

			return array(
				'type'     => 'postcode',
				'label'    => $postcode,
				'postcode' => $postcode,
				'country'  => 'GB',
			);
		}

		foreach ( $this->get_shipping_city_postcode_aliases() as $city => $patterns ) {
			if ( false === strpos( $normalised, $city ) ) {
				continue;
			}

			return array(
				'type'              => 'city',
				'label'             => ucwords( $city ),
				'postcode_patterns' => $patterns,
				'country'           => 'GB',
			);
		}

		return array(
			'type'    => 'unknown',
			'label'   => __( 'the requested destination', 'adaptive-customer-engagement' ),
			'country' => preg_match( '/\b(uk|gb|great britain|united kingdom|england|scotland|wales)\b/i', $question ) ? 'GB' : '',
		);
	}

	/**
	 * Map common UK city names to outward postcode patterns for regional shipping checks.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function get_shipping_city_postcode_aliases(): array {
		return array(
			'sheffield'   => array( 'S1*', 'S2*', 'S3*', 'S4*', 'S5*', 'S6*', 'S7*', 'S8*', 'S9*' ),
			'leeds'       => array( 'LS*' ),
			'wakefield'   => array( 'WF*' ),
			'york'        => array( 'YO*' ),
			'hull'        => array( 'HU*' ),
			'newcastle'   => array( 'NE*' ),
			'sunderland'  => array( 'SR*' ),
			'birmingham'  => array( 'B1*', 'B2*', 'B3*', 'B4*', 'B5*', 'B6*', 'B7*', 'B8*', 'B9*' ),
			'coventry'    => array( 'CV*' ),
			'glasgow'     => array( 'G1*', 'G2*', 'G3*', 'G4*', 'G5*', 'G6*', 'G7*', 'G8*', 'G9*' ),
			'edinburgh'   => array( 'EH*' ),
			'cardiff'     => array( 'CF*' ),
			'swansea'     => array( 'SA*' ),
			'bristol'     => array( 'BS*' ),
			'cambridge'   => array( 'CB*' ),
			'leicester'   => array( 'LE*' ),
			'nottingham'  => array( 'NG*' ),
			'milton keynes' => array( 'MK*' ),
			'oxford'      => array( 'OX*' ),
			'reading'     => array( 'RG*' ),
			'london'      => array( 'EC*', 'SE*', 'SW*', 'WC*', 'NW*' ),
			'liverpool'   => array( 'L1*', 'L2*', 'L3*', 'L4*', 'L5*', 'L6*', 'L7*', 'L8*', 'L9*' ),
			'manchester'  => array( 'M*' ),
		);
	}

	/**
	 * Find the live WooCommerce shipping zone that best matches the destination.
	 *
	 * @param array<string, mixed> $destination Destination hint.
	 * @return array<string, mixed>
	 */
	private function find_shipping_zone_for_destination( array $destination ): array {
		$type = sanitize_key( (string) ( $destination['type'] ?? '' ) );

		if ( 'postcode' === $type && ! empty( $destination['postcode'] ) ) {
			$zone = \WC_Shipping_Zones::get_zone_matching_package(
				array(
					'destination' => array(
						'country'   => sanitize_text_field( (string) ( $destination['country'] ?? 'GB' ) ),
						'state'     => '',
						'postcode'  => sanitize_text_field( (string) $destination['postcode'] ),
						'city'      => '',
						'address'   => '',
						'address_1' => '',
						'address_2' => '',
					),
					'contents'      => array(),
					'contents_cost' => 0,
				)
			);

			if ( is_object( $zone ) ) {
				return $this->build_shipping_zone_payload( $zone );
			}
		}

		if ( 'city' === $type && ! empty( $destination['postcode_patterns'] ) && is_array( $destination['postcode_patterns'] ) ) {
			foreach ( \WC_Shipping_Zones::get_zones() as $zone_row ) {
				$zone = new \WC_Shipping_Zone( (int) ( $zone_row['zone_id'] ?? 0 ) );

				if ( ! is_object( $zone ) ) {
					continue;
				}

				$payload = $this->build_shipping_zone_payload( $zone );

				if ( $this->shipping_zone_matches_patterns( (array) ( $payload['postcodes'] ?? array() ), $destination['postcode_patterns'] ) ) {
					return $payload;
				}
			}
		}

		return array();
	}

	/**
	 * Build a compact shipping-zone payload from a WooCommerce shipping zone object.
	 *
	 * @param object $zone WooCommerce shipping zone.
	 * @return array<string, mixed>
	 */
	private function build_shipping_zone_payload( $zone ): array {
		$zone_id   = method_exists( $zone, 'get_id' ) ? (int) $zone->get_id() : 0;
		$zone_name = method_exists( $zone, 'get_zone_name' ) ? sanitize_text_field( (string) $zone->get_zone_name() ) : '';
		$methods   = method_exists( $zone, 'get_shipping_methods' ) ? $zone->get_shipping_methods( true ) : array();

		return array(
			'zone_id'   => $zone_id,
			'zone_name' => $zone_name,
			'postcodes' => $this->get_zone_location_postcodes( $zone_id ),
			'methods'   => is_array( $methods ) ? array_values( $methods ) : array(),
		);
	}

	/**
	 * Read postcode rules saved against a WooCommerce shipping zone.
	 *
	 * @param int $zone_id Zone ID.
	 * @return array<int, string>
	 */
	private function get_zone_location_postcodes( int $zone_id ): array {
		global $wpdb;

		if ( $zone_id < 0 ) {
			return array();
		}

		$table = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT location_code FROM {$table} WHERE zone_id = %d AND location_type = %s ORDER BY location_code ASC",
				$zone_id,
				'postcode'
			)
		);

		return array_values(
			array_filter(
				array_map(
					static function ( $row ): string {
						return sanitize_text_field( (string) $row );
					},
					is_array( $rows ) ? $rows : array()
				)
			)
		);
	}

	/**
	 * Check whether a zone's postcode rules match any of the target patterns.
	 *
	 * @param array<int, string> $zone_postcodes Zone postcode rules.
	 * @param array<int, string> $patterns       Target patterns.
	 * @return bool
	 */
	private function shipping_zone_matches_patterns( array $zone_postcodes, array $patterns ): bool {
		$zone_postcodes = array_map( array( $this, 'normalise_shipping_postcode_pattern' ), $zone_postcodes );
		$patterns       = array_map( array( $this, 'normalise_shipping_postcode_pattern' ), $patterns );

		foreach ( $patterns as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}

			if ( in_array( $pattern, $zone_postcodes, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pick the most useful shipping method for a delivery estimate.
	 *
	 * @param array<int, mixed> $methods Zone shipping methods.
	 * @return object|null
	 */
	private function get_quoteable_shipping_method( array $methods ) {
		foreach ( $methods as $method ) {
			if ( is_object( $method ) && ! empty( $method->enabled ) && 'local_pickup' !== ( $method->id ?? '' ) ) {
				return $method;
			}
		}

		return null;
	}

	/**
	 * Estimate a shipping cost for a product from a shipping method.
	 *
	 * @param object               $method  Shipping method object.
	 * @param array<string, mixed> $commerce Product commerce data.
	 * @return array<string, mixed>
	 */
	private function estimate_shipping_cost_for_product( $method, array $commerce ): array {
		$weight_kg = ! empty( $commerce['empty_weight_kg'] ) ? (float) $commerce['empty_weight_kg'] : 0.0;

		if ( 'flexible_shipping_single' === ( $method->id ?? '' ) ) {
			if ( $weight_kg <= 0 ) {
				return array();
			}

			$rules = method_exists( $method, 'get_option' ) ? $method->get_option( 'method_rules', '' ) : '';

			if ( empty( $rules ) && method_exists( $method, 'get_option' ) ) {
				$rules = $method->get_option( 'fs_method_rules', '' );
			}

			$rules = is_string( $rules ) ? json_decode( $rules, true ) : $rules;

			if ( ! is_array( $rules ) ) {
				return array();
			}

			foreach ( $rules as $rule ) {
				$conditions = is_array( $rule['conditions'] ?? null ) ? $rule['conditions'] : array();

				foreach ( $conditions as $condition ) {
					if ( 'weight' !== sanitize_key( (string) ( $condition['condition_id'] ?? '' ) ) ) {
						continue;
					}

					$min = isset( $condition['min'] ) ? (float) $condition['min'] : 0.0;
					$max = isset( $condition['max'] ) ? (float) $condition['max'] : 0.0;

					if ( $weight_kg < $min || ( $max > 0 && $weight_kg > $max ) ) {
						continue;
					}

					$cost = isset( $rule['cost_per_order'] ) ? (float) $rule['cost_per_order'] : 0.0;

					return array(
						'cost_raw'   => $cost,
						'cost_label' => wp_strip_all_tags( html_entity_decode( wc_price( $cost ), ENT_QUOTES ) ),
						'weight_kg'  => $weight_kg,
					);
				}
			}
		}

		if ( 'flat_rate' === ( $method->id ?? '' ) && method_exists( $method, 'get_option' ) ) {
			$cost = (float) $method->get_option( 'cost', 0 );

			if ( $cost > 0 ) {
				return array(
					'cost_raw'   => $cost,
					'cost_label' => wp_strip_all_tags( html_entity_decode( wc_price( $cost ), ENT_QUOTES ) ),
					'weight_kg'  => $weight_kg,
				);
			}
		}

		return array();
	}

	/**
	 * Normalise postcode-style shipping patterns for matching.
	 *
	 * @param string $pattern Pattern text.
	 * @return string
	 */
	private function normalise_shipping_postcode_pattern( string $pattern ): string {
		return strtoupper( preg_replace( '/\s+/', '', trim( $pattern ) ) ?: '' );
	}

	/**
	 * Append related PDF documents for any product sources.
	 *
	 * @param array<int, array<string, mixed>> $sources Source documents.
	 * @param int                               $limit   Maximum source count.
	 * @return array<int, array<string, mixed>>
	 */
	private function enrich_sources_with_product_documents( array $sources, int $limit ): array {
		$limit    = max( 1, min( 8, $limit ) );
		$enriched = array();
		$seen     = array();

		foreach ( $sources as $source ) {
			$source_key = sanitize_key( (string) ( $source['source_type'] ?? '' ) ) . ':' . absint( $source['id'] ?? 0 ) . ':' . esc_url_raw( (string) ( $source['url'] ?? '' ) );

			if ( isset( $seen[ $source_key ] ) ) {
				continue;
			}

			$enriched[]         = $source;
			$seen[ $source_key ] = true;

			if ( count( $enriched ) >= $limit ) {
				break;
			}

			if ( 'product' !== sanitize_key( (string) ( $source['source_type'] ?? '' ) ) ) {
				continue;
			}

			foreach ( $this->get_product_document_sources( absint( $source['id'] ?? 0 ), $source ) as $document_source ) {
				$document_key = sanitize_key( (string) ( $document_source['source_type'] ?? '' ) ) . ':' . absint( $document_source['id'] ?? 0 ) . ':' . esc_url_raw( (string) ( $document_source['url'] ?? '' ) );

				if ( isset( $seen[ $document_key ] ) ) {
					continue;
				}

				$enriched[]              = $document_source;
				$seen[ $document_key ] = true;

				if ( count( $enriched ) >= $limit ) {
					break 2;
				}
			}
		}

		return array_slice( $enriched, 0, $limit );
	}

	/**
	 * Get related PDF documents for a product source.
	 *
	 * @param int                  $product_id      Product ID.
	 * @param array<string, mixed> $product_source  Product source document.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_product_document_sources( int $product_id, array $product_source ): array {
		$product_post = get_post( $product_id );

		if ( ! $product_post instanceof \WP_Post ) {
			return array();
		}

		$attachments = $this->collect_product_pdf_attachments( $product_post, $product_source );
		$collected   = array();

		foreach ( $attachments as $attachment ) {
			$key = absint( $attachment->ID );

			if ( isset( $collected[ $key ] ) ) {
				continue;
			}

			$document = $this->build_pdf_document( $attachment, $product_post );

			if ( empty( $document ) ) {
				continue;
			}

			$collected[ $key ] = $document;
		}

		return array_values( $collected );
	}

	/**
	 * Collect likely PDF attachments for a product, including related range items.
	 *
	 * @param \WP_Post               $product_post   Product post.
	 * @param array<string, mixed>   $product_source Product source document.
	 * @return array<int, \WP_Post>
	 */
	private function collect_product_pdf_attachments( \WP_Post $product_post, array $product_source ): array {
		$attachments = array();
		$seen        = array();

		$append_attachment = static function ( \WP_Post $attachment ) use ( &$attachments, &$seen ): void {
			$key = absint( $attachment->ID );

			if ( $key <= 0 || isset( $seen[ $key ] ) ) {
				return;
			}

			$attachments[] = $attachment;
			$seen[ $key ]  = true;
		};

		foreach ( $this->get_attached_pdf_posts( array( (int) $product_post->ID ) ) as $attachment ) {
			$append_attachment( $attachment );
		}

		foreach ( $this->extract_linked_pdf_attachments( $product_post ) as $attachment ) {
			$append_attachment( $attachment );
		}

		if ( ! empty( $attachments ) ) {
			return $attachments;
		}

		foreach ( $this->get_related_product_posts_for_documents( $product_post, $product_source ) as $related_post ) {
			foreach ( $this->get_attached_pdf_posts( array( (int) $related_post->ID ) ) as $attachment ) {
				$append_attachment( $attachment );
			}

			foreach ( $this->extract_linked_pdf_attachments( $related_post ) as $attachment ) {
				$append_attachment( $attachment );
			}

			if ( count( $attachments ) >= 4 ) {
				break;
			}
		}

		if ( ! empty( $attachments ) ) {
			return array_slice( $attachments, 0, 4 );
		}

		foreach ( $this->search_pdf_attachments_by_terms( $this->get_product_document_search_terms( $product_post, $product_source ) ) as $attachment ) {
			$append_attachment( $attachment );

			if ( count( $attachments ) >= 4 ) {
				break;
			}
		}

		return array_slice( $attachments, 0, 4 );
	}

	/**
	 * Get PDF attachments directly attached to one or more posts.
	 *
	 * @param array<int, int> $post_ids Post IDs.
	 * @return array<int, \WP_Post>
	 */
	private function get_attached_pdf_posts( array $post_ids ): array {
		$post_ids = array_values(
			array_filter(
				array_map( 'absint', $post_ids )
			)
		);

		if ( empty( $post_ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				get_posts(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'post_mime_type' => 'application/pdf',
						'post_parent__in'=> $post_ids,
						'posts_per_page' => 8,
						'orderby'        => 'date',
						'order'          => 'DESC',
					)
				),
				static function ( $post ): bool {
					return $post instanceof \WP_Post;
				}
			)
		);
	}

	/**
	 * Find related products whose PDFs may apply to the current product's range.
	 *
	 * @param \WP_Post             $product_post   Product post.
	 * @param array<string, mixed> $product_source Product source document.
	 * @return array<int, \WP_Post>
	 */
	private function get_related_product_posts_for_documents( \WP_Post $product_post, array $product_source ): array {
		$term_ids = wp_get_post_terms( $product_post->ID, 'product_cat', array( 'fields' => 'ids' ) );
		$term_ids = is_wp_error( $term_ids ) ? array() : array_values( array_filter( array_map( 'absint', (array) $term_ids ) ) );
		$related  = array();
		$seen     = array(
			(int) $product_post->ID => true,
		);

		if ( ! empty( $term_ids ) ) {
			foreach ( get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 6,
					'post__not_in'   => array( (int) $product_post->ID ),
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $term_ids,
						),
					),
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			) as $candidate ) {
				if ( $candidate instanceof \WP_Post && ! isset( $seen[ (int) $candidate->ID ] ) ) {
					$related[]                  = $candidate;
					$seen[ (int) $candidate->ID ] = true;
				}
			}
		}

		foreach ( $this->search_related_products_by_terms( $this->get_product_document_search_terms( $product_post, $product_source ), (int) $product_post->ID ) as $candidate ) {
			if ( ! isset( $seen[ (int) $candidate->ID ] ) ) {
				$related[]                  = $candidate;
				$seen[ (int) $candidate->ID ] = true;
			}
		}

		return array_slice( $related, 0, 6 );
	}

	/**
	 * Build search terms that can link a product to range-level PDF documents.
	 *
	 * @param \WP_Post             $product_post   Product post.
	 * @param array<string, mixed> $product_source Product source document.
	 * @return array<int, string>
	 */
	private function get_product_document_search_terms( \WP_Post $product_post, array $product_source ): array {
		$categories = array_values(
			array_filter(
				array_map(
					'sanitize_text_field',
					(array) ( $product_source['commerce']['categories'] ?? array() )
				)
			)
		);
		$title_terms = array_values(
			array_filter(
				$this->extract_terms( (string) $product_post->post_title ),
				static function ( string $term ): bool {
					return ! in_array( $term, array( 'bin', 'bins', 'body', 'wheelie', 'container', 'containers', 'litre', 'litres', 'range' ), true );
				}
			)
		);

		return array_values(
			array_unique(
				array_filter(
					array_merge(
						array(
							sanitize_text_field( (string) ( $product_source['title'] ?? '' ) ),
							sanitize_text_field( (string) ( $product_source['commerce']['sku'] ?? '' ) ),
						),
						$categories,
						array_slice( $title_terms, 0, 4 )
					)
				)
			)
		);
	}

	/**
	 * Search for related products using broad matching terms.
	 *
	 * @param array<int, string> $terms              Search terms.
	 * @param int                $excluded_product_id Product to exclude.
	 * @return array<int, \WP_Post>
	 */
	private function search_related_products_by_terms( array $terms, int $excluded_product_id ): array {
		$results = array();
		$seen    = array();

		foreach ( array_slice( $terms, 0, 4 ) as $term ) {
			if ( '' === $term ) {
				continue;
			}

			foreach ( get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 4,
					'post__not_in'   => array( $excluded_product_id ),
					's'              => $term,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			) as $candidate ) {
				if ( ! $candidate instanceof \WP_Post ) {
					continue;
				}

				$key = (int) $candidate->ID;

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$results[]   = $candidate;
				$seen[ $key ] = true;
			}
		}

		return $results;
	}

	/**
	 * Search PDF attachments by a set of related product terms.
	 *
	 * @param array<int, string> $terms Search terms.
	 * @return array<int, \WP_Post>
	 */
	private function search_pdf_attachments_by_terms( array $terms ): array {
		$results = array();
		$seen    = array();

		foreach ( array_slice( $terms, 0, 5 ) as $term ) {
			if ( '' === $term ) {
				continue;
			}

			foreach ( get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'application/pdf',
					'posts_per_page' => 4,
					's'              => $term,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			) as $attachment ) {
				if ( ! $attachment instanceof \WP_Post ) {
					continue;
				}

				$key = (int) $attachment->ID;

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$results[]   = $attachment;
				$seen[ $key ] = true;
			}
		}

		return $results;
	}

	/**
	 * Extract explicitly linked PDF attachments from product content.
	 *
	 * @param \WP_Post $product_post Product post.
	 * @return array<int, \WP_Post>
	 */
	private function extract_linked_pdf_attachments( \WP_Post $product_post ): array {
		$matches = array();
		$posts   = array();
		$content = (string) $product_post->post_content . "\n" . (string) $product_post->post_excerpt;

		if ( preg_match_all( '#https?://[^\s"\']+?\.pdf(?:\?[^\s"\']*)?#i', $content, $matches ) ) {
			foreach ( array_unique( $matches[0] ) as $url ) {
				$attachment_id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;

				if ( $attachment_id <= 0 ) {
					continue;
				}

				$attachment = get_post( $attachment_id );

				if ( $attachment instanceof \WP_Post ) {
					$posts[] = $attachment;
				}
			}
		}

		return $posts;
	}

	/**
	 * Build a source document for a PDF attachment.
	 *
	 * @param \WP_Post $attachment   Attachment post.
	 * @param \WP_Post $product_post Product post.
	 * @return array<string, mixed>
	 */
	private function build_pdf_document( \WP_Post $attachment, \WP_Post $product_post ): array {
		$url = wp_get_attachment_url( $attachment->ID );

		if ( empty( $url ) || 'application/pdf' !== get_post_mime_type( $attachment ) ) {
			return array();
		}

		$title = sanitize_text_field( get_the_title( $attachment ) ?: basename( (string) $url ) );
		$summary = sprintf(
			/* translators: 1: document title, 2: product title. */
			__( '%1$s is a PDF document related to %2$s.', 'adaptive-customer-engagement' ),
			$title,
			sanitize_text_field( get_the_title( $product_post ) )
		);

		return array(
			'id'           => (int) $attachment->ID,
			'title'        => $title,
			'url'          => esc_url_raw( $url ),
			'source_type'  => 'document',
			'content_type' => __( 'PDF document', 'adaptive-customer-engagement' ),
			'source_label' => __( 'PDF document', 'adaptive-customer-engagement' ),
			'summary'      => sanitize_textarea_field( $summary ),
			'image_url'    => '',
			'commerce'     => array(),
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
