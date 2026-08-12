<?php
/**
 * Admin dashboard: menu page, summary cards, daily chart and report tables.
 *
 * @package Simple_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSA_Admin {

	const RANGES = array( 7, 30, 90 );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Site Analytics', 'simple-site-analytics' ),
			__( 'Analytics', 'simple-site-analytics' ),
			'manage_options',
			'ssa-analytics',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-bar',
			58
		);
	}

	public static function enqueue( $hook ) {
		if ( 'toplevel_page_ssa-analytics' === $hook ) {
			wp_enqueue_style( 'ssa-admin', SSA_PLUGIN_URL . 'assets/css/admin.css', array(), SSA_VERSION );
		}
	}

	private static function current_range() {
		$range = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $range, self::RANGES, true ) ? $range : 30;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$days     = self::current_range();
		$summary  = SSA_Reports::summary( $days );
		$daily    = SSA_Reports::daily_views( $days );
		$articles = SSA_Reports::top_content( $days, array( 'post', 'page' ), 10 );
		$products = post_type_exists( 'product' ) ? SSA_Reports::top_content( $days, array( 'product' ), 10 ) : null;
		$refs     = SSA_Reports::top_referrers( $days, 10 );
		$outbound = SSA_Reports::top_outbound( $days, 10 );
		$internal = SSA_Reports::top_internal_paths( $days, 10 );
		?>
		<div class="wrap ssa-wrap">
			<h1><?php esc_html_e( 'Site Analytics', 'simple-site-analytics' ); ?></h1>

			<ul class="ssa-range subsubsub">
				<?php foreach ( self::RANGES as $r ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'range', $r, admin_url( 'admin.php?page=ssa-analytics' ) ) ); ?>"
							class="<?php echo $r === $days ? 'current' : ''; ?>">
							<?php
							/* translators: %d: number of days. */
							echo esc_html( sprintf( _n( 'Last %d day', 'Last %d days', $r, 'simple-site-analytics' ), $r ) );
							?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="ssa-cards">
				<div class="ssa-card">
					<span class="ssa-card-label"><?php esc_html_e( 'Pageviews', 'simple-site-analytics' ); ?></span>
					<span class="ssa-card-value"><?php echo esc_html( number_format_i18n( $summary['views'] ) ); ?></span>
				</div>
				<div class="ssa-card">
					<span class="ssa-card-label"><?php esc_html_e( 'Unique visitors (per day)', 'simple-site-analytics' ); ?></span>
					<span class="ssa-card-value"><?php echo esc_html( number_format_i18n( $summary['visitors'] ) ); ?></span>
				</div>
				<div class="ssa-card">
					<span class="ssa-card-label"><?php esc_html_e( 'Avg. time on page', 'simple-site-analytics' ); ?></span>
					<span class="ssa-card-value"><?php echo esc_html( self::format_duration( $summary['avg_duration'] ) ); ?></span>
				</div>
			</div>

			<div class="ssa-panel">
				<h2><?php esc_html_e( 'Pageviews per day', 'simple-site-analytics' ); ?></h2>
				<?php self::render_chart( $daily ); ?>
			</div>

			<div class="ssa-grid">
				<div class="ssa-panel">
					<h2><?php esc_html_e( 'Most viewed articles & pages', 'simple-site-analytics' ); ?></h2>
					<?php self::render_content_table( $articles, __( 'Article', 'simple-site-analytics' ) ); ?>
				</div>

				<?php if ( null !== $products ) : ?>
					<div class="ssa-panel">
						<h2><?php esc_html_e( 'Most viewed products', 'simple-site-analytics' ); ?></h2>
						<?php self::render_content_table( $products, __( 'Product', 'simple-site-analytics' ) ); ?>
					</div>
				<?php endif; ?>

				<div class="ssa-panel">
					<h2><?php esc_html_e( 'Top referrers', 'simple-site-analytics' ); ?></h2>
					<?php self::render_two_col_table( $refs, __( 'Referrer', 'simple-site-analytics' ), __( 'Views', 'simple-site-analytics' ), 'referrer_domain', 'views' ); ?>
				</div>

				<div class="ssa-panel">
					<h2><?php esc_html_e( 'Top outbound destinations', 'simple-site-analytics' ); ?></h2>
					<?php self::render_two_col_table( $outbound, __( 'Destination', 'simple-site-analytics' ), __( 'Clicks', 'simple-site-analytics' ), 'target_domain', 'clicks' ); ?>
				</div>

				<div class="ssa-panel ssa-panel-wide">
					<h2><?php esc_html_e( 'Where visitors go next (internal navigation)', 'simple-site-analytics' ); ?></h2>
					<?php self::render_internal_table( $internal ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_chart( array $daily ) {
		$max = max( 1, max( $daily ) );

		if ( array_sum( $daily ) === 0 ) {
			echo '<p class="ssa-empty">' . esc_html__( 'No data yet. Views are recorded as soon as visitors browse the site.', 'simple-site-analytics' ) . '</p>';
			return;
		}

		$count  = count( $daily );
		$width  = 720;
		$height = 200;
		$pad    = array(
			'top'    => 10,
			'right'  => 8,
			'bottom' => 24,
			'left'   => 40,
		);
		$plot_w = $width - $pad['left'] - $pad['right'];
		$plot_h = $height - $pad['top'] - $pad['bottom'];
		$step   = $plot_w / $count;
		$bar_w  = max( 2, min( 24, $step - 2 ) ); // 2px surface gap between bars.

		echo '<svg class="ssa-chart" viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '" role="img" aria-label="'
			. esc_attr__( 'Bar chart of pageviews per day', 'simple-site-analytics' ) . '">';

		// Recessive horizontal gridlines + y labels at 0, half, max.
		foreach ( array( 0, 0.5, 1 ) as $t ) {
			$y = $pad['top'] + $plot_h * ( 1 - $t );
			printf(
				'<line x1="%1$d" y1="%2$.1f" x2="%3$d" y2="%2$.1f" class="%4$s"/>',
				(int) $pad['left'],
				$y, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- float built above.
				(int) ( $width - $pad['right'] ),
				0.0 === (float) $t ? 'ssa-baseline' : 'ssa-grid'
			);
			printf(
				'<text x="%1$d" y="%2$.1f" class="ssa-tick" text-anchor="end">%3$s</text>',
				(int) $pad['left'] - 6,
				$y + 3, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( number_format_i18n( (int) round( $max * $t ) ) )
			);
		}

		$i          = 0;
		$label_step = max( 1, (int) ceil( $count / 8 ) );
		foreach ( $daily as $day => $views ) {
			$h = $views > 0 ? max( 1, $plot_h * $views / $max ) : 0;
			$x = $pad['left'] + $i * $step + ( $step - $bar_w ) / 2;
			$y = $pad['top'] + $plot_h - $h;

			if ( $h > 0 ) {
				// Rounded data-end (top) anchored flat to the baseline. The
				// radius shrinks with the bar so the path never self-crosses.
				$r = min( 3, $bar_w / 2, $h / 2 );
				printf(
					'<path class="ssa-bar" d="M %1$.1f %2$.1f h %3$.1f v %4$.1f q 0 %5$.1f %6$.1f %5$.1f h %7$.1f q %6$.1f 0 %6$.1f %8$.1f Z"><title>%9$s</title></path>',
					$x, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric values built above.
					$pad['top'] + $plot_h,
					$bar_w,
					-( $h - $r ),
					-$r,
					-$r,
					-( $bar_w - 2 * $r ),
					$r,
					esc_html( date_i18n( get_option( 'date_format' ), strtotime( $day ) ) . ' — ' . number_format_i18n( $views ) )
				);
			}

			if ( 0 === $i % $label_step ) {
				printf(
					'<text x="%1$.1f" y="%2$d" class="ssa-tick" text-anchor="middle">%3$s</text>',
					$x + $bar_w / 2, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					(int) ( $height - 6 ),
					esc_html( date_i18n( 'j M', strtotime( $day ) ) )
				);
			}

			$i++;
		}

		echo '</svg>';
	}

	private static function render_content_table( array $rows, $first_col ) {
		if ( ! $rows ) {
			echo '<p class="ssa-empty">' . esc_html__( 'No data for this period.', 'simple-site-analytics' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped ssa-table">
			<thead>
				<tr>
					<th><?php echo esc_html( $first_col ); ?></th>
					<th class="ssa-num"><?php esc_html_e( 'Views', 'simple-site-analytics' ); ?></th>
					<th class="ssa-num"><?php esc_html_e( 'Visitors', 'simple-site-analytics' ); ?></th>
					<th class="ssa-num"><?php esc_html_e( 'Avg. time', 'simple-site-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_permalink( (int) $row['post_id'] ) ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( get_the_title( (int) $row['post_id'] ) ?: __( '(no title)', 'simple-site-analytics' ) ); ?>
							</a>
						</td>
						<td class="ssa-num"><?php echo esc_html( number_format_i18n( (int) $row['views'] ) ); ?></td>
						<td class="ssa-num"><?php echo esc_html( number_format_i18n( (int) $row['visitors'] ) ); ?></td>
						<td class="ssa-num"><?php echo esc_html( self::format_duration( (int) round( (float) $row['avg_duration'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_two_col_table( array $rows, $label_a, $label_b, $key_a, $key_b ) {
		if ( ! $rows ) {
			echo '<p class="ssa-empty">' . esc_html__( 'No data for this period.', 'simple-site-analytics' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped ssa-table">
			<thead>
				<tr>
					<th><?php echo esc_html( $label_a ); ?></th>
					<th class="ssa-num"><?php echo esc_html( $label_b ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row[ $key_a ] ); ?></td>
						<td class="ssa-num"><?php echo esc_html( number_format_i18n( (int) $row[ $key_b ] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_internal_table( array $rows ) {
		if ( ! $rows ) {
			echo '<p class="ssa-empty">' . esc_html__( 'No data for this period.', 'simple-site-analytics' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped ssa-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'From', 'simple-site-analytics' ); ?></th>
					<th><?php esc_html_e( 'To', 'simple-site-analytics' ); ?></th>
					<th class="ssa-num"><?php esc_html_e( 'Navigations', 'simple-site-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row['from_path'] ); ?></code></td>
						<td><code><?php echo esc_html( $row['to_path'] ); ?></code></td>
						<td class="ssa-num"><?php echo esc_html( number_format_i18n( (int) $row['moves'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		return sprintf( '%d:%02d', floor( $seconds / 60 ), $seconds % 60 );
	}
}
