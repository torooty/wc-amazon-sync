<?php
/**
 * AJAX Handlers
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Amazon\AmazonApiClient;
use WCAmazonSync\Amazon\ProductImporter;
use WCAmazonSync\Amazon\InventorySyncService;
use WCAmazonSync\Database\ProductMappingRepository;

/**
 * AJAX Handlers Class
 */
class AjaxHandlers {

	/**
	 * Instance
	 *
	 * @var AjaxHandlers|null
	 */
	private static ?AjaxHandlers $instance = null;

	/**
	 * Get instance
	 *
	 * @return AjaxHandlers
	 */
	public static function instance(): AjaxHandlers {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->register_handlers();
	}

	/**
	 * Register AJAX handlers
	 *
	 * @return void
	 */
	private function register_handlers(): void {
		add_action( 'wp_ajax_wc_amazon_sync_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_wc_amazon_sync_import_products', array( $this, 'import_products' ) );
		add_action( 'wp_ajax_wc_amazon_sync_sync_product', array( $this, 'sync_product' ) );
		add_action( 'wp_ajax_wc_amazon_sync_delete_mapping', array( $this, 'delete_mapping' ) );
		add_action( 'wp_ajax_wc_amazon_sync_update_price', array( $this, 'update_price' ) );
		add_action( 'wp_ajax_wc_amazon_sync_update_inventory', array( $this, 'update_inventory' ) );
		add_action( 'wp_ajax_wc_amazon_sync_update_all_inventory', array( $this, 'update_all_inventory' ) );
		add_action( 'wp_ajax_wc_amazon_sync_delete_all_mappings', array( $this, 'delete_all_mappings' ) );
		add_action( 'wp_ajax_wc_amazon_sync_get_wc_products', array( $this, 'get_wc_products' ) );
		add_action( 'wp_ajax_wc_amazon_sync_find_product_by_sku', array( $this, 'find_product_by_sku' ) );
		add_action( 'wp_ajax_wc_amazon_sync_save_mappings', array( $this, 'save_mappings' ) );
	}

