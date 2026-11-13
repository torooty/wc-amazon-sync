<?php
/**
 * Import Tab Content - rendered inside Settings page tab "Import"
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

use WCAmazonSync\Database\Migration;
use WCAmazonSync\Logger\AmazonLogger;
use WCAmazonSync\Services\AmazonImportService;
use WCAmazonSync\Services\StockSyncHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Import Tab Content
 */
class ImportTabContent {

	/**
	 * Constructor (registers AJAX handlers when class is first used)
	 */
	public function __construct() {
		// AJAX handlers are registered in Plugin so they run on every admin request
	}

	/**
	 * Renders the Import tab. List source: same table/column as import (single source of truth).
	 * Table: wp_*_amazon_imported_products. Title column: title (no item_name/product_name).
	 */
	public function render() {
		?>
		<div class="wrap wc-amazon-sync-import-tab" style="margin-top: 20px;">
			<p><?php esc_html_e( 'Import products from Amazon SP-API. Results are stored in the database and will not duplicate on re-import.', 'wc-amazon-sync' ); ?></p>

			<div style="margin: 20px 0;">
				<button type="button" id="amazon-import-btn" class="button button-primary button-large">
					<?php esc_html_e( 'Import from Amazon', 'wc-amazon-sync' ); ?>
				</button>
				<button type="button" id="amazon-clear-list-btn" class="button button-large" style="margin-left: 10px;">
					<?php esc_html_e( 'Clear list', 'wc-amazon-sync' ); ?>
				</button>
				<button type="button" id="amazon-refresh-statuses-btn" class="button button-large" style="margin-left: 10px;" title="<?php esc_attr_e( 'Update Status, Bestand and Preis from Amazon without re-importing', 'wc-amazon-sync' ); ?>">
					<?php esc_html_e( 'Status aktualisieren', 'wc-amazon-sync' ); ?>
				</button>
				<button type="button" id="amazon-clean-broken-links-btn" class="button button-large" style="margin-left: 10px;" title="<?php esc_attr_e( 'Remove links where the WooCommerce product was deleted', 'wc-amazon-sync' ); ?>">
					<?php esc_html_e( 'Verknüpfungen prüfen', 'wc-amazon-sync' ); ?>
				</button>
				<span id="amazon-import-spinner" class="spinner" style="float: none; margin-left: 10px; visibility: hidden;"></span>
				<span id="amazon-import-result-msg" style="margin-left: 10px;"></span>
			</div>
			<p class="description" style="margin-top: -8px;">
				<?php esc_html_e( 'Clear list removes all imported products for the current marketplace so you can run a fresh import. After a clean import you can rely on "skip existing" on next runs.', 'wc-amazon-sync' ); ?>
				<br>
				<?php esc_html_e( 'Status aktualisieren: fetches current Status, Bestand and Preis from Amazon without deleting or re-importing. Verknüpfungen prüfen: removes links where the WooCommerce product no longer exists.', 'wc-amazon-sync' ); ?>
			</p>
			<p style="margin: 12px 0;">
				<label for="amazon-unlink-sku-input"><?php esc_html_e( 'Verknüpfung für SKU aufheben:', 'wc-amazon-sync' ); ?></label>
				<input type="text" id="amazon-unlink-sku-input" placeholder="<?php esc_attr_e( 'z.B. 41810325', 'wc-amazon-sync' ); ?>" style="width: 120px; margin-left: 8px;" />
				<button type="button" id="amazon-unlink-by-sku-btn" class="button" style="margin-left: 6px;"><?php esc_html_e( 'Verknüpfung aufheben', 'wc-amazon-sync' ); ?></button>
				<span id="amazon-unlink-sku-msg" style="margin-left: 10px;"></span>
			</p>

			<div class="notice notice-info" style="margin: 16px 0; max-width: 720px;">
				<p><strong><?php esc_html_e( 'If you see "403 Unauthorized" from Amazon', 'wc-amazon-sync' ); ?></strong></p>
				<p><?php esc_html_e( 'Credentials are sent correctly; Amazon is denying access to the Catalog Items API. Your app in Seller Central must have the Product Listing role approved, and the selling partner must have authorized the app.', 'wc-amazon-sync' ); ?></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'In Seller Central: Apps & Services → Develop Apps → your app → ensure "Product Listing" (or equivalent catalog/listings) role is selected and approved.', 'wc-amazon-sync' ); ?></li>
					<li><?php esc_html_e( 'Re-authorize the app if you added the role later; the refresh token must include the new permissions.', 'wc-amazon-sync' ); ?></li>
				</ul>
				<p><a href="https://developer-docs.amazon.com/sp-api/docs/authorizing-selling-partner-api-applications" target="_blank" rel="noopener"><?php esc_html_e( 'Amazon: Authorize SP-API applications', 'wc-amazon-sync' ); ?></a></p>
			</div>

			<h3><?php esc_html_e( 'Saved imported products', 'wc-amazon-sync' ); ?></h3>

			<?php
			self::render_products_list(
				admin_url( 'admin.php?page=wc-amazon-sync&tab=import' ),
				array( 'page' => 'wc-amazon-sync', 'tab' => 'import' )
			);
			?>

