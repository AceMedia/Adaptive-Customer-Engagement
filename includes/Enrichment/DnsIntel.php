<?php
/**
 * Keyless DNS-based network intelligence helpers.
 *
 * Wraps reverse DNS (PTR) and the Team Cymru IP-to-ASN DNS service, plus
 * static hosting/datacentre heuristics. Everything here is free, keyless,
 * and safe for commercial use.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

defined( 'ABSPATH' ) || exit;

final class DnsIntel {
	/**
	 * ASNs operated by hosting/cloud providers. Traffic from these is
	 * datacentre traffic, not a business browsing from its office.
	 *
	 * @var array<int, true>
	 */
	private const HOSTING_ASNS = array(
		16509  => true, // Amazon AWS.
		14618  => true, // Amazon AES.
		8987   => true, // Amazon (Europe).
		15169  => true, // Google.
		396982 => true, // Google Cloud.
		8075   => true, // Microsoft.
		12076  => true, // Microsoft Azure.
		13335  => true, // Cloudflare.
		16276  => true, // OVH.
		24940  => true, // Hetzner.
		213230 => true, // Hetzner Cloud.
		14061  => true, // DigitalOcean.
		63949  => true, // Linode / Akamai.
		20940  => true, // Akamai.
		16625  => true, // Akamai.
		54113  => true, // Fastly.
		20473  => true, // Vultr / Choopa.
		51167  => true, // Contabo.
		12876  => true, // Scaleway.
		8560   => true, // IONOS.
		26496  => true, // GoDaddy.
		45102  => true, // Alibaba Cloud.
		31898  => true, // Oracle Cloud.
		45090  => true, // Tencent Cloud.
		132203 => true, // Tencent Cloud (intl).
		60781  => true, // Leaseweb.
		9009   => true, // M247.
	);

	/**
	 * Reverse-DNS suffixes that identify datacentre machines.
	 *
	 * @var string[]
	 */
	private const HOSTING_PTR_SUFFIXES = array(
		'.amazonaws.com',
		'.googleusercontent.com',
		'.cloudapp.net',
		'.cloudapp.azure.com',
		'.linodeusercontent.com',
		'.vultrusercontent.com',
		'.your-server.de',
		'.ovh.net',
		'.ovh.ca',
		'.fastly.net',
		'.akamaitechnologies.com',
		'.hetzner.com',
		'.contaboserver.net',
		'.scaleway.com',
	);

	/**
	 * Second-level public suffixes for naive registrable-domain extraction.
	 *
	 * @var array<string, true>
	 */
	private const SECOND_LEVEL_TLDS = array(
		'co.uk'  => true,
		'org.uk' => true,
		'ac.uk'  => true,
		'gov.uk' => true,
		'net.uk' => true,
		'com.au' => true,
		'net.au' => true,
		'org.au' => true,
		'co.nz'  => true,
		'co.jp'  => true,
		'or.jp'  => true,
		'ne.jp'  => true,
		'com.br' => true,
		'com.mx' => true,
		'co.za'  => true,
		'com.sg' => true,
		'com.hk' => true,
		'co.in'  => true,
	);

	/**
	 * Look up the PTR (reverse DNS) hostname for an IP.
	 *
	 * @param string $ip IP address.
	 * @return string|null Lower-case hostname, or null when none exists.
	 */
	public static function ptr( string $ip ): ?string {
		$reverse = self::reverse_name( $ip, 'in-addr.arpa', 'ip6.arpa' );

		if ( null === $reverse ) {
			return null;
		}

		$records = self::dns_txt_safe( $reverse, DNS_PTR );

		foreach ( $records as $record ) {
			if ( ! empty( $record['target'] ) ) {
				return strtolower( rtrim( (string) $record['target'], '.' ) );
			}
		}

		return null;
	}

	/**
	 * Look up origin ASN details via Team Cymru's DNS service.
	 *
	 * @param string $ip IP address.
	 * @return array{asn: int, prefix: string, country: string, registry: string}|null
	 */
	public static function cymru_origin( string $ip ): ?array {
		$reverse = self::reverse_name( $ip, 'origin.asn.cymru.com', 'origin6.asn.cymru.com' );

		if ( null === $reverse ) {
			return null;
		}

		foreach ( self::dns_txt_safe( $reverse, DNS_TXT ) as $record ) {
			$parts = array_map( 'trim', explode( '|', (string) ( $record['txt'] ?? '' ) ) );

			if ( count( $parts ) < 3 ) {
				continue;
			}

			// The ASN field can hold several space-separated ASNs; take the first.
			$asn = (int) strtok( $parts[0], ' ' );

			if ( $asn <= 0 ) {
				continue;
			}

			return array(
				'asn'      => $asn,
				'prefix'   => $parts[1],
				'country'  => strtoupper( $parts[2] ),
				'registry' => strtolower( $parts[3] ?? '' ),
			);
		}

		return null;
	}

	/**
	 * Look up the AS name for an ASN via Team Cymru's DNS service.
	 *
	 * @param int $asn ASN.
	 * @return string|null e.g. "VIRGINMEDIA Virgin Media Limited, GB".
	 */
	public static function cymru_as_name( int $asn ): ?string {
		if ( $asn <= 0 ) {
			return null;
		}

		foreach ( self::dns_txt_safe( sprintf( 'AS%d.asn.cymru.com', $asn ), DNS_TXT ) as $record ) {
			$parts = array_map( 'trim', explode( '|', (string) ( $record['txt'] ?? '' ) ) );
			$name  = end( $parts );

			if ( is_string( $name ) && '' !== $name ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Determine whether an ASN belongs to a hosting/cloud provider.
	 *
	 * @param int|null $asn ASN.
	 * @return bool
	 */
	public static function is_hosting_asn( ?int $asn ): bool {
		return null !== $asn && isset( self::HOSTING_ASNS[ $asn ] );
	}

	/**
	 * Determine whether a PTR hostname identifies a datacentre machine.
	 *
	 * @param string|null $host Hostname.
	 * @return bool
	 */
	public static function is_hosting_host( ?string $host ): bool {
		if ( null === $host || '' === $host ) {
			return false;
		}

		$host = strtolower( $host );

		foreach ( self::HOSTING_PTR_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the registrable domain from a hostname (naive public-suffix
	 * handling — good enough for PTR/company-domain comparison).
	 *
	 * @param string|null $host Hostname.
	 * @return string|null
	 */
	public static function registrable_domain( ?string $host ): ?string {
		if ( null === $host || '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$labels = explode( '.', strtolower( rtrim( $host, '.' ) ) );
		$count  = count( $labels );

		if ( $count < 2 ) {
			return null;
		}

		$last_two = implode( '.', array_slice( $labels, -2 ) );

		if ( isset( self::SECOND_LEVEL_TLDS[ $last_two ] ) ) {
			return $count >= 3 ? implode( '.', array_slice( $labels, -3 ) ) : null;
		}

		return $last_two;
	}

	/**
	 * Parse an ASN out of provider strings such as "AS13335" or "13335".
	 *
	 * @param string|null $asn ASN string.
	 * @return int|null
	 */
	public static function parse_asn( ?string $asn ): ?int {
		if ( null === $asn || '' === $asn ) {
			return null;
		}

		if ( preg_match( '/(\d+)/', $asn, $matches ) ) {
			$value = (int) $matches[1];

			return $value > 0 ? $value : null;
		}

		return null;
	}

	/**
	 * Build a reversed DNS query name for an IP.
	 *
	 * @param string $ip        IP address.
	 * @param string $v4_suffix Suffix for IPv4 queries.
	 * @param string $v6_suffix Suffix for IPv6 queries.
	 * @return string|null
	 */
	private static function reverse_name( string $ip, string $v4_suffix, string $v6_suffix ): ?string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return implode( '.', array_reverse( explode( '.', $ip ) ) ) . '.' . $v4_suffix;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );

			if ( false === $packed ) {
				return null;
			}

			$nibbles = str_split( strrev( bin2hex( $packed ) ) );

			return implode( '.', $nibbles ) . '.' . $v6_suffix;
		}

		return null;
	}

	/**
	 * Run dns_get_record() without letting warnings escape.
	 *
	 * @param string $name Query name.
	 * @param int    $type Record type constant.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dns_txt_safe( string $name, int $type ): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() warns on SERVFAIL; failure is an expected outcome here.
		$records = @dns_get_record( $name, $type );

		return is_array( $records ) ? $records : array();
	}
}
