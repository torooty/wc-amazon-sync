<?php
/**
 * Amazon Orders List Table
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

defined( 'ABSPATH' ) || exit;

/**
 * Read-only orders list for Amazon SP-API synced orders.
 */
class Amazon_Orders_List_Table extends \WP_List_Table {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => __( 'Order', 'wc-amazon-sync' ),
			'plural'   => __( 'Orders', 'wc-amazon-sync' ),
			'ajax'     => false,
			'screen'   => isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : null,
		) );
	}

	/**
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'order';
	}

	/**
	 * @return array
	 */
	public function get_columns() {
		return array(
			'order'    => __( 'Order', 'wc-amazon-sync' ),
			'customer' => __( 'Customer', 'wc-amazon-sync' ),
			'date'     => __( 'Date', 'wc-amazon-sync' ),
			'status'   => __( 'Status', 'wc-amazon-sync' ),
			'total'    => __( 'Total', 'wc-amazon-sync' ),
			'items'    => __( 'Items', 'wc-amazon-sync' ),
			'channel'  => __( 'Fulfillment', 'wc-amazon-sync' ),
			'tracking' => __( 'Tracking', 'wc-amazon-sync' ),
		);
	}

	/**
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'order'  => array( 'amazon_order_id', false ),
			'date'   => array( 'order_date', true ),
			'status' => array( 'order_status', false ),
			'total'  => array( 'total_amount', false ),
		);
	}

	/**
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No orders found. Click "Sync orders" to fetch orders from Amazon.', 'wc-amazon-sync' );
	}

	/**
	 * @param object $item Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_order( $item ) {
		$id = $item->amazon_order_id ?? '';
		$output = '<strong>#' . esc_html( $id ) . '</strong>';
		$output .= ' <a href="#" class="amazon-order-view" data-order-id="' . esc_attr( $id ) . '" title="' . esc_attr__( 'View details', 'wc-amazon-sync' ) . '">';
		$output .= '<span class="dashicons dashicons-visibility" style="color:#2271b1;font-size:18px;vertical-align:middle;"></span></a>';

		if ( ! empty( $item->is_new ) ) {
			$output .= ' <span class="amazon-new-badge">' . esc_html__( 'New', 'wc-amazon-sync' ) . '</span>';
		}

		return $output;
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_customer( $item ) {
		$name = ! empty( $item->customer_name ) ? $item->customer_name : '—';
		$output = '<strong>' . esc_html( $name ) . '</strong>';

		$shipping = $this->get_shipping_data( $item );
		$line1    = ! empty( $shipping['line1'] ) ? (string) $shipping['line1'] : '';
		$city     = trim( ( ! empty( $shipping['postal_code'] ) ? $shipping['postal_code'] . ' ' : '' ) . ( $shipping['city'] ?? '' ) );
		$country  = ! empty( $shipping['country_code'] ) ? strtoupper( (string) $shipping['country_code'] ) : '';

		if ( $country === '' && ! empty( $item->order_data ) ) {
			$decoded = json_decode( $item->order_data, true );
			if ( is_array( $decoded ) ) {
				if ( ! empty( $decoded['ShippingAddress']['CountryCode'] ) ) {
					$country = strtoupper( (string) $decoded['ShippingAddress']['CountryCode'] );
				} elseif ( ! empty( $decoded['SalesChannel'] ) && preg_match( '/\.([a-z]{2})$/i', (string) $decoded['SalesChannel'], $m ) ) {
					$country = strtoupper( $m[1] );
				}
			}
		}

		if ( $line1 !== '' ) {
			$output .= '<br><span class="description">' . esc_html( $line1 ) . '</span>';
		}
		if ( $city !== '' || $country !== '' ) {
			$output .= '<br><span class="description">' . esc_html( trim( $city . ( $country ? ', ' . $country : '' ) ) ) . '</span>';
		} elseif ( $country ) {
			$output .= '<br><span class="description">' . esc_html( $country ) . '</span>';
		}

		if ( ! empty( $item->buyer_email ) ) {
			$output .= '<br><a href="mailto:' . esc_attr( $item->buyer_email ) . '" class="description">' . esc_html( $item->buyer_email ) . '</a>';
		}

		return $output;
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_tracking( $item ) {
		$order_id = $item->amazon_order_id ?? '';
		$tracking = isset( $item->tracking_number ) ? trim( (string) $item->tracking_number ) : '';
		$carrier  = isset( $item->shipping_carrier ) ? trim( (string) $item->shipping_carrier ) : '';

		$html  = '<div class="amazon-tracking-cell" data-order-id="' . esc_attr( $order_id ) . '">';
		$html .= '<input type="text" class="amazon-tracking-input regular-text" value="' . esc_attr( $tracking ) . '" placeholder="' . esc_attr__( 'Tracking number', 'wc-amazon-sync' ) . '" />';
		$html .= '<input type="text" class="amazon-carrier-input" value="' . esc_attr( $carrier ) . '" placeholder="' . esc_attr__( 'Carrier', 'wc-amazon-sync' ) . '" />';
		$html .= '<button type="button" class="button button-small amazon-tracking-save-btn">' . esc_html__( 'Save', 'wc-amazon-sync' ) . '</button>';
		$html .= '<span class="amazon-tracking-status"></span>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * @param object $item Row.
	 * @return array
	 */
	private function get_shipping_data( $item ) {
		if ( empty( $item->shipping_data ) ) {
			return array();
		}
		$decoded = json_decode( (string) $item->shipping_data, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_date( $item ) {
		if ( empty( $item->order_date ) ) {
			return '—';
		}
		return esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->order_date ) ) );
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status = $item->order_status ?? '';
		$class  = 'status-default';
		$map    = array(
			'Pending'           => 'status-pending',
			'Unshipped'         => 'status-open',
			'PartiallyShipped'  => 'status-partial',
			'Shipped'           => 'status-shipped',
			'Canceled'          => 'status-cancelled',
			'Unfulfillable'     => 'status-cancelled',
		);
		if ( isset( $map[ $status ] ) ) {
			$class = $map[ $status ];
		}
		return '<mark class="order-status ' . esc_attr( $class ) . '"><span>' . esc_html( $status ?: '—' ) . '</span></mark>';
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_total( $item ) {
		if ( $item->total_amount === null || $item->total_amount === '' ) {
			return '—';
		}
		$currency = ! empty( $item->currency ) ? $item->currency : 'EUR';
		return esc_html( number_format_i18n( (float) $item->total_amount, 2 ) . ' ' . $currency );
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_items( $item ) {
		return esc_html( (string) ( $item->items_count ?? '—' ) );
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_channel( $item ) {
		$channel = $item->fulfillment_channel ?? '';
		if ( $channel === 'AFN' ) {
			return esc_html__( 'FBA', 'wc-amazon-sync' );
		}
		if ( $channel === 'MFN' ) {
			return esc_html__( 'Merchant', 'wc-amazon-sync' );
		}
		return esc_html( $channel ?: '—' );
	}

	/**
	 * Status filter dropdown.
	 *
	 * @param string $which top|bottom
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$current = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) : '';
		$statuses = array(
			''                 => __( 'All statuses', 'wc-amazon-sync' ),
			'Pending'          => 'Pending',
			'Unshipped'        => 'Unshipped',
			'PartiallyShipped' => 'PartiallyShipped',
			'Shipped'          => 'Shipped',
			'Canceled'         => 'Canceled',
		);
		echo '<div class="alignleft actions">';
		echo '<label for="status_filter" class="screen-reader-text">' . esc_html__( 'Filter by status', 'wc-amazon-sync' ) . '</label>';
		echo '<select name="status_filter" id="status_filter">';
		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		submit_button( __( 'Filter', 'wc-amazon-sync' ), 'button', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$table = $wpdb->prefix . 'amazon_orders';
		$per_page = 20;
		$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $_GET['s'] ) ) {
			$search    = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) . '%';
			$where[]   = '(amazon_order_id LIKE %s OR customer_name LIKE %s OR buyer_email LIKE %s OR tracking_number LIKE %s)';
			$params[]  = $search;
			$params[]  = $search;
			$params[]  = $search;
			$params[]  = $search;
		}

		if ( ! empty( $_GET['status_filter'] ) ) {
			$where[]  = 'order_status = %s';
			$params[] = sanitize_text_field( wp_unslash( $_GET['status_filter'] ) );
		}

		$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'order_date';
		$allowed = array( 'amazon_order_id', 'order_date', 'order_status', 'total_amount' );
		if ( ! in_array( $orderby, $allowed, true ) ) {
			$orderby = 'order_date';
		}
		$order = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$data_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
			$query_params = array_merge( $params, array( $per_page, $offset ) );
			$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $query_params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
			$rows  = $wpdb->get_results( $wpdb->prepare( $data_sql, $per_page, $offset ) );
		}

		$this->items = is_array( $rows ) ? $rows : array();
		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );
	}
}
