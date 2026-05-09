<?php
/**
 * Capability registration.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Security;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const MANAGE          = 'manage_ace_engagement';
	public const VIEW            = 'view_ace_engagement';
	public const EXPORT          = 'export_ace_engagement';
	public const MANAGE_SETTINGS = 'manage_ace_engagement_settings';
	public const MANAGE_PRIVACY  = 'manage_ace_engagement_privacy';

	/**
	 * Grant capabilities to administrators.
	 *
	 * @return void
	 */
	public static function add(): void {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/**
	 * Remove capabilities from administrators.
	 *
	 * @return void
	 */
	public static function remove(): void {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			$role->remove_cap( $capability );
		}
	}

	/**
	 * All plugin capabilities.
	 *
	 * @return array<int, string>
	 */
	private static function all(): array {
		return array(
			self::MANAGE,
			self::VIEW,
			self::EXPORT,
			self::MANAGE_SETTINGS,
			self::MANAGE_PRIVACY,
		);
	}
}
