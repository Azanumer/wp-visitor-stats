<?php
/**
 * Uninstall WP Visitor Stats.
 * Drops both stats tables, the settings option and the cleanup cron.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'visitor_stats_daily' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'visitor_stats_hashes' );
delete_option( 'wvs_settings' );
wp_clear_scheduled_hook( 'wvs_daily_cleanup' );
