<?php
/**
 * Amazon Sync Logs Page — full-page log viewer (same style as Kaufland Logs).
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

use WCAmazonSync\Logger\AmazonLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Logs Page
 */
class LogsPage {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * AJAX: Stream logs from amazon_logs table (same format as Kaufland for UI).
	 * Registered in Plugin.php so it runs on every admin-ajax request (not only when admin menu is built).
	 */
	public static function ajax_logs_stream() {
		// Temporary early debug (before nonce check) — log only flags/lengths, no secrets
		$req_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$has_action = isset( $_POST['action'] );
		$nonce_key  = 'nonce';
		$has_nonce  = isset( $_POST[ $nonce_key ] );
		$nonce_len  = $has_nonce ? strlen( sanitize_text_field( wp_unslash( $_POST[ $nonce_key ] ) ) ) : 0;
		$logged_in  = is_user_logged_in();
		$can_manage = $logged_in && current_user_can( 'manage_woocommerce' );
		error_log( '[amazon_logs_stream] method=' . $req_method . ' has_action=' . ( $has_action ? '1' : '0' ) . ' has_nonce=' . ( $has_nonce ? '1' : '0' ) . ' nonce_len=' . $nonce_len . ' is_user_logged_in=' . ( $logged_in ? '1' : '0' ) . ' can_manage_woocommerce=' . ( $can_manage ? '1' : '0' ) );

		check_ajax_referer( 'amazon_logs_stream', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Berechtigung fehlt.', 'wc-amazon-sync' ) ) );
		}
		$request = isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
		$level_filter = isset( $request['level'] ) ? sanitize_text_field( wp_unslash( $request['level'] ) ) : '';
		$levels = array();
		if ( $level_filter !== '' ) {
			$levels = array_map( 'trim', explode( ',', $level_filter ) );
			$levels = array_intersect( $levels, array( 'error', 'warn', 'warning', 'info', 'debug' ) );
		}
		$scope_filter = isset( $request['scope'] ) ? sanitize_text_field( wp_unslash( $request['scope'] ) ) : '';
		$limit = isset( $request['limit'] ) ? min( 500, max( 20, (int) $request['limit'] ) ) : 100;
		$since_id = isset( $request['since_id'] ) ? absint( $request['since_id'] ) : 0;

		$logger = new AmazonLogger();
		$rows = $logger->get_recent( $limit, null, $scope_filter !== '' ? $scope_filter : null, $since_id );
		if ( ! empty( $levels ) ) {
			$rows = array_filter( $rows, function ( $r ) use ( $levels ) {
				$l = isset( $r['level'] ) ? strtolower( $r['level'] ) : 'info';
				if ( $l === 'warning' ) {
					$l = 'warn';
				}
				return in_array( $l, $levels, true );
			} );
		}

		$entries = array();
		$last_id = 0;
		foreach ( array_reverse( $rows ) as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id > $last_id ) {
				$last_id = $id;
			}
			$level = isset( $row['level'] ) ? strtolower( $row['level'] ) : 'info';
			if ( $level === 'warning' ) {
				$level = 'warn';
			}
			$msg = isset( $row['message'] ) ? $row['message'] : '';
			$scope = isset( $row['scope'] ) ? $row['scope'] : '';
			if ( $scope !== '' ) {
				$msg = '[' . $scope . '] ' . $msg;
			}
			$context = isset( $row['context_json'] ) && $row['context_json'] !== '' ? $row['context_json'] : '';
			if ( $context !== '' ) {
				$msg .= ' ' . $context;
			}
			$entries[] = array(
				'id'     => 'db-' . $id,
				'db_id'  => $id,
				'source' => 'db',
				'level'  => $level,
				'time'   => isset( $row['created_at'] ) ? $row['created_at'] : '',
				'msg'    => $msg,
				'scope'  => $scope,
				'payload'=> $context,
				'raw'    => '[' . ( isset( $row['created_at'] ) ? $row['created_at'] : '' ) . '] [' . strtoupper( $level ) . '] ' . $msg,
			);
		}
		$entries = array_slice( array_reverse( $entries ), 0, $limit );

