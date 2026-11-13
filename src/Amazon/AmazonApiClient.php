<?php
/**
 * Amazon API Client
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Amazon;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Settings\SettingsRepository;
use WCAmazonSync\Logger\Logger;

/**
 * Amazon SP-API Client Implementation
 */
class AmazonApiClient implements AmazonApiClientInterface {

	/**
	 * Settings repository
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Logger instance
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * API endpoint base URL
	 *
	 * @var string
	 */
	private string $api_base_url;

	/**
	 * Constructor
	 *
	 * @param SettingsRepository|null $settings Settings repository
	 * @param Logger|null             $logger Logger instance
	 */
	public function __construct( ?SettingsRepository $settings = null, ?Logger $logger = null ) {
		$this->settings = $settings ?? new SettingsRepository();
		$this->logger = $logger ?? Logger::instance();
		$this->init_endpoint();
	}

	/**
	 * Initialize API endpoint
	 *
	 * @return void
	 */
	private function init_endpoint(): void {
		$api_settings = $this->settings->get_group( 'api' );
		$region = $api_settings['region'] ?? 'eu-west-1';
		// Default to production, not sandbox - explicitly check if it's set, otherwise default to false
		$use_sandbox = isset( $api_settings['use_sandbox'] ) ? (bool) $api_settings['use_sandbox'] : false;
		
		// Log for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'WC Amazon Sync: API Settings - use_sandbox: %s (raw: %s)', $use_sandbox ? 'true' : 'false', var_export( $api_settings['use_sandbox'] ?? 'not set', true ) ) );
		}

		// Amazon SP-API endpoint mapping by region
		$endpoints = array(
			'eu-west-1' => array(
				'sandbox' => 'https://sandbox.sellingpartnerapi-eu.amazon.com',
				'production' => 'https://sellingpartnerapi-eu.amazon.com',
			),
			'us-east-1' => array(
				'sandbox' => 'https://sandbox.sellingpartnerapi-na.amazon.com',
				'production' => 'https://sellingpartnerapi-na.amazon.com',
			),
			'us-west-2' => array(
				'sandbox' => 'https://sandbox.sellingpartnerapi-na.amazon.com',
				'production' => 'https://sellingpartnerapi-na.amazon.com',
			),
			'ap-southeast-1' => array(
				'sandbox' => 'https://sandbox.sellingpartnerapi-fe.amazon.com',
				'production' => 'https://sellingpartnerapi-fe.amazon.com',
			),
		);

		$region_key = $region;
		if ( ! isset( $endpoints[ $region_key ] ) ) {
			// Default to EU if region not found
			$region_key = 'eu-west-1';
		}

		if ( $use_sandbox ) {
			$this->api_base_url = $endpoints[ $region_key ]['sandbox'];
		} else {
			$this->api_base_url = $endpoints[ $region_key ]['production'];
		}

