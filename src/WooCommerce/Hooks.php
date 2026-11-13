<?php
/**
 * WooCommerce Hooks
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Settings\SettingsRepository;
use WCAmazonSync\Background\Scheduler;
use WCAmazonSync\Database\ProductMappingRepository;
use WCAmazonSync\Logger\Logger;

/**
 * WooCommerce Hooks Class
 */
class Hooks {

	/**
	 * Instance
	 *
	 * @var Hooks|null
	 */
	private static ?Hooks $instance = null;

	/**
	 * Settings repository
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Product mapping repository
	 *
	 * @var ProductMappingRepository
	 */
	private ProductMappingRepository $mapping_repo;

	/**
	 * Logger instance
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Get instance
	 *
	 * @return Hooks
	 */
	public static function instance(): Hooks {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->settings = new SettingsRepository();
		$this->mapping_repo = new ProductMappingRepository();
		$this->logger = Logger::instance();

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		$sync_settings = $this->settings->get_group( 'sync' );

		if ( $sync_settings['auto_sync_on_save'] ?? true ) {
			// Hook into product save
			add_action( 'woocommerce_update_product', array( $this, 'on_product_save' ), 10, 1 );
			add_action( 'woocommerce_new_product', array( $this, 'on_product_save' ), 10, 1 );

			// Hook into stock update
			add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_update' ), 10, 1 );
			add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_update' ), 10, 1 );

			// Hook into price update
			add_action( 'woocommerce_product_object_updated_props', array( $this, 'on_product_update' ), 10, 2 );
		}
	}

	/**
	 * Handle product save
	 *
	 * @param int $product_id Product ID
	 * @return void
	 */
	public function on_product_save( int $product_id ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		// Check if product is mapped to Amazon
		$mapping = $this->mapping_repo->get_by_wc_product_id( $product_id );

		if ( ! $mapping ) {
			return;
		}

		// Schedule sync job
		$this->schedule_product_sync( $product_id );
	}

	/**
	 * Handle stock update
	 *
	 * @param \WC_Product $product Product object
	 * @return void
	 */
	public function on_stock_update( \WC_Product $product ): void {
		$product_id = $product->get_id();

		// Check if product is mapped
		$mapping = $this->mapping_repo->get_by_wc_product_id( $product_id );

		if ( ! $mapping ) {
			return;
		}

		// Mark as needs sync
		$this->mapping_repo->update_sync_status( $mapping->id, 'pending' );

		// Schedule inventory sync
		$this->schedule_inventory_sync( $product_id );
	}

	/**
	 * Handle product property update
	 *
	 * @param \WC_Product $product Product object
	 * @param array       $changed_props Changed properties
	 * @return void
	 */
	public function on_product_update( \WC_Product $product, array $changed_props ): void {
		$product_id = $product->get_id();

		// DO NOT sync prices from WooCommerce to Amazon (prices are managed in Product Mapping page)
		// Only sync other important properties: name, description
		$important_props = array( 'name', 'description' );
		$has_important_change = ! empty( array_intersect( $important_props, $changed_props ) );

		if ( ! $has_important_change ) {
			return;
		}

		// Check if product is mapped
		$mapping = $this->mapping_repo->get_by_wc_product_id( $product_id );

		if ( ! $mapping ) {
			return;
		}

		// Mark as needs sync
		$this->mapping_repo->update_sync_status( $mapping->id, 'pending' );

		// Schedule product sync (only for name/description, not prices)
		$this->schedule_product_sync( $product_id );
	}

	/**
	 * Schedule product sync
	 *
	 * @param int $product_id Product ID
	 * @return void
	 */
	private function schedule_product_sync( int $product_id ): void {
		if ( class_exists( 'WCAmazonSync\Background\Scheduler' ) ) {
			Scheduler::instance()->schedule_product_sync( $product_id );
		}
	}

	/**
	 * Schedule inventory sync
	 *
	 * @param int $product_id Product ID
	 * @return void
	 */
	private function schedule_inventory_sync( int $product_id ): void {
		if ( class_exists( 'WCAmazonSync\Background\Scheduler' ) ) {
			Scheduler::instance()->schedule_inventory_sync( $product_id );
		}
	}
}

