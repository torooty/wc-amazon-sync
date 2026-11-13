<?php
/**
 * Product Importer
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Amazon;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\WooCommerce\ProductMapper;
use WCAmazonSync\Database\ProductMappingRepository;
use WCAmazonSync\Logger\Logger;

/**
 * Product Importer Class
 */
class ProductImporter {

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
	 * Import products from Amazon
	 *
	 * @param array $args Query arguments
	 * @return array|WP_Error Products array or WP_Error
	 */
	public function import_products( array $args = array() ) {
		// Use Reports API by default (more reliable)
		// Fallback to Catalog API if method is specified
		$method = $args['method'] ?? 'reports';
		
		if ( 'catalog' === $method ) {
			// Try Catalog API (may require different permissions)
			$response = $this->api_client->get_catalog_items( $args );
			
			if ( is_wp_error( $response ) ) {
				$this->logger->warning( 'Catalog API failed, falling back to Reports API: ' . $response->get_error_message() );
				// Fallback to Reports API
				$response = $this->api_client->get_merchant_listings( $args );
			} else {
				$products = $response['items'] ?? array();
				return $products;
			}
		} else {
			// Use Reports API (recommended)
			$response = $this->api_client->get_merchant_listings( $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Reports API returns array of products directly
		// Catalog API returns response with 'items' key
		$products = is_array( $response ) && isset( $response[0] ) && is_array( $response[0] ) 
			? $response 
			: ( $response['items'] ?? array() );

		return $products;
	}

	/**
	 * Create WooCommerce product from Amazon data
	 *
	 * @param array $amazon_data Amazon product data
	 * @param string $mapping_type Mapping type
	 * @return int|WP_Error Product ID or WP_Error
	 */
	public function create_wc_product( array $amazon_data, string $mapping_type = 'sku' ) {
		$wc_data = ProductMapper::amazon_to_wc( $amazon_data );

		// Create product
		$product = new \WC_Product_Simple();
		$product->set_name( $wc_data['name'] );
		$product->set_description( $wc_data['description'] );
		$product->set_short_description( $wc_data['short_description'] ?? '' );
		$product->set_sku( $wc_data['sku'] );
		$product->set_regular_price( $wc_data['regular_price'] );
		$product->set_manage_stock( $wc_data['manage_stock'] );
		$product->set_stock_quantity( $wc_data['stock_quantity'] );
		$product->set_stock_status( $wc_data['stock_status'] );

		if ( ! empty( $wc_data['image_id'] ) ) {
			$product->set_image_id( $wc_data['image_id'] );
		}

		$product_id = $product->save();

		if ( ! $product_id ) {
			return new \WP_Error( 'product_creation_failed', __( 'Failed to create product.', 'wc-amazon-sync' ) );
		}

		// Create mapping
		$asin = $amazon_data['asin'] ?? '';
		if ( ! empty( $asin ) ) {
			$this->mapping_repo->create( $product_id, $asin, $mapping_type );
		}

		$this->logger->info(
			sprintf(
				/* translators: %1$d: Product ID, %2$s: Product name */
				__( 'Created WooCommerce product %1$d: %2$s', 'wc-amazon-sync' ),
				$product_id,
				$wc_data['name']
			)
		);

		return $product_id;
	}
}

