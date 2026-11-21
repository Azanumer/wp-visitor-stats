<?php
/**
 * Plugin Name: WP Visitor Stats
 * Plugin URI: https://github.com/Azanumer/wp-visitor-stats
 * Description: Privacy-friendly visitor statistics. Daily page views and unique visitors stored in your own database — no cookies, no external service, no raw IP addresses stored.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-visitor-stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WVS_VERSION', '1.0.0' );

function wvs_get_settings() {
	$defaults = array(
		'retention_days'  => 90,
		'skip_logged_in'  => true,
		'excluded_paths'  => array( '/wp-admin/', '/wp-login.php', '/wp-cron.php' ),
	);
	$saved = get_option( 'wvs_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

function wvs_table_daily() {
	global $wpdb;
	return $wpdb->prefix . 'visitor_stats_daily';
}

function wvs_table_hashes() {
	global $wpdb;
	return $wpdb->prefix . 'visitor_stats_hashes';
}

/**
 * Create the two tables on activation.
 */
function wvs_activate() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$daily   = wvs_table_daily();
	$hashes  = wvs_table_hashes();

	$sql = "CREATE TABLE $daily (
		stat_date date NOT NULL,
		path varchar(191) NOT NULL,
		views bigint(20) unsigned NOT NULL DEFAULT 0,
		uniques bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY (stat_date, path)
	) $charset;
	CREATE TABLE $hashes (
		stat_date date NOT NULL,
		path varchar(191) NOT NULL,
		visitor_hash char(64) NOT NULL,
		PRIMARY KEY (stat_date, path, visitor_hash)
	) $charset;";
	dbDelta( $sql );

	if ( false === get_option( 'wvs_settings' ) ) {
		add_option( 'wvs_settings', wvs_get_settings() );
	}
	if ( ! wp_next_scheduled( 'wvs_daily_cleanup' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'wvs_daily_cleanup' );
	}
}
register_activation_hook( __FILE__, 'wvs_activate' );

function wvs_deactivate() {
	wp_clear_scheduled_hook( 'wvs_daily_cleanup' );
}
register_deactivation_hook( __FILE__, 'wvs_deactivate' );

/**
 * Daily cleanup: drop visitor hashes and daily rows older than retention.
 */
function wvs_daily_cleanup() {
	global $wpdb;
	$settings = wvs_get_settings();
	$cutoff   = gmdate( 'Y-m-d', time() - ( (int) $settings['retention_days'] * DAY_IN_SECONDS ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . wvs_table_hashes() . ' WHERE stat_date < %s', $cutoff ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . wvs_table_daily() . ' WHERE stat_date < %s', $cutoff ) );
}
add_action( 'wvs_daily_cleanup', 'wvs_daily_cleanup' );

/**
 * Bot user-agents we never count (matched case-insensitively).
 */
function wvs_is_bot( $ua ) {
	$patterns = array( 'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests', 'headless', 'lighthouse', 'facebookexternalhit', 'monitor', 'uptime' );
	$ua       = strtolower( $ua );
	foreach ( $patterns as $pattern ) {
		if ( false !== strpos( $ua, $pattern ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Record one page view. Runs on template_redirect for front-end GETs.
 * Uniques are counted by a SHA-256 hash of (date | path | IP | UA) —
 * the raw IP is never stored.
 */
function wvs_track() {
	global $wpdb;
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() || is_trackback() ) {
		return;
	}
	$settings = wvs_get_settings();
	if ( $settings['skip_logged_in'] && is_user_logged_in() ) {
		return;
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( '' !== $ua && wvs_is_bot( $ua ) ) {
		return;
	}
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	$path = wp_parse_url( $uri, PHP_URL_PATH );
	if ( ! is_string( $path ) || '' === $path ) {
		$path = '/';
	}
	foreach ( (array) $settings['excluded_paths'] as $pattern ) {
		if ( '' !== $pattern && false !== strpos( $path, $pattern ) ) {
			return;
		}
	}
	$path = substr( $path, 0, 191 );
	$date = gmdate( 'Y-m-d' );

	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ip   = apply_filters( 'wvs_client_ip', $ip );
	$hash = hash( 'sha256', $date . '|' . $path . '|' . $ip . '|' . $ua );

	// Insert the visitor hash; if it is new for this date+path, count a unique.
	$inserted = $wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO ' . wvs_table_hashes() . ' (stat_date, path, visitor_hash) VALUES (%s, %s, %s)',
			$date,
			$path,
			$hash
		)
	);
	$is_new_unique = ( 1 === (int) $inserted );

	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO ' . wvs_table_daily() . ' (stat_date, path, views, uniques) VALUES (%s, %s, 1, %d)
			 ON DUPLICATE KEY UPDATE views = views + 1, uniques = uniques + %d',
			$date,
			$path,
			$is_new_unique ? 1 : 0,
			$is_new_unique ? 1 : 0
		)
	);
}
add_action( 'template_redirect', 'wvs_track', 999 );

