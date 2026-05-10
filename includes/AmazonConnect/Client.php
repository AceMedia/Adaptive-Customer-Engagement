<?php
/**
 * Minimal signed AWS client for Amazon Connect and Amazon Q in Connect.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AmazonConnect;

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

		if ( ! empty( $config['use_iam_role'] ) ) {
			return new WP_Error( 'ace_connect_iam_role_mode', __( 'Direct plugin requests currently expect saved AWS access keys rather than IAM role mode.', 'adaptive-customer-engagement' ) );
		}

		$access_key_id     = sanitize_text_field( (string) ( $config['access_key_id'] ?? '' ) );
		$secret_access_key = (string) ( $config['secret_access_key'] ?? '' );

		if ( '' === $access_key_id || '' === $secret_access_key ) {
			return new WP_Error( 'ace_connect_credentials_missing', __( 'Please save the AWS access key ID and secret access key first.', 'adaptive-customer-engagement' ) );
		}

		return array(
			'region'            => $region,
			'instance_id'       => sanitize_text_field( (string) ( $config['instance_id'] ?? '' ) ),
			'access_key_id'     => $access_key_id,
			'secret_access_key' => $secret_access_key,
		);
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

		$host = $this->get_host( $service, $config['region'] );

		if ( '' === $host ) {
			return new WP_Error( 'ace_connect_unknown_service', __( 'Unsupported AWS service requested.', 'adaptive-customer-engagement' ) );
		}

		$payload        = 'GET' === strtoupper( $method ) ? '' : wp_json_encode( $body );
		$payload        = is_string( $payload ) ? $payload : '';
		$payload_hash   = hash( 'sha256', $payload );
		$amz_date       = gmdate( 'Ymd\THis\Z' );
		$date_stamp     = gmdate( 'Ymd' );
		$canonical_uri  = $this->build_canonical_uri( $path );
		$query_string   = $this->build_query_string( $query );
		$signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';
		$canonical_headers = 'content-type:application/json' . "\n" .
			'host:' . $host . "\n" .
			'x-amz-content-sha256:' . $payload_hash . "\n" .
			'x-amz-date:' . $amz_date . "\n";
		$canonical_request = strtoupper( $method ) . "\n" .
			$canonical_uri . "\n" .
			$query_string . "\n" .
			$canonical_headers . "\n" .
			$signed_headers . "\n" .
			$payload_hash;
		$scope          = $date_stamp . '/' . $config['region'] . '/' . $service . '/aws4_request';
		$string_to_sign = 'AWS4-HMAC-SHA256' . "\n" .
			$amz_date . "\n" .
			$scope . "\n" .
			hash( 'sha256', $canonical_request );
		$signing_key    = $this->get_signature_key( $config['secret_access_key'], $date_stamp, $config['region'], $service );
		$signature      = hash_hmac( 'sha256', $string_to_sign, $signing_key );
		$authorization  = 'AWS4-HMAC-SHA256 Credential=' . $config['access_key_id'] . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
		$url            = 'https://' . $host . $canonical_uri;

		if ( '' !== $query_string ) {
			$url .= '?' . $query_string;
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => strtoupper( $method ),
				'timeout' => 20,
				'headers' => array(
					'Authorization'         => $authorization,
					'Content-Type'          => 'application/json',
					'Host'                  => $host,
					'X-Amz-Content-Sha256'  => $payload_hash,
					'X-Amz-Date'            => $amz_date,
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
			$data    = '' !== $raw_body ? json_decode( $raw_body, true ) : array();
			$message = is_array( $data )
				? (string) ( $data['Message'] ?? $data['message'] ?? wp_remote_retrieve_response_message( $response ) )
				: (string) wp_remote_retrieve_response_message( $response );

			return new WP_Error(
				'ace_connect_request_failed',
				'' !== $message ? $message : __( 'The AWS request failed.', 'adaptive-customer-engagement' ),
				array(
					'status' => $status_code,
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
		$segments = array_map( 'rawurlencode', explode( '/', ltrim( $path, '/' ) ) );

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
			case 's3':
				return 's3.' . $region . '.amazonaws.com';
			case 'wisdom':
				return 'wisdom.' . $region . '.amazonaws.com';
			default:
				return '';
		}
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
}