			<!-- Log window -->
			<div style="margin-top: 30px;">
				<h3><?php esc_html_e( 'Import log', 'wc-amazon-sync' ); ?></h3>
				<div style="border: 1px solid #ccd0d4; padding: 10px; background: #fff;">
					<div style="margin-bottom: 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
						<select id="amazon-log-scope-filter">
							<option value=""><?php esc_html_e( 'All scopes', 'wc-amazon-sync' ); ?></option>
							<option value="import"><?php esc_html_e( 'Import', 'wc-amazon-sync' ); ?></option>
							<option value="categories"><?php esc_html_e( 'Categories', 'wc-amazon-sync' ); ?></option>
							<option value="test_connection"><?php esc_html_e( 'Test connection', 'wc-amazon-sync' ); ?></option>
						</select>
						<select id="amazon-log-level-filter">
							<option value=""><?php esc_html_e( 'All levels', 'wc-amazon-sync' ); ?></option>
							<option value="info"><?php esc_html_e( 'Info', 'wc-amazon-sync' ); ?></option>
							<option value="warn"><?php esc_html_e( 'Warn', 'wc-amazon-sync' ); ?></option>
							<option value="error"><?php esc_html_e( 'Error', 'wc-amazon-sync' ); ?></option>
						</select>
						<button type="button" id="amazon-logs-refresh" class="button"><?php esc_html_e( 'Refresh', 'wc-amazon-sync' ); ?></button>
						<button type="button" id="amazon-logs-copy" class="button"><?php esc_html_e( 'Copy', 'wc-amazon-sync' ); ?></button>
						<button type="button" id="amazon-logs-clear" class="button"><?php esc_html_e( 'Clear', 'wc-amazon-sync' ); ?></button>
					</div>
					<div id="amazon-logs-container" style="max-height: 320px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; padding: 12px; font-family: monospace; font-size: 12px; line-height: 1.5;"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render products list (filters, table, pagination). Used by Import tab and Products page.
	 *
	 * @param string       $base_url     Base URL for form action, sort and pagination (e.g. admin.php?page=wc-amazon-sync&tab=import or ?page=wc-amazon-sync-products).
	 * @param array<string, string> $hidden_params Associative array of hidden input name => value for the filter form (e.g. ['page' => 'wc-amazon-sync', 'tab' => 'import']).
	 * @param bool         $only_mapped_categories When true (Products page), show only rows from Woo categories that have an Amazon mapping, plus all unlinked imported articles.
	 * @param bool         $skip_filter_form When true (Products page with own Kaufland-style form), do not output the tablenav filter form; only table and pagination.
	 * @return void
	 */
	public static function render_products_list( $base_url, $hidden_params = array(), $only_mapped_categories = false, $skip_filter_form = false ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'amazon_imported_products';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter_amazon_status = isset( $_GET['filter_amazon_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_amazon_status'] ) ) : '';
		$filter_woo_status    = isset( $_GET['filter_woo_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_woo_status'] ) ) : '';
		$category_filter      = isset( $_GET['category'] ) ? max( 0, (int) $_GET['category'] ) : 0;

		$allowed_orderby = array( 'active' => 'active', 'last_seen_at' => 'last_seen_at', 'listing_status' => 'listing_status', 'quantity' => 'quantity', 'title' => 'title', 'asin' => 'asin' );
		$orderby_param = isset( $_GET['orderby'] ) && isset( $allowed_orderby[ $_GET['orderby'] ] ) ? $_GET['orderby'] : 'active';
		$orderby = $allowed_orderby[ $orderby_param ];
		$order   = ( isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';
		$paged   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$per_page = 20;
		$offset  = ( $paged - 1 ) * $per_page;

		$where = array( '1=1' );
		$values = array();

		$products = array();
		$total = 0;
		$mappings_table = $wpdb->prefix . 'amazon_category_mappings';
		$tr_table       = $wpdb->prefix . 'term_relationships';
		$tt_table       = $wpdb->prefix . 'term_taxonomy';
		$posts_table    = $wpdb->posts;

		if ( $only_mapped_categories && $skip_filter_form ) {
			// Products page: list all WooCommerce products in categories that have an Amazon mapping (from table, no hardcoding). Include linked import data when present.
			$cat_subquery = $category_filter > 0
				? $wpdb->prepare(
					"SELECT DISTINCT tr.object_id FROM {$tr_table} tr INNER JOIN {$tt_table} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id = %d",
					$category_filter
				)
				: "SELECT DISTINCT tr.object_id FROM {$tr_table} tr INNER JOIN {$tt_table} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (SELECT woo_category_id FROM {$mappings_table})";
			$search_where = '';
			$search_values = array();
			if ( $search !== '' ) {
				$like = '%' . $wpdb->esc_like( $search ) . '%';
				$search_where = " AND (p.post_title LIKE %s OR aip.seller_sku LIKE %s OR aip.gtin LIKE %s OR aip.title LIKE %s)";
				$search_values = array( $like, $like, $like, $like );
			}
			$amazon_status_where = '';
			if ( $filter_amazon_status !== '' ) {
				if ( $filter_amazon_status === '__empty__' ) {
					$amazon_status_where = " AND (aip.listing_status IS NULL OR aip.listing_status = '')";
				} else {
					$amazon_status_where = " AND aip.listing_status = %s";
					$search_values[] = $filter_amazon_status;
				}
			}
			$count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$posts_table} p INNER JOIN ({$cat_subquery}) cat ON cat.object_id = p.ID LEFT JOIN {$table} aip ON aip.wc_product_id = p.ID AND aip.link_status = 'linked' WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private'){$search_where}{$amazon_status_where}";
			$total = $search_values ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $search_values ) ) : (int) $wpdb->get_var( $count_sql );
			$order_col = 'p.post_title';
			$order_dir = ( $order === 'ASC' ) ? 'ASC' : 'DESC';
			$products_sql = "SELECT p.ID as wc_product_id, p.post_title as wc_title, MAX(aip.id) as amazon_id, MAX(aip.asin) as asin, MAX(aip.seller_sku) as seller_sku, MAX(aip.gtin) as gtin, MAX(aip.title) as amazon_title, MAX(aip.quantity) as quantity, MAX(aip.listing_status) as listing_status, MAX(aip.link_status) as link_status, MAX(aip.sync_disabled) as sync_disabled, MAX(aip.marketplace_id) as marketplace_id, MAX(aip.price) as price, MAX(aip.brand) as brand, MAX(aip.link_method) as link_method FROM {$posts_table} p INNER JOIN ({$cat_subquery}) cat ON cat.object_id = p.ID LEFT JOIN {$table} aip ON aip.wc_product_id = p.ID AND aip.link_status = 'linked' WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private'){$search_where}{$amazon_status_where} GROUP BY p.ID, p.post_title ORDER BY {$order_col} {$order_dir} LIMIT %d OFFSET %d";
			$products_params = array_merge( $search_values, array( $per_page, $offset ) );
			$rows = $wpdb->get_results( $wpdb->prepare( $products_sql, $products_params ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$wc_id = isset( $r['wc_product_id'] ) ? (int) $r['wc_product_id'] : 0;
				$amazon_id = isset( $r['amazon_id'] ) ? (int) $r['amazon_id'] : null;
				$products[] = array(
					'id'              => $amazon_id,
					'wc_product_id'   => $wc_id,
					'title'           => isset( $r['amazon_title'] ) && $r['amazon_title'] !== '' ? $r['amazon_title'] : ( isset( $r['wc_title'] ) ? $r['wc_title'] : '' ),
					'asin'            => isset( $r['asin'] ) ? $r['asin'] : null,
					'seller_sku'      => isset( $r['seller_sku'] ) ? $r['seller_sku'] : null,
					'gtin'            => isset( $r['gtin'] ) ? $r['gtin'] : null,
					'quantity'        => isset( $r['quantity'] ) ? $r['quantity'] : null,
					'listing_status'  => isset( $r['listing_status'] ) ? $r['listing_status'] : null,
					'link_status'     => $amazon_id ? 'linked' : null,
					'sync_disabled'   => isset( $r['sync_disabled'] ) ? $r['sync_disabled'] : 0,
					'marketplace_id'  => isset( $r['marketplace_id'] ) ? $r['marketplace_id'] : null,
					'price'           => isset( $r['price'] ) ? $r['price'] : null,
					'brand'           => isset( $r['brand'] ) ? $r['brand'] : null,
					'link_method'     => isset( $r['link_method'] ) ? $r['link_method'] : null,
				);
			}
		} else {
			// Import tab or other: filter from amazon_imported_products only.
			if ( $only_mapped_categories ) {
				$where[] = "({$table}.link_status = 'linked' AND {$table}.wc_product_id IS NOT NULL)";
				if ( $category_filter > 0 ) {
					$subquery = $wpdb->prepare(
						"SELECT DISTINCT tr.object_id FROM {$tr_table} tr INNER JOIN {$tt_table} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id = %d",
						$category_filter
					);
					$where[] = "{$table}.wc_product_id IN ({$subquery})";
				} else {
					$subquery = "SELECT DISTINCT tr.object_id FROM {$tr_table} tr INNER JOIN {$tt_table} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (SELECT woo_category_id FROM {$mappings_table})";
					$where[] = "{$table}.wc_product_id IN ({$subquery})";
				}
			}

			if ( $search !== '' ) {
				$where[] = '( asin LIKE %s OR seller_sku LIKE %s OR title LIKE %s OR brand LIKE %s OR gtin LIKE %s )';
				$like = '%' . $wpdb->esc_like( $search ) . '%';
				$values = array_merge( $values, array( $like, $like, $like, $like, $like ) );
			}
			if ( $filter_amazon_status !== '' ) {
				if ( $filter_amazon_status === '__empty__' ) {
					$where[] = '( listing_status IS NULL OR listing_status = "" )';
				} else {
					$where[] = 'listing_status = %s';
					$values[] = $filter_amazon_status;
				}
			}
			if ( ! $only_mapped_categories && $filter_woo_status === 'linked' ) {
				$where[] = "link_status = 'linked'";
				$where[] = 'wc_product_id IS NOT NULL';
			} elseif ( ! $only_mapped_categories && $filter_woo_status === 'unlinked' ) {
				$where[] = "( link_status IS NULL OR link_status != 'linked' OR wc_product_id IS NULL )";
			}

			$where_sql = implode( ' AND ', $where );
			if ( $orderby === 'active' ) {
				$order_sql = "ORDER BY (CASE WHEN listing_status IN ('AVAILABLE','AKTIV','ACTIVE','IN_STOCK') AND COALESCE(sync_disabled,0) = 0 THEN 0 ELSE 1 END) ASC, last_seen_at DESC";
			} else {
				$order_sql = 'ORDER BY ' . $orderby . ' ' . ( $order === 'ASC' ? 'ASC' : 'DESC' );
			}
			$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
			$total = $values ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) : (int) $wpdb->get_var( $count_sql );
			$products_sql = "SELECT * FROM {$table} WHERE {$where_sql} {$order_sql} LIMIT %d OFFSET %d";
			$products_values = array_merge( $values, array( $per_page, $offset ) );
			$products = $wpdb->get_results( $wpdb->prepare( $products_sql, $products_values ), ARRAY_A );
		}

		$amazon_statuses = $wpdb->get_col( "SELECT DISTINCT listing_status FROM {$table} WHERE listing_status IS NOT NULL AND listing_status != '' ORDER BY listing_status ASC" );
		$amazon_statuses = is_array( $amazon_statuses ) ? $amazon_statuses : array();

		$sort_url = function( $ob, $dir ) use ( $base_url, $search, $filter_amazon_status, $filter_woo_status, $category_filter, $hidden_params ) {
			$params = array_merge( $hidden_params, array( 'orderby' => $ob, 'order' => $dir ) );
			if ( $search !== '' ) {
				$params['s'] = $search;
			}
			if ( $filter_amazon_status !== '' ) {
				$params['filter_amazon_status'] = $filter_amazon_status;
			}
			if ( $filter_woo_status !== '' ) {
				$params['filter_woo_status'] = $filter_woo_status;
			}
			if ( $category_filter > 0 ) {
				$params['category'] = $category_filter;
			}
			return add_query_arg( $params, $base_url );
		};
		$marketplace_domain = array(
			'A1PA6795UKMFR'  => 'amazon.de',
			'A1PA6795UKMFR9' => 'amazon.de',
			'A1F83G8C2ARO7P' => 'amazon.co.uk',
			'A13V1IB3VIYZZH' => 'amazon.fr',
			'A1RKKUPIHCS9HS' => 'amazon.es',
			'A1805IZSGTT6HS' => 'amazon.it',
		);
		$get_amazon_url = function( $asin, $marketplace_id ) use ( $marketplace_domain ) {
			$domain = isset( $marketplace_domain[ $marketplace_id ] ) ? $marketplace_domain[ $marketplace_id ] : 'amazon.de';
			return 'https://www.' . $domain . '/dp/' . $asin;
		};
		$amazon_categories_names = get_option( 'wc_amazon_sync_amazon_categories', array() );
		if ( ! is_array( $amazon_categories_names ) ) {
			$amazon_categories_names = array();
		}
		$clear_params = array_merge( $hidden_params, array( 'paged' => 1 ) );
		if ( $category_filter > 0 ) {
			$clear_params['category'] = $category_filter;
		}
		$has_filters = $search || $filter_amazon_status || $filter_woo_status || $category_filter;
		?>
		<?php if ( ! $skip_filter_form ) : ?>
		<div class="tablenav top">
			<div class="alignleft actions">
				<form method="get" action="" style="display: inline-flex; align-items: center; flex-wrap: wrap; gap: 10px;">
					<?php foreach ( $hidden_params as $name => $val ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $val ); ?>" />
					<?php endforeach; ?>
					<label for="filter_amazon_status" class="screen-reader-text"><?php esc_html_e( 'Status Amazon', 'wc-amazon-sync' ); ?></label>
					<select name="filter_amazon_status" id="filter_amazon_status">
						<option value=""><?php esc_html_e( 'Alle Status (Amazon)', 'wc-amazon-sync' ); ?></option>
						<option value="__empty__" <?php selected( $filter_amazon_status, '__empty__' ); ?>><?php esc_html_e( '— Leer', 'wc-amazon-sync' ); ?></option>
						<?php foreach ( $amazon_statuses as $as ) : ?>
							<option value="<?php echo esc_attr( $as ); ?>" <?php selected( $filter_amazon_status, $as ); ?>><?php echo esc_html( $as ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="filter_woo_status" class="screen-reader-text"><?php esc_html_e( 'Woo-Status', 'wc-amazon-sync' ); ?></label>
					<select name="filter_woo_status" id="filter_woo_status">
						<option value=""><?php esc_html_e( 'Alle (Woo)', 'wc-amazon-sync' ); ?></option>
						<option value="linked" <?php selected( $filter_woo_status, 'linked' ); ?>><?php esc_html_e( 'Verknüpft', 'wc-amazon-sync' ); ?></option>
						<option value="unlinked" <?php selected( $filter_woo_status, 'unlinked' ); ?>><?php esc_html_e( 'Nicht verknüpft', 'wc-amazon-sync' ); ?></option>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search ASIN, SKU, EAN…', 'wc-amazon-sync' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wc-amazon-sync' ); ?></button>
					<?php if ( $has_filters ) : ?>
						<a href="<?php echo esc_url( add_query_arg( $clear_params, $base_url ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'wc-amazon-sync' ); ?></a>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<?php endif; ?>

			<table class="wp-list-table widefat fixed striped wc-amazon-sync-import-table <?php echo $skip_filter_form ? 'wc-amazon-sync-products-table' : ''; ?>">
				<thead>
					<tr>
						<?php if ( $skip_filter_form ) : ?>
							<th style="width: 60px;"><?php esc_html_e( 'Image', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Woo Product', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Woo Category', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'SKU', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'EAN', 'wc-amazon-sync' ); ?></th>
							<th style="width: 90px;"><?php esc_html_e( 'Preis (Amazon)', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Amazon Category', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Amazon Status', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Stock', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wc-amazon-sync' ); ?></th>
						<?php else : ?>
							<th style="max-width: 200px; width: 18%;"><?php esc_html_e( 'Title', 'wc-amazon-sync' ); ?></th>
							<th style="width: 120px;"><?php esc_html_e( 'ASIN', 'wc-amazon-sync' ); ?></th>
							<th style="width: 110px;"><?php esc_html_e( 'SKU', 'wc-amazon-sync' ); ?></th>
							<th style="width: 110px;"><?php esc_html_e( 'EAN', 'wc-amazon-sync' ); ?></th>
							<th style="width: 90px;"><?php esc_html_e( 'Preis', 'wc-amazon-sync' ); ?></th>
							<th style="width: 80px;"><a href="<?php echo esc_url( $sort_url( 'quantity', $orderby_param === 'quantity' && $order === 'DESC' ? 'asc' : 'desc' ) ); ?>"><?php esc_html_e( 'Bestand', 'wc-amazon-sync' ); ?></a><?php echo $orderby_param === 'quantity' ? ' <span class="dashicons dashicons-arrow-' . ( $order === 'ASC' ? 'up' : 'down' ) . '-alt"></span>' : ''; ?></th>
							<th style="width: 180px;"><?php esc_html_e( 'Woo-Status', 'wc-amazon-sync' ); ?></th>
							<th style="width: 120px;"><a href="<?php echo esc_url( $sort_url( 'listing_status', $orderby_param === 'listing_status' && $order === 'DESC' ? 'asc' : 'desc' ) ); ?>"><?php esc_html_e( 'Status', 'wc-amazon-sync' ); ?></a><?php echo $orderby_param === 'listing_status' ? ' <span class="dashicons dashicons-arrow-' . ( $order === 'ASC' ? 'up' : 'down' ) . '-alt"></span>' : ''; ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr><td colspan="<?php echo $skip_filter_form ? 11 : 8; ?>"><?php
						if ( $only_mapped_categories ) {
							esc_html_e( 'Keine Produkte. Auf der Seite Kategorien müssen WooCommerce-Kategorien mit Amazon verknüpft sein. Dann erscheinen hier alle Produkte aus diesen Kategorien (bereits verknüpfte mit Amazon-Daten).', 'wc-amazon-sync' );
						} else {
							esc_html_e( 'No imported products yet. Click "Import from Amazon" to fetch products.', 'wc-amazon-sync' );
						}
						?></td></tr>
					<?php else : ?>
						<?php
						$mappings_table = $wpdb->prefix . 'amazon_category_mappings';
						foreach ( $products as $row ) :
							$amazon_row_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
							$marketplace_id = isset( $row['marketplace_id'] ) ? $row['marketplace_id'] : '';
							$is_linked = isset( $row['link_status'] ) && $row['link_status'] === 'linked' && ! empty( $row['wc_product_id'] );
							$wc_product_id = isset( $row['wc_product_id'] ) ? (int) $row['wc_product_id'] : 0;
							$wc_product_exists = $wc_product_id && function_exists( 'wc_get_product' ) && wc_get_product( $wc_product_id );
							$link_method = isset( $row['link_method'] ) && $row['link_method'] !== '' ? $row['link_method'] : '—';
							$sync_disabled = ! empty( $row['sync_disabled'] );
							$listing_status = isset( $row['listing_status'] ) && $row['listing_status'] !== '' ? $row['listing_status'] : '—';
							$status_active = in_array( strtoupper( $listing_status ), array( 'AKTIV', 'AVAILABLE', 'ACTIVE', 'IN_STOCK' ), true );
							$show_activate_btn = $sync_disabled || ! $status_active;
							// Export status: READY = not linked, EXPORTED = linked, BLOCKED = sync_disabled
							$export_status = $sync_disabled ? 'BLOCKED' : ( $is_linked ? 'EXPORTED' : 'READY' );
							$on_amazon = $is_linked;
							$woo_title = '';
							$woo_category_names = '';
							$amazon_category_display = '—';
							$product_image_url = '';
							if ( $wc_product_id && $wc_product_exists && function_exists( 'wc_get_product' ) ) {
								$wc_product = wc_get_product( $wc_product_id );
								if ( $wc_product ) {
									$woo_title = $wc_product->get_name();
									$image_id = $wc_product->get_image_id();
									if ( $image_id ) {
										$img_url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
										if ( $img_url ) {
											$product_image_url = $img_url;
										}
									}
									$term_ids = wc_get_product_term_ids( $wc_product_id, 'product_cat' );
									if ( ! empty( $term_ids ) ) {
										$names = array();
										foreach ( $term_ids as $tid ) {
											$t = get_term( $tid, 'product_cat' );
											if ( $t && ! is_wp_error( $t ) ) {
												$names[] = $t->name;
											}
										}
										$woo_category_names = implode( ', ', $names );
									}
									foreach ( $term_ids as $tid ) {
										$mapped = $wpdb->get_var( $wpdb->prepare( "SELECT default_amazon_category_id FROM {$mappings_table} WHERE woo_category_id = %d LIMIT 1", $tid ) );
										if ( $mapped ) {
											$amazon_category_display = isset( $amazon_categories_names[ $mapped ] ) ? $amazon_categories_names[ $mapped ] : $mapped;
											break;
										}
									}
								}
							}
							if ( $skip_filter_form && empty( $woo_title ) && isset( $row['title'] ) ) {
								$woo_title = wp_trim_words( $row['title'], 8 );
							}
							// Title: show Amazon title when published on Amazon, else WooCommerce title.
							$amazon_title = isset( $row['title'] ) && trim( (string) $row['title'] ) !== '' ? trim( $row['title'] ) : '';
							$display_title = ( $on_amazon && $amazon_title !== '' ) ? $amazon_title : ( $woo_title ? $woo_title : '—' );
						?>
							<tr data-amazon-row-id="<?php echo esc_attr( $amazon_row_id ); ?>">
								<?php if ( $skip_filter_form ) : ?>
									<td>
										<?php if ( $product_image_url ) : ?>
											<img src="<?php echo esc_url( $product_image_url ); ?>" alt="" style="width: 40px; height: 40px; object-fit: cover; border: 1px solid #ddd; border-radius: 3px;">
										<?php else : ?>
											<div style="width: 40px; height: 40px; background: #f0f0f0; border: 1px solid #ddd; border-radius: 3px; display: flex; align-items: center; justify-content: center;">
												<span class="dashicons dashicons-format-image" style="color: #999; font-size: 20px;"></span>
											</div>
										<?php endif; ?>
									</td>
									<td>
										<strong><?php echo esc_html( $display_title ); ?></strong>
										<?php if ( $on_amazon && $amazon_title !== '' && $woo_title !== '' && $amazon_title !== $woo_title ) : ?>
											<br><small style="color: #646970;"><?php esc_html_e( 'WC:', 'wc-amazon-sync' ); ?> <?php echo esc_html( wp_trim_words( $woo_title, 6 ) ); ?></small>
										<?php endif; ?>
										<?php if ( $wc_product_id ) : ?>
											<br><small>WC ID: <?php echo esc_html( $wc_product_id ); ?></small>
											<?php if ( $wc_product_exists ) : ?>
												<br><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $wc_product_id . '&action=edit' ) ); ?>" target="_blank" rel="noopener" style="font-size: 11px;"><?php esc_html_e( 'View in Woo', 'wc-amazon-sync' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $woo_category_names ? $woo_category_names : '—' ); ?></td>
									<td><?php echo esc_html( isset( $row['seller_sku'] ) && $row['seller_sku'] !== '' ? $row['seller_sku'] : '—' ); ?></td>
									<td><?php echo esc_html( isset( $row['gtin'] ) && $row['gtin'] !== '' ? $row['gtin'] : '—' ); ?></td>
									<td><?php
										if ( $on_amazon && isset( $row['price'] ) && $row['price'] !== '' && $row['price'] !== null ) {
											$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
											$decimals = ( function_exists( 'wc_get_price_decimal_separator' ) && function_exists( 'wc_get_price_thousand_separator' ) ) ? number_format( (float) $row['price'], 2, wc_get_price_decimal_separator(), wc_get_price_thousand_separator() ) : number_format( (float) $row['price'], 2, '.', ',' );
											echo esc_html( $decimals . ( $currency_symbol ? ' ' . $currency_symbol : '' ) );
										} else {
											echo '—';
										}
									?></td>
									<td><?php echo esc_html( $amazon_category_display ); ?></td>
									<td>
										<span class="wc-amazon-sync-status-badge wc-amazon-sync-export-<?php echo esc_attr( strtolower( $export_status ) ); ?>"><?php echo esc_html( $export_status ); ?></span>
										<?php if ( ! empty( $row['asin'] ) ) : ?>
											<br><a href="<?php echo esc_url( $get_amazon_url( $row['asin'], $marketplace_id ) ); ?>" target="_blank" rel="noopener" style="font-size: 11px; margin-top: 4px; display: inline-block;"><?php esc_html_e( 'Auf Amazon anzeigen', 'wc-amazon-sync' ); ?></a>
											<br><button type="button" class="button button-small wc-amazon-sync-check-link-btn" data-amazon-row-id="<?php echo esc_attr( $amazon_row_id ); ?>" data-amazon-url="<?php echo esc_url( $get_amazon_url( $row['asin'], $marketplace_id ) ); ?>" style="margin-top: 4px; font-size: 11px;"><?php esc_html_e( 'Verbindung prüfen', 'wc-amazon-sync' ); ?></button>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $sync_disabled ) : ?>
											<span class="wc-amazon-sync-status-badge wc-amazon-sync-status-inactive"><?php esc_html_e( 'Deaktiviert', 'wc-amazon-sync' ); ?></span>
										<?php else : ?>
											<span class="wc-amazon-sync-status-badge <?php echo $status_active ? 'wc-amazon-sync-status-active' : 'wc-amazon-sync-status-neutral'; ?>"><?php echo esc_html( $listing_status ); ?></span>
										<?php endif; ?>
										<?php if ( $on_amazon ) : ?>
											<br><button type="button" class="button button-small wc-amazon-sync-refresh-one-status-btn" data-amazon-row-id="<?php echo esc_attr( $amazon_row_id ); ?>" style="margin-top: 4px; font-size: 11px;"><?php esc_html_e( 'Aktualisieren', 'wc-amazon-sync' ); ?></button>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( ( isset( $row['quantity'] ) && $row['quantity'] !== '' && $row['quantity'] !== null ) ? (int) $row['quantity'] : '—' ); ?></td>
									<td>
										<?php if ( $on_amazon ) : ?>
											<button type="button" class="button button-small wc-amazon-sync-toggle-status-btn" data-amazon-row-id="<?php echo esc_attr( $amazon_row_id ); ?>" data-intent="<?php echo $show_activate_btn ? 'activate' : 'deactivate'; ?>"><?php echo $show_activate_btn ? esc_html__( 'Aktivieren', 'wc-amazon-sync' ) : esc_html__( 'Pause', 'wc-amazon-sync' ); ?></button>
										<?php elseif ( $skip_filter_form && $wc_product_id ) : ?>
											<button type="button" class="button button-small button-primary wc-amazon-sync-export-preview-btn" data-wc-product-id="<?php echo esc_attr( $wc_product_id ); ?>"><?php esc_html_e( 'Nach Amazon', 'wc-amazon-sync' ); ?></button>
										<?php else : ?>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-amazon-sync&tab=import' ) ); ?>" class="button button-small button-primary" target="_blank" rel="noopener"><?php esc_html_e( 'Nach Amazon', 'wc-amazon-sync' ); ?></a>
										<?php endif; ?>
									</td>
								<?php else : ?>
									<td style="max-width: 200px;" title="<?php echo esc_attr( isset( $row['title'] ) ? $row['title'] : '' ); ?>"><?php echo esc_html( wp_trim_words( isset( $row['title'] ) ? $row['title'] : '', 12 ) ); ?></td>
									<td>
										<code><?php echo esc_html( $row['asin'] ); ?></code>
										<br><a href="<?php echo esc_url( $get_amazon_url( $row['asin'], $marketplace_id ) ); ?>" target="_blank" rel="noopener" class="wc-amazon-sync-amazon-link"><?php esc_html_e( 'Auf Amazon anzeigen', 'wc-amazon-sync' ); ?></a>
									</td>
									<td><?php echo esc_html( isset( $row['seller_sku'] ) && $row['seller_sku'] !== '' ? $row['seller_sku'] : '—' ); ?></td>
									<td><?php echo esc_html( isset( $row['gtin'] ) && $row['gtin'] !== '' ? $row['gtin'] : '—' ); ?></td>
									<td><?php
										if ( isset( $row['price'] ) && $row['price'] !== '' && $row['price'] !== null ) {
											$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
											$decimals = ( function_exists( 'wc_get_price_decimal_separator' ) && function_exists( 'wc_get_price_thousand_separator' ) ) ? number_format( (float) $row['price'], 2, wc_get_price_decimal_separator(), wc_get_price_thousand_separator() ) : number_format( (float) $row['price'], 2, '.', ',' );
											echo esc_html( $decimals . ( $currency_symbol ? ' ' . $currency_symbol : '' ) );
										} else {
											echo '—';
										}
									?></td>
									<td><?php echo esc_html( ( isset( $row['quantity'] ) && $row['quantity'] !== '' && $row['quantity'] !== null ) ? (int) $row['quantity'] : '—' ); ?></td>
									<td class="wc-amazon-sync-woo-status-cell">
										<?php if ( $is_linked && $wc_product_id ) : ?>
											<?php if ( $wc_product_exists ) : ?>
												<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $wc_product_id . '&action=edit' ) ); ?>" target="_blank" rel="noopener" class="wc-amazon-sync-wc-product-link"><?php echo esc_html( sprintf( __( 'WC-Produkt #%d', 'wc-amazon-sync' ), $wc_product_id ) ); ?></a>
												<span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
											<?php else : ?>
												<span class="wc-amazon-sync-wc-deleted" title="<?php esc_attr_e( 'WooCommerce-Produkt wurde gelöscht', 'wc-amazon-sync' ); ?>"><?php echo esc_html( sprintf( __( 'WC #%d (nicht mehr vorhanden)', 'wc-amazon-sync' ), $wc_product_id ) ); ?></span>
											<?php endif; ?>
											<br><small><?php echo esc_html( sprintf( __( 'Methode: %s', 'wc-amazon-sync' ), $link_method ) ); ?></small>
											<br><button type="button" class="button button-small wc-amazon-sync-unlink-btn" data-amazon-row-id="<?php echo esc_attr( $amazon_row_id ); ?>"><?php esc_html_e( 'Verknüpfung aufheben', 'wc-amazon-sync' ); ?></button>
										<?php else : ?>
											<span class="wc-amazon-sync-not-linked"><?php esc_html_e( 'Nicht verknüpft', 'wc-amazon-sync' ); ?></span>
											<br><input type="text" class="wc-amazon-sync-search-input" placeholder="<?php esc_attr_e( 'SKU, Name, EAN eingeben…', 'wc-amazon-sync' ); ?>" data-amazon-row-id="<?php echo esc_attr( $amazon_row_id ); ?>" style="width: 160px; margin-top: 4px;" autocomplete="off" />
											<div class="wc-amazon-sync-search-results" data-amazon-row-id="<?php echo esc_attr( $amazon_row_id ); ?>" style="margin-top: 4px; font-size: 11px; max-height: 120px; overflow-y: auto;"></div>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $sync_disabled ) : ?>
											<span class="wc-amazon-sync-status-badge wc-amazon-sync-status-inactive"><?php esc_html_e( 'Deaktiviert', 'wc-amazon-sync' ); ?></span>
										<?php else : ?>
											<span class="wc-amazon-sync-status-badge <?php echo $status_active ? 'wc-amazon-sync-status-active' : 'wc-amazon-sync-status-neutral'; ?>"><?php echo esc_html( $listing_status ); ?></span>
										<?php endif; ?>
										<br>
										<button type="button" class="button button-small wc-amazon-sync-toggle-status-btn" data-amazon-row-id="<?php echo esc_attr( $amazon_row_id ); ?>" data-intent="<?php echo $show_activate_btn ? 'activate' : 'deactivate'; ?>">
											<?php echo $show_activate_btn ? esc_html__( 'Aktivieren', 'wc-amazon-sync' ) : esc_html__( 'Deaktivieren', 'wc-amazon-sync' ); ?>
										</button>
									</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total > $per_page ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						$total_pages = (int) ceil( $total / $per_page );
						$prev_page = $paged > 1 ? $paged - 1 : 0;
						$next_page = $paged < $total_pages ? $paged + 1 : 0;
						$pagination_args = array_merge( $hidden_params, array( 'paged' => 1, 's' => $search, 'orderby' => $orderby_param, 'order' => strtolower( $order ) ) );
						if ( $filter_amazon_status !== '' ) {
							$pagination_args['filter_amazon_status'] = $filter_amazon_status;
						}
						if ( $filter_woo_status !== '' ) {
							$pagination_args['filter_woo_status'] = $filter_woo_status;
						}
						if ( $category_filter > 0 ) {
							$pagination_args['category'] = $category_filter;
						}
						$pagination_args['paged'] = $prev_page;
						$url_prev = add_query_arg( $pagination_args, $base_url );
						$pagination_args['paged'] = $next_page;
						$url_next = add_query_arg( $pagination_args, $base_url );
						?>
						<span class="displaying-num"><?php echo esc_html( sprintf( __( '%d items', 'wc-amazon-sync' ), $total ) ); ?></span>
						<span class="pagination-links">
							<?php if ( $prev_page ) : ?><a class="prev-page button" href="<?php echo esc_url( $url_prev ); ?>">‹</a><?php endif; ?>
							<span class="paging-input"><?php echo esc_html( $paged ); ?> / <?php echo esc_html( $total_pages ); ?></span>
							<?php if ( $next_page ) : ?><a class="next-page button" href="<?php echo esc_url( $url_next ); ?>">›</a><?php endif; ?>
						</span>
					</div>
				</div>
			<?php endif; ?>
		<?php
	}

	/**
	 * AJAX: Clear imported products list for the current marketplace (fresh import).
	 */
	public static function ajax_clear_imported_products() {
		check_ajax_referer( 'amazon_clear_imported_products', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'amazon_imported_products';
		$api   = get_option( 'wc_amazon_sync_api', array() );
		$marketplace_id = is_array( $api ) && ! empty( $api['marketplace_id'] ) ? sanitize_text_field( $api['marketplace_id'] ) : '';

		if ( $marketplace_id === '' ) {
			$deleted = $wpdb->query( "DELETE FROM {$table}" );
		} else {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE marketplace_id = %s", $marketplace_id ) );
		}

		if ( $deleted === false ) {
			wp_send_json_error( array( 'message' => __( 'Database error.', 'wc-amazon-sync' ) ) );
			return;
		}

		wp_send_json_success( array(
			'deleted_count' => (int) $deleted,
			'message'      => sprintf(
				/* translators: %d: number of deleted rows */
				__( 'List cleared. %d row(s) deleted. You can run a fresh import now.', 'wc-amazon-sync' ),
				(int) $deleted
			),
		) );
	}

	/**
	 * AJAX: Import products
	 */
	public static function ajax_import_products() {
		check_ajax_referer( 'amazon_import_products', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}

		// Ensure tables exist (e.g. if plugin was updated without reactivation)
		Migration::create_tables();

		try {
			$service = new AmazonImportService();
			$result  = $service->run_import();
		} catch ( \Throwable $e ) {
			$logger = new AmazonLogger();
			$logger->error( 'Import exception: ' . $e->getMessage(), AmazonLogger::SCOPE_IMPORT, array(
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			) );
			$logs = $logger->get_recent( 20, null, AmazonLogger::SCOPE_IMPORT );
			wp_send_json_error( array(
				'message'          => __( 'Import failed:', 'wc-amazon-sync' ) . ' ' . $e->getMessage(),
				'last_log_entries' => is_array( $logs ) ? $logs : array(),
			) );
			return;
		}

		$logger = new AmazonLogger();
		$logs   = $logger->get_recent( 20, null, AmazonLogger::SCOPE_IMPORT );
		$logs   = is_array( $logs ) ? $logs : array();

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array(
				'message'          => $result['error'],
				'last_log_entries' => $logs,
			) );
			return;
		}

		wp_send_json_success( array(
			'new_count'        => (int) $result['new_count'],
			'updated_count'    => isset( $result['updated_count'] ) ? (int) $result['updated_count'] : 0,
			'skipped_count'    => (int) $result['skipped_count'],
			'last_log_entries' => $logs,
		) );
	}

	/**
	 * AJAX: Refresh statuses (listing_status, quantity, price) from Amazon report without full re-import.
	 */
	public static function ajax_refresh_statuses() {
		check_ajax_referer( 'amazon_refresh_statuses', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		Migration::create_tables();
		try {
			$service = new AmazonImportService();
			$result  = $service->refresh_statuses_from_report();
		} catch ( \Throwable $e ) {
			$logger = new AmazonLogger();
			$logger->error( 'Refresh statuses exception: ' . $e->getMessage(), AmazonLogger::SCOPE_IMPORT );
			wp_send_json_error( array( 'message' => __( 'Error:', 'wc-amazon-sync' ) . ' ' . $e->getMessage() ) );
			return;
		}
		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
			return;
		}
		$updated = isset( $result['updated_count'] ) ? (int) $result['updated_count'] : 0;
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of updated rows */
				__( 'Statuses updated: %d row(s). Reload the page to see changes.', 'wc-amazon-sync' ),
				$updated
			),
			'updated_count' => $updated,
		) );
	}

	/**
	 * AJAX: Clean broken links — check all linked rows; if the WooCommerce product no longer exists (or is trashed), remove the link.
	 */
	public static function ajax_clean_broken_links() {
		check_ajax_referer( 'amazon_clean_broken_links', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_imported_products';
		$linked = $wpdb->get_results(
			"SELECT id, wc_product_id, seller_sku FROM {$table} WHERE link_status = 'linked' AND wc_product_id IS NOT NULL",
			ARRAY_A
		);
		$unlinked_count = 0;
		foreach ( $linked as $row ) {
			$wc_product_id = (int) $row['wc_product_id'];
			if ( ! $wc_product_id ) {
				continue;
			}
			// No WooCommerce: skip (cannot check)
			if ( ! function_exists( 'wc_get_product' ) ) {
				continue;
			}
			$product = wc_get_product( $wc_product_id );
			// Product deleted or invalid → remove link
			if ( ! $product ) {
				$wpdb->update(
					$table,
					array( 'wc_product_id' => null, 'link_status' => 'unlinked', 'link_method' => null ),
					array( 'id' => (int) $row['id'] ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
				$unlinked_count++;
				continue;
			}
			// Product in trash → treat as "not existing", remove link
			$post_status = get_post_status( $wc_product_id );
			if ( $post_status === 'trash' || $post_status === false ) {
				$wpdb->update(
					$table,
					array( 'wc_product_id' => null, 'link_status' => 'unlinked', 'link_method' => null ),
					array( 'id' => (int) $row['id'] ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
				$unlinked_count++;
			}
		}
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of unlinked rows */
				__( 'Geprüft: %d Verknüpfung(en) entfernt (Artikel existiert nicht mehr in WooCommerce). Seite wird neu geladen.', 'wc-amazon-sync' ),
				$unlinked_count
			),
			'unlinked_count' => $unlinked_count,
		) );
	}

	/**
	 * AJAX: List logs
	 */
	public static function ajax_logs_list() {
		check_ajax_referer( 'amazon_logs_list', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}

		$level = isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '';
		$scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : '';
		$limit = isset( $_POST['limit'] ) ? min( 100, max( 1, (int) $_POST['limit'] ) ) : 50;

		$logger = new AmazonLogger();
		$logs   = $logger->get_recent( $limit, $level ?: null, $scope ?: null );
		wp_send_json_success( array( 'logs' => $logs ) );
	}

	/**
	 * AJAX: Clear logs
	 */
	public static function ajax_logs_clear() {
		check_ajax_referer( 'amazon_logs_clear', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}

		$scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : null;
		$logger = new AmazonLogger();
		$logger->clear( $scope );
		wp_send_json_success( array( 'message' => __( 'Logs cleared.', 'wc-amazon-sync' ) ) );
	}

	/**
	 * AJAX: Activate or deactivate sync for an imported product row (intent from button).
	 * Deaktivieren: sends quantity 0 to Amazon, then sets sync_disabled=1.
	 * Aktivieren: sends current quantity to Amazon, then sets sync_disabled=0.
	 */
	public static function ajax_toggle_sync_disabled() {
		check_ajax_referer( 'amazon_toggle_sync_disabled', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$row_id = isset( $_POST['amazon_row_id'] ) ? (int) $_POST['amazon_row_id'] : 0;
		if ( ! $row_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid row.', 'wc-amazon-sync' ) ) );
		}
		$intent = isset( $_POST['intent'] ) && $_POST['intent'] === 'activate' ? 'activate' : 'deactivate';
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_imported_products';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, marketplace_id, seller_sku, wc_product_id, quantity, sync_disabled FROM {$table} WHERE id = %d",
			$row_id
		), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Row not found.', 'wc-amazon-sync' ) ) );
		}
		$seller_sku = isset( $row['seller_sku'] ) ? trim( (string) $row['seller_sku'] ) : '';
		if ( $seller_sku === '' ) {
			wp_send_json_error( array( 'message' => __( 'This product has no seller SKU; cannot update Amazon.', 'wc-amazon-sync' ) ) );
		}
		$api = get_option( 'wc_amazon_sync_api', array() );
		$seller_id     = is_array( $api ) && ! empty( $api['seller_id'] ) ? sanitize_text_field( $api['seller_id'] ) : '';
		$marketplace_id = isset( $row['marketplace_id'] ) ? trim( (string) $row['marketplace_id'] ) : '';
		$issue_locale  = is_array( $api ) && ! empty( $api['issue_locale'] ) ? sanitize_text_field( $api['issue_locale'] ) : 'de_DE';
		if ( $seller_id === '' || $marketplace_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Seller ID or Marketplace not configured.', 'wc-amazon-sync' ) ) );
		}
		$new_disabled = ( $intent === 'deactivate' ) ? 1 : 0;
		$quantity_to_send = $new_disabled ? 0 : self::get_quantity_for_toggle( $row );
		$client = new \WCAmazonSync\Api\ReportsClient( $api );
		$merchant_shipping_group = get_option( 'wc_amazon_sync_merchant_shipping_group', '' );
		$result = $client->patch_listings_item_quantity( $seller_id, $seller_sku, $marketplace_id, $quantity_to_send, $issue_locale, $merchant_shipping_group );
		if ( ! $result['success'] ) {
			wp_send_json_error( array(
				'message' => $new_disabled
					? __( 'Deaktivieren fehlgeschlagen:', 'wc-amazon-sync' ) . ' ' . ( isset( $result['error'] ) ? $result['error'] : '' )
					: __( 'Aktivieren fehlgeschlagen:', 'wc-amazon-sync' ) . ' ' . ( isset( $result['error'] ) ? $result['error'] : '' ),
			) );
			return;
		}
		$wpdb->update(
			$table,
			array( 'sync_disabled' => $new_disabled, 'quantity' => $quantity_to_send ),
			array( 'id' => $row_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
		wp_send_json_success( array(
			'sync_disabled' => $new_disabled,
			'message'       => $new_disabled
				? __( 'An Amazon gesendet: Bestand 0 — Artikel deaktiviert.', 'wc-amazon-sync' )
				: __( 'An Amazon gesendet: Bestand wiederhergestellt — Artikel aktiviert.', 'wc-amazon-sync' ),
		) );
	}

	/**
	 * Get quantity to send to Amazon when activating a row (from Woo if linked, else from DB).
	 *
	 * @param array $row Row with wc_product_id, quantity
	 * @return int
	 */
	private static function get_quantity_for_toggle( array $row ) {
		$wc_product_id = isset( $row['wc_product_id'] ) ? (int) $row['wc_product_id'] : 0;
		if ( $wc_product_id && function_exists( 'wc_get_product' ) && wc_get_product( $wc_product_id ) ) {
			$qty_data = StockSyncHelper::get_quantity_for_linked( $wc_product_id );
			return (int) $qty_data['display_qty'];
		}
		return isset( $row['quantity'] ) && $row['quantity'] !== '' && $row['quantity'] !== null ? max( 0, (int) $row['quantity'] ) : 0;
	}

	/**
	 * AJAX: Link Amazon row to WooCommerce product
	 */
	public static function ajax_link_wc_product() {
		check_ajax_referer( 'amazon_link_wc_product', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$row_id = isset( $_POST['amazon_row_id'] ) ? (int) $_POST['amazon_row_id'] : 0;
		$wc_product_id = isset( $_POST['wc_product_id'] ) ? (int) $_POST['wc_product_id'] : 0;
		$link_method = isset( $_POST['link_method'] ) ? sanitize_text_field( wp_unslash( $_POST['link_method'] ) ) : 'manual';
		if ( ! $row_id || ! $wc_product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'wc-amazon-sync' ) ) );
		}
		if ( ! in_array( $link_method, array( 'sku', 'ean', 'title', 'manual' ), true ) ) {
			$link_method = 'manual';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_imported_products';
		$updated = $wpdb->update(
			$table,
			array( 'wc_product_id' => $wc_product_id, 'link_status' => 'linked', 'link_method' => $link_method ),
			array( 'id' => $row_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		if ( $updated === false ) {
			wp_send_json_error( array( 'message' => __( 'Update failed.', 'wc-amazon-sync' ) ) );
		}
		$edit_url = admin_url( 'post.php?post=' . $wc_product_id . '&action=edit' );
		wp_send_json_success( array( 'wc_product_id' => $wc_product_id, 'link_method' => $link_method, 'edit_url' => $edit_url ) );
	}

	/**
	 * AJAX: Unlink WooCommerce product from Amazon row
	 */
	public static function ajax_unlink_wc_product() {
		check_ajax_referer( 'amazon_unlink_wc_product', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$row_id = isset( $_POST['amazon_row_id'] ) ? (int) $_POST['amazon_row_id'] : 0;
		if ( ! $row_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid row.', 'wc-amazon-sync' ) ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_imported_products';
		$wpdb->update(
			$table,
			array( 'wc_product_id' => null, 'link_status' => 'unlinked', 'link_method' => null ),
			array( 'id' => $row_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		wp_send_json_success( array( 'message' => __( 'Unlinked.', 'wc-amazon-sync' ) ) );
	}

	/**
	 * AJAX: Unlink by seller SKU (finds row by marketplace + seller_sku and unlinks)
	 */
	public static function ajax_unlink_by_sku() {
		check_ajax_referer( 'amazon_unlink_by_sku', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$seller_sku = isset( $_POST['seller_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['seller_sku'] ) ) : '';
		$seller_sku = trim( $seller_sku );
		if ( $seller_sku === '' ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a SKU.', 'wc-amazon-sync' ) ) );
		}
		$api = get_option( 'wc_amazon_sync_api', array() );
		$marketplace_id = is_array( $api ) && ! empty( $api['marketplace_id'] ) ? sanitize_text_field( $api['marketplace_id'] ) : '';
		if ( $marketplace_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Marketplace not configured.', 'wc-amazon-sync' ) ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_imported_products';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE marketplace_id = %s AND seller_sku = %s LIMIT 1",
			$marketplace_id,
			$seller_sku
		), ARRAY_A );
		if ( ! $row || empty( $row['id'] ) ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'No imported product with SKU "%s" found.', 'wc-amazon-sync' ), $seller_sku ) ) );
		}
		$wpdb->update(
			$table,
			array( 'wc_product_id' => null, 'link_status' => 'unlinked', 'link_method' => null ),
			array( 'id' => (int) $row['id'] ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		wp_send_json_success( array(
			'message' => sprintf( __( 'Verknüpfung für SKU %s aufgehoben.', 'wc-amazon-sync' ), $seller_sku ),
		) );
	}

	/**
	 * AJAX: Search WooCommerce products by SKU, title, or EAN
	 */
	public static function ajax_search_wc_products() {
		check_ajax_referer( 'amazon_search_wc_products', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array( 'products' => array() ) );
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$posts = $wpdb->prefix . 'posts';
		$postmeta = $wpdb->prefix . 'postmeta';
		$results = array();
		// By SKU
		$sku_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s",
			$like
		) );
		// By EAN (common meta keys)
		$ean_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$postmeta} WHERE meta_key IN ('_ean', 'ean', '_gtin') AND meta_value LIKE %s",
			$like
		) );
		// By title
		$title_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$posts} WHERE post_type = 'product' AND post_status IN ('publish', 'draft', 'private') AND post_title LIKE %s",
			$like
		) );
		$ids = array_unique( array_merge( $sku_ids ?: array(), $ean_ids ?: array(), $title_ids ?: array() ) );
		$ids = array_slice( $ids, 0, 15 );
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post || $post->post_type !== 'product' ) {
				continue;
			}
			$sku = get_post_meta( $id, '_sku', true );
			$ean = get_post_meta( $id, '_ean', true ) ?: get_post_meta( $id, 'ean', true ) ?: get_post_meta( $id, '_gtin', true );
			$results[] = array(
				'id'    => (int) $id,
				'title' => $post->post_title,
				'sku'   => $sku ?: '—',
				'ean'   => $ean ?: '—',
			);
		}
		wp_send_json_success( array( 'products' => $results ) );
	}

	/**
	 * AJAX: Export preview — build payload and validation for a WC product (for modal).
	 */
	public static function ajax_export_preview() {
		check_ajax_referer( 'amazon_export_preview', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$wc_product_id = isset( $_POST['wc_product_id'] ) ? max( 0, (int) $_POST['wc_product_id'] ) : 0;
		if ( $wc_product_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'wc-amazon-sync' ) ) );
		}
		$builder = new \WCAmazonSync\Services\AmazonExportPayloadBuilder();
		$result = $builder->build( $wc_product_id );
		if ( ! empty( $result['errors'] ) && empty( $result['payload'] ) ) {
			wp_send_json_success( array(
				'success'  => false,
				'preview'  => isset( $result['preview'] ) ? $result['preview'] : array(),
				'errors'   => $result['errors'],
				'warnings'  => isset( $result['warnings'] ) ? $result['warnings'] : array(),
			) );
			return;
		}
		wp_send_json_success( array(
			'success'  => (bool) $result['success'],
			'preview'  => isset( $result['preview'] ) ? $result['preview'] : array(),
			'payload'  => isset( $result['payload'] ) ? $result['payload'] : null,
			'warnings'  => isset( $result['warnings'] ) ? $result['warnings'] : array(),
			'errors'   => isset( $result['errors'] ) ? $result['errors'] : array(),
			'sku'      => isset( $result['sku'] ) ? $result['sku'] : '',
		) );
	}

	/**
	 * AJAX: Export (publish) product to Amazon via putListingsItem.
	 */
	public static function ajax_export_submit() {
		check_ajax_referer( 'amazon_export_submit', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$wc_product_id = isset( $_POST['wc_product_id'] ) ? max( 0, (int) $_POST['wc_product_id'] ) : 0;
		if ( $wc_product_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'wc-amazon-sync' ) ) );
		}
		$api = get_option( 'wc_amazon_sync_api', array() );
		$seller_id = is_array( $api ) && ! empty( $api['seller_id'] ) ? sanitize_text_field( $api['seller_id'] ) : '';
		$marketplace_id = is_array( $api ) && ! empty( $api['marketplace_id'] ) ? sanitize_text_field( $api['marketplace_id'] ) : '';
		$issue_locale = is_array( $api ) && ! empty( $api['issue_locale'] ) ? sanitize_text_field( $api['issue_locale'] ) : 'de_DE';
		if ( $seller_id === '' || $marketplace_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Seller ID or Marketplace ID not configured.', 'wc-amazon-sync' ) ) );
		}
		$builder = new \WCAmazonSync\Services\AmazonExportPayloadBuilder();
		$result = $builder->build( $wc_product_id, $api );
		if ( ! $result['success'] || empty( $result['payload'] ) || empty( $result['sku'] ) ) {
			$errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array( __( 'Cannot build export data. Fix validation errors and try again.', 'wc-amazon-sync' ) );
			wp_send_json_error( array( 'message' => implode( ' ', $errors ) ) );
			return;
		}
		$client = new \WCAmazonSync\Api\ReportsClient( $api );
		$put_result = $client->put_listings_item( $seller_id, $result['sku'], $marketplace_id, $result['payload'], $issue_locale, 'LISTING_OFFER_ONLY' );
		if ( ! $put_result['success'] ) {
			wp_send_json_error( array( 'message' => isset( $put_result['error'] ) ? $put_result['error'] : __( 'Amazon API error.', 'wc-amazon-sync' ) ) );
			return;
		}
		wp_send_json_success( array(
			'message' => __( 'Product sent to Amazon successfully. You can run "Import from Amazon" or "Status aktualisieren" to see the new listing.', 'wc-amazon-sync' ),
		) );
	}
}
