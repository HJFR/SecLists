<?php
/**
 * Aggregation queries powering the dashboard.
 *
 * @package Site_Insights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SI_Stats {

	/**
	 * Start of the range: today minus ($days - 1), at midnight site time.
	 */
	private static function since( $days ) {
		return date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	/**
	 * Headline numbers: views, unique visitors, sessions, avg engaged time.
	 *
	 * @return array{views:int,visitors:int,sessions:int,avg_engaged:float}
	 */
	public static function totals( $days ) {
		global $wpdb;
		$table = SI_Schema::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS views,
						COUNT(DISTINCT visitor_hash) AS visitors,
						COUNT(DISTINCT session_id) AS sessions,
						COALESCE(AVG(NULLIF(engaged_seconds, 0)), 0) AS avg_engaged
				 FROM {$table}
				 WHERE entered_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days )
			),
			ARRAY_A
		);

		return array(
			'views'       => (int) ( $row['views'] ?? 0 ),
			'visitors'    => (int) ( $row['visitors'] ?? 0 ),
			'sessions'    => (int) ( $row['sessions'] ?? 0 ),
			'avg_engaged' => (float) ( $row['avg_engaged'] ?? 0 ),
		);
	}

	/**
	 * Views and visitors per calendar day, with gaps filled with zeros.
	 *
	 * @return array<string,array{views:int,visitors:int}> keyed by Y-m-d.
	 */
	public static function daily( $days ) {
		global $wpdb;
		$table = SI_Schema::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(entered_at) AS day,
						COUNT(*) AS views,
						COUNT(DISTINCT visitor_hash) AS visitors
				 FROM {$table}
				 WHERE entered_at >= %s
				 GROUP BY DATE(entered_at)
				 ORDER BY day ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days )
			),
			ARRAY_A
		);

		$by_day = array();
		foreach ( (array) $rows as $row ) {
			$by_day[ $row['day'] ] = array(
				'views'    => (int) $row['views'],
				'visitors' => (int) $row['visitors'],
			);
		}

		$series = array();
		$now    = current_time( 'timestamp' );
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day            = date( 'Y-m-d', $now - $i * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			$series[ $day ] = $by_day[ $day ] ?? array(
				'views'    => 0,
				'visitors' => 0,
			);
		}

		return $series;
	}

	/**
	 * Most viewed content for the given post types (e.g. posts or products).
	 *
	 * @param int      $days  Range in days.
	 * @param string[] $types Post types to include.
	 * @param int      $limit Max rows.
	 * @return array[] Each row: post_id, title, url, views, visitors, avg_engaged.
	 */
	public static function top_content( $days, array $types, $limit = 10 ) {
		global $wpdb;

		if ( empty( $types ) ) {
			return array();
		}

		$table        = SI_Schema::table();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$params       = array_merge( array( self::since( $days ) ), $types, array( (int) $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id,
						COUNT(*) AS views,
						COUNT(DISTINCT visitor_hash) AS visitors,
						COALESCE(AVG(NULLIF(engaged_seconds, 0)), 0) AS avg_engaged
				 FROM {$table}
				 WHERE entered_at >= %s AND post_id > 0 AND post_type IN ({$placeholders})
				 GROUP BY post_id
				 ORDER BY views DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$title   = get_the_title( $post_id );

			$out[] = array(
				'post_id'     => $post_id,
				'title'       => '' !== $title ? $title : sprintf( '#%d', $post_id ),
				'url'         => (string) get_permalink( $post_id ),
				'views'       => (int) $row['views'],
				'visitors'    => (int) $row['visitors'],
				'avg_engaged' => (float) $row['avg_engaged'],
			);
		}

		return $out;
	}

	/**
	 * Top referrer domains (excluding internal navigation).
	 *
	 * @return array[] Each row: domain, type, views, visitors.
	 */
	public static function referrers( $days, $limit = 10 ) {
		global $wpdb;
		$table = SI_Schema::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_domain AS domain,
						referrer_type AS type,
						COUNT(*) AS views,
						COUNT(DISTINCT visitor_hash) AS visitors
				 FROM {$table}
				 WHERE entered_at >= %s AND referrer_type <> 'internal'
				 GROUP BY referrer_domain, referrer_type
				 ORDER BY views DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days ),
				(int) $limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'domain'   => '' !== $row['domain'] ? $row['domain'] : __( '(direct / none)', 'site-insights' ),
				'type'     => $row['type'],
				'views'    => (int) $row['views'],
				'visitors' => (int) $row['visitors'],
			);
		}

		return $out;
	}

	/**
	 * Breakdown of entry traffic by channel (direct/search/social/other).
	 *
	 * @return array<string,int>
	 */
	public static function referrer_types( $days ) {
		global $wpdb;
		$table = SI_Schema::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_type AS type, COUNT(*) AS views
				 FROM {$table}
				 WHERE entered_at >= %s AND referrer_type <> 'internal'
				 GROUP BY referrer_type
				 ORDER BY views DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['type'] ] = (int) $row['views'];
		}

		return $out;
	}

	/**
	 * Outbound destinations: external domains visitors clicked through to.
	 *
	 * @return array[] Each row: domain, clicks.
	 */
	public static function outbound( $days, $limit = 10 ) {
		global $wpdb;
		$table = SI_Schema::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT exit_domain AS domain, COUNT(*) AS clicks
				 FROM {$table}
				 WHERE entered_at >= %s AND exit_type = 'outbound' AND exit_domain <> ''
				 GROUP BY exit_domain
				 ORDER BY clicks DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days ),
				(int) $limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'domain' => $row['domain'],
				'clicks' => (int) $row['clicks'],
			);
		}

		return $out;
	}

	/**
	 * Where visitors navigate next inside the site.
	 *
	 * @return array[] Each row: path, views.
	 */
	public static function next_pages( $days, $limit = 10 ) {
		global $wpdb;
		$table = SI_Schema::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT exit_url AS path, COUNT(*) AS views
				 FROM {$table}
				 WHERE entered_at >= %s AND exit_type = 'internal' AND exit_url <> ''
				 GROUP BY exit_url
				 ORDER BY views DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days ),
				(int) $limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'path'  => $row['path'],
				'views' => (int) $row['views'],
			);
		}

		return $out;
	}
}
