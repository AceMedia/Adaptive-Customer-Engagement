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
}
