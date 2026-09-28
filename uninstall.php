<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$slr_settings = get_option( 'slr_settings', array() );
if ( empty( $slr_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'visitors', 'events', 'leads' ) as $slr_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'slr_' . $slr_table ); // phpcs:ignore
}
delete_option( 'slr_settings' );
delete_option( 'slr_salt' );
delete_option( 'slr_db_version' );
wp_clear_scheduled_hook( 'slr_daily_cleanup' );
