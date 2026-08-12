<?php
/**
 * Admin dashboard and settings pages.
 *
 * @package Site_Insights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SI_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Site Insights', 'site-insights' ),
			__( 'Insights', 'site-insights' ),
			'manage_options',
			'site-insights',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			30
		);

		add_submenu_page(
			'site-insights',
			__( 'Site Insights Settings', 'site-insights' ),
			__( 'Settings', 'site-insights' ),
			'manage_options',
			'site-insights-settings',
			array( $this, 'render_settings' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'site-insights' ) ) {
			return;
		}

		wp_enqueue_style(
			'site-insights-admin',
			SITE_INSIGHTS_URL . 'assets/css/admin.css',
			array(),
			SITE_INSIGHTS_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public function register_settings() {
		register_setting(
			'si_settings_group',
			'si_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		return array(
			'exclude_logged_in' => empty( $input['exclude_logged_in'] ) ? 0 : 1,
			'respect_dnt'       => empty( $input['respect_dnt'] ) ? 0 : 1,
			'retention_days'    => min( 3650, max( 7, (int) ( $input['retention_days'] ?? 180 ) ) ),
		);
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = site_insights_settings();
		?>
		<div class="wrap si-wrap">
			<h1><?php esc_html_e( 'Site Insights Settings', 'site-insights' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'si_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Logged-in users', 'site-insights' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="si_settings[exclude_logged_in]" value="1" <?php checked( $settings['exclude_logged_in'] ); ?> />
								<?php esc_html_e( 'Do not track logged-in users (recommended — keeps your own visits out of the stats)', 'site-insights' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Do Not Track', 'site-insights' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="si_settings[respect_dnt]" value="1" <?php checked( $settings['respect_dnt'] ); ?> />
								<?php esc_html_e( 'Honor the browser “Do Not Track” signal', 'site-insights' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="si_retention"><?php esc_html_e( 'Data retention (days)', 'site-insights' ); ?></label></th>
						<td>
							<input type="number" id="si_retention" name="si_settings[retention_days]" min="7" max="3650" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Raw view rows older than this are deleted by a daily cleanup job.', 'site-insights' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------- */

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$range = isset( $_GET['range'] ) ? (int) $_GET['range'] : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $range, array( 7, 30, 90 ), true ) ) {
			$range = 30;
		}

		$totals       = SI_Stats::totals( $range );
		$daily        = SI_Stats::daily( $range );
		$articles     = SI_Stats::top_content( $range, array( 'post', 'page' ), 10 );
		$has_woo      = post_type_exists( 'product' );
		$products     = $has_woo ? SI_Stats::top_content( $range, array( 'product' ), 10 ) : array();
		$referrers    = SI_Stats::referrers( $range, 10 );
		$channels     = SI_Stats::referrer_types( $range );
		$outbound     = SI_Stats::outbound( $range, 10 );
		$next_pages   = SI_Stats::next_pages( $range, 10 );
		$channel_sum  = max( 1, array_sum( $channels ) );
		$channel_meta = array(
			'search' => __( 'Search', 'site-insights' ),
			'social' => __( 'Social', 'site-insights' ),
			'direct' => __( 'Direct', 'site-insights' ),
			'other'  => __( 'Other sites', 'site-insights' ),
		);
		?>
		<div class="wrap si-wrap">
			<h1><?php esc_html_e( 'Site Insights', 'site-insights' ); ?></h1>

			<ul class="si-range subsubsub">
				<?php foreach ( array( 7, 30, 90 ) as $option ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'site-insights', 'range' => $option ), admin_url( 'admin.php' ) ) ); ?>"
							class="<?php echo $option === $range ? 'current' : ''; ?>">
							<?php
							/* translators: %d: number of days */
							echo esc_html( sprintf( __( 'Last %d days', 'site-insights' ), $option ) );
							?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="clear"></div>

			<div class="si-cards">
				<div class="si-card">
					<span class="si-card-value"><?php echo esc_html( number_format_i18n( $totals['views'] ) ); ?></span>
					<span class="si-card-label"><?php esc_html_e( 'Pageviews', 'site-insights' ); ?></span>
				</div>
				<div class="si-card">
					<span class="si-card-value"><?php echo esc_html( number_format_i18n( $totals['visitors'] ) ); ?></span>
					<span class="si-card-label"><?php esc_html_e( 'Unique visitors', 'site-insights' ); ?></span>
				</div>
				<div class="si-card">
					<span class="si-card-value"><?php echo esc_html( number_format_i18n( $totals['sessions'] ) ); ?></span>
					<span class="si-card-label"><?php esc_html_e( 'Sessions', 'site-insights' ); ?></span>
				</div>
				<div class="si-card">
					<span class="si-card-value"><?php echo esc_html( self::format_duration( $totals['avg_engaged'] ) ); ?></span>
					<span class="si-card-label"><?php esc_html_e( 'Avg. time on page', 'site-insights' ); ?></span>
				</div>
			</div>

			<div class="si-panel">
				<h2><?php esc_html_e( 'Views per day', 'site-insights' ); ?></h2>
				<?php echo $this->render_chart( $daily ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="si-columns">
				<div class="si-panel">
					<h2><?php esc_html_e( 'Most viewed articles & pages', 'site-insights' ); ?></h2>
					<?php $this->render_content_table( $articles ); ?>
				</div>

				<div class="si-panel">
					<h2><?php esc_html_e( 'Most viewed products', 'site-insights' ); ?></h2>
					<?php if ( ! $has_woo ) : ?>
						<p class="si-empty"><?php esc_html_e( 'WooCommerce is not active on this site.', 'site-insights' ); ?></p>
					<?php else : ?>
						<?php $this->render_content_table( $products ); ?>
					<?php endif; ?>
				</div>

				<div class="si-panel">
					<h2><?php esc_html_e( 'Traffic channels', 'site-insights' ); ?></h2>
					<?php if ( empty( $channels ) ) : ?>
						<p class="si-empty"><?php esc_html_e( 'No data yet.', 'site-insights' ); ?></p>
					<?php else : ?>
						<table class="widefat striped si-table">
							<tbody>
							<?php foreach ( $channel_meta as $key => $label ) : ?>
								<?php
								if ( empty( $channels[ $key ] ) ) {
									continue;
								}
								$pct = round( 100 * $channels[ $key ] / $channel_sum );
								?>
								<tr>
									<td class="si-bar-cell">
										<span class="si-bar" style="width:<?php echo esc_attr( $pct ); ?>%"></span>
										<span class="si-bar-label"><?php echo esc_html( $label ); ?></span>
									</td>
									<td class="si-num"><?php echo esc_html( number_format_i18n( $channels[ $key ] ) ); ?></td>
									<td class="si-num"><?php echo esc_html( $pct . '%' ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="si-panel">
					<h2><?php esc_html_e( 'Top referrers', 'site-insights' ); ?></h2>
					<?php if ( empty( $referrers ) ) : ?>
						<p class="si-empty"><?php esc_html_e( 'No data yet.', 'site-insights' ); ?></p>
					<?php else : ?>
						<table class="widefat striped si-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Source', 'site-insights' ); ?></th>
									<th class="si-num"><?php esc_html_e( 'Views', 'site-insights' ); ?></th>
									<th class="si-num"><?php esc_html_e( 'Visitors', 'site-insights' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $referrers as $row ) : ?>
								<tr>
									<td>
										<?php echo esc_html( $row['domain'] ); ?>
										<span class="si-badge si-badge-<?php echo esc_attr( $row['type'] ); ?>"><?php echo esc_html( $row['type'] ); ?></span>
									</td>
									<td class="si-num"><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td>
									<td class="si-num"><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="si-panel">
					<h2><?php esc_html_e( 'Where visitors go next (on this site)', 'site-insights' ); ?></h2>
					<?php if ( empty( $next_pages ) ) : ?>
						<p class="si-empty"><?php esc_html_e( 'No data yet.', 'site-insights' ); ?></p>
					<?php else : ?>
						<table class="widefat striped si-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Next page', 'site-insights' ); ?></th>
									<th class="si-num"><?php esc_html_e( 'Navigations', 'site-insights' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $next_pages as $row ) : ?>
								<tr>
									<td class="si-truncate"><?php echo esc_html( $row['path'] ); ?></td>
									<td class="si-num"><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="si-panel">
					<h2><?php esc_html_e( 'Outbound clicks (leaving the site)', 'site-insights' ); ?></h2>
					<?php if ( empty( $outbound ) ) : ?>
						<p class="si-empty"><?php esc_html_e( 'No data yet.', 'site-insights' ); ?></p>
					<?php else : ?>
						<table class="widefat striped si-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Destination', 'site-insights' ); ?></th>
									<th class="si-num"><?php esc_html_e( 'Clicks', 'site-insights' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $outbound as $row ) : ?>
								<tr>
									<td class="si-truncate"><?php echo esc_html( $row['domain'] ); ?></td>
									<td class="si-num"><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_content_table( array $rows ) {
		if ( empty( $rows ) ) {
			echo '<p class="si-empty">' . esc_html__( 'No data yet.', 'site-insights' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped si-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'site-insights' ); ?></th>
					<th class="si-num"><?php esc_html_e( 'Views', 'site-insights' ); ?></th>
					<th class="si-num"><?php esc_html_e( 'Visitors', 'site-insights' ); ?></th>
					<th class="si-num"><?php esc_html_e( 'Avg. read time', 'site-insights' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td class="si-truncate">
						<?php if ( $row['url'] ) : ?>
							<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $row['title'] ); ?>
						<?php endif; ?>
					</td>
					<td class="si-num"><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td>
					<td class="si-num"><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?></td>
					<td class="si-num"><?php echo esc_html( self::format_duration( $row['avg_engaged'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Inline SVG area chart of daily views. No external chart library needed.
	 *
	 * @param array $daily Series from SI_Stats::daily().
	 * @return string Safe SVG markup.
	 */
	private function render_chart( array $daily ) {
		$values = wp_list_pluck( $daily, 'views' );
		$days   = array_keys( $daily );
		$count  = count( $values );

		if ( $count < 2 ) {
			return '<p class="si-empty">' . esc_html__( 'Not enough data yet.', 'site-insights' ) . '</p>';
		}

		$width  = 600;
		$height = 140;
		$pad    = 6;
		$max    = max( 1, max( $values ) );

		$points = array();
		$i      = 0;
		foreach ( $values as $value ) {
			$x        = $pad + ( $width - 2 * $pad ) * $i / ( $count - 1 );
			$y        = $height - $pad - ( $height - 2 * $pad ) * $value / $max;
			$points[] = round( $x, 1 ) . ',' . round( $y, 1 );
			$i++;
		}

		$line = implode( ' ', $points );
		$area = $pad . ',' . ( $height - $pad ) . ' ' . $line . ' ' . ( $width - $pad ) . ',' . ( $height - $pad );

		$first_label = esc_html( date_i18n( get_option( 'date_format' ), strtotime( $days[0] ) ) );
		$last_label  = esc_html( date_i18n( get_option( 'date_format' ), strtotime( end( $days ) ) ) );
		$max_label   = esc_html( number_format_i18n( $max ) );

		return '<svg class="si-chart" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Daily pageviews chart', 'site-insights' ) . '">'
			. '<polygon class="si-chart-area" points="' . esc_attr( $area ) . '"></polygon>'
			. '<polyline class="si-chart-line" fill="none" points="' . esc_attr( $line ) . '"></polyline>'
			. '</svg>'
			. '<div class="si-chart-labels"><span>' . $first_label . '</span><span class="si-chart-max">' . sprintf( /* translators: %s: number of views */ esc_html__( 'peak: %s views/day', 'site-insights' ), $max_label ) . '</span><span>' . $last_label . '</span></div>';
	}

	/**
	 * 95 -> "1m 35s", 12 -> "12s".
	 */
	private static function format_duration( $seconds ) {
		$seconds = (int) round( $seconds );

		if ( $seconds < 60 ) {
			return $seconds . 's';
		}

		return floor( $seconds / 60 ) . 'm ' . ( $seconds % 60 ) . 's';
	}
}
