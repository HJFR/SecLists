<?php
/**
 * REST API collection endpoints the front-end tracker posts to.
 *
 * @package Simple_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSA_Tracker {

	const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
	const MAX_DURATION = 7200; // Cap engaged time at 2 hours per pageview.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'ssa/v1',
			'/view',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'record_view' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'ssa/v1',
			'/duration',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'record_duration' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'ssa/v1',
			'/event',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'record_event' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function record_view( WP_REST_Request $request ) {
		global $wpdb;

		if ( ! self::is_countable() ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}

		$uuid = self::valid_uuid( $request->get_param( 'id' ) );
		if ( ! $uuid ) {
			return new WP_Error( 'ssa_bad_id', 'Invalid view id.', array( 'status' => 400 ) );
		}

		$post_id   = absint( $request->get_param( 'post_id' ) );
		$post_type = '';
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status ) {
				$post_type = $post->post_type;
			} else {
				$post_id = 0;
			}
		}

		$url  = esc_url_raw( (string) $request->get_param( 'url' ) );
		$path = self::url_path( $url );

		$referrer        = esc_url_raw( (string) $request->get_param( 'referrer' ) );
		$referrer_domain = self::url_domain( $referrer );
		$site_domain     = self::url_domain( home_url() );
		$is_internal     = ( '' !== $referrer_domain && $referrer_domain === $site_domain ) ? 1 : 0;

		$wpdb->insert(
			$wpdb->prefix . 'ssa_views',
			array(
				'view_uuid'            => $uuid,
				'post_id'              => $post_id,
				'post_type'            => $post_type,
				'path'                 => $path,
				'referrer_url'         => $is_internal ? '' : $referrer,
				'referrer_domain'      => $is_internal ? '' : $referrer_domain,
				'referrer_path'        => $is_internal ? self::url_path( $referrer ) : '',
				'is_internal_referrer' => $is_internal,
				'session_hash'         => self::session_hash(),
				'device'               => wp_is_mobile() ? 'mobile' : 'desktop',
				'duration'             => 0,
				'viewed_at'            => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function record_duration( WP_REST_Request $request ) {
		global $wpdb;

		$uuid = self::valid_uuid( $request->get_param( 'id' ) );
		if ( ! $uuid ) {
			return new WP_Error( 'ssa_bad_id', 'Invalid view id.', array( 'status' => 400 ) );
		}

		$seconds = min( absint( $request->get_param( 'seconds' ) ), self::MAX_DURATION );
		if ( $seconds < 1 ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}

		// Only rows from the last 24h are updatable; duration only ever grows.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}ssa_views
				 SET duration = GREATEST( duration, %d )
				 WHERE view_uuid = %s AND viewed_at >= %s",
				$seconds,
				$uuid,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function record_event( WP_REST_Request $request ) {
		global $wpdb;

		$uuid = self::valid_uuid( $request->get_param( 'id' ) );
		if ( ! $uuid ) {
			return new WP_Error( 'ssa_bad_id', 'Invalid view id.', array( 'status' => 400 ) );
		}

		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( 'outbound' !== $type ) {
			return new WP_Error( 'ssa_bad_type', 'Unknown event type.', array( 'status' => 400 ) );
		}

		$target        = esc_url_raw( (string) $request->get_param( 'url' ) );
		$target_domain = self::url_domain( $target );
		if ( '' === $target_domain || $target_domain === self::url_domain( home_url() ) ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}

		// Events must belong to a pageview we actually recorded.
		$view_exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}ssa_views WHERE view_uuid = %s", $uuid )
		);
		if ( ! $view_exists ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}

		$wpdb->insert(
			$wpdb->prefix . 'ssa_events',
			array(
				'view_uuid'     => $uuid,
				'event_type'    => $type,
				'target_url'    => $target,
				'target_domain' => $target_domain,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return rest_ensure_response( array( 'ok' => true ) );
	}

	private static function is_countable() {
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua ) {
			return false;
		}

		return ! preg_match(
			'/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|monitor|facebookexternalhit|embedly|curl|wget|python|scrapy|httpclient/i',
			$ua
		);
	}

	private static function valid_uuid( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( self::UUID_PATTERN, $value ) ? $value : false;
	}

	/**
	 * Anonymous per-day visitor hash: HMAC of IP + user agent with a salt
	 * derived from a stored secret and the current date. No cookies, and the
	 * raw IP is never stored; hashes can't be correlated across days.
	 */
	private static function session_hash() {
		$secret = get_option( 'ssa_secret', 'ssa-fallback' );
		$salt   = hash_hmac( 'sha256', gmdate( 'Y-m-d' ), $secret );
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		return md5( $salt . '|' . $ip . '|' . $ua );
	}

	private static function url_domain( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}
		$host = strtolower( $host );
		return substr( 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host, 0, 191 );
	}

	private static function url_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return substr( $path ? $path : '/', 0, 191 );
	}
}
