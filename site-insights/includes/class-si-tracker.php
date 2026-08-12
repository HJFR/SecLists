<?php
/**
 * Front-end tracking: script enqueue and REST collection endpoints.
 *
 * @package Site_Insights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SI_Tracker {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );
	}

	/* ---------------------------------------------------------------------
	 * Front-end script
	 * ------------------------------------------------------------------- */

	public function enqueue_tracker() {
		if ( is_admin() || is_preview() || is_customize_preview() ) {
			return;
		}

		$settings = site_insights_settings();

		if ( $settings['exclude_logged_in'] && is_user_logged_in() ) {
			return;
		}

		wp_enqueue_script(
			'site-insights-tracker',
			SITE_INSIGHTS_URL . 'assets/js/tracker.js',
			array(),
			SITE_INSIGHTS_VERSION,
			true
		);

		$post_id   = is_singular() ? get_queried_object_id() : 0;
		$post_type = $post_id ? get_post_type( $post_id ) : '';

		wp_localize_script(
			'site-insights-tracker',
			'SiteInsightsCfg',
			array(
				'viewUrl'    => esc_url_raw( rest_url( 'site-insights/v1/view' ) ),
				'engageUrl'  => esc_url_raw( rest_url( 'site-insights/v1/engage' ) ),
				'postId'     => (int) $post_id,
				'postType'   => (string) $post_type,
				'respectDnt' => (int) $settings['respect_dnt'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * REST endpoints
	 * ------------------------------------------------------------------- */

	public function register_routes() {
		register_rest_route(
			'site-insights/v1',
			'/view',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record_view' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session'   => array( 'type' => 'string', 'required' => true ),
					'post_id'   => array( 'type' => 'integer', 'default' => 0 ),
					'post_type' => array( 'type' => 'string', 'default' => '' ),
					'path'      => array( 'type' => 'string', 'default' => '/' ),
					'referrer'  => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			'site-insights/v1',
			'/engage',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record_engage' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'        => array( 'type' => 'integer', 'required' => true ),
					'token'     => array( 'type' => 'string', 'required' => true ),
					'seconds'   => array( 'type' => 'integer', 'default' => 0 ),
					'scroll'    => array( 'type' => 'integer', 'default' => 0 ),
					'exit_url'  => array( 'type' => 'string', 'default' => '' ),
					'exit_type' => array( 'type' => 'string', 'default' => 'none' ),
				),
			)
		);
	}

	/**
	 * POST /view — register a pageview, return an id + HMAC token that the
	 * client must present when updating engagement data for this view.
	 */
	public function record_view( WP_REST_Request $request ) {
		global $wpdb;

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		if ( '' === $ua || $this->is_bot( $ua ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 202 );
		}

		$session = strtolower( (string) $request['session'] );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $session ) ) {
			return new WP_Error( 'si_bad_session', 'Invalid session id.', array( 'status' => 400 ) );
		}

		$post_id   = max( 0, (int) $request['post_id'] );
		$post_type = sanitize_key( (string) $request['post_type'] );

		// Only trust post ids that actually exist and are public.
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				$post_id   = 0;
				$post_type = '';
			} else {
				$post_type = $post->post_type;
			}
		}

		$path = wp_parse_url( (string) $request['path'], PHP_URL_PATH );
		$path = $path ? substr( $path, 0, 191 ) : '/';

		$referrer        = esc_url_raw( (string) $request['referrer'] );
		$referrer_domain = $this->domain_of( $referrer );
		$referrer_type   = $this->classify_referrer( $referrer_domain );

		$ip           = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$visitor_hash = md5( $this->daily_salt() . '|' . $ip . '|' . $ua );

		$inserted = $wpdb->insert(
			SI_Schema::table(),
			array(
				'session_id'      => $session,
				'visitor_hash'    => $visitor_hash,
				'post_id'         => $post_id,
				'post_type'       => substr( $post_type, 0, 20 ),
				'page_path'       => $path,
				'referrer_url'    => substr( $referrer, 0, 255 ),
				'referrer_domain' => substr( $referrer_domain, 0, 191 ),
				'referrer_type'   => $referrer_type,
				'entered_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'si_db_error', 'Could not record view.', array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		return new WP_REST_Response(
			array(
				'id'    => $id,
				'token' => $this->view_token( $id ),
			),
			201
		);
	}

	/**
	 * POST /engage — update engaged time, scroll depth and exit destination
	 * for a previously registered view. Requires the matching HMAC token.
	 */
	public function record_engage( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request['id'];
		$token = (string) $request['token'];

		if ( $id < 1 || ! hash_equals( $this->view_token( $id ), $token ) ) {
			return new WP_Error( 'si_bad_token', 'Invalid view token.', array( 'status' => 403 ) );
		}

		$seconds = min( 14400, max( 0, (int) $request['seconds'] ) );
		$scroll  = min( 100, max( 0, (int) $request['scroll'] ) );

		$exit_type = (string) $request['exit_type'];
		if ( ! in_array( $exit_type, array( 'none', 'internal', 'outbound' ), true ) ) {
			$exit_type = 'none';
		}

		$exit_url    = '';
		$exit_domain = '';

		if ( 'none' !== $exit_type ) {
			$exit_url    = substr( esc_url_raw( (string) $request['exit_url'] ), 0, 255 );
			$exit_domain = substr( $this->domain_of( $exit_url ), 0, 191 );
		}

		$table = SI_Schema::table();

		// GREATEST() keeps the row monotonic even if beacons arrive out of order.
		$sql = "UPDATE {$table}
				SET engaged_seconds = GREATEST(engaged_seconds, %d),
					max_scroll = GREATEST(max_scroll, %d)";
		$params = array( $seconds, $scroll );

		if ( 'none' !== $exit_type ) {
			$sql     .= ', exit_url = %s, exit_domain = %s, exit_type = %s';
			$params[] = $exit_url;
			$params[] = $exit_domain;
			$params[] = $exit_type;
		}

		$sql     .= ' WHERE id = %d';
		$params[] = $id;

		$wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * HMAC token tying an engage request to the view row it created.
	 */
	private function view_token( $id ) {
		return hash_hmac( 'sha256', 'si-view-' . (int) $id, wp_salt( 'auth' ) );
	}

	/**
	 * Daily rotating salt so visitor hashes cannot be linked across days
	 * (same approach as Plausible/Fathom).
	 */
	private function daily_salt() {
		$opt   = get_option( 'si_daily_salt' );
		$today = gmdate( 'Ymd' );

		if ( ! is_array( $opt ) || ( $opt['day'] ?? '' ) !== $today ) {
			$opt = array(
				'day'  => $today,
				'salt' => wp_generate_password( 32, false ),
			);
			update_option( 'si_daily_salt', $opt, false );
		}

		return $opt['salt'];
	}

	private function is_bot( $ua ) {
		return (bool) preg_match(
			'/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|gtmetrix|monitor|scraper|curl|wget|python-requests/i',
			$ua
		);
	}

	private function domain_of( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return $host ? strtolower( preg_replace( '/^www\./', '', $host ) ) : '';
	}

	/**
	 * Bucket a referrer domain: direct / internal / search / social / other.
	 */
	private function classify_referrer( $domain ) {
		if ( '' === $domain ) {
			return 'direct';
		}

		$site_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( $domain === $site_host ) {
			return 'internal';
		}

		$search = array( 'google.', 'bing.', 'duckduckgo.', 'yahoo.', 'baidu.', 'yandex.', 'ecosia.', 'qwant.', 'startpage.', 'search.brave.' );
		foreach ( $search as $needle ) {
			if ( 0 === strpos( $domain, $needle ) || false !== strpos( $domain, '.' . $needle ) ) {
				return 'search';
			}
		}

		$social = array( 'facebook.com', 'fb.com', 'm.facebook.com', 'instagram.com', 'twitter.com', 'x.com', 't.co', 'linkedin.com', 'lnkd.in', 'pinterest.', 'reddit.com', 'youtube.com', 'youtu.be', 'tiktok.com', 'threads.net', 'bsky.app', 'mastodon.' );
		foreach ( $social as $needle ) {
			if ( $domain === $needle || 0 === strpos( $domain, $needle ) || false !== strpos( $domain, '.' . rtrim( $needle, '.' ) ) ) {
				return 'social';
			}
		}

		return 'other';
	}
}
