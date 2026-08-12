<?php
/**
 * Removes all plugin data when the plugin is deleted from the Plugins screen.
 *
 * @package Simple_Site_Analytics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ssa_events" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ssa_views" );

delete_option( 'ssa_db_version' );
delete_option( 'ssa_secret' );
