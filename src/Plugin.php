<?php
/**
 * Main Plugin Class
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync;

use WCAmazonSync\Admin\AdminMenu;
use WCAmazonSync\Admin\OrdersPage;
use WCAmazonSync\Database\Migration;
use WCAmazonSync\Update\WordPressUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin
 */
class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get instance
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->ensure_tables();
		$this->init_hooks();
	}

	/**
	 * Ensure DB tables exist (e.g. after plugin update without reactivation)
	 */
	private function ensure_tables() {
		$version = get_option( 'wc_amazon_sync_db_version', '' );
		if ( version_compare( $version, WC_AMAZON_SYNC_VERSION, '<' ) ) {
			Migration::create_tables();
			update_option( 'wc_amazon_sync_db_version', WC_AMAZON_SYNC_VERSION );
		} else {
			// Lightweight column checks for installs that already match version.
			Migration::ensure_orders_columns();
		}
	}

	/**
	 * Init hooks
	 */
	private function init_hooks() {
		\WCAmazonSync\StockSyncCron::init();
		OrdersPage::init_ajax_handlers();
		WordPressUpdater::register();
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 9 );
		add_action( 'wp_ajax_amazon_clear_imported_products', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_clear_imported_products' ) );
		add_action( 'wp_ajax_amazon_import_products', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_import_products' ) );
		add_action( 'wp_ajax_amazon_refresh_statuses', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_refresh_statuses' ) );
		add_action( 'wp_ajax_amazon_clean_broken_links', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_clean_broken_links' ) );
		add_action( 'wp_ajax_amazon_logs_list', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_logs_list' ) );
		add_action( 'wp_ajax_amazon_logs_stream', array( 'WCAmazonSync\Admin\LogsPage', 'ajax_logs_stream' ) );
		add_action( 'wp_ajax_amazon_logs_clear', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_logs_clear' ) );
		add_action( 'wp_ajax_wc_amazon_sync_categories_refresh_amazon', array( 'WCAmazonSync\Admin\CategoriesPage', 'ajax_refresh_amazon_categories' ) );
		add_action( 'wp_ajax_wc_amazon_sync_categories_save_mapping', array( 'WCAmazonSync\Admin\CategoriesPage', 'ajax_save_mapping' ) );
		add_action( 'wp_ajax_wc_amazon_sync_categories_delete_mapping', array( 'WCAmazonSync\Admin\CategoriesPage', 'ajax_delete_mapping' ) );
		add_action( 'wp_ajax_amazon_toggle_sync_disabled', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_toggle_sync_disabled' ) );
		add_action( 'wp_ajax_amazon_link_wc_product', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_link_wc_product' ) );
		add_action( 'wp_ajax_amazon_unlink_wc_product', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_unlink_wc_product' ) );
		add_action( 'wp_ajax_amazon_unlink_by_sku', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_unlink_by_sku' ) );
		add_action( 'wp_ajax_amazon_search_wc_products', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_search_wc_products' ) );
		add_action( 'wp_ajax_amazon_export_preview', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_export_preview' ) );
		add_action( 'wp_ajax_amazon_export_submit', array( 'WCAmazonSync\Admin\ImportTabContent', 'ajax_export_submit' ) );
		// SettingsPage is registered in main plugin file on admin_init:1 for form handling
	}

	/**
	 * Register admin menu
	 */
	public function register_admin_menu() {
		new AdminMenu();
	}

	/**
	 * Activation: create DB tables
	 */
	public static function activate() {
		if ( ! wc_amazon_sync_load_autoloader() ) {
			if ( function_exists( 'wc_amazon_sync_autoloader' ) ) {
				spl_autoload_register( 'wc_amazon_sync_autoloader' );
			}
		}
		Migration::create_tables();
		update_option( 'wc_amazon_sync_db_version', WC_AMAZON_SYNC_VERSION );
	}

	/**
	 * Deactivation
	 */
	public static function deactivate() {
		// Optional: clear scheduled events
	}
}