	/**
	 * Test connection handler
	 *
	 * @return void
	 */
	public function test_connection(): void {
		// Check nonce - allow both methods
		if ( ! check_ajax_referer( 'wc_amazon_sync_test_connection', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'wc-amazon-sync' ) ) );
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
			return;
		}

		try {
			delete_transient( 'wc_amazon_sync_access_token' );
			$api_client = new AmazonApiClient();
			$result = $api_client->test_connection();

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return;
			}

			wp_send_json_success( array( 'message' => __( 'Connection successful!', 'wc-amazon-sync' ) ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Import products handler
	 *
	 * @return void
	 */
	public function import_products(): void {
		// Check nonce - allow both methods
		if ( ! check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce', false ) ) {
			\WCAmazonSync\Logger\Logger::instance()->error( 'Import products: Security check failed. Nonce: ' . ( isset( $_POST['nonce'] ) ? $_POST['nonce'] : 'missing' ) );
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'wc-amazon-sync' ) ) );
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			\WCAmazonSync\Logger\Logger::instance()->error( 'Import products: Insufficient permissions' );
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
			return;
		}

		\WCAmazonSync\Logger\Logger::instance()->info( 'Starting product import from Amazon...' );

		try {
			$importer = new \WCAmazonSync\Amazon\ProductImporter();
			$products = $importer->import_products();

			if ( is_wp_error( $products ) ) {
				\WCAmazonSync\Logger\Logger::instance()->error( 'Import products failed: ' . $products->get_error_message() );
				wp_send_json_error( array( 'message' => $products->get_error_message() ) );
				return;
			}

			$count = is_array( $products ) ? count( $products ) : 0;
			\WCAmazonSync\Logger\Logger::instance()->info( sprintf( 'Product import completed: %d products imported', $count ) );

			wp_send_json_success( array( 'products' => $products, 'count' => $count ) );
		} catch ( \Exception $e ) {
			\WCAmazonSync\Logger\Logger::instance()->error( 'Import products exception: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Sync product handler
	 *
	 * @return void
	 */
	public function sync_product(): void {
		check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'wc-amazon-sync' ) ) );
		}

		$sync_service = new InventorySyncService();
		$result = $sync_service->sync( array( $product_id ) );

		wp_send_json_success( $result );
	}

	/**
	 * Delete mapping handler
	 *
	 * @return void
	 */
	public function delete_mapping(): void {
		check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
		}

		$mapping_id = isset( $_POST['mapping_id'] ) ? absint( $_POST['mapping_id'] ) : 0;

		if ( ! $mapping_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid mapping ID.', 'wc-amazon-sync' ) ) );
		}

		$mapping_repo = new ProductMappingRepository();
		$result = $mapping_repo->delete( $mapping_id );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete mapping.', 'wc-amazon-sync' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Mapping deleted successfully.', 'wc-amazon-sync' ) ) );
	}

	/**
	 * Update price handler
	 *
	 * @return void
	 */
	public function update_price(): void {
		check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$price = isset( $_POST['price'] ) ? (float) $_POST['price'] : null;

		if ( ! $product_id || $price === null || $price < 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID or price.', 'wc-amazon-sync' ) ) );
		}

		try {
			$exporter = new \WCAmazonSync\Amazon\ProductExporter();
			$result = $exporter->update_price( $product_id, $price );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return;
			}

			// Save price in post meta for display
			update_post_meta( $product_id, '_amazon_price', $price );

			wp_send_json_success( array( 'message' => __( 'Price updated successfully on Amazon.', 'wc-amazon-sync' ) ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Update inventory handler
	 *
	 * @return void
	 */
	public function update_inventory(): void {
		check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'wc-amazon-sync' ) ) );
		}

		try {
			$exporter = new \WCAmazonSync\Amazon\ProductExporter();
			$result = $exporter->update_inventory( $product_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return;
			}

			$product = wc_get_product( $product_id );
			$quantity = $product ? ( $product->get_stock_quantity() ?? 0 ) : 0;

			wp_send_json_success( 
				array( 
					'message' => __( 'Inventory updated successfully on Amazon.', 'wc-amazon-sync' ),
					'quantity' => $quantity,
				) 
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Update all inventory handler
	 *
	 * @return void
	 */
	public function update_all_inventory(): void {
		check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
		}

		try {
			$exporter = new \WCAmazonSync\Amazon\ProductExporter();
			$results = $exporter->update_all_inventory();

			wp_send_json_success( 
				array( 
					'message' => sprintf(
						/* translators: %1$d: Success count, %2$d: Error count */
						__( 'Inventory update completed: %1$d succeeded, %2$d failed.', 'wc-amazon-sync' ),
						$results['success'],
						$results['error']
					),
					'results' => $results,
				) 
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Delete all mappings handler
	 *
	 * @return void
	 */
	public function delete_all_mappings(): void {
		check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
		}

		try {
			$mapping_repo = new ProductMappingRepository();
			$deleted = $mapping_repo->delete_all();

			if ( false === $deleted ) {
				wp_send_json_error( array( 'message' => __( 'Failed to delete all mappings.', 'wc-amazon-sync' ) ) );
				return;
			}

			wp_send_json_success( 
				array( 
					'message' => sprintf(
						/* translators: %d: Number of deleted mappings */
						__( 'All mappings deleted successfully (%d items).', 'wc-amazon-sync' ),
						$deleted
					),
				) 
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Get WooCommerce products list for dropdown
	 *
	 * @return void
	 */
	public function get_wc_products(): void {
		check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
		}

		$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$limit = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 100;
		$offset = isset( $_GET['offset'] ) ? absint( $_GET['offset'] ) : 0;

		$args = array(
			'limit' => $limit,
			'offset' => $offset,
			'status' => 'publish',
			'orderby' => 'title',
			'order' => 'ASC',
		);

		// Search by product name or SKU
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
			
			// Also search by SKU if search doesn't match any products
			$sku_search_id = wc_get_product_id_by_sku( $search );
			if ( $sku_search_id ) {
				$args['include'] = isset( $args['include'] ) ? array_merge( $args['include'], array( $sku_search_id ) ) : array( $sku_search_id );
			}
		}

		$products = wc_get_products( $args );
		$results = array();

		foreach ( $products as $product ) {
			$product_sku = $product->get_sku() ?: '';
			$product_name = $product->get_name();
			
			// If searching, also check if SKU or name matches
			if ( ! empty( $search ) ) {
				$search_lower = strtolower( $search );
				$sku_match = ! empty( $product_sku ) && ( stripos( $product_sku, $search ) !== false || stripos( $search, $product_sku ) !== false );
				$name_match = stripos( strtolower( $product_name ), $search_lower ) !== false;
				
				if ( ! $sku_match && ! $name_match ) {
					continue; // Skip products that don't match search
				}
			}
			
			$results[] = array(
				'id' => $product->get_id(),
				'name' => $product_name,
				'sku' => $product_sku,
				'formatted_name' => $product->get_formatted_name(),
			);
		}

		wp_send_json_success( array( 'products' => $results ) );
	}

	/**
	 * Find WooCommerce product by SKU
	 *
	 * @return void
	 */
	public function find_product_by_sku(): void {
		check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
		}

		$sku = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';

		if ( empty( $sku ) ) {
			wp_send_json_error( array( 'message' => __( 'SKU is required.', 'wc-amazon-sync' ) ) );
		}

		// Normalize SKU: trim, remove spaces, try different variants
		$sku_original = $sku;
		$sku_normalized = trim( $sku );
		
		// Generate multiple SKU variants for matching
		$sku_variants = array(
			$sku_normalized,                                    // Original trimmed
			str_replace( ' ', '', $sku_normalized ),            // Remove all spaces
			preg_replace( '/\s+/', '', $sku_normalized ),       // Remove all whitespace (tabs, spaces, etc.)
			ltrim( $sku_normalized, '0' ),                      // Remove leading zeros
			trim( $sku_normalized, '0' ),                       // Remove leading and trailing zeros
			str_replace( array( ' ', '-', '_', '.', '/' ), '', $sku_normalized ), // Remove spaces, dashes, underscores, dots, slashes
			preg_replace( '/[^0-9a-zA-Z]/', '', $sku_normalized ), // Remove all non-alphanumeric
		);

		// Also try with leading zeros if SKU is numeric
		if ( is_numeric( $sku_normalized ) ) {
			// Try with leading zeros (pad to 8 digits)
			$sku_variants[] = str_pad( $sku_normalized, 8, '0', STR_PAD_LEFT );
			// Try with leading zeros (pad to 10 digits)
			$sku_variants[] = str_pad( $sku_normalized, 10, '0', STR_PAD_LEFT );
		}

		// Remove duplicates and empty values
		$sku_variants = array_unique( $sku_variants );
		$sku_variants = array_filter( $sku_variants, function( $v ) {
			return ! empty( $v );
		} );

		$product_id = null;
		
		// Try each variant with wc_get_product_id_by_sku
		foreach ( $sku_variants as $sku_variant ) {
			if ( empty( $sku_variant ) ) {
				continue;
			}
			
			$product_id = wc_get_product_id_by_sku( $sku_variant );
			
			if ( $product_id ) {
				\WCAmazonSync\Logger\Logger::instance()->debug( sprintf( 'Found product by SKU variant: "%s" (original: "%s")', $sku_variant, $sku_original ) );
				break;
			}
		}

		// If still not found, try case-insensitive search via all products in database
		if ( ! $product_id ) {
			global $wpdb;
			
			// Get all products with SKU
			$product_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value != '' LIMIT %d",
					10000 // Limit to 10000 products for performance
				)
			);
			
			foreach ( $product_ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}
				
				$product_sku = $product->get_sku() ?: '';
				if ( empty( $product_sku ) ) {
					continue;
				}
				
				$product_sku_normalized = trim( $product_sku );
				
				// Try exact match (case-insensitive)
				if ( strcasecmp( $product_sku_normalized, $sku_normalized ) === 0 ) {
					$product_id = $pid;
					\WCAmazonSync\Logger\Logger::instance()->debug( sprintf( 'Found product by case-insensitive SKU match: "%s" (original: "%s")', $product_sku, $sku_original ) );
					break;
				}
				
				// Try without spaces (case-insensitive)
				if ( strcasecmp( str_replace( ' ', '', $product_sku_normalized ), str_replace( ' ', '', $sku_normalized ) ) === 0 ) {
					$product_id = $pid;
					\WCAmazonSync\Logger\Logger::instance()->debug( sprintf( 'Found product by SKU without spaces: "%s" (original: "%s")', $product_sku, $sku_original ) );
					break;
				}
				
				// Try without any non-alphanumeric (case-insensitive)
				$product_sku_clean = preg_replace( '/[^0-9a-zA-Z]/', '', $product_sku_normalized );
				$sku_clean = preg_replace( '/[^0-9a-zA-Z]/', '', $sku_normalized );
				if ( ! empty( $product_sku_clean ) && ! empty( $sku_clean ) && strcasecmp( $product_sku_clean, $sku_clean ) === 0 ) {
					$product_id = $pid;
					\WCAmazonSync\Logger\Logger::instance()->debug( sprintf( 'Found product by cleaned SKU: "%s" (original: "%s")', $product_sku, $sku_original ) );
					break;
				}
				
				// Try with leading zeros removed
				if ( is_numeric( $product_sku_normalized ) && is_numeric( $sku_normalized ) ) {
					$product_sku_no_zeros = ltrim( $product_sku_normalized, '0' );
					$sku_no_zeros = ltrim( $sku_normalized, '0' );
					if ( $product_sku_no_zeros === $sku_no_zeros && ! empty( $product_sku_no_zeros ) && ! empty( $sku_no_zeros ) ) {
						$product_id = $pid;
						\WCAmazonSync\Logger\Logger::instance()->debug( sprintf( 'Found product by SKU without leading zeros: "%s" (original: "%s")', $product_sku, $sku_original ) );
						break;
					}
				}
				
				// Try partial match (SKU contains or is contained in product SKU)
				// This helps with cases like "35110500P" vs "35110500" or "K72200" vs "72200"
				$product_sku_lower = strtolower( $product_sku_normalized );
				$sku_lower = strtolower( $sku_normalized );
				
				// If one SKU is a prefix of another (and difference is only suffix like "P", "s", "k", etc.)
				if ( strlen( $product_sku_lower ) > 0 && strlen( $sku_lower ) > 0 ) {
					// Check if SKU is contained in product SKU or vice versa
					if ( strpos( $product_sku_lower, $sku_lower ) === 0 || strpos( $sku_lower, $product_sku_lower ) === 0 ) {
						// Get the difference
						$diff = '';
						if ( strlen( $product_sku_lower ) > strlen( $sku_lower ) ) {
							$diff = substr( $product_sku_lower, strlen( $sku_lower ) );
						} else {
							$diff = substr( $sku_lower, strlen( $product_sku_lower ) );
						}
						
						// If difference is only 1-2 characters (likely a suffix like "P", "s", "k", "hk")
						if ( strlen( $diff ) <= 2 && preg_match( '/^[a-z]*$/', $diff ) ) {
							$product_id = $pid;
							\WCAmazonSync\Logger\Logger::instance()->debug( sprintf( 'Found product by SKU partial match: "%s" matches "%s" (original: "%s", diff: "%s")', $product_sku, $sku_normalized, $sku_original, $diff ) );
							break;
						}
					}
				}
				
				// Try removing common suffixes (P, p, s, k, hk, etc.) from both and compare
				$product_sku_no_suffix = preg_replace( '/[a-z]+$/i', '', $product_sku_normalized );
				$sku_no_suffix = preg_replace( '/[a-z]+$/i', '', $sku_normalized );
				if ( ! empty( $product_sku_no_suffix ) && ! empty( $sku_no_suffix ) && strcasecmp( $product_sku_no_suffix, $sku_no_suffix ) === 0 ) {
					$product_id = $pid;
					\WCAmazonSync\Logger\Logger::instance()->debug( sprintf( 'Found product by SKU without suffix: "%s" matches "%s" (original: "%s")', $product_sku, $sku_normalized, $sku_original ) );
					break;
				}
			}
		}

		if ( ! $product_id ) {
			\WCAmazonSync\Logger\Logger::instance()->debug( sprintf( 'Product not found by SKU: "%s"', $sku_original ) );
			wp_send_json_success( array( 'found' => false ) );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_send_json_success( array( 'found' => false ) );
		}

		wp_send_json_success(
			array(
				'found' => true,
				'product' => array(
					'id' => $product->get_id(),
					'name' => $product->get_name(),
					'sku' => $product->get_sku() ?: '',
					'formatted_name' => $product->get_formatted_name(),
				),
			)
		);
	}

	/**
	 * Save product mappings
	 *
	 * @return void
	 */
	public function save_mappings(): void {
		// Security check
		check_ajax_referer( 'wc_amazon_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-amazon-sync' ) ) );
			return;
		}

		// Get mappings from POST
		$mappings_data = array();
		
		if ( isset( $_POST['mappings_json'] ) && ! empty( $_POST['mappings_json'] ) ) {
			$mappings_json = wp_unslash( $_POST['mappings_json'] );
			$mappings_data = json_decode( $mappings_json, true );
			
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $mappings_data ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid mappings data format.', 'wc-amazon-sync' ) ) );
				return;
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'No mappings provided.', 'wc-amazon-sync' ) ) );
			return;
		}

		if ( empty( $mappings_data ) ) {
			wp_send_json_error( array( 'message' => __( 'No mappings provided.', 'wc-amazon-sync' ) ) );
			return;
		}

		$mapping_repo = new ProductMappingRepository();
		$results = array(
			'success' => 0,
			'error' => 0,
			'errors' => array(),
		);

		// Process each mapping
		foreach ( $mappings_data as $mapping_data ) {
			$wc_product_id = isset( $mapping_data['wc_product_id'] ) ? absint( $mapping_data['wc_product_id'] ) : 0;
			$amazon_asin = isset( $mapping_data['amazon_asin'] ) ? sanitize_text_field( $mapping_data['amazon_asin'] ) : '';
			$mapping_type = isset( $mapping_data['mapping_type'] ) ? sanitize_text_field( $mapping_data['mapping_type'] ) : 'sku';

			if ( ! $wc_product_id || ! $amazon_asin ) {
				\WCAmazonSync\Logger::instance()->debug( 'Save mappings: Skipping - invalid data. WC ID: ' . $wc_product_id . ', ASIN: ' . $amazon_asin );
				$results['error']++;
				continue;
			}

			// Check if product exists
			$product = wc_get_product( $wc_product_id );
			if ( ! $product ) {
				\WCAmazonSync\Logger::instance()->debug( 'Save mappings: Product not found. WC ID: ' . $wc_product_id );
				$results['error']++;
				continue;
			}

			// Check if mapping already exists
			$existing_mapping = $mapping_repo->get_by_wc_product_id( $wc_product_id );
			if ( ! $existing_mapping ) {
				$existing_mapping = $mapping_repo->get_by_asin( $amazon_asin );
			}
			
			if ( $existing_mapping ) {
				// Update existing mapping
				global $wpdb;
				$table_name = $mapping_repo->get_table_name();
				
				$updated = $wpdb->update(
					$table_name,
					array(
						'wc_product_id' => $wc_product_id,
						'amazon_asin' => $amazon_asin,
						'mapping_type' => $mapping_type,
						'sync_status' => 'pending',
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => $existing_mapping->id ),
					array( '%d', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				
				if ( false !== $updated ) {
					$results['success']++;
					\WCAmazonSync\Logger::instance()->debug( 'Save mappings: Updated mapping ID ' . $existing_mapping->id . ' for WC ID ' . $wc_product_id . ', ASIN ' . $amazon_asin );
				} else {
					$results['error']++;
					\WCAmazonSync\Logger::instance()->error( 'Save mappings: Failed to update mapping ID ' . $existing_mapping->id . '. DB Error: ' . $wpdb->last_error );
				}
			} else {
				// Create new mapping
				$mapping_id = $mapping_repo->create( $wc_product_id, $amazon_asin, $mapping_type );
				if ( $mapping_id ) {
					$results['success']++;
					\WCAmazonSync\Logger::instance()->debug( 'Save mappings: Created mapping ID ' . $mapping_id . ' for WC ID ' . $wc_product_id . ', ASIN ' . $amazon_asin );
				} else {
					$results['error']++;
					\WCAmazonSync\Logger::instance()->error( 'Save mappings: Failed to create mapping for WC ID ' . $wc_product_id . ', ASIN ' . $amazon_asin );
				}
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %1$d: Success count, %2$d: Error count */
					__( 'Mappings saved: %1$d succeeded, %2$d failed.', 'wc-amazon-sync' ),
					$results['success'],
					$results['error']
				),
				'results' => $results,
			)
		);
	}
}