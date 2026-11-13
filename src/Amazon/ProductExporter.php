<?php
/**
 * Product Exporter
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Amazon;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\WooCommerce\ProductMapper;
use WCAmazonSync\Database\ProductMappingRepository;
use WCAmazonSync\Logger\Logger;

/**
 * Product Exporter Class
 */
class ProductExporter {

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
	 * @param Logger|null                   $logger Logger instance
	 */
	public function __construct(
		?AmazonApiClient $api_client = null,
		?ProductMappingRepository $mapping_repo = null,
		?Logger $logger = null
	) {
		$this->api_client = $api_client ?? new AmazonApiClient();
		$this->mapping_repo = $mapping_repo ?? new ProductMappingRepository();
		$this->logger = $logger ?? Logger::instance();
	}

	/**
	 * Export WooCommerce product to Amazon
	 *
	 * @param int $product_id WooCommerce product ID
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function export_product( int $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', __( 'Product not found.', 'wc-amazon-sync' ) );
		}

		// Get mapping
		$mapping = $this->mapping_repo->get_by_wc_product_id( $product_id );

		if ( ! $mapping ) {
			return new \WP_Error( 'no_mapping', __( 'Product is not mapped to Amazon.', 'wc-amazon-sync' ) );
		}

		// Map product data
		$amazon_data = ProductMapper::wc_to_amazon( $product );

		// Prepare listing data
		$listing_data = array(
			'product_type' => 'PRODUCT',
			'attributes' => $amazon_data['attributes'] ?? array(),
		);

		// Create or update listing
		$response = $this->api_client->create_or_update_listing( $mapping->amazon_asin, $listing_data );

		if ( is_wp_error( $response ) ) {
			$this->mapping_repo->update_sync_status( $mapping->id, 'error', $response->get_error_message() );
			return $response;
		}

		// Update sync status
		$this->mapping_repo->update_sync_status( $mapping->id, 'synced' );

		$this->logger->info(
			sprintf(
				/* translators: %1$d: Product ID, %2$s: ASIN */
				__( 'Exported product %1$d to Amazon (ASIN: %2$s)', 'wc-amazon-sync' ),
				$product_id,
				$mapping->amazon_asin
			)
		);

		return true;
	}

	/**
	 * Export multiple products
	 *
	 * @param array $product_ids Product IDs
	 * @return array Results array
	 */
	public function export_products( array $product_ids ): array {
		$results = array(
			'success' => 0,
			'error' => 0,
			'errors' => array(),
		);

		foreach ( $product_ids as $product_id ) {
			$result = $this->export_product( $product_id );

			if ( is_wp_error( $result ) ) {
				$results['error']++;
				$results['errors'][] = $result->get_error_message();
			} else {
				$results['success']++;
			}
		}

		return $results;
	}

	/**
	 * Update only product price on Amazon
	 *
	 * @param int   $product_id WooCommerce product ID
	 * @param float $price Price amount (if null, uses product price)
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function update_price( int $product_id, ?float $price = null ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', __( 'Product not found.', 'wc-amazon-sync' ) );
		}

		// Get mapping
		$mapping = $this->mapping_repo->get_by_wc_product_id( $product_id );

		if ( ! $mapping ) {
			return new \WP_Error( 'no_mapping', __( 'Product is not mapped to Amazon.', 'wc-amazon-sync' ) );
		}

		// Use provided price or product price
		$price_to_update = $price ?? (float) $product->get_price();
		$currency = get_woocommerce_currency();

		// Update price on Amazon
		$response = $this->api_client->update_price( $mapping->amazon_asin, $price_to_update, $currency );

		if ( is_wp_error( $response ) ) {
			$this->mapping_repo->update_sync_status( $mapping->id, 'error', $response->get_error_message() );
			return $response;
		}

		// Update sync status
		$this->mapping_repo->update_sync_status( $mapping->id, 'synced' );

		$this->logger->info(
			sprintf(
				/* translators: %1$d: Product ID, %2$s: ASIN, %3$.2f: Price */
				__( 'Updated price for product %1$d on Amazon (ASIN: %2$s, Price: %3$.2f %4$s)', 'wc-amazon-sync' ),
				$product_id,
				$mapping->amazon_asin,
				$price_to_update,
				$currency
			)
		);

		return true;
	}

	/**
	 * Update only inventory on Amazon
	 *
	 * @param int $product_id WooCommerce product ID
	 * @param int $quantity Quantity (if null, uses product stock quantity)
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function update_inventory( int $product_id, ?int $quantity = null ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', __( 'Product not found.', 'wc-amazon-sync' ) );
		}

		// Get mapping
		$mapping = $this->mapping_repo->get_by_wc_product_id( $product_id );

		if ( ! $mapping ) {
			return new \WP_Error( 'no_mapping', __( 'Product is not mapped to Amazon.', 'wc-amazon-sync' ) );
		}

		$sku = $product->get_sku() ?: 'WC-' . $product_id;
		$quantity_to_update = $quantity ?? ( $product->get_stock_quantity() ?? 0 );

		// Update inventory on Amazon
		$response = $this->api_client->update_single_inventory( $sku, $quantity_to_update );

		if ( is_wp_error( $response ) ) {
			$this->mapping_repo->update_sync_status( $mapping->id, 'error', $response->get_error_message() );
			return $response;
		}

		// Update sync status
		$this->mapping_repo->update_sync_status( $mapping->id, 'synced' );

		$this->logger->info(
			sprintf(
				/* translators: %1$d: Product ID, %2$s: ASIN, %3$d: Quantity */
				__( 'Updated inventory for product %1$d on Amazon (ASIN: %2$s, Quantity: %3$d)', 'wc-amazon-sync' ),
				$product_id,
				$mapping->amazon_asin,
				$quantity_to_update
			)
		);

		return true;
	}

	/**
	 * Update all mapped products inventory manually
	 *
	 * @return array Results array
	 */
	public function update_all_inventory(): array {
		$results = array(
			'success' => 0,
			'error' => 0,
			'errors' => array(),
		);

		$mappings = $this->mapping_repo->get_all( array( 'limit' => 1000 ) );

		foreach ( $mappings as $mapping ) {
			$result = $this->update_inventory( $mapping->wc_product_id );

			if ( is_wp_error( $result ) ) {
				$results['error']++;
				$results['errors'][] = sprintf(
					/* translators: %d: Product ID */
					__( 'Product %d: %s', 'wc-amazon-sync' ),
					$mapping->wc_product_id,
					$result->get_error_message()
				);
			} else {
				$results['success']++;
			}
		}

		return $results;
	}
}

