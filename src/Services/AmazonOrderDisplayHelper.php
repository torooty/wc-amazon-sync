<?php
/**
 * Build normalized order detail payload for admin UI.
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Amazon order display helper.
 */
class AmazonOrderDisplayHelper {

	/**
	 * Marketplace ID → default country code.
	 *
	 * @var array<string, string>
	 */
	private static $marketplace_countries = array(
		'A1PA6795UKMFR9'  => 'DE',
		'A1RKKUPIHCS9HS'  => 'ES',
		'A13V1IB3VIYZZH'  => 'FR',
		'APJ6JRA9NG5V4'   => 'IT',
		'A1F83G8C2ARO7P'  => 'GB',
		'ATVPDKIKX0DER'   => 'US',
		'A2EUQ1WTGCTBG2'  => 'CA',
	);

	/**
	 * @param array       $order          Amazon order payload.
	 * @param array       $items          Amazon order items.
	 * @param string|null $marketplace_id Configured marketplace ID.
	 * @param array       $shipping_data  Optional enriched data from order report upload.
	 * @return array
	 */
	public static function build( array $order, array $items, ?string $marketplace_id = null, array $shipping_data = array() ) {
		$currency = self::detect_currency( $order, $items );
		$country  = self::detect_country_code( $order, $marketplace_id );

		$shipping = self::parse_address( $order['ShippingAddress'] ?? array() );
		$shipping = self::merge_report_shipping( $shipping, $shipping_data );
		if ( empty( $shipping['country_code'] ) && $country ) {
			$shipping['country_code'] = $country;
		}
		$shipping['country_name'] = self::country_name( $shipping['country_code'] ?: $country );

		$buyer_name = '';
		if ( ! empty( $shipping_data['recipient_name'] ) ) {
			$buyer_name = (string) $shipping_data['recipient_name'];
		} elseif ( ! empty( $shipping_data['buyer_name'] ) ) {
			$buyer_name = (string) $shipping_data['buyer_name'];
		} elseif ( ! empty( $order['BuyerInfo']['BuyerName'] ) ) {
			$buyer_name = (string) $order['BuyerInfo']['BuyerName'];
		} elseif ( ! empty( $shipping['name'] ) ) {
			$buyer_name = (string) $shipping['name'];
		}

		$buyer_email = '';
		if ( ! empty( $shipping_data['buyer_email'] ) ) {
			$buyer_email = (string) $shipping_data['buyer_email'];
		} elseif ( ! empty( $order['BuyerInfo']['BuyerEmail'] ) ) {
			$buyer_email = (string) $order['BuyerInfo']['BuyerEmail'];
		}

		$line_items = self::build_line_items( $items, $currency, $order['OrderStatus'] ?? '' );
		$totals     = self::build_totals( $order, $line_items, $currency );

		$phone = ! empty( $shipping_data['phone'] ) ? (string) $shipping_data['phone'] : '';
		if ( $phone !== '' ) {
			$shipping['phone'] = $phone;
		}

		return array(
			'order_id'       => $order['AmazonOrderId'] ?? '',
			'status'         => $order['OrderStatus'] ?? '',
			'created'        => $order['PurchaseDate'] ?? '',
			'sales_channel'  => $order['SalesChannel'] ?? '',
			'fulfillment'    => $order['FulfillmentChannel'] ?? '',
			'currency'       => $currency,
			'country_code'   => $shipping['country_code'] ?: $country,
			'country_name'   => self::country_name( $shipping['country_code'] ?: $country ),
			'customer_name'  => $buyer_name,
			'buyer_email'    => $buyer_email,
			'shipping_address' => $shipping,
			'totals'         => $totals,
			'line_items'     => $line_items,
			'pii_available'  => ! empty( $shipping['line1'] ) || ! empty( $buyer_name ),
			'address_from_report' => ! empty( $shipping_data['line1'] ) || ! empty( $shipping_data['recipient_name'] ),
		);
	}