/**
 * Totals + daily series + top pages for a date range.
 */
function wvs_get_report( $days ) {
	global $wpdb;
	$days  = max( 1, min( 365, (int) $days ) );
	$start = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

	$totals = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT COALESCE(SUM(views),0) AS views, COALESCE(SUM(uniques),0) AS uniques FROM ' . wvs_table_daily() . ' WHERE stat_date >= %s',
			$start
		),
		ARRAY_A
	);
	$series = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT stat_date, SUM(views) AS views, SUM(uniques) AS uniques FROM ' . wvs_table_daily() . ' WHERE stat_date >= %s GROUP BY stat_date ORDER BY stat_date ASC',
			$start
		),
		ARRAY_A
	);
	$top = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT path, SUM(views) AS views, SUM(uniques) AS uniques FROM ' . wvs_table_daily() . ' WHERE stat_date >= %s GROUP BY path ORDER BY views DESC LIMIT 20',
			$start
		),
		ARRAY_A
	);
	return array(
		'totals' => $totals ? $totals : array( 'views' => 0, 'uniques' => 0 ),
		'series' => $series ? $series : array(),
		'top'    => $top ? $top : array(),
		'start'  => $start,
	);
}

/* ------------------------------------------------------------------ */
/* Admin pages                                                         */
/* ------------------------------------------------------------------ */

function wvs_register_pages() {
	add_management_page(
		__( 'Visitor Stats', 'wp-visitor-stats' ),
		__( 'Visitor Stats', 'wp-visitor-stats' ),
		'manage_options',
		'wp-visitor-stats',
		'wvs_render_stats_page'
	);
	add_options_page(
		__( 'Visitor Stats', 'wp-visitor-stats' ),
		__( 'Visitor Stats', 'wp-visitor-stats' ),
		'manage_options',
		'wp-visitor-stats-settings',
		'wvs_render_settings_page'
	);
}
add_action( 'admin_menu', 'wvs_register_pages' );

