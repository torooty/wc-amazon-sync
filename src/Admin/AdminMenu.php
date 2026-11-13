<?php
/**
 * Admin Menu - registers menu and legacy redirects
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Menu
 */
class AdminMenu {

	/** @var \WCAmazonSync\Admin\CategoriesPage|null */
	private $categories_page;

	/** @var \WCAmazonSync\Admin\LogsPage|null */
	private $logs_page;

	/** @var \WCAmazonSync\Admin\ProductsPage|null */
	private $products_page;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_menu', array( $this, 'remove_legacy_menu_items' ), 999 );
		add_action( 'admin_head', array( $this, 'hide_admin_notices_on_amazon_pages' ), 999 );
	}

	/**
	 * Hide third-party admin notices (WooCommerce, other plugins) on all Amazon Sync module pages.
	 */
	public function hide_admin_notices_on_amazon_pages() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$amazon_pages = array(
			'wc-amazon-sync',
			'wc-amazon-sync-categories',
			'wc-amazon-sync-products',
			'wc-amazon-sync-orders',
			'wc-amazon-sync-logs',
		);
		if ( ! in_array( $page, $amazon_pages, true ) ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		echo '<style>.notice, .error, .updated, .update-nag { display: none !important; }</style>';
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Amazon Sync', 'wc-amazon-sync' ),
			__( 'Amazon Sync', 'wc-amazon-sync' ),
			'manage_woocommerce',
			'wc-amazon-sync',
			array( $this, 'render_settings_page' ),
			'dashicons-cloud',
			56
		);

		add_submenu_page(
			'wc-amazon-sync',
			__( 'Settings', 'wc-amazon-sync' ),
			__( 'Settings', 'wc-amazon-sync' ),
			'manage_woocommerce',
			'wc-amazon-sync',
			array( $this, 'render_settings_page' )
		);

		$this->products_page = new ProductsPage();
		add_submenu_page(
			'wc-amazon-sync',
			__( 'Produkte', 'wc-amazon-sync' ),
			__( 'Produkte', 'wc-amazon-sync' ),
			'manage_woocommerce',
			'wc-amazon-sync-products',
			array( $this->products_page, 'render' )
		);

		add_submenu_page(
			'wc-amazon-sync',
			__( 'Bestellungen', 'wc-amazon-sync' ),
			__( 'Bestellungen', 'wc-amazon-sync' ),
			'manage_woocommerce',
			'wc-amazon-sync-orders',
			array( $this, 'render_orders_page' )
		);

		$this->categories_page = new CategoriesPage();
		add_submenu_page(
			'wc-amazon-sync',
			__( 'Kategorien', 'wc-amazon-sync' ),
			__( 'Kategorien', 'wc-amazon-sync' ),
			'manage_woocommerce',
			'wc-amazon-sync-categories',
			array( $this->categories_page, 'render' )
		);

		$this->logs_page = new LogsPage();
		add_submenu_page(
			'wc-amazon-sync',
			__( 'Logs', 'wc-amazon-sync' ),
			__( 'Logs', 'wc-amazon-sync' ),
			'manage_woocommerce',
			'wc-amazon-sync-logs',
			array( $this->logs_page, 'render' )
		);
	}

	/**
	 * Render orders page
	 */
	public function render_orders_page() {
		$page = new OrdersPage();
		$page->render();
	}

	/**
	 * Render settings page (delegate to SettingsPage)
	 */
	public function render_settings_page() {
		SettingsPage::instance()->render();
	}

	/**
	 * Remove legacy submenu items from display (none currently)
	 */
	public function remove_legacy_menu_items() {
		// Kept for future use if we need to hide any menu item.
	}
}
