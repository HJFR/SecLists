<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Removes the events table, options and scheduled jobs.
 *
 * @package Site_Insights
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$si_table = $wpdb->prefix . 'si_views';
$wpdb->query( "DROP TABLE IF EXISTS {$si_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

delete_option( 'si_settings' );
delete_option( 'si_daily_salt' );
delete_option( 'si_db_version' );

wp_clear_scheduled_hook( 'si_daily_purge' );
