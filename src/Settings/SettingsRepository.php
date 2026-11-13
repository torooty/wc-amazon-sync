<?php
/**
 * Settings Repository
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings Repository Class
 */
class SettingsRepository {

	/**
	 * Option name prefix
	 *
	 * @var string
	 */
	private string $option_prefix = 'wc_amazon_sync_';

	/**
	 * Get all settings
	 *
	 * @return array
	 */
	public function get_all(): array {
		$defaults = $this->get_defaults();
		$settings = array();

		foreach ( $defaults as $group => $values ) {
			$option_name = $this->option_prefix . $group;
			$saved = get_option( $option_name, array() );
			$settings[ $group ] = wp_parse_args( $saved, $values );
		}

		return $settings;
	}

	/**
	 * Get setting group
	 *
	 * @param string $group Setting group name
	 * @return array
	 */
	public function get_group( string $group ): array {
		$defaults = $this->get_defaults();
		$default_values = $defaults[ $group ] ?? array();

		$option_name = $this->option_prefix . $group;
		$saved = get_option( $option_name, array() );
		
		$result = wp_parse_args( $saved, $default_values );
		
		// Special handling for use_sandbox - FORCE production if user has real credentials
		if ( 'api' === $group ) {
			// If user has configured real credentials (client_id exists), they want production
			// Production API URL is https://sellingpartnerapi-eu.amazon.com (not sandbox)
			if ( ! empty( $result['client_id'] ) ) {
				// User has real credentials - FORCE production (sandbox is only for testing)
				$result['use_sandbox'] = false;
			} elseif ( isset( $result['use_sandbox'] ) && true === $result['use_sandbox'] ) {
				// If it's true but settings look unconfigured, use production
				if ( empty( $result['client_id'] ) && empty( $saved['client_id'] ) ) {
					$result['use_sandbox'] = false;
				}
			}
		}

		return $result;
	}

	/**
	 * Get single setting
	 *
	 * @param string $group Setting group
	 * @param string $key Setting key
	 * @param mixed  $default Default value
	 * @return mixed
	 */
	public function get( string $group, string $key, $default = null ) {
		$group_settings = $this->get_group( $group );
		return $group_settings[ $key ] ?? $default;
	}

	/**
	 * Set setting group
	 *
	 * @param string $group Setting group
	 * @param array  $values Settings values
	 * @return bool
	 */
	public function set_group( string $group, array $values ): bool {
		$option_name = $this->option_prefix . $group;
		
		// Use autoload = false to avoid loading on every request
		$result = update_option( $option_name, $values, false );
		
		// Log for debugging (remove in production)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "WC Amazon Sync: Updating option '{$option_name}'. Result: " . ( $result ? 'true' : 'false' ) );
			error_log( "WC Amazon Sync: Option value: " . print_r( $values, true ) );
			error_log( "WC Amazon Sync: Current option value: " . print_r( get_option( $option_name ), true ) );
		}
		
