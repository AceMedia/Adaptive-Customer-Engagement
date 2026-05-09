<?php
/**
 * Simple transient rate limiter.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Security;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {
	/**
	 * Check whether a request is allowed.
	 *
	 * @param string $bucket Bucket key.
	 * @param int    $limit  Allowed requests.
	 * @param int    $window Window in seconds.
	 * @return bool
	 */
	public function allow( string $bucket, int $limit, int $window ): bool {
		$key   = 'ace_rl_' . md5( $bucket );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}
}
