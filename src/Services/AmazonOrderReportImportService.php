<?php
/**
 * Import customer/shipping data from Seller Central order report files.
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Services;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Logger\AmazonLogger;

/**
 * Parses Amazon flat-file order reports (TSV/CSV) and enriches local orders.
 */
class AmazonOrderReportImportService {

	/**
	 * Known header aliases (lowercase) → internal field.
	 *
	 * @var array<string, string>
	 */
	private static $header_map = array(
		'order-id'              => 'order_id',
		'order_id'              => 'order_id',
		'amazon-order-id'       => 'order_id',
		'bestellnummer'         => 'order_id',
		'buyer-email'           => 'buyer_email',
		'buyer_email'           => 'buyer_email',
		'buyer-name'            => 'buyer_name',
		'buyer_name'            => 'buyer_name',
		'buyer-phone-number'    => 'phone',
		'buyer_phone_number'    => 'phone',
		'recipient-name'        => 'recipient_name',
		'recipient_name'        => 'recipient_name',
		'ship-address-1'        => 'line1',
		'ship_address_1'        => 'line1',
		'ship-address-2'        => 'line2',
		'ship_address_2'        => 'line2',
		'ship-address-3'        => 'line3',
		'ship_address_3'        => 'line3',
		'ship-city'             => 'city',
		'ship_city'             => 'city',
		'ship-state'            => 'state',
		'ship_state'            => 'state',
		'ship-postal-code'     => 'postal_code',
		'ship_postal_code'     => 'postal_code',
		'ship-country'          => 'country_code',
		'ship_country'          => 'country_code',
	);

	/**
	 * @var AmazonLogger
	 */
	private $logger;

	/**
	 * @param AmazonLogger|null $logger Logger.
	 */
	public function __construct( ?AmazonLogger $logger = null ) {
		$this->logger = $logger ?? new AmazonLogger();
	}

	/**
	 * Import uploaded report file and merge into amazon_orders by amazon_order_id.
	 *
	 * @param string $tmp_path Absolute path to uploaded temp file.
	 * @param string $original_name Original filename (for logging).
	 * @return array{matched:int,updated:int,skipped:int,unknown:int,message:string}|WP_Error
	 */
	public function import_file( string $tmp_path, string $original_name = '' ) {
		if ( $tmp_path === '' || ! is_readable( $tmp_path ) ) {
			return new \WP_Error( 'missing_file', __( 'Uploaded file is not readable.', 'wc-amazon-sync' ) );
		}

		$raw = file_get_contents( $tmp_path );
		if ( false === $raw || $raw === '' ) {
			return new \WP_Error( 'empty_file', __( 'Uploaded file is empty.', 'wc-amazon-sync' ) );
		}

		// Strip UTF-8 BOM.
		if ( strncmp( $raw, "\xEF\xBB\xBF", 3 ) === 0 ) {
			$raw = substr( $raw, 3 );
		}

		$parsed = $this->parse_report( $raw );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		/** @var array<string, array> $by_order */
		$by_order = $parsed;
		$matched  = 0;
		$updated  = 0;
		$skipped  = 0;
		$unknown  = 0;

		global $wpdb;
		$table = $wpdb->prefix . 'amazon_orders';
		$now   = current_time( 'mysql' );

		foreach ( $by_order as $amazon_order_id => $shipping ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, customer_name, buyer_email, shipping_data FROM {$table} WHERE amazon_order_id = %s LIMIT 1",
					$amazon_order_id
				),
				ARRAY_A
			);

			if ( ! $row ) {
				$unknown++;
				continue;
			}

			$matched++;

			$existing = array();
			if ( ! empty( $row['shipping_data'] ) ) {
				$decoded = json_decode( (string) $row['shipping_data'], true );
				if ( is_array( $decoded ) ) {
					$existing = $decoded;
				}
			}

			$merged = array_merge( $existing, array_filter( $shipping, static function ( $v ) {
				return $v !== null && $v !== '';
			} ) );
			$merged['source']     = 'order_report';
			$merged['imported_at'] = $now;

			$customer_name = ! empty( $merged['recipient_name'] )
				? $merged['recipient_name']
				: ( ! empty( $merged['buyer_name'] ) ? $merged['buyer_name'] : (string) ( $row['customer_name'] ?? '' ) );
			$buyer_email = ! empty( $merged['buyer_email'] ) ? $merged['buyer_email'] : (string) ( $row['buyer_email'] ?? '' );

			$result = $wpdb->update(
				$table,
				array(
					'shipping_data'  => wp_json_encode( $merged ),
					'customer_name'  => $customer_name !== '' ? sanitize_text_field( $customer_name ) : null,
					'buyer_email'    => $buyer_email !== '' ? sanitize_email( $buyer_email ) : null,
					'updated_at'     => $now,
				),
				array( 'id' => (int) $row['id'] )
			);

