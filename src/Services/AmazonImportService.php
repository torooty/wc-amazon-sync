<?php
/**
 * Amazon Import Service - fetch from API, clear storage, insert all rows from report
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Services;

use WCAmazonSync\Logger\AmazonLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Amazon Import Service
 */
class AmazonImportService {

	/**
	 * @var AmazonLogger
	 */
	private $logger;

	/** @var int Max debug candidates to collect when insert title != DB title */
	const DEBUG_CANDIDATES_MAX = 30;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->logger = new AmazonLogger();
	}

	/**
	 * Whether import debug diagnostics are enabled (schema log, post-insert check, raw row log).
	 * Enable via constant WC_AMAZON_SYNC_IMPORT_DEBUG or option wc_amazon_sync_import_debug.
	 *
	 * @return bool
	 */
	private function is_import_debug() {
		if ( defined( 'WC_AMAZON_SYNC_IMPORT_DEBUG' ) && WC_AMAZON_SYNC_IMPORT_DEBUG ) {
			return true;
		}
		return (bool) get_option( 'wc_amazon_sync_import_debug', false );
	}

	/**
	 * Run import: clear storage for marketplace, fetch report, insert each row (no skip/second pass/update).
	 *
	 * UI vs Import (single source of truth):
	 * - UI: ImportTabContent::render() reads via SELECT * FROM {$table} WHERE ... ; displays $row['title'].
	 * - Import: writes to same $table, column 'title'. Table = wp_*_amazon_imported_products. No other title column (no item_name).
	 *
	 * @return array{ new_count: int, updated_count: int, skipped_count: int, error?: string }
	 */
	public function run_import() {
		global $wpdb;
		$now   = current_time( 'mysql' );

		$this->logger->info( 'Import started', AmazonLogger::SCOPE_IMPORT );

		$marketplace_id = $this->get_marketplace_id();
		if ( ! $marketplace_id ) {
			$this->logger->error( 'Marketplace ID not configured', AmazonLogger::SCOPE_IMPORT );
			return array( 'new_count' => 0, 'updated_count' => 0, 'skipped_count' => 0, 'error' => __( 'Marketplace ID not configured. Save API settings first.', 'wc-amazon-sync' ) );
		}

		$table = $wpdb->prefix . 'amazon_imported_products';

		// Step 1 — Schema diagnostic (when DEBUG): ensure title column exists and type is correct.
		if ( $this->is_import_debug() ) {
			$create = $wpdb->get_var( "SHOW CREATE TABLE `{$table}`" );
			$this->logger->info( '[DEBUG] SHOW CREATE TABLE: ' . ( $create ? substr( $create, 0, 1500 ) : '(null)' ), AmazonLogger::SCOPE_IMPORT );
			$describe = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
			$this->logger->info( '[DEBUG] DESCRIBE: ' . wp_json_encode( $describe ), AmazonLogger::SCOPE_IMPORT );
			$title_col = $wpdb->get_results( $wpdb->prepare( "SHOW FULL COLUMNS FROM `{$table}` LIKE %s", 'title' ), ARRAY_A );
			$this->logger->info( '[DEBUG] SHOW FULL COLUMNS LIKE title: ' . wp_json_encode( $title_col ), AmazonLogger::SCOPE_IMPORT );
			$this->logger->info( '[DEBUG] wpdb->prefix=' . $wpdb->prefix . ' table=' . $table, AmazonLogger::SCOPE_IMPORT );
		}

		// Source: raw report rows only (no items list, no map by ASIN). Keys = normalized column names (item-name, seller-sku, asin1, asin2, asin3, product-id, product-id-type, price, quantity).
		$report_result = $this->fetch_report_raw_rows( $marketplace_id );
		if ( isset( $report_result['error'] ) ) {
			$this->logger->error( 'Reports API: ' . $report_result['error'], AmazonLogger::SCOPE_IMPORT );
			return array( 'new_count' => 0, 'updated_count' => 0, 'skipped_count' => 0, 'error' => $report_result['error'] );
		}
		$report_rows = isset( $report_result['report_rows'] ) && is_array( $report_result['report_rows'] ) ? $report_result['report_rows'] : array();
		$this->logger->info( 'Reports API returned ' . count( $report_rows ) . ' report rows (raw, no deduplication)', AmazonLogger::SCOPE_IMPORT );
		if ( ! empty( $report_result['debug']['header'] ) ) {
			$this->logger->info( 'Report header (normalized keys): ' . implode( ' | ', array_slice( $report_result['debug']['header'], 0, 20 ) ), AmazonLogger::SCOPE_IMPORT );
		}

		// Index report by ASIN for final debug (log one raw row for a problematic asin)
		$report_by_asin = array();
		foreach ( $report_rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$a = '';
			foreach ( array( 'asin1', 'asin2', 'asin3' ) as $k ) {
				$v = isset( $r[ $k ] ) ? trim( (string) $r[ $k ] ) : '';
				if ( $v !== '' && strlen( $v ) === 10 && preg_match( '/^[A-Z0-9]{10}$/i', $v ) ) {
					$a = $v;
					break;
				}
			}
			if ( $a !== '' ) {
				$report_by_asin[ $a ] = $r;
			}
		}

		// Count rows for this marketplace before clear (diagnostic: if 0 deleted but count > 0, marketplace_id may differ)
		$count_before = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE marketplace_id = %s",
			$marketplace_id
		) );
		// Clear storage for this marketplace
		$deleted = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE marketplace_id = %s",
			$marketplace_id
		) );
		$this->logger->info(
			"Storage cleared: type=table, table={$table}, marketplace_id=" . $marketplace_id . ", count_before={$count_before}, deleted_rows={$deleted}",
			AmazonLogger::SCOPE_IMPORT
		);

		$imported_count   = 0;
		$updated_count    = 0;
		$insert_failed    = 0;
		$skipped_empty   = 0;
		$empty_warn_logged = 0;
		$max_empty_warnings = 10;
		$inserted_asins = array(); // Table has UNIQUE(marketplace_id, asin): only first occurrence per ASIN
		$debug_candidates = array(); // When DEBUG: rows where item-name was non-empty but DB title is empty after insert
		$type_stats = array(); // product-id-type distribution (for diagnostics)
		$ean_saved_count = 0;

		foreach ( $report_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// asin = first non-empty of asin, asin1, asin2, asin3 (report column names may vary)
			$asin = '';
			foreach ( array( 'asin', 'asin1', 'asin2', 'asin3' ) as $k ) {
				$v = isset( $row[ $k ] ) ? trim( (string) $row[ $k ] ) : '';
				if ( $v !== '' && strlen( $v ) === 10 && preg_match( '/^[A-Z0-9]{10}$/i', $v ) ) {
					$asin = $v;
					break;
				}
			}
			if ( $asin === '' ) {
				continue;
			}
			if ( isset( $inserted_asins[ $asin ] ) ) {
				continue; // One row per ASIN (table UNIQUE)
			}
			$asin = sanitize_text_field( $asin );

			// title = from report; try common column names (en_US report: item-name; others: item_name, title, product-name, etc.)
			$title_raw = '';
			foreach ( array( 'item-name', 'item_name', 'title', 'product-name', 'product_name', 'item-description', 'product-description' ) as $title_key ) {
				if ( isset( $row[ $title_key ] ) && trim( (string) $row[ $title_key ] ) !== '' ) {
					$title_raw = trim( (string) $row[ $title_key ] );
					break;
				}
			}
			if ( strlen( $title_raw ) === 0 ) {
				// Fallback: first column that looks like a title (longest text that is not ASIN/SKU/numeric)
				foreach ( $row as $val ) {
					$v = trim( (string) $val );
					if ( strlen( $v ) >= 3 && strlen( $v ) < 600 && preg_match( '/[A-Za-z]/', $v ) && ! preg_match( '/^[A-Z0-9]{10}$/i', $v ) && ! preg_match( '/^\d{8,14}$/', preg_replace( '/[\s\-]/', '', $v ) ) ) {
						if ( strlen( $v ) > strlen( $title_raw ) ) {
							$title_raw = $v;
						}
					}
				}
			}
			if ( strlen( $title_raw ) === 0 ) {
				$skipped_empty++;
				if ( $empty_warn_logged < $max_empty_warnings ) {
					$this->logger->warn(
						'Empty title in report row (skipped). Keys present: ' . implode( ', ', array_keys( $row ) ) . ' | raw row (first 300 chars): ' . substr( wp_json_encode( $row ), 0, 300 ),
						AmazonLogger::SCOPE_IMPORT
					);
					$empty_warn_logged++;
				}
				continue;
			}
			$title = wp_strip_all_tags( $title_raw );
			$title = trim( $title );
			// If strip_all_tags removed everything, keep original trimmed so we don't lose content
			if ( strlen( $title ) === 0 && strlen( $title_raw ) > 0 ) {
				$title = $title_raw;
			}
			// Sanitize: remove null bytes and invalid UTF-8 so DB accepts the value (avoids "title too long or invalid data")
			$title = str_replace( "\0", '', $title );
			if ( function_exists( 'iconv' ) ) {
				$cleaned = @iconv( 'UTF-8', 'UTF-8//IGNORE', $title );
				if ( $cleaned !== false ) {
					$title = $cleaned;
				}
			}
			// Truncate to safe length (TEXT = 65535 bytes; some installs may have VARCHAR or strict limits)
			$max_title_len = 8000;
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $title, 'UTF-8' ) > $max_title_len ) {
				$title = mb_substr( $title, 0, $max_title_len, 'UTF-8' );
			} elseif ( strlen( $title ) > $max_title_len ) {
				$title = substr( $title, 0, $max_title_len );
			}

			$seller_sku = isset( $row['seller-sku'] ) ? sanitize_text_field( substr( trim( (string) $row['seller-sku'] ), 0, 64 ) ) : null;
			if ( $seller_sku === '' ) {
				$seller_sku = null;
			}

			// EAN/GTIN (stored in gtin): product-id when product-id-type = 1 (EAN) or 4. Try product-id-type and product_id_type.
			$product_id_type = isset( $row['product-id-type'] ) ? trim( (string) $row['product-id-type'] ) : ( isset( $row['product_id_type'] ) ? trim( (string) $row['product_id_type'] ) : '' );
			$product_id      = isset( $row['product-id'] ) ? trim( (string) $row['product-id'] ) : ( isset( $row['product_id'] ) ? trim( (string) $row['product_id'] ) : '' );
			$gtin = null;
			if ( ( $product_id_type === '1' || $product_id_type === '4' ) && $product_id !== '' ) {
				$cleaned = preg_replace( '/[\s\-]/', '', $product_id );
				if ( preg_match( '/^\d{8,14}$/', $cleaned ) ) {
					$gtin = sanitize_text_field( substr( $cleaned, 0, 64 ) );
					if ( $gtin === '' ) {
						$gtin = null;
					}
				}
			}

			$price = null;
			if ( isset( $row['price'] ) && $row['price'] !== '' ) {
				$p = trim( (string) $row['price'] );
				$p = str_replace( array( ',', ' ' ), array( '.', '' ), $p );
				if ( is_numeric( $p ) ) {
					$price = (float) $p;
				}
			}
			$quantity = null;
			if ( isset( $row['quantity'] ) && $row['quantity'] !== '' && is_numeric( trim( (string) $row['quantity'] ) ) ) {
				$quantity = (int) $row['quantity'];
			}
			// Status: try listing-status, status, item-condition, condition (report column names may vary)
			$listing_status = null;
			foreach ( array( 'listing-status', 'status', 'item-condition', 'condition', 'listing_status', 'item_status' ) as $status_key ) {
				if ( isset( $row[ $status_key ] ) ) {
					$v = sanitize_text_field( substr( trim( (string) $row[ $status_key ] ), 0, 64 ) );
					if ( $v !== '' ) {
						$listing_status = $v;
						break;
					}
				}
			}
			if ( $listing_status === '' ) {
				$listing_status = null;
			}

			// Diagnostics: product-id-type distribution and EAN counts (for inserted rows only)
			$type_key = $product_id_type !== '' ? $product_id_type : '(empty)';
			if ( ! isset( $type_stats[ $type_key ] ) ) {
				$type_stats[ $type_key ] = 0;
			}
			$type_stats[ $type_key ]++;
			if ( $gtin !== null ) {
				$ean_saved_count++;
			}

			$insert_data = array(
				'marketplace_id'  => $marketplace_id,
				'asin'            => $asin,
				'seller_sku'      => $seller_sku,
				'title'           => $title,
				'brand'           => null,
				'gtin'            => $gtin,
				'price'           => $price,
				'quantity'        => $quantity,
				'raw_json'        => null,
				'first_seen_at'   => $now,
				'last_seen_at'    => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
				'link_status'     => 'unlinked',
				'listing_status'  => $listing_status,
			);
			$insert_format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

			$insert_ok = $wpdb->insert( $table, $insert_data, $insert_format );
			if ( $insert_ok ) {
				$imported_count++;
				$new_id = (int) $wpdb->insert_id;
			} else {
				$new_id = 0;
				// Duplicate key (marketplace_id, asin): update existing row (keep wc_product_id, link_status, link_method)
				if ( $wpdb->last_error && strpos( $wpdb->last_error, 'Duplicate' ) !== false ) {
					$updated = $wpdb->update(
						$table,
						array(
							'seller_sku'     => $seller_sku,
							'title'          => $title,
							'gtin'           => $gtin,
							'price'          => $price,
							'quantity'       => $quantity,
							'listing_status' => $listing_status,
							'last_seen_at'   => $now,
							'updated_at'     => $now,
						),
						array( 'marketplace_id' => $marketplace_id, 'asin' => $asin ),
						array( '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s' ),
						array( '%s', '%s' )
					);
					if ( $updated !== false ) {
						$updated_count++;
						$new_id = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT id FROM {$table} WHERE marketplace_id = %s AND asin = %s LIMIT 1",
							$marketplace_id,
							$asin
						) );
					}
				}
				if ( $new_id === 0 ) {
					$insert_failed++;
					if ( $insert_failed <= 5 ) {
						$this->logger->warn(
							'Insert failed for asin=' . $asin . ' last_error=' . ( $wpdb->last_error ?: '(none)' ),
							AmazonLogger::SCOPE_IMPORT
						);
					}
				}
			}
			$inserted_asins[ $asin ] = true;

			// Step 2 — Post-insert check: if we had non-empty title but DB shows empty, log and safeguard.
			if ( $new_id && strlen( $title ) > 0 ) {
				$db_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT title, asin, seller_sku FROM `{$table}` WHERE id = %d",
					$new_id
				), ARRAY_A );
				$db_title = isset( $db_row['title'] ) ? trim( (string) $db_row['title'] ) : '';
				if ( strlen( $db_title ) === 0 ) {
					$candidate = array(
						'asin'         => $asin,
						'seller_sku'   => $seller_sku,
						'insert_id'    => $new_id,
						'item_name_len' => strlen( $title_raw ),
						'title_len'    => strlen( $title ),
						'title_preview' => substr( $title, 0, 80 ),
						'last_error'   => $wpdb->last_error,
						'last_query'   => substr( (string) $wpdb->last_query, 0, 200 ),
					);
					$this->logger->error(
						'[DEBUG] Title lost after INSERT: asin=' . $asin . ' sku=' . $seller_sku . ' insert_id=' . $new_id . ' itemName_len=' . strlen( $title_raw ) . ' title_after_len=' . strlen( $title ) . ' last_error=' . ( $wpdb->last_error ?: '(none)' ) . ' last_query=' . substr( (string) $wpdb->last_query, 0, 150 ),
						AmazonLogger::SCOPE_IMPORT
					);
					if ( $this->is_import_debug() && count( $debug_candidates ) < self::DEBUG_CANDIDATES_MAX ) {
						$debug_candidates[] = $candidate;
						if ( count( $debug_candidates ) <= 3 ) {
							$this->logger->info(
								'[DEBUG] Pre-insert title: title_raw_len=' . $candidate['item_name_len'] . ' title_after_sanitize_len=' . $candidate['title_len'] . ' preview=' . substr( $title, 0, 60 ),
								AmazonLogger::SCOPE_IMPORT
							);
						}
					}
					// Step 5 — Safeguard: forced UPDATE to try to write title again.
					$wpdb->update(
						$table,
						array( 'title' => $title ),
						array( 'id' => $new_id ),
						array( '%s' ),
						array( '%d' )
					);
					$db_row2 = $wpdb->get_row( $wpdb->prepare(
						"SELECT title FROM `{$table}` WHERE id = %d",
						$new_id
					), ARRAY_A );
					$db_title_after = isset( $db_row2['title'] ) ? trim( (string) $db_row2['title'] ) : '';
					if ( strlen( $db_title_after ) === 0 ) {
						$this->logger->error(
							'[DEBUG] Safeguard UPDATE did not persist title for id=' . $new_id . ' asin=' . $asin . ' last_error=' . ( $wpdb->last_error ?: '(none)' ),
							AmazonLogger::SCOPE_IMPORT
						);
					}
				}
			}

			if ( $new_id ) {
				$this->try_auto_link( $table, $new_id, $seller_sku, $gtin, $title );
			}
		}

		if ( $skipped_empty > 0 ) {
			$this->logger->info( "Skipped {$skipped_empty} report rows with empty item-name", AmazonLogger::SCOPE_IMPORT );
		}

		// Verification: COUNT(*) WHERE title empty and first 10 asin/sku
		$verify_empty = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE marketplace_id = %s AND (title IS NULL OR TRIM(COALESCE(title,'')) = '')",
			$marketplace_id
		) );
		$this->logger->info(
			"Verification after import: rows with empty/NULL title = {$verify_empty}",
			AmazonLogger::SCOPE_IMPORT
		);
		$empty_title_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, asin, seller_sku, title FROM {$table} WHERE marketplace_id = %s AND (title IS NULL OR TRIM(COALESCE(title,'')) = '') ORDER BY id ASC LIMIT 10",
			$marketplace_id
		), ARRAY_A );
		if ( ! empty( $empty_title_rows ) ) {
			$this->logger->info( 'First 10 rows with empty/NULL title (asin, seller_sku):', AmazonLogger::SCOPE_IMPORT );
			foreach ( $empty_title_rows as $r ) {
				$this->logger->info(
					sprintf( "  asin=%s seller_sku=%s", isset( $r['asin'] ) ? $r['asin'] : '', isset( $r['seller_sku'] ) ? $r['seller_sku'] : '' ),
					AmazonLogger::SCOPE_IMPORT
				);
			}
		}

		// Step 6 — If verify_empty > skipped_empty: log top 10 problematic and one raw report row for one of them.
		if ( $verify_empty > $skipped_empty && ! empty( $empty_title_rows ) && ! empty( $report_by_asin ) ) {
			$this->logger->info(
				'[DEBUG] verify_empty_title_count (' . $verify_empty . ') > skipped_empty_title (' . $skipped_empty . '). Top 10 problematic asin/sku above; one raw report row below.',
				AmazonLogger::SCOPE_IMPORT
			);
			$one_asin = isset( $empty_title_rows[0]['asin'] ) ? $empty_title_rows[0]['asin'] : '';
			if ( $one_asin !== '' && isset( $report_by_asin[ $one_asin ] ) ) {
				$this->logger->info(
					'[DEBUG] Raw report row for asin=' . $one_asin . ': ' . substr( wp_json_encode( $report_by_asin[ $one_asin ] ), 0, 800 ),
					AmazonLogger::SCOPE_IMPORT
				);
			}
		}

		if ( $this->is_import_debug() && ! empty( $debug_candidates ) ) {
			$this->logger->info( '[DEBUG] debug_candidates count=' . count( $debug_candidates ), AmazonLogger::SCOPE_IMPORT );
		}

		// EAN diagnostics: product-id-type distribution and row counts
		$this->logger->info( 'EAN diagnostics (product-id-type distribution):', AmazonLogger::SCOPE_IMPORT );
		$type_labels = array(
			'1'       => 'ASIN',
			'2'       => 'UPC',
			'3'       => 'ISBN/etc',
			'4'       => 'EAN',
			'(empty)' => 'empty product-id-type',
		);
		ksort( $type_stats );
		foreach ( $type_stats as $t => $cnt ) {
			$label = isset( $type_labels[ $t ] ) ? $type_labels[ $t ] : $t;
			$this->logger->info( "  type={$t} ({$label}): {$cnt} rows", AmazonLogger::SCOPE_IMPORT );
		}
		$this->logger->info( "Rows with EAN saved: {$ean_saved_count}", AmazonLogger::SCOPE_IMPORT );
		$this->logger->info( 'Rows without EAN: ' . ( $imported_count - $ean_saved_count ), AmazonLogger::SCOPE_IMPORT );

		// Validation: count rows with/without EAN in DB
		$count_with_ean = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE marketplace_id = %s AND gtin IS NOT NULL AND gtin <> ''",
			$marketplace_id
		) );
		$count_without_ean = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE marketplace_id = %s AND (gtin IS NULL OR gtin = '')",
			$marketplace_id
		) );
		$this->logger->info(
			"EAN validation: rows with gtin = {$count_with_ean}, rows without gtin = {$count_without_ean}",
			AmazonLogger::SCOPE_IMPORT
		);

		$count_after = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE marketplace_id = %s",
			$marketplace_id
		) );
		$this->logger->info( 'Import finished', AmazonLogger::SCOPE_IMPORT, array(
			'report_rows_count'   => count( $report_rows ),
			'imported_count'     => $imported_count,
			'updated_count'      => $updated_count,
			'insert_failed'       => $insert_failed,
			'skipped_empty_title' => $skipped_empty,
			'verify_empty_title_count' => $verify_empty,
			'rows_in_table_for_marketplace' => $count_after,
		) );
		if ( $insert_failed > 0 ) {
			$this->logger->warn( "Import: {$insert_failed} row(s) failed to insert (see earlier log for last_error)", AmazonLogger::SCOPE_IMPORT );
		}

		return array(
			'new_count'      => $imported_count,
			'updated_count'  => $updated_count,
			'skipped_count'  => $skipped_empty + $insert_failed,
		);
	}

	/**
	 * Refresh listing status, quantity, price from Amazon report for existing rows (no delete, no new insert).
	 * Use when statuses changed (e.g. after stock sync) and you don't want a full re-import.
	 *
	 * @return array{ updated_count: int }|array{ error: string }
	 */
	public function refresh_statuses_from_report() {
		global $wpdb;
		$marketplace_id = $this->get_marketplace_id();
		if ( ! $marketplace_id ) {
			$this->logger->error( 'Marketplace ID not configured', AmazonLogger::SCOPE_IMPORT );
			return array( 'error' => __( 'Marketplace ID not configured.', 'wc-amazon-sync' ) );
		}
		$table = $wpdb->prefix . 'amazon_imported_products';
		$report_result = $this->fetch_report_raw_rows( $marketplace_id );
		if ( isset( $report_result['error'] ) ) {
			return array( 'error' => $report_result['error'] );
		}
		$report_rows = isset( $report_result['report_rows'] ) && is_array( $report_result['report_rows'] ) ? $report_result['report_rows'] : array();
		$this->logger->info( 'Refresh statuses: got ' . count( $report_rows ) . ' report rows', AmazonLogger::SCOPE_IMPORT );
		$now = current_time( 'mysql' );
		$updated_count = 0;
		foreach ( $report_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$asin = '';
			foreach ( array( 'asin', 'asin1', 'asin2', 'asin3' ) as $k ) {
				$v = isset( $row[ $k ] ) ? trim( (string) $row[ $k ] ) : '';
				if ( $v !== '' && strlen( $v ) === 10 && preg_match( '/^[A-Z0-9]{10}$/i', $v ) ) {
					$asin = sanitize_text_field( $v );
					break;
				}
			}
			if ( $asin === '' ) {
				continue;
			}
			$seller_sku = isset( $row['seller-sku'] ) ? sanitize_text_field( substr( trim( (string) $row['seller-sku'] ), 0, 64 ) ) : null;
			if ( $seller_sku === '' ) {
				$seller_sku = null;
			}
			$price = null;
			if ( isset( $row['price'] ) && $row['price'] !== '' ) {
				$p = trim( (string) $row['price'] );
				$p = str_replace( array( ',', ' ' ), array( '.', '' ), $p );
				if ( is_numeric( $p ) ) {
					$price = (float) $p;
				}
			}
			$quantity = null;
			if ( isset( $row['quantity'] ) && $row['quantity'] !== '' && is_numeric( trim( (string) $row['quantity'] ) ) ) {
				$quantity = (int) $row['quantity'];
			}
			$listing_status = null;
			foreach ( array( 'listing-status', 'status', 'item-condition', 'condition', 'listing_status', 'item_status' ) as $status_key ) {
				if ( isset( $row[ $status_key ] ) ) {
					$v = sanitize_text_field( substr( trim( (string) $row[ $status_key ] ), 0, 64 ) );
					if ( $v !== '' ) {
						$listing_status = $v;
						break;
					}
				}
			}
			if ( $listing_status === '' ) {
				$listing_status = null;
			}
			$updated = $wpdb->update(
				$table,
				array(
					'seller_sku'     => $seller_sku,
					'price'          => $price,
					'quantity'       => $quantity,
					'listing_status' => $listing_status,
					'last_seen_at'   => $now,
					'updated_at'     => $now,
				),
				array( 'marketplace_id' => $marketplace_id, 'asin' => $asin ),
				array( '%s', '%f', '%d', '%s', '%s', '%s' ),
				array( '%s', '%s' )
			);
			if ( $updated !== false && $updated > 0 ) {
				$updated_count += (int) $updated;
			}
		}
		$this->logger->info( 'Refresh statuses finished', AmazonLogger::SCOPE_IMPORT, array( 'updated_count' => $updated_count ) );
		return array( 'updated_count' => $updated_count );
	}

	/**
	 * Try to auto-link an imported product row to a WooCommerce product by SKU, EAN, or title.
	 *
	 * @param string $table       Table name
	 * @param int    $row_id      Row id in amazon_imported_products
	 * @param string|null $seller_sku Amazon seller SKU
	 * @param string|null $gtin   EAN/GTIN
	 * @param string|null $title  Product title
	 * @return void
	 */
	private function try_auto_link( $table, $row_id, $seller_sku, $gtin, $title ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, link_status FROM {$table} WHERE id = %d", $row_id ), ARRAY_A );
		if ( ! $row || ( isset( $row['link_status'] ) && $row['link_status'] === 'linked' ) ) {
			return;
		}
		$postmeta = $wpdb->prefix . 'postmeta';
		$posts = $wpdb->prefix . 'posts';
		$wc_product_id = null;
		$link_method = null;
		if ( $seller_sku !== null && $seller_sku !== '' ) {
			$pid = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
				$seller_sku
			) );
			if ( $pid && get_post_type( (int) $pid ) === 'product' ) {
				$wc_product_id = (int) $pid;
				$link_method = 'sku';
			}
		}
		if ( ! $wc_product_id && $gtin !== null && $gtin !== '' ) {
			$pid = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$postmeta} WHERE meta_key IN ('_ean', 'ean', '_gtin') AND meta_value = %s LIMIT 1",
				$gtin
			) );
			if ( $pid && get_post_type( (int) $pid ) === 'product' ) {
				$wc_product_id = (int) $pid;
				$link_method = 'ean';
			}
		}
		if ( ! $wc_product_id && $title !== null && strlen( $title ) >= 3 ) {
			$like = '%' . $wpdb->esc_like( $title ) . '%';
			$pid = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$posts} WHERE post_type = 'product' AND post_status IN ('publish', 'draft', 'private') AND post_title LIKE %s LIMIT 1",
				$like
			) );
			if ( $pid ) {
				$wc_product_id = (int) $pid;
				$link_method = 'title';
			}
		}
		if ( $wc_product_id && $link_method ) {
			$wpdb->update(
				$table,
				array( 'wc_product_id' => $wc_product_id, 'link_status' => 'linked', 'link_method' => $link_method ),
				array( 'id' => $row_id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Get marketplace ID from settings
	 *
	 * @return string|null
	 */
	private function get_marketplace_id() {
		$api = get_option( 'wc_amazon_sync_api', array() );
		if ( is_array( $api ) && ! empty( $api['marketplace_id'] ) ) {
			return sanitize_text_field( $api['marketplace_id'] );
		}
		return null;
	}

	/**
	 * Fetch raw report rows for reset-import (no deduplication, keys = normalized column names).
	 * Title is taken only from item-name in each row.
	 *
	 * @param string $marketplace_id
	 * @return array{ report_rows: array, debug?: array }|array{ error: string }
	 */
	private function fetch_report_raw_rows( $marketplace_id ) {
		$api = get_option( 'wc_amazon_sync_api', array() );
		if ( ! is_array( $api ) ) {
			$api = array();
		}
		if ( empty( $api['client_id'] ) || empty( $api['client_secret'] ) || empty( $api['refresh_token'] ) ) {
			return array( 'error' => __( 'Client ID, Client Secret and Refresh Token are required for Reports API.', 'wc-amazon-sync' ) );
		}
		$this->logger->info( 'Using Reports API (raw report rows for import)', AmazonLogger::SCOPE_IMPORT );
		$client = new \WCAmazonSync\Api\ReportsClient( $api );
		$result = $client->fetch_listings_report_raw( $marketplace_id, 'GET_MERCHANT_LISTINGS_ALL_DATA', 120 );
		if ( ! empty( $result['error'] ) ) {
			return array( 'error' => $result['error'] );
		}
		if ( empty( $result['success'] ) || ! isset( $result['report_rows'] ) ) {
			return array( 'error' => __( 'Reports API did not return report rows.', 'wc-amazon-sync' ) );
		}
		return array(
			'report_rows' => $result['report_rows'],
			'debug'       => isset( $result['debug'] ) ? $result['debug'] : array(),
		);
	}

	/**
	 * Fetch catalog items from Amazon SP-API: try Catalog Items API first, then Reports API (same LWA auth as Feeds).
	 *
	 * @param string $marketplace_id Marketplace ID
	 * @return array List of items with keys asin, seller_sku?, title?, brand?, gtin?, raw?; or ['error' => message]
	 */
	private function fetch_catalog_items( $marketplace_id ) {
		$api = get_option( 'wc_amazon_sync_api', array() );
		if ( ! is_array( $api ) ) {
			$api = array();
		}
		$this->logger->info( 'Using credentials from wc_amazon_sync_api', AmazonLogger::SCOPE_IMPORT, array(
			'has_client_id'      => ! empty( $api['client_id'] ),
			'has_client_secret'  => ! empty( $api['client_secret'] ),
			'has_refresh_token'  => ! empty( $api['refresh_token'] ),
			'has_marketplace_id' => ! empty( $api['marketplace_id'] ),
			'use_sandbox'        => ! empty( $api['use_sandbox'] ),
		) );

		// 1) Try Catalog Items API if AmazonApiClient exists
		if ( class_exists( 'WCAmazonSync\Amazon\AmazonApiClient' ) ) {
			try {
				$client = new \WCAmazonSync\Amazon\AmazonApiClient();
				$items  = $client->get_catalog_items( array( 'marketplace_id' => $marketplace_id ) );
				if ( is_wp_error( $items ) ) {
					$msg = $items->get_error_message();
					if ( strpos( $msg, '403' ) !== false || stripos( $msg, 'Unauthorized' ) !== false ) {
						$this->logger->info( 'Catalog API returned 403; falling back to Reports API', AmazonLogger::SCOPE_IMPORT );
						return $this->fetch_via_reports_api( $marketplace_id, $api );
					}
					return array( 'error' => $msg );
				}
				if ( is_array( $items ) ) {
					return $items;
				}
			} catch ( \Exception $e ) {
				$msg = $e->getMessage();
				if ( strpos( $msg, '403' ) !== false || stripos( $msg, 'Unauthorized' ) !== false ) {
					$this->logger->info( 'Catalog API exception 403; falling back to Reports API', AmazonLogger::SCOPE_IMPORT );
					return $this->fetch_via_reports_api( $marketplace_id, $api );
				}
				$this->logger->error( $msg, AmazonLogger::SCOPE_IMPORT, array( 'exception' => $msg ) );
				return array( 'error' => $msg );
			}
		}

		// 2) Use Reports API (same LWA token as Feeds — works with existing credentials)
		$this->logger->info( 'Using Reports API (GET_MERCHANT_LISTINGS_ALL_DATA)', AmazonLogger::SCOPE_IMPORT );
		return $this->fetch_via_reports_api( $marketplace_id, $api );
	}

	/**
	 * Fetch product list via Reports API (LWA only, same as working Feeds script).
	 *
	 * @param string $marketplace_id
	 * @param array  $api wc_amazon_sync_api option
	 * @return array Items or ['error' => message]
	 */
	private function fetch_via_reports_api( $marketplace_id, array $api ) {
		if ( empty( $api['client_id'] ) || empty( $api['client_secret'] ) || empty( $api['refresh_token'] ) ) {
			return array( 'error' => __( 'Client ID, Client Secret and Refresh Token are required for Reports API.', 'wc-amazon-sync' ) );
		}
		$this->logger->info( 'Requesting report with preferredReportDocumentLocale=en_US for consistent column headers (item-name, asin, ean)', AmazonLogger::SCOPE_IMPORT );
		$client = new \WCAmazonSync\Api\ReportsClient( $api );
		$result = $client->fetch_listings_report( $marketplace_id, 'GET_MERCHANT_LISTINGS_ALL_DATA', 120 );
		if ( ! empty( $result['error'] ) ) {
			$this->logger->error( 'Reports API: ' . $result['error'], AmazonLogger::SCOPE_IMPORT );
			return array( 'error' => $result['error'] );
		}
		$items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		if ( count( $items ) > 0 ) {
			$this->logger->info( 'Reports API returned ' . count( $items ) . ' items', AmazonLogger::SCOPE_IMPORT );
			$empty_title = isset( $result['debug']['empty_title_count'] ) ? (int) $result['debug']['empty_title_count'] : 0;
			$empty_gtin  = isset( $result['debug']['empty_gtin_count'] ) ? (int) $result['debug']['empty_gtin_count'] : 0;
			if ( $empty_title > 0 || $empty_gtin > 0 ) {
				$this->logger->info( "Report parse: {$empty_title} items with empty title, {$empty_gtin} with empty EAN", AmazonLogger::SCOPE_IMPORT );
			}
			if ( ! empty( $result['debug']['header'] ) ) {
				$this->logger->info( 'Report header (columns): ' . implode( ' | ', array_slice( $result['debug']['header'], 0, 25 ) ), AmazonLogger::SCOPE_IMPORT );
			}
			if ( ! empty( $result['debug']['debug_empty_title'] ) ) {
				$this->logger->info( 'Sample rows with missing title/EAN (up to 25):', AmazonLogger::SCOPE_IMPORT );
				foreach ( $result['debug']['debug_empty_title'] as $asin => $debug ) {
					$header_str = isset( $debug['header'] ) ? implode( ' | ', array_slice( $debug['header'], 0, 20 ) ) : '';
					$row_str   = isset( $debug['row'] ) ? implode( ' | ', array_slice( $debug['row'], 0, 20 ) ) : '';
					$missing_title = ! empty( $debug['missing_title'] );
					$missing_gtin  = ! empty( $debug['missing_gtin'] );
					$parts = array();
					if ( $missing_title ) {
						$parts[] = 'no title';
					}
					if ( $missing_gtin ) {
						$parts[] = 'no EAN';
					}
					$this->logger->info( "  ASIN={$asin} (" . implode( ', ', $parts ) . "). Row: " . substr( $row_str, 0, 400 ), AmazonLogger::SCOPE_IMPORT );
				}
			}
			return $items;
		}
		// Log debug when 0 items so we can see report format (header / first row)
		if ( ! empty( $result['debug'] ) ) {
			$d = $result['debug'];
			$this->logger->info( 'Reports API: 0 items. Debug: raw_bytes=' . ( isset( $d['raw_bytes'] ) ? $d['raw_bytes'] : 0 ) . ', lines=' . ( isset( $d['line_count'] ) ? $d['line_count'] : 0 ), AmazonLogger::SCOPE_IMPORT );
			if ( ! empty( $d['header'] ) ) {
				$this->logger->info( 'Report header: ' . implode( ' | ', array_slice( $d['header'], 0, 15 ) ), AmazonLogger::SCOPE_IMPORT );
			}
			if ( ! empty( $d['first_row_preview'] ) ) {
				$this->logger->info( 'Report first row: ' . substr( $d['first_row_preview'], 0, 300 ), AmazonLogger::SCOPE_IMPORT );
			}
		}
		return array();
	}
}
