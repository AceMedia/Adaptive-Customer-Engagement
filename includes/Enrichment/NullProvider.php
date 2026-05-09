<?php
/**
 * Null enrichment provider.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

defined( 'ABSPATH' ) || exit;

final class NullProvider implements ProviderInterface {
	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'none';
	}

	/**
	 * Determine whether the provider is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return true;
	}

	/**
	 * Return an empty lookup result.
	 *
	 * @param string $ip IP address.
	 * @return EnrichmentResult
	 */
	public function lookup( string $ip ): EnrichmentResult {
		$result           = new EnrichmentResult();
		$result->provider = 'none';
		$result->raw      = array(
			'message' => 'No enrichment provider has been configured yet.',
			'ip'      => $ip,
		);

		return $result;
	}
}
