<?php
/**
 * Amazon Category Mapping Service — WooCommerce category → Amazon category (browse node).
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Category mapping storage (same pattern as Kaufland).
 */
class AmazonCategoryMappingService {

	/**
	 * Get default Amazon category for a WooCommerce category.
	 *
	 * @param int $woo_category_id WooCommerce term_id (product_cat).
	 * @return string|null Amazon category ID or null.
	 */
	public function get_default_mapping( $woo_category_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_category_mappings';
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT default_amazon_category_id FROM {$table} WHERE woo_category_id = %d LIMIT 1",
			(int) $woo_category_id
		) );
		return $value !== null ? (string) $value : null;
	}

	/**
	 * Set default mapping.
	 *
	 * @param int    $woo_category_id WooCommerce category ID.
	 * @param string $amazon_category_id Amazon browse node / category ID.
	 * @return bool Success.
	 */
	public function set_default_mapping( $woo_category_id, $amazon_category_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_category_mappings';
		$now   = current_time( 'mysql' );
		$data  = array(
			'woo_category_id'           => (int) $woo_category_id,
			'default_amazon_category_id' => sanitize_text_field( $amazon_category_id ),
			'updated_at'                => $now,
		);
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE woo_category_id = %d LIMIT 1",
			(int) $woo_category_id
		) );
		if ( $existing ) {
			return false !== $wpdb->update(
				$table,
				$data,
				array( 'woo_category_id' => (int) $woo_category_id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
		}
		$data['created_at'] = $now;
		return false !== $wpdb->insert( $table, $data, array( '%d', '%s', '%s', '%s' ) );
	}

	/**
	 * Delete mapping for a WooCommerce category.
	 *
	 * @param int $woo_category_id WooCommerce category ID.
	 * @return bool Success.
	 */
	public function delete_mapping( $woo_category_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_category_mappings';
		return false !== $wpdb->delete(
			$table,
			array( 'woo_category_id' => (int) $woo_category_id ),
			array( '%d' )
		);
	}

	/**
	 * Get all mappings.
	 *
	 * @return array Rows with woo_category_id, default_amazon_category_id, etc.
	 */
	public function get_all_mappings() {
		global $wpdb;
		$table   = $wpdb->prefix . 'amazon_category_mappings';
		$results = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY woo_category_id ASC", ARRAY_A );
		return is_array( $results ) ? $results : array();
	}
}
