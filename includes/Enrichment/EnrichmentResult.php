<?php
/**
 * Enrichment result DTO.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

defined( 'ABSPATH' ) || exit;

final class EnrichmentResult {
	/** @var string */
	public $provider = 'none';
	/** @var string|null */
	public $company_name;
	/** @var string|null */
	public $company_domain;
	/** @var string|null */
	public $company_type;
	/** @var string|null */
	public $country_code;
	/** @var string|null */
	public $region;
	/** @var string|null */
	public $city;
	/** @var string|null */
	public $asn;
	/** @var string|null */
	public $isp;
	/** @var bool|null */
	public $is_hosting;
	/** @var bool|null */
	public $is_vpn;
	/** @var bool|null */
	public $is_proxy;
	/** @var string */
	public $confidence = 'unknown';
	/** @var array<string, mixed> */
	public $raw = array();
	/** @var string|null Safe provider failure code. */
	public $error_code;
	/** @var int Provider retry hint in seconds. */
	public $retry_after = 0;

	public function is_error(): bool {
		return null !== $this->error_code || isset( $this->raw['error'] ) || ( isset( $this->raw['code'] ) && isset( $this->raw['message'] ) );
	}

	public static function error( string $provider, string $code, int $retry_after = 0 ): self {
		$result = new self();
		$result->provider = $provider;
		$result->error_code = self::safe_error_code( $code );
		$result->retry_after = $retry_after;
		$result->raw = array( 'error' => array( 'code' => $result->error_code ) );
		return $result;
	}

	private static function safe_error_code( string $code ): string {
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_]/', '_', $code ) );
		return substr( $code ?: 'PROVIDER_ERROR', 0, 64 );
	}

	/** Accept numeric and HTTP-date Retry-After values without persisting headers. */
	public static function retry_seconds( $header ): int {
		if ( ! is_scalar( $header ) ) {
			return 0;
		}
		$value = trim( (string) $header );
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		$timestamp = strtotime( $value );
		return false === $timestamp ? 0 : max( 0, $timestamp - time() );
	}

	/** Keep only diagnostics needed to classify a cached result. */
	public static function safe_raw( array $raw ): array {
		if ( isset( $raw['error'] ) || ( isset( $raw['code'] ) && isset( $raw['message'] ) ) ) {
			$code = is_array( $raw['error'] ?? null ) ? (string) ( $raw['error']['code'] ?? 'PROVIDER_ERROR' ) : (string) ( $raw['code'] ?? 'PROVIDER_ERROR' );
			return array( 'error' => array( 'code' => self::safe_error_code( $code ) ) );
		}
		$safe = array();
		foreach ( array( 'asn_type', 'network_kind' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
				$safe[ $key ] = sanitize_key( $raw[ $key ] );
			}
		}
		foreach ( array( 'scrubbed_person', 'ptr_checked', 'ptr_hosting', 'ptr_domain_match' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$safe[ $key ] = (bool) $raw[ $key ];
			}
		}
		return $safe;
	}

	/** Identify a legacy provider response even if local confirmation changed its source. */
	public static function looks_like_provider_raw( array $raw ): bool {
		foreach ( array( 'ip', 'ptr', 'connection', 'security', 'privacy', 'rdap', 'cymru', 'asname', 'hostname', 'network_kind', 'asn_type' ) as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert the result to an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'provider'       => $this->provider,
			'company_name'   => $this->company_name,
			'company_domain' => $this->company_domain,
			'company_type'   => $this->company_type,
			'country_code'   => $this->country_code,
			'region'         => $this->region,
			'city'           => $this->city,
			'asn'            => $this->asn,
			'isp'            => $this->isp,
			'is_hosting'     => $this->is_hosting,
			'is_vpn'         => $this->is_vpn,
			'is_proxy'       => $this->is_proxy,
			'confidence'     => $this->confidence,
			'raw'            => self::safe_raw( $this->raw ),
			'error_code'     => $this->error_code,
			'retry_after'    => $this->retry_after,
		);
	}

	/**
	 * Build a result from an array payload.
	 *
	 * @param array<string, mixed> $data Result data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$result                 = new self();
		$result->provider       = (string) ( $data['provider'] ?? 'none' );
		$result->company_name   = isset( $data['company_name'] ) ? (string) $data['company_name'] : null;
		$result->company_domain = isset( $data['company_domain'] ) ? (string) $data['company_domain'] : null;
		$result->company_type   = isset( $data['company_type'] ) ? (string) $data['company_type'] : null;
		$result->country_code   = isset( $data['country_code'] ) ? (string) $data['country_code'] : null;
		$result->region         = isset( $data['region'] ) ? (string) $data['region'] : null;
		$result->city           = isset( $data['city'] ) ? (string) $data['city'] : null;
		$result->asn            = isset( $data['asn'] ) ? (string) $data['asn'] : null;
		$result->isp            = isset( $data['isp'] ) ? (string) $data['isp'] : null;
		$result->is_hosting     = isset( $data['is_hosting'] ) ? (bool) $data['is_hosting'] : null;
		$result->is_vpn         = isset( $data['is_vpn'] ) ? (bool) $data['is_vpn'] : null;
		$result->is_proxy       = isset( $data['is_proxy'] ) ? (bool) $data['is_proxy'] : null;
		$result->confidence     = isset( $data['confidence'] ) ? (string) $data['confidence'] : 'unknown';
		$raw = isset( $data['raw'] ) && is_array( $data['raw'] ) ? $data['raw'] : array();
		$result->raw            = self::safe_raw( $raw );
		if ( array_key_exists( 'ptr', $raw ) ) {
			$result->raw['ptr_checked'] = true;
			$result->raw['ptr_hosting'] = true === $result->is_hosting;
		}
		$result->error_code     = ! empty( $data['error_code'] ) ? self::safe_error_code( (string) $data['error_code'] ) : ( isset( $result->raw['error'] ) ? $result->raw['error']['code'] : null );
		$result->retry_after    = max( 0, (int) ( $data['retry_after'] ?? 0 ) );

		return $result;
	}
}
