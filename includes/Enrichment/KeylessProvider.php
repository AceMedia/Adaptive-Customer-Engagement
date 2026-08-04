<?php
/**
 * Keyless enrichment provider.
 *
 * Free, no-API-key enrichment built from authoritative public sources:
 * Team Cymru's IP-to-ASN DNS service, registry RDAP records, and reverse
 * DNS. No visitor data is shared with any commercial enrichment vendor.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Enrichment;

defined( 'ABSPATH' ) || exit;

final class KeylessProvider implements ProviderInterface {
	/**
	 * Keywords that mark an organisation as a consumer ISP / carrier rather
	 * than an identifiable business visitor.
	 *
	 * @var string[]
	 */
	private const ISP_KEYWORDS = array(
		'broadband',
		'telecom',
		'telekom',
		'communications',
		'cable',
		'mobile',
		'wireless',
		'cellular',
		'internet service',
		'virgin media',
		'sky uk',
		'british telecom',
		'talktalk',
		'vodafone',
		'plusnet',
		'hyperoptic',
		'zen internet',
		'comcast',
		'verizon',
		'at&t',
		'orange',
		'telefonica',
		'deutsche telekom',
		'kpn',
		'ziggo',
	);

	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'keyless';
	}

	/**
	 * The keyless provider needs no API key.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return true;
	}

	/**
	 * Look up an IP address using DNS + RDAP.
	 *
	 * @param string $ip IP address.
	 * @return EnrichmentResult
	 */
	public function lookup( string $ip ): EnrichmentResult {
		$result           = new EnrichmentResult();
		$result->provider = $this->get_name();

		$origin = DnsIntel::cymru_origin( $ip );
		$ptr    = DnsIntel::ptr( $ip );
		$rdap   = $this->rdap_lookup( $ip );

		$asn     = $origin['asn'] ?? null;
		$as_name = $asn ? DnsIntel::cymru_as_name( $asn ) : null;

		$result->asn          = $asn ? 'AS' . $asn : null;
		$result->isp          = $as_name;
		$result->country_code = $rdap['country'] ?? ( $origin['country'] ?? null );

		$org_name = $rdap['org'] ?? null;

		if ( null !== $org_name && $this->looks_like_network_handle( $org_name ) ) {
			$org_name = null;
		}

		if ( null === $org_name && ! empty( $rdap['network_name'] ) && ! $this->looks_like_network_handle( $rdap['network_name'] ) ) {
			$org_name = $rdap['network_name'];
		}

		if ( null === $org_name ) {
			$org_name = $this->org_from_as_name( $as_name );
		}

		$result->company_name = $org_name;
		$result->is_hosting   = DnsIntel::is_hosting_asn( $asn ) || DnsIntel::is_hosting_host( $ptr );

		// A corporate PTR (mail.acme.co.uk) yields a usable company domain;
		// skip it for datacentre machines where the PTR names the host, not
		// the visitor.
		if ( $ptr && ! $result->is_hosting ) {
			$result->company_domain = DnsIntel::registrable_domain( $ptr );
		}

		$result->confidence = $this->map_confidence( $result );
		$result->raw        = array_filter(
			array(
				'ip'     => $ip,
				'ptr'    => $ptr,
				'cymru'  => $origin,
				'rdap'   => $rdap,
				'asname' => $as_name,
			)
		);

		if ( null === $origin && null === $rdap && null === $ptr ) {
			$result->raw['error'] = 'No DNS or RDAP data available for this address.';
		}

		return $result;
	}

	/**
	 * Query RDAP for the IP's registered network and organisation.
	 *
	 * rdap.org bootstraps the query to the owning registry (RIPE, ARIN, …).
	 *
	 * @param string $ip IP address.
	 * @return array{network_name: ?string, org: ?string, country: ?string, handle: ?string}|null
	 */
	private function rdap_lookup( string $ip ): ?array {
		$response = wp_remote_get(
			'https://rdap.org/ip/' . rawurlencode( $ip ),
			array(
				'timeout'     => 5,
				'redirection' => 5,
				'headers'     => array(
					'Accept' => 'application/rdap+json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return array(
			'network_name' => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : null,
			'org'          => $this->extract_registrant_name( $data['entities'] ?? array() ),
			'country'      => isset( $data['country'] ) ? strtoupper( sanitize_text_field( (string) $data['country'] ) ) : null,
			'handle'       => isset( $data['handle'] ) ? sanitize_text_field( (string) $data['handle'] ) : null,
		);
	}

	/**
	 * Pull the registrant organisation's display name out of RDAP entities.
	 *
	 * @param mixed $entities RDAP entities array.
	 * @param int   $depth    Recursion depth guard.
	 * @return string|null
	 */
	private function extract_registrant_name( $entities, int $depth = 0 ): ?string {
		if ( ! is_array( $entities ) || $depth > 2 ) {
			return null;
		}

		$fallback = null;

		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}

			$roles = array_map( 'strtolower', (array) ( $entity['roles'] ?? array() ) );
			$name  = $this->vcard_fn( $entity['vcardArray'] ?? null );

			if ( null === $name && ! empty( $entity['entities'] ) ) {
				$name = $this->extract_registrant_name( $entity['entities'], $depth + 1 );
			}

			if ( null === $name ) {
				continue;
			}

			if ( in_array( 'registrant', $roles, true ) ) {
				return $name;
			}

			if ( null === $fallback && array_intersect( array( 'administrative', 'technical' ), $roles ) ) {
				$fallback = $name;
			}
		}

		return $fallback;
	}

	/**
	 * Read the fn (formatted name) property from a jCard structure.
	 *
	 * @param mixed $vcard jCard array.
	 * @return string|null
	 */
	private function vcard_fn( $vcard ): ?string {
		if ( ! is_array( $vcard ) || ! isset( $vcard[1] ) || ! is_array( $vcard[1] ) ) {
			return null;
		}

		foreach ( $vcard[1] as $property ) {
			if ( is_array( $property ) && 'fn' === ( $property[0] ?? '' ) && ! empty( $property[3] ) && is_string( $property[3] ) ) {
				$name = sanitize_text_field( $property[3] );

				return '' !== $name ? $name : null;
			}
		}

		return null;
	}

	/**
	 * Detect RDAP network names that are registry handles (ACME-NET-20) and
	 * not human-readable organisation names.
	 *
	 * @param string $name Network name.
	 * @return bool
	 */
	private function looks_like_network_handle( string $name ): bool {
		if ( preg_match( '/\b(MNT|HOSTMASTER|NOC|ABUSE)\b/i', $name ) ) {
			return true;
		}

		return (bool) preg_match( '/^[A-Z0-9][A-Z0-9_-]*$/', $name ) && false === strpos( $name, ' ' );
	}

	/**
	 * Derive an organisation name from a Cymru AS name string, which is
	 * formatted as "HANDLE - Organisation Name, CC".
	 *
	 * @param string|null $as_name AS name.
	 * @return string|null
	 */
	private function org_from_as_name( ?string $as_name ): ?string {
		if ( null === $as_name || false === strpos( $as_name, ' - ' ) ) {
			return null;
		}

		$org = trim( (string) substr( $as_name, strpos( $as_name, ' - ' ) + 3 ) );
		$org = (string) preg_replace( '/,\s*[A-Z]{2}$/', '', $org );

		return '' !== $org && ! $this->looks_like_network_handle( $org ) ? $org : null;
	}

	/**
	 * Map the gathered signals to a confidence level.
	 *
	 * @param EnrichmentResult $result Enrichment result.
	 * @return string
	 */
	private function map_confidence( EnrichmentResult $result ): string {
		if ( true === $result->is_hosting ) {
			return 'weak';
		}

		$org = strtolower( (string) $result->company_name . ' ' . (string) $result->isp );

		foreach ( self::ISP_KEYWORDS as $keyword ) {
			if ( false !== strpos( $org, $keyword ) ) {
				return 'weak';
			}
		}

		if ( $result->company_name ) {
			return 'likely';
		}

		if ( $result->isp ) {
			return 'weak';
		}

		return 'unknown';
	}
}
