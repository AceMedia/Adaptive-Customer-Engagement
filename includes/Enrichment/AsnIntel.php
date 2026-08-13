<?php
/**
 * ASN classification via PeeringDB.
 *
 * PeeringDB is the industry registry where networks describe themselves;
 * its `info_type` field says authoritatively whether an ASN is a consumer
 * ISP, a transit carrier, a hosting/content network, or an end-user
 * enterprise. That beats name keywords: the org that owns a visitor's IP
 * range can be typed at the source. Anonymous access works at low volume;
 * a key from a free PeeringDB account raises the rate limit.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

final class AsnIntel {
	/**
	 * How long a PeeringDB answer is cached. Network types near enough
	 * never change.
	 */
	private const CACHE_TTL = 90 * DAY_IN_SECONDS;

	/**
	 * How long "not listed in PeeringDB" is cached.
	 */
	private const MISS_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Back-off window after a rate-limit or transport error.
	 */
	private const BACKOFF_TTL = HOUR_IN_SECONDS;

	/**
	 * PeeringDB info_type → the classification used by enrichment.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_MAP = array(
		'cable/dsl/isp'        => 'isp',
		'nsp'                  => 'isp',
		'network services'     => 'isp',
		'content'              => 'hosting',
		'enterprise'           => 'business',
		'educational/research' => 'education',
		'government'           => 'government',
		'non-profit'           => 'organisation',
	);

	/**
	 * Classify an ASN via PeeringDB, heavily cached.
	 *
	 * @param int|null $asn ASN.
	 * @return string|null 'isp', 'hosting', 'business', 'education',
	 *                     'government', 'organisation', or null when the
	 *                     ASN is unknown, unlisted, or lookups are backed off.
	 */
	public static function network_type( ?int $asn ): ?string {
		if ( null === $asn || $asn <= 0 ) {
			return null;
		}

		$cache_key = 'ace_asn_type_' . $asn;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return 'none' === $cached ? null : (string) $cached;
		}

		if ( get_transient( 'ace_peeringdb_backoff' ) ) {
			return null;
		}

		$type = self::fetch( $asn );

		if ( 'backoff' === $type ) {
			set_transient( 'ace_peeringdb_backoff', 1, self::BACKOFF_TTL );

			return null;
		}

		set_transient( $cache_key, $type ?? 'none', null === $type ? self::MISS_TTL : self::CACHE_TTL );

		return $type;
	}

	/**
	 * Query the PeeringDB net endpoint for an ASN.
	 *
	 * @param int $asn ASN.
	 * @return string|null Classification, null when unlisted, or the
	 *                     sentinel 'backoff' on rate-limit/transport errors.
	 */
	private static function fetch( int $asn ): ?string {
		$settings = Settings::get();
		$api_key  = (string) ( $settings['enrichment']['peeringdb_api_key'] ?? '' );
		$headers  = array( 'Accept' => 'application/json' );

		if ( '' !== $api_key ) {
			$headers['Authorization'] = 'Api-Key ' . $api_key;
		}

		$response = wp_remote_get(
			sprintf( 'https://www.peeringdb.com/api/net?asn=%d&depth=0', $asn ),
			array(
				'timeout' => 4,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'backoff';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code || $code >= 500 ) {
			return 'backoff';
		}

		if ( 200 !== $code ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$type = is_array( $body ) ? strtolower( (string) ( $body['data'][0]['info_type'] ?? '' ) ) : '';

		return self::TYPE_MAP[ $type ] ?? null;
	}
}
