<?php
/**
 * Builds Listings Items API payload and validation for exporting a WooCommerce product to Amazon.
 * Uses Product Type Definitions API to determine required attributes per product type.
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Amazon Export Payload Builder
 */
class AmazonExportPayloadBuilder {

	/**
	 * @var AmazonCategoryMappingService
	 */
	private $category_mapping;

	/**
	 * Constructor
	 *
	 * @param AmazonCategoryMappingService|null $category_mapping Optional.
	 */
	public function __construct( AmazonCategoryMappingService $category_mapping = null ) {
		$this->category_mapping = $category_mapping ?: new AmazonCategoryMappingService();
	}

	/**
	 * Map Amazon attribute name to value from WC product and collected data.
	 *
	 * @param string       $attr_name   Amazon attribute name (e.g. item_name, brand, list_price).
	 * @param array        $value_map   Associative array: title, price_value, currency, stock_qty, ean, merchant_shipping_group, brand, etc.
	 * @param \WC_Product  $product     WooCommerce product.
	 * @return string|array|null Human-readable value for preview, or null if not mapped.
	 */
	private function get_attribute_value_for_preview( $attr_name, array $value_map, $product ) {
		switch ( $attr_name ) {
			case 'item_name':
				return isset( $value_map['title'] ) ? $value_map['title'] : null;
			case 'brand':
				return isset( $value_map['brand'] ) ? $value_map['brand'] : null;
			case 'list_price':
				return isset( $value_map['price_value'] ) && isset( $value_map['currency'] )
					? number_format( (float) $value_map['price_value'], 2, ',', ' ' ) . ' ' . $value_map['currency']
					: null;
			case 'fulfillment_availability':
				return isset( $value_map['stock_qty'] ) ? (string) $value_map['stock_qty'] : null;
			case 'condition_type':
				return 'new_new';
			case 'merchant_shipping_group':
				return isset( $value_map['merchant_shipping_group'] ) ? $value_map['merchant_shipping_group'] : null;
			case 'external_product_identifier':
			case 'ean':
				return isset( $value_map['ean'] ) ? $value_map['ean'] : null;
			case 'purchasable_offer':
				return isset( $value_map['price_value'] ) ? number_format( (float) $value_map['price_value'], 2, ',', ' ' ) . ' ' . ( $value_map['currency'] ?? 'EUR' ) : null;
			default:
				return null;
		}
	}