			if ( false === $result ) {
				$skipped++;
				continue;
			}
			$updated++;
		}

		$message = sprintf(
			/* translators: 1: matched, 2: updated, 3: unknown (not in DB), 4: skipped errors */
			__( 'Report import: %1$d matched, %2$d updated, %3$d not found locally, %4$d skipped.', 'wc-amazon-sync' ),
			$matched,
			$updated,
			$unknown,
			$skipped
		);

		$this->logger->info(
			$message,
			AmazonLogger::SCOPE_ORDERS,
			array(
				'file'    => $original_name,
				'orders'  => count( $by_order ),
				'matched' => $matched,
				'updated' => $updated,
				'unknown' => $unknown,
				'skipped' => $skipped,
			)
		);

		return array(
			'matched' => $matched,
			'updated' => $updated,
			'skipped' => $skipped,
			'unknown' => $unknown,
			'message' => $message,
		);
	}

	/**
	 * Parse report content into order_id => shipping fields.
	 *
	 * @param string $raw File contents.
	 * @return array<string, array>|WP_Error
	 */
	private function parse_report( string $raw ) {
		$raw   = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines = explode( "\n", $raw );
		$lines = array_values( array_filter( $lines, static function ( $line ) {
			return trim( (string) $line ) !== '';
		} ) );

		if ( count( $lines ) < 2 ) {
			return new \WP_Error( 'invalid_report', __( 'Report must contain a header row and at least one data row.', 'wc-amazon-sync' ) );
		}

		$header_line = array_shift( $lines );
		$delimiter   = $this->detect_delimiter( $header_line );
		$headers     = $this->split_line( $header_line, $delimiter );
		$index_map   = $this->map_headers( $headers );

		if ( ! isset( $index_map['order_id'] ) ) {
			return new \WP_Error(
				'missing_order_id_column',
				__( 'Could not find order-id column in the report. Export the Seller Central “Bestellberichte / FBM” flat file.', 'wc-amazon-sync' )
			);
		}

		$by_order = array();
		foreach ( $lines as $line ) {
			$cols = $this->split_line( $line, $delimiter );
			$order_id = isset( $cols[ $index_map['order_id'] ] ) ? trim( (string) $cols[ $index_map['order_id'] ] ) : '';
			if ( $order_id === '' || ! preg_match( '/^\d{3}-\d{7}-\d{7}$/', $order_id ) ) {
				continue;
			}

			$row = array();
			foreach ( $index_map as $field => $idx ) {
				if ( $field === 'order_id' ) {
					continue;
				}
				$value = isset( $cols[ $idx ] ) ? trim( (string) $cols[ $idx ] ) : '';
				if ( $value !== '' ) {
					$row[ $field ] = $value;
				}
			}

			if ( empty( $by_order[ $order_id ] ) ) {
				$by_order[ $order_id ] = $row;
			} else {
				// First non-empty wins; fill gaps from later item rows.
				foreach ( $row as $k => $v ) {
					if ( empty( $by_order[ $order_id ][ $k ] ) && $v !== '' ) {
						$by_order[ $order_id ][ $k ] = $v;
					}
				}
			}
		}

		if ( empty( $by_order ) ) {
			return new \WP_Error( 'no_orders_in_report', __( 'No valid Amazon order IDs found in the report.', 'wc-amazon-sync' ) );
		}

		return $by_order;
	}

	/**
	 * @param string $header_line Header line.
	 * @return string Delimiter character.
	 */
	private function detect_delimiter( string $header_line ): string {
		$tab   = substr_count( $header_line, "\t" );
		$semi  = substr_count( $header_line, ';' );
		$comma = substr_count( $header_line, ',' );
		if ( $tab >= $semi && $tab >= $comma && $tab > 0 ) {
			return "\t";
		}
		if ( $semi >= $comma && $semi > 0 ) {
			return ';';
		}
		return ',';
	}

	/**
	 * @param string $line      Line.
	 * @param string $delimiter Delimiter.
	 * @return array<int, string>
	 */
	private function split_line( string $line, string $delimiter ): array {
		if ( $delimiter === "\t" ) {
			return array_map( 'trim', explode( "\t", $line ) );
		}
		$parsed = str_getcsv( $line, $delimiter );
		return array_map( static function ( $v ) {
			return trim( (string) $v );
		}, is_array( $parsed ) ? $parsed : array() );
	}

	/**
	 * @param array<int, string> $headers Raw headers.
	 * @return array<string, int> field => column index
	 */
	private function map_headers( array $headers ): array {
		$map = array();
		foreach ( $headers as $i => $header ) {
			$key = strtolower( trim( (string) $header ) );
			$key = str_replace( array( ' ', '_' ), '-', $key );
			// Normalize underscore variants already covered; also strip quotes.
			$key = trim( $key, "\"'" );
			$alt = str_replace( '-', '_', $key );

			$field = null;
			if ( isset( self::$header_map[ $key ] ) ) {
				$field = self::$header_map[ $key ];
			} elseif ( isset( self::$header_map[ $alt ] ) ) {
				$field = self::$header_map[ $alt ];
			}

			if ( $field && ! isset( $map[ $field ] ) ) {
				$map[ $field ] = (int) $i;
			}
		}
		return $map;
	}
}
