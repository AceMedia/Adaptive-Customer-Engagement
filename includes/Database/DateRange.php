<?php
/**
 * UTC date-range helpers for repository filters.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database;

defined( 'ABSPATH' ) || exit;

final class DateRange {
	/**
	 * Append inclusive start / exclusive end date filters to a query fragment list.
	 *
	 * @param array<int, string> $where  SQL where fragments.
	 * @param array<int, mixed>  $params Query params.
	 * @param string             $column Datetime column name.
	 * @param string             $from   From date in Y-m-d format.
	 * @param string             $to     To date in Y-m-d format.
	 * @return void
	 */
	public static function append_filters( array &$where, array &$params, string $column, string $from = '', string $to = '' ): void {
		$from_datetime = self::day_start( $from );
		$to_datetime   = self::next_day_start( $to );

		if ( $from_datetime ) {
			$where[]  = "{$column} >= %s";
			$params[] = $from_datetime;
		}

		if ( $to_datetime ) {
			$where[]  = "{$column} < %s";
			$params[] = $to_datetime;
		}
	}

	/**
	 * Get the current UTC day bounds.
	 *
	 * @return array{start:string,end:string}
	 */
	public static function today_bounds(): array {
		$start = gmdate( 'Y-m-d 00:00:00' );

		return array(
			'start' => $start,
			'end'   => gmdate( 'Y-m-d 00:00:00', strtotime( $start . ' +1 day' ) ),
		);
	}

	/**
	 * Normalise a date-only value to UTC day start.
	 *
	 * @param string $date Date value.
	 * @return string
	 */
	private static function day_start( string $date ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		return $date . ' 00:00:00';
	}

	/**
	 * Normalise a date-only value to the next UTC day start.
	 *
	 * @param string $date Date value.
	 * @return string
	 */
	private static function next_day_start( string $date ): string {
		$start = self::day_start( $date );

		if ( '' === $start ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +1 day' ) );
	}
}
