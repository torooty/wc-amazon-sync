<?php
/**
 * Settings Page - Amazon Sync with tabs
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

use WCAmazonSync\Logger\AmazonLogger;
use WCAmazonSync\Services\StockSyncHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Settings Page
 */
class SettingsPage {

	/**
	 * @var SettingsPage|null
	 */
	private static $instance = null;

	/**
	 * Current tab
	 *
	 * @var string
	 */
	private $current_tab = 'api';

	/**
	 * Get singleton instance
	 *
	 * @return SettingsPage
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_amazon_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wc_amazon_sync_manual_stock_sync', array( $this, 'ajax_manual_stock_sync' ) );
		add_action( 'wp_ajax_wc_amazon_sync_get_shipping_templates', array( $this, 'ajax_get_shipping_templates' ) );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting( 'wc_amazon_sync_api', 'wc_amazon_sync_api', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_api_settings' ),
		) );
		register_setting( 'wc_amazon_sync_sync', 'wc_amazon_sync_sync', array(
			'type' => 'array',
		) );
		register_setting( 'wc_amazon_sync_sync', 'wc_amazon_sync_use_standard_price', array( 'type' => 'boolean' ) );
		register_setting( 'wc_amazon_sync_sync', 'wc_amazon_sync_price_markup_percent', array( 'type' => 'number' ) );
		register_setting( 'wc_amazon_sync_sync', 'wc_amazon_sync_round_to_nice_price', array( 'type' => 'boolean' ) );
		register_setting( 'wc_amazon_sync_sync', 'wc_amazon_sync_price_sync_enabled', array( 'type' => 'boolean' ) );
		register_setting( 'wc_amazon_sync_sync', 'wc_amazon_sync_price_sync_frequency', array( 'type' => 'string' ) );
		register_setting( 'wc_amazon_sync_sync', 'wc_amazon_sync_max_stock_quantity', array( 'type' => 'number' ) );
		register_setting( 'wc_amazon_sync_sync', 'wc_amazon_sync_stock_sync_enabled', array( 'type' => 'boolean' ) );
		register_setting( 'wc_amazon_sync_sync', 'wc_amazon_sync_stock_sync_frequency', array( 'type' => 'string' ) );
		register_setting( 'wc_amazon_sync_ui', 'wc_amazon_sync_ui', array(
			'type' => 'array',
		) );
	}

	/**
	 * Sanitize API settings
	 *
	 * @param array $input Raw input
	 * @return array
	 */
	public function sanitize_api_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return get_option( 'wc_amazon_sync_api', array() );
		}
		$out = array(
			'region'         => isset( $input['region'] ) ? sanitize_text_field( $input['region'] ) : 'eu-west-1',
			'seller_id'      => isset( $input['seller_id'] ) ? sanitize_text_field( $input['seller_id'] ) : '',
			'marketplace_id' => isset( $input['marketplace_id'] ) ? sanitize_text_field( $input['marketplace_id'] ) : '',
			'client_id'      => isset( $input['client_id'] ) ? sanitize_text_field( $input['client_id'] ) : '',
			'client_secret'  => isset( $input['client_secret'] ) ? $input['client_secret'] : '',
			'refresh_token'  => isset( $input['refresh_token'] ) ? $input['refresh_token'] : '',
			'use_sandbox'    => ! empty( $input['use_sandbox'] ),
		);
		$saved = get_option( 'wc_amazon_sync_api', array() );
		if ( is_array( $saved ) && empty( $out['client_secret'] ) && ! empty( $saved['client_secret'] ) ) {
			$out['client_secret'] = $saved['client_secret'];
		}
		if ( is_array( $saved ) && empty( $out['refresh_token'] ) && ! empty( $saved['refresh_token'] ) ) {
			$out['refresh_token'] = $saved['refresh_token'];
		}
		return $out;
	}

	/**
	 * Handle form submission (Save Changes)
	 */
	public function handle_form_submission() {
		if ( ! isset( $_GET['page'] ) || 'wc-amazon-sync' !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_POST['wc_amazon_sync_settings_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_settings_nonce'] ) ), 'wc_amazon_sync_settings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tab = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( $_POST['tab'] ) ) : ( isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'api' );
		if ( 'general' === $tab ) {
			$forced_language = isset( $_POST['wc_amazon_sync_forced_language'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_forced_language'] ) ) : 'auto';
			if ( in_array( $forced_language, array( 'auto', 'en_US', 'de_DE', 'ru_RU' ), true ) ) {
				update_option( 'wc_amazon_sync_forced_language', $forced_language );
			}
			if ( isset( $_POST['wc_amazon_sync_merchant_shipping_group'] ) ) {
				update_option( 'wc_amazon_sync_merchant_shipping_group', sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_merchant_shipping_group'] ) ) );
			}
		}
		if ( 'sync' === $tab ) {
			update_option( 'wc_amazon_sync_use_standard_price', isset( $_POST['wc_amazon_sync_use_standard_price'] ) );
			$markup = isset( $_POST['wc_amazon_sync_price_markup_percent'] ) ? floatval( $_POST['wc_amazon_sync_price_markup_percent'] ) : 10;
			update_option( 'wc_amazon_sync_price_markup_percent', $markup );
			update_option( 'wc_amazon_sync_round_to_nice_price', isset( $_POST['wc_amazon_sync_round_to_nice_price'] ) );
			update_option( 'wc_amazon_sync_price_sync_enabled', isset( $_POST['wc_amazon_sync_price_sync_enabled'] ) );
			$price_freq = isset( $_POST['wc_amazon_sync_price_sync_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_price_sync_frequency'] ) ) : 'daily';
			update_option( 'wc_amazon_sync_price_sync_frequency', $price_freq );
			$max_stock = isset( $_POST['wc_amazon_sync_max_stock_quantity'] ) ? max( 0, (int) $_POST['wc_amazon_sync_max_stock_quantity'] ) : 500;
			update_option( 'wc_amazon_sync_max_stock_quantity', $max_stock );
			update_option( 'wc_amazon_sync_stock_sync_enabled', isset( $_POST['wc_amazon_sync_stock_sync_enabled'] ) );
			$stock_freq = isset( $_POST['wc_amazon_sync_stock_sync_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_stock_sync_frequency'] ) ) : '4hours';
			update_option( 'wc_amazon_sync_stock_sync_frequency', $stock_freq );
			\WCAmazonSync\StockSyncCron::reschedule();
		}
		if ( 'api' === $tab ) {
			$api = get_option( 'wc_amazon_sync_api', array() );
			if ( ! is_array( $api ) ) {
				$api = array();
			}
			$saved_before = get_option( 'wc_amazon_sync_api', array() );
			if ( ! is_array( $saved_before ) ) {
				$saved_before = array();
			}
			if ( isset( $_POST['wc_amazon_sync_region'] ) ) {
				$api['region'] = sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_region'] ) );
			}
			if ( isset( $_POST['wc_amazon_sync_seller_id'] ) ) {
				$api['seller_id'] = sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_seller_id'] ) );
			}
			if ( isset( $_POST['wc_amazon_sync_marketplace_id'] ) ) {
				$api['marketplace_id'] = sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_marketplace_id'] ) );
			}
			if ( isset( $_POST['wc_amazon_sync_issue_locale'] ) ) {
				$locale = sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_issue_locale'] ) );
				if ( in_array( $locale, array( 'de_DE', 'en_US', 'en_GB', 'fr_FR', 'it_IT', 'es_ES', 'tr_TR', 'ja_JP', 'pt_BR', 'zh_CN' ), true ) ) {
					$api['issue_locale'] = $locale;
				} else {
					$api['issue_locale'] = 'de_DE';
				}
			}
			if ( isset( $_POST['wc_amazon_sync_client_id'] ) ) {
				$api['client_id'] = sanitize_text_field( wp_unslash( $_POST['wc_amazon_sync_client_id'] ) );
			}
			$new_secret = isset( $_POST['wc_amazon_sync_client_secret'] ) ? trim( (string) wp_unslash( $_POST['wc_amazon_sync_client_secret'] ) ) : '';
			if ( $new_secret !== '' ) {
				$api['client_secret'] = $new_secret;
			} elseif ( isset( $saved_before['client_secret'] ) && $saved_before['client_secret'] !== '' ) {
				$api['client_secret'] = $saved_before['client_secret'];
			}
			$new_token = isset( $_POST['wc_amazon_sync_refresh_token'] ) ? trim( (string) wp_unslash( $_POST['wc_amazon_sync_refresh_token'] ) ) : '';
			if ( $new_token !== '' ) {
				$api['refresh_token'] = $new_token;
			} elseif ( isset( $saved_before['refresh_token'] ) && $saved_before['refresh_token'] !== '' ) {
				$api['refresh_token'] = $saved_before['refresh_token'];
			}
			$api['use_sandbox'] = isset( $_POST['wc_amazon_sync_use_sandbox'] );
			update_option( 'wc_amazon_sync_api', $api );

			$credential_keys = array( 'client_id', 'client_secret', 'refresh_token', 'region', 'use_sandbox' );
			foreach ( $credential_keys as $key ) {
				$before = isset( $saved_before[ $key ] ) ? $saved_before[ $key ] : null;
				$after  = isset( $api[ $key ] ) ? $api[ $key ] : null;
				if ( $before !== $after ) {
					delete_transient( 'wc_amazon_sync_access_token' );
					break;
				}
			}

			set_transient( 'wc_amazon_sync_save_result', array(
				'secret_saved'  => ! empty( $api['client_secret'] ),
				'secret_len'    => isset( $api['client_secret'] ) ? strlen( $api['client_secret'] ) : 0,
				'secret_suffix' => ! empty( $api['client_secret'] ) && strlen( $api['client_secret'] ) >= 8
					? substr( $api['client_secret'], -8 )
					: '',
				'token_saved'   => ! empty( $api['refresh_token'] ),
				'token_len'     => isset( $api['refresh_token'] ) ? strlen( $api['refresh_token'] ) : 0,
				'token_suffix'  => ! empty( $api['refresh_token'] ) && strlen( $api['refresh_token'] ) >= 8
					? substr( $api['refresh_token'], -8 )
					: '',
			), 60 );
		}

		set_transient( 'wc_amazon_sync_settings_saved', true, 30 );
		$redirect = add_query_arg( array( 'page' => 'wc-amazon-sync', 'tab' => $tab ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Enqueue scripts
	 *
	 * @param string $hook Hook
	 */
	public function enqueue_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'wc-amazon-sync' !== $page ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		$is_sync_tab = isset( $_GET['tab'] ) && 'sync' === $_GET['tab'];
		if ( $is_sync_tab ) {
			wp_enqueue_style(
				'wc-amazon-sync-sync-tab',
				WC_AMAZON_SYNC_PLUGIN_URL . 'assets/css/sync-tab.css',
				array(),
				WC_AMAZON_SYNC_VERSION
			);
		}
		$is_import_tab = isset( $_GET['tab'] ) && 'import' === $_GET['tab'];
		if ( $is_import_tab ) {
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
			) );
		}
		wp_enqueue_script(
			'wc-amazon-sync-settings',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/js/settings.js',
			array( 'jquery' ),
			WC_AMAZON_SYNC_VERSION,
			true
		);
		wp_localize_script( 'wc-amazon-sync-settings', 'wcAmazonSyncSettings', array(
			'ajax_url'          => admin_url( 'admin-ajax.php' ),
			'test_nonce'        => wp_create_nonce( 'amazon_test_connection' ),
			'logs_list_nonce'   => wp_create_nonce( 'amazon_logs_list' ),
			'stock_sync_nonce'  => wp_create_nonce( 'wc_amazon_sync_stock_sync' ),
			'plugin_update_nonce' => wp_create_nonce( 'wc_amazon_sync_check_plugin_update' ),
			'i18n_test_ok'      => __( 'Connection successful.', 'wc-amazon-sync' ),
			'i18n_test_fail'    => __( 'Connection failed: %s', 'wc-amazon-sync' ),
			'i18n_refresh'      => __( 'Refresh logs', 'wc-amazon-sync' ),
			'i18n_test_logs'    => __( 'Logs', 'wc-amazon-sync' ),
			'i18n_stock_sync_ok'     => __( 'Stock applied.', 'wc-amazon-sync' ),
			'i18n_stock_sync_fail'   => __( 'Stock sync failed: %s', 'wc-amazon-sync' ),
			'i18n_stock_sync_btn'      => __( 'Jetzt starten', 'wc-amazon-sync' ),
			'i18n_stock_sync_loading' => __( 'Sending…', 'wc-amazon-sync' ),
			'i18n_stock_sync_wait'    => __( 'Stock is being sent to Amazon. This may take several minutes. Please do not close the page.', 'wc-amazon-sync' ),
		) );
	}

	/**
	 * Render settings page
	 */
	public function render() {
		$this->current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'api';
		// Legacy: redirect old Product Mapping tab to API
		if ( $this->current_tab === 'product_mapping' ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wc-amazon-sync', 'tab' => 'api' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$allowed = array( 'api', 'sync', 'general', 'interface', 'language', 'import' );
		if ( ! in_array( $this->current_tab, $allowed, true ) ) {
			$this->current_tab = 'api';
		}
		// Legacy: redirect Interface/Language to Allgemein
		if ( in_array( $this->current_tab, array( 'interface', 'language' ), true ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wc-amazon-sync', 'tab' => 'general' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$this->display_notices();
		$fullwidth = ( 'import' === $this->current_tab ) ? ' wc-amazon-sync-settings-fullwidth' : '';
		?>
		<div class="wrap wc-amazon-sync-settings<?php echo esc_attr( $fullwidth ); ?>">
			<h1><?php esc_html_e( 'Amazon Sync Settings', 'wc-amazon-sync' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-amazon-sync&tab=api' ) ); ?>" class="nav-tab <?php echo 'api' === $this->current_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Amazon API', 'wc-amazon-sync' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-amazon-sync&tab=sync' ) ); ?>" class="nav-tab <?php echo 'sync' === $this->current_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Synchronization', 'wc-amazon-sync' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-amazon-sync&tab=general' ) ); ?>" class="nav-tab <?php echo 'general' === $this->current_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Allgemein', 'wc-amazon-sync' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-amazon-sync&tab=import' ) ); ?>" class="nav-tab <?php echo 'import' === $this->current_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Import', 'wc-amazon-sync' ); ?></a>
			</nav>

			<div class="tab-content">
				<?php
				switch ( $this->current_tab ) {
					case 'api':
						$this->render_api_tab();
						break;
					case 'sync':
						$this->render_sync_tab();
						break;
					case 'general':
						$this->render_general_tab();
						break;
					case 'import':
						$import = new ImportTabContent();
						$import->render();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Display admin notices
	 */
	private function display_notices() {
		if ( get_transient( 'wc_amazon_sync_settings_saved' ) ) {
			delete_transient( 'wc_amazon_sync_settings_saved' );
			$result = get_transient( 'wc_amazon_sync_save_result' );
			if ( is_array( $result ) ) {
				delete_transient( 'wc_amazon_sync_save_result' );
			}
			$msg = __( 'Settings saved.', 'wc-amazon-sync' );
			if ( is_array( $result ) ) {
				$msg .= ' ';
				$msg .= $result['secret_saved']
					? sprintf( __( 'Client secret: saved (%d chars).', 'wc-amazon-sync' ), (int) $result['secret_len'] )
					: __( 'Client secret: not changed or empty.', 'wc-amazon-sync' );
				if ( ! empty( $result['secret_suffix'] ) ) {
					$msg .= ' ' . sprintf( __( 'Ends with: …%s', 'wc-amazon-sync' ), $result['secret_suffix'] );
				}
				$msg .= ' ';
				$msg .= $result['token_saved']
					? sprintf( __( 'Refresh token: saved (%d chars).', 'wc-amazon-sync' ), (int) $result['token_len'] )
					: __( 'Refresh token: not changed or empty.', 'wc-amazon-sync' );
				if ( ! empty( $result['token_suffix'] ) ) {
					$msg .= ' ' . sprintf( __( 'Ends with: …%s', 'wc-amazon-sync' ), $result['token_suffix'] );
				}
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/**
	 * Render API tab
	 */
	private function render_api_tab() {
		$api = get_option( 'wc_amazon_sync_api', array() );
		if ( ! is_array( $api ) ) {
			$api = array();
		}
		$region         = isset( $api['region'] ) ? $api['region'] : 'eu-west-1';
		$seller_id      = isset( $api['seller_id'] ) ? $api['seller_id'] : '';
		$marketplace_id = isset( $api['marketplace_id'] ) ? $api['marketplace_id'] : '';
		$issue_locale   = isset( $api['issue_locale'] ) && $api['issue_locale'] !== '' ? $api['issue_locale'] : 'de_DE';
		$client_id      = isset( $api['client_id'] ) ? $api['client_id'] : '';
		$client_secret  = isset( $api['client_secret'] ) ? $api['client_secret'] : '';
		$refresh_token  = isset( $api['refresh_token'] ) ? $api['refresh_token'] : '';
		$use_sandbox    = ! empty( $api['use_sandbox'] );
		?>
		<p class="description" style="margin-bottom: 16px;">
			<?php esc_html_e( 'Use the same LWA credentials as in your working scripts (e.g. Client ID = LWA Client ID, Client Secret = LWA Client Secret, Refresh Token = Refresh Token). For EU use Region: EU (eu-west-1); the plugin then uses the same endpoint as sellingpartnerapi-eu.amazon.com.', 'wc-amazon-sync' ); ?>
			<?php esc_html_e( 'Import uses Catalog Items API (not Feeds API): your app in Seller Central must have the Product Listing role approved.', 'wc-amazon-sync' ); ?>
		</p>
		<form method="post" action="" id="wc-amazon-sync-api-form">
			<?php wp_nonce_field( 'wc_amazon_sync_settings', 'wc_amazon_sync_settings_nonce' ); ?>
			<input type="hidden" name="tab" value="api" />
			<table class="form-table">
				<tr>
					<th><label for="wc_amazon_sync_region"><?php esc_html_e( 'Region', 'wc-amazon-sync' ); ?></label></th>
					<td>
						<select id="wc_amazon_sync_region" name="wc_amazon_sync_region">
							<option value="eu-west-1" <?php selected( $region, 'eu-west-1' ); ?>><?php esc_html_e( 'EU (eu-west-1)', 'wc-amazon-sync' ); ?></option>
							<option value="us-east-1" <?php selected( $region, 'us-east-1' ); ?>><?php esc_html_e( 'NA (us-east-1)', 'wc-amazon-sync' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Select your Amazon SP-API region.', 'wc-amazon-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wc_amazon_sync_seller_id"><?php esc_html_e( 'Seller ID', 'wc-amazon-sync' ); ?></label></th>
					<td>
						<input type="text" id="wc_amazon_sync_seller_id" name="wc_amazon_sync_seller_id" value="<?php echo esc_attr( $seller_id ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Selling Partner ID (Seller ID / merchantId from Seller Central). Not LWA Client ID, not refresh token, not Marketplace ID. Required for Versandvorlagen (Product Type Definitions API).', 'wc-amazon-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wc_amazon_sync_marketplace_id"><?php esc_html_e( 'Marketplace ID', 'wc-amazon-sync' ); ?></label></th>
					<td>
						<input type="text" id="wc_amazon_sync_marketplace_id" name="wc_amazon_sync_marketplace_id" value="<?php echo esc_attr( $marketplace_id ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Your Amazon Marketplace ID (e.g. A1PA6795UKMFR9 for Germany).', 'wc-amazon-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wc_amazon_sync_issue_locale"><?php esc_html_e( 'Locale (Kategorie-Namen, Listings)', 'wc-amazon-sync' ); ?></label></th>
					<td>
						<select id="wc_amazon_sync_issue_locale" name="wc_amazon_sync_issue_locale">
							<option value="de_DE" <?php selected( $issue_locale, 'de_DE' ); ?>><?php esc_html_e( 'Deutsch (de_DE)', 'wc-amazon-sync' ); ?></option>
							<option value="en_US" <?php selected( $issue_locale, 'en_US' ); ?>><?php esc_html_e( 'English US (en_US)', 'wc-amazon-sync' ); ?></option>
							<option value="en_GB" <?php selected( $issue_locale, 'en_GB' ); ?>><?php esc_html_e( 'English UK (en_GB)', 'wc-amazon-sync' ); ?></option>
							<option value="fr_FR" <?php selected( $issue_locale, 'fr_FR' ); ?>><?php esc_html_e( 'Français (fr_FR)', 'wc-amazon-sync' ); ?></option>
							<option value="it_IT" <?php selected( $issue_locale, 'it_IT' ); ?>><?php esc_html_e( 'Italiano (it_IT)', 'wc-amazon-sync' ); ?></option>
							<option value="es_ES" <?php selected( $issue_locale, 'es_ES' ); ?>><?php esc_html_e( 'Español (es_ES)', 'wc-amazon-sync' ); ?></option>
							<option value="tr_TR" <?php selected( $issue_locale, 'tr_TR' ); ?>><?php esc_html_e( 'Türkçe (tr_TR)', 'wc-amazon-sync' ); ?></option>
							<option value="ja_JP" <?php selected( $issue_locale, 'ja_JP' ); ?>><?php esc_html_e( '日本語 (ja_JP)', 'wc-amazon-sync' ); ?></option>
							<option value="pt_BR" <?php selected( $issue_locale, 'pt_BR' ); ?>><?php esc_html_e( 'Português BR (pt_BR)', 'wc-amazon-sync' ); ?></option>
							<option value="zh_CN" <?php selected( $issue_locale, 'zh_CN' ); ?>><?php esc_html_e( '中文 (zh_CN)', 'wc-amazon-sync' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Sprache für Amazon-Kategorienamen und Listings. Deutsch empfohlen für DE-Marketplace.', 'wc-amazon-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wc_amazon_sync_client_id"><?php esc_html_e( 'Client ID (LWA)', 'wc-amazon-sync' ); ?></label></th>
					<td>
						<input type="text" id="wc_amazon_sync_client_id" name="wc_amazon_sync_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Login with Amazon (LWA) Client ID.', 'wc-amazon-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wc_amazon_sync_client_secret"><?php esc_html_e( 'Client Secret (LWA)', 'wc-amazon-sync' ); ?></label></th>
					<td>
						<input type="text" id="wc_amazon_sync_client_secret" name="wc_amazon_sync_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="large-text code" autocomplete="off" style="width:100%;max-width:800px;" />
						<p class="description"><?php esc_html_e( 'Login with Amazon (LWA) Client Secret. Leave blank to keep current value.', 'wc-amazon-sync' ); ?></p>
						<?php if ( $client_secret !== '' ) : ?>
							<p class="description"><?php echo esc_html( sprintf( __( 'Stored in database: %1$d chars, ends with …%2$s', 'wc-amazon-sync' ), strlen( $client_secret ), substr( $client_secret, -8 ) ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="wc_amazon_sync_refresh_token"><?php esc_html_e( 'Refresh Token', 'wc-amazon-sync' ); ?></label></th>
					<td>
						<input type="text" id="wc_amazon_sync_refresh_token" name="wc_amazon_sync_refresh_token" value="<?php echo esc_attr( $refresh_token ); ?>" class="large-text code" autocomplete="off" style="width:100%;max-width:800px;" />
						<p class="description"><?php esc_html_e( 'Amazon SP-API Refresh Token. Leave blank to keep current value.', 'wc-amazon-sync' ); ?></p>
						<?php if ( $refresh_token !== '' ) : ?>
							<p class="description"><?php echo esc_html( sprintf( __( 'Stored in database: %1$d chars, ends with …%2$s', 'wc-amazon-sync' ), strlen( $refresh_token ), substr( $refresh_token, -8 ) ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="wc_amazon_sync_use_sandbox"><?php esc_html_e( 'Use Sandbox', 'wc-amazon-sync' ); ?></label></th>
					<td>
						<label><input type="checkbox" id="wc_amazon_sync_use_sandbox" name="wc_amazon_sync_use_sandbox" <?php checked( $use_sandbox ); ?> /> <?php esc_html_e( 'Use Amazon SP-API sandbox environment for testing.', 'wc-amazon-sync' ); ?></label>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="button" id="amazon-test-connection-btn" class="button"><?php esc_html_e( 'Test Connection', 'wc-amazon-sync' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'wc-amazon-sync' ); ?></button>
			</p>
		</form>

		<div id="amazon-api-test-logs" style="margin-top: 24px; border: 1px solid #ccd0d4; padding: 12px; background: #fff;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'Logs', 'wc-amazon-sync' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Result of the last test and recent test connection logs. Use this to see what the API returns and what access your credentials have.', 'wc-amazon-sync' ); ?></p>
			<div id="amazon-api-test-result" style="margin: 10px 0; padding: 8px; border-left: 4px solid #72aee6; background: #f0f6fc; display: none;"></div>
			<p>
				<button type="button" id="amazon-api-test-logs-refresh" class="button"><?php esc_html_e( 'Refresh logs', 'wc-amazon-sync' ); ?></button>
			</p>
			<div id="amazon-api-test-logs-container" style="max-height: 280px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; padding: 12px; font-family: monospace; font-size: 12px; line-height: 1.5;"></div>
		</div>

		<?php
		$this->render_api_tab_credentials_diagnostic();
		?>
		<?php
	}

	/**
	 * Show where credentials are stored and whether any other options contain "amazon" (e.g. from an older plugin version).
	 */
	private function render_api_tab_credentials_diagnostic() {
		global $wpdb;
		$option_name = 'wc_amazon_sync_api';
		$current = get_option( $option_name, array() );
		$current = is_array( $current ) ? $current : array();
		$has_secret = ! empty( $current['client_secret'] );
		$has_token  = ! empty( $current['refresh_token'] );

		$like = $wpdb->esc_like( 'amazon' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS value_len FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				'%' . $like . '%'
			),
			ARRAY_A
		);
		?>
		<details style="margin-top: 24px; border: 1px solid #ccd0d4; padding: 12px; background: #f9f9f9;">
			<summary style="cursor: pointer; font-weight: bold;"><?php esc_html_e( 'Where credentials are stored (diagnostics)', 'wc-amazon-sync' ); ?></summary>
			<div style="margin-top: 12px;">
				<p><strong><?php esc_html_e( 'Current storage', 'wc-amazon-sync' ); ?>:</strong> WordPress option <code><?php echo esc_html( $option_name ); ?></code></p>
				<p>
					<?php esc_html_e( 'In this option right now', 'wc-amazon-sync' ); ?>:
					<code>region</code> <?php echo isset( $current['region'] ) ? '✓' : '—'; ?>,
					<code>seller_id</code> <?php echo isset( $current['seller_id'] ) && $current['seller_id'] !== '' ? '✓' : '—'; ?>,
					<code>marketplace_id</code> <?php echo isset( $current['marketplace_id'] ) && $current['marketplace_id'] !== '' ? '✓' : '—'; ?>,
					<code>issue_locale</code> <?php echo isset( $current['issue_locale'] ) && $current['issue_locale'] !== '' ? esc_html( $current['issue_locale'] ) : '—'; ?>,
					<code>client_id</code> <?php echo isset( $current['client_id'] ) && $current['client_id'] !== '' ? '✓' : '—'; ?>,
					<code>client_secret</code> <?php
					if ( $has_secret ) {
						$secret_len = strlen( $current['client_secret'] );
						echo '✓ ' . esc_html( sprintf( __( '(%1$d chars, ends …%2$s)', 'wc-amazon-sync' ), $secret_len, substr( $current['client_secret'], -8 ) ) );
					} else {
						echo '— ' . esc_html__( '(empty)', 'wc-amazon-sync' );
					}
					?>,
					<code>refresh_token</code> <?php
					if ( $has_token ) {
						$token_len = strlen( $current['refresh_token'] );
						echo '✓ ' . esc_html( sprintf( __( '(%1$d chars, ends …%2$s)', 'wc-amazon-sync' ), $token_len, substr( $current['refresh_token'], -8 ) ) );
					} else {
						echo '— ' . esc_html__( '(empty)', 'wc-amazon-sync' );
					}
					?>
				</p>
				<?php if ( ! $has_secret || ! $has_token ) : ?>
					<p style="color: #d63638;"><strong><?php esc_html_e( 'Client Secret or Refresh Token is missing in the database.', 'wc-amazon-sync' ); ?></strong> <?php esc_html_e( 'Re-enter them in the form above and click Save Changes. The plugin only saves them in the option above; there is no other copy.', 'wc-amazon-sync' ); ?></p>
				<?php endif; ?>
				<p><strong><?php esc_html_e( 'All options in the database whose name contains "amazon"', 'wc-amazon-sync' ); ?>:</strong></p>
				<ul style="margin: 0; padding-left: 20px;">
					<?php
					if ( ! empty( $rows ) ) {
						foreach ( $rows as $row ) {
							echo '<li><code>' . esc_html( $row['option_name'] ) . '</code> (' . esc_html( $row['value_len'] ) . ' ' . esc_html__( 'bytes', 'wc-amazon-sync' ) . ')</li>';
						}
					} else {
						echo '<li>' . esc_html__( 'None found.', 'wc-amazon-sync' ) . '</li>';
					}
					?>
				</ul>
				<p class="description"><?php esc_html_e( 'If an older version of the plugin used a different option name, you could try to copy the credentials from that option (e.g. via phpMyAdmin or a backup) into this plugin’s form and save again.', 'wc-amazon-sync' ); ?></p>
			</div>
		</details>
		<?php
	}

	/**
	 * AJAX Test Connection
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'amazon_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}

		$logger = new AmazonLogger();
		$logger->info( 'Test connection started', AmazonLogger::SCOPE_TEST_CONNECTION );

		// Always request a fresh LWA token so credential changes take effect immediately.
		delete_transient( 'wc_amazon_sync_access_token' );

		if ( class_exists( 'WCAmazonSync\Amazon\AmazonApiClient' ) ) {
			try {
				$client = new \WCAmazonSync\Amazon\AmazonApiClient();
				$result = $client->test_connection();
				if ( is_wp_error( $result ) ) {
					$msg = $result->get_error_message();
					$code = $result->get_error_code();
					$data = $result->get_error_data();
					$logger->error( 'Test connection failed: ' . $msg, AmazonLogger::SCOPE_TEST_CONNECTION, array(
						'error_code' => $code,
						'error_data' => $data,
					) );
					$logs = $logger->get_recent( 20, null, AmazonLogger::SCOPE_TEST_CONNECTION );
					wp_send_json_error( array(
						'message'          => $msg,
						'last_log_entries' => is_array( $logs ) ? $logs : array(),
					) );
				}
				$logger->info( 'Test connection successful', AmazonLogger::SCOPE_TEST_CONNECTION );
				$logs = $logger->get_recent( 20, null, AmazonLogger::SCOPE_TEST_CONNECTION );
				wp_send_json_success( array(
					'message'          => __( 'Connection successful.', 'wc-amazon-sync' ),
					'last_log_entries' => is_array( $logs ) ? $logs : array(),
				) );
			} catch ( \Exception $e ) {
				$logger->error( 'Test connection exception: ' . $e->getMessage(), AmazonLogger::SCOPE_TEST_CONNECTION, array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				) );
				$logs = $logger->get_recent( 20, null, AmazonLogger::SCOPE_TEST_CONNECTION );
				wp_send_json_error( array(
					'message'          => $e->getMessage(),
					'last_log_entries' => is_array( $logs ) ? $logs : array(),
				) );
			}
		} else {
			$logger->info( 'No API client: credentials only (no live test).', AmazonLogger::SCOPE_TEST_CONNECTION );
			$logs = $logger->get_recent( 20, null, AmazonLogger::SCOPE_TEST_CONNECTION );
			wp_send_json_success( array(
				'message'          => __( 'Credentials saved. Connect an API client to test live.', 'wc-amazon-sync' ),
				'last_log_entries' => is_array( $logs ) ? $logs : array(),
			) );
		}
	}

	/**
	 * Run stock sync (used by manual AJAX and by cron). Sends WooCommerce stock to Amazon and updates last_run options.
	 *
	 * @param string $mode 'manual' or 'auto'
	 * @return array{ synced: int, failed: int, skipped: int, unlinked_gone: int, message: string, first_error: string }
	 */
	public static function run_stock_sync( $mode = 'auto' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_imported_products';
		$api   = get_option( 'wc_amazon_sync_api', array() );
		$marketplace_id = is_array( $api ) && ! empty( $api['marketplace_id'] ) ? sanitize_text_field( $api['marketplace_id'] ) : '';
		$seller_id     = is_array( $api ) && ! empty( $api['seller_id'] ) ? sanitize_text_field( $api['seller_id'] ) : '';
		$synced = 0;
		$failed = 0;
		$skipped = 0;
		$unlinked_gone = 0;
		$first_error = '';
		$first_debug = null;

		if ( $marketplace_id === '' || $seller_id === '' ) {
			$now = current_time( 'mysql' );
			update_option( 'wc_amazon_sync_stock_sync_last_run', $now );
			update_option( 'wc_amazon_sync_stock_sync_last_status', 'error' );
			update_option( 'wc_amazon_sync_stock_sync_last_message', __( 'Seller ID or Marketplace not configured.', 'wc-amazon-sync' ) );
			update_option( 'wc_amazon_sync_stock_sync_last_mode', $mode === 'manual' ? __( 'Manual', 'wc-amazon-sync' ) : __( 'Auto', 'wc-amazon-sync' ) );
			return array( 'synced' => 0, 'failed' => 0, 'skipped' => 0, 'unlinked_gone' => 0, 'message' => '', 'first_error' => __( 'Seller ID or Marketplace not configured.', 'wc-amazon-sync' ) );
		}

		$linked = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, wc_product_id, seller_sku, asin FROM {$table} WHERE marketplace_id = %s AND link_status = 'linked' AND wc_product_id IS NOT NULL",
			$marketplace_id
		), ARRAY_A );
		$issue_locale = is_array( $api ) && ! empty( $api['issue_locale'] ) ? sanitize_text_field( $api['issue_locale'] ) : 'de_DE';
		$client = new \WCAmazonSync\Api\ReportsClient( $api );
		$logger = new AmazonLogger();
		$total = count( $linked );
		$logger->info( 'Stock sync (' . $mode . ') started', AmazonLogger::SCOPE_IMPORT, array( 'linked_count' => $total ) );

		foreach ( $linked as $row ) {
			$seller_sku = isset( $row['seller_sku'] ) ? trim( (string) $row['seller_sku'] ) : '';
			if ( $seller_sku === '' ) {
				$skipped++;
				continue;
			}
			$wc_product_id = (int) $row['wc_product_id'];
			if ( $wc_product_id && ! wc_get_product( $wc_product_id ) ) {
				$wpdb->update(
					$table,
					array( 'wc_product_id' => null, 'link_status' => 'unlinked', 'link_method' => null ),
					array( 'id' => (int) $row['id'] ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
				$unlinked_gone++;
				$logger->info( 'Stock sync: auto-unlinked (Woo product no longer exists)', AmazonLogger::SCOPE_IMPORT, array( 'sku' => $seller_sku, 'wc_product_id' => $wc_product_id ) );
				continue;
			}
			$qty_data = StockSyncHelper::get_quantity_for_linked( $wc_product_id );
			$quantity_to_send = $qty_data['display_qty'];
			$merchant_shipping_group = get_option( 'wc_amazon_sync_merchant_shipping_group', '' );
			$result = $client->patch_listings_item_quantity( $seller_id, $seller_sku, $marketplace_id, $quantity_to_send, $issue_locale, $merchant_shipping_group );
			if ( $result['success'] ) {
				$wpdb->update( $table, array( 'quantity' => $quantity_to_send ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) );
				$synced++;
			} else {
				$failed++;
				if ( $first_error === '' && ! empty( $result['error'] ) ) {
					$first_error = $result['error'];
					$first_debug = isset( $result['debug'] ) ? $result['debug'] : null;
					$logger->error( 'Stock sync to Amazon: first failure', AmazonLogger::SCOPE_IMPORT, array( 'sku' => $seller_sku, 'asin' => isset( $row['asin'] ) ? $row['asin'] : '', 'error' => $first_error, 'total_linked' => $total ) );
					if ( $first_debug !== null ) {
						$logger->error( 'Stock sync request snapshot (first failure)', AmazonLogger::SCOPE_IMPORT, array(
							'request_url_path_and_query' => isset( $first_debug['request_url_path_and_query'] ) ? $first_debug['request_url_path_and_query'] : '',
							'request_body' => isset( $first_debug['request_body'] ) ? $first_debug['request_body'] : array(),
							'response_code' => isset( $first_debug['response_code'] ) ? $first_debug['response_code'] : null,
							'response_body' => isset( $first_debug['response_body'] ) ? $first_debug['response_body'] : '',
						) );
					}
				}
			}
			if ( $synced + $failed > 0 ) {
				usleep( 250000 );
			}
		}

		$now = current_time( 'mysql' );
		update_option( 'wc_amazon_sync_stock_sync_last_run', $now );
		update_option( 'wc_amazon_sync_stock_sync_last_status', $failed > 0 ? 'error' : 'success' );
		$last_msg = sprintf( __( 'Synced: %d, Failed: %d, Skipped: %d', 'wc-amazon-sync' ), $synced, $failed, $skipped );
		if ( $unlinked_gone > 0 ) {
			$last_msg .= ' ' . sprintf( __( 'Auto-unlinked (Woo deleted): %d', 'wc-amazon-sync' ), $unlinked_gone );
		}
		update_option( 'wc_amazon_sync_stock_sync_last_message', $last_msg );
		update_option( 'wc_amazon_sync_stock_sync_last_mode', $mode === 'manual' ? __( 'Manual', 'wc-amazon-sync' ) : __( 'Auto', 'wc-amazon-sync' ) );

		$logger->info( 'Stock sync (' . $mode . ') finished', AmazonLogger::SCOPE_IMPORT, array( 'synced' => $synced, 'failed' => $failed, 'skipped' => $skipped, 'unlinked_gone' => $unlinked_gone, 'first_error' => $first_error ) );

		$message = $failed > 0
			? sprintf( __( 'Sent to Amazon: %d ok, %d failed, %d skipped (no SKU).', 'wc-amazon-sync' ), $synced, $failed, $skipped )
			: sprintf( __( 'Stock sent to Amazon: %d product(s) updated.', 'wc-amazon-sync' ), $synced );
		if ( $unlinked_gone > 0 ) {
			$message .= ' ' . sprintf( __( '%d link(s) removed (Woo product no longer exists).', 'wc-amazon-sync' ), $unlinked_gone );
		}
		if ( $first_error !== '' ) {
			$message .= ' ' . __( 'First error:', 'wc-amazon-sync' ) . ' ' . $first_error;
		}
		return array(
			'synced'       => $synced,
			'failed'       => $failed,
			'skipped'      => $skipped,
			'unlinked_gone' => $unlinked_gone,
			'message'      => $message,
			'first_error'  => $first_error,
		);
	}

	/**
	 * AJAX: Manual stock sync — calls run_stock_sync and sends JSON.
	 */
	public function ajax_manual_stock_sync() {
		check_ajax_referer( 'wc_amazon_sync_stock_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-amazon-sync' ) ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 );
		}
		$result = self::run_stock_sync( 'manual' );
		$now = get_option( 'wc_amazon_sync_stock_sync_last_run', '' );
		wp_send_json_success( array(
			'message' => $result['message'],
			'first_error' => $result['first_error'],
			'stats' => array(
				'total'        => $result['synced'] + $result['failed'] + $result['skipped'],
				'synced'       => $result['synced'],
				'failed'       => $result['failed'],
				'skipped'      => $result['skipped'],
				'unlinked_gone' => $result['unlinked_gone'],
			),
			'last_run' => $now,
			'last_run_formatted' => $now ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $now ) ) : '',
			'last_status' => get_option( 'wc_amazon_sync_stock_sync_last_status', '' ),
			'last_message' => get_option( 'wc_amazon_sync_stock_sync_last_message', '' ),
		) );
	}

	/**
	 * Render Synchronization tab (Preis + Lagerbestand blocks, same style as Kaufland sync tab)
	 */
	private function render_sync_tab() {
		$use_standard_price = (bool) get_option( 'wc_amazon_sync_use_standard_price', true );
		$markup_percent     = (float) get_option( 'wc_amazon_sync_price_markup_percent', 10 );
		$round_to_nice      = (bool) get_option( 'wc_amazon_sync_round_to_nice_price', false );
		$price_sync_enabled = (bool) get_option( 'wc_amazon_sync_price_sync_enabled', false );
		$price_sync_freq    = get_option( 'wc_amazon_sync_price_sync_frequency', 'daily' );
		$price_last_run     = get_option( 'wc_amazon_sync_price_sync_last_run', '' );
		$price_last_status  = get_option( 'wc_amazon_sync_price_sync_last_status', '' );
		$price_last_message = get_option( 'wc_amazon_sync_price_sync_last_message', '' );
		$price_last_mode    = get_option( 'wc_amazon_sync_price_sync_last_mode', __( 'Auto', 'wc-amazon-sync' ) );

		$max_stock          = (int) get_option( 'wc_amazon_sync_max_stock_quantity', 500 );
		$stock_sync_enabled = (bool) get_option( 'wc_amazon_sync_stock_sync_enabled', false );
		$stock_sync_freq    = get_option( 'wc_amazon_sync_stock_sync_frequency', '4hours' );
		$stock_last_run     = get_option( 'wc_amazon_sync_stock_sync_last_run', '' );
		$stock_last_status  = get_option( 'wc_amazon_sync_stock_sync_last_status', '' );
		$stock_last_message = get_option( 'wc_amazon_sync_stock_sync_last_message', '' );
		$stock_last_mode    = get_option( 'wc_amazon_sync_stock_sync_last_mode', __( 'Auto', 'wc-amazon-sync' ) );
		?>
		<div class="amazon-sync-tab-notice">
			<p><?php esc_html_e( 'Diese Einstellungen gelten für alle Produkte mit dem Status "an Amazon gesendet" (EXPORTED).', 'wc-amazon-sync' ); ?></p>
		</div>

		<div style="max-width: 1600px;">
			<form method="post" action="" id="wc-amazon-sync-sync-form">
				<?php wp_nonce_field( 'wc_amazon_sync_settings', 'wc_amazon_sync_settings_nonce' ); ?>
				<input type="hidden" name="tab" value="sync" />

				<!-- Preis -->
				<div class="amazon-sync-section">
					<h2><?php esc_html_e( 'Preis', 'wc-amazon-sync' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wc_amazon_sync_use_standard_price"><?php esc_html_e( 'Standardpreis verwenden', 'wc-amazon-sync' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="wc_amazon_sync_use_standard_price" name="wc_amazon_sync_use_standard_price" value="1" <?php checked( $use_standard_price ); ?> />
									<?php esc_html_e( 'WooCommerce Standardpreis als Grundpreis verwenden', 'wc-amazon-sync' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Wenn aktiviert, wird der Standard-WooCommerce-Produktpreis als Grundpreis verwendet. Andernfalls wird der Angebotspreis (falls verfügbar) verwendet.', 'wc-amazon-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wc_amazon_sync_price_markup_percent"><?php esc_html_e( 'Preisaufschlag (%)', 'wc-amazon-sync' ); ?></label>
							</th>
							<td>
								<input type="number" id="wc_amazon_sync_price_markup_percent" name="wc_amazon_sync_price_markup_percent" value="<?php echo esc_attr( $markup_percent ); ?>" step="0.1" min="0" max="1000" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Prozentsatz, der zum Grundpreis hinzugefügt wird. Beispielsweise bedeutet 10, dass 10% zum Preis hinzugefügt werden. Dies wird typischerweise verwendet, um Amazon-Provisionen zu decken.', 'wc-amazon-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wc_amazon_sync_round_to_nice_price"><?php esc_html_e( 'Auf schönen Preis runden', 'wc-amazon-sync' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="wc_amazon_sync_round_to_nice_price" name="wc_amazon_sync_round_to_nice_price" value="1" <?php checked( $round_to_nice ); ?> />
									<?php esc_html_e( 'Preis auf den nächsten "schönen" Preis runden (z.B. 1,43 → 1,49, 1,96 → 1,99)', 'wc-amazon-sync' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Wenn aktiviert, werden Preise auf den nächsten "schönen" Preis gerundet, der auf .49, .79, .89 oder .99 endet, für eine bessere Darstellung.', 'wc-amazon-sync' ); ?></p>
							</td>
						</tr>
					</table>
					<div class="sync-controls">
						<label>
							<input type="checkbox" name="wc_amazon_sync_price_sync_enabled" value="1" <?php checked( $price_sync_enabled ); ?> />
							<strong><?php esc_html_e( 'Automatische Synchronisierung aktivieren', 'wc-amazon-sync' ); ?></strong>
						</label>
						<select name="wc_amazon_sync_price_sync_frequency" id="wc_amazon_sync_price_sync_frequency">
							<option value="disabled" <?php selected( $price_sync_freq, 'disabled' ); ?>><?php esc_html_e( 'Deaktiviert', 'wc-amazon-sync' ); ?></option>
							<option value="4hours" <?php selected( $price_sync_freq, '4hours' ); ?>><?php esc_html_e( 'Alle 4 Stunden', 'wc-amazon-sync' ); ?></option>
							<option value="8hours" <?php selected( $price_sync_freq, '8hours' ); ?>><?php esc_html_e( 'Alle 8 Stunden', 'wc-amazon-sync' ); ?></option>
							<option value="12hours" <?php selected( $price_sync_freq, '12hours' ); ?>><?php esc_html_e( 'Alle 12 Stunden', 'wc-amazon-sync' ); ?></option>
							<option value="daily" <?php selected( $price_sync_freq, 'daily' ); ?>><?php esc_html_e( 'Täglich', 'wc-amazon-sync' ); ?></option>
						</select>
						<button type="button" class="button button-secondary" id="wc-amazon-sync-price-now" disabled="disabled" title="<?php esc_attr_e( 'Wird in einer späteren Version aktiv.', 'wc-amazon-sync' ); ?>"><?php esc_html_e( 'Jetzt starten', 'wc-amazon-sync' ); ?></button>
					</div>
					<?php if ( $price_last_run ) : ?>
					<div class="last-run-info">
						<p><strong><?php esc_html_e( 'Letzter Start:', 'wc-amazon-sync' ); ?></strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $price_last_run ) ) ); ?> (<?php echo esc_html( $price_last_mode ); ?>)</p>
						<p><strong><?php esc_html_e( 'Status:', 'wc-amazon-sync' ); ?></strong> <span class="<?php echo 'success' === $price_last_status ? 'status-ok' : 'status-error'; ?>"><?php echo 'success' === $price_last_status ? esc_html__( 'OK', 'wc-amazon-sync' ) : esc_html__( 'ERROR', 'wc-amazon-sync' ); ?></span></p>
						<?php if ( $price_last_message ) : ?><p><strong><?php esc_html_e( 'Zusammenfassung:', 'wc-amazon-sync' ); ?></strong> <?php echo esc_html( $price_last_message ); ?></p><?php endif; ?>
					</div>
					<?php else : ?>
					<div class="last-run-info">
						<p><strong><?php esc_html_e( 'Letzter Start:', 'wc-amazon-sync' ); ?></strong> —</p>
						<p><strong><?php esc_html_e( 'Status:', 'wc-amazon-sync' ); ?></strong> —</p>
						<p><strong><?php esc_html_e( 'Zusammenfassung:', 'wc-amazon-sync' ); ?></strong> <?php esc_html_e( 'Synced: 0, Failed: 0, Skipped: 0', 'wc-amazon-sync' ); ?></p>
					</div>
					<?php endif; ?>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Speichern', 'wc-amazon-sync' ); ?></button>
					</p>
				</div>

				<!-- Lagerbestand -->
				<div class="amazon-sync-section">
					<h3><?php esc_html_e( 'Lagerbestand', 'wc-amazon-sync' ); ?></h3>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wc_amazon_sync_max_stock_quantity"><?php esc_html_e( 'Maximale Lagerbestandsmenge', 'wc-amazon-sync' ); ?></label>
							</th>
							<td>
								<input type="number" id="wc_amazon_sync_max_stock_quantity" name="wc_amazon_sync_max_stock_quantity" value="<?php echo esc_attr( $max_stock ); ?>" class="small-text" min="0" step="1" />
								<p class="description"><?php esc_html_e( 'Maximale Lagerbestandsmenge, die an Amazon gesendet wird. Wenn auf 0 gesetzt, wird der tatsächliche Lagerbestandswert gesendet. Wenn auf eine Zahl gesetzt, wird diese Zahl gesendet, wenn der Lagerbestand größer oder gleich dieser Zahl ist, andernfalls wird der tatsächliche Lagerbestand gesendet.', 'wc-amazon-sync' ); ?></p>
							</td>
						</tr>
					</table>
					<div class="sync-controls">
						<label>
							<input type="checkbox" name="wc_amazon_sync_stock_sync_enabled" value="1" <?php checked( $stock_sync_enabled ); ?> />
							<strong><?php esc_html_e( 'Automatische Synchronisierung aktivieren', 'wc-amazon-sync' ); ?></strong>
						</label>
						<select name="wc_amazon_sync_stock_sync_frequency" id="wc_amazon_sync_stock_sync_frequency">
							<option value="disabled" <?php selected( $stock_sync_freq, 'disabled' ); ?>><?php esc_html_e( 'Deaktiviert', 'wc-amazon-sync' ); ?></option>
							<option value="1hour" <?php selected( $stock_sync_freq, '1hour' ); ?>><?php esc_html_e( 'Jede Stunde', 'wc-amazon-sync' ); ?></option>
							<option value="2hours" <?php selected( $stock_sync_freq, '2hours' ); ?>><?php esc_html_e( 'Alle 2 Stunden', 'wc-amazon-sync' ); ?></option>
							<option value="4hours" <?php selected( $stock_sync_freq, '4hours' ); ?>><?php esc_html_e( 'Alle 4 Stunden', 'wc-amazon-sync' ); ?></option>
							<option value="daily" <?php selected( $stock_sync_freq, 'daily' ); ?>><?php esc_html_e( 'Täglich', 'wc-amazon-sync' ); ?></option>
						</select>
						<button type="button" class="button button-secondary" id="wc-amazon-sync-stock-now"><?php esc_html_e( 'Jetzt starten', 'wc-amazon-sync' ); ?></button>
					</div>
					<?php if ( $stock_last_run ) : ?>
					<div class="last-run-info">
						<p><strong><?php esc_html_e( 'Letzter Start:', 'wc-amazon-sync' ); ?></strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $stock_last_run ) ) ); ?> (<?php echo esc_html( $stock_last_mode ); ?>)</p>
						<p><strong><?php esc_html_e( 'Status:', 'wc-amazon-sync' ); ?></strong> <span class="<?php echo 'success' === $stock_last_status ? 'status-ok' : 'status-error'; ?>"><?php echo 'success' === $stock_last_status ? esc_html__( 'OK', 'wc-amazon-sync' ) : esc_html__( 'ERROR', 'wc-amazon-sync' ); ?></span></p>
						<?php if ( $stock_last_message ) : ?><p><strong><?php esc_html_e( 'Zusammenfassung:', 'wc-amazon-sync' ); ?></strong> <?php echo esc_html( $stock_last_message ); ?></p><?php endif; ?>
					</div>
					<?php else : ?>
					<div class="last-run-info">
						<p><strong><?php esc_html_e( 'Letzter Start:', 'wc-amazon-sync' ); ?></strong> —</p>
						<p><strong><?php esc_html_e( 'Status:', 'wc-amazon-sync' ); ?></strong> —</p>
						<p><strong><?php esc_html_e( 'Zusammenfassung:', 'wc-amazon-sync' ); ?></strong> <?php esc_html_e( 'Synced: 0, Failed: 0, Skipped: 0', 'wc-amazon-sync' ); ?></p>
					</div>
					<?php endif; ?>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Speichern', 'wc-amazon-sync' ); ?></button>
					</p>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Get merchant shipping templates (Versandvorlagen) from API, with transient cache.
	 *
	 * @return array<array{ key: string, name: string }>
	 */
	private function get_shipping_templates() {
		$cached = get_transient( 'wc_amazon_sync_shipping_templates' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$api = get_option( 'wc_amazon_sync_api', array() );
		if ( ! is_array( $api ) || empty( $api['seller_id'] ) || empty( $api['marketplace_id'] ) ) {
			return array();
		}
		$client = new \WCAmazonSync\Api\ReportsClient( $api );
		$result = $client->get_merchant_shipping_templates( $api['seller_id'], $api['marketplace_id'] );
		if ( empty( $result['success'] ) || empty( $result['templates'] ) ) {
			return array();
		}
		set_transient( 'wc_amazon_sync_shipping_templates', $result['templates'], HOUR_IN_SECONDS );
		return $result['templates'];
	}

	/**
	 * Render Allgemein tab (Versand + Sprache des Plugins), same style as Kaufland.
	 */
	private function render_general_tab() {
		$forced_language = get_option( 'wc_amazon_sync_forced_language', 'auto' );
		$merchant_shipping_group = get_option( 'wc_amazon_sync_merchant_shipping_group', '' );
		$shipping_templates = $this->get_shipping_templates();
		$seller_central_domain = 'sellercentral.amazon.de';
		$shipping_settings_url = 'https://' . $seller_central_domain . '/gp/v2/seller/shipping-templates.html';
		?>
		<style>
		.wc-amazon-sync-general-section {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			padding: 20px;
			margin-bottom: 20px;
		}
		.wc-amazon-sync-general-section h2 {
			margin-top: 0;
			margin-bottom: 15px;
			padding-bottom: 10px;
			border-bottom: 2px solid #2271b1;
			color: #2271b1;
		}
		</style>

		<form method="post" action="" id="wc-amazon-sync-general-form">
			<?php wp_nonce_field( 'wc_amazon_sync_settings', 'wc_amazon_sync_settings_nonce' ); ?>
			<input type="hidden" name="wc_amazon_sync_shipping_templates_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wc_amazon_sync_get_shipping_templates' ) ); ?>" />
			<input type="hidden" name="tab" value="general" />

			<!-- Block 1: Versand -->
			<div class="wc-amazon-sync-general-section">
				<h2><?php esc_html_e( 'Versand', 'wc-amazon-sync' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wc_amazon_sync_merchant_shipping_group"><?php esc_html_e( 'Versandvorlage', 'wc-amazon-sync' ); ?></label></th>
						<td>
							<select id="wc_amazon_sync_merchant_shipping_group" name="wc_amazon_sync_merchant_shipping_group" class="regular-text">
								<option value=""><?php esc_html_e( '— Versandvorlage wählen —', 'wc-amazon-sync' ); ?></option>
								<?php
								foreach ( $shipping_templates as $tpl ) {
									$key = isset( $tpl['key'] ) ? $tpl['key'] : '';
									$name = isset( $tpl['name'] ) ? $tpl['name'] : $key;
									if ( $key === '' ) {
										continue;
									}
									echo '<option value="' . esc_attr( $key ) . '" ' . selected( $merchant_shipping_group, $key, false ) . '>' . esc_html( $name ) . '</option>';
								}
								?>
							</select>
							<button type="button" id="wc-amazon-sync-refresh-shipping-templates" class="button" style="margin-left: 10px;"><?php esc_html_e( 'Liste aktualisieren', 'wc-amazon-sync' ); ?></button>
							<p class="description"><?php esc_html_e( 'Standard-Versandvorlage für an Amazon gesendete Artikel. „Liste aktualisieren“ lädt die im Seller Central konfigurierten Versandvorlagen über die Product Type Definitions API.', 'wc-amazon-sync' ); ?></p>
							<p class="description"><?php esc_html_e( 'Bei Fehlern oder 0 Einträgen: Meldung im Alert; Details siehe Log unten oder Tab Import → „Import log“.', 'wc-amazon-sync' ); ?></p>
							<div id="versandvorlagen-log-box" style="margin-top: 12px; padding: 10px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px; display: none;"></div>
							<button type="button" id="versandvorlagen-log-load-btn" class="button button-small" style="margin-top: 6px;"><?php esc_html_e( 'Letzte Versandvorlagen-Logs anzeigen', 'wc-amazon-sync' ); ?></button>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Versand im Seller Central', 'wc-amazon-sync' ); ?></th>
						<td>
							<a href="<?php echo esc_url( $shipping_settings_url ); ?>" target="_blank" rel="noopener" class="button"><?php esc_html_e( 'Versand im Seller Central öffnen', 'wc-amazon-sync' ); ?></a>
						</td>
					</tr>
				</table>
			</div>

			<!-- Block 2: Sprache des Plugins -->
			<div class="wc-amazon-sync-general-section">
				<h2><?php esc_html_e( 'Sprache des Plugins', 'wc-amazon-sync' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wc_amazon_sync_forced_language"><?php esc_html_e( 'Sprache', 'wc-amazon-sync' ); ?></label></th>
						<td>
							<select id="wc_amazon_sync_forced_language" name="wc_amazon_sync_forced_language">
								<option value="auto" <?php selected( $forced_language, 'auto' ); ?>><?php esc_html_e( 'Automatisch (Sprache der Website verwenden)', 'wc-amazon-sync' ); ?></option>
								<option value="en_US" <?php selected( $forced_language, 'en_US' ); ?>><?php esc_html_e( 'English', 'wc-amazon-sync' ); ?></option>
								<option value="de_DE" <?php selected( $forced_language, 'de_DE' ); ?>><?php esc_html_e( 'Deutsch', 'wc-amazon-sync' ); ?></option>
								<option value="ru_RU" <?php selected( $forced_language, 'ru_RU' ); ?>><?php esc_html_e( 'Русский', 'wc-amazon-sync' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Sprache der Plugin-Oberfläche.', 'wc-amazon-sync' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<?php $this->render_plugin_update_section(); ?>

			<p class="submit">
				<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Einstellungen speichern', 'wc-amazon-sync' ); ?>" />
			</p>
		</form>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			function amazonApplyUpdateStatus(status) {
				if (!status) return;
				if (status.remote_version) {
					$('#amazon-update-remote').text(status.remote_version);
				}
				if (status.last_check) {
					$('#amazon-update-last-check').text(status.last_check);
				}
				if (status.update_available) {
					$('#amazon-install-plugin-update').show();
				} else {
					$('#amazon-install-plugin-update').hide();
				}
			}

			$('#amazon-check-plugin-update').on('click', function() {
				var $btn = $(this);
				var $spinner = $('#amazon-update-spinner');
				var $msg = $('#amazon-update-message');
				var $err = $('#amazon-update-error');
				var nonce = (typeof wcAmazonSyncSettings !== 'undefined' && wcAmazonSyncSettings.plugin_update_nonce) ? wcAmazonSyncSettings.plugin_update_nonce : '';
				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$msg.text('');
				$err.text('');
				$.post(ajaxurl, {
					action: 'wc_amazon_sync_check_plugin_update',
					nonce: nonce
				}).done(function(response) {
					var status = (response.data && response.data.status) ? response.data.status : null;
					amazonApplyUpdateStatus(status);
					if (status && status.last_error) {
						$err.text(status.last_error);
					}
					if (response.success) {
						$msg.css('color', '#00a32a').text(response.data && response.data.message ? response.data.message : '');
					} else {
						var failMsg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Update-Prüfung fehlgeschlagen.', 'wc-amazon-sync' ) ); ?>';
						$msg.css('color', '#d63638').text(failMsg);
						$err.text(failMsg);
					}
				}).fail(function() {
					$msg.css('color', '#d63638').text('<?php echo esc_js( __( 'Anfrage fehlgeschlagen.', 'wc-amazon-sync' ) ); ?>');
				}).always(function() {
					$btn.prop('disabled', false);
					$spinner.removeClass('is-active');
				});
			});

			$('#amazon-install-plugin-update').on('click', function() {
				var $btn = $(this);
				var $check = $('#amazon-check-plugin-update');
				var $spinner = $('#amazon-update-spinner');
				var $msg = $('#amazon-update-message');
				var $err = $('#amazon-update-error');
				var nonce = (typeof wcAmazonSyncSettings !== 'undefined' && wcAmazonSyncSettings.plugin_update_nonce) ? wcAmazonSyncSettings.plugin_update_nonce : '';
				if (!window.confirm('<?php echo esc_js( __( 'Amazon Sync jetzt aktualisieren? Die Seite wird danach neu geladen.', 'wc-amazon-sync' ) ); ?>')) {
					return;
				}
				$btn.prop('disabled', true);
				$check.prop('disabled', true);
				$spinner.addClass('is-active');
				$msg.css('color', '').text('<?php echo esc_js( __( 'Update wird installiert… bitte warten.', 'wc-amazon-sync' ) ); ?>');
				$err.text('');
				$.ajax({
					url: ajaxurl,
					method: 'POST',
					timeout: 300000,
					data: {
						action: 'wc_amazon_sync_install_plugin_update',
						nonce: nonce
					}
				}).done(function(response) {
					var status = (response.data && response.data.status) ? response.data.status : null;
					amazonApplyUpdateStatus(status);
					if (response.success) {
						$msg.css('color', '#00a32a').text(response.data && response.data.message ? response.data.message : '');
						if (response.data && response.data.reloaded) {
							setTimeout(function() { window.location.reload(); }, 1200);
							return;
						}
					} else {
						var failMsg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Update fehlgeschlagen.', 'wc-amazon-sync' ) ); ?>';
						$msg.css('color', '#d63638').text(failMsg);
						$err.text(failMsg);
					}
					$btn.prop('disabled', false);
					$check.prop('disabled', false);
					$spinner.removeClass('is-active');
				}).fail(function() {
					$msg.css('color', '#d63638').text('<?php echo esc_js( __( 'Anfrage fehlgeschlagen.', 'wc-amazon-sync' ) ); ?>');
					$btn.prop('disabled', false);
					$check.prop('disabled', false);
					$spinner.removeClass('is-active');
				});
			});

			function loadVersandvorlagenLog() {
				var $box = $('#versandvorlagen-log-box');
				var nonce = (typeof wcAmazonSyncSettings !== 'undefined' && wcAmazonSyncSettings.logs_list_nonce) ? wcAmazonSyncSettings.logs_list_nonce : '';
				$box.show().html('<?php echo esc_js( __( 'Lade…', 'wc-amazon-sync' ) ); ?>');
				$.post(ajaxurl, {
					action: 'amazon_logs_list',
					nonce: nonce,
					scope: 'import',
					limit: 20
				}, function(res) {
					if (res && res.success && res.data && res.data.logs && res.data.logs.length) {
						var versand = res.data.logs.filter(function(l) { return (l.message || '').indexOf('Versandvorlagen') !== -1; });
						var lines = (versand.length ? versand : res.data.logs.slice(0, 10)).map(function(l) {
							var ctx = '';
							try { if (l.context_json) ctx = ' ' + l.context_json; } catch(e) {}
							return (l.created_at || '') + ' [' + (l.level || '') + '] ' + (l.message || '') + ctx;
						});
						$box.html(lines.join('\n').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>'));
					} else {
						$box.html('<?php echo esc_js( __( 'Keine Log-Einträge.', 'wc-amazon-sync' ) ); ?>');
					}
				}).fail(function() {
					$box.html('<?php echo esc_js( __( 'Logs konnten nicht geladen werden.', 'wc-amazon-sync' ) ); ?>');
				});
			}
			$('#versandvorlagen-log-load-btn').on('click', function() {
				$('#versandvorlagen-log-box').show();
				loadVersandvorlagenLog();
			});
			$('#wc-amazon-sync-refresh-shipping-templates').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var nonce = $('#wc-amazon-sync-general-form input[name="wc_amazon_sync_shipping_templates_nonce"]').val() || '';
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Laden…', 'wc-amazon-sync' ) ); ?>');
				$.post(ajaxurl, {
					action: 'wc_amazon_sync_get_shipping_templates',
					nonce: nonce
				}, function(res) {
					if (res && res.success) {
						var count = (res.data && res.data.templates) ? res.data.templates.length : 0;
						var msg = res.data && res.data.message ? res.data.message : ('<?php echo esc_js( __( 'Versandvorlagen aktualisiert.', 'wc-amazon-sync' ) ); ?>' + (count ? ' (' + count + ').' : '') + '\n<?php echo esc_js( __( 'Seite wird neu geladen.', 'wc-amazon-sync' ) ); ?>');
						alert(msg);
						if (count > 0) { window.location.reload(); }
						else { loadVersandvorlagenLog(); $('#versandvorlagen-log-box').show(); }
					} else {
						alert((res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Abfrage fehlgeschlagen.', 'wc-amazon-sync' ) ); ?>');
						loadVersandvorlagenLog();
						$('#versandvorlagen-log-box').show();
					}
				}).fail(function(xhr) {
					var msg = '<?php echo esc_js( __( 'Fehler beim Aktualisieren der Versandvorlagen.', 'wc-amazon-sync' ) ); ?>';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) { msg = xhr.responseJSON.data.message; }
					alert(msg);
					loadVersandvorlagenLog();
					$('#versandvorlagen-log-box').show();
				}).always(function() {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Liste aktualisieren', 'wc-amazon-sync' ) ); ?>');
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Plugin update block for Allgemein tab (same pattern as eBay Sync).
	 *
	 * @return void
	 */
	private function render_plugin_update_section() {
		$status = \WCAmazonSync\Update\WordPressUpdater::get_status_payload();
		$installed = isset( $status['installed_version'] ) ? (string) $status['installed_version'] : WC_AMAZON_SYNC_VERSION;
		$remote    = isset( $status['remote_version'] ) ? (string) $status['remote_version'] : '';
		$uri       = isset( $status['update_uri'] ) ? (string) $status['update_uri'] : '';
		$last_check = isset( $status['last_check'] ) ? (string) $status['last_check'] : '';
		$last_error = isset( $status['last_error'] ) ? (string) $status['last_error'] : '';
		$update_available = ! empty( $status['update_available'] );
		?>
			<div class="wc-amazon-sync-general-section" id="amazon-plugin-update-section">
				<h2><?php esc_html_e( 'Plugin-Update', 'wc-amazon-sync' ); ?></h2>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'Private Updates über module-updates-api (api.webtest24.de). Prüfung und Installation direkt hier — ohne Umweg über die Plugins-Seite.', 'wc-amazon-sync' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Installierte Version', 'wc-amazon-sync' ); ?></th>
						<td><strong id="amazon-update-installed"><?php echo esc_html( $installed ); ?></strong></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Update-Server', 'wc-amazon-sync' ); ?></th>
						<td><code id="amazon-update-uri"><?php echo esc_html( $uri ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Server-Version', 'wc-amazon-sync' ); ?></th>
						<td>
							<span id="amazon-update-remote"><?php echo $remote !== '' ? esc_html( $remote ) : '—'; ?></span>
							<?php if ( $update_available ) : ?>
								<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:3px;background:#dba617;color:#fff;font-size:12px;">
									<?php esc_html_e( 'Update verfügbar', 'wc-amazon-sync' ); ?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Letzte Prüfung', 'wc-amazon-sync' ); ?></th>
						<td>
							<span id="amazon-update-last-check">
								<?php
								if ( $last_check !== '' ) {
									echo esc_html( date_i18n( 'd.m.Y H:i', strtotime( $last_check ) ) );
								} else {
									echo '—';
								}
								?>
							</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'wc-amazon-sync' ); ?></th>
						<td>
							<span id="amazon-update-error" style="color:#d63638;"><?php echo ( $last_error !== '' && ! $update_available ) ? esc_html( $last_error ) : ''; ?></span>
							<span id="amazon-update-message" style="margin-left:8px;"></span>
						</td>
					</tr>
				</table>
				<p>
					<button type="button" class="button" id="amazon-check-plugin-update">
						<?php esc_html_e( 'Jetzt auf Updates prüfen', 'wc-amazon-sync' ); ?>
					</button>
					<button
						type="button"
						class="button button-primary"
						id="amazon-install-plugin-update"
						style="margin-left:8px;<?php echo $update_available ? '' : 'display:none;'; ?>"
						<?php disabled( ! current_user_can( 'update_plugins' ) ); ?>
					>
						<?php esc_html_e( 'Jetzt aktualisieren', 'wc-amazon-sync' ); ?>
					</button>
					<span class="spinner" id="amazon-update-spinner" style="float:none;margin:0 0 0 8px;"></span>
				</p>
				<?php if ( ! current_user_can( 'update_plugins' ) ) : ?>
					<p class="description" style="color:#d63638;">
						<?php esc_html_e( 'Hinweis: Für die Installation wird die WordPress-Berechtigung „Plugins aktualisieren“ benötigt.', 'wc-amazon-sync' ); ?>
					</p>
				<?php endif; ?>
			</div>
		<?php
	}

	/**
	 * AJAX: Refresh merchant shipping templates (clear cache, fetch from API).
	 */
	public function ajax_get_shipping_templates() {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wc_amazon_sync_get_shipping_templates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sicherheitsprüfung fehlgeschlagen.', 'wc-amazon-sync' ) ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Berechtigung fehlt.', 'wc-amazon-sync' ) ) );
		}
		\WCAmazonSync\Database\Migration::create_tables();
		delete_transient( 'wc_amazon_sync_shipping_templates' );
		$logger = new AmazonLogger();

		$api = get_option( 'wc_amazon_sync_api', array() );
		$seller_id_opt = is_array( $api ) && isset( $api['seller_id'] ) ? $api['seller_id'] : '';
		$marketplace_id_opt = is_array( $api ) && isset( $api['marketplace_id'] ) ? $api['marketplace_id'] : '';
		$logger->info( 'Versandvorlagen: Liste aktualisieren gestartet', AmazonLogger::SCOPE_IMPORT, array(
			'sellerId'      => $seller_id_opt !== '' ? ( substr( $seller_id_opt, 0, 4 ) . '…' ) : '(nicht gesetzt)',
			'marketplaceId' => $marketplace_id_opt !== '' ? $marketplace_id_opt : '(nicht gesetzt)',
		) );

		if ( ! is_array( $api ) || empty( $api['seller_id'] ) || empty( $api['marketplace_id'] ) ) {
			$logger->error( 'Versandvorlagen: Seller ID oder Marketplace nicht konfiguriert.', AmazonLogger::SCOPE_IMPORT );
			wp_send_json_error( array( 'message' => __( 'Seller ID und Marketplace in den API-Einstellungen eintragen.', 'wc-amazon-sync' ) ) );
		}
		$client = new \WCAmazonSync\Api\ReportsClient( $api );
		$result = $client->get_merchant_shipping_templates( $api['seller_id'], $api['marketplace_id'] );

		$d = isset( $result['debug'] ) ? $result['debug'] : array();
		$logger->info( 'Versandvorlagen: Definitions response has schema.link.resource', AmazonLogger::SCOPE_IMPORT, array(
			'value' => isset( $d['schema_link_present'] ) ? $d['schema_link_present'] : 'unknown',
		) );
		$logger->info( 'Versandvorlagen: Downloaded schema', AmazonLogger::SCOPE_IMPORT, array(
			'bytes'     => isset( $d['schema_fetch_bytes'] ) ? $d['schema_fetch_bytes'] : 0,
			'http_code' => isset( $d['schema_fetch_http_code'] ) ? $d['schema_fetch_http_code'] : null,
			'url_short' => isset( $d['schema_resource_url_short'] ) ? $d['schema_resource_url_short'] : null,
		) );
		$logger->info( 'Versandvorlagen: merchant_shipping_group', AmazonLogger::SCOPE_IMPORT, array(
			'found'      => isset( $d['merchant_shipping_group_found'] ) ? $d['merchant_shipping_group_found'] : 'unknown',
			'enum_count' => isset( $d['merchant_shipping_group_enum_count'] ) ? $d['merchant_shipping_group_enum_count'] : 0,
			'first_3'    => isset( $d['first_3'] ) ? $d['first_3'] : null,
		) );
		$logger->info( 'Versandvorlagen: Request (debug)', AmazonLogger::SCOPE_IMPORT, array(
			'sellerId_sent'       => isset( $d['sellerId_sent'] ) ? $d['sellerId_sent'] : '',
			'marketplaceIds_sent' => isset( $d['marketplaceIds_sent'] ) ? $d['marketplaceIds_sent'] : '',
			'enumNames_count'     => isset( $d['enumNames_count'] ) ? $d['enumNames_count'] : 0,
		) );

		if ( empty( $result['success'] ) ) {
			$err = isset( $result['error'] ) ? $result['error'] : __( 'Unbekannter Fehler.', 'wc-amazon-sync' );
			$logger->error( 'Versandvorlagen: API-Fehler', AmazonLogger::SCOPE_IMPORT, array( 'error' => $err ) );
			wp_send_json_error( array( 'message' => $err ) );
		}
		$templates = isset( $result['templates'] ) ? $result['templates'] : array();
		if ( empty( $templates ) ) {
			$logger->warn( 'No seller-specific merchant_shipping_group values. Check sellerId/marketplaceId.', AmazonLogger::SCOPE_IMPORT );
			if ( ! empty( $result['schema_keys'] ) ) {
				$logger->info( 'Versandvorlagen: Schema-Top-Keys (real schema, not wrapper)', AmazonLogger::SCOPE_IMPORT, array( 'keys' => $result['schema_keys'] ) );
			}
			wp_send_json_success( array(
				'templates' => array(),
				'message'   => __( 'Не удалось получить шаблоны — проверь SellerId (SellingPartnerId) и MarketplaceId.', 'wc-amazon-sync' ),
			) );
		}
		set_transient( 'wc_amazon_sync_shipping_templates', $templates, HOUR_IN_SECONDS );
		$logger->info( 'Versandvorlagen geladen', AmazonLogger::SCOPE_IMPORT, array( 'count' => count( $templates ) ) );
		wp_send_json_success( array( 'templates' => $templates ) );
	}
}
