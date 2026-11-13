<?php
/**
 * Product Mapping Repository
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Product Mapping Repository Class
 */
class ProductMappingRepository {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private string $table_name;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'wc_amazon_product_map';
	}

	/**
	 * Get table name
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		return $this->table_name;
	}

	/**
	 * Create mapping
	 *
	 * @param int    $wc_product_id WooCommerce product ID
	 * @param string $amazon_asin Amazon ASIN
	 * @param string $mapping_type Mapping type (sku, asin, ean, custom_field)
	 * @return int|false Mapping ID on success, false on failure
	 */
	public function create( int $wc_product_id, string $amazon_asin, string $mapping_type = 'sku' ) {
		global $wpdb;

		$result = $wpdb->insert(
			$this->table_name,
			array(
				'wc_product_id' => $wc_product_id,
				'amazon_asin' => sanitize_text_field( $amazon_asin ),
				'mapping_type' => sanitize_text_field( $mapping_type ),
				'sync_status' => 'pending',
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Get mapping by WooCommerce product ID
	 *
	 * @param int $wc_product_id WooCommerce product ID
	 * @return object|null Mapping object or null
	 */
	public function get_by_wc_product_id( int $wc_product_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE wc_product_id = %d LIMIT 1",
				$wc_product_id
			)
		);
	}

	/**
	 * Get mapping by Amazon ASIN
	 *
	 * @param string $amazon_asin Amazon ASIN
	 * @return object|null Mapping object or null
	 */
	public function get_by_asin( string $amazon_asin ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE amazon_asin = %s LIMIT 1",
				$amazon_asin
			)
		);
	}

	/**
	 * Update mapping sync status
	 *
	 * @param int    $mapping_id Mapping ID
	 * @param string $status Sync status
	 * @param string $error Error message (optional)
	 * @return bool
	 */
	public function update_sync_status( int $mapping_id, string $status, string $error = '' ): bool {
		global $wpdb;

		$data = array(
			'sync_status' => sanitize_text_field( $status ),
			'last_synced_at' => current_time( 'mysql' ),
		);

		if ( ! empty( $error ) ) {
			$data['sync_error'] = sanitize_textarea_field( $error );
		}

		return false !== $wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => $mapping_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete mapping
	 *
	 * @param int $mapping_id Mapping ID
	 * @return bool
	 */
	public function delete( int $mapping_id ): bool {
		global $wpdb;

		return false !== $wpdb->delete(
			$this->table_name,
			array( 'id' => $mapping_id ),
			array( '%d' )
		);
	}

	/**
	 * Delete mapping by WooCommerce product ID
	 *
	 * @param int $wc_product_id WooCommerce product ID
	 * @return bool
	 */
	public function delete_by_wc_product_id( int $wc_product_id ): bool {
		global $wpdb;

		return false !== $wpdb->delete(
			$this->table_name,
			array( 'wc_product_id' => $wc_product_id ),
			array( '%d' )
		);
	}

	/**
	 * Get all mappings
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public function get_all( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'limit' => 100,
			'offset' => 0,
			'status' => '',
			'orderby' => 'created_at',
			'order' => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$where_values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[] = 'sync_status = %s';
			$where_values[] = $args['status'];
		}

		$where_clause = implode( ' AND ', $where );

		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		if ( ! $orderby ) {
			$orderby = 'created_at DESC';
		}

		$limit = absint( $args['limit'] );
		$offset = absint( $args['offset'] );

		$query = "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";

		if ( ! empty( $where_values ) ) {
			$query = $wpdb->prepare( $query, array_merge( $where_values, array( $limit, $offset ) ) );
		} else {
			$query = $wpdb->prepare( $query, $limit, $offset );
		}

		return $wpdb->get_results( $query );
	}

	/**
	 * Get mappings that need sync
	 *
	 * @param int $limit Limit
	 * @return array
	 */
	public function get_pending_sync( int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE sync_status = 'pending' ORDER BY updated_at ASC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Delete all mappings
	 *
	 * @return int|false Number of rows deleted, false on error
	 */
	public function delete_all() {
		global $wpdb;

		return $wpdb->query( "DELETE FROM {$this->table_name}" );
	}
}

