<?php
/**
 * Product Mapper
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Product Mapper Class
 */
class ProductMapper {

	/**
	 * Map WooCommerce product to Amazon data structure
	 *
	 * @param \WC_Product $product WooCommerce product
	 * @return array Amazon product data
	 */
	public static function wc_to_amazon( \WC_Product $product ): array {
		$data = array(
			'sku' => $product->get_sku(),
			'title' => $product->get_name(),
			'description' => wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ),
			'price' => array(
				'amount' => (string) $product->get_price(),
				'currency' => get_woocommerce_currency(),
			),
			'quantity' => $product->get_stock_quantity() ?? 0,
			'images' => self::get_product_images( $product ),
			'attributes' => self::get_product_attributes( $product ),
		);

		return $data;
	}

	/**
	 * Map Amazon product data to WooCommerce product
	 *
	 * @param array $amazon_data Amazon product data
	 * @param \WC_Product|null $product Existing WooCommerce product (optional)
	 * @return array WooCommerce product data
	 */
	public static function amazon_to_wc( array $amazon_data, ?\WC_Product $product = null ): array {
		$wc_data = array(
			'name' => $amazon_data['title'] ?? $amazon_data['attributes']['item_name'][0] ?? '',
			'description' => $amazon_data['description'] ?? $amazon_data['attributes']['product_description'][0] ?? '',
			'short_description' => wp_trim_words( $amazon_data['description'] ?? '', 20 ),
			'sku' => $amazon_data['sku'] ?? '',
			'regular_price' => isset( $amazon_data['price']['amount'] ) ? (float) $amazon_data['price']['amount'] : 0,
			'manage_stock' => true,
			'stock_quantity' => isset( $amazon_data['quantity'] ) ? absint( $amazon_data['quantity'] ) : 0,
			'stock_status' => isset( $amazon_data['quantity'] ) && $amazon_data['quantity'] > 0 ? 'instock' : 'outofstock',
		);

		// Map images
		if ( ! empty( $amazon_data['images'] ) ) {
			$wc_data['image_id'] = self::import_image( $amazon_data['images'][0] ?? '', $product ? $product->get_id() : 0 );
		}

		// Map attributes
		if ( ! empty( $amazon_data['attributes'] ) ) {
			$wc_data['attributes'] = self::map_amazon_attributes( $amazon_data['attributes'] );
		}

		return $wc_data;
	}

	/**
	 * Get product images
	 *
	 * @param \WC_Product $product WooCommerce product
	 * @return array Image URLs
	 */
	private static function get_product_images( \WC_Product $product ): array {
		$images = array();

		// Main image
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$image_url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $image_url ) {
				$images[] = $image_url;
			}
		}

		// Gallery images
		$gallery_ids = $product->get_gallery_image_ids();
		foreach ( $gallery_ids as $gallery_id ) {
			$image_url = wp_get_attachment_image_url( $gallery_id, 'full' );
			if ( $image_url ) {
				$images[] = $image_url;
			}
		}

		return $images;
	}

	/**
	 * Get product attributes
	 *
	 * @param \WC_Product $product WooCommerce product
	 * @return array Product attributes
	 */
	private static function get_product_attributes( \WC_Product $product ): array {
		$attributes = array();

		// Get product attributes
		$product_attributes = $product->get_attributes();
		foreach ( $product_attributes as $attribute_name => $attribute ) {
			if ( $attribute->is_taxonomy() ) {
				$terms = wp_get_post_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
				$attributes[ $attribute_name ] = $terms;
			} else {
				$attributes[ $attribute_name ] = $attribute->get_options();
			}
		}

		return $attributes;
	}

	/**
	 * Map Amazon attributes to WooCommerce
	 *
	 * @param array $amazon_attributes Amazon attributes
	 * @return array WooCommerce attributes
	 */
	private static function map_amazon_attributes( array $amazon_attributes ): array {
		$wc_attributes = array();

		// Simple mapping - can be extended
		$attribute_map = array(
			'brand' => 'pa_brand',
			'color' => 'pa_color',
			'size' => 'pa_size',
		);

		foreach ( $amazon_attributes as $key => $value ) {
			$wc_key = $attribute_map[ $key ] ?? 'pa_' . sanitize_title( $key );
			$wc_attributes[ $wc_key ] = is_array( $value ) ? $value : array( $value );
		}

		return $wc_attributes;
	}

	/**
	 * Import image from URL
	 *
	 * @param string $image_url Image URL
	 * @param int    $product_id Product ID (optional)
	 * @return int|false Attachment ID or false
	 */
	private static function import_image( string $image_url, int $product_id = 0 ): int|false {
		if ( empty( $image_url ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $image_url );

		if ( is_wp_error( $tmp ) ) {
			return false;
		}

		$file_array = array(
			'name' => basename( $image_url ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $product_id );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return false;
		}

		return $attachment_id;
	}
}

