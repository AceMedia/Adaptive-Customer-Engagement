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
			'raw'            => $this->raw,
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
		$result->raw            = isset( $data['raw'] ) && is_array( $data['raw'] ) ? $data['raw'] : array();

		return $result;
	}
}