		$this->logger->debug( 'Amazon SP-API endpoint: ' . $this->api_base_url );
	}

	/**
	 * Format datetime for Amazon SP-API query params (strict ISO8601 UTC with Z suffix).
	 *
	 * @param int|string|null $datetime Unix timestamp or parseable date string.
	 * @return string e.g. 2026-05-01T00:00:00Z
	 */
	public static function format_iso8601_z( $datetime = null ): string {
		if ( null === $datetime ) {
			$ts = time() - ( 30 * DAY_IN_SECONDS );
		} elseif ( is_numeric( $datetime ) ) {
			$ts = (int) $datetime;
		} else {
			$ts = strtotime( (string) $datetime );
			if ( ! $ts ) {
				$ts = time() - ( 30 * DAY_IN_SECONDS );
			}
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/**
	 * Test API connection
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function test_connection() {
		try {
			// Try to make a simple API call to test connection
			// If seller_id is not set, we'll try to get it from marketplaceParticipations
			$api_settings = $this->settings->get_group( 'api' );
			$seller_id = $api_settings['seller_id'] ?? '';

			if ( empty( $seller_id ) ) {
				// Try to get seller info from marketplaceParticipations endpoint
				$response = $this->make_request( 'GET', '/sellers/v1/marketplaceParticipations' );
				
				if ( ! is_wp_error( $response ) && ! empty( $response['payload'] ) ) {
					// Extract seller ID from response if available
					$participations = $response['payload'] ?? array();
					if ( ! empty( $participations ) ) {
						// Try to get seller ID from first participation
						$first_participation = reset( $participations );
						if ( isset( $first_participation['seller']['sellerId'] ) ) {
							$this->settings->set( 'api', 'seller_id', $first_participation['seller']['sellerId'] );
							$this->logger->info( 'Seller ID retrieved automatically from API response' );
						}
					}
				}
			} else {
				$response = $this->make_request( 'GET', '/sellers/v1/marketplaceParticipations' );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->logger->info( 'Amazon API connection test successful' );
			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Amazon API connection test failed: ' . $e->getMessage() );
			return new \WP_Error( 'amazon_api_test_failed', $e->getMessage() );
		}
	}

	/**
	 * Get catalog items (using Catalog API - may require different permissions)
	 *
	 * @param array $args Query arguments
	 * @return array|WP_Error
	 */
	public function get_catalog_items( array $args = array() ) {
		$defaults = array(
			'keywords' => '',
			'marketplaceIds' => array(),
			'identifiers' => array(),
			'identifiersType' => 'ASIN',
			'includedData' => array( 'summaries', 'attributes' ),
		);

		$args = wp_parse_args( $args, $defaults );

		$api_settings = $this->settings->get_group( 'api' );
		$marketplace_id = $api_settings['marketplace_id'] ?? '';

		if ( empty( $args['marketplaceIds'] ) && ! empty( $marketplace_id ) ) {
			$args['marketplaceIds'] = array( $marketplace_id );
		}

		return $this->make_request( 'POST', '/catalog/2022-04-01/items/search', $args );
	}

	/**
	 * Get merchant listings using Reports API (more reliable method)
	 *
	 * @param array $args Optional arguments
	 * @return array|WP_Error Array of products or WP_Error
	 */
	public function get_merchant_listings( array $args = array() ) {
		$api_settings = $this->settings->get_group( 'api' );
		$marketplace_id = $api_settings['marketplace_id'] ?? '';

		if ( empty( $marketplace_id ) ) {
			return new \WP_Error( 'missing_marketplace_id', __( 'Marketplace ID is required.', 'wc-amazon-sync' ) );
		}

		// Step 1: Create report
		$this->logger->info( 'Creating merchant listings report...' );
		$report_response = $this->create_report( 'GET_MERCHANT_LISTINGS_ALL_DATA', array( $marketplace_id ) );
		
		if ( is_wp_error( $report_response ) ) {
			return $report_response;
		}

		$report_id = $report_response['reportId'] ?? '';
		if ( empty( $report_id ) ) {
			return new \WP_Error( 'no_report_id', __( 'Failed to create report: no report ID returned.', 'wc-amazon-sync' ) );
		}

		$this->logger->info( sprintf( 'Report created: %s. Waiting for completion...', $report_id ) );

		// Step 2: Wait for report to be ready
		$max_attempts = 20; // 20 attempts with 10 seconds delay = ~3.3 minutes max
		$document_id = null;

		for ( $i = 0; $i < $max_attempts; $i++ ) {
			sleep( 10 ); // Wait 10 seconds between checks

			$status_response = $this->get_report_status( $report_id );
			
			if ( is_wp_error( $status_response ) ) {
				return $status_response;
			}

			$status = $status_response['processingStatus'] ?? '';

			if ( 'DONE' === $status ) {
				$document_id = $status_response['reportDocumentId'] ?? '';
				if ( ! empty( $document_id ) ) {
					$this->logger->info( sprintf( 'Report ready. Document ID: %s', $document_id ) );
					break;
				}
			}

			$this->logger->debug( sprintf( 'Report status check %d/%d: %s', $i + 1, $max_attempts, $status ) );
		}

		if ( empty( $document_id ) ) {
			return new \WP_Error( 'report_timeout', __( 'Report did not complete within timeout period.', 'wc-amazon-sync' ) );
		}

		// Step 3: Get document URL
		$document_response = $this->get_report_document( $document_id );
		
		if ( is_wp_error( $document_response ) ) {
			return $document_response;
		}

		$download_url = $document_response['url'] ?? '';
		if ( empty( $download_url ) ) {
			return new \WP_Error( 'no_document_url', __( 'Failed to get document URL.', 'wc-amazon-sync' ) );
		}

		// Step 4: Download and parse TSV
		$this->logger->info( 'Downloading report document...' );
		$products = $this->download_and_parse_report( $download_url );

		if ( is_wp_error( $products ) ) {
			return $products;
		}

		$this->logger->info( sprintf( 'Successfully imported %d products from report.', count( $products ) ) );

		return $products;
	}

	/**
	 * Create a report
	 *
	 * @param string $report_type Report type (e.g., GET_MERCHANT_LISTINGS_ALL_DATA)
	 * @param array  $marketplace_ids Marketplace IDs
	 * @return array|WP_Error
	 */
	public function create_report( string $report_type, array $marketplace_ids ) {
		// Reports API requires marketplaceIds in the body, not as query parameter
		$payload = array(
			'reportType' => $report_type,
			'marketplaceIds' => $marketplace_ids,
		);

		$this->logger->debug( 'Creating report with payload: ' . print_r( $payload, true ) );

		// Get access token first
		$access_token = $this->get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		// Check if we're in sandbox mode - sandbox may not support all report types
		$api_settings = $this->settings->get_group( 'api' );
		$use_sandbox = $api_settings['use_sandbox'] ?? false;
		
		// Note: Sandbox may not support GET_MERCHANT_LISTINGS_ALL_DATA
		// But we'll try anyway - if it fails, we'll provide a helpful error message

		$endpoint = '/reports/2021-06-30/reports';
		$url = $this->api_base_url . $endpoint;

		$headers = array(
			'x-amz-access-token' => $access_token,
			'Content-Type' => 'application/json',
		);

		$body_json = wp_json_encode( $payload );
		$this->logger->debug( 'Report request body JSON: ' . $body_json );
		$this->logger->debug( 'Report API URL: ' . $url );
		$this->logger->debug( 'Sandbox mode: ' . ( $use_sandbox ? 'yes' : 'no' ) );

		$request_args = array(
			'method' => 'POST',
			'headers' => $headers,
			'body' => $body_json,
			'timeout' => 30,
			'sslverify' => true,
			'user-agent' => 'WCAmazonSync/' . WC_AMAZON_SYNC_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
		);

		$this->logger->debug( sprintf( 'Amazon API request: POST %s', $url ) );
		$this->logger->debug( 'Request headers: ' . print_r( $headers, true ) );

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Amazon API request failed: ' . $response->get_error_message() );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$this->logger->debug( sprintf( 'Amazon API response: %d', $status_code ) );
		$this->logger->debug( 'Response body: ' . $body );

		if ( $status_code >= 400 ) {
			$error_data = json_decode( $body, true );
			$error_message = '';
			
			if ( isset( $error_data['errors'] ) && is_array( $error_data['errors'] ) ) {
				$errors = array();
				foreach ( $error_data['errors'] as $error ) {
					$code = $error['code'] ?? 'Unknown';
					$msg = $error['message'] ?? 'Unknown error';
					$errors[] = sprintf( '%s: %s', $code, $msg );
				}
				$error_message = implode( '; ', $errors );
				
				// Special handling for Sandbox + GET_MERCHANT_LISTINGS_ALL_DATA
				if ( $use_sandbox && 'GET_MERCHANT_LISTINGS_ALL_DATA' === $report_type && 400 === $status_code && 'InvalidInput' === ( $error_data['errors'][0]['code'] ?? '' ) ) {
					$error_message = __( 'GET_MERCHANT_LISTINGS_ALL_DATA is not supported in Sandbox environment. Please disable "Use Sandbox" option in settings and use Production API.', 'wc-amazon-sync' ) . ' ' . $error_message;
				}
			} else {
				$error_message = $body;
			}
			
			$full_error = sprintf(
				/* translators: %1$d: HTTP status code, %2$s: Error message */
				__( 'Amazon API error: %1$d - %2$s', 'wc-amazon-sync' ),
				$status_code,
				$error_message
			);
			$this->logger->error( $full_error );
			return new \WP_Error( 'amazon_api_error', $full_error, array( 'status' => $status_code, 'response' => $error_data ) );
		}

		$decoded = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$error = __( 'Failed to parse Amazon API response.', 'wc-amazon-sync' );
			$this->logger->error( $error );
			return new \WP_Error( 'json_decode_error', $error );
		}

		$this->logger->debug( 'Amazon API request successful' );

		return $decoded;
	}

	/**
	 * Get report status
	 *
	 * @param string $report_id Report ID
	 * @return array|WP_Error
	 */
	public function get_report_status( string $report_id ) {
		return $this->make_request( 'GET', '/reports/2021-06-30/reports/' . $report_id );
	}

	/**
	 * Get report document details
	 *
	 * @param string $document_id Document ID
	 * @return array|WP_Error
	 */
	public function get_report_document( string $document_id ) {
		return $this->make_request( 'GET', '/reports/2021-06-30/documents/' . $document_id );
	}

	/**
	 * Download and parse TSV report
	 *
	 * @param string $url Download URL
	 * @return array|WP_Error Array of products or WP_Error
	 */
	private function download_and_parse_report( string $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 120 ) );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Failed to download report: ' . $response->get_error_message() );
			return $response;
		}

		$csv_data = wp_remote_retrieve_body( $response );

		if ( empty( $csv_data ) ) {
			return new \WP_Error( 'empty_report', __( 'Report document is empty.', 'wc-amazon-sync' ) );
		}

		// Detect and convert encoding (Amazon often returns Windows-1252)
		$encoding = mb_detect_encoding( $csv_data, array( 'UTF-8', 'Windows-1252', 'ISO-8859-1' ), true );
		if ( $encoding && 'UTF-8' !== $encoding ) {
			$csv_data = mb_convert_encoding( $csv_data, 'UTF-8', $encoding );
			$this->logger->debug( sprintf( 'Converted encoding from %s to UTF-8', $encoding ) );
		}

		// Parse TSV using fgetcsv() like in the working PHP script
		// Create temporary file to use with fgetcsv
		$tmp = tmpfile();
		if ( ! $tmp ) {
			return new \WP_Error( 'tmp_file_error', __( 'Failed to create temporary file.', 'wc-amazon-sync' ) );
		}

		fwrite( $tmp, $csv_data );
		rewind( $tmp );

		$header = null;
		$products = array();
		$row_count = 0;

		// Read TSV line by line using fgetcsv with tab delimiter
		while ( ( $data = fgetcsv( $tmp, 0, "\t" ) ) !== false ) {
			$row_count++;

			// Skip empty rows
			if ( empty( $data ) || ( count( $data ) === 1 && empty( trim( $data[0] ) ) ) ) {
				continue;
			}

			if ( null === $header ) {
				// First non-empty line is header
				$header = $data;
				$this->logger->debug( sprintf( 'TSV Header found with %d columns', count( $header ) ) );
				$this->logger->debug( sprintf( 'Header columns: %s', implode( ', ', array_map( 'trim', $header ) ) ) );
				continue;
			}

			// Create associative array from header and data
			$product = array();
			foreach ( $header as $index => $key ) {
				$clean_key = trim( $key ); // Clean header key
				$product[ $clean_key ] = isset( $data[ $index ] ) ? trim( $data[ $index ] ) : '';
			}

			// Normalize product data to standard format (convert German column names to English)
			$normalized = array();
			
			// Map SKU - check various column names
			$normalized['sku'] = $product['sku'] ?? $product['seller-sku'] ?? $product['Seller SKU'] ?? $product['SKU'] 
				?? $product['Händler-SKU'] ?? $product['Händler - SKU'] ?? '';
			
			// Map ASIN - check various column names
			$normalized['asin'] = $product['asin'] ?? $product['ASIN'] ?? $product['product-id'] ?? $product['Produkt-ID']
				?? $product['ASIN 1'] ?? $product['asin1'] ?? $product['asin2'] ?? $product['asin3'] ?? '';
			
			// Map Title - check various column names
			$normalized['title'] = $product['title'] ?? $product['Title'] ?? $product['name'] ?? $product['Name']
				?? $product['Artikelbezeichnung'] ?? '';
			
			// Map Price - check various column names
			$price_str = $product['price'] ?? $product['Price'] ?? $product['Preis'] ?? '';
			// Clean price string (remove currency symbols, spaces)
			$price_str = preg_replace( '/[^\d.,]/', '', $price_str );
			// Replace comma with dot for decimal
			$price_str = str_replace( ',', '.', $price_str );
			$normalized['price'] = ! empty( $price_str ) ? floatval( $price_str ) : 0;
			
			// Map Stock/Quantity - check various column names
			$stock_str = $product['quantity'] ?? $product['Quantity'] ?? $product['stock'] ?? $product['Stock']
				?? $product['Menge'] ?? '';
			$normalized['stock'] = ! empty( $stock_str ) ? absint( $stock_str ) : 0;
			$normalized['quantity'] = $normalized['stock']; // Alias
			
			// Map Description
			$normalized['description'] = $product['description'] ?? $product['Description'] 
				?? $product['Artikelbeschreibung'] ?? '';
			
			// Map Image URL
			$normalized['image_url'] = $product['image-url'] ?? $product['image_url'] ?? $product['image'] 
				?? $product['Image URL'] ?? '';
			
			// Keep all original data for reference
			$normalized['raw_data'] = $product;

			// Skip rows without SKU or ASIN
			if ( empty( $normalized['sku'] ) && empty( $normalized['asin'] ) ) {
				// Log first few rows to debug
				if ( $row_count <= 3 ) {
					$this->logger->debug( sprintf( 'Row %d - Skipped: no SKU or ASIN. Raw data sample: %s', $row_count, print_r( array_slice( $product, 0, 5, true ), true ) ) );
				}
				continue;
			}

			$products[] = $normalized;
		}

		fclose( $tmp );

		$this->logger->debug( sprintf( 'Parsed TSV: %d total rows, %d products found', $row_count, count( $products ) ) );

		if ( empty( $products ) && $row_count > 0 ) {
			$this->logger->warning( sprintf( 'No products found in report. Header: %s', print_r( $header, true ) ) );
		}

		return $products;
	}

	/**
	 * Get inventory summary
	 *
	 * @param array $skus Product SKUs (optional)
	 * @return array|WP_Error
	 */
	public function get_inventory_summary( array $skus = array() ) {
		$api_settings = $this->settings->get_group( 'api' );
		$seller_id = $api_settings['seller_id'] ?? '';

		$query_params = array(
			'details' => 'true',
			'granularityType' => 'Marketplace',
			'granularityId' => $api_settings['marketplace_id'] ?? '',
		);

		if ( ! empty( $skus ) ) {
			$query_params['sellerSkus'] = implode( ',', array_slice( $skus, 0, 50 ) ); // Max 50 SKUs
		}

		$url = '/fba/inventory/v1/summaries?' . http_build_query( $query_params );

		return $this->make_request( 'GET', $url );
	}

	/**
	 * Update inventory using Feeds API
	 *
	 * @param array $items Inventory items to update
	 * @return array|WP_Error
	 */
	public function update_inventory( array $items ) {
		if ( empty( $items ) ) {
			return new \WP_Error( 'empty_items', __( 'No inventory items provided.', 'wc-amazon-sync' ) );
		}

		// SP-API uses Feeds API for inventory updates
		// Feed type: POST_INVENTORY_AVAILABILITY_DATA
		$api_settings = $this->settings->get_group( 'api' );
		$marketplace_id = $api_settings['marketplace_id'] ?? '';

		if ( empty( $marketplace_id ) ) {
			return new \WP_Error( 'no_marketplace', __( 'Marketplace ID is not configured.', 'wc-amazon-sync' ) );
		}

		// Build feed document (TSV format for inventory)
		$feed_lines = array( 'sku\tquantity' ); // Header
		foreach ( $items as $item ) {
			$sku = $item['sku'] ?? '';
			$quantity = absint( $item['quantity'] ?? 0 );
			if ( ! empty( $sku ) ) {
				$feed_lines[] = $sku . "\t" . $quantity;
			}
		}

		$feed_content = implode( "\n", $feed_lines );

		// Step 1: Create feed document
		$document_response = $this->create_feed_document( $feed_content );
		if ( is_wp_error( $document_response ) ) {
			return $document_response;
		}

		$feed_document_id = $document_response['feedDocumentId'] ?? '';
		$feed_document_url = $document_response['url'] ?? '';

		if ( empty( $feed_document_id ) || empty( $feed_document_url ) ) {
			return new \WP_Error( 'no_feed_document', __( 'Failed to create feed document.', 'wc-amazon-sync' ) );
		}

		// Step 2: Upload feed content
		$upload_response = wp_remote_request(
			$feed_document_url,
			array(
				'method' => 'PUT',
				'headers' => array(
					'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
				),
				'body' => $feed_content,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $upload_response ) ) {
			return $upload_response;
		}

		// Step 3: Create feed
		$feed_response = $this->create_feed( 'POST_INVENTORY_AVAILABILITY_DATA', array( $marketplace_id ), $feed_document_id );
		if ( is_wp_error( $feed_response ) ) {
			return $feed_response;
		}

		$feed_id = $feed_response['feedId'] ?? '';
		if ( empty( $feed_id ) ) {
			return new \WP_Error( 'no_feed_id', __( 'Failed to create feed.', 'wc-amazon-sync' ) );
		}

		$this->logger->info( sprintf( 'Inventory feed created: %s for %d items', $feed_id, count( $items ) ) );

		return array(
			'feedId' => $feed_id,
			'items_count' => count( $items ),
		);
	}

	/**
	 * Update product price
	 *
	 * @param string $asin Amazon ASIN
	 * @param float  $price Price amount
	 * @param string $currency Currency code (default: EUR)
	 * @return array|WP_Error
	 */
	public function update_price( string $asin, float $price, string $currency = 'EUR' ) {
		$api_settings = $this->settings->get_group( 'api' );

		$payload = array(
			'marketplaceId' => $api_settings['marketplace_id'] ?? '',
			'itemPrice' => array(
				array(
					'sellerSku' => '', // Will be retrieved from ASIN mapping
					'price' => array(
						'amount' => (string) $price,
						'currencyCode' => $currency,
					),
				),
			),
		);

		// Use Pricing API endpoint
		// Note: This is a simplified implementation - actual SP-API pricing endpoint may differ
		return $this->make_request( 'POST', '/pricing/v1/items/' . $asin . '/offers', $payload );
	}

	/**
	 * Update inventory for single product
	 *
	 * @param string $sku Product SKU
	 * @param int    $quantity Quantity
	 * @return array|WP_Error
	 */
	public function update_single_inventory( string $sku, int $quantity ) {
		return $this->update_inventory(
			array(
				array(
					'sku' => $sku,
					'quantity' => $quantity,
				),
			)
		);
	}

	/**
	 * Create feed document
	 *
	 * @param string $content Feed content
	 * @return array|WP_Error
	 */
	private function create_feed_document( string $content ) {
		$access_token = $this->get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$endpoint = '/feeds/2021-06-30/documents';
		$url = $this->api_base_url . $endpoint;

		$payload = array(
			'contentType' => 'text/tab-separated-values; charset=UTF-8',
		);

		$headers = array(
			'x-amz-access-token' => $access_token,
			'Content-Type' => 'application/json',
		);

		$request_args = array(
			'method' => 'POST',
			'headers' => $headers,
			'body' => wp_json_encode( $payload ),
			'timeout' => 30,
		);

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Failed to create feed document: ' . $response->get_error_message() );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $status_code >= 400 ) {
			$error_data = json_decode( $body, true );
			$error_message = $error_data['errors'][0]['message'] ?? $body;
			$this->logger->error( sprintf( 'Feed document creation failed: %d - %s', $status_code, $error_message ) );
			return new \WP_Error( 'feed_document_error', sprintf( __( 'Amazon API error: %1$d - %2$s', 'wc-amazon-sync' ), $status_code, $error_message ) );
		}

		return json_decode( $body, true );
	}

	/**
	 * Create feed
	 *
	 * @param string $feed_type Feed type (e.g., POST_INVENTORY_AVAILABILITY_DATA)
	 * @param array  $marketplace_ids Marketplace IDs
	 * @param string $feed_document_id Feed document ID
	 * @return array|WP_Error
	 */
	private function create_feed( string $feed_type, array $marketplace_ids, string $feed_document_id ) {
		$access_token = $this->get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$endpoint = '/feeds/2021-06-30/feeds';
		$url = $this->api_base_url . $endpoint;

		$payload = array(
			'feedType' => $feed_type,
			'marketplaceIds' => $marketplace_ids,
			'inputFeedDocumentId' => $feed_document_id,
		);

		$headers = array(
			'x-amz-access-token' => $access_token,
			'Content-Type' => 'application/json',
		);

		$request_args = array(
			'method' => 'POST',
			'headers' => $headers,
			'body' => wp_json_encode( $payload ),
			'timeout' => 30,
		);

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Failed to create feed: ' . $response->get_error_message() );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $status_code >= 400 ) {
			$error_data = json_decode( $body, true );
			$error_message = $error_data['errors'][0]['message'] ?? $body;
			$this->logger->error( sprintf( 'Feed creation failed: %d - %s', $status_code, $error_message ) );
			return new \WP_Error( 'feed_error', sprintf( __( 'Amazon API error: %1$d - %2$s', 'wc-amazon-sync' ), $status_code, $error_message ) );
		}

		return json_decode( $body, true );
	}

	/**
	 * Create or update listing
	 *
	 * @param string $asin Amazon ASIN
	 * @param array  $data Product data
	 * @return array|WP_Error
	 */
	public function create_or_update_listing( string $asin, array $data ) {
		$api_settings = $this->settings->get_group( 'api' );

		$payload = array(
			'marketplaceId' => $api_settings['marketplace_id'] ?? '',
			'productType' => $data['product_type'] ?? 'PRODUCT',
			'requirements' => 'LISTING',
			'attributes' => $data['attributes'] ?? array(),
		);

		return $this->make_request( 'POST', '/listings/2021-08-01/items/' . $asin, $payload );
	}

	/**
	 * Get orders
	 *
	 * @param array $args Query arguments
	 * @return array|WP_Error
	 */
	public function get_orders( array $args = array() ) {
		$defaults = array(
			'MarketplaceIds'    => array(),
			'CreatedAfter'      => self::format_iso8601_z( strtotime( '-30 days' ) ),
			'MaxResultsPerPage' => 100,
		);

		$args = wp_parse_args( $args, $defaults );

		$api_settings = $this->settings->get_group( 'api' );
		if ( empty( $args['MarketplaceIds'] ) && ! empty( $api_settings['marketplace_id'] ) ) {
			$args['MarketplaceIds'] = array( $api_settings['marketplace_id'] );
		}

		$date_keys = array( 'CreatedAfter', 'CreatedBefore', 'LastUpdatedAfter', 'LastUpdatedBefore' );
		foreach ( $date_keys as $date_key ) {
			if ( ! empty( $args[ $date_key ] ) ) {
				$args[ $date_key ] = self::format_iso8601_z( $args[ $date_key ] );
			}
		}

		$query_params = array();
		foreach ( $args as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				if ( in_array( $key, array( 'OrderStatuses', 'FulfillmentChannels', 'PaymentMethods', 'AmazonOrderIds' ), true ) ) {
					$query_params[] = $key . '=' . rawurlencode( implode( ',', array_map( 'strval', $value ) ) );
				} else {
					foreach ( $value as $item ) {
						$query_params[] = $key . '=' . rawurlencode( (string) $item );
					}
				}
			} else {
				$query_params[] = $key . '=' . rawurlencode( (string) $value );
			}
		}
		sort( $query_params );

		$url = '/orders/v0/orders';
		if ( ! empty( $query_params ) ) {
			$url .= '?' . implode( '&', $query_params );
		}

		return $this->make_request( 'GET', $url );
	}

	/**
	 * Fetch all orders with NextToken pagination.
	 *
	 * @param array $args Query arguments for get_orders (without NextToken).
	 * @return array|WP_Error List of order arrays from payload.Orders
	 */
	public function fetch_all_orders( array $args = array() ) {
		$all_orders = array();
		$next_token = null;
		$page       = 0;

		do {
			$page_args = $args;
			if ( $next_token ) {
				$page_args['NextToken'] = $next_token;
			}

			$response = $this->get_orders( $page_args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$payload = isset( $response['payload'] ) && is_array( $response['payload'] ) ? $response['payload'] : array();
			$orders  = isset( $payload['Orders'] ) && is_array( $payload['Orders'] ) ? $payload['Orders'] : array();
			$all_orders = array_merge( $all_orders, $orders );
			$next_token = isset( $payload['NextToken'] ) && $payload['NextToken'] !== '' ? $payload['NextToken'] : null;
			$page++;

			if ( $page > 50 ) {
				break;
			}
		} while ( $next_token );

		return $all_orders;
	}

	/**
	 * Get order by ID
	 *
	 * @param string $order_id Order ID
	 * @return array|WP_Error
	 */
	public function get_order( string $order_id ) {
		return $this->make_request( 'GET', '/orders/v0/orders/' . rawurlencode( $order_id ) );
	}

	/**
	 * Get order items by Amazon order ID
	 *
	 * @param string $order_id Amazon order ID
	 * @return array|WP_Error
	 */
	public function get_order_items( string $order_id ) {
		return $this->make_request( 'GET', '/orders/v0/orders/' . rawurlencode( $order_id ) . '/orderItems' );
	}

	/**
	 * Create Restricted Data Token for PII (shipping address, buyer info).
	 *
	 * @param array $restricted_resources List of resource descriptors.
	 * @return array|WP_Error
	 */
	public function create_restricted_data_token( array $restricted_resources ) {
		return $this->make_request(
			'POST',
			'/tokens/2021-03-01/restrictedDataToken',
			array( 'restrictedResources' => $restricted_resources )
		);
	}

	/**
	 * Get RDT string for a restricted path.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path (unencoded), e.g. /orders/v0/orders/123-456.
	 * @param array  $data_elements Optional dataElements.
	 * @return string|WP_Error
	 */
	public function get_restricted_data_token( string $method, string $path, array $data_elements = array() ) {
		$resource = array(
			'method' => $method,
			'path'   => $path,
		);
		if ( ! empty( $data_elements ) ) {
			$resource['dataElements'] = array_values( $data_elements );
		}

		$response = $this->create_restricted_data_token( array( $resource ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$token = isset( $response['restrictedDataToken'] ) ? (string) $response['restrictedDataToken'] : '';
		if ( $token === '' ) {
			return new \WP_Error( 'no_rdt', __( 'Amazon did not return a restricted data token.', 'wc-amazon-sync' ) );
		}

		return $token;
	}

	/**
	 * Get order with shipping address and buyer info (RDT).
	 *
	 * @param string $order_id Amazon order ID.
	 * @return array|WP_Error
	 */
	public function get_order_with_pii( string $order_id ) {
		$path = '/orders/v0/orders/' . $order_id;
		$rdt  = $this->get_restricted_data_token( 'GET', $path, array( 'buyerInfo', 'shippingAddress' ) );
		if ( is_wp_error( $rdt ) ) {
			return $this->get_order( $order_id );
		}

		$response = $this->make_request( 'GET', '/orders/v0/orders/' . rawurlencode( $order_id ), array(), $rdt );
		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		return $this->get_order( $order_id );
	}

	/**
	 * Get order shipping address (RDT), merged into order array shape.
	 *
	 * @param string $order_id Amazon order ID.
	 * @return array|WP_Error Address payload or error.
	 */
	public function get_order_address( string $order_id ) {
		$path = '/orders/v0/orders/' . $order_id . '/address';
		$rdt  = $this->get_restricted_data_token( 'GET', $path, array( 'shippingAddress' ) );
		if ( is_wp_error( $rdt ) ) {
			return $rdt;
		}

		return $this->make_request( 'GET', '/orders/v0/orders/' . rawurlencode( $order_id ) . '/address', array(), $rdt );
	}

	/**
	 * Make API request
	 *
	 * @param string $method HTTP method
	 * @param string $endpoint API endpoint
	 * @param array  $data Request data (optional)
	 * @return array|WP_Error
	 */
	private function make_request( string $method, string $endpoint, array $data = array(), ?string $access_token_override = null ) {
		$api_settings = $this->settings->get_group( 'api' );

		// Check if credentials are set
		if ( empty( $api_settings['client_id'] ) || empty( $api_settings['client_secret'] ) ) {
			$error = __( 'Amazon API credentials are not configured.', 'wc-amazon-sync' );
			$this->logger->error( $error );
			return new \WP_Error( 'missing_credentials', $error );
		}

		// Get access token
		if ( $access_token_override ) {
			$access_token = $access_token_override;
		} else {
			$access_token = $this->get_access_token();
		}
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$url = $this->api_base_url . $endpoint;

		// Amazon SP-API requires specific headers
		$headers = array(
			'x-amz-access-token' => $access_token, // Required for SP-API
			'Content-Type' => 'application/json',
		);

		// Only add Authorization header if not using x-amz-access-token
		// For SP-API, x-amz-access-token is the primary method
		
		// NOTE: marketplaceIds should be in the request body for Reports API, not in URL
		// Only add to URL for GET requests to specific endpoints that require it in query
		$marketplace_id = $api_settings['marketplace_id'] ?? '';
		$is_get_request = 'GET' === $method;
		$is_reports_endpoint = strpos( $endpoint, '/reports/' ) !== false;
		
		// Don't add marketplaceIds to URL for Reports API POST requests (it goes in body)
		// Don't add if already in endpoint or if we have data payload (data should contain it)
		if ( ! empty( $marketplace_id )
			&& stripos( $endpoint, 'marketplaceids' ) === false
			&& ! ( $is_reports_endpoint && ! empty( $data ) )
			&& $is_get_request ) {
			// Only add to URL for GET requests to endpoints that need it
			$url .= ( strpos( $endpoint, '?' ) === false ? '?' : '&' ) . 'marketplaceIds=' . urlencode( $marketplace_id );
		}

		$request_args = array(
			'method' => $method,
			'headers' => $headers,
			'timeout' => 30,
			'sslverify' => true,
			'user-agent' => 'WCAmazonSync/' . WC_AMAZON_SYNC_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
		);

		if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$request_args['body'] = wp_json_encode( $data );
			$this->logger->debug( 'Request body: ' . $request_args['body'] );
		}

		$this->logger->debug( sprintf( 'Amazon API request: %s %s', $method, $url ) );
		$this->logger->debug( 'Request headers: ' . print_r( $headers, true ) );

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Amazon API request failed: ' . $response->get_error_message() );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		$this->logger->debug( sprintf( 'Amazon API response: %d', $status_code ) );
		$this->logger->debug( 'Response body: ' . $body );

		if ( $status_code >= 400 ) {
			// Try to parse error response
			$error_data = json_decode( $body, true );
			$error_message = '';
			
			if ( isset( $error_data['errors'] ) && is_array( $error_data['errors'] ) ) {
				$errors = array();
				foreach ( $error_data['errors'] as $error ) {
					$code = $error['code'] ?? 'Unknown';
					$msg = $error['message'] ?? 'Unknown error';
					$errors[] = sprintf( '%s: %s', $code, $msg );
				}
				$error_message = implode( '; ', $errors );
			} else {
				$error_message = $body;
			}
			
			$full_error = sprintf(
				/* translators: %1$d: HTTP status code, %2$s: Error message */
				__( 'Amazon API error: %1$d - %2$s', 'wc-amazon-sync' ),
				$status_code,
				$error_message
			);
			$this->logger->error( $full_error );
			return new \WP_Error( 'amazon_api_error', $full_error, array( 'status' => $status_code, 'response' => $error_data ) );
		}

		$decoded = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$error = __( 'Failed to parse Amazon API response.', 'wc-amazon-sync' );
			$this->logger->error( $error );
			return new \WP_Error( 'json_decode_error', $error );
		}

		$this->logger->debug( 'Amazon API request successful' );

		return $decoded;
	}

	/**
	 * Get access token using refresh token
	 *
	 * @return string|WP_Error Access token or WP_Error
	 */
	private function get_access_token() {
		$api_settings = $this->settings->get_group( 'api' );

		// Check if we have a cached token
		$cached_token = get_transient( 'wc_amazon_sync_access_token' );
		if ( false !== $cached_token ) {
			return $cached_token;
		}

		$client_id = $api_settings['client_id'] ?? '';
		$client_secret = $api_settings['client_secret'] ?? '';
		$refresh_token = $api_settings['refresh_token'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
			return new \WP_Error( 'missing_credentials', __( 'Amazon API credentials are incomplete.', 'wc-amazon-sync' ) );
		}

		// Get LWA access token
		$token_url = 'https://api.amazon.com/auth/o2/token';

		$token_data = array(
			'grant_type' => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id' => $client_id,
			'client_secret' => $client_secret,
		);

		$response = wp_remote_post(
			$token_url,
			array(
				'body' => $token_data,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Failed to get access token: ' . $response->get_error_message() );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $status_code !== 200 ) {
			// Try to parse error response
			$error_data = json_decode( $body, true );
			$error_message = '';
			
			if ( isset( $error_data['error_description'] ) ) {
				$error_message = $error_data['error_description'];
			} elseif ( isset( $error_data['error'] ) ) {
				$error_message = $error_data['error'];
				if ( isset( $error_data['error_description'] ) ) {
					$error_message .= ': ' . $error_data['error_description'];
				}
			} else {
				$error_message = $body;
			}
			
			$error = sprintf(
				/* translators: %1$d: HTTP status code, %2$s: Error message */
				__( 'Failed to get access token: %1$d - %2$s', 'wc-amazon-sync' ),
				$status_code,
				$error_message
			);
			$this->logger->error( $error );
			$this->logger->error( 'Token request body: ' . print_r( $token_data, true ) );
			$this->logger->error( 'Token response: ' . $body );
			return new \WP_Error( 'token_error', $error, array( 'status' => $status_code, 'response' => $error_data ) );
		}

		$token_response = json_decode( $body, true );

		if ( ! isset( $token_response['access_token'] ) ) {
			$error = __( 'Invalid token response from Amazon.', 'wc-amazon-sync' );
			$this->logger->error( $error );
			return new \WP_Error( 'invalid_token_response', $error );
		}

		$access_token = $token_response['access_token'];
		$expires_in = $token_response['expires_in'] ?? 3600;

		// Cache token (subtract 5 minutes for safety)
		set_transient( 'wc_amazon_sync_access_token', $access_token, $expires_in - 300 );

		return $access_token;
	}
}

