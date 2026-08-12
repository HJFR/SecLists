<?php
/**
 * Enqueues the front-end tracking script.
 *
 * @package Simple_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSA_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( is_preview() || is_customize_preview() || is_404() ) {
			return;
		}

		// Editors and admins browsing the site are not visitors.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_enqueue_script(
			'ssa-tracker',
			SSA_PLUGIN_URL . 'assets/js/tracker.js',
			array(),
			SSA_VERSION,
			true
		);

		$post_id = is_singular() ? get_queried_object_id() : 0;

		wp_add_inline_script(
			'ssa-tracker',
			'window.SSA_CFG = ' . wp_json_encode(
				array(
					'endpoint' => esc_url_raw( rest_url( 'ssa/v1' ) ),
					'postId'   => $post_id,
				)
			) . ';',
			'before'
		);
	}
}