		return $result;
	}

	/**
	 * Set single setting
	 *
	 * @param string $group Setting group
	 * @param string $key Setting key
	 * @param mixed  $value Setting value
	 * @return bool
	 */
	public function set( string $group, string $key, $value ): bool {
		$group_settings = $this->get_group( $group );
		$group_settings[ $key ] = $value;
		return $this->set_group( $group, $group_settings );
	}

	/**
	 * Set default settings
	 *
	 * @return void
	 */
	public function set_defaults(): void {
		$defaults = $this->get_defaults();

		foreach ( $defaults as $group => $values ) {
			$option_name = $this->option_prefix . $group;
			if ( false === get_option( $option_name ) ) {
				add_option( $option_name, $values );
			}
		}
	}

	/**
	 * Get default settings
	 *
	 * @return array
	 */
	private function get_defaults(): array {
		return array(
			'api' => array(
				'region' => 'eu-west-1',
				'seller_id' => '',
				'marketplace_id' => '',
				'client_id' => '',
				'client_secret' => '',
				'refresh_token' => '',
				'use_sandbox' => false, // Default to production, not sandbox
			),
			'mapping' => array(
				'default_mapping_type' => 'sku', // sku|asin|ean|custom_field
				'custom_field_name' => '_ean',
			),
			'sync' => array(
				'inventory_direction' => 'woo_to_amazon', // woo_to_amazon|amazon_to_woo|bidirectional
				'sync_frequency' => 3600, // seconds
				'auto_sync_on_save' => true,
			),
			'ui' => array(
				'language' => 'auto', // auto|en_US|de_DE|ru_RU
				'force_language' => false,
			),
		);
	}

	/**
	 * Validate settings
	 *
	 * @param string $group Setting group
	 * @param array  $values Settings values
	 * @return array|WP_Error Array of validated settings or WP_Error on failure
	 */
	public function validate( string $group, array $values ) {
		$defaults = $this->get_defaults();
		$group_defaults = $defaults[ $group ] ?? array();

		$validated = array();

		foreach ( $group_defaults as $key => $default_value ) {
			if ( ! isset( $values[ $key ] ) ) {
				$validated[ $key ] = $default_value;
				continue;
			}

			$value = $values[ $key ];

			// Sanitize based on type
			switch ( $group ) {
				case 'api':
					$validated[ $key ] = $this->sanitize_api_setting( $key, $value );
					break;

				case 'mapping':
					$validated[ $key ] = $this->sanitize_mapping_setting( $key, $value );
					break;

				case 'sync':
					$validated[ $key ] = $this->sanitize_sync_setting( $key, $value );
					break;

				case 'ui':
					$validated[ $key ] = $this->sanitize_ui_setting( $key, $value );
					break;

				default:
					$validated[ $key ] = sanitize_text_field( $value );
			}
		}

		return $validated;
	}

	/**
	 * Sanitize API setting
	 *
	 * @param string $key Setting key
	 * @param mixed  $value Setting value
	 * @return mixed
	 */
	private function sanitize_api_setting( string $key, $value ) {
		switch ( $key ) {
			case 'region':
				return sanitize_text_field( $value );
			case 'seller_id':
			case 'marketplace_id':
			case 'client_id':
			case 'client_secret':
			case 'refresh_token':
				return sanitize_text_field( $value );
			case 'use_sandbox':
				return (bool) $value;
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitize mapping setting
	 *
	 * @param string $key Setting key
	 * @param mixed  $value Setting value
	 * @return mixed
	 */
	private function sanitize_mapping_setting( string $key, $value ) {
		switch ( $key ) {
			case 'default_mapping_type':
				$allowed = array( 'sku', 'asin', 'ean', 'custom_field' );
				return in_array( $value, $allowed, true ) ? $value : 'sku';
			case 'custom_field_name':
				return sanitize_key( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitize sync setting
	 *
	 * @param string $key Setting key
	 * @param mixed  $value Setting value
	 * @return mixed
	 */
	private function sanitize_sync_setting( string $key, $value ) {
		switch ( $key ) {
			case 'inventory_direction':
				$allowed = array( 'woo_to_amazon', 'amazon_to_woo', 'bidirectional' );
				return in_array( $value, $allowed, true ) ? $value : 'woo_to_amazon';
			case 'sync_frequency':
				return absint( $value );
			case 'auto_sync_on_save':
				return (bool) $value;
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitize UI setting
	 *
	 * @param string $key Setting key
	 * @param mixed  $value Setting value
	 * @return mixed
	 */
	private function sanitize_ui_setting( string $key, $value ) {
		switch ( $key ) {
			case 'language':
				$allowed = array( 'auto', 'en_US', 'de_DE', 'ru_RU' );
				return in_array( $value, $allowed, true ) ? $value : 'auto';
			case 'force_language':
				return (bool) $value;
			default:
				return sanitize_text_field( $value );
		}
	}
}

