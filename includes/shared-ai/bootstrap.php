<?php
/** Shared Ace AI connection service. Bundled identically by independent consumers. */
defined( 'ABSPATH' ) || exit;
$GLOBALS['ace_ai_bundles']['1.1.0'][__DIR__] = true;
if ( ! function_exists( 'ace_ai_connection_service' ) ) {
	function ace_ai_connection_service() {
		return $GLOBALS['ace_ai_connection_service'] ?? null;
	}
	add_action( 'plugins_loaded', static function () {
		$versions = array_keys( $GLOBALS['ace_ai_bundles'] );
		usort( $versions, 'version_compare' );
		$paths = array_keys( $GLOBALS['ace_ai_bundles'][ end( $versions ) ] );
		sort( $paths );
		require_once $paths[0] . '/service.php';
		$GLOBALS['ace_ai_connection_service'] = new \AceMedia\SharedAI\V1\Service();
		require_once $paths[0] . '/admin.php';
		\AceMedia\SharedAI\V1\Admin::register();
	}, -100 );
}