		wp_send_json_success( array(
			'entries' => $entries,
			'last_id' => $last_id,
		) );
	}

	/**
	 * Enqueue scripts and styles
	 *
	 * @param string $hook Admin hook
	 */
	public function enqueue_scripts( $hook ) {
		if ( ! is_string( $hook ) || strpos( $hook, 'wc-amazon-sync-logs' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'wc-amazon-sync-logs',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/css/logs-admin.css',
			array(),
			WC_AMAZON_SYNC_VERSION
		);
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script(
			'wc-amazon-sync-logs',
			WC_AMAZON_SYNC_PLUGIN_URL . 'assets/js/logs-admin.js',
			array( 'jquery' ),
			WC_AMAZON_SYNC_VERSION,
			true
		);
		$localize = array(
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'amazon_logs_stream' ),
			'poll_interval' => 35000,
			'i18n'          => array(
				'expand'            => __( 'Erweitern', 'wc-amazon-sync' ),
				'collapse'          => __( 'Einklappen', 'wc-amazon-sync' ),
				'copy'              => __( 'Kopieren', 'wc-amazon-sync' ),
				'copied'            => __( 'Kopiert!', 'wc-amazon-sync' ),
				'entries'           => __( 'Einträge', 'wc-amazon-sync' ),
				'updated'           => __( 'Aktualisiert', 'wc-amazon-sync' ),
				'noLevels'          => __( 'No levels selected', 'wc-amazon-sync' ),
				'jumpToLatest'      => __( 'Zum neuesten', 'wc-amazon-sync' ),
				'clearView'         => __( 'Clear view', 'wc-amazon-sync' ),
				'searchPlaceholder' => __( 'Nachricht durchsuchen…', 'wc-amazon-sync' ),
				'noEntries'         => __( 'Noch keine Einträge. API-Aufrufe erscheinen hier.', 'wc-amazon-sync' ),
			),
		);
		wp_localize_script( 'wc-amazon-sync-logs', 'wcAmazonSyncLogs', $localize );
		// Alias for diagnostics; backend expects nonce under key "nonce"
		wp_localize_script( 'wc-amazon-sync-logs', 'AmazonLogs', array(
			'ajaxUrl'       => $localize['ajax_url'],
			'nonce'         => $localize['nonce'],
			'pollInterval'  => $localize['poll_interval'],
		) );
	}

	/**
	 * Get initial log entries for server-side render
	 *
	 * @return array
	 */
	private function get_initial_entries() {
		$logger = new AmazonLogger();
		$rows = $logger->get_recent( 100, null, null );
		$entries = array();
		foreach ( array_reverse( $rows ) as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$level = isset( $row['level'] ) ? strtolower( $row['level'] ) : 'info';
			if ( $level === 'warning' ) {
				$level = 'warn';
			}
			$msg = isset( $row['message'] ) ? $row['message'] : '';
			$scope = isset( $row['scope'] ) ? $row['scope'] : '';
			if ( $scope !== '' ) {
				$msg = '[' . $scope . '] ' . $msg;
			}
			if ( isset( $row['context_json'] ) && $row['context_json'] !== '' ) {
				$msg .= ' ' . $row['context_json'];
			}
			$entries[] = array(
				'id'    => 'db-' . $id,
				'db_id' => $id,
				'level' => $level,
				'time'  => isset( $row['created_at'] ) ? $row['created_at'] : '',
				'msg'   => $msg,
			);
		}
		return array_slice( $entries, 0, 100 );
	}

	/**
	 * Format time for two-line display
	 *
	 * @param string $time MySQL datetime
	 * @return array [date, time]
	 */
	private function format_time_two_lines( $time ) {
		if ( empty( $time ) ) {
			return array( '—', '—' );
		}
		$parts = preg_split( '/[\sT]/', trim( $time ), 2 );
		$date = isset( $parts[0] ) ? $parts[0] : '—';
		$time_part = isset( $parts[1] ) ? substr( $parts[1], 0, 8 ) : '—';
		return array( $date, $time_part );
	}

	/**
	 * Render page
	 */
	public function render() {
		$logger = new AmazonLogger();
		$logger->info( __( 'Logs-Seite geöffnet', 'wc-amazon-sync' ), AmazonLogger::SCOPE_IMPORT, array( 'source' => 'logs_page' ) );
		$initial = $this->get_initial_entries();
		?>
		<div class="wrap amz-logviewer amz-theme-light" id="amz-logviewer" data-initial="<?php echo esc_attr( wp_json_encode( $initial ) ); ?>">
			<h1><?php esc_html_e( 'Amazon Sync — Logs', 'wc-amazon-sync' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Live-Logs des Amazon-Moduls: API-Anfragen, Kategorien-Sync, Import, Test-Verbindung. Filtern nach Level/Scope, suchen und exportieren.', 'wc-amazon-sync' ); ?></p>
			<div class="amz-logs-content">
				<header class="amz-logviewer__toolbar">
					<div class="amz-logviewer__toolbar-row">
						<div>
							<label for="amz-logviewer-level-filter"><strong><?php esc_html_e( 'Filter nach Level', 'wc-amazon-sync' ); ?>:</strong></label><br>
							<div class="amz-logviewer__pills" role="group" aria-label="<?php esc_attr_e( 'Filter nach Level', 'wc-amazon-sync' ); ?>" id="amz-logviewer-level-filter">
								<button type="button" class="amz-logviewer__pill amz-logviewer__pill--red is-active" data-level="error"><?php esc_html_e( 'Fehler', 'wc-amazon-sync' ); ?></button>
								<button type="button" class="amz-logviewer__pill amz-logviewer__pill--yellow is-active" data-level="warn"><?php esc_html_e( 'Warnungen', 'wc-amazon-sync' ); ?></button>
								<button type="button" class="amz-logviewer__pill amz-logviewer__pill--blue is-active" data-level="info"><?php esc_html_e( 'Info', 'wc-amazon-sync' ); ?></button>
								<button type="button" class="amz-logviewer__pill amz-logviewer__pill--green" data-level="debug"><?php esc_html_e( 'Debug', 'wc-amazon-sync' ); ?></button>
							</div>
							<span class="amz-logviewer__no-levels" style="display:none;"><?php esc_html_e( 'No levels selected', 'wc-amazon-sync' ); ?></span>
						</div>
						<div>
							<label for="amz-logviewer-scope-filter"><strong><?php esc_html_e( 'Scope', 'wc-amazon-sync' ); ?>:</strong></label><br>
							<select id="amz-logviewer-scope-filter" class="amz-logviewer__select">
								<option value=""><?php esc_html_e( 'Alle', 'wc-amazon-sync' ); ?></option>
								<option value="import"><?php esc_html_e( 'Import', 'wc-amazon-sync' ); ?></option>
								<option value="categories"><?php esc_html_e( 'Categories', 'wc-amazon-sync' ); ?></option>
								<option value="orders"><?php esc_html_e( 'Orders', 'wc-amazon-sync' ); ?></option>
								<option value="test_connection"><?php esc_html_e( 'Test connection', 'wc-amazon-sync' ); ?></option>
							</select>
						</div>
						<div>
							<label for="amz-logviewer-search"><strong><?php esc_html_e( 'Search', 'wc-amazon-sync' ); ?>:</strong></label><br>
							<input type="search" id="amz-logviewer-search" class="amz-logviewer__search" placeholder="<?php esc_attr_e( 'Nachricht durchsuchen…', 'wc-amazon-sync' ); ?>" aria-label="<?php esc_attr_e( 'Suchen', 'wc-amazon-sync' ); ?>" />
						</div>
						<div>
							<label for="amz-logviewer-interval"><strong><?php esc_html_e( 'Interval', 'wc-amazon-sync' ); ?>:</strong></label><br>
							<select id="amz-logviewer-interval" class="amz-logviewer__select" data-setting="interval">
								<option value="10">10 s</option>
								<option value="20">20 s</option>
								<option value="35" selected>35 s</option>
								<option value="60">60 s</option>
							</select>
						</div>
						<div>
							<br>
							<button type="button" class="amz-logviewer__btn amz-logviewer__btn--blue" data-action="refresh"><?php esc_html_e( 'Aktualisieren', 'wc-amazon-sync' ); ?></button>
							<button type="button" class="amz-logviewer__btn amz-logviewer__btn--red" data-action="clear"><?php esc_html_e( 'Clear', 'wc-amazon-sync' ); ?></button>
						</div>
						<div class="amz-logviewer__toolbar-right">
							<div>
								<strong style="font-size: 13px;"><?php esc_html_e( 'Optionen', 'wc-amazon-sync' ); ?></strong>
								<br>
								<small style="color: #666; font-size: 11px;"><?php esc_html_e( 'Auto-Refresh, Auto-Scroll, Design', 'wc-amazon-sync' ); ?></small>
							</div>
							<div class="amz-logviewer__toolbar-actions">
								<label class="amz-logviewer__toggle-btn amz-logviewer__toggle-btn--gray">
									<input type="checkbox" class="amz-logviewer__toggle-input" data-setting="autoRefresh" checked />
									<span class="amz-logviewer__toggle-label"><?php esc_html_e( 'Auto-Refresh', 'wc-amazon-sync' ); ?></span>
								</label>
								<label class="amz-logviewer__toggle-btn amz-logviewer__toggle-btn--gray">
									<input type="checkbox" class="amz-logviewer__toggle-input" data-setting="autoScroll" checked />
									<span class="amz-logviewer__toggle-label"><?php esc_html_e( 'Auto-Scroll', 'wc-amazon-sync' ); ?></span>
								</label>
								<div class="amz-logviewer__theme" role="group" aria-label="<?php esc_attr_e( 'Design', 'wc-amazon-sync' ); ?>">
									<button type="button" class="amz-logviewer__theme-btn amz-logviewer__btn--light is-active" data-theme="light"><?php esc_html_e( 'Hell', 'wc-amazon-sync' ); ?></button>
									<button type="button" class="amz-logviewer__theme-btn amz-logviewer__btn--dark" data-theme="dark"><?php esc_html_e( 'Dunkel', 'wc-amazon-sync' ); ?></button>
									<button type="button" class="amz-logviewer__theme-btn amz-logviewer__btn--sepia" data-theme="sepia"><?php esc_html_e( 'Sepia', 'wc-amazon-sync' ); ?></button>
								</div>
							</div>
						</div>
					</div>
				</header>

				<div class="amz-logviewer__inner">
					<div class="amz-logviewer__tableWrap">
						<div class="amz-logviewer__tableInner">
							<table class="amz-logviewer__table">
								<thead>
									<tr>
										<th class="amz-logviewer__th amz-logviewer__th--time"><?php esc_html_e( 'Zeit', 'wc-amazon-sync' ); ?></th>
										<th class="amz-logviewer__th amz-logviewer__th--level"><?php esc_html_e( 'Level', 'wc-amazon-sync' ); ?></th>
										<th class="amz-logviewer__th amz-logviewer__th--msg"><?php esc_html_e( 'Nachricht', 'wc-amazon-sync' ); ?></th>
									</tr>
								</thead>
								<tbody class="amz-logviewer__tbody">
									<?php if ( ! empty( $initial ) ) : ?>
										<?php foreach ( $initial as $e ) : ?>
											<?php
											$ft = $this->format_time_two_lines( $e['time'] ?? '' );
											$msg = $e['msg'] ?? '';
											$level = isset( $e['level'] ) && $e['level'] === 'warning' ? 'warn' : ( $e['level'] ?? 'info' );
											?>
											<tr class="amz-logviewer__row level-<?php echo esc_attr( $level ); ?>" data-id="<?php echo esc_attr( $e['id'] ); ?>" data-level="<?php echo esc_attr( $level ); ?>" data-msg="<?php echo esc_attr( $msg ); ?>"<?php echo ! empty( $e['db_id'] ) ? ' data-db-id="' . (int) $e['db_id'] . '"' : ''; ?>>
												<td class="amz-logviewer__cell amz-logviewer__cell--time"><span class="amz-logviewer__date"><?php echo esc_html( $ft[0] ); ?></span><span class="amz-logviewer__clock"><?php echo esc_html( $ft[1] ); ?></span></td>
												<td class="amz-logviewer__cell amz-logviewer__cell--level"><span class="amz-logviewer__badge"><?php echo esc_html( strtoupper( $level ) ); ?></span></td>
												<td class="amz-logviewer__cell amz-logviewer__cell--msg"><span class="amz-logviewer__msgText"><?php echo esc_html( $msg ); ?></span></td>
											</tr>
										<?php endforeach; ?>
									<?php else : ?>
										<tr class="amz-logviewer__empty"><td colspan="3"><?php esc_html_e( 'Noch keine Einträge. API-Aufrufe (Kategorien, Import, Test) erscheinen hier.', 'wc-amazon-sync' ); ?></td></tr>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
						<div class="amz-logviewer__loading" style="display:none;"><span class="spinner is-active"></span> <?php esc_html_e( 'Lade…', 'wc-amazon-sync' ); ?></div>
						<button type="button" class="amz-logviewer__jump" style="display:none;" data-action="jumpToLatest"><?php esc_html_e( 'Zum neuesten', 'wc-amazon-sync' ); ?></button>
					</div>

					<footer class="amz-logviewer__footer">
						<span class="amz-logviewer__footerCount">0 <?php esc_html_e( 'Einträge', 'wc-amazon-sync' ); ?></span>
						<span class="amz-logviewer__footerUpdated">—</span>
						<div class="amz-logviewer__footerActions">
							<button type="button" class="amz-logviewer__btn amz-logviewer__btn--small" data-action="copy"><?php esc_html_e( 'Kopieren', 'wc-amazon-sync' ); ?></button>
							<button type="button" class="amz-logviewer__btn amz-logviewer__btn--small" data-action="download"><?php esc_html_e( 'Download', 'wc-amazon-sync' ); ?></button>
						</div>
					</footer>
				</div>
			</div>
		</div>
		<?php
	}
}
