<?php
/**
 * Read-side queries powering the dashboard.
 *
 * @package Simple_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSA_Reports {

	public static function summary( $days ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS views,
				        COUNT( DISTINCT session_hash ) AS visitors,
				        AVG( NULLIF( duration, 0 ) ) AS avg_duration
				 FROM {$wpdb->prefix}ssa_views
				 WHERE viewed_at >= %s",
				self::since( $days )
			),
			ARRAY_A
		);

		return array(
			'views'        => (int) ( $row['views'] ?? 0 ),
			'visitors'     => (int) ( $row['visitors'] ?? 0 ),
			'avg_duration' => (int) round( (float) ( $row['avg_duration'] ?? 0 ) ),
		);
	}

	/**
	 * Most viewed content of the given post types, with visitors and average
	 * engaged time per piece of content.
	 */
	public static function top_content( $days, array $post_types, $limit = 10 ) {
		global $wpdb;

		if ( ! $post_types ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$params       = array_merge( array( self::since( $days ) ), $post_types, array( (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id,
				        COUNT(*) AS views,
				        COUNT( DISTINCT session_hash ) AS visitors,
				        AVG( NULLIF( duration, 0 ) ) AS avg_duration
				 FROM {$wpdb->prefix}ssa_views
				 WHERE viewed_at >= %s AND post_id > 0 AND post_type IN ( {$placeholders} )
				 GROUP BY post_id
				 ORDER BY views DESC
				 LIMIT %d",
				$params
			),
			ARRAY_A
		);
	}

	public static function top_referrers( $days, $limit = 10 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_domain,
				        COUNT(*) AS views,
				        COUNT( DISTINCT session_hash ) AS visitors
				 FROM {$wpdb->prefix}ssa_views
				 WHERE viewed_at >= %s AND is_internal_referrer = 0 AND referrer_domain <> ''
				 GROUP BY referrer_domain
				 ORDER BY views DESC
				 LIMIT %d",
				self::since( $days ),
				(int) $limit
			),
			ARRAY_A
		);
	}

	public static function top_outbound( $days, $limit = 10 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target_domain,
				        COUNT(*) AS clicks
				 FROM {$wpdb->prefix}ssa_events
				 WHERE created_at >= %s AND event_type = 'outbound'
				 GROUP BY target_domain
				 ORDER BY clicks DESC
				 LIMIT %d",
				self::since( $days ),
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Where visitors go next inside the site (internal navigation edges),
	 * derived from views whose referrer is an internal page.
	 */
	public static function top_internal_paths( $days, $limit = 10 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_path AS from_path,
				        path AS to_path,
				        COUNT(*) AS moves
				 FROM {$wpdb->prefix}ssa_views
				 WHERE viewed_at >= %s AND is_internal_referrer = 1 AND referrer_path <> ''
				 GROUP BY referrer_path, path
				 ORDER BY moves DESC
				 LIMIT %d",
				self::since( $days ),
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Pageviews per day for the range, with missing days filled with zero.
	 */
	public static function daily_views( $days ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE( viewed_at ) AS day, COUNT(*) AS views
				 FROM {$wpdb->prefix}ssa_views
				 WHERE viewed_at >= %s
				 GROUP BY DATE( viewed_at )
				 ORDER BY day ASC",
				self::since( $days )
			),
			OBJECT_K
		);

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day            = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$series[ $day ] = isset( $rows[ $day ] ) ? (int) $rows[ $day ]->views : 0;
		}

		return $series;
	}

	private static function since( $days ) {
		return gmdate( 'Y-m-d 00:00:00', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
	}
}