	/**
	 * Overlay Seller Central report fields onto API address (report wins when present).
	 *
	 * @param array $shipping Parsed API address.
	 * @param array $report   Report shipping_data.
	 * @return array
	 */
	private static function merge_report_shipping( array $shipping, array $report ) {
		if ( empty( $report ) ) {
			return $shipping;
		}

		$name = $report['recipient_name'] ?? ( $report['buyer_name'] ?? '' );
		if ( $name !== '' ) {
			$shipping['name'] = (string) $name;
		}
		if ( ! empty( $report['line1'] ) ) {
			$shipping['line1'] = (string) $report['line1'];
		}
		if ( ! empty( $report['line2'] ) ) {
			$shipping['line2'] = (string) $report['line2'];
		} elseif ( ! empty( $report['line3'] ) && empty( $shipping['line2'] ) ) {
			$shipping['line2'] = (string) $report['line3'];
		}
		if ( ! empty( $report['city'] ) ) {
			$shipping['city'] = (string) $report['city'];
		}
		if ( ! empty( $report['state'] ) ) {
			$shipping['state'] = (string) $report['state'];
		}
		if ( ! empty( $report['postal_code'] ) ) {
			$shipping['postal_code'] = (string) $report['postal_code'];
		}
		if ( ! empty( $report['country_code'] ) ) {
			$shipping['country_code'] = strtoupper( (string) $report['country_code'] );
		}

		$lines = array_filter( array(
			$shipping['line1'] ?? '',
			$shipping['line2'] ?? '',
		) );
		$city_line = trim( (string) ( $shipping['postal_code'] ?? '' ) . ' ' . (string) ( $shipping['city'] ?? '' ) );
		$shipping['formatted'] = trim( implode( ', ', array_filter( array_merge( $lines, array( $city_line, self::country_name( $shipping['country_code'] ?? '' ) ) ) ) ) );

		return $shipping;
	}

	/**
	 * @param array $order Order.
	 * @param array $items Items.
	 * @return string
	 */
	private static function detect_currency( array $order, array $items ) {
		if ( ! empty( $order['OrderTotal']['CurrencyCode'] ) ) {
			return (string) $order['OrderTotal']['CurrencyCode'];
		}
		foreach ( $items as $item ) {
			if ( ! empty( $item['ItemPrice']['CurrencyCode'] ) ) {
				return (string) $item['ItemPrice']['CurrencyCode'];
			}
		}
		return 'EUR';
	}

	/**
	 * @param array       $order Order.
	 * @param string|null $marketplace_id Marketplace.
	 * @return string
	 */
	private static function detect_country_code( array $order, ?string $marketplace_id ) {
		if ( ! empty( $order['ShippingAddress']['CountryCode'] ) ) {
			return strtoupper( (string) $order['ShippingAddress']['CountryCode'] );
		}
		if ( ! empty( $order['SalesChannel'] ) && preg_match( '/\.([a-z]{2})$/i', (string) $order['SalesChannel'], $m ) ) {
			return strtoupper( $m[1] );
		}
		if ( $marketplace_id && isset( self::$marketplace_countries[ $marketplace_id ] ) ) {
			return self::$marketplace_countries[ $marketplace_id ];
		}
		return '';
	}

