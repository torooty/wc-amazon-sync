<?php
/**
 * Amazon Logger - writes to amazon_logs table
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Amazon Logger
 */
class AmazonLogger {

	/**
	 * Log levels
	 */
	const LEVEL_INFO  = 'info';
	const LEVEL_WARN  = 'warn';
	const LEVEL_ERROR = 'error';

	/**
	 * Scopes
	 */
	const SCOPE_IMPORT          = 'import';
	const SCOPE_TEST_CONNECTION = 'test_connection';
	const SCOPE_CATEGORIES      = 'categories';
	const SCOPE_ORDERS            = 'orders';

	/**
	 * Insert a log entry
	 *
	 * @param string $level   info|warn|error
	 * @param string $scope   import|test_connection
	 * @param string $message Message text
	 * @param array  $context Optional context (stored as JSON)
	 * @return int|false Log id or false
	 */
	public function log( $level, $scope, $message, $context = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_logs';
		$now   = current_time( 'mysql' );
		$context_json = ! empty( $context ) ? wp_json_encode( $context ) : null;
		$wpdb->insert(
			$table,
			array(
				'created_at'   => $now,
				'level'        => $level,
				'scope'        => $scope,
				'message'      => $message,
				'context_json' => $context_json,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	/**
	 * Info log
	 *
	 * @param string $message Message
	 * @param string $scope   Scope (default import)
	 * @param array  $context Context
	 */
	public function info( $message, $scope = self::SCOPE_IMPORT, $context = array() ) {
		$this->log( self::LEVEL_INFO, $scope, $message, $context );
	}

	/**
	 * Warning log
	 *
	 * @param string $message Message
	 * @param string $scope   Scope
	 * @param array  $context Context
	 */
	public function warn( $message, $scope = self::SCOPE_IMPORT, $context = array() ) {
		$this->log( self::LEVEL_WARN, $scope, $message, $context );
	}

	/**
	 * Error log
	 *
	 * @param string $message Message
	 * @param string $scope   Scope
	 * @param array  $context Context
	 */
	public function error( $message, $scope = self::SCOPE_IMPORT, $context = array() ) {
		$this->log( self::LEVEL_ERROR, $scope, $message, $context );
	}

	/**
	 * Get recent log entries
	 *
	 * @param int    $limit    Max rows
	 * @param string $level    Filter by level (optional)
	 * @param string $scope    Filter by scope (optional)
	 * @param int    $since_id Only rows with id > since_id (optional, for streaming)
	 * @return array
	 */
	public function get_recent( $limit = 20, $level = null, $scope = null, $since_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_logs';
		$where = array( '1=1' );
		$values = array();
		if ( $since_id > 0 ) {
			$where[] = 'id > %d';
			$values[] = $since_id;
		}
		if ( $level ) {
			$where[] = 'level = %s';
			$values[] = $level;
		}
		if ( $scope ) {
			$where[] = 'scope = %s';
			$values[] = $scope;
		}
		$sql = "SELECT id, created_at, level, scope, message, context_json FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT %d";
		$values[] = $limit;
		$sql = $wpdb->prepare( $sql, $values );
		$results = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Clear all logs (optionally by scope)
	 *
	 * @param string|null $scope Optional scope to clear
	 * @return int Number of rows deleted
	 */
	public function clear( $scope = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'amazon_logs';
		if ( $scope ) {
			$wpdb->delete( $table, array( 'scope' => $scope ), array( '%s' ) );
		} else {
			$wpdb->query( "DELETE FROM {$table}" );
		}
		return (int) $wpdb->rows_affected;
	}
}
