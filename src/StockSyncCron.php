<?php
/**
 * Stock sync cron — schedule and run automatic stock sync to Amazon.
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync;

defined( 'ABSPATH' ) || exit;

/**
 * Registers custom cron schedules and the stock sync event.
 * Reschedule when sync tab is saved (called from SettingsPage).
 */
class StockSyncCron {

	const HOOK = 'wc_amazon_sync_stock_cron';

	/**
	 * Recurrence keys for option value => WP cron schedule
	 *
	 * @var array
	 */
	public static $recurrence_map = array(
		'1hour'   => 'wc_amazon_sync_1hour',
		'2hours'  => 'wc_amazon_sync_2hours',
		'4hours'  => 'wc_amazon_sync_4hours',
		'daily'   => 'daily',
		'disabled' => null,
	);

	/**
	 * Add cron schedules and hook. Reschedule is called when user saves the Synchronization tab.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Add custom intervals for stock sync.
	 *
	 * @param array $schedules Existing schedules
	 * @return array
	 */
	public static function add_schedules( $schedules ) {
		$schedules['wc_amazon_sync_1hour']  = array(
			'interval' => 3600,
			'display'  => __( 'Alle 1 Stunde', 'wc-amazon-sync' ),
		);
		$schedules['wc_amazon_sync_2hours'] = array(
			'interval' => 7200,
			'display'  => __( 'Alle 2 Stunden', 'wc-amazon-sync' ),
		);
		$schedules['wc_amazon_sync_4hours']  = array(
			'interval' => 14400,
			'display'  => __( 'Alle 4 Stunden', 'wc-amazon-sync' ),
		);
		return $schedules;
	}

	/**
	 * Run stock sync (called by cron).
	 */
	public static function run() {
		\WCAmazonSync\Admin\SettingsPage::run_stock_sync( 'auto' );
	}

	/**
	 * Reschedule or clear the next run based on options.
	 * Call after saving sync tab.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );

		$enabled = (bool) get_option( 'wc_amazon_sync_stock_sync_enabled', false );
		$freq    = get_option( 'wc_amazon_sync_stock_sync_frequency', '4hours' );
		$recurrence = isset( self::$recurrence_map[ $freq ] ) ? self::$recurrence_map[ $freq ] : null;

		if ( $enabled && $recurrence && $recurrence !== 'disabled' ) {
			wp_schedule_event( time(), $recurrence, self::HOOK );
		}
	}
}
