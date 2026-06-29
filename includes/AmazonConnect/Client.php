<?php
/**
 * Minimal signed AWS client for Amazon Connect and Amazon Q in Connect.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AmazonConnect;

use ACE\AdaptiveCustomerEngagement\AI\SiteContextService;
use ACE\AdaptiveCustomerEngagement\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Client {
	/**
	 * Build a configuration error if Connect is not ready.
	 *
	 * @return array<string, string>|WP_Error
	 */
	private function get_config( array $override = array() ) {
		$settings = Settings::get();
		$config   = isset( $settings['amazon_connect'] ) && is_array( $settings['amazon_connect'] ) ? $settings['amazon_connect'] : array();

		if ( ! empty( $override ) ) {
			$config = array_merge( $config, $override );
		}
		$region   = sanitize_text_field( (string) ( $config['region'] ?? '' ) );

		if ( '' === $region ) {
			return new WP_Error( 'ace_connect_region_missing', __( 'Please save the AWS region first.', 'adaptive-customer-engagement' ) );
		}
		$credentials = $this->resolve_credentials( $config );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		return array(
			'region'            => $region,
			'instance_id'       => sanitize_text_field( (string) ( $config['instance_id'] ?? '' ) ),
			'access_key_id'     => sanitize_text_field( (string) $credentials['access_key_id'] ),
			'secret_access_key' => (string) $credentials['secret_access_key'],
			'session_token'     => sanitize_text_field( (string) ( $credentials['session_token'] ?? '' ) ),
		);
	}

	/**
	 * Resolve AWS credentials, preferring saved access keys and falling back to IAM role credentials.
	 *
	 * @param array<string, mixed> $config Saved config.
	 * @return array<string, string>|WP_Error
	 */
	private function resolve_credentials( array $config ) {
		$access_key_id     = sanitize_text_field( (string) ( $config['access_key_id'] ?? '' ) );
		$secret_access_key = (string) ( $config['secret_access_key'] ?? '' );

		if ( '' !== $access_key_id && '' !== $secret_access_key ) {
			return array(
				'access_key_id'     => $access_key_id,
				'secret_access_key' => $secret_access_key,
				'session_token'     => sanitize_text_field( (string) ( $config['session_token'] ?? '' ) ),
			);
		}

		if ( ! empty( $config['use_iam_role'] ) ) {
			return $this->get_iam_role_credentials();
		}

		return new WP_Error( 'ace_connect_credentials_missing', __( 'Please save AWS access keys, or enable IAM role fallback on an AWS-hosted environment.', 'adaptive-customer-engagement' ) );
	}

	/**
	 * Resolve temporary AWS credentials from the host IAM role.
	 *
	 * @return array<string, string>|WP_Error
	 */
	private function get_iam_role_credentials() {
		$ecs_relative_uri = getenv( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI' );
		$ecs_full_uri     = getenv( 'AWS_CONTAINER_CREDENTIALS_FULL_URI' );

		if ( is_string( $ecs_relative_uri ) && '' !== $ecs_relative_uri ) {
			$credentials = $this->request_metadata_credentials( 'http://169.254.170.2' . $ecs_relative_uri );

			if ( ! is_wp_error( $credentials ) ) {
				return $credentials;
			}
		}

		if ( is_string( $ecs_full_uri ) && '' !== $ecs_full_uri && $this->is_supported_container_credentials_uri( $ecs_full_uri ) ) {
			$credentials = $this->request_metadata_credentials( $ecs_full_uri );

			if ( ! is_wp_error( $credentials ) ) {
				return $credentials;
			}
		}

		$token_response = wp_remote_request(
			'http://169.254.169.254/latest/api/token',
			array(
				'method'      => 'PUT',
				'timeout'     => 2,
				'redirection' => 0,
				'headers'     => array(
					'X-aws-ec2-metadata-token-ttl-seconds' => '21600',
				),
			)
		);

		if ( is_wp_error( $token_response ) || 200 !== (int) wp_remote_retrieve_response_code( $token_response ) ) {
			return new WP_Error( 'ace_connect_iam_role_unavailable', __( 'IAM role credentials could not be resolved from the host. Saved access keys will work immediately, or the site needs working AWS metadata access.', 'adaptive-customer-engagement' ) );
		}

		$token = trim( (string) wp_remote_retrieve_body( $token_response ) );

		if ( '' === $token ) {
			return new WP_Error( 'ace_connect_iam_role_token_missing', __( 'The host returned an empty IAM metadata token.', 'adaptive-customer-engagement' ) );
		}

		$role_response = wp_remote_get(
			'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
			array(
				'timeout'     => 2,
				'redirection' => 0,
				'headers'     => array(
					'X-aws-ec2-metadata-token' => $token,
				),
			)
		);

		if ( is_wp_error( $role_response ) || 200 !== (int) wp_remote_retrieve_response_code( $role_response ) ) {
			return new WP_Error( 'ace_connect_iam_role_name_missing', __( 'The host IAM role name could not be read from AWS metadata.', 'adaptive-customer-engagement' ) );
		}

		$role_name_lines = preg_split( '/\r\n|\r|\n/', trim( (string) wp_remote_retrieve_body( $role_response ) ) );
		$role_name       = sanitize_text_field( (string) ( $role_name_lines[0] ?? '' ) );

		if ( '' === $role_name ) {
			return new WP_Error( 'ace_connect_iam_role_name_empty', __( 'The host IAM role metadata did not include a role name.', 'adaptive-customer-engagement' ) );
		}

		return $this->request_metadata_credentials(
			'http://169.254.169.254/latest/meta-data/iam/security-credentials/' . rawurlencode( $role_name ),
			array(
				'X-aws-ec2-metadata-token' => $token,
			)
		);
	}

	/**
	 * Read AWS credentials JSON from a metadata endpoint.
	 *
	 * @param string               $url     Metadata URL.
	 * @param array<string,string> $headers Optional headers.
	 * @return array<string, string>|WP_Error
	 */
	private function request_metadata_credentials( string $url, array $headers = array() ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 2,
				'redirection' => 0,
				'headers'     => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'ace_connect_iam_role_request_failed', __( 'The host IAM credential endpoint returned an unexpected response.', 'adaptive-customer-engagement' ) );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ace_connect_iam_role_invalid_payload', __( 'The host IAM credential payload could not be parsed.', 'adaptive-customer-engagement' ) );
		}

		$access_key_id     = sanitize_text_field( (string) ( $data['AccessKeyId'] ?? '' ) );
		$secret_access_key = (string) ( $data['SecretAccessKey'] ?? '' );
		$session_token     = sanitize_text_field( (string) ( $data['Token'] ?? '' ) );

		if ( '' === $access_key_id || '' === $secret_access_key || '' === $session_token ) {
			return new WP_Error( 'ace_connect_iam_role_credentials_incomplete', __( 'The host IAM role did not return a complete temporary credential set.', 'adaptive-customer-engagement' ) );
		}

		return array(
			'access_key_id'     => $access_key_id,
			'secret_access_key' => $secret_access_key,
			'session_token'     => $session_token,
		);
	}

	/**
	 * Check whether a container credentials URI is safe to call directly.
	 *
	 * @param string $url Candidate credentials URI.
	 * @return bool
	 */
	private function is_supported_container_credentials_uri( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return in_array( $host, array( '127.0.0.1', 'localhost', '169.254.170.2' ), true );
	}

	/**
	 * Get the configured instance ID.
	 *
	 * @return string
	 */
	public function get_instance_id(): string {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return '';
		}

		return $config['instance_id'];
	}

	/**
	 * Ensure a dedicated IAM role exists for Lex bot creation.
	 *
	 * @param string $role_name Optional role name override.
	 * @return array<string, mixed>|WP_Error
	 */
	public function ensure_lex_bot_role( string $role_name = '' ) {
		$role_name = $this->sanitize_iam_role_name( $role_name );

		if ( '' === $role_name ) {
			$role_name = $this->build_default_lex_bot_role_name();
		}

		$role = $this->create_iam_role(
			$role_name,
			array(
				'Version'   => '2012-10-17',
				'Statement' => array(
					array(
						'Effect'    => 'Allow',
						'Principal' => array(
							'Service' => 'lexv2.amazonaws.com',
						),
						'Action'    => 'sts:AssumeRole',
					),
				),
			),
			__( 'Adaptive Customer Engagement managed Amazon Lex V2 bot role.', 'adaptive-customer-engagement' )
		);
		$created = ! is_wp_error( $role );

		if ( is_wp_error( $role ) ) {
			if ( ! $this->is_iam_error_code( $role, 'EntityAlreadyExists' ) ) {
				return $role;
			}

			$role = $this->get_iam_role( $role_name );

			if ( is_wp_error( $role ) ) {
				return $role;
			}
		}

		$policy = $this->put_iam_role_policy(
			$role_name,
			'AdaptiveCustomerEngagementLexBotBase',
			array(
				'Version'   => '2012-10-17',
				'Statement' => array(
					array(
						'Effect'   => 'Allow',
						'Action'   => array(
							'logs:CreateLogGroup',
							'logs:CreateLogStream',
							'logs:PutLogEvents',
						),
						'Resource' => '*',
					),
					array(
						'Effect'   => 'Allow',
						'Action'   => array(
							'polly:SynthesizeSpeech',
						),
						'Resource' => '*',
					),
				),
			)
		);

		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		$role_arn = sanitize_text_field( (string) ( $role['Arn'] ?? '' ) );

		if ( '' === $role_arn ) {
			return new WP_Error( 'ace_connect_lex_role_missing_arn', __( 'The IAM role was prepared, but AWS did not return a usable role ARN.', 'adaptive-customer-engagement' ) );
		}

		return array(
			'role_name'   => $role_name,
			'role_arn'    => $role_arn,
			'created'     => $created,
			'policy_name' => 'AdaptiveCustomerEngagementLexBotBase',
		);
	}

	/**
	 * Ensure a dedicated IAM role exists for the plugin-managed Lex runtime Lambda.
	 *
	 * @param string $role_name Optional role name override.
	 * @return array<string, mixed>|WP_Error
	 */
	public function ensure_lex_runtime_role( string $role_name = '' ) {
		$role_name = $this->sanitize_iam_role_name( $role_name );

		if ( '' === $role_name ) {
			$role_name = $this->build_default_lex_runtime_role_name();
		}

		$role = $this->create_iam_role(
			$role_name,
			array(
				'Version'   => '2012-10-17',
				'Statement' => array(
					array(
						'Effect'    => 'Allow',
						'Principal' => array(
							'Service' => 'lambda.amazonaws.com',
						),
						'Action'    => 'sts:AssumeRole',
					),
				),
			),
			__( 'Adaptive Customer Engagement managed Lex runtime Lambda role.', 'adaptive-customer-engagement' )
		);
		$created = ! is_wp_error( $role );

		if ( is_wp_error( $role ) ) {
			if ( ! $this->is_iam_error_code( $role, 'EntityAlreadyExists' ) ) {
				return $role;
			}

			$role = $this->get_iam_role( $role_name );

			if ( is_wp_error( $role ) ) {
				return $role;
			}
		}

		$policy = $this->put_iam_role_policy(
			$role_name,
			'AdaptiveCustomerEngagementLexRuntimeBase',
			array(
				'Version'   => '2012-10-17',
				'Statement' => array(
					array(
						'Effect'   => 'Allow',
						'Action'   => array(
							'logs:CreateLogGroup',
							'logs:CreateLogStream',
							'logs:PutLogEvents',
						),
						'Resource' => '*',
					),
				),
			)
		);

		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		$role_arn = sanitize_text_field( (string) ( $role['Arn'] ?? '' ) );

		if ( '' === $role_arn ) {
			return new WP_Error( 'ace_connect_lex_runtime_role_missing_arn', __( 'The Lambda runtime role was prepared, but AWS did not return a usable role ARN.', 'adaptive-customer-engagement' ) );
		}

		return array(
			'role_name'   => $role_name,
			'role_arn'    => $role_arn,
			'created'     => $created,
			'policy_name' => 'AdaptiveCustomerEngagementLexRuntimeBase',
		);
	}

	/**
	 * List visible Amazon Connect instances.
	 *
	 * @param array<string, mixed> $config_override Optional config override.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_instances( array $config_override = array() ) {
		$config = $this->get_config( $config_override );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$items      = array();
		$next_token = '';

		do {
			$query = array(
				'maxResults' => 10,
			);

			if ( '' !== $next_token ) {
				$query['nextToken'] = $next_token;
			}

			$response = $this->request_with_config( $config, 'connect', 'GET', '/instance', array(), $query );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$batch = isset( $response['InstanceSummaryList'] ) && is_array( $response['InstanceSummaryList'] ) ? $response['InstanceSummaryList'] : array();
			$items = array_merge( $items, $batch );
			$next_token = sanitize_text_field( (string) ( $response['NextToken'] ?? '' ) );
		} while ( '' !== $next_token );

		return $items;
	}

	/**
	 * Describe a Connect instance.
	 *
	 * @param string               $instance_id      Instance ID.
	 * @param array<string, mixed> $config_override Optional config override.
	 * @return array<string, mixed>|WP_Error
	 */
	public function describe_instance( string $instance_id, array $config_override = array() ) {
		$instance_id = sanitize_text_field( $instance_id );

		if ( '' === $instance_id ) {
			return new WP_Error( 'ace_connect_instance_id_missing', __( 'Please provide the Amazon Connect instance ID first.', 'adaptive-customer-engagement' ) );
		}

		$config = $this->get_config( $config_override );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$response = $this->request_with_config( $config, 'connect', 'GET', '/instance/' . rawurlencode( $instance_id ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['Instance'] ) && is_array( $response['Instance'] ) ? $response['Instance'] : array();
	}

	/**
	 * List Lex V2 bots associated with the saved Connect instance.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_connect_lex_v2_bots() {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$instance_id = sanitize_text_field( (string) ( $config['instance_id'] ?? '' ) );

		if ( '' === $instance_id ) {
			return new WP_Error( 'ace_connect_instance_id_missing', __( 'Please provide the Amazon Connect instance ID first.', 'adaptive-customer-engagement' ) );
		}

		$items      = array();
		$next_token = '';

		do {
			$query = array(
				'lexVersion' => 'V2',
				'maxResults' => 25,
			);

			if ( '' !== $next_token ) {
				$query['nextToken'] = $next_token;
			}

			$response = $this->request_with_config( $config, 'connect', 'GET', '/instance/' . rawurlencode( $instance_id ) . '/bots', array(), $query );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$batch      = isset( $response['LexBots'] ) && is_array( $response['LexBots'] ) ? $response['LexBots'] : array();
			$items      = array_merge( $items, $batch );
			$next_token = sanitize_text_field( (string) ( $response['NextToken'] ?? '' ) );
		} while ( '' !== $next_token );

		return $items;
	}

	/**
	 * Disassociate a Lex V2 bot alias from the saved Connect instance.
	 *
	 * @param string $alias_arn Lex V2 bot alias ARN.
	 * @return true|WP_Error
	 */
	public function disassociate_lex_v2_bot( string $alias_arn ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$instance_id = sanitize_text_field( (string) ( $config['instance_id'] ?? '' ) );
		$alias_arn   = sanitize_text_field( $alias_arn );

		if ( '' === $instance_id ) {
			return new WP_Error( 'ace_connect_instance_id_missing', __( 'Please provide the Amazon Connect instance ID first.', 'adaptive-customer-engagement' ) );
		}

		if ( '' === $alias_arn ) {
			return new WP_Error( 'ace_connect_lex_alias_arn_missing', __( 'Please provide the Amazon Lex V2 alias ARN first.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'connect',
			'POST',
			'/instance/' . rawurlencode( $instance_id ) . '/bot',
			array(
				'ClientToken' => wp_generate_uuid4(),
				'LexV2Bot'    => array(
					'AliasArn' => $alias_arn,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Delete a Lex V2 bot.
	 *
	 * @param string $bot_id                      Bot ID.
	 * @param bool   $skip_resource_in_use_check  Whether to skip alias checks.
	 * @return array<string, mixed>|WP_Error
	 */
	public function delete_lex_bot( string $bot_id, bool $skip_resource_in_use_check = true ) {
		$bot_id = sanitize_text_field( $bot_id );

		if ( '' === $bot_id ) {
			return new WP_Error( 'ace_connect_lex_bot_missing', __( 'Please provide the Amazon Lex bot ID first.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'lex',
			'DELETE',
			'/bots/' . rawurlencode( $bot_id ) . '/',
			array(),
			array(
				'skipResourceInUseCheck' => $skip_resource_in_use_check ? 'true' : 'false',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * List claimed Connect phone numbers.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_phone_numbers() {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$response = $this->request(
			'connect',
			'POST',
			'/phone-number/list',
			array(
				'InstanceId' => $config['instance_id'],
				'MaxResults' => 100,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['ListPhoneNumbersSummaryList'] ) && is_array( $response['ListPhoneNumbersSummaryList'] ) ? $response['ListPhoneNumbersSummaryList'] : array();
	}

	/**
	 * Search available Connect phone numbers.
	 *
	 * @param string $country_code Country code.
	 * @param string $type         Number type.
	 * @param string $prefix       Optional prefix.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function search_available_phone_numbers( string $country_code, string $type, string $prefix = '' ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$payload = array(
			'InstanceId'             => $config['instance_id'],
			'PhoneNumberCountryCode' => strtoupper( $country_code ),
			'PhoneNumberType'        => strtoupper( $type ),
			'MaxResults'             => 10,
		);

		if ( '' !== $prefix ) {
			$payload['PhoneNumberPrefix'] = $prefix;
		}

		$response = $this->request( 'connect', 'POST', '/phone-number/search-available', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['AvailableNumbersList'] ) && is_array( $response['AvailableNumbersList'] ) ? $response['AvailableNumbersList'] : array();
	}

	/**
	 * Claim a Connect phone number.
	 *
	 * @param string $phone_number Claimed number in E.164.
	 * @param string $description  Optional description.
	 * @return array<string, mixed>|WP_Error
	 */
	public function claim_phone_number( string $phone_number, string $description = '' ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$response = $this->request(
			'connect',
			'POST',
			'/phone-number/claim',
			array(
				'ClientToken'            => wp_generate_uuid4(),
				'InstanceId'             => $config['instance_id'],
				'PhoneNumber'            => $phone_number,
				'PhoneNumberDescription' => $description,
				'Tags'                   => array(
					'managed-by' => 'adaptive-customer-engagement',
					'environment' => 'wordpress-plugin',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$phone_number_id = isset( $response['PhoneNumberId'] ) ? sanitize_text_field( (string) $response['PhoneNumberId'] ) : '';

		if ( '' === $phone_number_id ) {
			return new WP_Error( 'ace_connect_claim_failed', __( 'Amazon Connect did not return a phone number ID.', 'adaptive-customer-engagement' ) );
		}

		return $this->describe_phone_number( $phone_number_id );
	}

	/**
	 * Describe a claimed phone number.
	 *
	 * @param string $phone_number_id Phone number ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function describe_phone_number( string $phone_number_id ) {
		$response = $this->request( 'connect', 'GET', '/phone-number/' . rawurlencode( $phone_number_id ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['ClaimedPhoneNumberSummary'] ) && is_array( $response['ClaimedPhoneNumberSummary'] ) ? $response['ClaimedPhoneNumberSummary'] : array();
	}

	/**
	 * Release a claimed Connect phone number (irreversible).
	 *
	 * @param string $phone_number_id Phone number ID.
	 * @return true|WP_Error
	 */
	public function release_phone_number( string $phone_number_id ) {
		$phone_number_id = sanitize_text_field( $phone_number_id );

		if ( '' === $phone_number_id ) {
			return new WP_Error( 'ace_connect_release_failed', __( 'A phone number ID is required to release a number.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'connect',
			'DELETE',
			'/phone-number/' . rawurlencode( $phone_number_id ),
			array(),
			array( 'clientToken' => wp_generate_uuid4() )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * List contact flows for the configured instance.
	 *
	 * @param array<int, string> $types Optional flow types to keep.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_contact_flows( array $types = array() ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$response = $this->request(
			'connect',
			'GET',
			'/contact-flows-summary/' . rawurlencode( $config['instance_id'] ),
			array(),
			array(
				'maxResults' => 1000,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = isset( $response['ContactFlowSummaryList'] ) && is_array( $response['ContactFlowSummaryList'] ) ? $response['ContactFlowSummaryList'] : array();

		if ( empty( $types ) ) {
			return $items;
		}

		$allowed_types = array_map( 'sanitize_text_field', $types );

		return array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $allowed_types ): bool {
					$type = sanitize_text_field( (string) ( $item['ContactFlowType'] ?? '' ) );

					return in_array( $type, $allowed_types, true );
				}
			)
		);
	}

	/**
	 * List standard queues for the configured instance.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_queues() {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$response = $this->request(
			'connect',
			'GET',
			'/queues-summary/' . rawurlencode( $config['instance_id'] ),
			array(),
			array(
				'maxResults' => 1000,
				'queueTypes' => 'STANDARD',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['QueueSummaryList'] ) && is_array( $response['QueueSummaryList'] ) ? $response['QueueSummaryList'] : array();
	}

	/**
	 * Describe a contact flow.
	 *
	 * @param string $contact_flow_id Contact flow ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function describe_contact_flow( string $contact_flow_id ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$response = $this->request(
			'connect',
			'GET',
			'/contact-flows/' . rawurlencode( $config['instance_id'] ) . '/' . rawurlencode( $contact_flow_id )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['ContactFlow'] ) && is_array( $response['ContactFlow'] ) ? $response['ContactFlow'] : array();
	}

	/**
	 * Create a contact flow in Amazon Connect.
	 *
	 * @param string $name        Flow name.
	 * @param string $description Optional description.
	 * @param string $content     Flow language JSON content.
	 * @param string $type        Flow type.
	 * @param string $status      Flow status.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_contact_flow( string $name, string $description, string $content, string $type = 'CONTACT_FLOW', string $status = 'PUBLISHED' ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$response = $this->request(
			'connect',
			'PUT',
			'/contact-flows/' . rawurlencode( $config['instance_id'] ),
			array(
				'Name'        => $name,
				'Description' => $description,
				'Content'     => $content,
				'Type'        => $type,
				'Status'      => $status,
				'Tags'        => array(
					'managed-by'  => 'adaptive-customer-engagement',
					'environment' => 'wordpress-plugin',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$contact_flow_id = isset( $response['ContactFlowId'] ) ? sanitize_text_field( (string) $response['ContactFlowId'] ) : '';

		if ( '' === $contact_flow_id ) {
			return new WP_Error( 'ace_connect_contact_flow_create_failed', __( 'Amazon Connect did not return a contact flow ID.', 'adaptive-customer-engagement' ) );
		}

		return $this->describe_contact_flow( $contact_flow_id );
	}

	/**
	 * Associate a claimed phone number with a contact flow.
	 *
	 * @param string $phone_number_id Phone number ID or ARN.
	 * @param string $contact_flow_id Contact flow ID.
	 * @return true|WP_Error
	 */
	public function associate_phone_number_contact_flow( string $phone_number_id, string $contact_flow_id ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$response = $this->request(
			'connect',
			'PUT',
			'/phone-number/' . rawurlencode( $phone_number_id ) . '/contact-flow',
			array(
				'InstanceId'    => $config['instance_id'],
				'ContactFlowId' => $contact_flow_id,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Start an Amazon Connect chat contact against a specific contact flow.
	 *
	 * @param string               $contact_flow_id                 Contact flow ID.
	 * @param string               $display_name                    Participant display name.
	 * @param array<string,string> $attributes                      Optional contact attributes.
	 * @param array<int,string>    $supported_messaging_types       Optional supported content types.
	 * @param string               $client_token                    Optional idempotency token.
	 * @return array<string, mixed>|WP_Error
	 */
	public function start_chat_contact(
		string $contact_flow_id,
		string $display_name,
		array $attributes = array(),
		array $supported_messaging_types = array(),
		string $client_token = ''
	) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$contact_flow_id = sanitize_text_field( $contact_flow_id );
		$display_name    = sanitize_text_field( $display_name );
		$client_token    = sanitize_text_field( $client_token );
		$instance_id     = sanitize_text_field( (string) $config['instance_id'] );

		if ( '' === $instance_id ) {
			return new WP_Error( 'ace_connect_instance_missing', __( 'Please save the Amazon Connect instance before starting chat.', 'adaptive-customer-engagement' ) );
		}

		if ( '' === $contact_flow_id ) {
			return new WP_Error( 'ace_connect_chat_flow_missing', __( 'Please choose the Amazon Connect chat flow for the website widget first.', 'adaptive-customer-engagement' ) );
		}

		if ( '' === $display_name ) {
			$display_name = __( 'Website visitor', 'adaptive-customer-engagement' );
		}

		$sanitized_attributes = array();

		foreach ( $attributes as $key => $value ) {
			$attribute_key   = sanitize_key( (string) $key );
			$attribute_value = sanitize_text_field( (string) $value );

			if ( '' === $attribute_key ) {
				continue;
			}

			$sanitized_attributes[ $attribute_key ] = $attribute_value;
		}

		$allowed_content_types = array(
			'text/plain',
			'text/markdown',
			'application/json',
			'application/vnd.amazonaws.connect.message.interactive',
			'application/vnd.amazonaws.connect.message.interactive.response',
		);
		$supported_types       = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', $supported_messaging_types ),
					static function ( string $type ) use ( $allowed_content_types ): bool {
						return in_array( $type, $allowed_content_types, true );
					}
				)
			)
		);

		if ( ! in_array( 'text/plain', $supported_types, true ) ) {
			array_unshift( $supported_types, 'text/plain' );
		}

		$payload = array(
			'InstanceId'                     => $instance_id,
			'ContactFlowId'                  => $contact_flow_id,
			'ParticipantDetails'             => array(
				'DisplayName' => $display_name,
			),
			'Attributes'                     => $sanitized_attributes,
			'SupportedMessagingContentTypes' => $supported_types,
		);

		if ( '' !== $client_token ) {
			$payload['ClientToken'] = $client_token;
		}

		return $this->request( 'connect', 'PUT', '/contact/chat', $payload );
	}

	/**
	 * List Amazon Lex V2 bots visible to the saved credentials.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_lex_bots() {
		$items      = array();
		$next_token = '';

		do {
			$payload = array(
				'maxResults' => 100,
			);

			if ( '' !== $next_token ) {
				$payload['nextToken'] = $next_token;
			}

			$response = $this->request( 'lex', 'POST', '/bots/', $payload );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$batch      = isset( $response['botSummaries'] ) && is_array( $response['botSummaries'] ) ? $response['botSummaries'] : array();
			$items      = array_merge( $items, $batch );
			$next_token = sanitize_text_field( (string) ( $response['nextToken'] ?? '' ) );
		} while ( '' !== $next_token );

		return $items;
	}

	/**
	 * Describe an Amazon Lex V2 bot.
	 *
	 * @param string $bot_id Bot ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function describe_lex_bot( string $bot_id ) {
		$bot_id = sanitize_text_field( $bot_id );

		if ( '' === $bot_id ) {
			return new WP_Error( 'ace_connect_lex_bot_missing', __( 'Please provide the Amazon Lex bot ID first.', 'adaptive-customer-engagement' ) );
		}

		return $this->request( 'lex', 'GET', '/bots/' . rawurlencode( $bot_id ) . '/' );
	}

	/**
	 * Wait for a Lex bot to reach an expected status.
	 *
	 * @param string            $bot_id             Bot ID.
	 * @param array<int,string> $expected_statuses  Acceptable statuses.
	 * @param int               $max_attempts       Maximum poll attempts.
	 * @param int               $sleep_microseconds Delay between attempts.
	 * @return array<string, mixed>|WP_Error
	 */
	public function wait_for_lex_bot_status( string $bot_id, array $expected_statuses = array( 'Available' ), int $max_attempts = 10, int $sleep_microseconds = 2000000 ) {
		$bot_id            = sanitize_text_field( $bot_id );
		$expected_statuses = array_values(
			array_filter(
				array_map( 'sanitize_text_field', $expected_statuses )
			)
		);

		if ( '' === $bot_id ) {
			return new WP_Error( 'ace_connect_lex_bot_missing', __( 'Please provide the Amazon Lex bot ID first.', 'adaptive-customer-engagement' ) );
		}

		if ( empty( $expected_statuses ) ) {
			$expected_statuses = array( 'Available' );
		}

		$last_bot = array();

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$bot = $this->describe_lex_bot( $bot_id );

			if ( is_wp_error( $bot ) ) {
				return $bot;
			}

			$last_bot    = is_array( $bot ) ? $bot : array();
			$bot_status  = sanitize_text_field( (string) ( $last_bot['botStatus'] ?? '' ) );

			if ( in_array( $bot_status, $expected_statuses, true ) ) {
				return $last_bot;
			}

			if ( in_array( $bot_status, array( 'Failed', 'Deleting' ), true ) ) {
				return new WP_Error(
					'ace_connect_lex_bot_unavailable',
					sprintf(
						/* translators: %s: Amazon Lex bot status */
						__( 'The Amazon Lex bot could not be prepared because it entered the %s state.', 'adaptive-customer-engagement' ),
						$bot_status
					)
				);
			}

			if ( $attempt < ( $max_attempts - 1 ) ) {
				usleep( max( 0, $sleep_microseconds ) );
			}
		}

		return new WP_Error(
			'ace_connect_lex_bot_not_ready',
			sprintf(
				/* translators: %s: Amazon Lex bot status */
				__( 'Amazon Lex is still preparing the bot (%s). Please retry in a moment.', 'adaptive-customer-engagement' ),
				sanitize_text_field( (string) ( $last_bot['botStatus'] ?? __( 'unknown status', 'adaptive-customer-engagement' ) ) )
			)
		);
	}

	/**
	 * List aliases for a Lex V2 bot.
	 *
	 * @param string $bot_id Bot ID.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_lex_bot_aliases( string $bot_id ) {
		$bot_id = sanitize_text_field( $bot_id );

		if ( '' === $bot_id ) {
			return new WP_Error( 'ace_connect_lex_bot_missing', __( 'Please provide the Amazon Lex bot ID first.', 'adaptive-customer-engagement' ) );
		}

		$items      = array();
		$next_token = '';

		do {
			$payload = array(
				'maxResults' => 100,
			);

			if ( '' !== $next_token ) {
				$payload['nextToken'] = $next_token;
			}

			$response = $this->request( 'lex', 'POST', '/bots/' . rawurlencode( $bot_id ) . '/botaliases/', $payload );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$batch      = isset( $response['botAliasSummaries'] ) && is_array( $response['botAliasSummaries'] ) ? $response['botAliasSummaries'] : array();
			$items      = array_merge( $items, $batch );
			$next_token = sanitize_text_field( (string) ( $response['nextToken'] ?? '' ) );
		} while ( '' !== $next_token );

		return $items;
	}

	/**
	 * Associate a Lex V2 bot alias with the saved Connect instance.
	 *
	 * @param string $alias_arn Lex V2 bot alias ARN.
	 * @return true|WP_Error
	 */
	public function associate_lex_v2_bot( string $alias_arn ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$instance_id = sanitize_text_field( (string) ( $config['instance_id'] ?? '' ) );
		$alias_arn   = sanitize_text_field( $alias_arn );

		if ( '' === $instance_id ) {
			return new WP_Error( 'ace_connect_instance_id_missing', __( 'Please provide the Amazon Connect instance ID first.', 'adaptive-customer-engagement' ) );
		}

		if ( '' === $alias_arn ) {
			return new WP_Error( 'ace_connect_lex_alias_arn_missing', __( 'Please provide the Amazon Lex V2 alias ARN first.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'connect',
			'PUT',
			'/instance/' . rawurlencode( $instance_id ) . '/bot',
			array(
				'ClientToken' => wp_generate_uuid4(),
				'LexV2Bot'    => array(
					'AliasArn' => $alias_arn,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * List locales for a Lex V2 bot.
	 *
	 * @param string $bot_id      Bot ID.
	 * @param string $bot_version Bot version.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_lex_bot_locales( string $bot_id, string $bot_version = 'DRAFT' ) {
		$bot_id      = sanitize_text_field( $bot_id );
		$bot_version = sanitize_text_field( $bot_version );

		if ( '' === $bot_id ) {
			return new WP_Error( 'ace_connect_lex_bot_missing', __( 'Please provide the Amazon Lex bot ID first.', 'adaptive-customer-engagement' ) );
		}

		if ( '' === $bot_version ) {
			$bot_version = 'DRAFT';
		}

		$items      = array();
		$next_token = '';

		do {
			$payload = array(
				'maxResults' => 100,
			);

			if ( '' !== $next_token ) {
				$payload['nextToken'] = $next_token;
			}

			$response = $this->request( 'lex', 'POST', '/bots/' . rawurlencode( $bot_id ) . '/botversions/' . rawurlencode( $bot_version ) . '/botlocales/', $payload );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$batch      = isset( $response['botLocaleSummaries'] ) && is_array( $response['botLocaleSummaries'] ) ? $response['botLocaleSummaries'] : array();
			$items      = array_merge( $items, $batch );
			$next_token = sanitize_text_field( (string) ( $response['nextToken'] ?? '' ) );
		} while ( '' !== $next_token );

		return $items;
	}

	/**
	 * Describe a Lex V2 bot locale.
	 *
	 * @param string $bot_id      Bot ID.
	 * @param string $locale_id   Locale ID.
	 * @param string $bot_version Bot version.
	 * @return array<string, mixed>|WP_Error
	 */
	public function describe_lex_bot_locale( string $bot_id, string $locale_id, string $bot_version = 'DRAFT' ) {
		$bot_id      = sanitize_text_field( $bot_id );
		$locale_id   = sanitize_text_field( $locale_id );
		$bot_version = sanitize_text_field( $bot_version );

		if ( '' === $bot_id || '' === $locale_id ) {
			return new WP_Error( 'ace_connect_lex_bot_locale_missing', __( 'Please provide the Amazon Lex bot and locale first.', 'adaptive-customer-engagement' ) );
		}

		if ( '' === $bot_version ) {
			$bot_version = 'DRAFT';
		}

		return $this->request( 'lex', 'GET', '/bots/' . rawurlencode( $bot_id ) . '/botversions/' . rawurlencode( $bot_version ) . '/botlocales/' . rawurlencode( $locale_id ) . '/' );
	}

	/**
	 * Wait for a Lex bot locale to reach an expected status.
	 *
	 * @param string            $bot_id             Bot ID.
	 * @param string            $locale_id          Locale ID.
	 * @param array<int,string> $expected_statuses  Acceptable statuses.
	 * @param int               $max_attempts       Maximum poll attempts.
	 * @param int               $sleep_microseconds Delay between attempts.
	 * @param string            $bot_version        Bot version.
	 * @return array<string, mixed>|WP_Error
	 */
	public function wait_for_lex_bot_locale_status( string $bot_id, string $locale_id, array $expected_statuses = array( 'NotBuilt', 'Built' ), int $max_attempts = 10, int $sleep_microseconds = 2000000, string $bot_version = 'DRAFT' ) {
		$last_locale = array();

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$locale = $this->describe_lex_bot_locale( $bot_id, $locale_id, $bot_version );

			if ( is_wp_error( $locale ) ) {
				return $locale;
			}

			$last_locale    = is_array( $locale ) ? $locale : array();
			$locale_status  = sanitize_text_field( (string) ( $last_locale['botLocaleStatus'] ?? '' ) );

			if ( in_array( $locale_status, $expected_statuses, true ) ) {
				return $last_locale;
			}

			if ( in_array( $locale_status, array( 'Failed', 'Deleting' ), true ) ) {
				return new WP_Error(
					'ace_connect_lex_bot_locale_unavailable',
					sprintf(
						/* translators: %s: Amazon Lex bot locale status */
						__( 'The Amazon Lex bot locale could not be prepared because it entered the %s state.', 'adaptive-customer-engagement' ),
						$locale_status
					)
				);
			}

			if ( $attempt < ( $max_attempts - 1 ) ) {
				usleep( max( 0, $sleep_microseconds ) );
			}
		}

		return new WP_Error(
			'ace_connect_lex_bot_locale_not_ready',
			sprintf(
				/* translators: %s: Amazon Lex bot locale status */
				__( 'Amazon Lex is still preparing the bot locale (%s). Please retry in a moment.', 'adaptive-customer-engagement' ),
				sanitize_text_field( (string) ( $last_locale['botLocaleStatus'] ?? __( 'unknown status', 'adaptive-customer-engagement' ) ) )
			)
		);
	}

	/**
	 * Describe a Lex V2 bot alias.
	 *
	 * @param string $bot_id      Bot ID.
	 * @param string $bot_alias_id Alias ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function describe_lex_bot_alias( string $bot_id, string $bot_alias_id ) {
		$bot_id       = sanitize_text_field( $bot_id );
		$bot_alias_id = sanitize_text_field( $bot_alias_id );

		if ( '' === $bot_id || '' === $bot_alias_id ) {
			return new WP_Error( 'ace_connect_lex_bot_alias_missing', __( 'Please provide the Amazon Lex bot and alias first.', 'adaptive-customer-engagement' ) );
		}

		return $this->request( 'lex', 'GET', '/bots/' . rawurlencode( $bot_id ) . '/botaliases/' . rawurlencode( $bot_alias_id ) . '/' );
	}

	/**
	 * Wait for a Lex bot alias to reach an expected status.
	 *
	 * @param string            $bot_id            Bot ID.
	 * @param string            $bot_alias_id      Alias ID.
	 * @param array<int,string> $expected_statuses Acceptable statuses.
	 * @param int               $max_attempts      Maximum poll attempts.
	 * @param int               $sleep_microseconds Delay between attempts.
	 * @return array<string, mixed>|WP_Error
	 */
	public function wait_for_lex_bot_alias_status( string $bot_id, string $bot_alias_id, array $expected_statuses = array( 'Available' ), int $max_attempts = 12, int $sleep_microseconds = 2000000 ) {
		$last_alias = array();

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$alias = $this->describe_lex_bot_alias( $bot_id, $bot_alias_id );

			if ( is_wp_error( $alias ) ) {
				$alias_error_data = $alias->get_error_data();
				$alias_status     = is_array( $alias_error_data ) ? absint( $alias_error_data['status'] ?? 0 ) : 0;

				if ( 404 === $alias_status && $attempt < ( $max_attempts - 1 ) ) {
					usleep( max( 0, $sleep_microseconds ) );
					continue;
				}

				return $alias;
			}

			$last_alias   = is_array( $alias ) ? $alias : array();
			$alias_status = sanitize_text_field( (string) ( $last_alias['botAliasStatus'] ?? '' ) );

			if ( in_array( $alias_status, $expected_statuses, true ) ) {
				return $last_alias;
			}

			if ( in_array( $alias_status, array( 'Failed', 'Deleting' ), true ) ) {
				return new WP_Error(
					'ace_connect_lex_bot_alias_unavailable',
					sprintf(
						/* translators: %s: Amazon Lex bot alias status */
						__( 'The Amazon Lex bot alias could not be prepared because it entered the %s state.', 'adaptive-customer-engagement' ),
						$alias_status
					)
				);
			}

			if ( $attempt < ( $max_attempts - 1 ) ) {
				usleep( max( 0, $sleep_microseconds ) );
			}
		}

		return new WP_Error(
			'ace_connect_lex_bot_alias_not_ready',
			sprintf(
				/* translators: %s: Amazon Lex bot alias status */
				__( 'Amazon Lex is still preparing the bot alias (%s). Please retry in a moment.', 'adaptive-customer-engagement' ),
				sanitize_text_field( (string) ( $last_alias['botAliasStatus'] ?? __( 'unknown status', 'adaptive-customer-engagement' ) ) )
			)
		);
	}

	/**
	 * Describe a Lex V2 bot version.
	 *
	 * @param string $bot_id      Bot ID.
	 * @param string $bot_version Bot version.
	 * @return array<string, mixed>|WP_Error
	 */
	public function describe_lex_bot_version( string $bot_id, string $bot_version ) {
		$bot_id      = sanitize_text_field( $bot_id );
		$bot_version = sanitize_text_field( $bot_version );

		if ( '' === $bot_id || '' === $bot_version ) {
			return new WP_Error( 'ace_connect_lex_bot_version_missing', __( 'Please provide the Amazon Lex bot and version first.', 'adaptive-customer-engagement' ) );
		}

		return $this->request( 'lex', 'GET', '/bots/' . rawurlencode( $bot_id ) . '/botversions/' . rawurlencode( $bot_version ) . '/' );
	}

	/**
	 * Wait for a Lex bot version to reach an expected status.
	 *
	 * @param string            $bot_id            Bot ID.
	 * @param string            $bot_version       Bot version.
	 * @param array<int,string> $expected_statuses Acceptable statuses.
	 * @param int               $max_attempts      Maximum poll attempts.
	 * @param int               $sleep_microseconds Delay between attempts.
	 * @return array<string, mixed>|WP_Error
	 */
	public function wait_for_lex_bot_version_status( string $bot_id, string $bot_version, array $expected_statuses = array( 'Available' ), int $max_attempts = 15, int $sleep_microseconds = 2000000 ) {
		$last_version = array();

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$version = $this->describe_lex_bot_version( $bot_id, $bot_version );

			if ( is_wp_error( $version ) ) {
				$version_error_data = $version->get_error_data();
				$version_status     = is_array( $version_error_data ) ? absint( $version_error_data['status'] ?? 0 ) : 0;

				if ( 404 === $version_status && $attempt < ( $max_attempts - 1 ) ) {
					usleep( max( 0, $sleep_microseconds ) );
					continue;
				}

				return $version;
			}

			$last_version = is_array( $version ) ? $version : array();
			$status       = sanitize_text_field( (string) ( $last_version['botStatus'] ?? '' ) );

			if ( in_array( $status, $expected_statuses, true ) ) {
				return $last_version;
			}

			if ( in_array( $status, array( 'Failed', 'Deleting' ), true ) ) {
				return new WP_Error(
					'ace_connect_lex_bot_version_unavailable',
					sprintf(
						/* translators: %s: Amazon Lex bot version status */
						__( 'The Amazon Lex bot version could not be prepared because it entered the %s state.', 'adaptive-customer-engagement' ),
						$status
					)
				);
			}

			if ( $attempt < ( $max_attempts - 1 ) ) {
				usleep( max( 0, $sleep_microseconds ) );
			}
		}

		return new WP_Error(
			'ace_connect_lex_bot_version_not_ready',
			sprintf(
				/* translators: %s: Amazon Lex bot version status */
				__( 'Amazon Lex is still preparing the bot version (%s). Please retry in a moment.', 'adaptive-customer-engagement' ),
				sanitize_text_field( (string) ( $last_version['botStatus'] ?? __( 'unknown status', 'adaptive-customer-engagement' ) ) )
			)
		);
	}

	/**
	 * List intents for a Lex V2 bot locale.
	 *
	 * @param string $bot_id      Bot ID.
	 * @param string $locale_id   Locale ID.
	 * @param string $bot_version Bot version.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_lex_intents( string $bot_id, string $locale_id, string $bot_version = 'DRAFT' ) {
		$bot_id      = sanitize_text_field( $bot_id );
		$locale_id   = sanitize_text_field( $locale_id );
		$bot_version = sanitize_text_field( $bot_version );

		if ( '' === $bot_id || '' === $locale_id ) {
			return new WP_Error( 'ace_connect_lex_intent_scope_missing', __( 'Please provide the Amazon Lex bot ID and locale first.', 'adaptive-customer-engagement' ) );
		}

		if ( '' === $bot_version ) {
			$bot_version = 'DRAFT';
		}

		$items      = array();
		$next_token = '';

		do {
			$payload = array(
				'maxResults' => 100,
			);

			if ( '' !== $next_token ) {
				$payload['nextToken'] = $next_token;
			}

			$response = $this->request( 'lex', 'POST', '/bots/' . rawurlencode( $bot_id ) . '/botversions/' . rawurlencode( $bot_version ) . '/botlocales/' . rawurlencode( $locale_id ) . '/intents/', $payload );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$batch      = isset( $response['intentSummaries'] ) && is_array( $response['intentSummaries'] ) ? $response['intentSummaries'] : array();
			$items      = array_merge( $items, $batch );
			$next_token = sanitize_text_field( (string) ( $response['nextToken'] ?? '' ) );
		} while ( '' !== $next_token );

		return $items;
	}

	/**
	 * Describe a Lex V2 intent.
	 *
	 * @param string $bot_id      Bot ID.
	 * @param string $locale_id   Locale ID.
	 * @param string $intent_id   Intent ID.
	 * @param string $bot_version Bot version.
	 * @return array<string, mixed>|WP_Error
	 */
	public function describe_lex_intent( string $bot_id, string $locale_id, string $intent_id, string $bot_version = 'DRAFT' ) {
		$bot_id      = sanitize_text_field( $bot_id );
		$locale_id   = sanitize_text_field( $locale_id );
		$intent_id   = sanitize_text_field( $intent_id );
		$bot_version = sanitize_text_field( '' !== $bot_version ? $bot_version : 'DRAFT' );

		if ( '' === $bot_id || '' === $locale_id || '' === $intent_id ) {
			return new WP_Error( 'ace_connect_lex_intent_describe_missing', __( 'Please provide the Amazon Lex bot, locale, and intent before reading the intent settings.', 'adaptive-customer-engagement' ) );
		}

		return $this->request( 'lex', 'GET', '/bots/' . rawurlencode( $bot_id ) . '/botversions/' . rawurlencode( $bot_version ) . '/botlocales/' . rawurlencode( $locale_id ) . '/intents/' . rawurlencode( $intent_id ) . '/' );
	}

	/**
	 * Ensure the built-in Lex fallback intent invokes the runtime Lambda during fulfillment.
	 *
	 * @param string $bot_id      Bot ID.
	 * @param string $locale_id   Locale ID.
	 * @param string $bot_version Bot version.
	 * @return array<string, mixed>|WP_Error
	 */
	public function ensure_lex_runtime_fallback_intent( string $bot_id, string $locale_id, string $bot_version = 'DRAFT' ) {
		$intents = $this->list_lex_intents( $bot_id, $locale_id, $bot_version );

		if ( is_wp_error( $intents ) ) {
			return $intents;
		}

		$fallback_intent_id = '';

		foreach ( $intents as $intent ) {
			$parent_signature = sanitize_text_field( (string) ( $intent['parentIntentSignature'] ?? '' ) );

			if ( 'AMAZON.FallbackIntent' === $parent_signature ) {
				$fallback_intent_id = sanitize_text_field( (string) ( $intent['intentId'] ?? '' ) );
				break;
			}
		}

		if ( '' === $fallback_intent_id ) {
			return new WP_Error( 'ace_connect_lex_fallback_missing', __( 'The Amazon Lex fallback intent could not be found for this bot locale.', 'adaptive-customer-engagement' ) );
		}

		$fallback_intent = $this->describe_lex_intent( $bot_id, $locale_id, $fallback_intent_id, $bot_version );

		if ( is_wp_error( $fallback_intent ) ) {
			return $fallback_intent;
		}

		$payload = array(
			'intentName'          => sanitize_text_field( (string) ( $fallback_intent['intentName'] ?? 'FallbackIntent' ) ),
			'description'         => sanitize_textarea_field( (string) ( $fallback_intent['description'] ?? '' ) ),
			'parentIntentSignature' => sanitize_text_field( (string) ( $fallback_intent['parentIntentSignature'] ?? 'AMAZON.FallbackIntent' ) ),
			'dialogCodeHook'      => array(
				'enabled' => true,
			),
			'fulfillmentCodeHook' => array(
				'enabled' => true,
			),
		);

		$optional_keys = array(
			'initialResponseSetting',
			'intentClosingSetting',
			'intentConfirmationSetting',
			'inputContexts',
			'outputContexts',
			'sampleUtterances',
			'slotPriorities',
			'kendraConfiguration',
			'qnAIntentConfiguration',
			'qInConnectIntentConfiguration',
			'bedrockAgentIntentConfiguration',
		);

		foreach ( $optional_keys as $optional_key ) {
			if ( array_key_exists( $optional_key, $fallback_intent ) && null !== $fallback_intent[ $optional_key ] ) {
				$payload[ $optional_key ] = $fallback_intent[ $optional_key ];
			}
		}

		return $this->request( 'lex', 'PUT', '/bots/' . rawurlencode( sanitize_text_field( $bot_id ) ) . '/botversions/' . rawurlencode( sanitize_text_field( $bot_version ) ) . '/botlocales/' . rawurlencode( sanitize_text_field( $locale_id ) ) . '/intents/' . rawurlencode( $fallback_intent_id ) . '/', $payload );
	}

	/**
	 * Create an Amazon Lex V2 bot.
	 *
	 * @param string $name        Bot name.
	 * @param string $role_arn    IAM role ARN.
	 * @param string $description Optional description.
	 * @param string $locale_id   Preferred locale.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_lex_bot( string $name, string $role_arn, string $description = '', string $locale_id = 'en_GB' ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$name      = $this->sanitize_lex_resource_name( $name, 'LexBot' );
		$role_arn  = sanitize_text_field( $role_arn );
		$locale_id = sanitize_text_field( $locale_id );

		if ( '' === $name ) {
			return new WP_Error( 'ace_connect_lex_bot_name_missing', __( 'Please provide the Amazon Lex bot name first.', 'adaptive-customer-engagement' ) );
		}

		if ( '' === $role_arn ) {
			return new WP_Error( 'ace_connect_lex_bot_role_missing', __( 'Please provide the Amazon Lex bot IAM role ARN first.', 'adaptive-customer-engagement' ) );
		}

		$bot_tags = array(
			'managed-by'           => 'adaptive-customer-engagement',
			'environment'          => 'wordpress-plugin',
			'locale'               => $locale_id,
			'AmazonConnectEnabled' => 'true',
		);
		$alias_tags = array(
			'managed-by'           => 'adaptive-customer-engagement',
			'environment'          => 'wordpress-plugin',
			'AmazonConnectEnabled' => 'true',
		);

		$response = $this->request(
			'lex',
			'PUT',
			'/bots/',
			array(
				'botName'                 => $name,
				'description'             => sanitize_textarea_field( $description ),
				'roleArn'                 => $role_arn,
				'idleSessionTTLInSeconds' => 300,
				'dataPrivacy'             => array(
					'childDirected' => false,
				),
				'botTags'                 => $bot_tags,
				'testBotAliasTags'        => $alias_tags,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Create a locale for an Amazon Lex V2 bot.
	 *
	 * @param string $bot_id      Bot ID.
	 * @param string $locale_id   Locale ID.
	 * @param string $description Optional description.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_lex_bot_locale( string $bot_id, string $locale_id, string $description = '' ) {
		$bot_id    = sanitize_text_field( $bot_id );
		$locale_id = sanitize_text_field( $locale_id );

		if ( '' === $bot_id || '' === $locale_id ) {
			return new WP_Error( 'ace_connect_lex_bot_locale_missing', __( 'Please provide the Amazon Lex bot and locale first.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'lex',
			'PUT',
			'/bots/' . rawurlencode( $bot_id ) . '/botversions/DRAFT/botlocales/',
			array(
				'localeId'                     => $locale_id,
				'description'                  => sanitize_textarea_field( $description ),
				'nluIntentConfidenceThreshold' => 0.40,
				'voiceSettings'                => array(
					'voiceId' => 'Amy',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Create a published Lex V2 bot version from the current draft locale.
	 *
	 * @param string $bot_id      Bot ID.
	 * @param string $locale_id   Locale ID.
	 * @param string $description Optional description.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_lex_bot_version( string $bot_id, string $locale_id, string $description = '' ) {
		$bot_id    = sanitize_text_field( $bot_id );
		$locale_id = sanitize_text_field( $locale_id );

		if ( '' === $bot_id || '' === $locale_id ) {
			return new WP_Error( 'ace_connect_lex_bot_version_missing', __( 'Please provide the Amazon Lex bot and locale before publishing a version.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'lex',
			'PUT',
			'/bots/' . rawurlencode( $bot_id ) . '/botversions/',
			array(
				'botVersionLocaleSpecification' => array(
					$locale_id => array(
						'sourceBotVersion' => 'DRAFT',
					),
				),
				'description'                  => sanitize_textarea_field( $description ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Create a published Lex V2 bot alias.
	 *
	 * @param string               $bot_id      Bot ID.
	 * @param string               $bot_version Bot version.
	 * @param string               $alias_name  Alias name.
	 * @param string               $locale_id   Locale ID.
	 * @param array<string,string> $tags        Alias tags.
	 * @param string               $description Optional description.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_lex_bot_alias( string $bot_id, string $bot_version, string $alias_name, string $locale_id, array $tags = array(), string $description = '' ) {
		$bot_id      = sanitize_text_field( $bot_id );
		$bot_version = sanitize_text_field( $bot_version );
		$alias_name  = $this->sanitize_lex_resource_name( $alias_name, 'SiteChat' );
		$locale_id   = sanitize_text_field( $locale_id );

		if ( '' === $bot_id || '' === $bot_version || '' === $alias_name || '' === $locale_id ) {
			return new WP_Error( 'ace_connect_lex_bot_alias_create_missing', __( 'Please provide the Amazon Lex bot, version, alias name, and locale before creating an alias.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'lex',
			'PUT',
			'/bots/' . rawurlencode( $bot_id ) . '/botaliases/',
			array(
				'botAliasName'           => $alias_name,
				'botVersion'             => $bot_version,
				'description'            => sanitize_textarea_field( $description ),
				'botAliasLocaleSettings' => array(
					$locale_id => array(
						'enabled' => true,
					),
				),
				'tags'                   => $tags,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Update an existing Lex V2 alias so it uses a Lambda code hook for the selected locale.
	 *
	 * @param string $bot_id       Bot ID.
	 * @param string $bot_alias_id Alias ID.
	 * @param string $bot_version  Bot version.
	 * @param string $locale_id    Locale ID.
	 * @param string $lambda_arn   Lambda ARN.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_lex_bot_alias_code_hook( string $bot_id, string $bot_alias_id, string $bot_version, string $locale_id, string $lambda_arn ) {
		$alias = $this->describe_lex_bot_alias( $bot_id, $bot_alias_id );

		if ( is_wp_error( $alias ) ) {
			return $alias;
		}

		$bot_version = sanitize_text_field( '' !== $bot_version ? $bot_version : (string) ( $alias['botVersion'] ?? '' ) );
		$locale_id   = sanitize_text_field( $locale_id );
		$lambda_arn  = sanitize_text_field( $lambda_arn );

		if ( '' === $bot_version || '' === $locale_id || '' === $lambda_arn ) {
			return new WP_Error( 'ace_connect_lex_alias_code_hook_missing', __( 'The Lex alias code hook is missing the bot version, locale, or Lambda ARN.', 'adaptive-customer-engagement' ) );
		}

		$locale_settings = isset( $alias['botAliasLocaleSettings'] ) && is_array( $alias['botAliasLocaleSettings'] ) ? $alias['botAliasLocaleSettings'] : array();
		$current_locale  = isset( $locale_settings[ $locale_id ] ) && is_array( $locale_settings[ $locale_id ] ) ? $locale_settings[ $locale_id ] : array();

		$locale_settings[ $locale_id ] = array_merge(
			$current_locale,
			array(
				'enabled'               => true,
				'codeHookSpecification' => array(
					'lambdaCodeHook' => array(
						'lambdaARN'                => $lambda_arn,
						'codeHookInterfaceVersion' => '1.0',
					),
				),
			)
		);

		$response = $this->request(
			'lex',
			'PUT',
			'/bots/' . rawurlencode( sanitize_text_field( $bot_id ) ) . '/botaliases/' . rawurlencode( sanitize_text_field( $bot_alias_id ) ) . '/',
			array(
				'botAliasName'           => sanitize_text_field( (string) ( $alias['botAliasName'] ?? 'WebsiteChat' ) ),
				'botVersion'             => $bot_version,
				'description'            => sanitize_textarea_field( (string) ( $alias['description'] ?? '' ) ),
				'botAliasLocaleSettings' => $locale_settings,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Create or update the plugin-managed Lex runtime Lambda.
	 *
	 * @param string $function_name     Function name.
	 * @param string $role_arn          Execution role ARN.
	 * @param string $chat_api_endpoint Live answer endpoint.
	 * @param string $webhook_secret    Shared secret.
	 * @param string $site_name         Site name.
	 * @return array<string, mixed>|WP_Error
	 */
	public function ensure_lex_site_context_lambda( string $function_name, string $role_arn, string $chat_api_endpoint, string $webhook_secret, string $site_name = '' ) {
		$function_name     = $this->sanitize_lambda_function_name( $function_name );
		$role_arn          = sanitize_text_field( $role_arn );
		$chat_api_endpoint = esc_url_raw( $chat_api_endpoint );
		$webhook_secret    = sanitize_text_field( $webhook_secret );
		$site_name         = sanitize_text_field( '' !== $site_name ? $site_name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		if ( '' === $function_name || '' === $role_arn || '' === $chat_api_endpoint || '' === $webhook_secret ) {
			return new WP_Error( 'ace_connect_lex_runtime_lambda_missing', __( 'The Lex runtime Lambda is missing its name, role ARN, endpoint, or shared secret.', 'adaptive-customer-engagement' ) );
		}

		$site_context_snapshot = ( new SiteContextService() )->export_snapshot();
		$zip_file              = $this->build_site_context_lambda_zip_base64( $site_context_snapshot );

		if ( is_wp_error( $zip_file ) ) {
			return $zip_file;
		}

		$environment = array(
			'Variables' => array(
				'CHAT_API_ENDPOINT' => $chat_api_endpoint,
				'WEBHOOK_SECRET'    => $webhook_secret,
				'SITE_NAME'         => $site_name,
			),
		);
		$existing    = $this->get_lambda_function( $function_name );
		$create      = false;

		if ( is_wp_error( $existing ) ) {
			$error_data = $existing->get_error_data();
			$status     = is_array( $error_data ) ? absint( $error_data['status'] ?? 0 ) : 0;
			$message    = $existing->get_error_message();

			if ( 404 !== $status && ( 403 !== $status || false === stripos( $message, 'lambda:GetFunction' ) ) ) {
				return $existing;
			}

			$create = true;
		}

		if ( $create ) {
			$response = $this->request(
				'lambda',
				'POST',
				'/2015-03-31/functions',
				array(
					'FunctionName' => $function_name,
					'Role'         => $role_arn,
					'Runtime'      => 'python3.12',
					'Handler'      => 'lambda_function.lambda_handler',
					'Description'  => __( 'Adaptive Customer Engagement Lex live site-context runtime.', 'adaptive-customer-engagement' ),
					'Timeout'      => 10,
					'MemorySize'   => 256,
					'Publish'      => true,
					'Environment'  => $environment,
					'Code'         => array(
						'ZipFile' => $zip_file,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		} else {
			$config_response = $this->request(
				'lambda',
				'PUT',
				'/2015-03-31/functions/' . rawurlencode( $function_name ) . '/configuration',
				array(
					'FunctionName' => $function_name,
					'Role'         => $role_arn,
					'Runtime'      => 'python3.12',
					'Handler'      => 'lambda_function.lambda_handler',
					'Description'  => __( 'Adaptive Customer Engagement Lex live site-context runtime.', 'adaptive-customer-engagement' ),
					'Timeout'      => 10,
					'MemorySize'   => 256,
					'Environment'  => $environment,
				)
			);

			if ( is_wp_error( $config_response ) ) {
				return $config_response;
			}

			$config_ready = $this->wait_for_lambda_function_status( $function_name );

			if ( is_wp_error( $config_ready ) ) {
				return $config_ready;
			}

			$code_response = $this->request(
				'lambda',
				'PUT',
				'/2015-03-31/functions/' . rawurlencode( $function_name ) . '/code',
				array(
					'ZipFile' => $zip_file,
					'Publish' => true,
				)
			);

			if ( is_wp_error( $code_response ) ) {
				return $code_response;
			}
		}

		$ready = $this->wait_for_lambda_function_status( $function_name );

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return is_array( $ready ) ? $ready : array();
	}

	/**
	 * Read a Lambda function definition.
	 *
	 * @param string $function_name Function name.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_lambda_function( string $function_name ) {
		$response = $this->request( 'lambda', 'GET', '/2015-03-31/functions/' . rawurlencode( $this->sanitize_lambda_function_name( $function_name ) ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Wait for a Lambda function to become active.
	 *
	 * @param string $function_name      Function name.
	 * @param int    $max_attempts       Maximum poll attempts.
	 * @param int    $sleep_microseconds Delay between attempts.
	 * @return array<string, mixed>|WP_Error
	 */
	public function wait_for_lambda_function_status( string $function_name, int $max_attempts = 15, int $sleep_microseconds = 2000000 ) {
		$last_function = array();

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$function = $this->get_lambda_function( $function_name );

			if ( is_wp_error( $function ) ) {
				return $function;
			}

			$last_function      = is_array( $function ) ? $function : array();
			$configuration      = isset( $last_function['Configuration'] ) && is_array( $last_function['Configuration'] ) ? $last_function['Configuration'] : $last_function;
			$state              = sanitize_text_field( (string) ( $configuration['State'] ?? 'Active' ) );
			$last_update_status = sanitize_text_field( (string) ( $configuration['LastUpdateStatus'] ?? 'Successful' ) );

			if ( 'Active' === $state && in_array( $last_update_status, array( '', 'Successful' ), true ) ) {
				return $last_function;
			}

			if ( 'Failed' === $state || 'Failed' === $last_update_status ) {
				return new WP_Error(
					'ace_connect_lambda_failed',
					sprintf(
						/* translators: 1: state, 2: update status */
						__( 'The Lex runtime Lambda could not be prepared because it entered the %1$s / %2$s state.', 'adaptive-customer-engagement' ),
						$state ?: __( 'unknown', 'adaptive-customer-engagement' ),
						$last_update_status ?: __( 'unknown', 'adaptive-customer-engagement' )
					)
				);
			}

			if ( $attempt < ( $max_attempts - 1 ) ) {
				usleep( max( 0, $sleep_microseconds ) );
			}
		}

		return new WP_Error( 'ace_connect_lambda_not_ready', __( 'AWS Lambda is still preparing the Lex runtime function. Please retry in a moment.', 'adaptive-customer-engagement' ) );
	}

	/**
	 * Allow a specific Lex alias to invoke the runtime Lambda.
	 *
	 * @param string $function_name Function name.
	 * @param string $source_arn    Lex alias ARN.
	 * @param string $statement_id  Policy statement ID.
	 * @return true|WP_Error
	 */
	public function allow_lex_alias_to_invoke_lambda( string $function_name, string $source_arn, string $statement_id = '' ) {
		$function_name = $this->sanitize_lambda_function_name( $function_name );
		$source_arn    = sanitize_text_field( $source_arn );
		$statement_id  = sanitize_key( '' !== $statement_id ? $statement_id : 'acelex' . substr( md5( $function_name . '|' . $source_arn ), 0, 12 ) );

		if ( '' === $function_name || '' === $source_arn ) {
			return new WP_Error( 'ace_connect_lambda_permission_missing', __( 'The Lambda invoke permission is missing the function name or Lex alias ARN.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'lambda',
			'POST',
			'/2015-03-31/functions/' . rawurlencode( $function_name ) . '/policy',
			array(
				'StatementId' => $statement_id,
				'Action'      => 'lambda:InvokeFunction',
				'Principal'   => 'lex.amazonaws.com',
				'SourceArn'   => $source_arn,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$aws_code   = is_array( $error_data ) ? sanitize_text_field( (string) ( $error_data['aws_code'] ?? '' ) ) : '';
			$status     = is_array( $error_data ) ? absint( $error_data['status'] ?? 0 ) : 0;
			$message    = $response->get_error_message();

			if ( 'ResourceConflictException' === $aws_code || ( 409 === $status && false !== stripos( $message, 'statement id' ) && false !== stripos( $message, 'already exists' ) ) ) {
				return true;
			}

			return $response;
		}

		return true;
	}

	/**
	 * Create an Amazon Lex V2 intent.
	 *
	 * @param string               $bot_id            Bot ID.
	 * @param string               $locale_id         Locale ID.
	 * @param string               $intent_name       Intent name.
	 * @param string               $answer            Answer text.
	 * @param array<int, string>   $sample_utterances Sample utterances.
	 * @param string               $description       Optional description.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_lex_intent( string $bot_id, string $locale_id, string $intent_name, string $answer, array $sample_utterances, string $description = '' ) {
		$bot_id      = sanitize_text_field( $bot_id );
		$locale_id   = sanitize_text_field( $locale_id );
		$intent_name = sanitize_text_field( $intent_name );
		$answer      = sanitize_textarea_field( $answer );

		if ( '' === $bot_id || '' === $locale_id || '' === $intent_name ) {
			return new WP_Error( 'ace_connect_lex_intent_missing', __( 'Please provide the Amazon Lex bot, locale, and intent name first.', 'adaptive-customer-engagement' ) );
		}

		if ( '' === $answer ) {
			return new WP_Error( 'ace_connect_lex_intent_answer_missing', __( 'Please provide the Amazon Lex intent answer first.', 'adaptive-customer-engagement' ) );
		}

		$utterances = array_values(
			array_filter(
				array_map(
					static function ( $utterance ) {
						$utterance = sanitize_text_field( (string) $utterance );

						if ( '' === $utterance ) {
							return null;
						}

						return array(
							'utterance' => $utterance,
						);
					},
					$sample_utterances
				)
			)
		);

		$response = $this->request(
			'lex',
			'PUT',
			'/bots/' . rawurlencode( $bot_id ) . '/botversions/DRAFT/botlocales/' . rawurlencode( $locale_id ) . '/intents/',
			array(
				'intentName'             => $intent_name,
				'description'            => sanitize_textarea_field( $description ),
				'dialogCodeHook'         => array(
					'enabled' => false,
				),
				'fulfillmentCodeHook'    => array(
					'enabled' => false,
				),
				'sampleUtterances'       => $utterances,
				'initialResponseSetting' => array(
					'initialResponse' => array(
						'messageGroups' => array(
							array(
								'message' => array(
									'plainTextMessage' => array(
										'value' => $answer,
									),
								),
							),
						),
					),
				),
				'closingSetting'         => array(
					'active'          => true,
					'closingResponse' => array(
						'messageGroups' => array(
							array(
								'message' => array(
									'plainTextMessage' => array(
										'value' => $answer,
									),
								),
							),
						),
					),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Build an Amazon Lex V2 bot locale.
	 *
	 * @param string $bot_id    Bot ID.
	 * @param string $locale_id Locale ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function build_lex_bot_locale( string $bot_id, string $locale_id ) {
		$bot_id    = sanitize_text_field( $bot_id );
		$locale_id = sanitize_text_field( $locale_id );

		if ( '' === $bot_id || '' === $locale_id ) {
			return new WP_Error( 'ace_connect_lex_build_missing', __( 'Please provide the Amazon Lex bot and locale before building.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'lex',
			'POST',
			'/bots/' . rawurlencode( $bot_id ) . '/botversions/DRAFT/botlocales/' . rawurlencode( $locale_id ) . '/',
			array()
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * List Amazon Q in Connect assistants.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_assistants() {
		$response = $this->request( 'wisdom', 'GET', '/assistants', array(), array( 'maxResults' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['assistantSummaries'] ) && is_array( $response['assistantSummaries'] ) ? $response['assistantSummaries'] : array();
	}

	/**
	 * Create an Amazon Q in Connect assistant.
	 *
	 * @param string $name        Assistant name.
	 * @param string $description Optional description.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_assistant( string $name, string $description = '' ) {
		$response = $this->request(
			'wisdom',
			'POST',
			'/assistants',
			array(
				'clientToken' => wp_generate_uuid4(),
				'name'        => $name,
				'description' => $description,
				'type'        => 'AGENT',
				'tags'        => array(
					'managed-by'  => 'adaptive-customer-engagement',
					'environment' => 'wordpress-plugin',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['assistant'] ) && is_array( $response['assistant'] ) ) {
			return $response['assistant'];
		}

		if ( isset( $response['assistantId'] ) || isset( $response['assistantArn'] ) ) {
			return $response;
		}

		return new WP_Error( 'ace_connect_assistant_create_failed', __( 'Amazon Q in Connect did not return the new assistant details.', 'adaptive-customer-engagement' ) );
	}

	/**
	 * List recent S3 objects from a bucket/prefix.
	 *
	 * @param string $bucket             Bucket name.
	 * @param string $prefix             Optional object prefix.
	 * @param int    $max_keys           Maximum keys to request.
	 * @param string $continuation_token Optional continuation token.
	 * @return array{items:array<int,array<string,mixed>>,next_token:string}|WP_Error
	 */
	public function list_s3_objects( string $bucket, string $prefix = '', int $max_keys = 100, string $continuation_token = '' ) {
		$bucket = sanitize_text_field( $bucket );
		$prefix = ltrim( sanitize_text_field( $prefix ), '/' );

		if ( '' === $bucket ) {
			return new WP_Error( 'ace_connect_s3_bucket_missing', __( 'Please save the Amazon Connect export bucket first.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request_raw(
			's3',
			'GET',
			'/' . rawurlencode( $bucket ),
			array(),
			array(
				'list-type'          => 2,
				'prefix'             => $prefix,
				'max-keys'           => max( 1, min( 1000, $max_keys ) ),
				'continuation-token' => $continuation_token,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$xml = simplexml_load_string( $response['body'] );

		if ( false === $xml ) {
			return new WP_Error( 'ace_connect_s3_list_parse_failed', __( 'The Amazon Connect export listing could not be read from S3.', 'adaptive-customer-engagement' ) );
		}

		$items = array();

		if ( isset( $xml->Contents ) ) {
			foreach ( $xml->Contents as $content ) {
				$items[] = array(
					'Key'          => sanitize_text_field( (string) $content->Key ),
					'LastModified' => sanitize_text_field( (string) $content->LastModified ),
					'ETag'         => sanitize_text_field( (string) $content->ETag ),
					'Size'         => (int) $content->Size,
				);
			}
		}

		return array(
			'items'      => $items,
			'next_token' => sanitize_text_field( (string) ( $xml->NextContinuationToken ?? '' ) ),
		);
	}

	/**
	 * Read a raw object from S3.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $key    Object key.
	 * @return string|WP_Error
	 */
	public function get_s3_object( string $bucket, string $key ) {
		$bucket = sanitize_text_field( $bucket );
		$key    = ltrim( $key, '/' );

		if ( '' === $bucket || '' === $key ) {
			return new WP_Error( 'ace_connect_s3_object_missing', __( 'The requested Amazon Connect export object is incomplete.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request_raw(
			's3',
			'GET',
			'/' . rawurlencode( $bucket ) . '/' . $this->build_s3_object_path( $key )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['body'];
	}

	/**
	 * Perform a signed AWS request.
	 *
	 * @param string               $service Service key.
	 * @param string               $method  HTTP method.
	 * @param string               $path    Request path.
	 * @param array<string, mixed> $body    Request payload.
	 * @param array<string, mixed> $query   Query values.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $service, string $method, string $path, array $body = array(), array $query = array() ) {
		$response = $this->request_raw( $service, $method, $path, $body, $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_body = $response['body'];
		$data     = '' !== $raw_body ? json_decode( $raw_body, true ) : array();

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Perform a signed AWS request and return the raw response body.
	 *
	 * @param string               $service Service key.
	 * @param string               $method  HTTP method.
	 * @param string               $path    Request path.
	 * @param array<string, mixed> $body    Request payload.
	 * @param array<string, mixed> $query   Query values.
	 * @return array{status_code:int,body:string,headers:array<string,mixed>}|WP_Error
	 */
	private function request_raw( string $service, string $method, string $path, array $body = array(), array $query = array() ) {
		$config = $this->get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return $this->request_raw_with_config( $config, $service, $method, $path, $body, $query );
	}

	/**
	 * Perform a signed AWS request and decode JSON.
	 *
	 * @param array<string, string> $config  Request config.
	 * @param string                $service Service key.
	 * @param string                $method  HTTP method.
	 * @param string                $path    Request path.
	 * @param array<string, mixed>  $body    Request payload.
	 * @param array<string, mixed>  $query   Query values.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request_with_config( array $config, string $service, string $method, string $path, array $body = array(), array $query = array() ) {
		$response = $this->request_raw_with_config( $config, $service, $method, $path, $body, $query );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_body = $response['body'];
		$data     = '' !== $raw_body ? json_decode( $raw_body, true ) : array();

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Perform a signed AWS request and return the raw response body.
	 *
	 * @param array<string, string> $config  Request config.
	 * @param string                $service Service key.
	 * @param string                $method  HTTP method.
	 * @param string                $path    Request path.
	 * @param array<string, mixed>  $body    Request payload.
	 * @param array<string, mixed>  $query   Query values.
	 * @return array{status_code:int,body:string,headers:array<string,mixed>}|WP_Error
	 */
	private function request_raw_with_config( array $config, string $service, string $method, string $path, array $body = array(), array $query = array() ) {
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$signing_region = $this->get_signing_region( $service, $config['region'] );
		$host           = $this->get_host( $service, $config['region'] );

		if ( '' === $host ) {
			return new WP_Error( 'ace_connect_unknown_service', __( 'Unsupported AWS service requested.', 'adaptive-customer-engagement' ) );
		}

		$payload        = $this->encode_payload( $service, $method, $body );
		$payload        = is_string( $payload ) ? $payload : '';
		$payload_hash   = hash( 'sha256', $payload );
		$amz_date       = gmdate( 'Ymd\THis\Z' );
		$date_stamp     = gmdate( 'Ymd' );
		$url_path       = $path;
		$canonical_uri  = $this->build_canonical_uri( $path );

		if ( 'lex' === $service && 0 === strpos( $path, '/tags/' ) ) {
			$url_path = $path;
			$segments = explode( '/', ltrim( $path, '/' ) );

			$canonical_uri = '/' . implode(
				'/',
				array_map(
					'rawurlencode',
					$segments
				)
			);
		}
		$query_string   = $this->build_query_string( $query );
		$session_token  = sanitize_text_field( (string) ( $config['session_token'] ?? '' ) );
		$content_type = $this->get_content_type( $service );
		$headers_to_sign = array(
			'content-type'         => $content_type,
			'host'                 => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $amz_date,
		);

		if ( '' !== $session_token ) {
			$headers_to_sign['x-amz-security-token'] = $session_token;
		}

		ksort( $headers_to_sign, SORT_STRING );

		$signed_headers    = implode( ';', array_keys( $headers_to_sign ) );
		$canonical_headers = '';

		foreach ( $headers_to_sign as $header_name => $header_value ) {
			$canonical_headers .= $header_name . ':' . $header_value . "\n";
		}

		$canonical_request = strtoupper( $method ) . "\n" .
			$canonical_uri . "\n" .
			$query_string . "\n" .
			$canonical_headers . "\n" .
			$signed_headers . "\n" .
			$payload_hash;
		$scope          = $date_stamp . '/' . $signing_region . '/' . $service . '/aws4_request';
		$string_to_sign = 'AWS4-HMAC-SHA256' . "\n" .
			$amz_date . "\n" .
			$scope . "\n" .
			hash( 'sha256', $canonical_request );
		$signing_key    = $this->get_signature_key( $config['secret_access_key'], $date_stamp, $signing_region, $service );
		$signature      = hash_hmac( 'sha256', $string_to_sign, $signing_key );
		$authorization  = 'AWS4-HMAC-SHA256 Credential=' . $config['access_key_id'] . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
		$url            = 'https://' . $host . $url_path;

		if ( '' !== $query_string ) {
			$url .= '?' . $query_string;
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => strtoupper( $method ),
				'timeout' => 20,
				'headers' => array_filter(
					array(
					'Authorization'         => $authorization,
					'Content-Type'          => $content_type,
					'Host'                  => $host,
					'X-Amz-Content-Sha256'  => $payload_hash,
					'X-Amz-Date'            => $amz_date,
						'X-Amz-Security-Token'  => $session_token,
					),
					static function ( $value ): bool {
						return null !== $value && '' !== $value;
					}
				),
				'body'    => 'GET' === strtoupper( $method ) ? null : $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = '' !== $raw_body ? json_decode( $raw_body, true ) : array();

		if ( $status_code < 200 || $status_code >= 300 ) {
			$data          = '' !== $raw_body ? json_decode( $raw_body, true ) : array();
			$error_details = $this->parse_aws_error_response( $raw_body, $data );
			$message       = '' !== $error_details['message']
				? $error_details['message']
				: ( is_array( $data )
					? (string) ( $data['Message'] ?? $data['message'] ?? wp_remote_retrieve_response_message( $response ) )
					: (string) wp_remote_retrieve_response_message( $response ) );

			return new WP_Error(
				'ace_connect_request_failed',
				'' !== $message ? $message : __( 'The AWS request failed.', 'adaptive-customer-engagement' ),
				array(
					'status' => $status_code,
					'aws_code' => $error_details['code'],
					'body'   => is_array( $data ) ? $data : $raw_body,
				)
			);
		}

		return array(
			'status_code' => $status_code,
			'body'        => $raw_body,
			'headers'     => wp_remote_retrieve_headers( $response )->getAll(),
		);
	}

	/**
	 * Build a canonical URI.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	private function build_canonical_uri( string $path ): string {
		$segments = array_map(
			static function ( string $segment ): string {
				$segment = rawurlencode( $segment );

				return preg_replace( '/%25([0-9A-Fa-f]{2})/', '%$1', $segment );
			},
			explode( '/', ltrim( $path, '/' ) )
		);

		return '/' . implode( '/', $segments );
	}

	/**
	 * Build a canonical query string.
	 *
	 * @param array<string, mixed> $query Query values.
	 * @return string
	 */
	private function build_query_string( array $query ): string {
		if ( empty( $query ) ) {
			return '';
		}

		$pairs = array();

		foreach ( $query as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}

			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		sort( $pairs, SORT_STRING );

		return implode( '&', $pairs );
	}

	/**
	 * Get the AWS hostname for a service.
	 *
	 * @param string $service Service key.
	 * @param string $region  AWS region.
	 * @return string
	 */
	private function get_host( string $service, string $region ): string {
		switch ( $service ) {
			case 'connect':
				return 'connect.' . $region . '.amazonaws.com';
			case 'iam':
				return 'iam.amazonaws.com';
			case 'lambda':
				return 'lambda.' . $region . '.amazonaws.com';
			case 'lex':
				return 'models-v2-lex.' . $region . '.amazonaws.com';
			case 's3':
				return 's3.' . $region . '.amazonaws.com';
			case 'wisdom':
				return 'wisdom.' . $region . '.amazonaws.com';
			default:
				return '';
		}
	}

	/**
	 * Get the required content type for an AWS service.
	 *
	 * @param string $service Service key.
	 * @return string
	 */
	private function get_content_type( string $service ): string {
		if ( 'iam' === $service ) {
			return 'application/x-www-form-urlencoded; charset=utf-8';
		}

		if ( 'lex' === $service ) {
			return 'application/x-amz-json-1.1';
		}

		return 'application/json';
	}

	/**
	 * Build an encoded S3 object path while preserving path separators.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	private function build_s3_object_path( string $key ): string {
		return implode(
			'/',
			array_map(
				'rawurlencode',
				array_filter( explode( '/', $key ), 'strlen' )
			)
		);
	}

	/**
	 * Build the signing key.
	 *
	 * @param string $secret Secret access key.
	 * @param string $date   Date stamp.
	 * @param string $region Region.
	 * @param string $service Service.
	 * @return string
	 */
	private function get_signature_key( string $secret, string $date, string $region, string $service ): string {
		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/**
	 * Encode a request payload for the target AWS service.
	 *
	 * @param string               $service Service key.
	 * @param string               $method  HTTP method.
	 * @param array<string, mixed> $body    Request body.
	 * @return string
	 */
	private function encode_payload( string $service, string $method, array $body ): string {
		if ( in_array( strtoupper( $method ), array( 'GET', 'DELETE' ), true ) ) {
			return '';
		}

		if ( empty( $body ) ) {
			return '';
		}

		if ( 'iam' === $service ) {
			return $this->build_query_string( $body );
		}

		$payload = wp_json_encode( $body );

		return is_string( $payload ) ? $payload : '';
	}

	/**
	 * Get the SigV4 signing region for an AWS service.
	 *
	 * @param string $service Service key.
	 * @param string $region  Configured region.
	 * @return string
	 */
	private function get_signing_region( string $service, string $region ): string {
		if ( 'iam' === $service ) {
			return 'us-east-1';
		}

		return $region;
	}

	/**
	 * Parse a structured AWS error response.
	 *
	 * @param string                    $raw_body Raw response body.
	 * @param array<string, mixed>|null $data     JSON-decoded payload when available.
	 * @return array{code:string,message:string}
	 */
	private function parse_aws_error_response( string $raw_body, $data ): array {
		if ( is_array( $data ) ) {
			return array(
				'code'    => sanitize_text_field( (string) ( $data['Code'] ?? $data['code'] ?? '' ) ),
				'message' => sanitize_text_field( (string) ( $data['Message'] ?? $data['message'] ?? '' ) ),
			);
		}

		$xml = $this->parse_xml_body( $raw_body );
		$code = sanitize_text_field( (string) ( $xml['Error']['Code'] ?? $xml['Code'] ?? '' ) );
		$message = sanitize_text_field( (string) ( $xml['Error']['Message'] ?? $xml['Message'] ?? '' ) );

		if ( '' === $code && preg_match( '#<Code>([^<]+)</Code>#i', $raw_body, $code_match ) ) {
			$code = sanitize_text_field( html_entity_decode( (string) $code_match[1], ENT_QUOTES ) );
		}

		if ( '' === $message && preg_match( '#<Message>([^<]+)</Message>#i', $raw_body, $message_match ) ) {
			$message = sanitize_text_field( html_entity_decode( (string) $message_match[1], ENT_QUOTES ) );
		}

		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Parse a simple XML response body into an array.
	 *
	 * @param string $raw_body Raw XML body.
	 * @return array<string, mixed>
	 */
	private function parse_xml_body( string $raw_body ): array {
		if ( '' === trim( $raw_body ) || ! function_exists( 'simplexml_load_string' ) ) {
			return array();
		}

		$raw_body = preg_replace( '/\sxmlns(?::[A-Za-z0-9_-]+)?="[^"]*"/', '', $raw_body );
		$raw_body = is_string( $raw_body ) ? $raw_body : '';

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $raw_body, 'SimpleXMLElement', LIBXML_NOCDATA );
		libxml_clear_errors();

		if ( false === $xml ) {
			return array();
		}

		$data = json_decode( wp_json_encode( $xml ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Make an IAM query API request.
	 *
	 * @param string               $action IAM action name.
	 * @param array<string, mixed> $params IAM request parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	private function iam_query( string $action, array $params = array() ) {
		$response = $this->request_raw(
			'iam',
			'POST',
			'/',
			array_merge(
				array(
					'Action'  => $action,
					'Version' => '2010-05-08',
				),
				$params
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->parse_xml_body( $response['body'] );

		return ! empty( $data ) ? $data : array();
	}

	/**
	 * Read an IAM role definition.
	 *
	 * @param string $role_name Role name.
	 * @return array<string, mixed>|WP_Error
	 */
	private function get_iam_role( string $role_name ) {
		$response = $this->request_raw(
			'iam',
			'POST',
			'/',
			array(
				'Action'   => 'GetRole',
				'Version'  => '2010-05-08',
				'RoleName' => $role_name,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->extract_iam_role_from_xml( (string) $response['body'] );
	}

	/**
	 * Create an IAM role.
	 *
	 * @param string               $role_name            Role name.
	 * @param array<string, mixed> $assume_role_policy   Trust policy.
	 * @param string               $description          Optional description.
	 * @return array<string, mixed>|WP_Error
	 */
	private function create_iam_role( string $role_name, array $assume_role_policy, string $description = '' ) {
		$response = $this->request_raw(
			'iam',
			'POST',
			'/',
			array(
				'Action'                   => 'CreateRole',
				'Version'                  => '2010-05-08',
				'RoleName'                 => $role_name,
				'AssumeRolePolicyDocument' => wp_json_encode( $assume_role_policy ),
				'Description'              => sanitize_text_field( $description ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->extract_iam_role_from_xml( (string) $response['body'] );
	}

	/**
	 * Put an inline policy on an IAM role.
	 *
	 * @param string               $role_name   Role name.
	 * @param string               $policy_name Policy name.
	 * @param array<string, mixed> $policy      Policy document.
	 * @return true|WP_Error
	 */
	private function put_iam_role_policy( string $role_name, string $policy_name, array $policy ) {
		$response = $this->iam_query(
			'PutRolePolicy',
			array(
				'RoleName'       => $role_name,
				'PolicyName'     => sanitize_text_field( $policy_name ),
				'PolicyDocument' => wp_json_encode( $policy ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Check whether an AWS error represents a specific IAM error code.
	 *
	 * @param WP_Error $error    Error instance.
	 * @param string   $iam_code IAM error code.
	 * @return bool
	 */
	private function is_iam_error_code( WP_Error $error, string $iam_code ): bool {
		$data = $error->get_error_data();

		if ( is_array( $data ) && ! empty( $data['aws_code'] ) ) {
			return $iam_code === sanitize_text_field( (string) $data['aws_code'] );
		}

		if ( ! is_array( $data ) || empty( $data['body'] ) || ! is_string( $data['body'] ) ) {
			return false;
		}

		$parsed = $this->parse_xml_body( $data['body'] );

		return $iam_code === sanitize_text_field( (string) ( $parsed['Error']['Code'] ?? $parsed['Code'] ?? '' ) );
	}

	/**
	 * Build a safe default IAM role name for plugin-managed Lex bots.
	 *
	 * @return string
	 */
	private function build_default_lex_bot_role_name(): string {
		$site_name = sanitize_title_with_dashes( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$site_name = preg_replace( '/[^A-Za-z0-9+=,.@_-]+/', '-', (string) $site_name );
		$site_name = trim( (string) $site_name, '-_' );
		$site_name = '' !== $site_name ? $site_name : 'site';
		$site_name = substr( $site_name, 0, 32 );

		return $this->sanitize_iam_role_name( 'AdaptiveCustomerEngagementLexBotRole-' . $site_name );
	}

	/**
	 * Build a safe default IAM role name for plugin-managed Lex runtime Lambdas.
	 *
	 * @return string
	 */
	private function build_default_lex_runtime_role_name(): string {
		$site_name = sanitize_title_with_dashes( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$site_name = preg_replace( '/[^A-Za-z0-9+=,.@_-]+/', '-', (string) $site_name );
		$site_name = trim( (string) $site_name, '-_' );
		$site_name = '' !== $site_name ? $site_name : 'site';
		$site_name = substr( $site_name, 0, 24 );

		return $this->sanitize_iam_role_name( 'AdaptiveCustomerEngagementLexRuntimeRole-' . $site_name );
	}

	/**
	 * Sanitize an IAM role name.
	 *
	 * @param string $role_name Raw role name.
	 * @return string
	 */
	private function sanitize_iam_role_name( string $role_name ): string {
		$role_name = preg_replace( '/[^A-Za-z0-9+=,.@_-]+/', '-', $role_name );
		$role_name = trim( (string) $role_name, '-_' );

		return substr( $role_name, 0, 64 );
	}

	/**
	 * Extract a minimal IAM role payload from an XML response.
	 *
	 * @param string $raw_body Raw IAM XML body.
	 * @return array<string, mixed>
	 */
	private function extract_iam_role_from_xml( string $raw_body ): array {
		$fields = array(
			'Path'                     => '',
			'AssumeRolePolicyDocument' => '',
			'MaxSessionDuration'       => '',
			'RoleId'                   => '',
			'RoleName'                 => '',
			'Description'              => '',
			'Arn'                      => '',
			'CreateDate'               => '',
		);
		$role   = array();

		foreach ( $fields as $field => $default ) {
			if ( preg_match( '#<' . preg_quote( $field, '#' ) . '>(.*?)</' . preg_quote( $field, '#' ) . '>#s', $raw_body, $matches ) ) {
				$role[ $field ] = html_entity_decode( trim( (string) $matches[1] ), ENT_QUOTES );
			} else {
				$role[ $field ] = $default;
			}
		}

		return array_filter(
			$role,
			static function ( $value ): bool {
				return '' !== (string) $value;
			}
		);
	}

	/**
	 * Sanitize a Lex-compatible resource name.
	 *
	 * @param string $name     Raw name.
	 * @param string $fallback Fallback base name.
	 * @return string
	 */
	private function sanitize_lex_resource_name( string $name, string $fallback = 'LexResource' ): string {
		$name = sanitize_text_field( $name );
		$name = preg_replace( '/[^0-9A-Za-z_-]+/', '-', $name );
		$name = preg_replace( '/[-_]{2,}/', '-', (string) $name );
		$name = trim( (string) $name, '-_' );

		if ( '' === $name ) {
			$name = $fallback;
		}

		return substr( $name, 0, 100 );
	}

	/**
	 * Sanitize an AWS Lambda function name.
	 *
	 * @param string $name Raw function name.
	 * @return string
	 */
	private function sanitize_lambda_function_name( string $name ): string {
		$name = sanitize_text_field( $name );
		$name = preg_replace( '/[^0-9A-Za-z-_]+/', '-', $name );
		$name = preg_replace( '/[-_]{2,}/', '-', (string) $name );
		$name = trim( (string) $name, '-_' );

		if ( '' === $name ) {
			$name = 'AdaptiveCustomerEngagementLexRuntime';
		}

		return substr( $name, 0, 64 );
	}

	/**
	 * Build a base64-encoded Lambda zip from the bundled runtime template.
	 *
	 * @return string|WP_Error
	 */
	private function build_site_context_lambda_zip_base64( array $site_context_snapshot = array() ) {
		$template_path = ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_DIR . 'assets/lambda/lex_site_context_runtime.py';

		if ( ! file_exists( $template_path ) ) {
			return new WP_Error( 'ace_connect_lambda_template_missing', __( 'The bundled Lex runtime Lambda template could not be found.', 'adaptive-customer-engagement' ) );
		}

		$template_body = file_get_contents( $template_path );

		if ( false === $template_body || '' === $template_body ) {
			return new WP_Error( 'ace_connect_lambda_template_empty', __( 'The bundled Lex runtime Lambda template could not be read.', 'adaptive-customer-engagement' ) );
		}

		$zip_path = wp_tempnam( 'ace-lex-runtime.zip' );

		if ( ! is_string( $zip_path ) || '' === $zip_path ) {
			return new WP_Error( 'ace_connect_lambda_zip_temp_missing', __( 'A temporary file for the Lex runtime Lambda package could not be created.', 'adaptive-customer-engagement' ) );
		}

		$snapshot_body = wp_json_encode( $site_context_snapshot );

		if ( ! is_string( $snapshot_body ) || '' === $snapshot_body ) {
			return new WP_Error( 'ace_connect_lambda_snapshot_empty', __( 'The Lex runtime site-context snapshot could not be generated.', 'adaptive-customer-engagement' ) );
		}

		if ( class_exists( '\ZipArchive' ) ) {
			$zip    = new \ZipArchive();
			$opened = $zip->open( $zip_path, \ZipArchive::OVERWRITE );

			if ( true !== $opened ) {
				@unlink( $zip_path );
				return new WP_Error( 'ace_connect_lambda_zip_open_failed', __( 'The Lex runtime Lambda zip package could not be opened for writing.', 'adaptive-customer-engagement' ) );
			}

			$zip->addFromString( 'lambda_function.py', $template_body );
			$zip->addFromString( 'site_context_snapshot.json', $snapshot_body );
			$zip->close();
		} else {
			$work_dir       = dirname( $zip_path );
			$lambda_path    = trailingslashit( $work_dir ) . 'lambda_function.py';
			$snapshot_path  = trailingslashit( $work_dir ) . 'site_context_snapshot.json';
			$written        = file_put_contents( $lambda_path, $template_body );
			$snapshot_write = file_put_contents( $snapshot_path, $snapshot_body );

			if ( false === $written || false === $snapshot_write ) {
				@unlink( $zip_path );
				@unlink( $snapshot_path );
				return new WP_Error( 'ace_connect_lambda_template_write_failed', __( 'The Lex runtime Lambda template could not be prepared for packaging.', 'adaptive-customer-engagement' ) );
			}

			@unlink( $zip_path );

			$command = sprintf(
				'cd %s && zip -q -j %s %s %s 2>&1',
				escapeshellarg( $work_dir ),
				escapeshellarg( $zip_path ),
				escapeshellarg( $lambda_path ),
				escapeshellarg( $snapshot_path )
			);
			$output = shell_exec( $command );
			@unlink( $lambda_path );
			@unlink( $snapshot_path );

			if ( ! file_exists( $zip_path ) || 0 === filesize( $zip_path ) ) {
				@unlink( $zip_path );
				return new WP_Error( 'ace_connect_lambda_zip_shell_failed', __( 'The Lex runtime Lambda zip package could not be built with the system zip command.', 'adaptive-customer-engagement' ), array( 'output' => is_string( $output ) ? trim( $output ) : '' ) );
			}
		}

		$zip_body = file_get_contents( $zip_path );
		@unlink( $zip_path );

		if ( false === $zip_body || '' === $zip_body ) {
			return new WP_Error( 'ace_connect_lambda_zip_read_failed', __( 'The Lex runtime Lambda zip package could not be read after creation.', 'adaptive-customer-engagement' ) );
		}

		return base64_encode( $zip_body );
	}

	/**
	 * Build a Lex V2 bot alias ARN.
	 *
	 * @param string $bot_id     Bot ID.
	 * @param string $alias_id   Alias ID.
	 * @param string $account_id AWS account ID.
	 * @param string $region     AWS region.
	 * @return string
	 */
	public function build_lex_v2_alias_arn( string $bot_id, string $alias_id, string $account_id, string $region ): string {
		$bot_id     = sanitize_text_field( $bot_id );
		$alias_id   = sanitize_text_field( $alias_id );
		$account_id = preg_replace( '/[^0-9]/', '', $account_id );
		$region     = sanitize_text_field( $region );

		if ( '' === $bot_id || '' === $alias_id || '' === $account_id || '' === $region ) {
			return '';
		}

		return sprintf(
			'arn:aws:lex:%1$s:%2$s:bot-alias/%3$s/%4$s',
			$region,
			$account_id,
			$bot_id,
			$alias_id
		);
	}

	/**
	 * Build a Lex V2 bot ARN.
	 *
	 * @param string $bot_id     Bot ID.
	 * @param string $account_id AWS account ID.
	 * @param string $region     AWS region.
	 * @return string
	 */
	public function build_lex_v2_bot_arn( string $bot_id, string $account_id, string $region ): string {
		$bot_id     = sanitize_text_field( $bot_id );
		$account_id = preg_replace( '/[^0-9]/', '', $account_id );
		$region     = sanitize_text_field( $region );

		if ( '' === $bot_id || '' === $account_id || '' === $region ) {
			return '';
		}

		return sprintf(
			'arn:aws:lex:%1$s:%2$s:bot/%3$s',
			$region,
			$account_id,
			$bot_id
		);
	}

	/**
	 * Add or replace tags on a Lex resource.
	 *
	 * @param string               $resource_arn Resource ARN.
	 * @param array<string,string> $tags         Tags to add.
	 * @return true|WP_Error
	 */
	public function tag_lex_resource( string $resource_arn, array $tags ) {
		$resource_arn = sanitize_text_field( $resource_arn );
		$tags         = array_filter(
			array_map(
				static function ( $value ) {
					return sanitize_text_field( (string) $value );
				},
				$tags
			),
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);

		if ( '' === $resource_arn ) {
			return new WP_Error( 'ace_connect_lex_resource_arn_missing', __( 'Please provide the Amazon Lex resource ARN first.', 'adaptive-customer-engagement' ) );
		}

		if ( empty( $tags ) ) {
			return new WP_Error( 'ace_connect_lex_tags_missing', __( 'Please provide at least one Amazon Lex tag.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'lex',
			'POST',
			'/tags/' . rawurlencode( $resource_arn ),
			array(
				'tags' => $tags,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Ensure Connect-required tags exist on the Lex bot and alias, retrying while Lex settles.
	 *
	 * @param string               $bot_arn    Bot ARN.
	 * @param array<string,string> $bot_tags   Bot tags.
	 * @param string               $alias_arn  Optional alias ARN.
	 * @param array<string,string> $alias_tags Optional alias tags.
	 * @return true|WP_Error
	 */
	public function ensure_lex_connect_tags( string $bot_arn, array $bot_tags, string $alias_arn = '', array $alias_tags = array() ) {
		$attempts     = 6;
		$delay_second = 5;
		$resources    = array(
			array(
				'arn'  => sanitize_text_field( $bot_arn ),
				'tags' => $bot_tags,
			),
		);

		if ( '' !== $alias_arn && ! empty( $alias_tags ) ) {
			$resources[] = array(
				'arn'  => sanitize_text_field( $alias_arn ),
				'tags' => $alias_tags,
			);
		}

		foreach ( $resources as $resource ) {
			$last_error = null;

			for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
				$result = $this->tag_lex_resource( $resource['arn'], $resource['tags'] );

				if ( ! is_wp_error( $result ) ) {
					$last_error = null;
					break;
				}

				$last_error = $result;
				$message    = strtolower( $result->get_error_message() );
				$should_wait = false !== strpos( $message, 'available or versioning state' )
					|| false !== strpos( $message, 'resource is in a conflicting state' )
					|| false !== strpos( $message, 'conflicting state' );

				if ( ! $should_wait || $attempt === $attempts ) {
					break;
				}

				sleep( $delay_second );
			}

			if ( is_wp_error( $last_error ) ) {
				return $last_error;
			}
		}

		return true;
	}

	/**
	 * List tags for a Lex resource.
	 *
	 * @param string $resource_arn Resource ARN.
	 * @return array<string,string>|WP_Error
	 */
	public function list_lex_resource_tags( string $resource_arn ) {
		$resource_arn = sanitize_text_field( $resource_arn );

		if ( '' === $resource_arn ) {
			return new WP_Error( 'ace_connect_lex_resource_arn_missing', __( 'Please provide the Amazon Lex resource ARN first.', 'adaptive-customer-engagement' ) );
		}

		$response = $this->request(
			'lex',
			'GET',
			'/tags/' . rawurlencode( $resource_arn )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['tags'] ) && is_array( $response['tags'] ) ? $response['tags'] : array();
	}
}