	/**
	 * @param string $code Country code.
	 * @return string
	 */
	private static function country_name( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( $code === '' ) {
			return '';
		}
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			if ( isset( $countries[ $code ] ) ) {
				return $countries[ $code ];
			}
		}
		return $code;
	}

	/**
	 * @param array $addr Amazon address.
	 * @return array
	 */
	private static function parse_address( array $addr ) {
		$lines = array();
		if ( ! empty( $addr['AddressLine1'] ) ) {
			$lines[] = (string) $addr['AddressLine1'];
		}
		if ( ! empty( $addr['AddressLine2'] ) ) {
			$lines[] = (string) $addr['AddressLine2'];
		}
		if ( ! empty( $addr['AddressLine3'] ) ) {
			$lines[] = (string) $addr['AddressLine3'];
		}

		$city_line = trim( (string) ( $addr['PostalCode'] ?? '' ) . ' ' . (string) ( $addr['City'] ?? '' ) );
		$country   = ! empty( $addr['CountryCode'] ) ? strtoupper( (string) $addr['CountryCode'] ) : '';

		return array(
			'name'         => (string) ( $addr['Name'] ?? '' ),
			'line1'        => (string) ( $addr['AddressLine1'] ?? '' ),
			'line2'        => (string) ( $addr['AddressLine2'] ?? '' ),
			'postal_code'  => (string) ( $addr['PostalCode'] ?? '' ),
			'city'         => (string) ( $addr['City'] ?? '' ),
			'state'        => (string) ( $addr['StateOrRegion'] ?? '' ),
			'country_code' => $country,
			'country_name' => self::country_name( $country ),
			'formatted'    => trim( implode( ', ', array_filter( array_merge( $lines, array( $city_line, self::country_name( $country ) ) ) ) ) ),
		);
	}

	/**
	 * @param array  $items    Order items.
	 * @param string $currency Currency.
	 * @param string $status   Order status.
	 * @return array
	 */
	private static function build_line_items( array $items, string $currency, string $status ) {
		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$qty       = isset( $item['QuantityOrdered'] ) ? max( 1, (int) $item['QuantityOrdered'] ) : 1;
			$line      = self::money_amount( $item['ItemPrice'] ?? array() );
			$unit      = $qty > 0 ? $line / $qty : $line;
			$item_ship = self::money_amount( $item['ShippingPrice'] ?? array() );
			$weight    = isset( $item['ItemWeight']['Value'] ) ? (float) $item['ItemWeight']['Value'] : null;
			$weight_u  = isset( $item['ItemWeight']['Unit'] ) ? (string) $item['ItemWeight']['Unit'] : 'kg';
			$total_w   = $weight !== null ? $weight * max( 1, $qty ) : null;

			$rows[] = array(
				'title'       => (string) ( $item['Title'] ?? '' ),
				'sku'         => (string) ( $item['SellerSKU'] ?? '' ),
				'asin'        => (string) ( $item['ASIN'] ?? '' ),
				'qty'         => $qty,
				'price'       => $unit,
				'line_total'  => $line,
				'currency'    => ! empty( $item['ItemPrice']['CurrencyCode'] ) ? (string) $item['ItemPrice']['CurrencyCode'] : $currency,
				'status'      => $status,
				'shipping'    => $item_ship,
				'weight'      => $total_w,
				'weight_unit' => $weight_u,
				'weight_label' => $total_w !== null ? self::format_weight( $total_w, $weight_u ) : '—',
			);
		}
		return $rows;
	}

	/**
	 * @param array       $order      Order.
	 * @param array       $line_items Line items.
	 * @param string      $currency   Currency.
	 * @return array
	 */
	private static function build_totals( array $order, array $line_items, string $currency ) {
		$subtotal = 0.0;
		$shipping = 0.0;
		foreach ( $line_items as $row ) {
			$subtotal += (float) $row['line_total'];
			$shipping += (float) ( $row['shipping'] ?? 0 );
		}

		if ( ! empty( $order['OrderTotal']['Amount'] ) ) {
			$total = (float) $order['OrderTotal']['Amount'];
			if ( isset( $order['OrderTotal']['CurrencyCode'] ) ) {
				$currency = (string) $order['OrderTotal']['CurrencyCode'];
			}
		} else {
			$total = $subtotal + $shipping;
		}

		if ( $shipping <= 0 && $total > $subtotal ) {
			$shipping = $total - $subtotal;
		}

		return array(
			'subtotal' => round( $subtotal, 2 ),
			'shipping' => round( $shipping, 2 ),
			'total'    => round( $total, 2 ),
			'currency' => $currency,
		);
	}

	/**
	 * @param array $money Amazon money object.
	 * @return float
	 */
	private static function money_amount( array $money ) {
		if ( isset( $money['Amount'] ) ) {
			return (float) $money['Amount'];
		}
		return 0.0;
	}

	/**
	 * @param float  $value Value.
	 * @param string $unit  Unit.
	 * @return string
	 */
	private static function format_weight( $value, $unit ) {
		$unit = strtolower( $unit );
		if ( $unit === 'grams' || $unit === 'gram' || $unit === 'g' ) {
			return number_format_i18n( $value, 0 ) . ' g';
		}
		return number_format_i18n( $value, 3 ) . ' ' . $unit;
	}
}
