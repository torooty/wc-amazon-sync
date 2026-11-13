<?php
/**
 * Products Page - list of imported Amazon products with filters and status (like Kaufland Produkte).
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Products Page Class
 */
class ProductsPage {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts and styles on Products page (same as Import tab for table actions).
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		$is_products_page = (
			( is_string( $hook ) && strpos( $hook, 'wc-amazon-sync-products' ) !== false ) ||
			( isset( $_GET['page'] ) && sanitize_text_field( wp_unslash( $_GET['page'] ) ) === 'wc-amazon-sync-products' )
		);

		if ( ! $is_products_page ) {
			return;
		}

		wp_enqueue_style(
			'wc-amazon-sync-import-tab',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/css/import-tab.css',
			array(),
			WC_AMAZON_SYNC_VERSION
		);
		wp_enqueue_script(
			'wc-amazon-sync-import',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/js/amazon-import.js',
			array( 'jquery' ),
			WC_AMAZON_SYNC_VERSION,
			true
		);
		wp_localize_script( 'wc-amazon-sync-import', 'wcAmazonSyncImport', array(
			'ajax_url'            => admin_url( 'admin-ajax.php' ),
			'import_nonce'        => wp_create_nonce( 'amazon_import_products' ),
			'clear_list_nonce'    => wp_create_nonce( 'amazon_clear_imported_products' ),
			'refresh_statuses_nonce' => wp_create_nonce( 'amazon_refresh_statuses' ),
			'clean_broken_links_nonce' => wp_create_nonce( 'amazon_clean_broken_links' ),
			'logs_list_nonce'     => wp_create_nonce( 'amazon_logs_list' ),
			'logs_clear_nonce'    => wp_create_nonce( 'amazon_logs_clear' ),
			'toggle_status_nonce' => wp_create_nonce( 'amazon_toggle_sync_disabled' ),
			'link_wc_nonce'       => wp_create_nonce( 'amazon_link_wc_product' ),
			'unlink_wc_nonce'     => wp_create_nonce( 'amazon_unlink_wc_product' ),
			'unlink_by_sku_nonce' => wp_create_nonce( 'amazon_unlink_by_sku' ),
			'search_wc_nonce'     => wp_create_nonce( 'amazon_search_wc_products' ),
			'export_preview_nonce' => wp_create_nonce( 'amazon_export_preview' ),
			'export_submit_nonce' => wp_create_nonce( 'amazon_export_submit' ),
			'i18n_importing'      => __( 'Importing…', 'wc-amazon-sync' ),
			'i18n_import_done'    => __( 'Import done. New: %d, Updated: %d, Skipped: %d', 'wc-amazon-sync' ),
			'i18n_clear_confirm'  => __( 'This will only clear the local list in WordPress (imported products table). Your listings on Amazon will NOT be deleted or changed. Continue?', 'wc-amazon-sync' ),
			'i18n_import_error'   => __( 'Import failed: %s', 'wc-amazon-sync' ),
			'i18n_link_method'    => __( 'Methode: %s', 'wc-amazon-sync' ),
			'i18n_unlink'         => __( 'Verknüpfung aufheben', 'wc-amazon-sync' ),
			'i18n_not_linked'     => __( 'Nicht verknüpft', 'wc-amazon-sync' ),
			'i18n_wc_product'     => __( 'WC-Produkt #%d', 'wc-amazon-sync' ),
			'i18n_deactivate'     => __( 'Deaktivieren', 'wc-amazon-sync' ),
			'i18n_activate'       => __( 'Aktivieren', 'wc-amazon-sync' ),
			'i18n_deactivated'    => __( 'Deaktiviert', 'wc-amazon-sync' ),
			'i18n_enter_sku'      => __( 'Please enter a SKU.', 'wc-amazon-sync' ),
			'i18n_export_preview_title' => __( 'Export to Amazon — Preview', 'wc-amazon-sync' ),
			'i18n_export_send'    => __( 'Send', 'wc-amazon-sync' ),
			'i18n_export_cancel'  => __( 'Cancel', 'wc-amazon-sync' ),
			'i18n_export_loading' => __( 'Loading…', 'wc-amazon-sync' ),
			'i18n_export_sending' => __( 'Sending…', 'wc-amazon-sync' ),
			'i18n_export_warnings' => __( 'Warnings', 'wc-amazon-sync' ),
			'i18n_export_errors'  => __( 'Errors', 'wc-amazon-sync' ),
			'i18n_export_data_sent' => __( 'Data to be sent to Amazon', 'wc-amazon-sync' ),
		) );
	}

	/**
	 * Render the Products page (Kaufland-style: same filters and functions).
	 * Filters: Suchen (Name, SKU, EAN), Woo Category, Amazon Status, Status (Export/Verknüpfung). Only products from mapped categories + unlinked.
	 *
	 * @return void
	 */
	public function render() {
		global $wpdb;
		$products_url = admin_url( 'admin.php?page=wc-amazon-sync-products' );
		$mappings_table = $wpdb->prefix . 'amazon_category_mappings';
		$terms_table    = $wpdb->prefix . 'terms';
		$mapped_categories = array();
		$mapped_cats = $wpdb->get_results(
			"SELECT m.woo_category_id, t.name FROM {$mappings_table} m " .
			"LEFT JOIN {$terms_table} t ON t.term_id = m.woo_category_id ORDER BY t.name ASC"
		);
		foreach ( (array) $mapped_cats as $cat ) {
			$mapped_categories[ (int) $cat->woo_category_id ] = $cat->name ? $cat->name : __( '(no name)', 'wc-amazon-sync' );
		}

		$search                 = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$category_filter        = isset( $_GET['category'] ) ? max( 0, (int) $_GET['category'] ) : 0;
		$filter_amazon_status   = isset( $_GET['filter_amazon_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_amazon_status'] ) ) : '';
		$filter_woo_status      = isset( $_GET['filter_woo_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_woo_status'] ) ) : '';

		$table = $wpdb->prefix . 'amazon_imported_products';
		$amazon_statuses = $wpdb->get_col( "SELECT DISTINCT listing_status FROM {$table} WHERE listing_status IS NOT NULL AND listing_status != '' ORDER BY listing_status ASC" );
		$amazon_statuses = is_array( $amazon_statuses ) ? $amazon_statuses : array();

		$unlinked_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE (link_status IS NULL OR link_status != 'linked' OR wc_product_id IS NULL)"
		);
		$import_tab_url = admin_url( 'admin.php?page=wc-amazon-sync&tab=import' );

		$clear_url = add_query_arg( array( 'page' => 'wc-amazon-sync-products', 'paged' => 1 ), admin_url( 'admin.php' ) );
		$has_filters = $search || $category_filter || $filter_amazon_status;
		?>
		<div class="wrap wc-amazon-sync-products wc-amazon-sync-import-tab">
			<h1><?php esc_html_e( 'Produkte', 'wc-amazon-sync' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Hier werden alle WooCommerce-Produkte aus Kategorien angezeigt, die auf der Seite Kategorien mit Amazon verknüpft sind. Bereits importierte und verknüpfte Artikel erscheinen mit Amazon-Daten. Von hier aus können Sie die Artikel kontrollieren und neue Artikel nach Amazon senden.', 'wc-amazon-sync' ); ?>
			</p>
			<?php if ( $unlinked_count > 0 ) : ?>
			<div class="notice notice-warning" style="margin: 15px 0; border-left-color: #dba617;">
				<p><strong><?php esc_html_e( 'Nicht verknüpfte Artikel', 'wc-amazon-sync' ); ?>:</strong>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: number of unlinked articles, 2: URL to Import tab */
							__( 'Im Import sind noch <strong>%1$d</strong> Artikel, die noch nicht mit WooCommerce verknüpft sind. Bitte zuerst im <a href="%2$s">Import</a> verknüpfen, damit es keine Verwechslung bei den Artikeln gibt.', 'wc-amazon-sync' ),
							$unlinked_count,
							esc_url( $import_tab_url )
						)
					);
					?>
				</p>
			</div>
			<?php else : ?>
			<div class="notice notice-info" style="margin: 15px 0;">
				<p><?php echo wp_kses_post( sprintf( __( 'Im <a href="%s">Import</a> sind derzeit keine nicht verknüpften Artikel.', 'wc-amazon-sync' ), esc_url( $import_tab_url ) ) ); ?></p>
			</div>
			<?php endif; ?>

			<div class="kaufland-export-filters" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
				<div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 15px;">
					<a href="<?php echo esc_url( $products_url ); ?>" class="button button-primary"><?php esc_html_e( 'Liste aktualisieren', 'wc-amazon-sync' ); ?></a>
					<button type="button" id="amazon-refresh-statuses-btn" class="button" title="<?php esc_attr_e( 'Update Status, Bestand and Preis from Amazon without re-importing', 'wc-amazon-sync' ); ?>">
						<?php esc_html_e( 'Status aktualisieren', 'wc-amazon-sync' ); ?>
					</button>
					<span id="amazon-import-result-msg" style="margin-left: 8px;"></span>
				</div>
				<form method="get" action="">
					<input type="hidden" name="page" value="wc-amazon-sync-products">
					<div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
						<div>
							<label for="amazon-products-search"><strong><?php esc_html_e( 'Suchen', 'wc-amazon-sync' ); ?>:</strong></label><br>
							<input type="text" id="amazon-products-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, SKU, EAN...', 'wc-amazon-sync' ); ?>" style="width: 250px;">
						</div>
						<?php if ( ! empty( $mapped_categories ) ) : ?>
						<div>
							<label for="amazon-products-category"><strong><?php esc_html_e( 'Woo Category', 'wc-amazon-sync' ); ?>:</strong></label><br>
							<select id="amazon-products-category" name="category" style="width: 200px;">
								<option value=""><?php esc_html_e( 'All categories', 'wc-amazon-sync' ); ?></option>
								<?php foreach ( $mapped_categories as $cat_id => $cat_name ) : ?>
									<option value="<?php echo esc_attr( $cat_id ); ?>" <?php selected( $category_filter, $cat_id ); ?>><?php echo esc_html( $cat_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php endif; ?>
						<div>
							<label for="amazon-products-amazon-status"><strong><?php esc_html_e( 'Amazon Status', 'wc-amazon-sync' ); ?>:</strong></label><br>
							<select id="amazon-products-amazon-status" name="filter_amazon_status" style="width: 200px;" onchange="this.form.submit();">
								<option value=""><?php esc_html_e( 'All', 'wc-amazon-sync' ); ?></option>
								<option value="__empty__" <?php selected( $filter_amazon_status, '__empty__' ); ?>><?php esc_html_e( '— Leer', 'wc-amazon-sync' ); ?></option>
								<?php foreach ( $amazon_statuses as $as ) : ?>
									<option value="<?php echo esc_attr( $as ); ?>" <?php selected( $filter_amazon_status, $as ); ?>><?php echo esc_html( $as ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wc-amazon-sync' ); ?></button>
							<?php if ( $has_filters ) : ?>
								<a href="<?php echo esc_url( $clear_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'wc-amazon-sync' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</form>
			</div>

			<?php
			ImportTabContent::render_products_list(
				$products_url,
				array( 'page' => 'wc-amazon-sync-products' ),
				true,
				true
			);
			?>

			<!-- Export to Amazon: preview modal -->
			<div id="wc-amazon-sync-export-modal" class="wc-amazon-sync-export-modal" style="display: none;" aria-hidden="true">
				<div class="wc-amazon-sync-export-modal-backdrop"></div>
				<div class="wc-amazon-sync-export-modal-content">
					<div class="wc-amazon-sync-export-modal-header">
						<h2 id="wc-amazon-sync-export-modal-title" class="wc-amazon-sync-export-modal-title"><?php esc_html_e( 'Export to Amazon — Preview', 'wc-amazon-sync' ); ?></h2>
						<button type="button" class="wc-amazon-sync-export-modal-close" aria-label="<?php esc_attr_e( 'Close', 'wc-amazon-sync' ); ?>">&times;</button>
					</div>
					<div class="wc-amazon-sync-export-modal-body">
						<div id="wc-amazon-sync-export-modal-loading" class="wc-amazon-sync-export-modal-loading"><?php esc_html_e( 'Loading…', 'wc-amazon-sync' ); ?></div>
						<div id="wc-amazon-sync-export-modal-main" class="wc-amazon-sync-export-modal-main" style="display: none;">
							<div id="wc-amazon-sync-export-errors" class="wc-amazon-sync-export-errors" style="display: none;"></div>
							<div id="wc-amazon-sync-export-warnings" class="wc-amazon-sync-export-warnings" style="display: none;"></div>
							<div id="wc-amazon-sync-export-preview-data" class="wc-amazon-sync-export-preview-data"></div>
							<div id="wc-amazon-sync-export-payload-json" class="wc-amazon-sync-export-payload-json" style="display: none;"></div>
						</div>
					</div>
					<div class="wc-amazon-sync-export-modal-footer">
						<button type="button" id="wc-amazon-sync-export-cancel-btn" class="button"><?php esc_html_e( 'Cancel', 'wc-amazon-sync' ); ?></button>
						<button type="button" id="wc-amazon-sync-export-send-btn" class="button button-primary" style="display: none;"><?php esc_html_e( 'Send', 'wc-amazon-sync' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