	/**
	 * Build preview data and putListingsItem payload for a WC product.
	 *
	 * @param int   $wc_product_id WooCommerce product ID.
	 * @param array $api_settings  Optional. get_option( 'wc_amazon_sync_api' ). If empty, loaded internally.
	 * @return array{ success: bool, preview?: array, payload?: array, warnings?: string[], errors?: string[], error?: string }
	 */
	public function build( $wc_product_id, $api_settings = array() ) {
		$wc_product_id = (int) $wc_product_id;
		if ( $wc_product_id <= 0 ) {
			return array( 'success' => false, 'errors' => array( __( 'Invalid product ID.', 'wc-amazon-sync' ) ) );
		}

		if ( empty( $api_settings ) || ! is_array( $api_settings ) ) {
			$api_settings = get_option( 'wc_amazon_sync_api', array() );
		}
		$marketplace_id = isset( $api_settings['marketplace_id'] ) ? trim( (string) $api_settings['marketplace_id'] ) : '';
		if ( $marketplace_id === '' ) {
			return array( 'success' => false, 'errors' => array( __( 'Marketplace ID is not configured. Set it in Amazon Sync settings.', 'wc-amazon-sync' ) ) );
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return array( 'success' => false, 'errors' => array( __( 'WooCommerce is not available.', 'wc-amazon-sync' ) ) );
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product || ! is_object( $product ) ) {
			return array( 'success' => false, 'errors' => array( __( 'Product not found.', 'wc-amazon-sync' ) ) );
		}

		$warnings = array();
		$errors   = array();

		$sku = $product->get_sku();
		if ( $sku === null || trim( (string) $sku ) === '' ) {
			$sku = 'wc_' . $wc_product_id;
			$warnings[] = __( 'Product has no SKU; export will use generated SKU.', 'wc-amazon-sync' );
		}
		$sku = trim( (string) $sku );

		$title = $product->get_name();
		if ( $title === null || trim( (string) $title ) === '' ) {
			$errors[] = __( 'Product title is required for Amazon.', 'wc-amazon-sync' );
		}
		$title = trim( (string) $title );

		$price = $product->get_regular_price();
		if ( $price === null || $price === '' ) {
			$price = $product->get_price();
		}
		$price_value = is_numeric( $price ) ? (float) $price : 0.0;
		if ( $price_value <= 0 ) {
			$warnings[] = __( 'Product price is missing or zero; listing may be rejected by Amazon.', 'wc-amazon-sync' );
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		$stock_qty = $product->managing_stock() ? $product->get_stock_quantity() : null;
		if ( $stock_qty === null ) {
			$stock_qty = $product->get_stock_status() === 'instock' ? 999 : 0;
		}
		$stock_qty = max( 0, (int) $stock_qty );

		$ean = $this->get_product_ean( $product );
		if ( $ean === '' ) {
			$warnings[] = __( 'EAN/GTIN is not set; some categories require it.', 'wc-amazon-sync' );
		}

		$term_ids = wc_get_product_term_ids( $wc_product_id, 'product_cat' );
		$amazon_category_id = null;
		$woo_category_name = '';
		foreach ( $term_ids as $tid ) {
			$mapped = $this->category_mapping->get_default_mapping( $tid );
			if ( $mapped ) {
				$amazon_category_id = $mapped;
				$t = get_term( $tid, 'product_cat' );
				if ( $t && ! is_wp_error( $t ) ) {
					$woo_category_name = $t->name;
				}
				break;
			}
		}
		if ( $amazon_category_id === null || $amazon_category_id === '' ) {
			$errors[] = __( 'No Amazon category mapping for this product\'s WooCommerce category. Map categories in Amazon Sync → Categories.', 'wc-amazon-sync' );
		}

		$merchant_shipping_group = get_option( 'wc_amazon_sync_merchant_shipping_group', '' );
		if ( $merchant_shipping_group === '' ) {
			$warnings[] = __( 'Merchant shipping group is not set in Sync settings; Amazon may require it.', 'wc-amazon-sync' );
		}

		$brand = $this->get_product_brand( $product );
		$value_map = array(
			'title'                   => $title,
			'price_value'             => $price_value,
			'currency'                => $currency,
			'stock_qty'                => $stock_qty,
			'ean'                     => $ean,
			'merchant_shipping_group' => $merchant_shipping_group,
			'brand'                   => $brand,
		);

		$product_type_used = 'PRODUCT';
		$required_attributes_preview = array();
		$optional_attributes_preview = array();
		$schema_error = '';

		$seller_id = isset( $api_settings['seller_id'] ) ? trim( (string) $api_settings['seller_id'] ) : '';
		$issue_locale = isset( $api_settings['issue_locale'] ) && $api_settings['issue_locale'] !== '' ? trim( (string) $api_settings['issue_locale'] ) : 'de_DE';
		if ( $seller_id !== '' ) {
			$client = new \WCAmazonSync\Api\ReportsClient( $api_settings );
			// Use LISTING_OFFER_ONLY to get required attributes for the offer we actually send.
			$schema_result = $client->get_product_type_schema( $seller_id, $marketplace_id, $product_type_used, 'LISTING_OFFER_ONLY', $issue_locale );
			if ( ! empty( $schema_result['success'] ) && ! empty( $schema_result['schema'] ) ) {
				$parsed = $client->parse_listing_attributes_from_schema( $schema_result['schema'] );
				foreach ( $parsed['required'] as $req ) {
					$value = $this->get_attribute_value_for_preview( $req['name'], $value_map, $product );
					$filled = $value !== null && $value !== '';
					$required_attributes_preview[] = array(
						'name'   => $req['name'],
						'label'  => $req['label'],
						'value'  => $filled ? ( is_string( $value ) ? $value : wp_json_encode( $value ) ) : '—',
						'status' => $filled ? 'filled' : 'missing',
					);
					if ( ! $filled ) {
						$errors[] = sprintf(
							/* translators: 1: attribute label, 2: attribute name */
							__( 'Required for Amazon (product type %1$s): "%2$s" is missing.', 'wc-amazon-sync' ),
							$product_type_used,
							$req['label'] ?: $req['name']
						);
					}
				}
				foreach ( $parsed['optional'] as $opt ) {
					$value = $this->get_attribute_value_for_preview( $opt['name'], $value_map, $product );
					$filled = $value !== null && $value !== '';
					$optional_attributes_preview[] = array(
						'name'   => $opt['name'],
						'label'  => $opt['label'],
						'value'  => $filled ? ( is_string( $value ) ? $value : wp_json_encode( $value ) ) : '—',
						'status' => $filled ? 'filled' : 'missing',
					);
				}
			} else {
				$schema_error = isset( $schema_result['error'] ) ? $schema_result['error'] : __( 'Could not load product type schema from Amazon.', 'wc-amazon-sync' );
				$warnings[] = $schema_error;
			}
		} else {
			$schema_error = __( 'Seller ID not configured; required attributes for this product type could not be loaded.', 'wc-amazon-sync' );
		}

		if ( ! empty( $errors ) ) {
			$preview = $this->preview_data( $product, $sku, $title, $price_value, $currency, $stock_qty, $ean, $woo_category_name, $amazon_category_id );
			$preview['merchant_shipping_group'] = $merchant_shipping_group ?: '—';
			$preview['product_type']             = $product_type_used;
			$preview['required_attributes']      = $required_attributes_preview;
			$preview['optional_attributes']      = $optional_attributes_preview;
			$preview['schema_error']             = $schema_error;
			return array(
				'success'  => false,
				'errors'   => $errors,
				'warnings' => $warnings,
				'preview'  => $preview,
			);
		}

		$attributes = $this->build_attributes( $marketplace_id, $title, $price_value, $currency, $stock_qty, $ean, $amazon_category_id, $merchant_shipping_group, $product );
		$payload = array(
			'productType'  => $product_type_used,
			'requirements' => 'LISTING_OFFER_ONLY',
			'attributes'   => $attributes,
		);

		$preview = $this->preview_data( $product, $sku, $title, $price_value, $currency, $stock_qty, $ean, $woo_category_name, $amazon_category_id );
		$preview['merchant_shipping_group'] = $merchant_shipping_group ?: '—';
		$preview['product_type']             = $product_type_used;
		$preview['required_attributes']      = $required_attributes_preview;
		$preview['optional_attributes']      = $optional_attributes_preview;
		$preview['schema_error']              = $schema_error;
		$preview['payload_json']              = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		return array(
			'success'  => true,
			'preview'  => $preview,
			'payload'  => $payload,
			'warnings' => $warnings,
			'sku'      => $sku,
		);
	}

	/**
	 * Get brand from product (attribute or meta).
	 *
	 * @param \WC_Product $product
	 * @return string
	 */
	private function get_product_brand( $product ) {
		$id = $product->get_id();
		$brand = get_post_meta( $id, '_brand', true );
		if ( is_string( $brand ) && trim( $brand ) !== '' ) {
			return trim( $brand );
		}
		$brand = get_post_meta( $id, 'brand', true );
		if ( is_string( $brand ) && trim( $brand ) !== '' ) {
			return trim( $brand );
		}
		$brand = $product->get_attribute( 'brand' );
		if ( is_string( $brand ) && trim( $brand ) !== '' ) {
			return trim( $brand );
		}
		$brand = $product->get_attribute( 'pa_brand' );
		if ( is_string( $brand ) && trim( $brand ) !== '' ) {
			return trim( $brand );
		}
		return '';
	}

	/**
	 * Get EAN/GTIN from product (meta or attribute).
	 *
	 * @param \WC_Product $product
	 * @return string
	 */
	private function get_product_ean( $product ) {
		$id = $product->get_id();
		$ean = get_post_meta( $id, '_ean', true );
		if ( is_string( $ean ) && trim( $ean ) !== '' ) {
			return trim( preg_replace( '/\D/', '', $ean ) );
		}
		$ean = get_post_meta( $id, 'ean', true );
		if ( is_string( $ean ) && trim( $ean ) !== '' ) {
			return trim( preg_replace( '/\D/', '', $ean ) );
		}
		$gtin = get_post_meta( $id, '_gtin', true );
		if ( is_string( $gtin ) && trim( $gtin ) !== '' ) {
			return trim( preg_replace( '/\D/', '', $gtin ) );
		}
		foreach ( array( 'ean', 'gtin', 'upc' ) as $key ) {
			$val = $product->get_attribute( $key );
			if ( is_string( $val ) && trim( $val ) !== '' ) {
				return trim( preg_replace( '/\D/', '', $val ) );
			}
		}
		return '';
	}

	/**
	 * Build attributes array for putListingsItem (LISTING_OFFER_ONLY).
	 *
	 * @param string      $marketplace_id
	 * @param string      $title
	 * @param float       $price_value
	 * @param string      $currency
	 * @param int         $stock_qty
	 * @param string      $ean
	 * @param string|null $amazon_category_id
	 * @param string      $merchant_shipping_group
	 * @param \WC_Product $product
	 * @return array
	 */
	private function build_attributes( $marketplace_id, $title, $price_value, $currency, $stock_qty, $ean, $amazon_category_id, $merchant_shipping_group, $product ) {
		$attrs = array();

		$attrs['condition_type'] = array(
			array(
				'value'          => 'new_new',
				'marketplace_id'  => $marketplace_id,
			),
		);

		$attrs['fulfillment_availability'] = array(
			array(
				'fulfillment_channel_code' => 'DEFAULT',
				'quantity'                => $stock_qty,
				'marketplace_id'          => $marketplace_id,
			),
		);

		$attrs['list_price'] = array(
			array(
				'value'          => array(
					'value'    => $price_value,
					'currency' => $currency,
				),
				'marketplace_id' => $marketplace_id,
			),
		);

		if ( $merchant_shipping_group !== '' ) {
			$attrs['merchant_shipping_group'] = array(
				array(
					'value'          => $merchant_shipping_group,
					'marketplace_id' => $marketplace_id,
				),
			);
		}

		if ( $ean !== '' ) {
			$attrs['external_product_identifier'] = array(
				array(
					'value'          => $ean,
					'type'           => 'EAN',
					'marketplace_id' => $marketplace_id,
				),
			);
		}

		return $attrs;
	}

	/**
	 * Human-readable preview data for the modal.
	 *
	 * @param \WC_Product $product
	 * @param string      $sku
	 * @param string      $title
	 * @param float       $price_value
	 * @param string      $currency
	 * @param int         $stock_qty
	 * @param string      $ean
	 * @param string      $woo_category_name
	 * @param string|null $amazon_category_id
	 * @return array
	 */
	private function preview_data( $product, $sku, $title, $price_value, $currency, $stock_qty, $ean, $woo_category_name, $amazon_category_id ) {
		// Use currency code (e.g. EUR) to avoid HTML entities like &euro; in JSON/preview.
		return array(
			'wc_product_id'    => $product->get_id(),
			'sku'               => $sku,
			'title'             => $title,
			'price'             => $price_value,
			'price_formatted'   => number_format( $price_value, 2, ',', ' ' ) . ' ' . $currency,
			'currency'          => $currency,
			'quantity'          => $stock_qty,
			'ean'               => $ean ?: '—',
			'woo_category'      => $woo_category_name ?: '—',
			'amazon_category_id'=> $amazon_category_id ?: '—',
		);
	}
}
