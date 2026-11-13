<?php
/**
 * Amazon API Client Interface
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Amazon;

defined( 'ABSPATH' ) || exit;

/**
 * Amazon API Client Interface
 */
interface AmazonApiClientInterface {

	/**
	 * Test API connection
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function test_connection();

	/**
	 * Get catalog items
	 *
	 * @param array $args Query arguments
	 * @return array|WP_Error
	 */
	public function get_catalog_items( array $args = array() );

	/**
	 * Get inventory summary
	 *
	 * @param array $skus Product SKUs (optional)
	 * @return array|WP_Error
	 */
	public function get_inventory_summary( array $skus = array() );

	/**
	 * Update inventory
	 *
	 * @param array $items Inventory items to update
	 * @return array|WP_Error
	 */
	public function update_inventory( array $items );

	/**
	 * Create or update listing
	 *
	 * @param string $asin Amazon ASIN
	 * @param array  $data Product data
	 * @return array|WP_Error
	 */
	public function create_or_update_listing( string $asin, array $data );

	/**
	 * Get orders
	 *
	 * @param array $args Query arguments
	 * @return array|WP_Error
	 */
	public function get_orders( array $args = array() );

	/**
	 * Get order by ID
	 *
	 * @param string $order_id Order ID
	 * @return array|WP_Error
	 */
	public function get_order( string $order_id );
}

