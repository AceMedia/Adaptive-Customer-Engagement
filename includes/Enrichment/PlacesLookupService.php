<?php
/** Administrator-requested Google Places business lookup. */
namespace ACE\AdaptiveCustomerEngagement\Enrichment;

defined( 'ABSPATH' ) || exit;

final class PlacesLookupService {
	public const OPTION = 'ace_places_settings';
	private const COUNT_OPTION = 'ace_places_requests';
	private const LOCK_OPTION = 'ace_places_lookup_lock';
	private const STATUS_OPTION = 'ace_places_lookup_status';
	private const COOLDOWN_OPTION = 'ace_places_cooldown_until';

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( array( 'enabled' => false, 'capture_addresses' => false, 'key' => '', 'country' => 'GB', 'language' => 'en', 'daily_limit' => 20 ), $saved );
	}

	public static function public_settings(): array {
		$settings = self::settings();
		$status = get_option( self::STATUS_OPTION, array() );
		return array(
			'enabled' => (bool) $settings['enabled'],
			'capture_addresses' => (bool) $settings['capture_addresses'],
			'has_key' => '' !== $settings['key'],
			'country' => $settings['country'],
			'language' => $settings['language'],
			'daily_limit' => (int) $settings['daily_limit'],
			'requests_today' => self::requests_today(),
			'status' => self::status_for_settings( $settings, $status ),
			'staging_blocked' => self::is_staging(),
		);
	}

	/** A success belongs only to the enabled key generation that made the call. */
	public static function status_for_settings( array $settings, $status ): string {
		return ! empty( $settings['enabled'] ) && ! empty( $settings['key'] ) && is_array( $status ) && ( $status['key_hash'] ?? '' ) === self::generation_hash( $settings ) ? 'last_lookup_succeeded' : 'untested';
	}

	private static function generation_hash( array $settings ): string {
		return hash( 'sha256', (string) ( $settings['key'] ?? '' ) . '|' . (string) ( $settings['generation'] ?? '' ) );
	}

	public static function save( array $data ): array {
		$current = self::settings();
		$country = strtoupper( sanitize_text_field( (string) ( $data['country'] ?? $current['country'] ) ) );
		$language = strtolower( sanitize_text_field( (string) ( $data['language'] ?? $current['language'] ) ) );
		$current['country'] = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'GB';
		$current['language'] = preg_match( '/^[a-z]{2}(?:-[a-z]{2})?$/', $language ) ? $language : 'en';
		$current['daily_limit'] = max( 1, min( 100, absint( $data['daily_limit'] ?? $current['daily_limit'] ) ) );
		$current['enabled'] = rest_sanitize_boolean( $data['enabled'] ?? $current['enabled'] );
		$current['capture_addresses'] = rest_sanitize_boolean( $data['capture_addresses'] ?? $current['capture_addresses'] );
		if ( ! empty( $data['remove_key'] ) ) {
			$current['key'] = '';
			$current['generation'] = wp_generate_uuid4();
			$current['enabled'] = false;
			update_option( self::STATUS_OPTION, 'untested', false );
		} elseif ( isset( $data['replace_key'] ) && '' !== trim( (string) $data['replace_key'] ) ) {
			$current['key'] = sanitize_text_field( (string) $data['replace_key'] );
			$current['generation'] = wp_generate_uuid4();
			update_option( self::STATUS_OPTION, 'untested', false );
		}
		update_option( self::OPTION, $current, false );
		return self::public_settings();
	}

	public static function is_staging(): bool {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return ( defined( 'WP_LOCAL_DEV' ) && WP_LOCAL_DEV ) || ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'production' !== WP_ENVIRONMENT_TYPE ) || ( defined( 'JETPACK_STAGING_MODE' ) && JETPACK_STAGING_MODE ) || ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) || 0 === strpos( $host, 'dev.' ) || (bool) preg_match( '/(?:\\.pi|\\.test|\\.localhost)$/', $host );
	}

	public static function requests_today(): int {
		$count = get_option( self::COUNT_OPTION, array() );
		return is_array( $count ) && ( $count['date'] ?? '' ) === gmdate( 'Y-m-d' ) ? (int) ( $count['count'] ?? 0 ) : 0;
	}

	/** Return provider data only to the current authorised administrator response. */
	public function lookup( string $name, array $address ) {
		$settings = self::settings();
		if ( ! $settings['enabled'] || '' === $settings['key'] || self::is_staging() ) {
			return new \WP_Error( 'ace_places_unavailable', 'Places lookup is disabled, unconfigured or blocked in this environment.', array( 'status' => 403 ) );
		}
		if ( (int) get_option( self::COOLDOWN_OPTION, 0 ) > time() ) {
			return new \WP_Error( 'ace_places_cooldown', 'Places is cooling down after a provider error. Try again later.', array( 'status' => 429 ) );
		}
		$line = trim( (string) ( $address['line_1'] ?? '' ) );
		$postcode = trim( (string) ( $address['postcode'] ?? '' ) );
		$country = strtoupper( (string) ( $address['country_code'] ?? '' ) );
		if ( ! (int) ( $address['public_premises'] ?? 0 ) || '' === trim( $name ) || '' === $line || '' === $postcode || $country !== $settings['country'] ) {
			return new \WP_Error( 'ace_places_address_unverified', 'A verified public business premises address in the configured country is required.', array( 'status' => 400 ) );
		}
		$token = time() . ':' . wp_generate_uuid4();
		if ( ! self::acquire_lock( $token ) ) {
			return new \WP_Error( 'ace_places_busy', 'Another lookup is in progress. Try again shortly.', array( 'status' => 429 ) );
		}
		try {
			$count = self::requests_today();
			if ( $count >= $settings['daily_limit'] ) {
				return new \WP_Error( 'ace_places_limit', 'The daily Places request limit has been reached.', array( 'status' => 429 ) );
			}
			update_option( self::COUNT_OPTION, array( 'date' => gmdate( 'Y-m-d' ), 'count' => $count + 1 ), false );
			$query = implode( ', ', array_filter( array( $name, $line, $address['line_2'] ?? '', $address['city'] ?? '', $postcode, $country ) ) );
			$response = wp_remote_post( 'https://places.googleapis.com/v1/places:searchText', array(
				'timeout' => 8,
				'redirection' => 0,
				'limit_response_size' => 131072,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-Goog-Api-Key' => $settings['key'],
					'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.addressComponents,places.businessStatus,places.types,places.attributions',
				),
				'body' => wp_json_encode( array( 'textQuery' => $query, 'languageCode' => $settings['language'], 'regionCode' => $country, 'pageSize' => 5 ) ),
			) );
			if ( is_wp_error( $response ) ) {
				update_option( self::COOLDOWN_OPTION, time() + 60, false );
				return new \WP_Error( 'ace_places_transport', 'Places could not be reached. The request has been counted.', array( 'status' => 502 ) );
			}
			$status = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status ) {
				if ( 429 === $status || $status >= 500 ) { update_option( self::COOLDOWN_OPTION, time() + 300, false ); }
				return new \WP_Error( 'ace_places_provider', 'Places returned an error. Check the key, quota and server IP restriction.', array( 'status' => 502 ) );
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$result = self::parse_response( $name, $address, $body );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'ace_places_response', 'Places returned an invalid response.', array( 'status' => 502 ) );
			}
			$current = self::settings();
			if ( ! empty( $current['enabled'] ) && hash_equals( self::generation_hash( $settings ), self::generation_hash( $current ) ) ) {
				update_option( self::STATUS_OPTION, array( 'key_hash' => self::generation_hash( $settings ), 'at' => current_time( 'mysql', true ) ), false );
			}
			return $result;
		} finally {
			self::release_lock( $token );
		}
	}

	/** Normalise ephemeral provider content; never save it as company data. */
	public static function parse_response( string $name, array $address, $body ) {
		if ( ! is_array( $body ) || ( isset( $body['places'] ) && ! is_array( $body['places'] ) ) || isset( $body['error'] ) ) {
			return new \WP_Error( 'ace_places_response', 'Invalid Places response.' );
		}
		$places = array_slice( (array) ( $body['places'] ?? array() ), 0, 5 );
		foreach ( $places as $place ) {
			if ( ! is_array( $place ) || ! isset( $place['id'], $place['displayName']['text'], $place['formattedAddress'] ) ) {
				return new \WP_Error( 'ace_places_response', 'Incomplete Places candidate.' );
			}
		}
		return array( 'candidates' => array_values( array_map( static function ( $place ) use ( $name, $address ) {
				$place = is_array( $place ) ? $place : array();
				return array(
					'id' => sanitize_text_field( (string) ( $place['id'] ?? '' ) ),
					'name' => sanitize_text_field( (string) ( $place['displayName']['text'] ?? '' ) ),
					'address' => sanitize_text_field( (string) ( $place['formattedAddress'] ?? '' ) ),
					'business_status' => sanitize_key( (string) ( $place['businessStatus'] ?? '' ) ),
					'assessment' => self::assess( $name, $address, $place ),
					'attributions' => array_values( array_filter( array_map( static function ( $attribution ) { return is_array( $attribution ) ? array( 'display_name' => sanitize_text_field( (string) ( $attribution['provider'] ?? '' ) ), 'uri' => esc_url_raw( (string) ( $attribution['providerUri'] ?? '' ) ) ) : null; }, (array) ( $place['attributions'] ?? array() ) ) ) ),
				);
		}, $places ) ), 'attribution' => 'Google Maps' );
	}

	private static function acquire_lock( string $token ): bool {
		global $wpdb;
		if ( add_option( self::LOCK_OPTION, $token, '', false ) ) { return true; }
		$old = (string) get_option( self::LOCK_OPTION, '' );
		if ( (int) $old > time() - 30 ) { return false; }
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, self::LOCK_OPTION, $old ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return 1 === (int) $changed;
	}

	private static function release_lock( string $token ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $token ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	public static function assess( string $name, array $address, array $place ): string {
		$components = (array) ( $place['addressComponents'] ?? array() );
		$types = array();
		foreach ( $components as $component ) {
			foreach ( (array) ( $component['types'] ?? array() ) as $type ) {
				$types[ $type ] = strtoupper( (string) ( $component['shortText'] ?? $component['longText'] ?? '' ) );
			}
		}
		if ( 'CLOSED_PERMANENTLY' === ( $place['businessStatus'] ?? '' ) || 'CLOSED_TEMPORARILY' === ( $place['businessStatus'] ?? '' ) ) {
			return 'Closed business: review manually';
		}
		if ( strtoupper( (string) ( $address['country_code'] ?? '' ) ) !== ( $types['country'] ?? '' ) ) {
			return 'Country differs: review manually';
		}
		$normalise = static function ( string $value ): string { return preg_replace( '/[^A-Z0-9]/', '', strtoupper( $value ) ); };
		if ( $normalise( (string) ( $address['postcode'] ?? '' ) ) !== $normalise( $types['postal_code'] ?? '' ) ) {
			return 'Postcode differs: review manually';
		}
		$google_name = (string) ( $place['displayName']['text'] ?? '' );
		if ( $normalise( $google_name ) !== $normalise( $name ) ) {
			return 'Name differs or shared premises: review manually';
		}
		$line = $normalise( (string) ( $address['line_1'] ?? '' ) . ' ' . (string) ( $address['line_2'] ?? '' ) );
		$formatted = $normalise( (string) ( $place['formattedAddress'] ?? '' ) );
		if ( '' === $line || false === strpos( $formatted, $line ) ) {
			return 'Street or unit differs: review manually';
		}
		return 'Possible match: check building, unit and branch before linking';
	}
}
