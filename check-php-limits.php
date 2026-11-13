<?php
/**
 * Temporary file to check PHP limits
 * Delete this file after checking
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	require_once '../../../wp-load.php';
}

echo '<h2>PHP Configuration</h2>';
echo '<table border="1" cellpadding="5">';
echo '<tr><th>Setting</th><th>Value</th></tr>';
echo '<tr><td>post_max_size</td><td>' . ini_get('post_max_size') . '</td></tr>';
echo '<tr><td>upload_max_filesize</td><td>' . ini_get('upload_max_filesize') . '</td></tr>';
echo '<tr><td>memory_limit</td><td>' . ini_get('memory_limit') . '</td></tr>';
echo '<tr><td>max_input_vars</td><td>' . ini_get('max_input_vars') . '</td></tr>';
echo '<tr><td>max_execution_time</td><td>' . ini_get('max_execution_time') . '</td></tr>';
echo '<tr><td>Current memory usage</td><td>' . round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB</td></tr>';
echo '</table>';

echo '<h3>$_POST test</h3>';
if (!empty($_POST)) {
	echo '<p>POST data size: ' . strlen(json_encode($_POST)) . ' bytes</p>';
	echo '<p>POST keys: ' . implode(', ', array_keys($_POST)) . '</p>';
} else {
	echo '<p>No POST data</p>';
}

