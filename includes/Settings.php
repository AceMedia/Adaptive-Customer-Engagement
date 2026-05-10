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
				'mode'                       => 'off',
				'provider'                   => 'amazon_q_connect',
				'assistant_id'               => '',
				'assistant_arn'              => '',
				'handoff_to_human'           => true,
				'share_session_context'      => true,
				'share_company_context'      => true,
				'share_number_context'       => true,
				'share_woocommerce_context'  => false,
				'session_summary_attribute'  => 'ace_session_summary',
				'company_summary_attribute'  => 'ace_company_summary',
				'number_summary_attribute'   => 'ace_number_summary',
				'context_instructions'       => '',
				'frontend_test_enabled'      => false,
				'frontend_test_admin_only'   => true,
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
				'mode'                      => sanitize_key( $ai_agent['mode'] ?? $defaults['ai_agent']['mode'] ),
				'provider'                  => 'amazon_q_connect',
				'assistant_id'              => sanitize_text_field( $ai_agent['assistant_id'] ?? $defaults['ai_agent']['assistant_id'] ),
				'assistant_arn'             => sanitize_text_field( $ai_agent['assistant_arn'] ?? $defaults['ai_agent']['assistant_arn'] ),
				'handoff_to_human'          => rest_sanitize_boolean( $ai_agent['handoff_to_human'] ?? $defaults['ai_agent']['handoff_to_human'] ),
				'share_session_context'     => rest_sanitize_boolean( $ai_agent['share_session_context'] ?? $defaults['ai_agent']['share_session_context'] ),
				'share_company_context'     => rest_sanitize_boolean( $ai_agent['share_company_context'] ?? $defaults['ai_agent']['share_company_context'] ),
				'share_number_context'      => rest_sanitize_boolean( $ai_agent['share_number_context'] ?? $defaults['ai_agent']['share_number_context'] ),
				'share_woocommerce_context' => rest_sanitize_boolean( $ai_agent['share_woocommerce_context'] ?? $defaults['ai_agent']['share_woocommerce_context'] ),
				'session_summary_attribute' => sanitize_key( $ai_agent['session_summary_attribute'] ?? $defaults['ai_agent']['session_summary_attribute'] ),
				'company_summary_attribute' => sanitize_key( $ai_agent['company_summary_attribute'] ?? $defaults['ai_agent']['company_summary_attribute'] ),
				'number_summary_attribute'  => sanitize_key( $ai_agent['number_summary_attribute'] ?? $defaults['ai_agent']['number_summary_attribute'] ),
				'context_instructions'      => sanitize_textarea_field( $ai_agent['context_instructions'] ?? $defaults['ai_agent']['context_instructions'] ),
				'frontend_test_enabled'     => rest_sanitize_boolean( $ai_agent['frontend_test_enabled'] ?? $defaults['ai_agent']['frontend_test_enabled'] ),
				'frontend_test_admin_only'  => rest_sanitize_boolean( $ai_agent['frontend_test_admin_only'] ?? $defaults['ai_agent']['frontend_test_admin_only'] ),
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
		$view    = in_array( $view, array( 'sessions', 'companies', 'calls', 'commerce' ), true ) ? $view : 'sessions';

		$sanitized_filters = array(
			'search'     => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
			'confidence' => sanitize_key( (string) ( $filters['confidence'] ?? '' ) ),
			'source'     => sanitize_text_field( (string) ( $filters['source'] ?? '' ) ),
			'provider'   => sanitize_text_field( (string) ( $filters['provider'] ?? '' ) ),
			'status'     => sanitize_text_field( (string) ( $filters['status'] ?? '' ) ),
			'date_from'  => sanitize_text_field( (string) ( $filters['date_from'] ?? '' ) ),
			'date_to'    => sanitize_text_field( (string) ( $filters['date_to'] ?? '' ) ),
			'match_only' => rest_sanitize_boolean( $filters['match_only'] ?? false ) ? '1' : '',
			'repeat_only'=> rest_sanitize_boolean( $filters['repeat_only'] ?? false ) ? '1' : '',
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
}
