<?php
/**
 * Inventory Sync Service
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Amazon;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Amazon\AmazonApiClient;
use WCAmazonSync\Database\ProductMappingRepository;
use WCAmazonSync\Settings\SettingsRepository;
use WCAmazonSync\Logger\Logger;

/**
 * Inventory Sync Service Class
 */
class InventorySyncService {

	/**
	 * Amazon API client
	 *
	 * @var AmazonApiClient
	 */
	private AmazonApiClient $api_client;

	/**
	 * Product mapping repository
	 *
	 * @var ProductMappingRepository
	 */
	private ProductMappingRepository $mapping_repo;

	/**
	 * Settings repository
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Logger instance
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor
	 *
	 * @param AmazonApiClient|null           $api_client API client
	 * @param ProductMappingRepository|null $mapping_repo Mapping repository
	 * @param SettingsRepository|null       $settings Settings repository
	 * @param Logger|null                   $logger Logger instance
	 */
	public function __construct(
		?AmazonApiClient $api_client = null,
		?ProductMappingRepository $mapping_repo = null,
		?SettingsRepository $settings = null,
		?Logger $logger = null
	) {
		$this->api_client = $api_client ?? new AmazonApiClient();
		$this->mapping_repo = $mapping_repo ?? new ProductMappingRepository();
		$this->settings = $settings ?? new SettingsRepository();
		$this->logger = $logger ?? Logger::instance();
	}

	/**
	 * Sync inventory from WooCommerce to Amazon
	 *
	 * @param array $product_ids WooCommerce product IDs (optional, syncs all if empty)
	 * @return array Results array with success/error counts
	 */
	public function sync_woo_to_amazon( array $product_ids = array() ): array {
		$results = array(
			'success' => 0,
			'error' => 0,
			'errors' => array(),
		);

		// Get mappings
		if ( empty( $product_ids ) ) {
			$mappings = $this->mapping_repo->get_pending_sync( 100 );
		} else {
			$mappings = array();
			foreach ( $product_ids as $product_id ) {
				$mapping = $this->mapping_repo->get_by_wc_product_id( $product_id );
				if ( $mapping ) {
					$mappings[] = $mapping;
				}
			}
		}

		if ( empty( $mappings ) ) {
			$this->logger->info( 'No products to sync' );
			return $results;
		}

		// Batch update inventory
		$inventory_items = array();

		foreach ( $mappings as $mapping ) {
			$product = wc_get_product( $mapping->wc_product_id );

			if ( ! $product ) {
				$error = sprintf(
					/* translators: %d: Product ID */
					__( 'Product %d not found.', 'wc-amazon-sync' ),
					$mapping->wc_product_id
				);
				$results['error']++;
				$results['errors'][] = $error;
				$this->mapping_repo->update_sync_status( $mapping->id, 'error', $error );
				continue;
			}

			$inventory_items[] = array(
				'sku' => $product->get_sku() ?: 'WC-' . $product->get_id(),
				'quantity' => $product->get_stock_quantity() ?? 0,
			);

			// Update mapping status
			$this->mapping_repo->update_sync_status( $mapping->id, 'syncing' );
		}

		if ( empty( $inventory_items ) ) {
			return $results;
		}

		// Send batch update to Amazon
		$response = $this->api_client->update_inventory( $inventory_items );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->logger->error( 'Inventory sync failed: ' . $error_message );

			// Mark all as error
			foreach ( $mappings as $mapping ) {
				$this->mapping_repo->update_sync_status( $mapping->id, 'error', $error_message );
			}

			$results['error'] = count( $mappings );
			$results['errors'][] = $error_message;
			return $results;
		}

		// Update sync status for successful items
		foreach ( $mappings as $mapping ) {
			$this->mapping_repo->update_sync_status( $mapping->id, 'synced' );
			$results['success']++;
		}

		$this->logger->info(
			sprintf(
				/* translators: %d: Number of items synced */
				__( 'Synced %d inventory items to Amazon.', 'wc-amazon-sync' ),
				count( $inventory_items )
			)
		);

		return $results;
	}

	/**
	 * Sync inventory from Amazon to WooCommerce
	 *
	 * @param array $skus Product SKUs (optional)
	 * @return array Results array
	 */
	public function sync_amazon_to_woo( array $skus = array() ): array {
		$results = array(
			'success' => 0,
			'error' => 0,
			'errors' => array(),
		);

		// Get inventory summary from Amazon
		$response = $this->api_client->get_inventory_summary( $skus );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->logger->error( 'Failed to get inventory from Amazon: ' . $error_message );
			$results['error'] = 1;
			$results['errors'][] = $error_message;
			return $results;
		}

		// Process inventory data
		$inventory_data = $response['inventorySummaries'] ?? array();

		foreach ( $inventory_data as $item ) {
			$asin = $item['asin'] ?? '';
			$sku = $item['sellerSku'] ?? '';
			$quantity = absint( $item['totalQuantity'] ?? 0 );

			// Find WooCommerce product by mapping
			$mapping = $this->mapping_repo->get_by_asin( $asin );
			if ( ! $mapping ) {
				// Try to find by SKU
				$product_id = wc_get_product_id_by_sku( $sku );
				if ( ! $product_id ) {
					continue;
				}
			} else {
				$product_id = $mapping->wc_product_id;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			// Update stock quantity
			$product->set_stock_quantity( $quantity );
			$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
			$product->save();

			if ( $mapping ) {
				$this->mapping_repo->update_sync_status( $mapping->id, 'synced' );
			}

			$results['success']++;
		}

		$this->logger->info(
			sprintf(
				/* translators: %d: Number of items synced */
				__( 'Synced %d inventory items from Amazon to WooCommerce.', 'wc-amazon-sync' ),
				count( $inventory_data )
			)
		);

		return $results;
	}

	/**
	 * Sync inventory based on settings
	 *
	 * @param array $product_ids Product IDs (optional)
	 * @return array Results
	 */
	public function sync( array $product_ids = array() ): array {
		$sync_settings = $this->settings->get_group( 'sync' );
		$direction = $sync_settings['inventory_direction'] ?? 'woo_to_amazon';

		switch ( $direction ) {
			case 'amazon_to_woo':
				return $this->sync_amazon_to_woo( array() );
			case 'bidirectional':
				$results_woo = $this->sync_woo_to_amazon( $product_ids );
				$results_amazon = $this->sync_amazon_to_woo( array() );
				return array(
					'success' => $results_woo['success'] + $results_amazon['success'],
					'error' => $results_woo['error'] + $results_amazon['error'],
					'errors' => array_merge( $results_woo['errors'], $results_amazon['errors'] ),
				);
			default:
				return $this->sync_woo_to_amazon( $product_ids );
		}
	}
}

