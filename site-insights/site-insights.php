<?php
/**
 * Plugin Name:       Site Insights
 * Plugin URI:        https://github.com/hjfr/seclists
 * Description:       Privacy-friendly, self-hosted analytics for WordPress and WooCommerce: most viewed articles and products, reading time, referrers and exit destinations — no external service required.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Hugo Rodrigues
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-insights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITE_INSIGHTS_VERSION', '1.0.0' );
define( 'SITE_INSIGHTS_FILE', __FILE__ );
define( 'SITE_INSIGHTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITE_INSIGHTS_URL', plugin_dir_url( __FILE__ ) );

require_once SITE_INSIGHTS_DIR . 'includes/class-si-schema.php';
require_once SITE_INSIGHTS_DIR . 'includes/class-si-tracker.php';
require_once SITE_INSIGHTS_DIR . 'includes/class-si-stats.php';
require_once SITE_INSIGHTS_DIR . 'includes/class-si-admin.php';

register_activation_hook( __FILE__, array( 'SI_Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SI_Schema', 'deactivate' ) );

/**
 * Default plugin settings, merged over whatever is stored.
 *
 * @return array
 */
function site_insights_settings() {
	$defaults = array(
		'exclude_logged_in' => 1,
		'respect_dnt'       => 1,
		'retention_days'    => 180,
	);
	$stored = get_option( 'si_settings', array() );

	return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
}

add_action(
	'plugins_loaded',
	function () {
		SI_Schema::maybe_upgrade();
		new SI_Tracker();

		if ( is_admin() ) {
			new SI_Admin();
		}
	}
);

add_action( 'si_daily_purge', array( 'SI_Schema', 'purge_old_data' ) );
