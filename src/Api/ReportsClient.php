<?php
/**
 * Amazon SP-API Reports client — LWA token only (same as Feeds in working scripts).
 * Fetches product list via GET_MERCHANT_LISTINGS_ALL_DATA report.
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Reports API client using only LWA access token (no AWS SigV4).
 * Uses same auth as Feeds API in amazon_stock_worker-style scripts.
 */
class ReportsClient {

	/** @var string */
	private $base_url;

	/** @var string */
	private $access_token;

	/** @var string */
	private $client_id;

	/** @var string */
	private $client_secret;

	/** @var string */
	private $refresh_token;

	/**
	 * Build base URL from region (eu-west-1 => EU, us-east-1 => NA).
	 *
	 * @param string $region
	 * @return string
	 */
	public static function base_url_for_region( $region ) {
		if ( $region === 'us-east-1' ) {
			return 'https://sellingpartnerapi-na.amazon.com';
		}
		return 'https://sellingpartnerapi-eu.amazon.com';
	}

	/**
	 * @param array $api get_option( 'wc_amazon_sync_api' )
	 */
	public function __construct( array $api ) {
		$region = isset( $api['region'] ) ? $api['region'] : 'eu-west-1';
		$this->base_url = self::base_url_for_region( $region );
		$this->client_id = isset( $api['client_id'] ) ? $api['client_id'] : '';
		$this->client_secret = isset( $api['client_secret'] ) ? $api['client_secret'] : '';
		$this->refresh_token = isset( $api['refresh_token'] ) ? $api['refresh_token'] : '';
		$this->access_token = '';
	}

