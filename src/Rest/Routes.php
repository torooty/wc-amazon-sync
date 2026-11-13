<?php
/**
 * REST API Routes
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * REST Routes Class
 */
class Routes {

	/**
	 * Instance
	 *
	 * @var Routes|null
	 */
	private static ?Routes $instance = null;

	/**
	 * Get instance
	 *
	 * @return Routes
	 */
	public static function instance(): Routes {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'wc-amazon-sync/v1',
			'/test-connection',
			array(
				'methods' => 'POST',
				'callback' => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'wc-amazon-sync/v1',
			'/import-products',
			array(
				'methods' => 'POST',
				'callback' => array( $this, 'import_products' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'wc-amazon-sync/v1',
			'/sync-product',
			array(
				'methods' => 'POST',
				'callback' => array( $this, 'sync_product' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check permission
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Test connection endpoint
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_connection( \WP_REST_Request $request ) {
		$api_client = new \WCAmazonSync\Amazon\AmazonApiClient();
		$result = $api_client->test_connection();

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'connection_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Import products endpoint
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_products( \WP_REST_Request $request ) {
		$importer = new \WCAmazonSync\Amazon\ProductImporter();
		$args = $request->get_json_params();
		$products = $importer->import_products( $args ?? array() );

		if ( is_wp_error( $products ) ) {
			return new \WP_Error( 'import_failed', $products->get_error_message(), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( array( 'products' => $products ), 200 );
	}

	/**
	 * Sync product endpoint
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync_product( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$product_id = isset( $params['product_id'] ) ? absint( $params['product_id'] ) : 0;

		if ( ! $product_id ) {
			return new \WP_Error( 'invalid_product_id', __( 'Invalid product ID.', 'wc-amazon-sync' ), array( 'status' => 400 ) );
		}

		$sync_service = new \WCAmazonSync\Amazon\InventorySyncService();
		$result = $sync_service->sync( array( $product_id ) );

		return new \WP_REST_Response( $result, 200 );
	}
}

