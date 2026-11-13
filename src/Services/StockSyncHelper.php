<?php
/**
 * Stock Sync Helper – only linked products get quantity from Woo + "Maximale Lagerbestandsmenge"
 *
 * Same logic as Kaufland: normalize stock for display and future sync.
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Services;

defined( 'ABSPATH' ) || exit;

/**
 * StockSyncHelper Class
 */
class StockSyncHelper {

	/**
	 * Normalize stock for Amazon (same rule as Kaufland)
	 *
	 * - max_stock 0 → return actual quantity (no limit)
	 * - max_stock > 0 → return min( quantity, max_stock )
	 *
	 * @param int $qty      Stock quantity from WooCommerce
	 * @param int $max_stock Maximum stock from settings (0 = no limit)
	 * @return int
	 */
	public static function normalize_stock( $qty, $max_stock = null ) {
		$qty = max( 0, (int) $qty );
		if ( null === $max_stock ) {
			$max_stock = (int) get_option( 'wc_amazon_sync_max_stock_quantity', 500 );
		} else {
			$max_stock = (int) $max_stock;
		}
		if ( 0 === $max_stock ) {
			return $qty;
		}
		return min( $qty, $max_stock );
	}

	/**
	 * Get WooCommerce stock quantity for a product
	 *
	 * @param int $wc_product_id WooCommerce product or variation ID
	 * @return int
	 */
	public static function get_woo_stock( $wc_product_id ) {
		$product = $wc_product_id ? wc_get_product( $wc_product_id ) : null;
		if ( ! $product ) {
			return 0;
		}
		if ( $product->managing_stock() ) {
			return max( 0, (int) $product->get_stock_quantity() );
		}
		// Not managing stock: treat as 0 or "in stock" – common to send 0 or 1
		return 0;
	}

	/**
	 * Get quantity to use for a linked product (Woo stock + max setting)
	 *
	 * Used in Import tab display and (when implemented) for sync.
	 *
	 * @param int $wc_product_id WooCommerce product ID
	 * @param int|null $max_stock Optional override; null = use option
	 * @return array{ 'woo_stock': int, 'display_qty': int, 'max_stock': int }
	 */
	public static function get_quantity_for_linked( $wc_product_id, $max_stock = null ) {
		$woo_stock = self::get_woo_stock( $wc_product_id );
		if ( null === $max_stock ) {
			$max_stock = (int) get_option( 'wc_amazon_sync_max_stock_quantity', 500 );
		} else {
			$max_stock = (int) $max_stock;
		}
		$display_qty = self::normalize_stock( $woo_stock, $max_stock );
		return array(
			'woo_stock'  => $woo_stock,
			'display_qty' => $display_qty,
			'max_stock'  => $max_stock,
		);
	}
}