function wvs_render_stats_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-visitor-stats' ) );
	}
	$days   = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30;
	$days   = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
	$report = wvs_get_report( $days );
	$max    = 0;
	foreach ( $report['series'] as $row ) {
		$max = max( $max, (int) $row['views'] );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Visitor Stats', 'wp-visitor-stats' ); ?></h1>
		<p>
			<?php foreach ( array( 7, 30, 90 ) as $d ) : ?>
				<a class="button <?php echo $d === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'days', $d ) ); ?>">
					<?php /* translators: %d: number of days. */ echo esc_html( sprintf( __( 'Last %d days', 'wp-visitor-stats' ), $d ) ); ?>
				</a>
			<?php endforeach; ?>
		</p>
		<h2><?php esc_html_e( 'Totals', 'wp-visitor-stats' ); ?></h2>
		<p>
			<strong><?php esc_html_e( 'Views:', 'wp-visitor-stats' ); ?></strong> <?php echo esc_html( number_format_i18n( (int) $report['totals']['views'] ) ); ?>
			&nbsp;|&nbsp; <strong><?php esc_html_e( 'Unique visitors:', 'wp-visitor-stats' ); ?></strong> <?php echo esc_html( number_format_i18n( (int) $report['totals']['uniques'] ) ); ?>
		</p>

		<h2><?php esc_html_e( 'Daily views', 'wp-visitor-stats' ); ?></h2>
		<?php if ( empty( $report['series'] ) ) : ?>
			<p><?php esc_html_e( 'No visits recorded yet in this period.', 'wp-visitor-stats' ); ?></p>
		<?php else : ?>
			<div style="display:flex;align-items:flex-end;gap:2px;height:120px;border-bottom:1px solid #ccc;padding-bottom:4px;">
				<?php foreach ( $report['series'] as $row ) : ?>
					<div title="<?php echo esc_attr( $row['stat_date'] . ': ' . number_format_i18n( (int) $row['views'] ) . ' views' ); ?>"
						style="flex:1;background:#2271b1;height:<?php echo $max > 0 ? max( 2, round( ( (int) $row['views'] / $max ) * 100 ) ) : 0; ?>%;min-width:2px;"></div>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php echo esc_html( $report['start'] . ' — ' . gmdate( 'Y-m-d' ) ); ?></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Top pages', 'wp-visitor-stats' ); ?></h2>
		<?php if ( empty( $report['top'] ) ) : ?>
			<p><?php esc_html_e( 'No visits recorded yet in this period.', 'wp-visitor-stats' ); ?></p>
		<?php else : ?>
			<table class="widefat striped"><thead><tr>
				<th><?php esc_html_e( 'Page', 'wp-visitor-stats' ); ?></th>
				<th><?php esc_html_e( 'Views', 'wp-visitor-stats' ); ?></th>
				<th><?php esc_html_e( 'Unique visitors', 'wp-visitor-stats' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $report['top'] as $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( $row['path'] ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( (int) $row['views'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $row['uniques'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Privacy: no cookies are set and no raw IP addresses are stored — uniques are counted from one-way hashes that are deleted with the daily cleanup.', 'wp-visitor-stats' ); ?></p>
	</div>
	<?php
}

function wvs_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-visitor-stats' ) );
	}
	$settings = wvs_get_settings();
	$message  = '';
	if ( isset( $_POST['wvs_save'] ) && check_admin_referer( 'wvs_save_settings' ) ) {
		$excluded_raw = isset( $_POST['wvs_excluded'] ) ? wp_unslash( $_POST['wvs_excluded'] ) : '';
		$excluded     = array_filter( array_map( 'trim', explode( "\n", $excluded_raw ) ) );
		$settings     = array(
			'retention_days' => max( 7, min( 365, (int) ( $_POST['wvs_retention'] ?? 90 ) ) ),
			'skip_logged_in' => ! empty( $_POST['wvs_skip_logged_in'] ),
			'excluded_paths' => array_values( $excluded ),
		);
		update_option( 'wvs_settings', $settings );
		$message = __( 'Settings saved.', 'wp-visitor-stats' );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Visitor Stats Settings', 'wp-visitor-stats' ); ?></h1>
		<?php if ( '' !== $message ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'wvs_save_settings' ); ?>
			<table class="form-table"><tbody>
				<tr>
					<th scope="row"><label for="wvs_retention"><?php esc_html_e( 'Keep data for (days)', 'wp-visitor-stats' ); ?></label></th>
					<td><input type="number" id="wvs_retention" name="wvs_retention" min="7" max="365" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wvs_skip_logged_in"><?php esc_html_e( 'Skip logged-in users', 'wp-visitor-stats' ); ?></label></th>
					<td><input type="checkbox" id="wvs_skip_logged_in" name="wvs_skip_logged_in" value="1" <?php checked( $settings['skip_logged_in'] ); ?>> <span class="description"><?php esc_html_e( 'Your own visits are not counted.', 'wp-visitor-stats' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label for="wvs_excluded"><?php esc_html_e( 'Excluded URL patterns', 'wp-visitor-stats' ); ?></label></th>
					<td>
						<textarea id="wvs_excluded" name="wvs_excluded" rows="4" cols="50" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['excluded_paths'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One substring per line. Any path containing one of these is never counted.', 'wp-visitor-stats' ); ?></p>
					</td>
				</tr>
			</tbody></table>
			<p><input type="submit" name="wvs_save" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'wp-visitor-stats' ); ?>"></p>
		</form>
	</div>
	<?php
}
