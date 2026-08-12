<?php
/**
 * Plugin Name:       Simple Site Analytics
 * Description:       Privacy-friendly, self-hosted analytics: most viewed articles and WooCommerce products, average reading time, referrers, internal navigation and outbound destinations.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Hugo Rodrigues
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simple-site-analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSA_VERSION', '1.0.0' );
define( 'SSA_PLUGIN_FILE', __FILE__ );
define( 'SSA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SSA_PLUGIN_DIR . 'includes/class-ssa-activator.php';
require_once SSA_PLUGIN_DIR . 'includes/class-ssa-tracker.php';
require_once SSA_PLUGIN_DIR . 'includes/class-ssa-frontend.php';
require_once SSA_PLUGIN_DIR . 'includes/class-ssa-reports.php';
require_once SSA_PLUGIN_DIR . 'includes/class-ssa-admin.php';

register_activation_hook( __FILE__, array( 'SSA_Activator', 'activate' ) );

add_action(
	'plugins_loaded',
	function () {
		SSA_Activator::maybe_upgrade();
		SSA_Tracker::init();
		SSA_Frontend::init();

		if ( is_admin() ) {
			SSA_Admin::init();
		}
	}
);
