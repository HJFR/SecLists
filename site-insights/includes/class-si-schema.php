<?php
/**
 * Database schema, activation/deactivation and data retention.
 *
 * @package Site_Insights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SI_Schema {

	const DB_VERSION = '1.0.0';

	/**
	 * Name of the events table (with prefix).
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'si_views';
	}

	public static function activate() {
		self::create_tables();
		update_option( 'si_db_version', self::DB_VERSION, false );

		if ( ! wp_next_scheduled( 'si_daily_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'si_daily_purge' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'si_daily_purge' );
	}

	/**
	 * Re-run dbDelta when the stored schema version is stale.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'si_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'si_db_version', self::DB_VERSION, false );
		}
	}

	private static function create_tables() {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id CHAR(32) NOT NULL DEFAULT '',
			visitor_hash CHAR(32) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_type VARCHAR(20) NOT NULL DEFAULT '',
			page_path VARCHAR(191) NOT NULL DEFAULT '',
			referrer_url VARCHAR(255) NOT NULL DEFAULT '',
			referrer_domain VARCHAR(191) NOT NULL DEFAULT '',
			referrer_type VARCHAR(20) NOT NULL DEFAULT 'direct',
			engaged_seconds INT UNSIGNED NOT NULL DEFAULT 0,
			max_scroll TINYINT UNSIGNED NOT NULL DEFAULT 0,
			exit_url VARCHAR(255) NOT NULL DEFAULT '',
			exit_domain VARCHAR(191) NOT NULL DEFAULT '',
			exit_type VARCHAR(20) NOT NULL DEFAULT 'none',
			entered_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY post_type (post_type),
			KEY entered_at (entered_at),
			KEY referrer_domain (referrer_domain),
			KEY session_id (session_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Cron callback: drop rows older than the configured retention window.
	 */
	public static function purge_old_data() {
		global $wpdb;

		$settings = site_insights_settings();
		$days     = max( 1, (int) $settings['retention_days'] );
		$cutoff   = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$table    = self::table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE entered_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
