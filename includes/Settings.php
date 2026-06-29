<?php
/**
 * Settings accessors.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION_NAME      = 'ace_settings';
	public const HASH_SALT_OPTION = 'ace_hash_salt';
	public const REPORTING_SEGMENTS_OPTION = 'ace_reporting_segments';
	public const CONNECT_IMPORT_STATUS_OPTION = 'ace_connect_import_status';

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'        => true,
			'tracking'       => array(
				'track_pageviews'          => true,
				'track_click_to_call'      => true,
				'track_forms'              => false,
				'track_downloads'          => true,
				'cookie_name'              => 'ace_sid',
				'visitor_cookie_name'      => 'ace_vid',
				'session_lifetime_minutes' => 30,
				'visitor_lifetime_days'    => 90,
				'respect_dnt'              => true,
				'ignore_logged_in_admins'  => true,
				'call_track_selectors'     => array( '.ace-track-call', 'a[href^="tel:"]' ),
			),
			'privacy'        => array(
				'raw_ip_retention_days'    => 14,
				'raw_phone_retention_days' => 30,
				'session_retention_days'   => 365,
				'bot_retention_days'       => 30,
				'ignore_internal_ips'      => false,
			),
			'enrichment'     => array(
				'provider'           => 'none',
				'api_key'            => '',
				'cache_days'         => 30,
				'enrich_bots'        => false,
				'enrich_private_ips' => false,
			),
			'amazon_connect' => array(
				'enabled'                 => false,
				'region'                  => 'eu-west-2',
				'instance_id'             => '',
				'instance_url'            => '',
				'lex_bot_console_url'     => '',
				'lex_bot_role_arn'        => '',
				'lex_runtime_role_arn'    => '',
				'lex_runtime_lambda_name' => '',
				'lex_runtime_lambda_arn'  => '',
				'lex_bot_id'              => '',
				'lex_bot_alias_id'        => '',
				'lex_bot_locale_id'       => 'en_GB',
				'lex_bot_intent_name'     => '',
				'chat_widget_script_url'  => '',
				'chat_widget_id'          => '',
				'chat_widget_snippet_id'  => '',
				'chat_widget_security_key'=> '',
				's3_bucket'               => '',
				's3_prefix'               => '',
				'flow_logs_group'         => '',
				'access_key_id'           => '',
				'secret_access_key'       => '',
				'use_iam_role'            => true,
				'default_contact_flow_id' => '',
				'chat_contact_flow_id'    => '',
				'chat_api_endpoint'       => '',
				'webhook_secret'          => '',
			),
			'ai_agent'       => array(
				'enabled'                    => false,
				'mode'                       => 'chat',
				'provider'                   => 'openai',
				'assistant_id'               => '',
				'assistant_arn'              => '',
				'openai_api_key'             => '',
				'openai_model'               => 'gpt-4.1-mini',
				'anthropic_api_key'          => '',
				'anthropic_model'            => 'claude-opus-4-8',
				'openai_temperature'         => 0.2,
				'openai_max_response_tokens' => 1500,
				'system_prompt'              => "You are the expert product and sales consultant for this company's website. Your job is to help each visitor choose the right product and guide them toward buying, requesting a quote, or speaking to the team.\n\nHow to help:\n- Understand the need first. If a request is vague, ask one or two brief clarifying questions (use case, size or capacity, quantity, budget, delivery location) before recommending.\n- Recommend specific products by name and explain why they fit. When comparing options, give a short, scannable comparison (capacity, price, stock) rather than a long essay.\n- Answer only from the supplied live site and product context. Use the explicit facts provided — capacities, sizes, weights, attributes, variations, prices and stock — and quote prices and availability when relevant. Never invent prices, specifications, stock levels, or products. If a detail is not in the supplied context, say so plainly and offer to find out or connect the visitor with the team.\n- For products with options (variations), use the per-option price and stock to answer questions like \"how much is the 1100L\" or \"is the green one in stock\".\n- Move things forward: when a product fits, offer the natural next step — view the product, add it to the basket, request a quote, or leave contact details — without being pushy. Suggest genuinely relevant complementary items when it helps.\n- Be concise, warm, and professional, and write in British English.",
				'handoff_to_human'           => true,
				'restrict_to_site_scope'     => true,
				'share_session_context'      => true,
				'share_company_context'      => true,
				'share_number_context'       => true,
				'share_woocommerce_context'  => false,
				'session_summary_attribute'  => 'ace_session_summary',
				'company_summary_attribute'  => 'ace_company_summary',
				'number_summary_attribute'   => 'ace_number_summary',
				'context_instructions'       => '',
				'use_live_site_context'      => true,
				'live_context_post_types'    => array(),
				'show_source_links'          => true,
				'keep_history'               => true,
				'max_context_documents'      => 4,
				'max_history_messages'       => 8,
				'frontend_chat_enabled'      => false,
				'frontend_chat_admin_only'   => true,
				'frontend_chat_bot_name'     => 'Site assistant',
				'frontend_chat_title'        => 'Site assistant',
				'frontend_chat_greeting'     => 'Hello, I am the site assistant. Ask me about the company, products, or services and I will do my best to help.',
				'frontend_chat_placeholder'  => 'Ask about the company or products',
				'frontend_voice_input'       => false,
				'frontend_voice_replies'     => false,
				'frontend_voice_autospeak'   => false,
				'frontend_voice_hands_free'  => false,
				'frontend_voice_lang'        => 'en-GB',
				'frontend_voice_provider'    => 'browser',
				'voice_openai_api_key'       => '',
				'voice_openai_model'         => 'gpt-4o-mini-tts',
				'voice_openai_voice'         => 'alloy',
				'voice_elevenlabs_api_key'   => '',
				'voice_elevenlabs_voice_id'  => '',
				'voice_elevenlabs_model'     => 'eleven_turbo_v2_5',
				'frontend_test_enabled'      => false,
				'frontend_test_admin_only'   => true,
				'bot_knowledge_entries'      => array(),
				'allowed_tools'              => array(),
				'guardrails'                 => array(),
			),
		);
	}

	/**
	 * Get settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return self::merge_defaults( self::defaults(), $settings );
	}

	/**
	 * Update settings.
	 *
	 * @param array<string, mixed> $settings Settings payload.
	 * @return array<string, mixed>
	 */
	public static function update( array $settings ): array {
		$sanitized = self::sanitize( $settings );
		update_option( self::OPTION_NAME, $sanitized );

		return $sanitized;
	}

	/**
	 * Get saved reporting segments.
	 *
	 * @param string $view Optional view filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_reporting_segments( string $view = '' ): array {
		$segments = get_option( self::REPORTING_SEGMENTS_OPTION, array() );
		$segments = is_array( $segments ) ? self::sanitize_reporting_segments( $segments ) : array();

		if ( '' === $view ) {
			return $segments;
		}

		return array_values(
			array_filter(
				$segments,
				static function ( array $segment ) use ( $view ): bool {
					return $segment['view'] === $view;
				}
			)
		);
	}

	/**
	 * Save a reporting segment.
	 *
	 * @param array<string, mixed> $segment Segment payload.
	 * @return array<string, mixed>
	 */
	public static function add_reporting_segment( array $segment ): array {
		$segments       = self::get_reporting_segments();
		$sanitized      = self::sanitize_reporting_segment( $segment );
		array_unshift( $segments, $sanitized );
		$segments = array_slice( $segments, 0, 50 );
		update_option( self::REPORTING_SEGMENTS_OPTION, $segments, false );

		return $sanitized;
	}

	/**
	 * Replace all saved reporting segments.
	 *
	 * @param array<int, mixed> $segments Segment payloads.
	 * @return array<int, array<string, mixed>>
	 */
	public static function update_reporting_segments( array $segments ): array {
		$sanitized = self::sanitize_reporting_segments( $segments );
		$sanitized = array_slice( $sanitized, 0, 50 );
		update_option( self::REPORTING_SEGMENTS_OPTION, $sanitized, false );

		return $sanitized;
	}

	/**
	 * Delete a reporting segment.
	 *
	 * @param string $segment_id Segment ID.
	 * @return bool
	 */
	public static function delete_reporting_segment( string $segment_id ): bool {
		$segment_id = sanitize_text_field( $segment_id );
		$segments   = self::get_reporting_segments();
		$remaining  = array_values(
			array_filter(
				$segments,
				static function ( array $segment ) use ( $segment_id ): bool {
					return $segment['id'] !== $segment_id;
				}
			)
		);

		if ( count( $remaining ) === count( $segments ) ) {
			return false;
		}

		update_option( self::REPORTING_SEGMENTS_OPTION, $remaining, false );

		return true;
	}

	/**
	 * Get or create the hash salt.
	 *
	 * @return string
	 */
	public static function get_hash_salt(): string {
		$salt = get_option( self::HASH_SALT_OPTION );

		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = wp_generate_password( 64, true, true );
			update_option( self::HASH_SALT_OPTION, $salt, false );
		}

		return $salt;
	}

	/**
	 * Get the latest Amazon Connect import-run status.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_connect_import_status(): array {
		$status = get_option( self::CONNECT_IMPORT_STATUS_OPTION, array() );

		return is_array( $status ) ? self::sanitize_connect_import_status( $status ) : self::sanitize_connect_import_status( array() );
	}

	/**
	 * Save the latest Amazon Connect import-run status.
	 *
	 * @param array<string, mixed> $status Import status payload.
	 * @return array<string, mixed>
	 */
	public static function update_connect_import_status( array $status ): array {
		$sanitized = self::sanitize_connect_import_status( $status );
		update_option( self::CONNECT_IMPORT_STATUS_OPTION, $sanitized, false );

		return $sanitized;
	}

	/**
	 * Get the public searchable post types that can feed live chat context.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_available_site_context_post_types(): array {
		$post_types = array();
		$excluded   = array(
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_navigation',
			'wp_template',
			'wp_template_part',
			'wp_font_family',
			'wp_font_face',
			'product_variation',
		);

		foreach ( get_post_types( array( 'public' => true, 'exclude_from_search' => false ), 'objects' ) as $post_type => $post_type_object ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type || in_array( $post_type, $excluded, true ) || ! $post_type_object instanceof \WP_Post_Type ) {
				continue;
			}

			$label = ! empty( $post_type_object->labels->singular_name ) ? $post_type_object->labels->singular_name : $post_type_object->label;
			$label = sanitize_text_field( (string) $label );

			$post_types[] = array(
				'value' => $post_type,
				'label' => '' !== $label ? $label : ucfirst( $post_type ),
			);
		}

		usort(
			$post_types,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) );
			}
		);

		return $post_types;
	}

	/**
	 * Get the available site-context post type names.
	 *
	 * @return array<int, string>
	 */
	public static function get_available_site_context_post_type_names(): array {
		return array_values(
			array_filter(
				array_map(
					static function ( array $post_type ): string {
						return sanitize_key( (string) ( $post_type['value'] ?? '' ) );
					},
					self::get_available_site_context_post_types()
				)
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array<string, mixed> $settings Settings payload.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $settings ): array {
		$defaults       = self::defaults();
		$tracking       = isset( $settings['tracking'] ) && is_array( $settings['tracking'] ) ? $settings['tracking'] : array();
		$privacy        = isset( $settings['privacy'] ) && is_array( $settings['privacy'] ) ? $settings['privacy'] : array();
		$enrichment     = isset( $settings['enrichment'] ) && is_array( $settings['enrichment'] ) ? $settings['enrichment'] : array();
		$amazon_connect = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();
		$ai_agent       = isset( $settings['ai_agent'] ) && is_array( $settings['ai_agent'] ) ? $settings['ai_agent'] : array();
		$provider       = sanitize_key( $ai_agent['provider'] ?? $defaults['ai_agent']['provider'] );
		$mode           = sanitize_key( $ai_agent['mode'] ?? $defaults['ai_agent']['mode'] );
		$available_site_context_post_types = self::get_available_site_context_post_type_names();

		if ( ! in_array( $provider, array( 'openai', 'anthropic' ), true ) ) {
			$provider = 'openai';
		}

		if ( ! in_array( $mode, array( 'chat', 'assist', 'off' ), true ) ) {
			$mode = $defaults['ai_agent']['mode'];
		}

		$frontend_chat_enabled = $ai_agent['frontend_chat_enabled'] ?? $ai_agent['frontend_test_enabled'] ?? $defaults['ai_agent']['frontend_chat_enabled'];
		$frontend_chat_admin_only = $ai_agent['frontend_chat_admin_only'] ?? $ai_agent['frontend_test_admin_only'] ?? $defaults['ai_agent']['frontend_chat_admin_only'];

		return array(
			'enabled'        => rest_sanitize_boolean( $settings['enabled'] ?? $defaults['enabled'] ),
			'tracking'       => array(
				'track_pageviews'          => rest_sanitize_boolean( $tracking['track_pageviews'] ?? $defaults['tracking']['track_pageviews'] ),
				'track_click_to_call'      => rest_sanitize_boolean( $tracking['track_click_to_call'] ?? $defaults['tracking']['track_click_to_call'] ),
				'track_forms'              => rest_sanitize_boolean( $tracking['track_forms'] ?? $defaults['tracking']['track_forms'] ),
				'track_downloads'          => rest_sanitize_boolean( $tracking['track_downloads'] ?? $defaults['tracking']['track_downloads'] ),
				'cookie_name'              => sanitize_key( $tracking['cookie_name'] ?? $defaults['tracking']['cookie_name'] ),
				'visitor_cookie_name'      => sanitize_key( $tracking['visitor_cookie_name'] ?? $defaults['tracking']['visitor_cookie_name'] ),
				'session_lifetime_minutes' => max( 5, absint( $tracking['session_lifetime_minutes'] ?? $defaults['tracking']['session_lifetime_minutes'] ) ),
				'visitor_lifetime_days'    => max( 1, absint( $tracking['visitor_lifetime_days'] ?? $defaults['tracking']['visitor_lifetime_days'] ) ),
				'respect_dnt'              => rest_sanitize_boolean( $tracking['respect_dnt'] ?? $defaults['tracking']['respect_dnt'] ),
				'ignore_logged_in_admins'  => rest_sanitize_boolean( $tracking['ignore_logged_in_admins'] ?? $defaults['tracking']['ignore_logged_in_admins'] ),
				'call_track_selectors'     => array_values(
					array_filter(
						array_map(
							static function ( $selector ): string {
								return sanitize_text_field( (string) $selector );
							},
							is_array( $tracking['call_track_selectors'] ?? null ) ? $tracking['call_track_selectors'] : $defaults['tracking']['call_track_selectors']
						)
					)
				),
			),
			'privacy'        => array(
				'raw_ip_retention_days'    => max( 1, absint( $privacy['raw_ip_retention_days'] ?? $defaults['privacy']['raw_ip_retention_days'] ) ),
				'raw_phone_retention_days' => max( 1, absint( $privacy['raw_phone_retention_days'] ?? $defaults['privacy']['raw_phone_retention_days'] ) ),
				'session_retention_days'   => max( 30, absint( $privacy['session_retention_days'] ?? $defaults['privacy']['session_retention_days'] ) ),
				'bot_retention_days'       => max( 1, absint( $privacy['bot_retention_days'] ?? $defaults['privacy']['bot_retention_days'] ) ),
				'ignore_internal_ips'      => rest_sanitize_boolean( $privacy['ignore_internal_ips'] ?? $defaults['privacy']['ignore_internal_ips'] ),
			),
			'enrichment'     => array(
				'provider'           => sanitize_key( $enrichment['provider'] ?? $defaults['enrichment']['provider'] ),
				'api_key'            => sanitize_text_field( $enrichment['api_key'] ?? $defaults['enrichment']['api_key'] ),
				'cache_days'         => max( 1, absint( $enrichment['cache_days'] ?? $defaults['enrichment']['cache_days'] ) ),
				'enrich_bots'        => rest_sanitize_boolean( $enrichment['enrich_bots'] ?? $defaults['enrichment']['enrich_bots'] ),
				'enrich_private_ips' => rest_sanitize_boolean( $enrichment['enrich_private_ips'] ?? $defaults['enrichment']['enrich_private_ips'] ),
			),
			'amazon_connect' => array(
				'enabled'                 => rest_sanitize_boolean( $amazon_connect['enabled'] ?? $defaults['amazon_connect']['enabled'] ),
				'region'                  => sanitize_text_field( $amazon_connect['region'] ?? $defaults['amazon_connect']['region'] ),
				'instance_id'             => sanitize_text_field( $amazon_connect['instance_id'] ?? $defaults['amazon_connect']['instance_id'] ),
				'instance_url'            => esc_url_raw( $amazon_connect['instance_url'] ?? $defaults['amazon_connect']['instance_url'] ),
				'lex_bot_console_url'     => esc_url_raw( $amazon_connect['lex_bot_console_url'] ?? $defaults['amazon_connect']['lex_bot_console_url'] ),
				'lex_bot_role_arn'        => sanitize_text_field( $amazon_connect['lex_bot_role_arn'] ?? $defaults['amazon_connect']['lex_bot_role_arn'] ),
				'lex_runtime_role_arn'    => sanitize_text_field( $amazon_connect['lex_runtime_role_arn'] ?? $defaults['amazon_connect']['lex_runtime_role_arn'] ),
				'lex_runtime_lambda_name' => sanitize_text_field( $amazon_connect['lex_runtime_lambda_name'] ?? $defaults['amazon_connect']['lex_runtime_lambda_name'] ),
				'lex_runtime_lambda_arn'  => sanitize_text_field( $amazon_connect['lex_runtime_lambda_arn'] ?? $defaults['amazon_connect']['lex_runtime_lambda_arn'] ),
				'lex_bot_id'              => sanitize_text_field( $amazon_connect['lex_bot_id'] ?? $defaults['amazon_connect']['lex_bot_id'] ),
				'lex_bot_alias_id'        => sanitize_text_field( $amazon_connect['lex_bot_alias_id'] ?? $defaults['amazon_connect']['lex_bot_alias_id'] ),
				'lex_bot_locale_id'       => sanitize_text_field( $amazon_connect['lex_bot_locale_id'] ?? $defaults['amazon_connect']['lex_bot_locale_id'] ),
				'lex_bot_intent_name'     => sanitize_text_field( $amazon_connect['lex_bot_intent_name'] ?? $defaults['amazon_connect']['lex_bot_intent_name'] ),
				'chat_widget_script_url'  => esc_url_raw( $amazon_connect['chat_widget_script_url'] ?? $defaults['amazon_connect']['chat_widget_script_url'] ),
				'chat_widget_id'          => sanitize_text_field( $amazon_connect['chat_widget_id'] ?? $defaults['amazon_connect']['chat_widget_id'] ),
				'chat_widget_snippet_id'  => sanitize_text_field( $amazon_connect['chat_widget_snippet_id'] ?? $defaults['amazon_connect']['chat_widget_snippet_id'] ),
				'chat_widget_security_key'=> sanitize_text_field( $amazon_connect['chat_widget_security_key'] ?? $defaults['amazon_connect']['chat_widget_security_key'] ),
				's3_bucket'               => sanitize_text_field( $amazon_connect['s3_bucket'] ?? $defaults['amazon_connect']['s3_bucket'] ),
				's3_prefix'               => sanitize_text_field( $amazon_connect['s3_prefix'] ?? $defaults['amazon_connect']['s3_prefix'] ),
				'flow_logs_group'         => sanitize_text_field( $amazon_connect['flow_logs_group'] ?? $defaults['amazon_connect']['flow_logs_group'] ),
				'access_key_id'           => sanitize_text_field( $amazon_connect['access_key_id'] ?? $defaults['amazon_connect']['access_key_id'] ),
				'secret_access_key'       => sanitize_text_field( $amazon_connect['secret_access_key'] ?? $defaults['amazon_connect']['secret_access_key'] ),
				'use_iam_role'            => rest_sanitize_boolean( $amazon_connect['use_iam_role'] ?? $defaults['amazon_connect']['use_iam_role'] ),
				'default_contact_flow_id' => sanitize_text_field( $amazon_connect['default_contact_flow_id'] ?? $defaults['amazon_connect']['default_contact_flow_id'] ),
				'chat_contact_flow_id'    => sanitize_text_field( $amazon_connect['chat_contact_flow_id'] ?? $defaults['amazon_connect']['chat_contact_flow_id'] ),
				'chat_api_endpoint'       => esc_url_raw( $amazon_connect['chat_api_endpoint'] ?? $defaults['amazon_connect']['chat_api_endpoint'] ),
				'webhook_secret'          => sanitize_text_field( $amazon_connect['webhook_secret'] ?? $defaults['amazon_connect']['webhook_secret'] ),
			),
			'ai_agent'       => array(
				'enabled'                   => rest_sanitize_boolean( $ai_agent['enabled'] ?? $defaults['ai_agent']['enabled'] ),
				'mode'                      => $mode,
				'provider'                  => $provider,
				'assistant_id'              => sanitize_text_field( $ai_agent['assistant_id'] ?? $defaults['ai_agent']['assistant_id'] ),
				'assistant_arn'             => sanitize_text_field( $ai_agent['assistant_arn'] ?? $defaults['ai_agent']['assistant_arn'] ),
				'openai_api_key'            => sanitize_text_field( $ai_agent['openai_api_key'] ?? $defaults['ai_agent']['openai_api_key'] ),
				'openai_model'              => sanitize_text_field( $ai_agent['openai_model'] ?? $defaults['ai_agent']['openai_model'] ),
				'anthropic_api_key'         => sanitize_text_field( $ai_agent['anthropic_api_key'] ?? $defaults['ai_agent']['anthropic_api_key'] ),
				'anthropic_model'           => sanitize_text_field( $ai_agent['anthropic_model'] ?? $defaults['ai_agent']['anthropic_model'] ),
				'openai_temperature'        => max( 0, min( 2, (float) ( $ai_agent['openai_temperature'] ?? $defaults['ai_agent']['openai_temperature'] ) ) ),
				'openai_max_response_tokens'=> max( 200, min( 8000, absint( $ai_agent['openai_max_response_tokens'] ?? $defaults['ai_agent']['openai_max_response_tokens'] ) ) ),
				'system_prompt'             => sanitize_textarea_field( $ai_agent['system_prompt'] ?? $defaults['ai_agent']['system_prompt'] ),
				'handoff_to_human'          => rest_sanitize_boolean( $ai_agent['handoff_to_human'] ?? $defaults['ai_agent']['handoff_to_human'] ),
				'restrict_to_site_scope'    => rest_sanitize_boolean( $ai_agent['restrict_to_site_scope'] ?? $defaults['ai_agent']['restrict_to_site_scope'] ),
				'share_session_context'     => rest_sanitize_boolean( $ai_agent['share_session_context'] ?? $defaults['ai_agent']['share_session_context'] ),
				'share_company_context'     => rest_sanitize_boolean( $ai_agent['share_company_context'] ?? $defaults['ai_agent']['share_company_context'] ),
				'share_number_context'      => rest_sanitize_boolean( $ai_agent['share_number_context'] ?? $defaults['ai_agent']['share_number_context'] ),
				'share_woocommerce_context' => rest_sanitize_boolean( $ai_agent['share_woocommerce_context'] ?? $defaults['ai_agent']['share_woocommerce_context'] ),
				'session_summary_attribute' => sanitize_key( $ai_agent['session_summary_attribute'] ?? $defaults['ai_agent']['session_summary_attribute'] ),
				'company_summary_attribute' => sanitize_key( $ai_agent['company_summary_attribute'] ?? $defaults['ai_agent']['company_summary_attribute'] ),
				'number_summary_attribute'  => sanitize_key( $ai_agent['number_summary_attribute'] ?? $defaults['ai_agent']['number_summary_attribute'] ),
				'context_instructions'      => sanitize_textarea_field( $ai_agent['context_instructions'] ?? $defaults['ai_agent']['context_instructions'] ),
				'use_live_site_context'     => rest_sanitize_boolean( $ai_agent['use_live_site_context'] ?? $defaults['ai_agent']['use_live_site_context'] ),
				'live_context_post_types'   => array_values(
					array_filter(
						array_map(
							'sanitize_key',
							is_array( $ai_agent['live_context_post_types'] ?? null ) ? $ai_agent['live_context_post_types'] : $defaults['ai_agent']['live_context_post_types']
						),
						static function ( string $post_type ) use ( $available_site_context_post_types ): bool {
							return in_array( $post_type, $available_site_context_post_types, true );
						}
					)
				),
				'show_source_links'         => rest_sanitize_boolean( $ai_agent['show_source_links'] ?? $defaults['ai_agent']['show_source_links'] ),
				'keep_history'              => rest_sanitize_boolean( $ai_agent['keep_history'] ?? $defaults['ai_agent']['keep_history'] ),
				'max_context_documents'     => max( 1, min( 8, absint( $ai_agent['max_context_documents'] ?? $defaults['ai_agent']['max_context_documents'] ) ) ),
				'max_history_messages'      => max( 1, min( 12, absint( $ai_agent['max_history_messages'] ?? $defaults['ai_agent']['max_history_messages'] ) ) ),
				'frontend_chat_enabled'     => rest_sanitize_boolean( $frontend_chat_enabled ),
				'frontend_chat_admin_only'  => rest_sanitize_boolean( $frontend_chat_admin_only ),
				'frontend_chat_bot_name'    => sanitize_text_field( $ai_agent['frontend_chat_bot_name'] ?? $ai_agent['frontend_chat_title'] ?? $defaults['ai_agent']['frontend_chat_bot_name'] ),
				'frontend_chat_title'       => sanitize_text_field( $ai_agent['frontend_chat_title'] ?? $defaults['ai_agent']['frontend_chat_title'] ),
				'frontend_chat_greeting'    => sanitize_textarea_field( $ai_agent['frontend_chat_greeting'] ?? $defaults['ai_agent']['frontend_chat_greeting'] ),
				'frontend_chat_placeholder' => sanitize_text_field( $ai_agent['frontend_chat_placeholder'] ?? $defaults['ai_agent']['frontend_chat_placeholder'] ),
				'frontend_voice_input'      => rest_sanitize_boolean( $ai_agent['frontend_voice_input'] ?? $defaults['ai_agent']['frontend_voice_input'] ),
				'frontend_voice_replies'    => rest_sanitize_boolean( $ai_agent['frontend_voice_replies'] ?? $defaults['ai_agent']['frontend_voice_replies'] ),
				'frontend_voice_autospeak'  => rest_sanitize_boolean( $ai_agent['frontend_voice_autospeak'] ?? $defaults['ai_agent']['frontend_voice_autospeak'] ),
				'frontend_voice_hands_free' => rest_sanitize_boolean( $ai_agent['frontend_voice_hands_free'] ?? $defaults['ai_agent']['frontend_voice_hands_free'] ),
				'frontend_voice_lang'       => sanitize_text_field( $ai_agent['frontend_voice_lang'] ?? $defaults['ai_agent']['frontend_voice_lang'] ),
				'frontend_voice_provider'   => in_array( sanitize_key( $ai_agent['frontend_voice_provider'] ?? 'browser' ), array( 'browser', 'openai', 'elevenlabs' ), true ) ? sanitize_key( $ai_agent['frontend_voice_provider'] ?? 'browser' ) : 'browser',
				'voice_openai_api_key'      => sanitize_text_field( $ai_agent['voice_openai_api_key'] ?? $defaults['ai_agent']['voice_openai_api_key'] ),
				'voice_openai_model'        => sanitize_text_field( $ai_agent['voice_openai_model'] ?? $defaults['ai_agent']['voice_openai_model'] ),
				'voice_openai_voice'        => sanitize_text_field( $ai_agent['voice_openai_voice'] ?? $defaults['ai_agent']['voice_openai_voice'] ),
				'voice_elevenlabs_api_key'  => sanitize_text_field( $ai_agent['voice_elevenlabs_api_key'] ?? $defaults['ai_agent']['voice_elevenlabs_api_key'] ),
				'voice_elevenlabs_voice_id' => sanitize_text_field( $ai_agent['voice_elevenlabs_voice_id'] ?? $defaults['ai_agent']['voice_elevenlabs_voice_id'] ),
				'voice_elevenlabs_model'    => sanitize_text_field( $ai_agent['voice_elevenlabs_model'] ?? $defaults['ai_agent']['voice_elevenlabs_model'] ),
				'frontend_test_enabled'     => rest_sanitize_boolean( $ai_agent['frontend_test_enabled'] ?? $defaults['ai_agent']['frontend_test_enabled'] ),
				'frontend_test_admin_only'  => rest_sanitize_boolean( $ai_agent['frontend_test_admin_only'] ?? $defaults['ai_agent']['frontend_test_admin_only'] ),
				'bot_knowledge_entries'     => self::sanitize_bot_knowledge_entries(
					is_array( $ai_agent['bot_knowledge_entries'] ?? null ) ? $ai_agent['bot_knowledge_entries'] : $defaults['ai_agent']['bot_knowledge_entries']
				),
				'allowed_tools'             => array_values(
					array_filter(
						array_map(
							static function ( $tool ): string {
								return sanitize_key( (string) $tool );
							},
							is_array( $ai_agent['allowed_tools'] ?? null ) ? $ai_agent['allowed_tools'] : array()
						)
					)
				),
				'guardrails'                => array_values(
					array_filter(
						array_map(
							static function ( $guardrail ): string {
								return sanitize_text_field( (string) $guardrail );
							},
							is_array( $ai_agent['guardrails'] ?? null ) ? $ai_agent['guardrails'] : array()
						)
					)
				),
			),
		);
	}

	/**
	 * Merge nested defaults.
	 *
	 * @param array<string, mixed> $defaults Defaults.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private static function merge_defaults( array $defaults, array $settings ): array {
		$merged = $defaults;

		foreach ( $settings as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) ) {
				$merged[ $key ] = self::merge_defaults( $defaults[ $key ], $value );
				continue;
			}

			$merged[ $key ] = $value;
		}

		return $merged;
	}

	/**
	 * Sanitize all reporting segments.
	 *
	 * @param array<int, mixed> $segments Raw segments.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_reporting_segments( array $segments ): array {
		$sanitized = array();

		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}

			$clean = self::sanitize_reporting_segment( $segment );

			if ( '' === $clean['name'] ) {
				continue;
			}

			$sanitized[] = $clean;
		}

		return $sanitized;
	}

	/**
	 * Sanitize a stored Connect import status payload.
	 *
	 * @param array<string, mixed> $status Raw status payload.
	 * @return array<string, mixed>
	 */
	private static function sanitize_connect_import_status( array $status ): array {
		$errors = is_array( $status['errors'] ?? null ) ? $status['errors'] : array();

		return array(
			'status'          => sanitize_key( (string) ( $status['status'] ?? 'idle' ) ),
			'started_at'      => sanitize_text_field( (string) ( $status['started_at'] ?? '' ) ),
			'completed_at'    => sanitize_text_field( (string) ( $status['completed_at'] ?? '' ) ),
			'lookback_hours'  => max( 0, absint( $status['lookback_hours'] ?? 0 ) ),
			'max_objects'     => max( 0, absint( $status['max_objects'] ?? 0 ) ),
			'objects_scanned' => max( 0, absint( $status['objects_scanned'] ?? 0 ) ),
			'records_found'   => max( 0, absint( $status['records_found'] ?? 0 ) ),
			'created'         => max( 0, absint( $status['created'] ?? 0 ) ),
			'updated'         => max( 0, absint( $status['updated'] ?? 0 ) ),
			'matched'         => max( 0, absint( $status['matched'] ?? 0 ) ),
			'number_matched'  => max( 0, absint( $status['number_matched'] ?? 0 ) ),
			'errors'          => array_values(
				array_slice(
					array_filter(
						array_map(
							static function ( $error ): string {
								return sanitize_text_field( (string) $error );
							},
							$errors
						)
					),
					0,
					20
				)
			),
		);
	}

	/**
	 * Sanitize a reporting segment.
	 *
	 * @param array<string, mixed> $segment Raw segment.
	 * @return array<string, mixed>
	 */
	private static function sanitize_reporting_segment( array $segment ): array {
		$view    = sanitize_key( (string) ( $segment['view'] ?? 'sessions' ) );
		$filters = isset( $segment['filters'] ) && is_array( $segment['filters'] ) ? $segment['filters'] : array();
		$view    = in_array( $view, array( 'sessions', 'companies', 'calls', 'chats', 'commerce' ), true ) ? $view : 'sessions';

		$sanitized_filters = array(
			'search'             => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
			'confidence'         => sanitize_key( (string) ( $filters['confidence'] ?? '' ) ),
			'source'             => sanitize_text_field( (string) ( $filters['source'] ?? '' ) ),
			'provider'           => sanitize_text_field( (string) ( $filters['provider'] ?? '' ) ),
			'model'              => sanitize_text_field( (string) ( $filters['model'] ?? '' ) ),
			'status'             => sanitize_text_field( (string) ( $filters['status'] ?? '' ) ),
			'commercial_status'  => sanitize_text_field( (string) ( $filters['commercial_status'] ?? '' ) ),
			'commercial_outcome' => sanitize_text_field( (string) ( $filters['commercial_outcome'] ?? '' ) ),
			'priority'           => sanitize_text_field( (string) ( $filters['priority'] ?? '' ) ),
			'owner_user_id'      => absint( $filters['owner_user_id'] ?? 0 ) ? (string) absint( $filters['owner_user_id'] ?? 0 ) : '',
			'date_from'          => sanitize_text_field( (string) ( $filters['date_from'] ?? '' ) ),
			'date_to'            => sanitize_text_field( (string) ( $filters['date_to'] ?? '' ) ),
			'match_only'         => rest_sanitize_boolean( $filters['match_only'] ?? false ) ? '1' : '',
			'repeat_only'        => rest_sanitize_boolean( $filters['repeat_only'] ?? false ) ? '1' : '',
			'due_only'           => rest_sanitize_boolean( $filters['due_only'] ?? false ) ? '1' : '',
			'connect_import_only'=> rest_sanitize_boolean( $filters['connect_import_only'] ?? false ) ? '1' : '',
		);

		return array(
			'id'         => sanitize_text_field( (string) ( $segment['id'] ?? wp_generate_uuid4() ) ),
			'name'       => sanitize_text_field( (string) ( $segment['name'] ?? '' ) ),
			'view'       => $view,
			'filters'    => array_filter(
				$sanitized_filters,
				static function ( string $value ): bool {
					return '' !== $value;
				}
			),
			'created_at' => sanitize_text_field( (string) ( $segment['created_at'] ?? current_time( 'mysql', true ) ) ),
		);
	}

	/**
	 * Sanitize stored bot knowledge entries.
	 *
	 * @param array<int, mixed> $entries Raw entries.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_bot_knowledge_entries( array $entries ): array {
		$sanitized = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$question = sanitize_text_field( (string) ( $entry['question'] ?? '' ) );
			$answer   = sanitize_textarea_field( (string) ( $entry['answer'] ?? '' ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$sanitized[] = array(
				'id'           => sanitize_text_field( (string) ( $entry['id'] ?? wp_generate_uuid4() ) ),
				'question'     => $question,
				'answer'       => $answer,
				'source_type'  => sanitize_key( (string) ( $entry['source_type'] ?? 'manual' ) ),
				'source_id'    => absint( $entry['source_id'] ?? 0 ),
				'source_label' => sanitize_text_field( (string) ( $entry['source_label'] ?? '' ) ),
				'url'          => esc_url_raw( (string) ( $entry['url'] ?? '' ) ),
				'enabled'      => ! array_key_exists( 'enabled', $entry ) || rest_sanitize_boolean( $entry['enabled'] ),
			);

			if ( count( $sanitized ) >= 25 ) {
				break;
			}
		}

		return $sanitized;
	}
}