	/**
	 * Get LWA access token (same as amazon_stock_worker.php).
	 *
	 * @return string|null Access token or null on failure
	 */
	public function get_access_token() {
		if ( $this->access_token ) {
			return $this->access_token;
		}
		if ( empty( $this->client_id ) || empty( $this->client_secret ) || empty( $this->refresh_token ) ) {
			return null;
		}
		$ch = curl_init( 'https://api.amazon.com/auth/o2/token' );
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST          => true,
			CURLOPT_POSTFIELDS     => http_build_query( array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $this->refresh_token,
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
			) ),
			CURLOPT_HTTPHEADER    => array( 'Content-Type: application/x-www-form-urlencoded' ),
		) );
		$response = curl_exec( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		if ( $code !== 200 || ! $response ) {
			return null;
		}
		$data = json_decode( $response, true );
		$this->access_token = isset( $data['access_token'] ) ? $data['access_token'] : null;
		return $this->access_token;
	}

	/**
	 * SP-API request with only x-amz-access-token (no SigV4).
	 *
	 * @param string $method GET, POST, PUT, or PATCH
	 * @param string $path e.g. /reports/2021-06-30/reports
	 * @param array|null $body JSON body for POST/PUT/PATCH
	 * @return array{ code: int, body: string, data: array|null } Response
	 */
	public function request( $method, $path, $body = null ) {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return array( 'code' => 0, 'body' => '', 'data' => null );
		}
		$url = rtrim( $this->base_url, '/' ) . '/' . ltrim( $path, '/' );
		$headers = array(
			'x-amz-access-token: ' . $token,
			'Content-Type: application/json',
			'Accept: application/json',
		);
		$ch = curl_init( $url );
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => $headers,
		);
		$body_json = $body ? wp_json_encode( $body ) : '{}';
		if ( $method === 'POST' ) {
			$opts[ CURLOPT_POST ] = true;
			$opts[ CURLOPT_POSTFIELDS ] = $body_json;
		} elseif ( $method === 'PATCH' ) {
			$opts[ CURLOPT_CUSTOMREQUEST ] = 'PATCH';
			$opts[ CURLOPT_POSTFIELDS ] = $body_json;
		} elseif ( $method === 'PUT' ) {
			$opts[ CURLOPT_CUSTOMREQUEST ] = 'PUT';
			$opts[ CURLOPT_POSTFIELDS ] = $body_json;
		}
		curl_setopt_array( $ch, $opts );
		$response = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		$data = null;
		if ( $response && is_string( $response ) ) {
			$decoded = json_decode( $response, true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}
		return array( 'code' => $code, 'body' => $response, 'data' => $data );
	}

	/**
	 * Create report and wait for DONE, then return report document content (decoded).
	 *
	 * @param string $marketplace_id Single marketplace ID
	 * @param string $report_type e.g. GET_MERCHANT_LISTINGS_ALL_DATA
	 * @param int $max_wait_seconds Max seconds to poll
	 * @return array{ success: bool, error?: string, items?: array, raw?: string }
	 */
	public function fetch_listings_report( $marketplace_id, $report_type = 'GET_MERCHANT_LISTINGS_ALL_DATA', $max_wait_seconds = 120 ) {
		$body = array(
			'reportType'     => $report_type,
			'marketplaceIds' => array( $marketplace_id ),
		);
		// Request English column headers so we reliably detect item-name, asin, ean, etc.
		// See: https://developer-docs.amazon.com/sp-api/docs/report-type-values-inventory
		$body['reportOptions'] = array( 'preferredReportDocumentLocale' => 'en_US' );
		$res = $this->request( 'POST', '/reports/2021-06-30/reports', $body );
		// createReport returns 202 Accepted when report is queued; reportId is in body
		if ( ( $res['code'] !== 200 && $res['code'] !== 202 ) || empty( $res['data']['reportId'] ) ) {
			return array(
				'success' => false,
				'error'   => $res['code'] === 403
					? __( 'Amazon API error: 403 - Unauthorized. Reports API requires app role; try same credentials as for Feeds.', 'wc-amazon-sync' )
					: ( 'HTTP ' . $res['code'] . ( $res['body'] ? ': ' . wp_strip_all_tags( substr( $res['body'], 0, 500 ) ) : '' ) ),
			);
		}
		$report_id = $res['data']['reportId'];
		$status = '';

		$deadline = time() + $max_wait_seconds;
		// Poll getReport until processingStatus is DONE (report may take 30–90 seconds)
		while ( time() < $deadline ) {
			$get = $this->request( 'GET', '/reports/2021-06-30/reports/' . $report_id );
			if ( $get['code'] !== 200 || empty( $get['data'] ) ) {
				return array( 'success' => false, 'error' => 'getReport failed: ' . ( $get['body'] ?: 'HTTP ' . $get['code'] ) );
			}
			$status = isset( $get['data']['processingStatus'] ) ? $get['data']['processingStatus'] : '';
			if ( $status === 'DONE' ) {
				$doc_id = isset( $get['data']['reportDocumentId'] ) ? $get['data']['reportDocumentId'] : '';
				if ( ! $doc_id ) {
					return array( 'success' => false, 'error' => 'Missing reportDocumentId' );
				}
				$doc = $this->request( 'GET', '/reports/2021-06-30/documents/' . $doc_id );
				if ( $doc['code'] !== 200 || empty( $doc['data']['url'] ) ) {
					return array( 'success' => false, 'error' => 'getReportDocument failed' );
				}
				$doc_url = $doc['data']['url'];
				$compression = isset( $doc['data']['compressionAlgorithm'] ) ? $doc['data']['compressionAlgorithm'] : '';

				$ch = curl_init( $doc_url );
				curl_setopt_array( $ch, array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_BINARYTRANSFER => true,
					CURLOPT_HTTPHEADER     => array( 'User-Agent: WCAmazonSync/1.0' ),
				) );
				$raw = curl_exec( $ch );
				curl_close( $ch );
				if ( $raw === false || $raw === '' ) {
					return array( 'success' => false, 'error' => 'Failed to download report document' );
				}
				if ( strtoupper( $compression ) === 'GZIP' ) {
					$raw = @gzdecode( $raw );
					if ( $raw === false ) {
						return array( 'success' => false, 'error' => 'Failed to decompress report (GZIP)' );
					}
				}
				$parsed = $this->parse_merchant_listings_report( $raw );
				$items = $parsed['items'];
				$debug = array(
					'raw_bytes'         => strlen( $raw ),
					'line_count'        => $parsed['line_count'],
					'header'            => $parsed['header'],
					'first_row_preview' => $parsed['first_row_preview'],
					'debug_empty_title' => isset( $parsed['debug_empty_title'] ) ? $parsed['debug_empty_title'] : array(),
					'empty_title_count'  => isset( $parsed['empty_title_count'] ) ? $parsed['empty_title_count'] : 0,
					'empty_gtin_count'   => isset( $parsed['empty_gtin_count'] ) ? $parsed['empty_gtin_count'] : 0,
				);
				return array( 'success' => true, 'items' => $items, 'raw' => $raw, 'debug' => $debug );
			}
			if ( $status === 'CANCELLED' || $status === 'FATAL' ) {
				return array( 'success' => false, 'error' => 'Report processing status: ' . $status );
			}
			sleep( 5 );
		}
		return array( 'success' => false, 'error' => 'Report timed out (status: ' . ( $status ?: 'unknown' ) . ')' );
	}

	/**
	 * Fetch report and return raw report rows (one array per CSV row, keys = normalized column names).
	 * No deduplication, no title fallback — for reset-import that uses only item-name.
	 *
	 * @param string $marketplace_id
	 * @param string $report_type
	 * @param int    $max_wait_seconds
	 * @return array{ success: bool, error?: string, report_rows?: array, debug?: array }
	 */
	public function fetch_listings_report_raw( $marketplace_id, $report_type = 'GET_MERCHANT_LISTINGS_ALL_DATA', $max_wait_seconds = 120 ) {
		$body = array(
			'reportType'     => $report_type,
			'marketplaceIds' => array( $marketplace_id ),
		);
		$body['reportOptions'] = array( 'preferredReportDocumentLocale' => 'en_US' );
		$res = $this->request( 'POST', '/reports/2021-06-30/reports', $body );
		if ( ( $res['code'] !== 200 && $res['code'] !== 202 ) || empty( $res['data']['reportId'] ) ) {
			return array(
				'success' => false,
				'error'   => $res['code'] === 403
					? __( 'Amazon API error: 403 - Unauthorized. Reports API requires app role; try same credentials as for Feeds.', 'wc-amazon-sync' )
					: ( 'HTTP ' . $res['code'] . ( $res['body'] ? ': ' . wp_strip_all_tags( substr( $res['body'], 0, 500 ) ) : '' ) ),
			);
		}
		$report_id = $res['data']['reportId'];
		$status = '';
		$deadline = time() + $max_wait_seconds;
		while ( time() < $deadline ) {
			$get = $this->request( 'GET', '/reports/2021-06-30/reports/' . $report_id );
			if ( $get['code'] !== 200 || empty( $get['data'] ) ) {
				return array( 'success' => false, 'error' => 'getReport failed: ' . ( $get['body'] ?: 'HTTP ' . $get['code'] ) );
			}
			$status = isset( $get['data']['processingStatus'] ) ? $get['data']['processingStatus'] : '';
			if ( $status === 'DONE' ) {
				$doc_id = isset( $get['data']['reportDocumentId'] ) ? $get['data']['reportDocumentId'] : '';
				if ( ! $doc_id ) {
					return array( 'success' => false, 'error' => 'Missing reportDocumentId' );
				}
				$doc = $this->request( 'GET', '/reports/2021-06-30/documents/' . $doc_id );
				if ( $doc['code'] !== 200 || empty( $doc['data']['url'] ) ) {
					return array( 'success' => false, 'error' => 'getReportDocument failed' );
				}
				$doc_url = $doc['data']['url'];
				$compression = isset( $doc['data']['compressionAlgorithm'] ) ? $doc['data']['compressionAlgorithm'] : '';
				$ch = curl_init( $doc_url );
				curl_setopt_array( $ch, array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_BINARYTRANSFER => true,
					CURLOPT_HTTPHEADER     => array( 'User-Agent: WCAmazonSync/1.0' ),
					CURLOPT_TIMEOUT        => 300,
					CURLOPT_CONNECTTIMEOUT => 30,
				) );
				$raw = curl_exec( $ch );
				$curl_errno = curl_errno( $ch );
				$curl_error = curl_error( $ch );
				curl_close( $ch );
				if ( $raw === false || $raw === '' ) {
					return array( 'success' => false, 'error' => 'Failed to download report document' . ( $curl_errno ? ': ' . $curl_error : '' ) );
				}
				if ( strtoupper( $compression ) === 'GZIP' ) {
					$raw = @gzdecode( $raw );
					if ( $raw === false ) {
						return array( 'success' => false, 'error' => 'Failed to decompress report (GZIP)' );
					}
				}
				$parsed = $this->parse_report_to_raw_rows( $raw );
				return array(
					'success'     => true,
					'report_rows' => $parsed['report_rows'],
					'debug'       => array(
						'header'       => $parsed['header'],
						'line_count'   => $parsed['line_count'],
						'raw_bytes'    => strlen( $raw ),
					),
				);
			}
			if ( $status === 'CANCELLED' || $status === 'FATAL' ) {
				return array( 'success' => false, 'error' => 'Report processing status: ' . $status );
			}
			sleep( 5 );
		}
		return array( 'success' => false, 'error' => 'Report timed out (status: ' . ( $status ?: 'unknown' ) . ')' );
	}

	/**
	 * Fetch product types list (Product Type Definitions API) for categories dropdown.
	 * GET definitions/2020-09-01/productTypes?marketplaceIds=...&locale=...
	 * locale: optional; language for display names (e.g. de_DE, en_US). If not set, API may use marketplace default.
	 *
	 * @param string   $marketplace_id Marketplace ID (e.g. A1PA6795UKMFR9).
	 * @param array|null &$debug        Optional. Filled with endpoint_path, query_params, http_status, response_body_preview, response_top_level_keys, count.
	 * @param string|null $locale       Optional. Locale for category display names (e.g. de_DE, en_US). From API settings.
	 * @return array{ success: bool, categories?: array<string, string>, error?: string, debug?: array }
	 */
	public function search_product_types_list( $marketplace_id, &$debug = null, $locale = null ) {
		$marketplace_id = trim( (string) $marketplace_id );
		if ( $marketplace_id === '' ) {
			if ( is_array( $debug ) ) {
				$debug['error'] = 'marketplaceIds empty';
			}
			return array( 'success' => false, 'error' => __( 'Marketplace ID is required.', 'wc-amazon-sync' ) );
		}
		$path = 'definitions/2020-09-01/productTypes';
		$query = array( 'marketplaceIds' => $marketplace_id );
		if ( $locale !== null && $locale !== '' ) {
			$query['locale'] = trim( (string) $locale );
		}
		$path_with_query = $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$res = $this->request( 'GET', $path_with_query );
		$body_len = is_string( $res['body'] ) ? strlen( $res['body'] ) : 0;
		if ( is_array( $debug ) ) {
			$debug['endpoint_path'] = '/' . $path;
			$debug['query_params']  = $query;
			$debug['http_status']   = $res['code'];
			$debug['response_size'] = $body_len;
			$debug['response_content_type'] = 'application/json';
			$preview = is_string( $res['body'] ) ? substr( $res['body'], 0, 1000 ) : '';
			$debug['response_body_preview'] = $preview;
			if ( is_array( $res['data'] ) ) {
				$debug['response_top_level_keys'] = array_keys( $res['data'] );
			} else {
				$debug['response_top_level_keys'] = array();
			}
		}
		if ( $res['code'] !== 200 ) {
			$err = ( $res['body'] && is_string( $res['body'] ) ) ? wp_strip_all_tags( substr( $res['body'], 0, 500 ) ) : '';
			return array(
				'success' => false,
				'error'   => sprintf( __( 'Product Type Definitions API HTTP %d', 'wc-amazon-sync' ), $res['code'] ) . ( $err ? ': ' . $err : '' ),
				'debug'   => $debug,
			);
		}
		$data = $res['data'];
		if ( ! is_array( $data ) ) {
			if ( is_array( $debug ) ) {
				$debug['parse_error'] = 'response not array';
			}
			return array( 'success' => false, 'error' => __( 'Invalid API response.', 'wc-amazon-sync' ), 'debug' => $debug );
		}
		$product_types = isset( $data['productTypes'] ) && is_array( $data['productTypes'] ) ? $data['productTypes'] : array();
		$categories = array();
		foreach ( $product_types as $pt ) {
			if ( is_array( $pt ) && isset( $pt['name'] ) ) {
				$name = trim( (string) $pt['name'] );
				if ( $name !== '' ) {
					// displayName is localized per locale param; name is the product type id (e.g. LUGGAGE)
					$display = isset( $pt['displayName'] ) && trim( (string) $pt['displayName'] ) !== ''
						? trim( (string) $pt['displayName'] )
						: $name;
					$categories[ $name ] = $display;
				}
			} elseif ( is_string( $pt ) ) {
				$categories[ $pt ] = $pt;
			}
		}
		if ( is_array( $debug ) ) {
			$debug['count_parsed'] = count( $categories );
		}
		return array( 'success' => true, 'categories' => $categories, 'debug' => $debug );
	}

	/**
	 * Fetch browse tree report (GET_XML_BROWSE_TREE_DATA) and return categories as id => label for dropdown.
	 * Uses reportOptions.MarketplaceId; full tree is returned (can be large).
	 *
	 * @param string   $marketplace_id     Marketplace ID (e.g. A1PA6795UKMFR9).
	 * @param int      $max_wait_seconds   Max seconds to wait for report.
	 * @param array|null &$debug           Optional. Filled with request/response steps for observability.
	 * @return array{ success: bool, categories?: array<string, string>, error?: string }
	 */
	public function fetch_browse_tree_categories( $marketplace_id, $max_wait_seconds = 180, &$debug = null ) {
		$body = array(
			'reportType'     => 'GET_XML_BROWSE_TREE_DATA',
			'marketplaceIds' => array( $marketplace_id ),
			'reportOptions'  => array( 'MarketplaceId' => $marketplace_id ),
		);
		$res = $this->request( 'POST', '/reports/2021-06-30/reports', $body );
		if ( is_array( $debug ) ) {
			$debug['step1_create_report'] = array(
				'endpoint_path' => '/reports/2021-06-30/reports',
				'query_params'  => array( 'marketplaceIds' => $marketplace_id ),
				'http_status'   => $res['code'],
				'response_size' => is_string( $res['body'] ) ? strlen( $res['body'] ) : 0,
				'response_preview' => is_string( $res['body'] ) ? substr( $res['body'], 0, 500 ) : '',
			);
		}
		if ( ( $res['code'] !== 200 && $res['code'] !== 202 ) || empty( $res['data']['reportId'] ) ) {
			return array(
				'success' => false,
				'error'   => $res['code'] === 403
					? __( 'Reports API: 403. Browse Tree Report requires Product Listing role.', 'wc-amazon-sync' )
					: ( 'HTTP ' . $res['code'] . ( $res['body'] ? ': ' . wp_strip_all_tags( substr( $res['body'], 0, 400 ) ) : '' ) ),
			);
		}
		$report_id = $res['data']['reportId'];
		$status = '';
		$deadline = time() + $max_wait_seconds;
		while ( time() < $deadline ) {
			$get = $this->request( 'GET', '/reports/2021-06-30/reports/' . $report_id );
			if ( is_array( $debug ) && empty( $debug['step2_get_report'] ) ) {
				$debug['step2_get_report'] = array(
					'endpoint_path' => '/reports/2021-06-30/reports/' . $report_id,
					'http_status'   => $get['code'],
					'response_size' => is_string( $get['body'] ) ? strlen( $get['body'] ) : 0,
				);
			}
			if ( $get['code'] !== 200 || empty( $get['data'] ) ) {
				return array( 'success' => false, 'error' => 'getReport failed: ' . ( $get['body'] ?: 'HTTP ' . $get['code'] ) );
			}
			$status = isset( $get['data']['processingStatus'] ) ? $get['data']['processingStatus'] : '';
			if ( $status === 'DONE' ) {
				$doc_id = isset( $get['data']['reportDocumentId'] ) ? $get['data']['reportDocumentId'] : '';
				if ( ! $doc_id ) {
					return array( 'success' => false, 'error' => 'Missing reportDocumentId' );
				}
				$doc = $this->request( 'GET', '/reports/2021-06-30/documents/' . $doc_id );
				if ( $doc['code'] !== 200 || empty( $doc['data']['url'] ) ) {
					return array( 'success' => false, 'error' => 'getReportDocument failed' );
				}
				$doc_url = $doc['data']['url'];
				$compression = isset( $doc['data']['compressionAlgorithm'] ) ? $doc['data']['compressionAlgorithm'] : '';
				$ch = curl_init( $doc_url );
				curl_setopt_array( $ch, array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_BINARYTRANSFER => true,
					CURLOPT_HTTPHEADER     => array( 'User-Agent: WCAmazonSync/1.0' ),
					CURLOPT_TIMEOUT        => 300,
					CURLOPT_CONNECTTIMEOUT => 30,
				) );
				$raw = curl_exec( $ch );
				curl_close( $ch );
				if ( $raw === false || $raw === '' ) {
					return array( 'success' => false, 'error' => 'Failed to download report document' );
				}
				if ( strtoupper( $compression ) === 'GZIP' ) {
					$raw = @gzdecode( $raw );
					if ( $raw === false ) {
						return array( 'success' => false, 'error' => 'Failed to decompress report (GZIP)' );
					}
				}
				$categories = $this->parse_browse_tree_xml( $raw );
				if ( is_array( $debug ) ) {
					$debug['step3_document'] = array( 'raw_body_length' => strlen( $raw ), 'count_parsed' => count( $categories ) );
				}
				return array( 'success' => true, 'categories' => $categories );
			}
			if ( $status === 'CANCELLED' || $status === 'FATAL' ) {
				return array( 'success' => false, 'error' => 'Report status: ' . $status );
			}
			sleep( 5 );
		}
		return array( 'success' => false, 'error' => 'Report timed out (status: ' . ( $status ?: 'unknown' ) . ')' );
	}

	/**
	 * Parse Browse Tree XML: collect all Node elements (browseNodeId => label). Label = browsePathByName or browseNodeName.
	 *
	 * @param string $xml Raw XML string.
	 * @return array<string, string> id => label
	 */
	private function parse_browse_tree_xml( $xml ) {
		$out = array();
		libxml_use_internal_errors( true );
		$sx = @simplexml_load_string( $xml );
		if ( $sx === false ) {
			return $out;
		}
		$this->collect_browse_nodes( $sx, $out );
		return $out;
	}

	/**
	 * Recursively collect browse node id and label from Node elements (and childNodes).
	 *
	 * @param \SimpleXMLElement $el Parent element (Result or Node or childNodes).
	 * @param array<string, string> &$out Accumulator id => label.
	 * @param string $path_prefix Parent path for label (e.g. "Parent > ").
	 */
	private function collect_browse_nodes( $el, &$out, $path_prefix = '' ) {
		if ( $el->getName() === 'Node' ) {
			$id = isset( $el->browseNodeId ) ? trim( (string) $el->browseNodeId ) : '';
			if ( $id !== '' ) {
				$name = isset( $el->browseNodeName ) ? trim( (string) $el->browseNodeName ) : '';
				$path = isset( $el->browsePathByName ) ? trim( (string) $el->browsePathByName ) : '';
				$label = $path !== '' ? $path : ( $name !== '' ? $name : $id );
				if ( $path_prefix !== '' && $path === '' ) {
					$label = $path_prefix . $name;
				}
				$out[ $id ] = $label !== '' ? $label : $id;
			}
			$next_prefix = ( $path_prefix !== '' ? $path_prefix : '' ) . ( isset( $el->browseNodeName ) ? trim( (string) $el->browseNodeName ) . ' > ' : '' );
			if ( isset( $el->childNodes ) ) {
				foreach ( $el->childNodes->children() as $child ) {
					$this->collect_browse_nodes( $child, $out, $next_prefix );
				}
			}
			return;
		}
		foreach ( $el->children() as $child ) {
			$this->collect_browse_nodes( $child, $out, $path_prefix );
		}
	}

	/**
	 * Normalize report header key: lowercase, trim, keep dashes (do not replace with underscore).
	 *
	 * @param string $h
	 * @return string
	 */
	private function normalize_report_key( $h ) {
		return strtolower( trim( (string) $h ) );
	}

	/**
	 * Parse report CSV into raw rows: each row is an associative array with keys = normalized column names.
	 * Uses stream + fgetcsv() so quoted fields containing newlines (e.g. in item name/description) do not
	 * break rows — splitting by \n first would turn one logical row into multiple lines and drop/list wrong data.
	 *
	 * @param string $tsv Raw report content
	 * @return array{ report_rows: array, header: array, line_count: int }
	 */
	private function parse_report_to_raw_rows( $tsv ) {
		$empty_result = array( 'report_rows' => array(), 'header' => array(), 'line_count' => 0 );
		$tsv = preg_replace( '/^\xEF\xBB\xBF/', '', $tsv );
		$tsv = trim( $tsv );
		if ( $tsv === '' ) {
			return $empty_result;
		}
		$stream = fopen( 'php://memory', 'r+' );
		if ( $stream === false ) {
			return $empty_result;
		}
		fwrite( $stream, $tsv );
		rewind( $stream );

		// Detect delimiter from first record (fgetcsv respects quoted newlines)
		$header_tab = fgetcsv( $stream, 0, "\t" );
		rewind( $stream );
		$header_semi = fgetcsv( $stream, 0, ";" );
		rewind( $stream );
		$delim = "\t";
		$header_raw = is_array( $header_tab ) ? $header_tab : array();
		if ( is_array( $header_semi ) && count( $header_semi ) > count( $header_raw ) && count( $header_semi ) >= 2 ) {
			$delim = ";";
			$header_raw = $header_semi;
		} elseif ( is_array( $header_tab ) && count( $header_tab ) >= 2 ) {
			$header_raw = $header_tab;
		}
		if ( count( $header_raw ) < 2 ) {
			fclose( $stream );
			return $empty_result;
		}
		$header = array_map( array( $this, 'normalize_report_key' ), $header_raw );
		rewind( $stream );
		fgetcsv( $stream, 0, $delim ); // consume header
		$report_rows = array();
		while ( true ) {
			$row = fgetcsv( $stream, 0, $delim );
			if ( $row === false ) {
				break;
			}
			if ( count( $row ) === 1 && trim( (string) ( isset( $row[0] ) ? $row[0] : '' ) ) === '' ) {
				continue;
			}
			$assoc = array();
			foreach ( $header as $idx => $key ) {
				$assoc[ $key ] = isset( $row[ $idx ] ) ? trim( (string) $row[ $idx ] ) : '';
			}
			$report_rows[] = $assoc;
		}
		fclose( $stream );
		return array(
			'report_rows' => $report_rows,
			'header'      => $header_raw,
			'line_count'  => count( $report_rows ) + 1,
		);
	}

	/**
	 * Parse tab- or semicolon-delimited GET_MERCHANT_LISTINGS_ALL_DATA (or similar) into items.
	 * Handles BOM, different delimiters (EU often uses ;), and finds ASIN by header or by value pattern.
	 *
	 * @param string $tsv
	 * @return array{ items: array, line_count: int, header: array, first_row_preview: string }
	 */
	private function parse_merchant_listings_report( $tsv ) {
		$empty_result = array( 'items' => array(), 'line_count' => 0, 'header' => array(), 'first_row_preview' => '' );
		$tsv = preg_replace( '/^\xEF\xBB\xBF/', '', $tsv );
		$lines = preg_split( '/\r\n|\r|\n/', trim( $tsv ) );
		if ( count( $lines ) < 2 ) {
			return $empty_result;
		}
		$delim = "\t";
		$header_tab = str_getcsv( $lines[0], "\t" );
		$header_semi = str_getcsv( $lines[0], ";" );
		if ( count( $header_semi ) > count( $header_tab ) && count( $header_semi ) >= 2 ) {
			$delim = ";";
		}
		$header = str_getcsv( $lines[0], $delim );
		$header_lower = array_map( function ( $h ) {
			return strtolower( trim( (string) $h ) );
		}, $header );
		$find_col = function ( $names ) use ( $header_lower ) {
			foreach ( (array) $names as $n ) {
				$idx = array_search( $n, $header_lower, true );
				if ( $idx !== false ) {
					return $idx;
				}
				$idx = array_search( str_replace( '-', '_', $n ), $header_lower, true );
				if ( $idx !== false ) {
					return $idx;
				}
			}
			return false;
		};
		// Also match header that contains keyword (e.g. "item name", "Artikelname")
		$find_col_contains = function ( $keywords ) use ( $header_lower ) {
			foreach ( $header_lower as $idx => $h ) {
				foreach ( (array) $keywords as $kw ) {
					if ( strpos( $h, $kw ) !== false ) {
						return $idx;
					}
				}
			}
			return false;
		};
		// ASIN: use asin1 as primary; asin2/asin3 for lookup (report columns: asin1, asin2, asin3)
		$asin_idx  = $find_col( array( 'asin1', 'asin2', 'asin', 'product_id' ) );
		if ( $asin_idx === false ) {
			$asin_idx = $find_col_contains( array( 'asin' ) );
		}
		$asin2_idx = $find_col( array( 'asin2' ) );
		$asin3_idx = $find_col( array( 'asin3' ) );
		$description_idx = $find_col( array( 'item-description', 'item_description', 'product-description', 'description' ) );
		$sku_idx   = $find_col( array( 'seller-sku', 'sku', 'seller_sku', 'merchant-sku' ) );
		$listing_id_idx = $find_col( array( 'listing-id', 'listing_id', 'listingid' ) );
		$title_idx = $find_col( array( 'item-name', 'item_name', 'title', 'product-name', 'product_name' ) );
		if ( $title_idx === false ) {
			$title_idx = $find_col_contains( array( 'item name', 'product name', 'titel', 'artikelname', 'name', 'designation', 'bezeichnung', 'headline', 'item', 'product' ) );
		}
		$brand_idx = $find_col( array( 'brand', 'manufacturer' ) );
		if ( $brand_idx === false ) {
			$brand_idx = $find_col_contains( array( 'marke', 'marca', 'brand' ) );
		}
		// EAN/GTIN: product-id column in GET_MERCHANT_LISTINGS_ALL_DATA holds EAN when product-id-type=1
		$gtin_idx  = $find_col( array( 'product-id', 'ean', 'upc', 'gtin', 'external-product-id', 'barcode' ) );
		if ( $gtin_idx === false ) {
			$gtin_idx = $find_col_contains( array( 'product-id', 'ean', 'upc', 'gtin', 'identifier', 'barcode' ) );
		}
		if ( $sku_idx === false ) {
			$sku_idx = $find_col_contains( array( 'seller sku', 'merchant sku', 'artikelnummer', 'sku' ) );
		}
		$price_idx    = $find_col( array( 'price', 'listing-price', 'item_price' ) );
		if ( $price_idx === false ) {
			$price_idx = $find_col_contains( array( 'price', 'preis', 'listing price' ) );
		}
		$quantity_idx = $find_col( array( 'quantity', 'fulfillable-quantity', 'available', 'inventory', 'stock', 'amount' ) );
		if ( $quantity_idx === false ) {
			$quantity_idx = $find_col_contains( array( 'quantity', 'qty', 'bestand', 'fulfillable', 'available', 'inventory', 'stock', 'anzahl', 'menge', 'amount' ) );
		}
		$status_idx = $find_col( array( 'status', 'listing-status', 'item-status', 'condition' ) );
		if ( $status_idx === false ) {
			$status_idx = $find_col_contains( array( 'status', 'listing', 'condition', 'state' ) );
		}
		// Fallback: column that has integer values in first data rows (and is not ASIN column)
		if ( $quantity_idx === false ) {
			$col_counts = array();
			for ( $r = 1; $r < min( 21, count( $lines ) ); $r++ ) {
				$row = str_getcsv( $lines[ $r ], $delim );
				foreach ( $row as $c => $cell ) {
					$v = trim( (string) $cell );
					if ( $v !== '' && $c !== $asin_idx && preg_match( '/^\d{1,6}$/', $v ) ) {
						$col_counts[ $c ] = ( isset( $col_counts[ $c ] ) ? $col_counts[ $c ] : 0 ) + 1;
					}
				}
			}
			foreach ( $col_counts as $c => $cnt ) {
				if ( $cnt >= 10 ) {
					$quantity_idx = $c;
					break;
				}
			}
		}

		// Fallback: detect title by content = longest text in first data rows (skip numeric-only and ASIN-like)
		if ( $title_idx === false ) {
			$max_len = 0;
			$title_cand = false;
			for ( $r = 1; $r < min( 21, count( $lines ) ); $r++ ) {
				$row = str_getcsv( $lines[ $r ], $delim );
				foreach ( $row as $c => $cell ) {
					$v = trim( (string) $cell );
					if ( $v === '' || preg_match( '/^\d+$/', $v ) || preg_match( '/^[A-Z0-9]{10}$/i', $v ) ) {
						continue;
					}
					if ( preg_match( '/^\d{8,14}$/', preg_replace( '/[\s\-]/', '', $v ) ) ) {
						continue;
					}
					$len = strlen( $v );
					if ( $len > $max_len && $len >= 10 && $len < 600 ) {
						$max_len = $len;
						$title_cand = $c;
					}
				}
			}
			if ( $title_cand !== false ) {
				$title_idx = $title_cand;
			}
		}
		// Fallback 2: column that most often has "title-like" content in first rows (by position)
		if ( $title_idx === false && count( $header ) > 0 ) {
			$col_scores = array();
			$max_col = min( 15, count( $header ) - 1 );
			for ( $c = 0; $c <= $max_col; $c++ ) {
				$col_scores[ $c ] = 0;
			}
			for ( $r = 1; $r < min( 31, count( $lines ) ); $r++ ) {
				$row = str_getcsv( $lines[ $r ], $delim );
				foreach ( $col_scores as $c => $score ) {
					if ( ! isset( $row[ $c ] ) ) {
						continue;
					}
					$v = trim( (string) $row[ $c ] );
					if ( $v === '' || preg_match( '/^[A-Z0-9]{10}$/i', $v ) || preg_match( '/^\d{8,14}$/', preg_replace( '/[\s\-]/', '', $v ) ) || preg_match( '/^\d+$/', $v ) ) {
						continue;
					}
					if ( strlen( $v ) >= 5 && strlen( $v ) < 600 && preg_match( '/[A-Za-z]/', $v ) ) {
						$col_scores[ $c ]++;
					}
				}
			}
			$best_score = 0;
			foreach ( $col_scores as $c => $score ) {
				if ( $score > $best_score ) {
					$best_score = $score;
					$title_idx = $c;
				}
			}
			if ( $best_score < 1 ) {
				$title_idx = false;
			}
		}
		if ( $sku_idx === false ) {
			for ( $r = 1; $r < min( 6, count( $lines ) ); $r++ ) {
				$row = str_getcsv( $lines[ $r ], $delim );
				foreach ( $row as $c => $cell ) {
					$v = trim( (string) $cell );
					if ( strlen( $v ) >= 3 && strlen( $v ) <= 40 && preg_match( '/^[A-Za-z0-9\-_]+$/', $v ) && ! preg_match( '/^[A-Z0-9]{10}$/i', $v ) ) {
						$sku_idx = $c;
						break 2;
					}
				}
			}
		}

		// If no ASIN column by name: find column that has a 10-char alphanumeric value in first data rows
		if ( $asin_idx === false ) {
			for ( $r = 1; $r < min( 6, count( $lines ) ); $r++ ) {
				$row = str_getcsv( $lines[ $r ], $delim );
				foreach ( $row as $c => $cell ) {
					$v = trim( (string) $cell );
					if ( strlen( $v ) === 10 && preg_match( '/^[A-Z0-9]{10}$/i', $v ) ) {
						$asin_idx = $c;
						break 2;
					}
				}
			}
		}

		$items = array();
		$first_row_preview = '';
		$debug_empty_title = array();
		$empty_title_count = 0;
		$empty_gtin_count = 0;
		for ( $i = 1; $i < count( $lines ); $i++ ) {
			$row = str_getcsv( $lines[ $i ], $delim );
			if ( $first_row_preview === '' ) {
				$first_row_preview = implode( ' | ', array_slice( $row, 0, 8 ) );
			}
			$asin = '';
			if ( $asin_idx !== false && isset( $row[ $asin_idx ] ) ) {
				$asin = trim( (string) $row[ $asin_idx ] );
			}
			if ( $asin === '' || ! ( strlen( $asin ) === 10 && preg_match( '/^[A-Z0-9]{10}$/i', $asin ) ) ) {
				// Fallback: find any cell in this row that looks like ASIN
				foreach ( $row as $cell ) {
					$v = trim( (string) $cell );
					if ( strlen( $v ) === 10 && preg_match( '/^[A-Z0-9]{10}$/i', $v ) ) {
						$asin = $v;
						break;
					}
				}
			}
			if ( $asin === '' ) {
				continue;
			}
			$seller_sku_row = ( $sku_idx !== false && isset( $row[ $sku_idx ] ) ) ? trim( (string) $row[ $sku_idx ] ) : '';
			$listing_id_row = ( $listing_id_idx !== false && isset( $row[ $listing_id_idx ] ) ) ? trim( (string) $row[ $listing_id_idx ] ) : '';
			$price_val = null;
			if ( $price_idx !== false && isset( $row[ $price_idx ] ) && $row[ $price_idx ] !== '' ) {
				$p = trim( (string) $row[ $price_idx ] );
				if ( is_numeric( str_replace( array( ',', ' ' ), array( '.', '' ), $p ) ) ) {
					$price_val = (float) str_replace( ',', '.', $p );
				}
			}
			$qty_val = null;
			if ( $quantity_idx !== false && isset( $row[ $quantity_idx ] ) && $row[ $quantity_idx ] !== '' ) {
				$q = trim( (string) $row[ $quantity_idx ] );
				if ( is_numeric( $q ) ) {
					$qty_val = (int) $q;
				}
			}
			if ( $qty_val === null ) {
				foreach ( $row as $c => $cell ) {
					if ( $c === $asin_idx ) {
						continue;
					}
					$v = trim( (string) $cell );
					if ( $v !== '' && preg_match( '/^\d{1,8}$/', $v ) ) {
						$qty_val = (int) $v;
						break;
					}
				}
			}
			$gtin_val = ( $gtin_idx !== false && isset( $row[ $gtin_idx ] ) ) ? trim( (string) $row[ $gtin_idx ] ) : null;
			if ( $gtin_val === '' ) {
				$gtin_val = null;
			}
			if ( $gtin_val !== null ) {
				$gtin_normalized = preg_replace( '/[\s\-]/', '', $gtin_val );
				if ( preg_match( '/^\d{8,14}$/', $gtin_normalized ) ) {
					$gtin_val = $gtin_normalized;
				} elseif ( preg_match( '/^[A-Z0-9]{10}$/i', $gtin_val ) ) {
					$gtin_val = null;
				} else {
					$gtin_val = null;
				}
			}
			if ( $gtin_val === null ) {
				foreach ( $row as $c => $cell ) {
					$v = trim( (string) $cell );
					if ( $v === $asin || $v === $seller_sku_row ) {
						continue;
					}
					if ( $c === $sku_idx ) {
						continue;
					}
					$v_norm = preg_replace( '/[\s\-]/', '', $v );
					if ( preg_match( '/^\d{8,14}$/', $v_norm ) ) {
						$gtin_val = $v_norm;
						break;
					}
				}
			}
			$title_val = ( $title_idx !== false && isset( $row[ $title_idx ] ) ) ? trim( (string) $row[ $title_idx ] ) : null;
			if ( $title_val === '' ) {
				$title_val = null;
			}
			if ( $title_val === null && $description_idx !== false && isset( $row[ $description_idx ] ) ) {
				$d = trim( (string) $row[ $description_idx ] );
				if ( strlen( $d ) >= 3 && strlen( $d ) < 600 && preg_match( '/[A-Za-z]/', $d ) ) {
					$title_val = $d;
				}
			}
			if ( $title_val === null ) {
				$best_len = 0;
				foreach ( $row as $cell ) {
					$v = trim( (string) $cell );
					if ( $v === '' || $v === $asin || $v === $seller_sku_row ) {
						continue;
					}
					if ( preg_match( '/^\d+$/', $v ) || preg_match( '/^[A-Z0-9]{10}$/i', $v ) ) {
						continue;
					}
					if ( preg_match( '/^\d{8,14}$/', preg_replace( '/[\s\-]/', '', $v ) ) ) {
						continue;
					}
					if ( strlen( $v ) >= 2 && strlen( $v ) < 600 && preg_match( '/[A-Za-z]/', $v ) && strlen( $v ) > $best_len ) {
						$best_len = strlen( $v );
						$title_val = $v;
					}
				}
			}
			if ( $title_val === null && count( $row ) > 0 ) {
				$title_like = function( $v ) use ( $asin, $seller_sku_row ) {
					$v = trim( (string) $v );
					if ( $v === '' || $v === $asin || $v === $seller_sku_row ) {
						return false;
					}
					if ( preg_match( '/^\d+$/', $v ) || preg_match( '/^[A-Z0-9]{10}$/i', $v ) || preg_match( '/^\d{8,14}$/', preg_replace( '/[\s\-]/', '', $v ) ) ) {
						return false;
					}
					return strlen( $v ) >= 3 && strlen( $v ) < 600 && preg_match( '/[A-Za-z]/', $v );
				};
				// Common GET_MERCHANT_LISTINGS_ALL_DATA column order: seller-sku(0), asin(1), item-name(2), ...
				$try_indices = array( 2, 3, 1, 4, 5, 0, 6, 7, 8, 9, 10 );
				foreach ( $try_indices as $c ) {
					if ( isset( $row[ $c ] ) && $title_like( $row[ $c ] ) ) {
						$title_val = trim( (string) $row[ $c ] );
						break;
					}
				}
				if ( $title_val === null ) {
					$skip_idx = array_filter( array( $asin_idx, $sku_idx, $price_idx, $quantity_idx, $gtin_idx ) );
					for ( $c = 0; $c < min( 20, count( $row ) ); $c++ ) {
						if ( in_array( $c, $skip_idx, true ) ) {
							continue;
						}
						$v = trim( (string) $row[ $c ] );
						if ( $title_like( $v ) ) {
							$title_val = $v;
							break;
						}
					}
				}
			}
			$status_val = ( $status_idx !== false && isset( $row[ $status_idx ] ) ) ? trim( (string) $row[ $status_idx ] ) : null;
			if ( $status_val === '' ) {
				$status_val = null;
			}
			$debug_asins = array( 'B08MZVT9HH', 'B0FS7YDJ5T', 'B0B9HPC5W8', 'B00BP5PMQ6', 'B0FY6JCR4H' );
			if ( $title_val === null ) {
				$empty_title_count++;
			}
			if ( $gtin_val === null ) {
				$empty_gtin_count++;
			}
			$need_debug = ( $title_val === null || $gtin_val === null ) && ( in_array( $asin, $debug_asins, true ) || count( $debug_empty_title ) < 25 );
			if ( $need_debug ) {
				$debug_empty_title[ $asin ] = array( 'header' => $header, 'row' => $row, 'missing_title' => $title_val === null, 'missing_gtin' => $gtin_val === null );
			}
			$asin2 = ( $asin2_idx !== false && isset( $row[ $asin2_idx ] ) ) ? trim( (string) $row[ $asin2_idx ] ) : '';
			$asin3 = ( $asin3_idx !== false && isset( $row[ $asin3_idx ] ) ) ? trim( (string) $row[ $asin3_idx ] ) : '';
			if ( $asin2 !== '' && ! ( strlen( $asin2 ) === 10 && preg_match( '/^[A-Z0-9]{10}$/i', $asin2 ) ) ) {
				$asin2 = '';
			}
			if ( $asin3 !== '' && ! ( strlen( $asin3 ) === 10 && preg_match( '/^[A-Z0-9]{10}$/i', $asin3 ) ) ) {
				$asin3 = '';
			}
			$items[] = array(
				'asin'            => $asin,
				'asin2'           => $asin2 !== '' ? $asin2 : null,
				'asin3'           => $asin3 !== '' ? $asin3 : null,
				'seller_sku'      => $seller_sku_row !== '' ? $seller_sku_row : null,
				'listing_id'      => $listing_id_row !== '' ? $listing_id_row : null,
				'title'           => $title_val,
				'brand'           => ( $brand_idx !== false && isset( $row[ $brand_idx ] ) ) ? trim( (string) $row[ $brand_idx ] ) : null,
				'gtin'            => $gtin_val,
				'price'           => $price_val,
				'quantity'        => $qty_val,
				'listing_status'  => $status_val,
			);
		}
		// Deduplicate by primary ASIN: keep one row per ASIN, prefer non-empty title (fixes duplicate ASINs with empty title overwriting good title)
		$by_primary = array();
		foreach ( $items as $it ) {
			$a = isset( $it['asin'] ) ? trim( (string) $it['asin'] ) : '';
			if ( $a === '' ) {
				continue;
			}
			$has_title = isset( $it['title'] ) && trim( (string) $it['title'] ) !== '';
			if ( ! isset( $by_primary[ $a ] ) ) {
				$by_primary[ $a ] = $it;
				continue;
			}
			$cur_has_title = isset( $by_primary[ $a ]['title'] ) && trim( (string) $by_primary[ $a ]['title'] ) !== '';
			if ( $has_title && ! $cur_has_title ) {
				$by_primary[ $a ] = $it;
			}
		}
		$items = array_values( $by_primary );
		// Fill empty titles from sibling rows: same ASIN may appear as asin2/asin3 in another row that has title
		foreach ( $items as &$it ) {
			$t = isset( $it['title'] ) ? trim( (string) $it['title'] ) : '';
			if ( $t !== '' ) {
				continue;
			}
			$a = isset( $it['asin'] ) ? trim( (string) $it['asin'] ) : '';
			if ( $a === '' ) {
				continue;
			}
			foreach ( $items as $other ) {
				if ( $other === $it ) {
					continue;
				}
				$ot = isset( $other['title'] ) ? trim( (string) $other['title'] ) : '';
				if ( $ot === '' ) {
					continue;
				}
				$o2 = isset( $other['asin2'] ) ? trim( (string) $other['asin2'] ) : '';
				$o3 = isset( $other['asin3'] ) ? trim( (string) $other['asin3'] ) : '';
				if ( $o2 === $a || $o3 === $a ) {
					$it['title'] = $other['title'];
					break;
				}
			}
		}
		unset( $it );
		return array(
			'items'               => $items,
			'line_count'          => count( $lines ),
			'header'              => $header,
			'first_row_preview'   => $first_row_preview,
			'debug_empty_title'   => $debug_empty_title,
			'empty_title_count'   => $empty_title_count,
			'empty_gtin_count'    => $empty_gtin_count,
		);
	}

	/**
	 * Update listing quantity in Amazon via Listings Items API (PATCH replace fulfillment_availability).
	 * Requires Product Listing role. Uses same LWA token as Reports.
	 *
	 * @param string $seller_id       Seller ID (from API settings)
	 * @param string $sku             Seller SKU (listing identifier) — will be rawurlencode'd in path
	 * @param string $marketplace_id  Marketplace ID (required query param)
	 * @param int    $quantity        Quantity to set (fulfillable)
	 * @param string $issue_locale    Optional. e.g. de_DE, en_US. Default de_DE for EU.
	 * @param string $merchant_shipping_group Optional. Enum key from Product Type Definitions (standard Versandvorlage for articles).
	 * @return array{ success: bool, error?: string, debug?: array }
	 */
	public function patch_listings_item_quantity( $seller_id, $sku, $marketplace_id, $quantity, $issue_locale = 'de_DE', $merchant_shipping_group = '' ) {
		$seller_id = preg_replace( '/[^A-Z0-9]/i', '', (string) $seller_id );
		$sku = trim( (string) $sku );
		if ( $seller_id === '' || $sku === '' ) {
			return array( 'success' => false, 'error' => __( 'Missing seller ID or SKU.', 'wc-amazon-sync' ) );
		}
		$quantity = max( 0, (int) $quantity );
		$marketplace_id = trim( (string) $marketplace_id );
		if ( $marketplace_id === '' ) {
			return array( 'success' => false, 'error' => __( 'Missing marketplace ID.', 'wc-amazon-sync' ) );
		}
		if ( $issue_locale === '' ) {
			$issue_locale = 'de_DE';
		}
		// Path: sellerId and SKU must be URL-encoded (SKU can contain special chars)
		$path = sprintf(
			'listings/2021-08-01/items/%s/%s',
			rawurlencode( $seller_id ),
			rawurlencode( $sku )
		);
		$query = array(
			'marketplaceIds' => $marketplace_id,
			'issueLocale'    => $issue_locale,
		);
		$path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$patches = array(
			array(
				'op'    => 'replace',
				'path'  => '/attributes/fulfillment_availability',
				'value' => array(
					array(
						'fulfillment_channel_code' => 'DEFAULT',
						'quantity'                => $quantity,
					),
				),
			),
		);
		$merchant_shipping_group = trim( (string) $merchant_shipping_group );
		if ( $merchant_shipping_group !== '' ) {
			$patches[] = array(
				'op'    => 'replace',
				'path'  => '/attributes/merchant_shipping_group',
				'value' => array( array( 'value' => $merchant_shipping_group ) ),
			);
		}
		$body = array(
			'productType' => 'PRODUCT',
			'patches'     => $patches,
		);
		$res = $this->request( 'PATCH', $path, $body );
		if ( $res['code'] >= 200 && $res['code'] < 300 ) {
			return array( 'success' => true );
		}
		$response_body = isset( $res['body'] ) && is_string( $res['body'] ) ? $res['body'] : '';
		$err = wp_strip_all_tags( substr( $response_body, 0, 500 ) );
		if ( $res['code'] === 0 ) {
			$err = __( 'No response from Amazon (check credentials and network).', 'wc-amazon-sync' );
		} elseif ( $res['code'] === 403 ) {
			$err = __( 'Amazon 403: Listings Items API requires Product Listing role and authorization.', 'wc-amazon-sync' ) . ( $err ? ' ' . $err : '' );
		} else {
			$err = sprintf( __( 'Amazon HTTP %d', 'wc-amazon-sync' ), $res['code'] ) . ( $err ? ': ' . $err : '' );
		}
		$debug = array(
			'request_url_path_and_query' => $path,
			'request_body'               => $body,
			'response_code'              => $res['code'],
			'response_body'              => substr( $response_body, 0, 2000 ),
		);
		return array( 'success' => false, 'error' => $err, 'debug' => $debug );
	}

	/**
	 * Create or fully update a listing via putListingsItem (PUT).
	 *
	 * @param string $seller_id       Seller ID (from API settings)
	 * @param string $sku             Seller SKU (listing identifier)
	 * @param string $marketplace_id  Marketplace ID
	 * @param array  $body            Full putListingsItem body: productType, requirements, attributes
	 * @param string $issue_locale    Optional. e.g. de_DE. Default de_DE.
	 * @param string $requirements   Optional. LISTING or LISTING_OFFER_ONLY. Default LISTING_OFFER_ONLY.
	 * @return array{ success: bool, error?: string, data?: array, debug?: array }
	 */
	public function put_listings_item( $seller_id, $sku, $marketplace_id, $body, $issue_locale = 'de_DE', $requirements = 'LISTING_OFFER_ONLY' ) {
		$seller_id = preg_replace( '/[^A-Z0-9]/i', '', (string) $seller_id );
		$sku = trim( (string) $sku );
		if ( $seller_id === '' || $sku === '' ) {
			return array( 'success' => false, 'error' => __( 'Missing seller ID or SKU.', 'wc-amazon-sync' ) );
		}
		$marketplace_id = trim( (string) $marketplace_id );
		if ( $marketplace_id === '' ) {
			return array( 'success' => false, 'error' => __( 'Missing marketplace ID.', 'wc-amazon-sync' ) );
		}
		if ( $issue_locale === '' ) {
			$issue_locale = 'de_DE';
		}
		$path = sprintf(
			'listings/2021-08-01/items/%s/%s',
			rawurlencode( $seller_id ),
			rawurlencode( $sku )
		);
		$query = array(
			'marketplaceIds' => $marketplace_id,
			'issueLocale'    => $issue_locale,
		);
		if ( $requirements !== '' ) {
			$query['requirements'] = $requirements;
		}
		$path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$res = $this->request( 'PUT', $path, $body );
		if ( $res['code'] >= 200 && $res['code'] < 300 ) {
			return array( 'success' => true, 'data' => $res['data'] );
		}
		$response_body = isset( $res['body'] ) && is_string( $res['body'] ) ? $res['body'] : '';
		$err = wp_strip_all_tags( substr( $response_body, 0, 800 ) );
		if ( $res['code'] === 0 ) {
			$err = __( 'No response from Amazon (check credentials and network).', 'wc-amazon-sync' );
		} elseif ( $res['code'] === 403 ) {
			$err = __( 'Amazon 403: Listings Items API requires Product Listing role and authorization.', 'wc-amazon-sync' ) . ( $err ? ' ' . $err : '' );
		} else {
			$err = sprintf( __( 'Amazon HTTP %d', 'wc-amazon-sync' ), $res['code'] ) . ( $err ? ': ' . $err : '' );
		}
		$debug = array(
			'request_path_and_query' => $path,
			'response_code'          => $res['code'],
			'response_body'          => substr( $response_body, 0, 2000 ),
		);
		return array( 'success' => false, 'error' => $err, 'debug' => $debug );
	}

	/**
	 * Get merchant shipping templates (Versandvorlagen) from Product Type Definitions API.
	 * Official path: GET definitions/2020-09-01/productTypes/PRODUCT with sellerId, marketplaceIds,
	 * requirements=LISTING, productTypeVersion=LATEST, locale. Then load schema from schema.link.resource
	 * and parse merchant_shipping_group enum + enumNames.
	 *
	 * @param string $seller_id      Selling Partner ID (merchantId), NOT LWA client id / refresh token / marketplace id
	 * @param string $marketplace_id Marketplace ID (e.g. A1PA6795UKMFR)
	 * @return array{ success: bool, templates?: array<array{ key: string, name: string }>, error?: string, debug?: array }
	 */
	public function get_merchant_shipping_templates( $seller_id, $marketplace_id ) {
		$seller_id = preg_replace( '/[^A-Z0-9]/i', '', (string) $seller_id );
		$marketplace_id = trim( (string) $marketplace_id );
		$debug = array( 'sellerId_sent' => $seller_id, 'marketplaceIds_sent' => $marketplace_id );
		if ( $seller_id === '' || $marketplace_id === '' ) {
			return array( 'success' => false, 'error' => __( 'Missing seller ID or marketplace ID.', 'wc-amazon-sync' ), 'debug' => $debug );
		}
		$path = 'definitions/2020-09-01/productTypes/PRODUCT';
		$query = array(
			'sellerId'           => $seller_id,
			'marketplaceIds'     => $marketplace_id,
			'requirements'       => 'LISTING',
			'productTypeVersion' => 'LATEST',
			'locale'             => 'de_DE',
		);
		$path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$res = $this->request( 'GET', $path );
		if ( $res['code'] !== 200 || empty( $res['data'] ) ) {
			$body = isset( $res['body'] ) ? $res['body'] : '';
			return array(
				'success' => false,
				'error'   => sprintf( __( 'Product Type Definitions API HTTP %d', 'wc-amazon-sync' ), $res['code'] ) . ( $body ? ': ' . wp_strip_all_tags( substr( $body, 0, 300 ) ) : '' ),
				'debug'   => $debug,
			);
		}
		$schema = $this->resolve_product_type_schema( $res['data'], $debug );
		if ( $schema === null ) {
			return array( 'success' => false, 'error' => __( 'Product type schema missing or could not be loaded.', 'wc-amazon-sync' ), 'debug' => $debug );
		}
		$templates = $this->parse_merchant_shipping_group_enum_enum_names( $schema );
		$debug['merchant_shipping_group_found']    = ! empty( $templates ) ? 'yes' : 'no';
		$debug['merchant_shipping_group_enum_count'] = count( $templates );
		$debug['enumNames_count']                 = count( $templates );
		if ( ! empty( $templates ) ) {
			$debug['first_3'] = array_slice( array_map( function ( $t ) {
				return array( 'value' => $t['key'], 'label' => $t['name'] );
			}, $templates ), 0, 3 );
		}
		$out = array( 'success' => true, 'templates' => $templates, 'debug' => $debug );
		if ( empty( $templates ) ) {
			$out['schema_keys'] = array_keys( $schema );
		}
		return $out;
	}

	/**
	 * Get product type definition schema for a given product type and requirements.
	 * Use this to determine required/optional attributes for listing creation.
	 *
	 * @param string $seller_id       Selling Partner ID
	 * @param string $marketplace_id  Marketplace ID (e.g. A1PA6795UKMFR)
	 * @param string $product_type    Product type (e.g. PRODUCT, LUGGAGE, FOOD). Default PRODUCT.
	 * @param string $requirements    LISTING or LISTING_OFFER_ONLY. Default LISTING.
	 * @param string $locale          e.g. de_DE. Default de_DE.
	 * @return array{ success: bool, schema?: array, error?: string, debug?: array }
	 */
	public function get_product_type_schema( $seller_id, $marketplace_id, $product_type = 'PRODUCT', $requirements = 'LISTING', $locale = 'de_DE' ) {
		$seller_id = preg_replace( '/[^A-Z0-9]/i', '', (string) $seller_id );
		$marketplace_id = trim( (string) $marketplace_id );
		$product_type = trim( (string) $product_type ) ?: 'PRODUCT';
		$requirements = trim( (string) $requirements ) ?: 'LISTING';
		$locale = trim( (string) $locale ) ?: 'de_DE';
		$debug = array( 'sellerId' => $seller_id, 'marketplaceIds' => $marketplace_id, 'productType' => $product_type, 'requirements' => $requirements );
		if ( $seller_id === '' || $marketplace_id === '' ) {
			return array( 'success' => false, 'error' => __( 'Missing seller ID or marketplace ID.', 'wc-amazon-sync' ), 'debug' => $debug );
		}
		$path = 'definitions/2020-09-01/productTypes/' . rawurlencode( $product_type );
		$query = array(
			'sellerId'           => $seller_id,
			'marketplaceIds'     => $marketplace_id,
			'requirements'       => $requirements,
			'productTypeVersion' => 'LATEST',
			'locale'             => $locale,
		);
		$path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$res = $this->request( 'GET', $path );
		if ( $res['code'] !== 200 || empty( $res['data'] ) ) {
			$body = isset( $res['body'] ) && is_string( $res['body'] ) ? wp_strip_all_tags( substr( $res['body'], 0, 300 ) ) : '';
			return array(
				'success' => false,
				'error'   => sprintf( __( 'Product Type Definitions API HTTP %d', 'wc-amazon-sync' ), $res['code'] ) . ( $body ? ': ' . $body : '' ),
				'debug'   => $debug,
			);
		}
		$schema = $this->resolve_product_type_schema( $res['data'], $debug );
		if ( $schema === null ) {
			return array( 'success' => false, 'error' => __( 'Product type schema missing or could not be loaded.', 'wc-amazon-sync' ), 'debug' => $debug );
		}
		return array( 'success' => true, 'schema' => $schema, 'debug' => $debug );
	}

	/**
	 * Parse required and optional attribute names (and labels) from a Product Type Definition schema.
	 * Schema structure: properties.attributes.required = [ 'item_name', 'brand', ... ],
	 *                  properties.attributes.properties = { item_name: { title: '...' }, ... }.
	 *
	 * @param array $schema Product type schema (from get_product_type_schema).
	 * @return array{ required: array<array{ name: string, label: string }>, optional: array<array{ name: string, label: string }>, all_names: string[] }
	 */
	public function parse_listing_attributes_from_schema( array $schema ) {
		$required = array();
		$optional = array();
		$all_names = array();
		$attrs_node = null;
		if ( isset( $schema['properties']['attributes']['properties'] ) && is_array( $schema['properties']['attributes']['properties'] ) ) {
			$attrs_node = $schema['properties']['attributes'];
		} else {
			// Recursive search for attributes.properties
			$attrs_node = $this->find_attributes_node( $schema );
		}
		if ( $attrs_node === null || empty( $attrs_node['properties'] ) ) {
			return array( 'required' => $required, 'optional' => $optional, 'all_names' => array() );
		}
		$required_names = array();
		if ( isset( $attrs_node['required'] ) && is_array( $attrs_node['required'] ) ) {
			$required_names = array_values( array_filter( array_map( 'strval', $attrs_node['required'] ) ) );
		}
		$properties = $attrs_node['properties'];
		foreach ( $properties as $name => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$name = (string) $name;
			$all_names[] = $name;
			$label = isset( $def['title'] ) && trim( (string) $def['title'] ) !== '' ? trim( (string) $def['title'] ) : $name;
			if ( isset( $def['enumNames'] ) && is_array( $def['enumNames'] ) && ! empty( $def['enumNames'] ) ) {
				// Use first enumName as hint if no title
				if ( $label === $name ) {
					$label = (string) reset( $def['enumNames'] );
				}
			}
			$entry = array( 'name' => $name, 'label' => $label );
			if ( in_array( $name, $required_names, true ) ) {
				$required[] = $entry;
			} else {
				$optional[] = $entry;
			}
		}
		return array( 'required' => $required, 'optional' => $optional, 'all_names' => $all_names );
	}

	/**
	 * Find the node that has 'properties' with attribute definitions (attributes container).
	 *
	 * @param array $node Schema node
	 * @return array|null attributes node or null
	 */
	private function find_attributes_node( array $node ) {
		if ( isset( $node['properties']['attributes'] ) && is_array( $node['properties']['attributes'] ) ) {
			return $node['properties']['attributes'];
		}
		foreach ( array( '$defs', 'definitions', 'properties' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_array( $node[ $key ] ) ) {
				$found = $this->find_attributes_node( $node[ $key ] );
				if ( $found !== null ) {
					return $found;
				}
			}
		}
		if ( isset( $node['properties'] ) && is_array( $node['properties'] ) ) {
			foreach ( $node['properties'] as $child ) {
				if ( is_array( $child ) ) {
					$found = $this->find_attributes_node( $child );
					if ( $found !== null ) {
						return $found;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Resolve schema from getDefinitionsProductType response: fetch from schema.link.resource (official path),
	 * or use inline schema only if it looks like a full schema (properties or $defs). Never return the wrapper { link, checksum }.
	 *
	 * @param array $data  Response data from getDefinitionsProductType
	 * @param array &$debug Debug array to add resolution info
	 * @return array|null Decoded schema or null
	 */
	private function resolve_product_type_schema( array $data, array &$debug ) {
		$schema_node = isset( $data['schema'] ) ? $data['schema'] : null;
		if ( $schema_node === null ) {
			$debug['schema_link_present'] = 'no';
			return null;
		}
		// Inline: schema is a string (JSON)
		if ( is_string( $schema_node ) ) {
			$decoded = json_decode( $schema_node, true );
			$debug['schema_link_present'] = 'no';
			return is_array( $decoded ) ? $decoded : null;
		}
		if ( is_array( $schema_node ) ) {
			$link = isset( $schema_node['link'] ) && is_array( $schema_node['link'] ) ? $schema_node['link'] : null;
			$resource = $link && isset( $link['resource'] ) ? trim( (string) $link['resource'] ) : '';
			$debug['schema_link_present'] = ( $resource !== '' ) ? 'yes' : 'no';

			if ( $resource !== '' ) {
				$debug['schema_resource_url_short'] = substr( preg_replace( '/\?.*/', '', $resource ), 0, 100 );
				$fetch_result = $this->fetch_schema_resource( $resource );
				$debug['schema_fetch_http_code'] = isset( $fetch_result['code'] ) ? $fetch_result['code'] : 0;
				$debug['schema_fetch_bytes']   = isset( $fetch_result['bytes'] ) ? $fetch_result['bytes'] : 0;
				if ( isset( $fetch_result['schema'] ) && is_array( $fetch_result['schema'] ) ) {
					$debug['schema_loaded_from'] = 'link.resource';
					return $fetch_result['schema'];
				}
				$debug['schema_fetch_failed'] = true;
				return null;
			}

			// No link: only treat as schema if it has real content (not just wrapper)
			$has_content = isset( $schema_node['properties'] ) || isset( $schema_node['$defs'] );
			if ( $has_content ) {
				return $schema_node;
			}
			return null;
		}
		return null;
	}

	/**
	 * Fetch schema JSON from schema.link.resource (pre-signed URL). Tries with LWA token first; on 403 retries without token.
	 *
	 * @param string $resource Full URL (e.g. https://sellingpartner-definitions-prod-...s3.amazonaws.com/...)
	 * @return array{ schema?: array, code: int, bytes: int }
	 */
	private function fetch_schema_resource( $resource ) {
		$url = ( strpos( $resource, 'http' ) === 0 ) ? $resource : rtrim( $this->base_url, '/' ) . '/' . ltrim( $resource, '/' );
		$token = $this->get_access_token();
		$headers = array( 'Accept: application/json' );
		if ( $token ) {
			$headers[] = 'x-amz-access-token: ' . $token;
		}
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => $headers,
		) );
		$response = curl_exec( $ch );
		$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$bytes    = is_string( $response ) ? strlen( $response ) : 0;
		curl_close( $ch );

		if ( $code === 200 && $response ) {
			$decoded = json_decode( $response, true );
			if ( is_array( $decoded ) ) {
				return array( 'schema' => $decoded, 'code' => $code, 'bytes' => $bytes );
			}
		}
		// 403: pre-signed URL may not need token — retry without
		if ( $code === 403 && $token ) {
			$ch2 = curl_init( $url );
			curl_setopt_array( $ch2, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
			) );
			$response2 = curl_exec( $ch2 );
			$code2     = (int) curl_getinfo( $ch2, CURLINFO_HTTP_CODE );
			$bytes2    = is_string( $response2 ) ? strlen( $response2 ) : 0;
			curl_close( $ch2 );
			if ( $code2 === 200 && $response2 ) {
				$decoded = json_decode( $response2, true );
				if ( is_array( $decoded ) ) {
					return array( 'schema' => $decoded, 'code' => $code2, 'bytes' => $bytes2 );
				}
			}
			return array( 'schema' => null, 'code' => $code2, 'bytes' => $bytes2 );
		}
		return array( 'schema' => null, 'code' => $code, 'bytes' => $bytes );
	}

	/**
	 * Find merchant_shipping_group in schema and extract enum + enumNames (key = enum[i], name = enumNames[i]).
	 *
	 * @param array $schema Full product type schema
	 * @return array<array{ key: string, name: string }>
	 */
	private function parse_merchant_shipping_group_enum_enum_names( array $schema ) {
		$attr = $this->find_merchant_shipping_group_node( $schema );
		if ( $attr === null ) {
			return array();
		}
		// items.properties.value: enum + enumNames (official format)
		$val = null;
		if ( isset( $attr['items']['properties']['value'] ) && is_array( $attr['items']['properties']['value'] ) ) {
			$val = $attr['items']['properties']['value'];
		} elseif ( isset( $attr['enum'] ) ) {
			$val = $attr;
		}
		if ( $val === null ) {
			return array();
		}
		$enum = isset( $val['enum'] ) && is_array( $val['enum'] ) ? $val['enum'] : array();
		$enum_names = isset( $val['enumNames'] ) && is_array( $val['enumNames'] ) ? $val['enumNames'] : array();
		if ( empty( $enum ) ) {
			return array();
		}
		$out = array();
		foreach ( $enum as $i => $key ) {
			$label = isset( $enum_names[ $i ] ) ? (string) $enum_names[ $i ] : (string) $key;
			$out[] = array( 'key' => (string) $key, 'name' => $label );
		}
		return $out;
	}

	/**
	 * Find merchant_shipping_group in schema (official path: properties.attributes.properties.merchant_shipping_group,
	 * or recursive search). Node has items.properties.value with enum + enumNames.
	 *
	 * @param array $node Schema node (full downloaded schema)
	 * @return array|null Attribute node or null
	 */
	private function find_merchant_shipping_group_node( array $node ) {
		$names = array( 'merchant_shipping_group', 'merchantShippingGroup' );
		foreach ( $names as $attr_name ) {
			if ( isset( $node[ $attr_name ] ) && is_array( $node[ $attr_name ] ) ) {
				return $node[ $attr_name ];
			}
		}
		// Official tutorial path: schema.properties.attributes.properties.merchant_shipping_group
		if ( isset( $node['properties']['attributes']['properties'] ) && is_array( $node['properties']['attributes']['properties'] ) ) {
			$attrs = $node['properties']['attributes']['properties'];
			foreach ( $names as $attr_name ) {
				if ( isset( $attrs[ $attr_name ] ) && is_array( $attrs[ $attr_name ] ) ) {
					return $attrs[ $attr_name ];
				}
			}
		}
		// Recurse into $defs and other containers
		foreach ( array( '$defs', 'definitions', 'properties', 'items' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_array( $node[ $key ] ) ) {
				$found = $this->find_merchant_shipping_group_node( $node[ $key ] );
				if ( $found !== null ) {
					return $found;
				}
			}
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				$found = $this->find_merchant_shipping_group_node( $child );
				if ( $found !== null ) {
					return $found;
				}
			}
		}
		return null;
	}
}
