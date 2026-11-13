<?php
/**
 * UI Assets
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\UI;

defined( 'ABSPATH' ) || exit;

/**
 * UI Assets Class
 */
class Assets {

	/**
	 * Instance
	 *
	 * @var Assets|null
	 */
	private static ?Assets $instance = null;

	/**
	 * Get instance
	 *
	 * @return Assets
	 */
	public static function instance(): Assets {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		// Only load on our plugin pages
		if ( strpos( $hook, 'wc-amazon-sync' ) === false ) {
			return;
		}

		// Enqueue styles
		wp_enqueue_style(
			'wc-amazon-sync-admin',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WC_AMAZON_SYNC_VERSION
		);

		// Enqueue SelectWoo (WooCommerce's Select2 fork) for searchable dropdowns
		if ( class_exists( 'WooCommerce' ) ) {
			wp_enqueue_script( 'selectWoo' );
			wp_enqueue_style( 'select2' );
		}

		// Enqueue scripts
		wp_enqueue_script(
			'wc-amazon-sync-admin',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'selectWoo' ),
			WC_AMAZON_SYNC_VERSION,
			true
		);

		// Localize script
		wp_localize_script(
			'wc-amazon-sync-admin',
			'wcAmazonSync',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wc_amazon_sync_nonce' ), // General nonce for most AJAX actions
				'testConnectionNonce' => wp_create_nonce( 'wc_amazon_sync_test_connection' ), // Specific nonce for test connection
				'i18n' => array(
					'confirmDelete' => __( 'Are you sure you want to delete this?', 'wc-amazon-sync' ),
					'syncing' => __( 'Syncing...', 'wc-amazon-sync' ),
					'syncComplete' => __( 'Sync complete!', 'wc-amazon-sync' ),
					'syncFailed' => __( 'Sync failed. Please try again.', 'wc-amazon-sync' ),
					'selectProduct' => __( 'Select product...', 'wc-amazon-sync' ),
					'createNew' => __( 'Create New', 'wc-amazon-sync' ),
					'noResults' => __( 'No products found', 'wc-amazon-sync' ),
					'searching' => __( 'Searching...', 'wc-amazon-sync' ),
					'progressText' => __( 'Processed: %1$d / %2$d | Matched: %3$d | Not found: %4$d', 'wc-amazon-sync' ),
					'matchingComplete' => __( 'Automatic matching complete! Matched: %1$d, Not found: %2$d', 'wc-amazon-sync' ),
				),
				'strings' => array(
					'savingMappings' => __( 'Saving mappings: {processed} of {total}', 'wc-amazon-sync' ),
					'succeeded' => __( 'Succeeded', 'wc-amazon-sync' ),
					'failed' => __( 'Failed', 'wc-amazon-sync' ),
					'mappingsSaved' => __( 'Mappings saved', 'wc-amazon-sync' ),
				),
			)
		);
	}
}

