<?php
/**
 * PO File Loader
 * 
 * Simple PO file parser for loading translations when .mo file is not available
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Localization;

defined( 'ABSPATH' ) || exit;

/**
 * PO File Loader Class
 */
class PoLoader {

	/**
	 * Load translations from PO file
	 *
	 * @param string $po_file Path to PO file
	 * @return array Translations array (msgid => msgstr)
	 */
	public static function load_po_file( string $po_file ): array {
		if ( ! file_exists( $po_file ) ) {
			return array();
		}

		$content = file_get_contents( $po_file );
		$translations = array();

		// Improved regex-based parser for PO files
		// Match msgid and msgstr pairs, handling multi-line strings
		preg_match_all(
			'/msgid\s+(?:"((?:[^"\\\\]|\\\\.)*)"|""\s*\n(?:"((?:[^"\\\\]|\\\\.)*)")*)\s+msgstr\s+(?:"((?:[^"\\\\]|\\\\.)*)"|""\s*\n(?:"((?:[^"\\\\]|\\\\.)*)")*)/s',
			$content,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			// Handle single-line and multi-line strings
			$msgid_parts = array();
			$msgstr_parts = array();
			
			// Collect msgid parts
			for ( $i = 1; $i <= 2; $i++ ) {
				if ( ! empty( $match[ $i ] ) ) {
					$msgid_parts[] = $match[ $i ];
				}
			}
			
			// Collect msgstr parts
			for ( $i = 3; $i <= 4; $i++ ) {
				if ( ! empty( $match[ $i ] ) ) {
					$msgstr_parts[] = $match[ $i ];
				}
			}
			
			$msgid = self::unescape_string( implode( '', $msgid_parts ) );
			$msgstr = self::unescape_string( implode( '', $msgstr_parts ) );

			// Skip empty msgid (header) or empty translations
			if ( empty( $msgid ) ) {
				continue;
			}

			$translations[ $msgid ] = $msgstr;
		}
		
		// Also try simpler regex for single-line entries
		preg_match_all(
			'/msgid\s+"((?:[^"\\\\]|\\\\.)*)"\s+msgstr\s+"((?:[^"\\\\]|\\\\.)*)"/',
			$content,
			$simple_matches,
			PREG_SET_ORDER
		);
		
		foreach ( $simple_matches as $match ) {
			if ( ! isset( $translations[ $match[1] ] ) ) {
				$msgid = self::unescape_string( $match[1] );
				$msgstr = self::unescape_string( $match[2] );
				
				if ( ! empty( $msgid ) ) {
					$translations[ $msgid ] = $msgstr;
				}
			}
		}

		return $translations;
	}

	/**
	 * Unescape PO string
	 *
	 * @param string $str Escaped string
	 * @return string Unescaped string
	 */
	private static function unescape_string( string $str ): string {
		return stripcslashes( $str );
	}

	/**
	 * Load and register translations from PO file
	 *
	 * @param string $domain Text domain
	 * @param string $po_file Path to PO file
	 * @return bool True on success
	 */
	public static function load_translations( string $domain, string $po_file ): bool {
		global $l10n;

		// Load required WordPress translation classes
		require_once ABSPATH . WPINC . '/pomo/translations.php';
		require_once ABSPATH . WPINC . '/pomo/entry.php';

		if ( ! isset( $l10n[ $domain ] ) ) {
			$l10n[ $domain ] = new \NOOP_Translations();
		}

		$translations = self::load_po_file( $po_file );

		if ( empty( $translations ) ) {
			return false;
		}

		// Add translations to domain
		foreach ( $translations as $msgid => $msgstr ) {
			if ( ! empty( $msgstr ) ) { // Only add non-empty translations
				$l10n[ $domain ]->add_entry(
					new \Translation_Entry(
						array(
							'singular' => $msgid,
							'translations' => array( $msgstr ),
						)
					)
				);
			}
		}

		return true;
	}
}

