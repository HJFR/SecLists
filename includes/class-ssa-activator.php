<?php
/**
 * Creates and upgrades the plugin's database tables.
 *
 * @package Simple_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSA_Activator {

	const DB_VERSION = '1';

	public static function activate() {
		self::create_tables();
		update_option( 'ssa_db_version', self::DB_VERSION );

		if ( ! get_option( 'ssa_secret' ) ) {
			// Secret used to derive the rotating daily salt for visitor hashes.
			add_option( 'ssa_secret', wp_generate_password( 64, true, true ), '', 'no' );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'ssa_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$views           = $wpdb->prefix . 'ssa_views';
		$events          = $wpdb->prefix . 'ssa_events';

		dbDelta(
			"CREATE TABLE {$views} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				view_uuid char(36) NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				post_type varchar(20) NOT NULL DEFAULT '',
				path varchar(191) NOT NULL DEFAULT '',
				referrer_url text NULL,
				referrer_domain varchar(191) NOT NULL DEFAULT '',
				referrer_path varchar(191) NOT NULL DEFAULT '',
				is_internal_referrer tinyint(1) NOT NULL DEFAULT 0,
				session_hash char(32) NOT NULL,
				device varchar(10) NOT NULL DEFAULT 'desktop',
				duration int(10) unsigned NOT NULL DEFAULT 0,
				viewed_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY view_uuid (view_uuid),
				KEY post_id (post_id),
				KEY post_type (post_type),
				KEY viewed_at (viewed_at),
				KEY session_hash (session_hash),
				KEY referrer_domain (referrer_domain)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				view_uuid char(36) NOT NULL,
				event_type varchar(20) NOT NULL DEFAULT 'outbound',
				target_url text NULL,
				target_domain varchar(191) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY view_uuid (view_uuid),
				KEY target_domain (target_domain),
				KEY created_at (created_at)
			) {$charset_collate};"
		);
	}
}
