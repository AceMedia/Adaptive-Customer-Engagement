<?php
/**
 * Phone number resolver.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\NumberRepository;

defined( 'ABSPATH' ) || exit;

final class NumberResolver {
	/**
	 * Number repository.
	 *
	 * @var NumberRepository
	 */
	private $numbers;

	/**
	 * Constructor.
	 *
	 * @param NumberRepository $numbers Number repository.
	 */
	public function __construct( NumberRepository $numbers ) {
		$this->numbers = $numbers;
	}

	/**
	 * Resolve the best number for the current context.
	 *
	 * @param array<string, string> $context Context.
	 * @return array<string, mixed>|null
	 */
	public function resolve( array $context ): ?array {
		$path         = (string) ( $context['path'] ?? '' );
		$utm_source   = strtolower( (string) ( $context['utm_source'] ?? '' ) );
		$utm_campaign = strtolower( (string) ( $context['utm_campaign'] ?? '' ) );
		$candidates   = $this->numbers->active();
		$default      = null;

		foreach ( $candidates as $candidate ) {
			if ( ! empty( $candidate['is_default'] ) ) {
				$default = $candidate;
				continue;
			}

			if ( $this->matches( $candidate, $path, $utm_source, $utm_campaign ) ) {
				return $candidate;
			}
		}

		return $default ?: ( $candidates[0] ?? null );
	}

	/**
	 * Determine whether a number matches.
	 *
	 * @param array<string, mixed> $number       Number row.
	 * @param string               $path         Path.
	 * @param string               $utm_source   UTM source.
	 * @param string               $utm_campaign UTM campaign.
	 * @return bool
	 */
	private function matches( array $number, string $path, string $utm_source, string $utm_campaign ): bool {
		$source_type  = (string) $number['source_type'];
		$source_value = strtolower( (string) $number['source_value'] );
		$page_type    = (string) $number['page_match_type'];
		$page_value   = (string) $number['page_match_value'];
		$campaign     = strtolower( (string) $number['campaign_match'] );

		if ( $page_value && ! $this->matches_path( $page_type, $page_value, $path ) ) {
			return false;
		}

		if ( $campaign && false === strpos( $utm_campaign, $campaign ) ) {
			return false;
		}

		switch ( $source_type ) {
			case 'campaign':
				return '' !== $utm_campaign || '' !== $utm_source;
			case 'google_business_profile':
				return str_contains( $utm_source, 'google' ) || 'google_business_profile' === $source_value;
			case 'bing':
				return str_contains( $utm_source, 'bing' );
			case 'social':
				return in_array( $utm_source, array( 'facebook', 'instagram', 'linkedin', 'x', 'twitter' ), true ) || ( $source_value && false !== strpos( $utm_source, $source_value ) );
			case 'default':
				return true;
			default:
				return '' === $source_value || false !== strpos( $utm_source, $source_value );
		}
	}

	/**
	 * Match a path against a rule.
	 *
	 * @param string $type  Match type.
	 * @param string $rule  Rule.
	 * @param string $path  Path.
	 * @return bool
	 */
	private function matches_path( string $type, string $rule, string $path ): bool {
		switch ( $type ) {
			case 'exact':
				return $path === $rule;
			case 'prefix':
				return 0 === strpos( $path, $rule );
			case 'regex':
				return 1 === preg_match( '/' . $rule . '/', $path );
			case 'contains':
			default:
				return false !== strpos( $path, $rule );
		}
	}
}
