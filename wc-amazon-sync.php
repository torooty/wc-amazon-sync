<?php
/**
 * Plugin Name: Amazon Sync Pro for WooCommerce
 * Plugin URI: https://github.com/torooty/wc-amazon-sync
 * Description: Synchronize products, orders, and inventory between WooCommerce and Amazon using SP-API.
 * Version: 1.0.4
 * Update URI: https://api.webtest24.de/api/v1/plugins/wc-amazon-sync
 * Author: Amazon Sync Pro
 * Author URI: https://github.com/torooty/wc-amazon-sync
 * Text Domain: wc-amazon-sync
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
if ( ! defined( 'WC_AMAZON_SYNC_VERSION' ) ) {
	define( 'WC_AMAZON_SYNC_VERSION', '1.0.4' );
}

if ( ! defined( 'WC_AMAZON_SYNC_PLUGIN_FILE' ) ) {
	define( 'WC_AMAZON_SYNC_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'WC_AMAZON_SYNC_PLUGIN_DIR' ) ) {
	define( 'WC_AMAZON_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WC_AMAZON_SYNC_PLUGIN_URL' ) ) {
	define( 'WC_AMAZON_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'WC_AMAZON_SYNC_PLUGIN_BASENAME' ) ) {
	define( 'WC_AMAZON_SYNC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * Check if WooCommerce is active
 */
function wc_amazon_sync_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_amazon_sync_woocommerce_missing_notice' );
		return false;
	}

	// Check WooCommerce version
	if ( version_compare( WC()->version, '8.0', '<' ) ) {
		add_action( 'admin_notices', 'wc_amazon_sync_woocommerce_version_notice' );
		return false;
	}

	return true;
}

/**
 * Admin notice for missing WooCommerce
 */
function wc_amazon_sync_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Amazon Sync Pro for WooCommerce', 'wc-amazon-sync' ); ?></strong>:
			<?php esc_html_e( 'WooCommerce must be installed and activated to use this plugin.', 'wc-amazon-sync' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Admin notice for WooCommerce version
 */
function wc_amazon_sync_woocommerce_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Amazon Sync Pro for WooCommerce', 'wc-amazon-sync' ); ?></strong>:
			<?php
			printf(
				/* translators: %s: WooCommerce version */
				esc_html__( 'WooCommerce version 8.0 or higher is required. You are running version %s.', 'wc-amazon-sync' ),
				esc_html( WC()->version )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Check PHP version
 */
function wc_amazon_sync_check_php_version() {
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		add_action( 'admin_notices', 'wc_amazon_sync_php_version_notice' );
		return false;
	}
	return true;
}

/**
 * Admin notice for PHP version
 */
function wc_amazon_sync_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Amazon Sync Pro for WooCommerce', 'wc-amazon-sync' ); ?></strong>:
			<?php
			printf(
				/* translators: %s: PHP version */
				esc_html__( 'PHP version 8.1 or higher is required. You are running version %s.', 'wc-amazon-sync' ),
				esc_html( PHP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Load autoloader
 */
function wc_amazon_sync_load_autoloader() {
	$autoloader = WC_AMAZON_SYNC_PLUGIN_DIR . 'vendor/autoload.php';

	if ( file_exists( $autoloader ) ) {
		require_once $autoloader;
		return true;
	}

	return false;
}

/**
 * Initialize the plugin
 */
function wc_amazon_sync_init() {
	// Check requirements
	if ( ! wc_amazon_sync_check_php_version() ) {
		return;
	}

	if ( ! wc_amazon_sync_check_woocommerce() ) {
		return;
	}

	// Load autoloader
	if ( ! wc_amazon_sync_load_autoloader() ) {
		// If composer autoloader doesn't exist, create a simple autoloader
		spl_autoload_register( 'wc_amazon_sync_autoloader' );
	}

	// Initialize plugin
	WCAmazonSync\Plugin::instance();
}

/**
 * Simple autoloader fallback if composer is not used
 */
function wc_amazon_sync_autoloader( $class ) {
	// Only handle our namespace
	if ( strpos( $class, 'WCAmazonSync\\' ) !== 0 ) {
		return;
	}

	// Remove namespace prefix
	$class = str_replace( 'WCAmazonSync\\', '', $class );
	$class = str_replace( '\\', '/', $class );

	// Build file path
	$file = WC_AMAZON_SYNC_PLUGIN_DIR . 'src/' . $class . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

/**
 * Plugin activation function
 *
 * @return void
 */
function wc_amazon_sync_activate() {
	// Register autoloader first
	if ( ! wc_amazon_sync_load_autoloader() ) {
		spl_autoload_register( 'wc_amazon_sync_autoloader' );
	}

	// Now we can use the class
	if ( class_exists( 'WCAmazonSync\Plugin' ) ) {
		WCAmazonSync\Plugin::activate();
	}
}

/**
 * Plugin deactivation function
 *
 * @return void
 */
function wc_amazon_sync_deactivate() {
	// Register autoloader first
	if ( ! wc_amazon_sync_load_autoloader() ) {
		spl_autoload_register( 'wc_amazon_sync_autoloader' );
	}

	// Now we can use the class
	if ( class_exists( 'WCAmazonSync\Plugin' ) ) {
		WCAmazonSync\Plugin::deactivate();
	}
}

// Register autoloader early (before activation hook)
if ( ! wc_amazon_sync_load_autoloader() ) {
	spl_autoload_register( 'wc_amazon_sync_autoloader' );
}

/**
 * Declare WooCommerce compatibility (HPOS - Custom Order Tables)
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Register settings form handler early (must be before admin_init)
 */
add_action( 'admin_init', function() {
	if ( class_exists( 'WCAmazonSync\Admin\SettingsPage' ) ) {
		// This will register the form handler
		WCAmazonSync\Admin\SettingsPage::instance();
	}
}, 1 );

// Hook into plugins_loaded
add_action( 'plugins_loaded', 'wc_amazon_sync_init', 10 );

// Register activation/deactivation hooks
register_activation_hook( __FILE__, 'wc_amazon_sync_activate' );
register_deactivation_hook( __FILE__, 'wc_amazon_sync_deactivate' );

