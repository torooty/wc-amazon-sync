<?php
/**
 * Amazon Orders admin page
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Database\Migration;
use WCAmazonSync\Services\AmazonOrderReportImportService;
use WCAmazonSync\Services\AmazonOrderSyncService;

/**
 * Orders page — sync, enrich from report, tracking field.
 */
class OrdersPage {

	/**
	 * @var OrdersPage|null
	 */
	private static $ajax_instance = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public static function init_ajax_handlers() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		if ( null === self::$ajax_instance ) {
			self::$ajax_instance = new self();
		}
		add_action( 'wp_ajax_amazon_sync_orders', array( self::$ajax_instance, 'ajax_sync_orders' ) );
		add_action( 'wp_ajax_amazon_get_order_detail', array( self::$ajax_instance, 'ajax_get_order_detail' ) );
		add_action( 'wp_ajax_amazon_upload_order_report', array( self::$ajax_instance, 'ajax_upload_order_report' ) );
		add_action( 'wp_ajax_amazon_save_tracking', array( self::$ajax_instance, 'ajax_save_tracking' ) );
	}

	/**
	 * Seller Central order reports URL.
	 *
	 * @return string
	 */
	public static function get_order_reports_url(): string {
		return 'https://sellercentral-europe.amazon.com/order-reports-and-feeds/reports';
	}

	/**
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( ! is_string( $hook ) || strpos( $hook, 'wc-amazon-sync' ) === false || strpos( $hook, 'orders' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wc-amazon-sync-orders-admin',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/css/orders-admin.css',
			array(),
			WC_AMAZON_SYNC_VERSION
		);

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script(
			'wc-amazon-sync-orders-admin',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/js/orders-admin.js',
			array( 'jquery' ),
			WC_AMAZON_SYNC_VERSION,
			true
		);

		wp_localize_script(
			'wc-amazon-sync-orders-admin',
			'wcAmazonSyncOrders',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'sync_nonce' => wp_create_nonce( 'amazon_sync_orders' ),
				'detail_nonce' => wp_create_nonce( 'amazon_get_order_detail' ),
				'upload_nonce' => wp_create_nonce( 'amazon_upload_order_report' ),
				'tracking_nonce' => wp_create_nonce( 'amazon_save_tracking' ),
				'i18n_syncing' => __( 'Syncing orders from Amazon…', 'wc-amazon-sync' ),
				'i18n_sync_ok' => __( 'Orders synced.', 'wc-amazon-sync' ),
				'i18n_sync_fail' => __( 'Sync failed: %s', 'wc-amazon-sync' ),
				'i18n_uploading' => __( 'Uploading report…', 'wc-amazon-sync' ),
				'i18n_upload_ok' => __( 'Report imported.', 'wc-amazon-sync' ),
				'i18n_upload_fail' => __( 'Upload failed: %s', 'wc-amazon-sync' ),
				'i18n_choose_file' => __( 'Please choose a report file first.', 'wc-amazon-sync' ),
				'i18n_saving' => __( 'Saving…', 'wc-amazon-sync' ),
				'i18n_saved' => __( 'Saved.', 'wc-amazon-sync' ),
				'i18n_save_fail' => __( 'Save failed: %s', 'wc-amazon-sync' ),
				'i18n_tracking_required' => __( 'Tracking number is required.', 'wc-amazon-sync' ),
				'i18n_loading' => __( 'Loading…', 'wc-amazon-sync' ),
				'i18n_order_details' => __( 'Order Details', 'wc-amazon-sync' ),
				'i18n_status' => __( 'Status', 'wc-amazon-sync' ),
				'i18n_created' => __( 'Created', 'wc-amazon-sync' ),
				'i18n_storefront' => __( 'Storefront', 'wc-amazon-sync' ),
				'i18n_fulfillment' => __( 'Fulfillment', 'wc-amazon-sync' ),
				'i18n_billing' => __( 'Billing Address', 'wc-amazon-sync' ),
				'i18n_shipping' => __( 'Shipping Address', 'wc-amazon-sync' ),
				'i18n_phone' => __( 'Phone', 'wc-amazon-sync' ),
				'i18n_tracking' => __( 'Tracking number', 'wc-amazon-sync' ),
				'i18n_carrier' => __( 'Carrier', 'wc-amazon-sync' ),
				'i18n_save_tracking' => __( 'Save tracking', 'wc-amazon-sync' ),
				'i18n_from_report' => __( 'Address from uploaded report', 'wc-amazon-sync' ),
				'i18n_items' => __( 'Order Items', 'wc-amazon-sync' ),
				'i18n_product' => __( 'Product', 'wc-amazon-sync' ),
				'i18n_sku' => __( 'SKU', 'wc-amazon-sync' ),
				'i18n_qty' => __( 'Quantity', 'wc-amazon-sync' ),
				'i18n_price' => __( 'Price', 'wc-amazon-sync' ),
				'i18n_total' => __( 'Total', 'wc-amazon-sync' ),
				'i18n_weight' => __( 'Weight', 'wc-amazon-sync' ),
				'i18n_subtotal' => __( 'Subtotal', 'wc-amazon-sync' ),
				'i18n_shipping_cost' => __( 'Shipping', 'wc-amazon-sync' ),
				'i18n_grand_total' => __( 'Total', 'wc-amazon-sync' ),
				'i18n_no_items' => __( 'No order items loaded.', 'wc-amazon-sync' ),
				'i18n_pii_missing' => __( 'No shipping address yet. Download the Seller Central order report and upload it above.', 'wc-amazon-sync' ),
			)
		);
	}

	/**
	 * Render page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wc-amazon-sync' ) );
		}

		Migration::ensure_orders_columns();

		$list_table = new Amazon_Orders_List_Table();
		$list_table->prepare_items();

		global $wpdb;
		$table = $wpdb->prefix . 'amazon_orders';
		$new_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_new = 1" );
		$last_sync = get_option( 'wc_amazon_sync_orders_last_sync', '' );
		$reports_url = self::get_order_reports_url();
		?>
		<div class="wrap wc-amazon-sync-orders">
			<h1><?php esc_html_e( 'Amazon Orders', 'wc-amazon-sync' ); ?></h1>
			<p><?php esc_html_e( 'Orders from Amazon SP-API (Orders v0). Sync fetches the last 30 days. Upload a Seller Central order report to fill in customer addresses.', 'wc-amazon-sync' ); ?></p>

			<div class="amazon-orders-toolbar">
				<button type="button" class="button button-primary" id="amazon-orders-sync-btn">
					<?php esc_html_e( 'Sync orders', 'wc-amazon-sync' ); ?>
				</button>
				<a class="button" href="<?php echo esc_url( $reports_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Open Amazon order reports', 'wc-amazon-sync' ); ?>
				</a>
				<span id="amazon-orders-sync-status" class="amazon-orders-sync-status"></span>
				<?php if ( $last_sync ) : ?>
					<span class="amazon-orders-last-sync">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: formatted datetime */
								__( 'Last sync: %s', 'wc-amazon-sync' ),
								date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_sync ) )
							)
						);
						?>
					</span>
				<?php endif; ?>
				<?php if ( $new_count > 0 ) : ?>
					<span class="amazon-orders-new-count"><?php echo esc_html( sprintf( __( '%d new', 'wc-amazon-sync' ), $new_count ) ); ?></span>
				<?php endif; ?>
			</div>

			<div class="amazon-orders-report-box">
				<h2><?php esc_html_e( 'Customer data from report', 'wc-amazon-sync' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'In Seller Central open Bestellberichte → FBM, download the flat file, then upload it here. Addresses are matched by Amazon order ID and are not overwritten by the next API sync.', 'wc-amazon-sync' ); ?>
				</p>
				<div class="amazon-orders-report-upload">
					<input type="file" id="amazon-orders-report-file" accept=".txt,.csv,.tsv,text/plain,text/csv,text/tab-separated-values" />
					<button type="button" class="button" id="amazon-orders-report-upload-btn">
						<?php esc_html_e( 'Upload report', 'wc-amazon-sync' ); ?>
					</button>
					<span id="amazon-orders-report-status" class="amazon-orders-sync-status"></span>
				</div>
			</div>

			<form method="get">
				<input type="hidden" name="page" value="wc-amazon-sync-orders" />
				<?php $list_table->search_box( __( 'Search orders', 'wc-amazon-sync' ), 'amazon-order-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>

		<div id="amazon-order-modal" class="amazon-order-modal" style="display:none;">
			<div class="amazon-order-modal__backdrop"></div>
			<div class="amazon-order-modal__content">
				<button type="button" class="amazon-order-modal__close" aria-label="<?php esc_attr_e( 'Close', 'wc-amazon-sync' ); ?>">&times;</button>
				<div id="amazon-order-modal-body"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: sync orders from Amazon.
	 *
	 * @return void
	 */
	public function ajax_sync_orders() {
		check_ajax_referer( 'amazon_sync_orders', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}

		Migration::ensure_orders_columns();
		$service = new AmazonOrderSyncService();
		$result  = $service->sync();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: order detail modal.
	 *
	 * @return void
	 */
	public function ajax_get_order_detail() {
		check_ajax_referer( 'amazon_get_order_detail', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
		if ( $order_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Order ID is required.', 'wc-amazon-sync' ) ) );
		}

		Migration::ensure_orders_columns();

		global $wpdb;
		$table = $wpdb->prefix . 'amazon_orders';
		$wpdb->update( $table, array( 'is_new' => 0 ), array( 'amazon_order_id' => $order_id ) );

		$service = new AmazonOrderSyncService();
		$detail  = $service->get_order_detail( $order_id );

		if ( is_wp_error( $detail ) ) {
			wp_send_json_error( array( 'message' => $detail->get_error_message() ) );
		}

		wp_send_json_success( $detail );
	}

	/**
	 * AJAX: upload Seller Central order report and enrich addresses.
	 *
	 * @return void
	 */
	public function ajax_upload_order_report() {
		check_ajax_referer( 'amazon_upload_order_report', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}

		if ( empty( $_FILES['report_file'] ) || ! is_array( $_FILES['report_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'wc-amazon-sync' ) ) );
		}

		$file = $_FILES['report_file'];
		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload error.', 'wc-amazon-sync' ) ) );
		}

		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload.', 'wc-amazon-sync' ) ) );
		}

		if ( $size <= 0 || $size > 20 * MB_IN_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'File is empty or larger than 20 MB.', 'wc-amazon-sync' ) ) );
		}

		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'txt', 'csv', 'tsv', '' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Allowed formats: .txt, .csv, .tsv', 'wc-amazon-sync' ) ) );
		}

		Migration::ensure_orders_columns();

		$service = new AmazonOrderReportImportService();
		$result  = $service->import_file( $tmp, $name );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: save tracking number locally.
	 *
	 * @return void
	 */
	public function ajax_save_tracking() {
		check_ajax_referer( 'amazon_save_tracking', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
		$tracking = isset( $_POST['tracking_number'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['tracking_number'] ) ) ) : '';
		$carrier  = isset( $_POST['carrier'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) ) : '';

		if ( $order_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Order ID is required.', 'wc-amazon-sync' ) ) );
		}
		if ( $tracking === '' ) {
			wp_send_json_error( array( 'message' => __( 'Tracking number is required.', 'wc-amazon-sync' ) ) );
		}

		Migration::ensure_orders_columns();

		$service = new AmazonOrderSyncService();
		$result  = $service->save_tracking( $order_id, $tracking, $carrier );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'         => __( 'Tracking number saved.', 'wc-amazon-sync' ),
				'tracking_number' => $tracking,
				'carrier'         => $carrier,
			)
		);
	}
}
