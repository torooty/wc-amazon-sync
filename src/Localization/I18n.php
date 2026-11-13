<?php
/**
 * Localization Class
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Localization;

defined( 'ABSPATH' ) || exit;

/**
 * I18n Class
 */
class I18n {

	/**
	 * Instance
	 *
	 * @var I18n|null
	 */
	private static ?I18n $instance = null;

	/**
	 * Plugin locale
	 *
	 * @var string
	 */
	private string $locale = '';

	/**
	 * Get instance
	 *
	 * @return I18n
	 */
	public static function instance(): I18n {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_locale();
		add_filter( 'plugin_locale', array( $this, 'filter_plugin_locale' ), 10, 2 );
	}

	/**
	 * Initialize locale
	 *
	 * @return void
	 */
	private function init_locale(): void {
		$settings = $this->get_plugin_settings();
		$ui_language = $settings['ui']['language'] ?? 'auto';
		$force_language = $settings['ui']['force_language'] ?? false;

		if ( 'auto' === $ui_language && ! $force_language ) {
			$this->locale = get_user_locale();
		} elseif ( 'auto' !== $ui_language ) {
			$this->locale = $ui_language;
		} else {
			// Force language is enabled, use saved language
			$this->locale = $ui_language !== 'auto' ? $ui_language : get_user_locale();
		}

		// Apply locale if forcing
		if ( $force_language && $this->locale ) {
			$this->switch_locale( $this->locale );
		}
	}

	/**
	 * Get plugin settings
	 *
	 * @return array
	 */
	private function get_plugin_settings(): array {
		if ( class_exists( 'WCAmazonSync\Settings\SettingsRepository' ) ) {
			$settings_repo = new \WCAmazonSync\Settings\SettingsRepository();
			return $settings_repo->get_all();
		}
		return array();
	}

	/**
	 * Filter plugin locale
	 *
	 * @param string $locale Current locale
	 * @param string $domain Text domain
	 * @return string
	 */
	public function filter_plugin_locale( string $locale, string $domain ): string {
		if ( 'wc-amazon-sync' === $domain && $this->locale ) {
			return $this->locale;
		}
		return $locale;
	}

	/**
	 * Switch to specific locale
	 *
	 * @param string $locale Locale to switch to
	 * @return void
	 */
	private function switch_locale( string $locale ): void {
		if ( function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( $locale );
		}
	}

	/**
	 * Load text domain
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		// Get locale from settings or use default
		$settings = $this->get_plugin_settings();
		$ui_language = $settings['ui']['language'] ?? 'auto';
		$force_language = $settings['ui']['force_language'] ?? false;

		// Debug: log what we got from settings
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'WC Amazon Sync: Settings - ui_language: %s, force_language: %s', var_export( $ui_language, true ), var_export( $force_language, true ) ) );
			error_log( sprintf( 'WC Amazon Sync: Full UI settings: %s', print_r( $settings['ui'] ?? 'not set', true ) ) );
		}

		// Determine locale
		if ( 'auto' === $ui_language && ! $force_language ) {
			$locale = get_user_locale();
		} elseif ( 'auto' !== $ui_language && ! empty( $ui_language ) ) {
			$locale = $ui_language;
		} else {
			$locale = get_user_locale();
		}

		// Apply filter to allow other plugins to modify locale
		$locale = apply_filters( 'plugin_locale', $locale, 'wc-amazon-sync' );

		// Store locale for future use
		$this->locale = $locale;

		// Debug logging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'WC Amazon Sync: Loading translations for locale: %s (ui_language: %s, force: %s)', $locale, $ui_language, $force_language ? 'yes' : 'no' ) );
		}

		// If forcing language, switch locale
		if ( $force_language && function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( $locale );
		}

		// Try to load .mo file from plugin languages directory first
		$mo_file = WC_AMAZON_SYNC_PLUGIN_DIR . 'languages/wc-amazon-sync-' . $locale . '.mo';
		$po_file = WC_AMAZON_SYNC_PLUGIN_DIR . 'languages/wc-amazon-sync-' . $locale . '.po';
		
		$mo_loaded = false;
		if ( file_exists( $mo_file ) ) {
			$mo_loaded = load_textdomain( 'wc-amazon-sync', $mo_file );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $mo_loaded ) {
				error_log( sprintf( 'WC Amazon Sync: Loaded .mo file: %s', $mo_file ) );
			}
		}
		
		// If .mo not loaded, try .po file as fallback
		if ( ! $mo_loaded && file_exists( $po_file ) ) {
			if ( class_exists( 'WCAmazonSync\Localization\PoLoader' ) ) {
				$po_loaded = PoLoader::load_translations( 'wc-amazon-sync', $po_file );
				if ( $po_loaded ) {
					$mo_loaded = true; // Mark as loaded
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( sprintf( 'WC Amazon Sync: Loaded translations from .po file: %s (locale: %s)', $po_file, $locale ) );
					}
				}
			}
		}
		
		// Also try WordPress languages directory
		$wp_mo_file = WP_LANG_DIR . '/wc-amazon-sync/wc-amazon-sync-' . $locale . '.mo';
		if ( ! $mo_loaded && file_exists( $wp_mo_file ) ) {
			$mo_loaded = load_textdomain( 'wc-amazon-sync', $wp_mo_file );
		}
		
		// Load from plugin languages directory using WordPress function (fallback)
		if ( ! $mo_loaded ) {
			load_plugin_textdomain(
				'wc-amazon-sync',
				false,
				dirname( WC_AMAZON_SYNC_PLUGIN_BASENAME ) . '/languages'
			);
		}
		
		// Also check for alternative locale formats (e.g., ru_RU vs ru)
		if ( ! $mo_loaded && strpos( $locale, '_' ) !== false ) {
			$simple_locale = explode( '_', $locale )[0];
			$simple_mo_file = WC_AMAZON_SYNC_PLUGIN_DIR . 'languages/wc-amazon-sync-' . $simple_locale . '.mo';
			$simple_po_file = WC_AMAZON_SYNC_PLUGIN_DIR . 'languages/wc-amazon-sync-' . $simple_locale . '.po';
			
			if ( file_exists( $simple_mo_file ) ) {
				load_textdomain( 'wc-amazon-sync', $simple_mo_file );
			} elseif ( file_exists( $simple_po_file ) && class_exists( 'WCAmazonSync\Localization\PoLoader' ) ) {
				PoLoader::load_translations( 'wc-amazon-sync', $simple_po_file );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'WC Amazon Sync: Loaded translations from .po file (simple locale): %s', $simple_po_file ) );
				}
			}
		}
	}

	/**
	 * Get current locale
	 *
	 * @return string
	 */
	public function get_locale(): string {
		return $this->locale ? $this->locale : get_user_locale();
	}

	/**
	 * Set locale
	 *
	 * @param string $locale Locale code (empty string for auto)
	 * @return void
	 */
	public function set_locale( string $locale ): void {
		if ( empty( $locale ) ) {
			// Reset to auto mode
			$this->locale = '';
			$this->init_locale();
		} else {
			$this->locale = $locale;
			if ( function_exists( 'switch_to_locale' ) ) {
				switch_to_locale( $locale );
			}
		}
	}
}


