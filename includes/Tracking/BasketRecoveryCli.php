<?php
/**
 * WP-CLI: abandoned basket quote email stats.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\BasketRecoveryRepository;

defined( 'ABSPATH' ) || exit;

final class BasketRecoveryCli {
	/**
	 * Show basket recovery counts (distinct baskets per step).
	 *
	 * ## OPTIONS
	 *
	 * [--from=<date>]
	 * : Start date, Y-m-d. Defaults to 30 days before --to.
	 *
	 * [--to=<date>]
	 * : End date, Y-m-d. Defaults to today.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ace basket-recovery stats --from=2026-10-01 --url=https://iegemea.com
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public function stats( $args, $assoc_args ): void {
		$service = new BasketRecoveryService( new BasketRecoveryRepository() );
		$result  = $service->stats( (string) ( $assoc_args['from'] ?? '' ), (string) ( $assoc_args['to'] ?? '' ) );
		$format  = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'json' === $format ) {
			\WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Baskets %s to %s', $result['from'], $result['to'] ) );
		$rows = array();
		foreach ( $result['counts'] as $step => $count ) {
			$rows[] = array(
				'step'    => $step,
				'baskets' => $count,
			);
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'step', 'baskets' ) );
	}
}
