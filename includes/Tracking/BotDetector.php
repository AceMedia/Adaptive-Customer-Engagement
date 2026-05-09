<?php
/**
 * Basic bot detection.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

defined( 'ABSPATH' ) || exit;

final class BotDetector {
	/**
	 * Determine whether the user agent looks like a bot.
	 *
	 * @param string $user_agent User agent string.
	 * @return bool
	 */
	public function is_bot( string $user_agent ): bool {
		if ( '' === $user_agent ) {
			return true;
		}

		$patterns = array(
			'bot',
			'spider',
			'crawler',
			'crawl',
			'headless',
			'monitor',
			'uptime',
			'curl/',
			'python-requests',
			'googlebot',
			'bingbot',
		);

		$user_agent = strtolower( $user_agent );

		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $user_agent, $pattern ) ) {
				return true;
			}
		}

		return false;
	}
}
