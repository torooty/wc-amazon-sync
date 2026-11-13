<?php
/**
 * Amazon Order Sync Service
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Services;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Amazon\AmazonApiClient;
use WCAmazonSync\Logger\AmazonLogger;

/**
 * Fetches orders from Amazon SP-API and stores them locally.
 */
class AmazonOrderSyncService {

	/**
	 * @var AmazonApiClient
	 */
	private $api_client;

	/**
	 * @var AmazonLogger
	 */
	private $logger;

	/**
	 * Constructor
	 *
	 * @param AmazonApiClient|null $api_client API client.
	 */
	public function __construct( ?AmazonApiClient $api_client = null ) {
		$this->api_client = $api_client ?? new AmazonApiClient();
		$this->logger     = new AmazonLogger();
	}

	/**
	 * Sync orders from Amazon into local table.
	 *
	 * @param string|null $since ISO8601 datetime (CreatedAfter). Default: 30 days ago.
	 * @return array{ synced: int, failed: int, message: string }|WP_Error
	 */
	public function sync( $since = null ) {
		global $wpdb;

		$api = get_option( 'wc_amazon_sync_api', array() );
		if ( ! is_array( $api ) || empty( $api['marketplace_id'] ) ) {
			return new \WP_Error( 'missing_marketplace', __( 'Marketplace ID is not configured.', 'wc-amazon-sync' ) );
		}

		delete_transient( 'wc_amazon_sync_access_token' );

		$created_after = $since ? AmazonApiClient::format_iso8601_z( $since ) : AmazonApiClient::format_iso8601_z( strtotime( '-30 days' ) );
		$this->logger->info(
			'Amazon orders sync started',
			AmazonLogger::SCOPE_ORDERS,
			array( 'created_after' => $created_after )
		);

		$args = array(
			'CreatedAfter' => $created_after,
		);

		$orders = $this->api_client->fetch_all_orders( $args );
		if ( is_wp_error( $orders ) ) {
			$this->logger->error(
				'Amazon orders sync failed: ' . $orders->get_error_message(),
				AmazonLogger::SCOPE_ORDERS,
				array( 'error_code' => $orders->get_error_code() )
			);
			return $orders;
		}

		$table  = $wpdb->prefix . 'amazon_orders';
		$synced = 0;
		$failed = 0;
		$now    = current_time( 'mysql' );

		foreach ( $orders as $order ) {
			if ( ! is_array( $order ) ) {
				continue;
			}
			$result = $this->upsert_order( $order, $api['marketplace_id'], $now );
			if ( is_wp_error( $result ) ) {
				$failed++;
				continue;
			}
			$synced++;
		}

		update_option( 'wc_amazon_sync_orders_last_sync', $now );

		$message = sprintf(
			/* translators: 1: synced count, 2: failed count */
			__( 'Synced %1$d orders (%2$d failed).', 'wc-amazon-sync' ),
			$synced,
			$failed
		);

		$this->logger->info(
			'Amazon orders sync finished: ' . $message,
			AmazonLogger::SCOPE_ORDERS,
			array(
				'synced'  => $synced,
				'failed'  => $failed,
				'fetched' => count( $orders ),
			)
		);

		return array(
			'synced'  => $synced,
			'failed'  => $failed,
			'message' => $message,
		);
	}

