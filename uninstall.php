<?php
/**
 * Uninstall script
 *
 * @package WCAmazonSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Delete options
delete_option( 'wc_amazon_sync_api' );
delete_option( 'wc_amazon_sync_mapping' );
delete_option( 'wc_amazon_sync_sync' );
delete_option( 'wc_amazon_sync_ui' );
delete_option( 'wc_amazon_sync_db_version' );

// Delete transients
delete_transient( 'wc_amazon_sync_access_token' );

// Drop custom tables
global $wpdb;
$tables = array(
	$wpdb->prefix . 'wc_amazon_product_map',
	$wpdb->prefix . 'amazon_imported_products',
	$wpdb->prefix . 'amazon_logs',
);
foreach ( $tables as $table_name ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
}

// Clear scheduled events
wp_clear_scheduled_hook( 'wc_amazon_sync_stock_cron' );
wp_clear_scheduled_hook( 'wc_amazon_sync_sync_inventory_cron' );
wp_clear_scheduled_hook( 'wc_amazon_sync_sync_product_cron' );

// If Action Scheduler is available
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wc_amazon_sync_sync_inventory' );
	as_unschedule_all_actions( 'wc_amazon_sync_sync_product' );
}

// Delete log directory (optional - keep logs by default)
// $upload_dir = wp_upload_dir();
// $log_dir = trailingslashit( $upload_dir['basedir'] ) . 'wc-amazon-sync-logs/';
// if ( file_exists( $log_dir ) ) {
//     // Delete directory recursively
// }

