<?php
/**
 * Amazon Categories Page — Kategorie-Zuordnung, Produkt-Overrides, Kategorie-Attribute (tabs).
 * Interface and logic mirrored from wc-kaufland-sync, adapted for Amazon API.
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

use WCAmazonSync\Logger\AmazonLogger;
use WCAmazonSync\Services\AmazonCategoryMappingService;

defined( 'ABSPATH' ) || exit;

/**
 * Categories Page
 */
class CategoriesPage {

	/** @var string */
	private $current_view = 'mapping';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// AJAX handlers are registered in Plugin::init_hooks() so they run on every admin-ajax request
	}

	/**
	 * Enqueue scripts and styles for categories page.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'wc-amazon-sync-categories' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'wc-amazon-sync-categories-admin',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/css/categories-admin.css',
			array(),
			WC_AMAZON_SYNC_VERSION
		);
		// SelectWoo (WooCommerce) or Select2 for searchable Amazon category dropdown
		$select_dep = 'select2';
		if ( class_exists( 'WooCommerce' ) ) {
			wp_enqueue_script( 'selectWoo' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
			$select_dep = 'selectWoo';
		} else {
			wp_enqueue_script( 'select2' );
			wp_enqueue_style( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0' );
		}
		wp_enqueue_script(
			'wc-amazon-sync-categories-admin',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/js/categories-admin.js',
			array( 'jquery', $select_dep ),
			WC_AMAZON_SYNC_VERSION,
			true
		);
		$amazon_categories_for_js = get_option( 'wc_amazon_sync_amazon_categories', array() );
		if ( ! is_array( $amazon_categories_for_js ) ) {
			$amazon_categories_for_js = array();
		}
		wp_localize_script(
			'wc-amazon-sync-categories-admin',
			'wcAmazonSyncCategoriesAdmin',
			array(
				'ajax_url'         => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'wc_amazon_sync_categories' ),
				'placeholder'      => __( 'Kategorie suchen oder auswählen…', 'wc-amazon-sync' ),
				'logs_list_nonce'  => wp_create_nonce( 'amazon_logs_list' ),
				'amazon_categories' => $amazon_categories_for_js,
			)
		);
	}

	/**
	 * Get base URL for categories page (with optional view param).
	 *
	 * @param array $params Query params.
	 * @return string
	 */
	private function get_page_url( $params = array() ) {
		$base = array( 'page' => 'wc-amazon-sync-categories' );
		$params = array_merge( $base, $params );
		return admin_url( 'admin.php?' . http_build_query( $params ) );
	}

	/**
	 * Render the page (tabs + content).
	 */
	public function render() {
		$this->current_view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'mapping';
		if ( ! in_array( $this->current_view, array( 'mapping', 'overrides', 'attributes' ), true ) ) {
			$this->current_view = 'mapping';
		}
		?>
		<div class="wrap wc-amazon-sync-categories">
			<h1><?php esc_html_e( 'Kategorien', 'wc-amazon-sync' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Ordnen Sie WooCommerce-Kategorien den Amazon-Kategorien (Browse Nodes) zu und legen Sie die Standard-Kategorie für den Export fest.', 'wc-amazon-sync' ); ?>
			</p>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $this->get_page_url( array( 'view' => 'mapping' ) ) ); ?>"
				   class="nav-tab <?php echo $this->current_view === 'mapping' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Kategorie-Zuordnung', 'wc-amazon-sync' ); ?>
				</a>
				<a href="<?php echo esc_url( $this->get_page_url( array( 'view' => 'overrides' ) ) ); ?>"
				   class="nav-tab <?php echo $this->current_view === 'overrides' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Produkt-Overrides', 'wc-amazon-sync' ); ?>
				</a>
				<a href="<?php echo esc_url( $this->get_page_url( array( 'view' => 'attributes' ) ) ); ?>"
				   class="nav-tab <?php echo $this->current_view === 'attributes' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Kategorie-Attribute', 'wc-amazon-sync' ); ?>
				</a>
			</nav>

			<?php if ( 'mapping' === $this->current_view ) : ?>
				<?php $this->render_mapping_view(); ?>
			<?php elseif ( 'overrides' === $this->current_view ) : ?>
				<?php $this->render_overrides_view(); ?>
			<?php else : ?>
				<?php $this->render_attributes_view(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Kategorie-Zuordnung tab: WooCommerce categories → Amazon category dropdown, save/delete.
	 */
	private function render_mapping_view() {
		$mapping_service = new AmazonCategoryMappingService();
		$mappings = $mapping_service->get_all_mappings();
		$mappings_map = array();
		foreach ( $mappings as $m ) {
			$mappings_map[ (int) $m['woo_category_id'] ] = $m['default_amazon_category_id'];
		}

		$woo_categories = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );
		if ( is_wp_error( $woo_categories ) ) {
			$woo_categories = array();
		}

		$amazon_categories = get_option( 'wc_amazon_sync_amazon_categories', array() );
		if ( ! is_array( $amazon_categories ) ) {
			$amazon_categories = array();
		}
		$categories_count   = count( $amazon_categories );
		$last_sync_at       = get_option( 'wc_amazon_sync_categories_last_sync_at', 0 );
		$categories_debug   = get_option( 'wc_amazon_sync_categories_debug', array() );
		$import_log_url     = admin_url( 'admin.php?page=wc-amazon-sync&tab=import' );
		$logs_page_url      = admin_url( 'admin.php?page=wc-amazon-sync-logs' );

		$filter = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'all';
		$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		?>
		<?php
		$categories_source = get_option( 'wc_amazon_sync_categories_source', 'product_types' );
		if ( ! in_array( $categories_source, array( 'product_types', 'browse_tree' ), true ) ) {
			$categories_source = 'product_types';
		}
		?>
		<div style="margin: 20px 0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
			<label for="wc-amazon-sync-categories-source" class="screen-reader-text"><?php esc_html_e( 'Kategoriequelle', 'wc-amazon-sync' ); ?></label>
			<select id="wc-amazon-sync-categories-source" name="wc_amazon_sync_categories_source" style="min-width: 280px;">
				<option value="product_types" <?php selected( $categories_source, 'product_types' ); ?>><?php esc_html_e( 'Nur Produkttypen (z. B. Fisch, Getränk)', 'wc-amazon-sync' ); ?></option>
				<option value="browse_tree" <?php selected( $categories_source, 'browse_tree' ); ?>><?php esc_html_e( 'Mit Unterkategorien (Browse Tree, z. B. Lebensmittel > Fisch > Forelle)', 'wc-amazon-sync' ); ?></option>
			</select>
			<button type="button" id="wc-amazon-sync-sync-woo-categories" class="button">
				<?php esc_html_e( 'WooCommerce-Kategorien synchronisieren', 'wc-amazon-sync' ); ?>
			</button>
			<button type="button" id="wc-amazon-sync-refresh-amazon-categories" class="button">
				<?php esc_html_e( 'Amazon-Kategorien aktualisieren', 'wc-amazon-sync' ); ?>
			</button>
			<input type="text" id="wc-amazon-sync-category-search" placeholder="<?php esc_attr_e( 'Kategorie suchen…', 'wc-amazon-sync' ); ?>"
				   value="<?php echo esc_attr( $search ); ?>" class="regular-text" style="min-width: 200px;" />
			<a href="<?php echo esc_url( $this->get_page_url( array( 'view' => 'mapping', 'filter' => 'all' ) ) ); ?>"
			   class="button <?php echo 'all' === $filter ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Alle', 'wc-amazon-sync' ); ?></a>
			<a href="<?php echo esc_url( $this->get_page_url( array( 'view' => 'mapping', 'filter' => 'unmapped' ) ) ); ?>"
			   class="button <?php echo 'unmapped' === $filter ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Nur nicht zugeordnet', 'wc-amazon-sync' ); ?></a>
			<a href="<?php echo esc_url( $this->get_page_url( array( 'view' => 'mapping', 'filter' => 'mapped' ) ) ); ?>"
			   class="button <?php echo 'mapped' === $filter ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Nur zugeordnet', 'wc-amazon-sync' ); ?></a>
		</div>
		<?php if ( $categories_count === 0 && $last_sync_at > 0 ) : ?>
		<p class="notice notice-warning" style="margin: 10px 0;">
			<?php esc_html_e( '0 Amazon-Kategorien empfangen.', 'wc-amazon-sync' ); ?>
			<button type="button" class="button button-small" id="wc-amazon-sync-show-debug-categories"><?php esc_html_e( 'Debug anzeigen', 'wc-amazon-sync' ); ?></button>
		</p>
		<?php endif; ?>
		<p class="description" style="margin: 4px 0;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: count, 2: last sync time or "—" */
					__( 'Gespeicherte Amazon-Kategorien: %1$d. Letzte Synchronisation: %2$s.', 'wc-amazon-sync' ),
					$categories_count,
					$last_sync_at > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync_at ) : '—'
				)
			);
			?>
		</p>
		<div id="wc-amazon-sync-categories-debug-panel" class="wc-amazon-sync-debug-panel" style="margin: 12px 0; border: 1px solid #c3c4c7; background: #f6f7f7; border-radius: 4px;">
			<button type="button" class="button button-small" id="wc-amazon-sync-categories-debug-toggle" aria-expanded="false" style="margin: 8px 12px;">
				<span class="dashicons dashicons-arrow-right-alt2" style="vertical-align: middle;"></span>
				<?php esc_html_e( 'Debug: Letzter Lauf', 'wc-amazon-sync' ); ?>
			</button>
			<div id="wc-amazon-sync-categories-debug-content" style="display: none; padding: 12px; border-top: 1px solid #c3c4c7; font-family: monospace; font-size: 12px; max-height: 320px; overflow-y: auto;">
				<?php
				$status   = isset( $categories_debug['last_run_status'] ) ? $categories_debug['last_run_status'] : '—';
				$endpoint = isset( $categories_debug['endpoint_called'] ) ? $categories_debug['endpoint_called'] : '—';
				$mid      = isset( $categories_debug['marketplaceId'] ) ? $categories_debug['marketplaceId'] : '—';
				$reg      = isset( $categories_debug['region'] ) ? $categories_debug['region'] : '—';
				$sid      = isset( $categories_debug['sellerId'] ) ? $categories_debug['sellerId'] : '—';
				$http     = isset( $categories_debug['http_status'] ) ? $categories_debug['http_status'] : '—';
				$rsize    = isset( $categories_debug['response_size'] ) ? $categories_debug['response_size'] : '—';
				$err      = isset( $categories_debug['error_message'] ) && $categories_debug['error_message'] !== '' ? $categories_debug['error_message'] : '—';
				$keys     = isset( $categories_debug['response_top_level_keys'] ) && is_array( $categories_debug['response_top_level_keys'] ) ? implode( ', ', $categories_debug['response_top_level_keys'] ) : '—';
				$cnt      = isset( $categories_debug['count_parsed'] ) ? $categories_debug['count_parsed'] : '—';
				?>
				<table class="widefat striped" style="margin: 0; font-size: 12px;">
					<tr><th style="width: 180px;"><?php esc_html_e( 'Last run status', 'wc-amazon-sync' ); ?></th><td><?php echo esc_html( $status ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Endpoint called', 'wc-amazon-sync' ); ?></th><td><code><?php echo esc_html( $endpoint ); ?></code></td></tr>
					<tr><th>marketplaceId</th><td><code><?php echo esc_html( $mid ); ?></code></td></tr>
					<tr><th>region</th><td><code><?php echo esc_html( $reg ); ?></code></td></tr>
					<tr><th>sellerId</th><td><code><?php echo esc_html( $sid ); ?></code></td></tr>
					<tr><th>HTTP status</th><td><?php echo esc_html( (string) $http ); ?></td></tr>
					<tr><th>Response size</th><td><?php echo esc_html( (string) $rsize ); ?></td></tr>
					<tr><th>Response top-level keys</th><td><code><?php echo esc_html( $keys ); ?></code></td></tr>
					<tr><th>Count parsed</th><td><?php echo esc_html( (string) $cnt ); ?></td></tr>
					<tr><th><?php esc_html_e( 'If error', 'wc-amazon-sync' ); ?></th><td style="color: #b32d2e;"><?php echo esc_html( $err ); ?></td></tr>
				</table>
				<p style="margin: 10px 0 0;"><strong><?php esc_html_e( 'Vollständige Logs', 'wc-amazon-sync' ); ?></strong>
					<a href="<?php echo esc_url( $logs_page_url ); ?>"><?php esc_html_e( 'Amazon Sync → Logs', 'wc-amazon-sync' ); ?></a>
					<?php esc_html_e( '(alle API-Aufrufe, Kategorien, Import, Test)', 'wc-amazon-sync' ); ?>
				</p>
				<pre id="wc-amazon-sync-categories-debug-logs" style="display:none; margin: 0; padding: 8px; background: #1e1e1e; color: #d4d4d4; white-space: pre-wrap; word-break: break-all;"></pre>
			</div>
		</div>

		<table class="wp-list-table widefat striped wc-amazon-sync-categories-table">
			<thead>
				<tr>
					<th class="col-woo-cat"><?php esc_html_e( 'WooCommerce-Kategorie', 'wc-amazon-sync' ); ?></th>
					<th class="col-amazon-cat"><?php esc_html_e( 'Standard-Amazon-Kategorie', 'wc-amazon-sync' ); ?></th>
					<th class="col-status"><?php esc_html_e( 'Status', 'wc-amazon-sync' ); ?></th>
					<th class="col-actions"><?php esc_html_e( 'Aktionen', 'wc-amazon-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $woo_categories ) ) : ?>
					<tr>
						<td colspan="4">
							<p><?php esc_html_e( 'Keine WooCommerce-Kategorien gefunden.', 'wc-amazon-sync' ); ?></p>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $woo_categories as $cat ) : ?>
						<?php
						$cat_id = (int) $cat->term_id;
						if ( 'unmapped' === $filter && isset( $mappings_map[ $cat_id ] ) ) {
							continue;
						}
						if ( 'mapped' === $filter && ! isset( $mappings_map[ $cat_id ] ) ) {
							continue;
						}
						if ( $search !== '' && stripos( $cat->name, $search ) === false ) {
							continue;
						}
						$default_mapping = isset( $mappings_map[ $cat_id ] ) ? $mappings_map[ $cat_id ] : '';
						$status = $default_mapping ? 'mapped' : 'not_mapped';
						?>
						<tr data-category-id="<?php echo esc_attr( $cat_id ); ?>">
							<td class="col-woo-cat">
								<strong><?php echo esc_html( $cat->name ); ?></strong><br>
								<small>ID: <?php echo esc_html( $cat_id ); ?></small>
							</td>
							<td class="col-amazon-cat">
								<select class="wc-amazon-sync-default-category-select" data-category-id="<?php echo esc_attr( $cat_id ); ?>">
									<option value=""><?php esc_html_e( '— Auswählen —', 'wc-amazon-sync' ); ?></option>
									<?php
									// Only output the selected option to keep DOM small (full list is in JS via wp_localize_script)
									if ( $default_mapping !== '' && isset( $amazon_categories[ $default_mapping ] ) ) {
										$label = is_string( $amazon_categories[ $default_mapping ] ) ? $amazon_categories[ $default_mapping ] : $default_mapping;
										?>
										<option value="<?php echo esc_attr( $default_mapping ); ?>" selected><?php echo esc_html( $label ); ?></option>
									<?php } ?>
								</select>
							</td>
							<td>
								<?php if ( 'mapped' === $status ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color: green;"></span>
									<?php esc_html_e( 'Zugeordnet', 'wc-amazon-sync' ); ?>
								<?php else : ?>
									<span class="dashicons dashicons-dismiss" style="color: #999;"></span>
									<?php esc_html_e( 'Nicht zugeordnet', 'wc-amazon-sync' ); ?>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="button button-small wc-amazon-sync-clear-mapping-btn" data-category-id="<?php echo esc_attr( $cat_id ); ?>">
									<?php esc_html_e( 'Zuordnung löschen', 'wc-amazon-sync' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Produkt-Overrides tab (placeholder).
	 */
	private function render_overrides_view() {
		echo '<p>' . esc_html__( 'Produkt-Overrides: Hier können Sie für einzelne Produkte eine andere Amazon-Kategorie festlegen. (In Kürze.)', 'wc-amazon-sync' ) . '</p>';
	}

	/**
	 * Render Kategorie-Attribute tab (placeholder).
	 */
	private function render_attributes_view() {
		echo '<p>' . esc_html__( 'Kategorie-Attribute: Zusätzliche Attribute pro Kategorie für den Amazon-Export. (In Kürze.)', 'wc-amazon-sync' ) . '</p>';
	}

	/**
	 * AJAX: Save mapping (Woo category → Amazon category). Registered in Plugin::init_hooks().
	 */
	public static function ajax_save_mapping() {
		check_ajax_referer( 'wc_amazon_sync_categories', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$woo_id = isset( $_POST['woo_category_id'] ) ? (int) $_POST['woo_category_id'] : 0;
		$amazon_id = isset( $_POST['amazon_category_id'] ) ? sanitize_text_field( wp_unslash( $_POST['amazon_category_id'] ) ) : '';
		if ( ! $woo_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid WooCommerce category.', 'wc-amazon-sync' ) ) );
		}
		if ( $amazon_id === '' ) {
			( new AmazonCategoryMappingService() )->delete_mapping( $woo_id );
			wp_send_json_success( array( 'message' => __( 'Zuordnung gelöscht.', 'wc-amazon-sync' ) ) );
			return;
		}
		$service = new AmazonCategoryMappingService();
		$ok = $service->set_default_mapping( $woo_id, $amazon_id );
		if ( $ok ) {
			wp_send_json_success( array( 'message' => __( 'Zuordnung gespeichert.', 'wc-amazon-sync' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Speichern fehlgeschlagen.', 'wc-amazon-sync' ) ) );
		}
	}

	/**
	 * AJAX: Delete mapping. Registered in Plugin::init_hooks().
	 */
	public static function ajax_delete_mapping() {
		check_ajax_referer( 'wc_amazon_sync_categories', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$woo_id = isset( $_POST['woo_category_id'] ) ? (int) $_POST['woo_category_id'] : 0;
		if ( ! $woo_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid category.', 'wc-amazon-sync' ) ) );
		}
		( new AmazonCategoryMappingService() )->delete_mapping( $woo_id );
		wp_send_json_success( array( 'message' => __( 'Zuordnung gelöscht.', 'wc-amazon-sync' ) ) );
	}

	/**
	 * AJAX: Refresh Amazon categories (Product Type Definitions API productTypes first; fallback Browse Tree Report).
	 * Full observability: logging, debug option, response with debug for panel. Registered in Plugin::init_hooks().
	 */
	public static function ajax_refresh_amazon_categories() {
		check_ajax_referer( 'wc_amazon_sync_categories', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		$logger = new AmazonLogger();
		$logger->info( 'Amazon-Kategorien-Sync gestartet (Anforderung empfangen)', AmazonLogger::SCOPE_CATEGORIES, array( 'step' => 'start' ) );

		$api = get_option( 'wc_amazon_sync_api', array() );
		if ( ! is_array( $api ) ) {
			$api = array();
		}
		$marketplace_id = ! empty( $api['marketplace_id'] ) ? sanitize_text_field( $api['marketplace_id'] ) : '';
		$region         = isset( $api['region'] ) ? sanitize_text_field( $api['region'] ) : 'eu-west-1';
		$seller_id      = isset( $api['seller_id'] ) ? sanitize_text_field( $api['seller_id'] ) : '';
		$sp_api_host    = \WCAmazonSync\Api\ReportsClient::base_url_for_region( $region );
		// Locale for category names (Product Types API). Use issue_locale from API settings or default de_DE for German.
		$locale = ! empty( $api['issue_locale'] ) ? sanitize_text_field( $api['issue_locale'] ) : 'de_DE';

		$debug  = array(
			'endpoint_called' => '',
			'marketplaceId'  => $marketplace_id,
			'region'         => $region,
			'sellerId'      => $seller_id,
			'spApiHost'      => $sp_api_host,
			'http_status'    => null,
			'response_size'   => null,
			'error_code'     => null,
			'error_message'  => null,
			'response_top_level_keys' => array(),
			'count_parsed'   => 0,
		);

		$logger->info( 'Amazon-Kategorien-Sync: Einstellungen geladen', AmazonLogger::SCOPE_CATEGORIES, array(
			'marketplaceId' => $marketplace_id ?: '(leer)',
			'region'        => $region,
			'spApiHost'     => $sp_api_host,
		) );

		if ( $marketplace_id === '' ) {
			$logger->error( 'Amazon-Kategorien-Sync: Marketplace-ID fehlt in den API-Einstellungen', AmazonLogger::SCOPE_CATEGORIES );
			self::save_categories_debug( 'Fail', $debug, __( 'Marketplace ID in den API-Einstellungen eintragen.', 'wc-amazon-sync' ) );
			$log_entries = $logger->get_recent( 50, null, AmazonLogger::SCOPE_CATEGORIES );
			wp_send_json_error( array(
				'message'     => __( 'Marketplace ID in den API-Einstellungen eintragen.', 'wc-amazon-sync' ),
				'debug'       => get_option( 'wc_amazon_sync_categories_debug', array() ),
				'log_entries' => is_array( $log_entries ) ? $log_entries : array(),
			) );
		}

		$client = new \WCAmazonSync\Api\ReportsClient( $api );
		$logger->info( 'Amazon-Kategorien-Sync: LWA-Token wird angefordert…', AmazonLogger::SCOPE_CATEGORIES );
		$token  = $client->get_access_token();
		if ( $token ) {
			$logger->info( 'Amazon-Kategorien-Sync: LWA-Token erhalten', AmazonLogger::SCOPE_CATEGORIES );
		} else {
			$logger->error( 'Amazon-Kategorien-Sync: LWA-Token fehlgeschlagen (Client ID, Client Secret oder Refresh Token prüfen)', AmazonLogger::SCOPE_CATEGORIES );
			$debug['error_message'] = __( 'Kein Zugriffstoken (LWA). Prüfen Sie Client ID, Client Secret und Refresh Token in den Einstellungen.', 'wc-amazon-sync' );
			self::save_categories_debug( 'Fail', $debug, $debug['error_message'] );
			wp_send_json_error( array(
				'message'     => $debug['error_message'],
				'debug'       => get_option( 'wc_amazon_sync_categories_debug', array() ),
				'log_entries' => $logger->get_recent( 50, null, AmazonLogger::SCOPE_CATEGORIES ),
			) );
		}

		$categories_source = isset( $_POST['categories_source'] ) ? sanitize_text_field( wp_unslash( $_POST['categories_source'] ) ) : 'product_types';
		if ( ! in_array( $categories_source, array( 'product_types', 'browse_tree' ), true ) ) {
			$categories_source = 'product_types';
		}
		update_option( 'wc_amazon_sync_categories_source', $categories_source );

		$categories = array();

		if ( $categories_source === 'browse_tree' ) {
			$logger->info( 'Amazon-Kategorien-Sync: Browse-Tree-Report wird aufgerufen (Unterkategorien mit Pfad)', AmazonLogger::SCOPE_CATEGORIES );
			$debug_bt = array();
			$result_bt = $client->fetch_browse_tree_categories( $marketplace_id, 180, $debug_bt );
			if ( is_array( $debug_bt ) ) {
				$debug['endpoint_called'] = '/reports/2021-06-30/reports (GET_XML_BROWSE_TREE_DATA)';
				$debug['http_status']    = isset( $debug_bt['step1_create_report']['http_status'] ) ? $debug_bt['step1_create_report']['http_status'] : null;
				$debug['response_size']  = isset( $debug_bt['step3_document']['raw_body_length'] ) ? $debug_bt['step3_document']['raw_body_length'] : null;
				$debug['response_top_level_keys'] = array( 'browse_tree' );
			}
			if ( ! empty( $result_bt['success'] ) && isset( $result_bt['categories'] ) && is_array( $result_bt['categories'] ) && count( $result_bt['categories'] ) > 0 ) {
				$categories = $result_bt['categories'];
				$debug['count_parsed'] = count( $categories );
				$debug['error_message'] = null;
				$logger->info( 'Amazon-Kategorien-Sync: Browse-Tree erfolgreich, ' . count( $categories ) . ' Kategorien (mit Unterkategorien)', AmazonLogger::SCOPE_CATEGORIES, array( 'count' => count( $categories ) ) );
			} else {
				$err_bt = ! empty( $result_bt['error'] ) ? $result_bt['error'] : __( 'Browse-Tree-Report lieferte keine Daten.', 'wc-amazon-sync' );
				$debug['error_message'] = $err_bt;
				$logger->warn( 'Amazon-Kategorien-Sync: Browse-Tree fehlgeschlagen, Fallback auf Product-Types', AmazonLogger::SCOPE_CATEGORIES, array( 'error' => $err_bt ) );
			}
		}

		if ( empty( $categories ) ) {
			$logger->info( 'Amazon-Kategorien-Sync: Product-Types-API wird aufgerufen (Marketplace ' . $marketplace_id . ', Locale ' . $locale . ')', AmazonLogger::SCOPE_CATEGORIES );
			$result = $client->search_product_types_list( $marketplace_id, $debug, $locale );

			if ( is_array( $debug ) ) {
				$debug['endpoint_called'] = '/definitions/2020-09-01/productTypes';
				$debug['http_status']     = isset( $debug['http_status'] ) ? $debug['http_status'] : null;
				$debug['response_size']   = isset( $debug['response_size'] ) ? $debug['response_size'] : null;
				$debug['response_top_level_keys'] = isset( $debug['response_top_level_keys'] ) ? $debug['response_top_level_keys'] : array();
				$debug['count_parsed']   = isset( $debug['count_parsed'] ) ? $debug['count_parsed'] : 0;
			}

			$http_status = isset( $debug['http_status'] ) ? $debug['http_status'] : null;
			$count_parsed = isset( $debug['count_parsed'] ) ? (int) $debug['count_parsed'] : 0;
			$logger->info( 'Amazon-Kategorien-Sync: Product-Types-API Antwort', AmazonLogger::SCOPE_CATEGORIES, array(
				'http_status'   => $http_status,
				'response_size' => isset( $debug['response_size'] ) ? $debug['response_size'] : null,
				'count_parsed'  => $count_parsed,
				'top_level_keys'=> isset( $debug['response_top_level_keys'] ) ? $debug['response_top_level_keys'] : array(),
			) );

			if ( ! empty( $result['success'] ) && isset( $result['categories'] ) && is_array( $result['categories'] ) ) {
				$categories = $result['categories'];
				$logger->info( 'Amazon-Kategorien-Sync: Product-Types-API erfolgreich, ' . count( $categories ) . ' Kategorien', AmazonLogger::SCOPE_CATEGORIES );
			}
			if ( ! empty( $result['error'] ) ) {
				$debug['error_message'] = $result['error'];
				$logger->error( 'Amazon-Kategorien-Sync: Product-Types-API Fehler', AmazonLogger::SCOPE_CATEGORIES, array( 'error' => $result['error'] ) );
			}

			if ( empty( $categories ) && $categories_source !== 'browse_tree' ) {
				$logger->info( 'Amazon-Kategorien-Sync: Fallback Browse-Tree-Report wird aufgerufen…', AmazonLogger::SCOPE_CATEGORIES );
				$debug_bt = array();
				$result_bt = $client->fetch_browse_tree_categories( $marketplace_id, 120, $debug_bt );
				$logger->info( 'Amazon-Kategorien-Sync: Browse-Tree-Report Antwort', AmazonLogger::SCOPE_CATEGORIES, array(
					'create_report_http_status' => isset( $debug_bt['step1_create_report']['http_status'] ) ? $debug_bt['step1_create_report']['http_status'] : null,
				) );
				if ( ! empty( $result_bt['success'] ) && isset( $result_bt['categories'] ) && is_array( $result_bt['categories'] ) ) {
					$categories = $result_bt['categories'];
					$debug['count_parsed']   = count( $categories );
					$debug['endpoint_called'] = '/reports/2021-06-30/reports (GET_XML_BROWSE_TREE_DATA)';
					$debug['error_message'] = null;
					$logger->info( 'Amazon-Kategorien-Sync: Browse-Tree-Fallback erfolgreich, ' . count( $categories ) . ' Kategorien', AmazonLogger::SCOPE_CATEGORIES );
				} else {
					$logger->warn( 'Amazon-Kategorien-Sync: Browse-Tree-Fallback lieferte keine Kategorien', AmazonLogger::SCOPE_CATEGORIES );
				}
			}
		}

		$logger->info( 'Amazon-Kategorien-Sync abgeschlossen: ' . count( $categories ) . ' Kategorien gespeichert', AmazonLogger::SCOPE_CATEGORIES, array( 'count' => count( $categories ) ) );

		update_option( 'wc_amazon_sync_amazon_categories', $categories );
		update_option( 'wc_amazon_sync_categories_last_sync_at', time() );
		self::save_categories_debug( empty( $categories ) ? 'Fail' : 'Success', $debug, $debug['error_message'] );

		$log_entries = $logger->get_recent( 50, null, AmazonLogger::SCOPE_CATEGORIES );
		$last_debug  = get_option( 'wc_amazon_sync_categories_debug', array() );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of categories */
				__( 'Amazon-Kategorien geladen: %d Einträge.', 'wc-amazon-sync' ),
				count( $categories )
			),
			'count'       => count( $categories ),
			'reload'      => true,
			'debug'       => $last_debug,
			'log_entries' => is_array( $log_entries ) ? $log_entries : array(),
		) );
	}

	/**
	 * Save last run debug for Categories page panel (no secrets).
	 *
	 * @param string     $status  Success|Fail
	 * @param array      $debug   Endpoint, marketplaceId, region, http_status, etc.
	 * @param string|null $error_message Error message if any
	 */
	private static function save_categories_debug( $status, array $debug, $error_message = null ) {
		$stored = array(
			'last_run_status' => $status,
			'last_run_at'     => time(),
			'endpoint_called' => isset( $debug['endpoint_called'] ) ? $debug['endpoint_called'] : '',
			'marketplaceId'  => isset( $debug['marketplaceId'] ) ? $debug['marketplaceId'] : '',
			'region'         => isset( $debug['region'] ) ? $debug['region'] : '',
			'sellerId'       => isset( $debug['sellerId'] ) ? $debug['sellerId'] : '',
			'http_status'    => isset( $debug['http_status'] ) ? $debug['http_status'] : null,
			'response_size'   => isset( $debug['response_size'] ) ? $debug['response_size'] : null,
			'error_message'  => $error_message !== null ? $error_message : ( isset( $debug['error_message'] ) ? $debug['error_message'] : null ),
			'response_top_level_keys' => isset( $debug['response_top_level_keys'] ) ? $debug['response_top_level_keys'] : array(),
			'count_parsed'   => isset( $debug['count_parsed'] ) ? $debug['count_parsed'] : 0,
		);
		update_option( 'wc_amazon_sync_categories_debug', $stored );
	}
}
