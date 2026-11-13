<?php
/**
 * Database Migration Class
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Database Migration Class
 */
class Migration {

	/**
	 * Create plugin database tables
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Amazon imported products (persistent list, no duplicates by marketplace_id + asin)
		$products_table = $wpdb->prefix . 'amazon_imported_products';
		$sql_products = "CREATE TABLE IF NOT EXISTS {$products_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			marketplace_id VARCHAR(32) NOT NULL,
			asin VARCHAR(32) NOT NULL,
			seller_sku VARCHAR(64) NULL,
			title TEXT NULL,
			brand VARCHAR(255) NULL,
			gtin VARCHAR(64) NULL,
			price DECIMAL(10,2) NULL,
			quantity INT NULL,
			raw_json LONGTEXT NULL,
			first_seen_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			wc_product_id BIGINT UNSIGNED NULL,
			link_status VARCHAR(20) NULL,
			listing_status VARCHAR(64) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY unique_marketplace_asin (marketplace_id, asin),
			KEY idx_seller_sku (seller_sku),
			KEY idx_last_seen_at (last_seen_at),
			KEY idx_wc_product_id (wc_product_id)
		) {$charset_collate};";
		$wpdb->suppress_errors( true );
		dbDelta( $sql_products );
		$wpdb->suppress_errors( false );
		$wpdb->last_error = '';

		// Ensure price/quantity columns exist (for existing installations)
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$products_table}", 0 );
		if ( is_array( $cols ) && ! in_array( 'price', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$products_table} ADD COLUMN price DECIMAL(10,2) NULL AFTER gtin" );
		}
		if ( is_array( $cols ) && ! in_array( 'quantity', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$products_table} ADD COLUMN quantity INT NULL AFTER price" );
		}
		if ( is_array( $cols ) && ! in_array( 'listing_status', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$products_table} ADD COLUMN listing_status VARCHAR(64) NULL AFTER link_status" );
		}
		if ( is_array( $cols ) && ! in_array( 'sync_disabled', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$products_table} ADD COLUMN sync_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER listing_status" );
		}
		if ( is_array( $cols ) && ! in_array( 'link_method', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$products_table} ADD COLUMN link_method VARCHAR(20) NULL AFTER link_status" );
		}
		// Ensure title column exists and is TEXT (accepts long strings). If nullable, normalize to NOT NULL DEFAULT '' so inserts never store NULL when value is provided.
		if ( is_array( $cols ) && ! in_array( 'title', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$products_table} ADD COLUMN title TEXT NULL AFTER seller_sku" );
		}
		$title_info = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$products_table} WHERE Field = 'title'", ARRAY_A );
		if ( $title_info && isset( $title_info['Type'] ) ) {
			$type_lower = strtolower( $title_info['Type'] );
			if ( strpos( $type_lower, 'text' ) === false && strpos( $type_lower, 'char' ) === false ) {
				$wpdb->query( "ALTER TABLE {$products_table} MODIFY COLUMN title TEXT NULL" );
			}
			// Normalize: allow NOT NULL DEFAULT '' so empty string is stored explicitly (avoids NULL vs '' confusion)
			$wpdb->query( "UPDATE {$products_table} SET title = '' WHERE title IS NULL" );
			$wpdb->query( "ALTER TABLE {$products_table} MODIFY COLUMN title TEXT NOT NULL DEFAULT ''" );
		}

		// Amazon logs (import, test_connection, etc.)
		$logs_table = $wpdb->prefix . 'amazon_logs';
		$sql_logs = "CREATE TABLE IF NOT EXISTS {$logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			level VARCHAR(10) NOT NULL,
			scope VARCHAR(32) NOT NULL,
			message TEXT NOT NULL,
			context_json LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY idx_scope (scope),
			KEY idx_level (level),
			KEY idx_created_at (created_at)
		) {$charset_collate};";
		$wpdb->suppress_errors( true );
		dbDelta( $sql_logs );
		$wpdb->suppress_errors( false );
		$wpdb->last_error = '';

		// Amazon category mappings (WooCommerce category → Amazon browse node / category)
		$mappings_table = $wpdb->prefix . 'amazon_category_mappings';
		$sql_mappings = "CREATE TABLE IF NOT EXISTS {$mappings_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			woo_category_id BIGINT UNSIGNED NOT NULL,
			default_amazon_category_id VARCHAR(128) NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY unique_woo_category (woo_category_id)
		) {$charset_collate};";
		$wpdb->suppress_errors( true );
		dbDelta( $sql_mappings );
		$wpdb->suppress_errors( false );
		$wpdb->last_error = '';

		// Amazon orders (synced from SP-API Orders v0)
		$orders_table = $wpdb->prefix . 'amazon_orders';
		$sql_orders   = "CREATE TABLE IF NOT EXISTS {$orders_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			amazon_order_id VARCHAR(64) NOT NULL,
			marketplace_id VARCHAR(32) NULL,
			order_status VARCHAR(50) DEFAULT '',
			fulfillment_channel VARCHAR(32) NULL,
			customer_name VARCHAR(255) NULL,
			buyer_email VARCHAR(255) NULL,
			total_amount DECIMAL(12,2) NULL,
			currency VARCHAR(10) DEFAULT 'EUR',
			items_count INT NULL,
			order_date DATETIME NULL,
			order_data LONGTEXT NULL,
			items_data LONGTEXT NULL,
			shipping_data LONGTEXT NULL,
			tracking_number VARCHAR(128) NULL,
			shipping_carrier VARCHAR(64) NULL,
			is_new TINYINT(1) DEFAULT 1,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			synced_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY unique_amazon_order (amazon_order_id),
			KEY idx_order_status (order_status),
			KEY idx_order_date (order_date),
			KEY idx_is_new (is_new),
			KEY idx_synced_at (synced_at)
		) {$charset_collate};";
		$wpdb->suppress_errors( true );
		dbDelta( $sql_orders );
		$wpdb->suppress_errors( false );
		$wpdb->last_error = '';

		self::ensure_orders_columns();
	}

	/**
	 * Add shipping/tracking columns to amazon_orders for existing installs.
	 *
	 * @return void
	 */
	public static function ensure_orders_columns() {
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_orders';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! is_array( $cols ) ) {
			return;
		}

		if ( ! in_array( 'buyer_email', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN buyer_email VARCHAR(255) NULL AFTER customer_name" );
		}
		if ( ! in_array( 'shipping_data', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN shipping_data LONGTEXT NULL AFTER items_data" );
		}
		if ( ! in_array( 'tracking_number', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN tracking_number VARCHAR(128) NULL AFTER shipping_data" );
		}
		if ( ! in_array( 'shipping_carrier', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN shipping_carrier VARCHAR(64) NULL AFTER tracking_number" );
		}
	}
}
