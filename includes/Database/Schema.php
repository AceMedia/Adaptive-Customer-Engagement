<?php
/**
 * Schema manager.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const SCHEMA_VERSION        = '0.1.7';
	public const SCHEMA_VERSION_OPTION = 'ace_schema_version';

	/**
	 * Install tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$tables  = array();

		$tables[] = 'CREATE TABLE ' . self::table_name( 'sessions' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_uuid CHAR(36) NOT NULL,
			visitor_uuid CHAR(36) NULL,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			first_url TEXT NULL,
			landing_path VARCHAR(255) NULL,
			referrer TEXT NULL,
			utm_source VARCHAR(100) NULL,
			utm_medium VARCHAR(100) NULL,
			utm_campaign VARCHAR(150) NULL,
			utm_term VARCHAR(150) NULL,
			utm_content VARCHAR(150) NULL,
			user_agent TEXT NULL,
			browser_hash CHAR(64) NULL,
			ip_hash CHAR(64) NULL,
			ip_raw VARBINARY(128) NULL,
			ip_raw_expires_at DATETIME NULL,
			country_code VARCHAR(8) NULL,
			region VARCHAR(100) NULL,
			city VARCHAR(100) NULL,
			asn VARCHAR(50) NULL,
			isp VARCHAR(255) NULL,
			company_id BIGINT UNSIGNED NULL,
			company_confidence VARCHAR(20) DEFAULT 'unknown',
			is_bot TINYINT(1) DEFAULT 0,
			ignored TINYINT(1) DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_uuid (session_uuid),
			KEY visitor_uuid (visitor_uuid),
			KEY first_seen (first_seen),
			KEY last_seen (last_seen),
			KEY ip_hash (ip_hash),
			KEY company_id (company_id),
			KEY is_bot (is_bot),
			KEY company_confidence (company_confidence),
			KEY ip_raw_expires_at (ip_raw_expires_at)
		) {$collate};";

		$tables[] = 'CREATE TABLE ' . self::table_name( 'events' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_uuid CHAR(36) NOT NULL,
			session_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(50) NOT NULL,
			event_name VARCHAR(100) NULL,
			url TEXT NULL,
			path VARCHAR(255) NULL,
			page_title TEXT NULL,
			post_id BIGINT UNSIGNED NULL,
			post_type VARCHAR(50) NULL,
			taxonomy_context TEXT NULL,
			product_area VARCHAR(150) NULL,
			brand_context VARCHAR(150) NULL,
			number_id BIGINT UNSIGNED NULL,
			metadata LONGTEXT NULL,
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_uuid (event_uuid),
			KEY session_id (session_id),
			KEY event_type (event_type),
			KEY occurred_at (occurred_at),
			KEY post_id (post_id),
			KEY number_id (number_id),
			KEY event_type_occurred (event_type, occurred_at),
			KEY session_event_type (session_id, event_type)
		) {$collate};";

		$tables[] = 'CREATE TABLE ' . self::table_name( 'companies' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			domain VARCHAR(255) NULL,
			type VARCHAR(100) NULL,
			country_code VARCHAR(8) NULL,
			source_provider VARCHAR(100) NULL,
			source_payload LONGTEXT NULL,
			confidence VARCHAR(20) DEFAULT 'unknown',
			first_seen DATETIME NULL,
			last_seen DATETIME NULL,
			total_sessions BIGINT UNSIGNED DEFAULT 0,
			total_events BIGINT UNSIGNED DEFAULT 0,
			total_calls BIGINT UNSIGNED DEFAULT 0,
			ignored TINYINT(1) DEFAULT 0,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY name (name),
			KEY domain (domain),
			KEY type (type),
			KEY last_seen (last_seen),
			KEY ignored (ignored)
		) {$collate};";

		$tables[] = 'CREATE TABLE ' . self::table_name( 'numbers' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(150) NOT NULL,
			display_number VARCHAR(50) NOT NULL,
			e164_number VARCHAR(50) NOT NULL,
			source_type VARCHAR(50) NOT NULL,
			source_value VARCHAR(255) NULL,
			page_match_type VARCHAR(50) NULL,
			page_match_value VARCHAR(255) NULL,
			campaign_match VARCHAR(255) NULL,
			amazon_connect_phone_number_id VARCHAR(255) NULL,
			amazon_connect_contact_flow_id VARCHAR(255) NULL,
			is_default TINYINT(1) DEFAULT 0,
			is_active TINYINT(1) DEFAULT 1,
			priority INT DEFAULT 10,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY source_type (source_type),
			KEY e164_number (e164_number),
			KEY is_default (is_default),
			KEY is_active (is_active),
			KEY priority (priority)
		) {$collate};";

		$tables[] = 'CREATE TABLE ' . self::table_name( 'calls' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			call_uuid CHAR(36) NOT NULL,
			amazon_contact_id VARCHAR(255) NULL,
			number_id BIGINT UNSIGNED NULL,
			called_number VARCHAR(50) NULL,
			caller_number_hash CHAR(64) NULL,
			caller_number_raw VARBINARY(128) NULL,
			caller_number_expires_at DATETIME NULL,
			started_at DATETIME NOT NULL,
			ended_at DATETIME NULL,
			duration_seconds INT NULL,
			direction VARCHAR(20) DEFAULT 'inbound',
			status VARCHAR(50) NULL,
			queue_name VARCHAR(150) NULL,
			agent_name VARCHAR(150) NULL,
			matched_session_id BIGINT UNSIGNED NULL,
			matched_company_id BIGINT UNSIGNED NULL,
			match_confidence VARCHAR(20) DEFAULT 'unknown',
			attributes LONGTEXT NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY call_uuid (call_uuid),
			KEY amazon_contact_id (amazon_contact_id),
			KEY number_id (number_id),
			KEY started_at (started_at),
			KEY matched_session_id (matched_session_id),
			KEY matched_company_id (matched_company_id),
			KEY status (status),
			KEY caller_number_expires_at (caller_number_expires_at)
		) {$collate};";

		$tables[] = 'CREATE TABLE ' . self::table_name( 'enrichment_cache' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_hash CHAR(64) NOT NULL,
			provider VARCHAR(100) NOT NULL,
			response LONGTEXT NULL,
			company_name VARCHAR(255) NULL,
			company_domain VARCHAR(255) NULL,
			company_type VARCHAR(100) NULL,
			asn VARCHAR(50) NULL,
			isp VARCHAR(255) NULL,
			confidence VARCHAR(20) DEFAULT 'unknown',
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_ip_hash (provider, ip_hash),
			KEY expires_at (expires_at),
			KEY company_name (company_name)
		) {$collate};";

		$tables[] = 'CREATE TABLE ' . self::table_name( 'chat_conversations' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_uuid CHAR(36) NOT NULL,
			session_id BIGINT UNSIGNED NULL,
			session_uuid CHAR(36) NULL,
			visitor_uuid CHAR(36) NULL,
			company_id BIGINT UNSIGNED NULL,
			page_url TEXT NULL,
			page_title TEXT NULL,
			provider VARCHAR(50) NOT NULL DEFAULT 'openai',
			model VARCHAR(100) NULL,
			message_count BIGINT UNSIGNED DEFAULT 0,
			user_message_count BIGINT UNSIGNED DEFAULT 0,
			assistant_message_count BIGINT UNSIGNED DEFAULT 0,
			operator_message_count BIGINT UNSIGNED DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			commercial_status VARCHAR(20) NOT NULL DEFAULT 'new',
			commercial_outcome VARCHAR(30) NULL,
			priority VARCHAR(20) NOT NULL DEFAULT 'normal',
			owner_user_id BIGINT UNSIGNED NULL,
			handover_requested TINYINT(1) DEFAULT 0,
			handover_requested_at DATETIME NULL,
			follow_up_requested TINYINT(1) DEFAULT 0,
			follow_up_at DATETIME NULL,
			contact_name VARCHAR(150) NULL,
			contact_email VARCHAR(190) NULL,
			contact_phone VARCHAR(50) NULL,
			contact_company VARCHAR(190) NULL,
			contact_role VARCHAR(150) NULL,
			lead_summary TEXT NULL,
			company_prompted_at DATETIME NULL,
			contact_prompted_at DATETIME NULL,
			contact_captured_at DATETIME NULL,
			internal_notes TEXT NULL,
			handover_enabled TINYINT(1) DEFAULT 0,
			started_at DATETIME NOT NULL,
			last_message_at DATETIME NOT NULL,
			ended_at DATETIME NULL,
			ended_by VARCHAR(20) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conversation_uuid (conversation_uuid),
			KEY session_id (session_id),
			KEY company_id (company_id),
			KEY last_message_at (last_message_at),
			KEY provider (provider),
			KEY owner_user_id (owner_user_id),
			KEY model (model),
			KEY status (status),
			KEY commercial_status (commercial_status),
			KEY commercial_outcome (commercial_outcome),
			KEY priority (priority),
			KEY handover_requested (handover_requested),
			KEY handover_requested_at (handover_requested_at),
			KEY follow_up_requested (follow_up_requested),
			KEY follow_up_at (follow_up_at)
		) {$collate};";

		$tables[] = 'CREATE TABLE ' . self::table_name( 'ip_company_memory' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_hash CHAR(64) NOT NULL,
			company_id BIGINT UNSIGNED NULL,
			company_name VARCHAR(255) NOT NULL,
			company_domain VARCHAR(255) NULL,
			contact_name VARCHAR(150) NULL,
			contact_email VARCHAR(190) NULL,
			contact_phone VARCHAR(50) NULL,
			contact_role VARCHAR(150) NULL,
			source VARCHAR(50) NOT NULL DEFAULT 'chat',
			confidence VARCHAR(20) DEFAULT 'likely',
			evidence LONGTEXT NULL,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ip_hash (ip_hash),
			KEY company_id (company_id),
			KEY company_name (company_name),
			KEY confidence (confidence),
			KEY last_seen (last_seen)
		) {$collate};";

		$tables[] = 'CREATE TABLE ' . self::table_name( 'chat_messages' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			session_id BIGINT UNSIGNED NULL,
			company_id BIGINT UNSIGNED NULL,
			message_role VARCHAR(20) NOT NULL,
			message_text LONGTEXT NULL,
			sources LONGTEXT NULL,
			model VARCHAR(100) NULL,
			operator_user_id BIGINT UNSIGNED NULL,
			author_name VARCHAR(150) NULL,
			author_avatar_url TEXT NULL,
			is_error TINYINT(1) DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY session_id (session_id),
			KEY company_id (company_id),
			KEY message_role (message_role),
			KEY operator_user_id (operator_user_id),
			KEY created_at (created_at),
			KEY conversation_role (conversation_id, message_role)
		) {$collate};";

		foreach ( $tables as $table_sql ) {
			dbDelta( $table_sql );
		}

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Drop plugin tables.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( self::tables() as $table_name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Get all table names.
	 *
	 * @return array<int, string>
	 */
	public static function tables(): array {
		return array(
			self::table_name( 'sessions' ),
			self::table_name( 'events' ),
			self::table_name( 'companies' ),
			self::table_name( 'numbers' ),
			self::table_name( 'calls' ),
			self::table_name( 'enrichment_cache' ),
			self::table_name( 'chat_conversations' ),
			self::table_name( 'chat_messages' ),
			self::table_name( 'ip_company_memory' ),
		);
	}

	/**
	 * Get a table name.
	 *
	 * @param string $suffix Table suffix.
	 * @return string
	 */
	public static function table_name( string $suffix ): string {
		global $wpdb;

		return $wpdb->prefix . 'ace_' . $suffix;
	}
}
