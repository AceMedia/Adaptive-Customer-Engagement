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
	 * Name fragments that mark an organisation as an ISP/carrier — the
	 * network operator that owns the IP range, not the business browsing
	 * through it. Word-bounded except fibre/fiber, which also match
	 * prefixed brand names (Fibrenest).
	 *
	 * @var string
	 */
	private const ISP_NAME_PATTERN = '/(?<![a-z0-9])(broadband|fibre\w*|fiber\w*|telecoms?|telecommunications?|telcom|telco|telekom|telefonica|telenor|telenet|telia|radiotelephone|cable|cellular|wireless|mobile|internet|isp|comcast|verizon|at&t|sfr|vodafone|virgin media|talktalk|plusnet|hyperoptic|gigaclear|kcom|brsk|hutchison|starlink|jio|sky uk|[345]g)(?![a-z0-9])/i';

	/**
	 * Secure-web-gateway vendors: traffic egresses through their cloud, so
	 * the visitor IS a business — just not this one.
	 *
	 * @var string
	 */
	private const GATEWAY_NAME_PATTERN = '/(?<![a-z0-9])(zscaler|iboss|netskope|forcepoint|menlo security|cisco umbrella|palo alto networks|cato networks)(?![a-z0-9])/i';

	/**
	 * Tokens that mark a name as an organisation rather than a person.
	 *
	 * @var string
	 */
	private const ORG_TOKEN_PATTERN = '/\b(ltd|limited|llc|inc|plc|gmbh|bv|ab|oy|sa|sarl|srl|spa|ag|kg|llp|pvt|pty|corp|corporation|company|co|group|holdings?|enterprises?|associates?|partners?|international|global|industries|partner\w*|solutions?|services?|systems?|technolog\w*|digital|media|network\w*|fibre\w*|fiber\w*|telecom\w*|internet|hosting|cloud|data|consult\w*|engineering|software|agency|studio|labs?|council|university|college|school|nhs|trust|association|foundation|charity)\b/i';

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
	 * Classify the network operator behind an enrichment result.
	 *
	 * @param string|null ...$names Any of company name, AS/ISP name, company
	 *                              domain, provider company type.
	 * @return string|null 'isp', 'proxy', or null when the name looks like a
	 *                     genuine end-user organisation.
	 */
	public static function network_kind( ?string ...$names ): ?string {
		$haystack = strtolower( trim( implode( ' ', array_filter( $names ) ) ) );

		if ( '' === $haystack ) {
			return null;
		}

		if ( preg_match( self::GATEWAY_NAME_PATTERN, $haystack ) ) {
			return 'proxy';
		}

		if ( preg_match( self::ISP_NAME_PATTERN, $haystack ) ) {
			return 'isp';
		}

		return null;
	}

	/**
	 * Detect names that look like a private individual rather than an
	 * organisation (RDAP registrant contacts leak personal names). Kept
	 * conservative: two or three capitalised words with no digits and no
	 * recognisable organisation token.
	 *
	 * @param string|null $name Candidate name.
	 * @return bool
	 */
	public static function looks_like_person( ?string $name ): bool {
		if ( null === $name || '' === trim( $name ) || strlen( $name ) > 40 ) {
			return false;
		}

		if ( preg_match( '/[\d&@,\.]/', $name ) || preg_match( self::ORG_TOKEN_PATTERN, $name ) ) {
			return false;
		}

		return (bool) preg_match( '/^\p{Lu}[\p{L}\'’-]+(?: \p{Lu}[\p{L}\'’-]+){1,2}$/u', trim( $name ) );
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
