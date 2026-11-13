<?php
/**
 * Background Scheduler
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Background;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Settings\SettingsRepository;
use WCAmazonSync\Amazon\InventorySyncService;
use WCAmazonSync\Logger\Logger;

/**
 * Background Scheduler Class
 */
class Scheduler {

	/**
	 * Instance
	 *
	 * @var Scheduler|null
	 */
	private static ?Scheduler $instance = null;

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
	 * Get instance
	 *
	 * @return Scheduler
	 */
	public static function instance(): Scheduler {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->settings = new SettingsRepository();
		$this->logger = Logger::instance();

		// Use Action Scheduler if available (comes with WooCommerce)
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$this->init_action_scheduler();
		} else {
			$this->init_wp_cron();
		}
	}

	/**
	 * Initialize Action Scheduler hooks
	 *
	 * @return void
	 */
	private function init_action_scheduler(): void {
		add_action( 'wc_amazon_sync_sync_inventory', array( $this, 'run_inventory_sync' ) );
		add_action( 'wc_amazon_sync_sync_product', array( $this, 'run_product_sync' ), 10, 1 );
	}

	/**
	 * Initialize WP Cron hooks
	 *
	 * @return void
	 */
	private function init_wp_cron(): void {
		add_action( 'wc_amazon_sync_sync_inventory_cron', array( $this, 'run_inventory_sync' ) );
		add_action( 'wc_amazon_sync_sync_product_cron', array( $this, 'run_product_sync' ), 10, 1 );
	}

	/**
	 * Schedule events
	 *
	 * @return void
	 */
	public function schedule_events(): void {
		$sync_settings = $this->settings->get_group( 'sync' );
		$frequency = absint( $sync_settings['sync_frequency'] ?? 3600 );

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			// Use Action Scheduler
			if ( ! as_next_scheduled_action( 'wc_amazon_sync_sync_inventory' ) ) {
				as_schedule_recurring_action( time(), $frequency, 'wc_amazon_sync_sync_inventory' );
			}
		} else {
			// Use WP Cron
			if ( ! wp_next_scheduled( 'wc_amazon_sync_sync_inventory_cron' ) ) {
				wp_schedule_event( time(), $this->get_cron_interval( $frequency ), 'wc_amazon_sync_sync_inventory_cron' );
			}
		}
	}

	/**
	 * Clear scheduled events
	 *
	 * @return void
	 */
	public function clear_scheduled_events(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wc_amazon_sync_sync_inventory' );
			as_unschedule_all_actions( 'wc_amazon_sync_sync_product' );
		} else {
			wp_clear_scheduled_hook( 'wc_amazon_sync_sync_inventory_cron' );
			wp_clear_scheduled_hook( 'wc_amazon_sync_sync_product_cron' );
		}
	}

	/**
	 * Reschedule events
	 *
	 * @return void
	 */
	public function reschedule_events(): void {
		$this->clear_scheduled_events();
		$this->schedule_events();
	}

	/**
	 * Schedule product sync
	 *
	 * @param int $product_id Product ID
	 * @return void
	 */
	public function schedule_product_sync( int $product_id ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 60, 'wc_amazon_sync_sync_product', array( $product_id ) );
		} else {
			wp_schedule_single_event( time() + 60, 'wc_amazon_sync_sync_product_cron', array( $product_id ) );
		}
	}

	/**
	 * Schedule inventory sync
	 *
	 * @param int $product_id Product ID (optional)
	 * @return void
	 */
	public function schedule_inventory_sync( int $product_id = 0 ): void {
		if ( $product_id > 0 ) {
			$this->schedule_product_sync( $product_id );
		} else {
			// Full sync will be handled by recurring event
		}
	}

	/**
	 * Run inventory sync
	 *
	 * @return void
	 */
	public function run_inventory_sync(): void {
		$this->logger->info( 'Starting scheduled inventory sync' );

		$sync_service = new InventorySyncService();
		$results = $sync_service->sync();

		$this->logger->info(
			sprintf(
				/* translators: %1$d: Success count, %2$d: Error count */
				__( 'Inventory sync completed: %1$d succeeded, %2$d failed.', 'wc-amazon-sync' ),
				$results['success'] ?? 0,
				$results['error'] ?? 0
			)
		);
	}

	/**
	 * Run product sync
	 *
	 * @param int $product_id Product ID
	 * @return void
	 */
	public function run_product_sync( int $product_id ): void {
		$this->logger->info(
			sprintf(
				/* translators: %d: Product ID */
				__( 'Syncing product %d', 'wc-amazon-sync' ),
				$product_id
			)
		);

		$sync_service = new InventorySyncService();
		$results = $sync_service->sync( array( $product_id ) );

		if ( $results['success'] > 0 ) {
			$this->logger->info(
				sprintf(
					/* translators: %d: Product ID */
					__( 'Product %d synced successfully', 'wc-amazon-sync' ),
					$product_id
				)
			);
		} else {
			$this->logger->warning(
				sprintf(
					/* translators: %d: Product ID */
					__( 'Product %d sync failed', 'wc-amazon-sync' ),
					$product_id
				)
			);
		}
	}

	/**
	 * Get cron interval name
	 *
	 * @param int $seconds Seconds
	 * @return string Cron interval name
	 */
	private function get_cron_interval( int $seconds ): string {
		// Register custom interval if needed
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

		if ( $seconds >= 86400 ) {
			return 'daily';
		} elseif ( $seconds >= 3600 ) {
			return 'hourly';
		} else {
			return 'wc_amazon_sync_' . $seconds;
		}
	}

	/**
	 * Add custom cron interval
	 *
	 * @param array $schedules Existing schedules
	 * @return array
	 */
	public function add_cron_interval( array $schedules ): array {
		$sync_settings = $this->settings->get_group( 'sync' );
		$frequency = absint( $sync_settings['sync_frequency'] ?? 3600 );

		if ( $frequency < 3600 ) {
			$schedules[ 'wc_amazon_sync_' . $frequency ] = array(
				'interval' => $frequency,
				'display' => sprintf(
					/* translators: %d: Seconds */
					__( 'Every %d seconds', 'wc-amazon-sync' ),
					$frequency
				),
			);
		}

		return $schedules;
	}
}

