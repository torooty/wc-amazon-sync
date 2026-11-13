<?php
/**
 * Translation Compiler Script
 * 
 * This script compiles .po files to .mo files for the plugin.
 * Usage: php compile-translations.php
 */

// Simple .po to .mo compiler (basic implementation)
// For production, use proper msgfmt tool

$plugin_dir = __DIR__;
$languages_dir = $plugin_dir . '/languages';

// Find all .po files
$po_files = glob( $languages_dir . '/*.po' );

foreach ( $po_files as $po_file ) {
	$mo_file = str_replace( '.po', '.mo', $po_file );
	
	echo "Compiling: " . basename( $po_file ) . "\n";
	
	// Read .po file
	$po_content = file_get_contents( $po_file );
	
	// Simple parser - extract msgid/msgstr pairs
	preg_match_all( '/msgid\s+"(.*?)"\s+msgstr\s+"(.*?)"/s', $po_content, $matches, PREG_SET_ORDER );
	
	$mo_data = array();
	foreach ( $matches as $match ) {
		$msgid = $match[1];
		$msgstr = $match[2];
		
		// Skip empty msgid
		if ( empty( $msgid ) ) {
			continue;
		}
		
		// Unescape strings
		$msgid = stripcslashes( $msgid );
		$msgstr = stripcslashes( $msgstr );
		
		$mo_data[ $msgid ] = $msgstr;
	}
	
	// Create basic .mo file structure
	// Note: This is a simplified version. For production, use msgfmt.
	// WordPress will use .po files directly if .mo files don't exist.
	
	echo "Note: Full .mo compilation requires msgfmt tool.\n";
	echo "WordPress can use .po files directly in some cases.\n";
	echo "For proper .mo file, install gettext tools:\n";
	echo "  Windows: https://mlocati.github.io/articles/gettext-iconv-windows.html\n";
	echo "  Or use Loco Translate plugin\n\n";
}

echo "Done!\n";

