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

		self::add_shop_manager();
	}

	/**
	 * Let shop managers see the reporting and chat screens (not settings).
	 * Runs on admin_init as well as activation, so sites where the plugin was
	 * already active pick it up without a reactivation.
	 *
	 * @return void
	 */
	public static function add_shop_manager(): void {
		$role = get_role( 'shop_manager' );

		if ( $role && ! $role->has_cap( self::VIEW ) ) {
			$role->add_cap( self::VIEW );
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

		$shop_manager = get_role( 'shop_manager' );

		if ( $shop_manager ) {
			$shop_manager->remove_cap( self::VIEW );
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