	/**
	 * Upsert one order row.
	 *
	 * @param array  $order          Order payload from getOrders.
	 * @param string $marketplace_id Marketplace ID.
	 * @param string $now            MySQL datetime.
	 * @return true|WP_Error
	 */
	public function upsert_order( array $order, string $marketplace_id, string $now ) {
		global $wpdb;

		$amazon_order_id = isset( $order['AmazonOrderId'] ) ? sanitize_text_field( $order['AmazonOrderId'] ) : '';
		if ( $amazon_order_id === '' ) {
			return new \WP_Error( 'missing_order_id', __( 'Order has no AmazonOrderId.', 'wc-amazon-sync' ) );
		}

		$table = $wpdb->prefix . 'amazon_orders';
		$row   = $this->normalize_order( $order, $marketplace_id );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, order_status, is_new, customer_name, buyer_email, shipping_data FROM {$table} WHERE amazon_order_id = %s",
				$amazon_order_id
			),
			ARRAY_A
		);

		$has_report_shipping = $existing && ! empty( $existing['shipping_data'] );
		$customer_name       = $row['customer_name'];
		if ( $has_report_shipping && ! empty( $existing['customer_name'] ) ) {
			// Keep name from uploaded Seller Central report; API often has no PII.
			$customer_name = (string) $existing['customer_name'];
		}

		$data = array(
			'amazon_order_id'      => $amazon_order_id,
			'marketplace_id'       => $marketplace_id,
			'order_status'         => $row['order_status'],
			'fulfillment_channel'  => $row['fulfillment_channel'],
			'customer_name'        => $customer_name,
			'total_amount'         => $row['total_amount'],
			'currency'             => $row['currency'],
			'items_count'          => $row['items_count'],
			'order_date'           => $row['order_date'],
			'order_data'           => wp_json_encode( $order ),
			'updated_at'           => $now,
			'synced_at'            => $now,
		);

		if ( $existing ) {
			if ( isset( $existing['order_status'] ) && $existing['order_status'] !== $row['order_status'] ) {
				$data['is_new'] = in_array( $row['order_status'], array( 'Pending', 'Unshipped', 'PartiallyShipped' ), true ) ? 1 : 0;
			}
			// Never wipe report-enriched shipping_data / buyer_email on API sync.
			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
			return true;
		}

		$data['is_new']     = in_array( $row['order_status'], array( 'Pending', 'Unshipped', 'PartiallyShipped' ), true ) ? 1 : 0;
		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );

		return true;
	}

	/**
	 * Normalize Amazon order array to DB columns.
	 *
	 * @param array  $order Order from API.
	 * @param string $marketplace_id Marketplace ID.
	 * @return array
	 */
	private function normalize_order( array $order, string $marketplace_id ) {
		$status = isset( $order['OrderStatus'] ) ? sanitize_text_field( $order['OrderStatus'] ) : '';
		$total  = isset( $order['OrderTotal']['Amount'] ) ? (float) $order['OrderTotal']['Amount'] : 0.0;
		$currency = isset( $order['OrderTotal']['CurrencyCode'] ) ? sanitize_text_field( $order['OrderTotal']['CurrencyCode'] ) : 'EUR';

		$shipped   = isset( $order['NumberOfItemsShipped'] ) ? (int) $order['NumberOfItemsShipped'] : 0;
		$unshipped = isset( $order['NumberOfItemsUnshipped'] ) ? (int) $order['NumberOfItemsUnshipped'] : 0;

		$order_date = null;
		if ( ! empty( $order['PurchaseDate'] ) ) {
			$ts = strtotime( $order['PurchaseDate'] );
			if ( $ts ) {
				$order_date = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$customer_name = '';
		if ( ! empty( $order['BuyerInfo']['BuyerName'] ) ) {
			$customer_name = sanitize_text_field( $order['BuyerInfo']['BuyerName'] );
		} elseif ( ! empty( $order['ShippingAddress']['Name'] ) ) {
			$customer_name = sanitize_text_field( $order['ShippingAddress']['Name'] );
		}

		return array(
			'order_status'        => $status,
			'fulfillment_channel' => isset( $order['FulfillmentChannel'] ) ? sanitize_text_field( $order['FulfillmentChannel'] ) : '',
			'customer_name'       => $customer_name,
			'total_amount'        => $total,
			'currency'            => $currency,
			'items_count'         => $shipped + $unshipped,
			'order_date'          => $order_date,
		);
	}

	/**
	 * Get order detail with items (live API + update DB).
	 *
	 * @param string $amazon_order_id Amazon order ID.
	 * @return array|WP_Error
	 */
	public function get_order_detail( string $amazon_order_id ) {
		delete_transient( 'wc_amazon_sync_access_token' );

		$api_settings = get_option( 'wc_amazon_sync_api', array() );
		$marketplace  = is_array( $api_settings ) && ! empty( $api_settings['marketplace_id'] ) ? $api_settings['marketplace_id'] : '';

		$order_resp = $this->api_client->get_order_with_pii( $amazon_order_id );
		if ( is_wp_error( $order_resp ) ) {
			return $order_resp;
		}

		$order = isset( $order_resp['payload'] ) && is_array( $order_resp['payload'] ) ? $order_resp['payload'] : $order_resp;

		if ( empty( $order['ShippingAddress'] ) || empty( $order['ShippingAddress']['AddressLine1'] ) ) {
			$addr_resp = $this->api_client->get_order_address( $amazon_order_id );
			if ( ! is_wp_error( $addr_resp ) ) {
				$addr_payload = isset( $addr_resp['payload'] ) && is_array( $addr_resp['payload'] ) ? $addr_resp['payload'] : $addr_resp;
				if ( ! empty( $addr_payload['ShippingAddress'] ) ) {
					$order['ShippingAddress'] = $addr_payload['ShippingAddress'];
				}
			}
		}

		$items_resp = $this->api_client->get_order_items( $amazon_order_id );
		$items      = array();
		if ( ! is_wp_error( $items_resp ) ) {
			$payload = isset( $items_resp['payload'] ) && is_array( $items_resp['payload'] ) ? $items_resp['payload'] : $items_resp;
			$items   = isset( $payload['OrderItems'] ) && is_array( $payload['OrderItems'] ) ? $payload['OrderItems'] : array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'amazon_orders';
		$db_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT customer_name, buyer_email, shipping_data, tracking_number, shipping_carrier FROM {$table} WHERE amazon_order_id = %s LIMIT 1",
				$amazon_order_id
			),
			ARRAY_A
		);

		$shipping_data = array();
		if ( is_array( $db_row ) && ! empty( $db_row['shipping_data'] ) ) {
			$decoded = json_decode( (string) $db_row['shipping_data'], true );
			if ( is_array( $decoded ) ) {
				$shipping_data = $decoded;
			}
		}

		$display = AmazonOrderDisplayHelper::build( $order, $items, $marketplace, $shipping_data );

		if ( is_array( $db_row ) ) {
			if ( empty( $display['buyer_email'] ) && ! empty( $db_row['buyer_email'] ) ) {
				$display['buyer_email'] = (string) $db_row['buyer_email'];
			}
			if ( empty( $display['customer_name'] ) && ! empty( $db_row['customer_name'] ) ) {
				$display['customer_name'] = (string) $db_row['customer_name'];
			}
			$display['tracking_number']  = isset( $db_row['tracking_number'] ) ? (string) $db_row['tracking_number'] : '';
			$display['shipping_carrier'] = isset( $db_row['shipping_carrier'] ) ? (string) $db_row['shipping_carrier'] : '';
		} else {
			$display['tracking_number']  = '';
			$display['shipping_carrier'] = '';
		}

		$update = array(
			'order_data'   => wp_json_encode( $order ),
			'items_data'   => wp_json_encode( $items ),
			'currency'     => $display['currency'],
			'total_amount' => $display['totals']['total'],
			'updated_at'   => current_time( 'mysql' ),
		);
		// Prefer report-enriched name when present.
		if ( ! empty( $shipping_data ) && ! empty( $display['customer_name'] ) ) {
			$update['customer_name'] = $display['customer_name'];
		} elseif ( ! empty( $display['customer_name'] ) && ( ! is_array( $db_row ) || empty( $db_row['shipping_data'] ) ) ) {
			$update['customer_name'] = $display['customer_name'];
		}

		$wpdb->update( $table, $update, array( 'amazon_order_id' => $amazon_order_id ) );

		return array(
			'order'   => $order,
			'items'   => $items,
			'display' => $display,
		);
	}

	/**
	 * Save tracking number locally (confirmShipment to Amazon can be added later).
	 *
	 * @param string $amazon_order_id Amazon order ID.
	 * @param string $tracking_number Tracking number.
	 * @param string $carrier         Optional carrier code/name.
	 * @return true|WP_Error
	 */
	public function save_tracking( string $amazon_order_id, string $tracking_number, string $carrier = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_orders';

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE amazon_order_id = %s LIMIT 1", $amazon_order_id )
		);
		if ( ! $exists ) {
			return new \WP_Error( 'order_not_found', __( 'Order not found.', 'wc-amazon-sync' ) );
		}

		$wpdb->update(
			$table,
			array(
				'tracking_number'  => $tracking_number !== '' ? sanitize_text_field( $tracking_number ) : null,
				'shipping_carrier' => $carrier !== '' ? sanitize_text_field( $carrier ) : null,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'amazon_order_id' => $amazon_order_id )
		);

		$this->logger->info(
			sprintf( 'Tracking saved for %s', $amazon_order_id ),
			AmazonLogger::SCOPE_ORDERS,
			array(
				'amazon_order_id'  => $amazon_order_id,
				'tracking_number'  => $tracking_number,
				'shipping_carrier' => $carrier,
			)
		);

		return true;
	}
}
