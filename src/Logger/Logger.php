<?php
/**
 * Logger Class
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Logger Class
 */
class Logger {

	/**
	 * Instance
	 *
	 * @var Logger|null
	 */
	private static ?Logger $instance = null;

	/**
	 * Log directory
	 *
	 * @var string
	 */
	private string $log_dir;

	/**
	 * Log file prefix
	 *
	 * @var string
	 */
	private string $log_prefix = 'wc-amazon-sync-';

	/**
	 * Max log file size in bytes (5MB)
	 *
	 * @var int
	 */
	private int $max_file_size = 5242880;

	/**
	 * Number of log files to keep
	 *
	 * @var int
	 */
	private int $max_files = 5;

	/**
	 * Get instance
	 *
	 * @return Logger
	 */
	public static function instance(): Logger {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$upload_dir = wp_upload_dir();
		$this->log_dir = trailingslashit( $upload_dir['basedir'] ) . 'wc-amazon-sync-logs/';

		// Create log directory if it doesn't exist
		if ( ! file_exists( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );

			// Add .htaccess to protect log files
			file_put_contents(
				$this->log_dir . '.htaccess',
				"deny from all\n"
			);

			// Add index.php to prevent directory listing
			file_put_contents(
				$this->log_dir . 'index.php',
				"<?php\n// Silence is golden.\n"
			);
		}
	}

	/**
	 * Log message
	 *
	 * @param string $message Log message
	 * @param string $level Log level (info, warning, error, debug)
	 * @param array  $context Additional context
	 * @return void
	 */
	public function log( string $message, string $level = 'info', array $context = array() ): void {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		$log_file = $this->get_log_file( $level );
		$log_entry = $this->format_log_entry( $message, $level, $context );

		// Write to log file
		file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );

		// Rotate logs if needed
		$this->rotate_logs( $level );

		// Also log to WordPress debug log if WP_DEBUG is enabled
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[WC Amazon Sync] [%s] %s', strtoupper( $level ), $message ) );
		}
	}

	/**
	 * Log info message
	 *
	 * @param string $message Log message
	 * @param array  $context Additional context
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( $message, 'info', $context );
	}

	/**
	 * Log warning message
	 *
	 * @param string $message Log message
	 * @param array  $context Additional context
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( $message, 'warning', $context );
	}

	/**
	 * Log error message
	 *
	 * @param string $message Log message
	 * @param array  $context Additional context
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( $message, 'error', $context );
	}

	/**
	 * Log debug message
	 *
	 * @param string $message Log message
	 * @param array  $context Additional context
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( $message, 'debug', $context );
	}

	/**
	 * Check if should log this level
	 *
	 * @param string $level Log level
	 * @return bool
	 */
	private function should_log( string $level ): bool {
		// In production, skip debug logs unless WP_DEBUG is enabled
		if ( 'debug' === $level && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Get log file path
	 *
	 * @param string $level Log level
	 * @return string
	 */
	private function get_log_file( string $level ): string {
		$date = date( 'Y-m-d' );
		return $this->log_dir . $this->log_prefix . $level . '-' . $date . '.log';
	}

	/**
	 * Format log entry
	 *
	 * @param string $message Log message
	 * @param string $level Log level
	 * @param array  $context Additional context
	 * @return string
	 */
	private function format_log_entry( string $message, string $level, array $context ): string {
		$timestamp = current_time( 'mysql' );
		$entry = sprintf(
			"[%s] [%s] %s",
			$timestamp,
			strtoupper( $level ),
			$message
		);

		if ( ! empty( $context ) ) {
			$entry .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$entry .= "\n";

		return $entry;
	}

	/**
	 * Rotate log files
	 *
	 * @param string $level Log level
	 * @return void
	 */
	private function rotate_logs( string $level ): void {
		$log_file = $this->get_log_file( $level );

		if ( ! file_exists( $log_file ) ) {
			return;
		}

		// Check if file size exceeds limit
		if ( filesize( $log_file ) < $this->max_file_size ) {
			return;
		}

		// Rotate: rename current file
		$rotated_file = $log_file . '.' . time();
		rename( $log_file, $rotated_file );

		// Clean up old log files
		$this->cleanup_old_logs( $level );
	}

	/**
	 * Clean up old log files
	 *
	 * @param string $level Log level
	 * @return void
	 */
	private function cleanup_old_logs( string $level ): void {
		$pattern = $this->log_dir . $this->log_prefix . $level . '-*.log*';
		$files = glob( $pattern );

		if ( ! $files || count( $files ) <= $this->max_files ) {
			return;
		}

		// Sort by modification time (oldest first)
		usort( $files, function( $a, $b ) {
			return filemtime( $a ) - filemtime( $b );
		} );

		// Delete oldest files
		$files_to_delete = count( $files ) - $this->max_files;
		for ( $i = 0; $i < $files_to_delete; $i++ ) {
			@unlink( $files[ $i ] );
		}
	}

	/**
	 * Get log directory path
	 *
	 * @return string
	 */
	public function get_log_dir(): string {
		return $this->log_dir;
	}
}

