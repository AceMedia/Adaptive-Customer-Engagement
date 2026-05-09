<?php
/**
 * Enrichment provider registry.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {
	/**
	 * Get the currently selected provider.
	 *
	 * @return ProviderInterface
	 */
	public function get_active_provider(): ProviderInterface {
		$settings = Settings::get();
		$provider = sanitize_key( (string) ( $settings['enrichment']['provider'] ?? 'none' ) );

		switch ( $provider ) {
			case 'ipregistry':
				return new IpregistryProvider();
			case 'ipinfo':
				return new IpinfoProvider();
			case 'none':
			default:
				return new NullProvider();
		}
	}
}
