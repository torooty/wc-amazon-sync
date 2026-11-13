<?php
/**
 * Private plugin updates via module-updates-api (api.webtest24.de).
 *
 * Safe for many shops: remember active state → swap files → always restore active
 * (silent activate). Mid-update deactivate must not wipe cron schedules.
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Update;

defined( 'ABSPATH' ) || exit;

class WordPressUpdater {

	const OPTION_LAST_CHECK     = 'wc_amazon_sync_update_last_check';
	const OPTION_LAST_ERROR     = 'wc_amazon_sync_update_last_error';
	const OPTION_REMOTE_CACHE   = 'wc_amazon_sync_update_remote_cache';
	const OPTION_RESTORE_ACTIVE = 'wc_amazon_sync_update_restore_active';
	const TRANSIENT_REMOTE      = 'wc_amazon_sync_update_remote';
	const CACHE_TTL_OK          = HOUR_IN_SECONDS;
	const CACHE_TTL_FAIL        = 15 * MINUTE_IN_SECONDS;

	/** Default Update URI when header is missing. */
	const DEFAULT_UPDATE_URI = 'https://api.webtest24.de/api/v1/plugins/wc-amazon-sync';

	/** Health endpoint of module-updates-api (not plugin metadata). */
	const HEALTH_URI = 'https://api.webtest24.de/api/v1/health';

	/**
	 * Register WordPress update hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update_transient' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'on_pre_install' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 20, 2 );
		add_action( 'wp_ajax_wc_amazon_sync_check_plugin_update', array( __CLASS__, 'ajax_check_update' ) );
		add_action( 'wp_ajax_wc_amazon_sync_install_plugin_update', array( __CLASS__, 'ajax_install_update' ) );
	}

	/**
	 * Plugin basename (wc-amazon-sync/wc-amazon-sync.php).
	 *
	 * @return string
	 */
	public static function plugin_basename() {
		return defined( 'wc_amazon_sync_PLUGIN_BASENAME' ) ? wc_amazon_sync_PLUGIN_BASENAME : 'wc-amazon-sync/wc-amazon-sync.php';
	}

	/**
	 * Installed version.
	 *
	 * @return string
	 */
	public static function installed_version() {
		return defined( 'wc_amazon_sync_VERSION' ) ? (string) wc_amazon_sync_VERSION : '0.0.0';
	}

	/**
	 * Update server base URL from plugin header Update URI, or default.
	 *
	 * @return string
	 */
	public static function update_uri() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = defined( 'wc_amazon_sync_PLUGIN_FILE' ) ? wc_amazon_sync_PLUGIN_FILE : '';
		if ( $file === '' || ! is_readable( $file ) ) {
			return self::DEFAULT_UPDATE_URI;
		}
		$data = get_plugin_data( $file, false, false );
		$uri  = isset( $data['UpdateURI'] ) ? trim( (string) $data['UpdateURI'] ) : '';
		if ( $uri === '' ) {
			$raw = get_file_data( $file, array( 'UpdateURI' => 'Update URI' ), 'plugin' );
			$uri = isset( $raw['UpdateURI'] ) ? trim( (string) $raw['UpdateURI'] ) : '';
		}
		return $uri !== '' ? $uri : self::DEFAULT_UPDATE_URI;
	}

	/**
	 * Remember whether the plugin was active before Plugin_Upgrader deactivates it.
	 *
	 * @param bool|\WP_Error $return     Continue?
	 * @param array          $hook_extra Extra.
	 * @return bool|\WP_Error
	 */
	public static function on_pre_install( $return, $hook_extra ) {
		if ( is_wp_error( $return ) ) {
			return $return;
		}
		if ( empty( $hook_extra['plugin'] ) || (string) $hook_extra['plugin'] !== self::plugin_basename() ) {
			return $return;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// Persist across the deactivate→replace→activate window (and AJAX death).
		update_option(
			self::OPTION_RESTORE_ACTIVE,
			is_plugin_active( self::plugin_basename() ) ? 1 : 0,
			false
		);
		return $return;
	}

	/**
	 * After any WP plugin upgrade (in-module or Plugins screen), restore active state.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader.
	 * @param array        $options  Result options.
	 * @return void
	 */
	public static function on_upgrader_complete( $upgrader, $options ) {
		unset( $upgrader );
		if ( empty( $options['type'] ) || $options['type'] !== 'plugin' ) {
			return;
		}
		$ours     = self::plugin_basename();
		$affected = array();
		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			$affected = $options['plugins'];
		} elseif ( ! empty( $options['plugin'] ) ) {
			$affected = array( (string) $options['plugin'] );
		}
		if ( ! in_array( $ours, $affected, true ) ) {
			return;
		}
		self::ensure_active_after_update();
	}

	/**
	 * Silently re-add plugin to active_plugins if it was active before the swap.
	 *
	 * @return true|\WP_Error
	 */
	public static function ensure_active_after_update() {
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$basename = self::plugin_basename();
		$restore  = get_option( self::OPTION_RESTORE_ACTIVE, null );
		// Default: restore if flag missing but main file looks like a normal update path.
		$should_restore = ( $restore === null ) ? true : ( (int) $restore === 1 );

		$main = WP_PLUGIN_DIR . '/' . $basename;
		if ( ! is_readable( $main ) ) {
			return new \WP_Error(
				'missing_main',
				__( 'Plugin-Hauptdatei nach Update nicht gefunden.', 'wc-amazon-sync' )
			);
		}

		if ( $should_restore && ! is_plugin_active( $basename ) ) {
			// silent=true: do not re-run activation hooks (migrations already run on load).
			$result = activate_plugin( $basename, '', false, true );
			if ( is_wp_error( $result ) ) {
				update_option( self::OPTION_LAST_ERROR, $result->get_error_message(), false );
				return $result;
			}
		}

		delete_option( self::OPTION_RESTORE_ACTIVE );
		return true;
	}

	/**
	 * Inject update row into WordPress plugin update transient.
	 *
	 * @param object|mixed $transient Site transient.
	 * @return object|mixed
	 */
	public static function inject_update_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$basename  = self::plugin_basename();
		$installed = self::installed_version();
		$remote    = self::get_remote_info( false );
		if ( ! is_array( $remote ) ) {
			return $transient;
		}

		$remote_version = self::extract_version( $remote );
		$package        = self::extract_package( $remote );
		if ( $remote_version === '' || $package === '' ) {
			return $transient;
		}
		if ( version_compare( $remote_version, $installed, '<=' ) ) {
			if ( isset( $transient->response[ $basename ] ) ) {
				unset( $transient->response[ $basename ] );
			}
			$transient->no_update[ $basename ] = self::build_plugin_info_object( $remote, false );
			return $transient;
		}

		$transient->response[ $basename ] = self::build_plugin_info_object( $remote, true );
		return $transient;
	}

	/**
	 * Provide plugin details for the WP “View version details” modal.
	 *
	 * @param mixed  $result Default false.
	 * @param string $action API action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public static function plugins_api( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || ! is_object( $args ) ) {
			return $result;
		}
		$slug = isset( $args->slug ) ? (string) $args->slug : '';
		if ( $slug !== 'wc-amazon-sync' && $slug !== dirname( self::plugin_basename() ) ) {
			return $result;
		}

		$remote = self::get_remote_info( false );
		if ( ! is_array( $remote ) ) {
			return $result;
		}

		$info = self::build_plugins_api_object( $remote );
		return $info ? $info : $result;
	}

	/**
	 * Ensure unzipped folder is named wc-amazon-sync (WP expects matching slug).
	 *
	 * @param string       $source        Source path.
	 * @param string       $remote_source Remote source root.
	 * @param \WP_Upgrader $upgrader      Upgrader.
	 * @param array        $hook_extra    Extra.
	 * @return string|\WP_Error
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;
		unset( $upgrader );
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::plugin_basename() ) {
			return $source;
		}
		if ( ! is_string( $source ) || $source === '' || ! $wp_filesystem ) {
			return $source;
		}

		$desired        = trailingslashit( $remote_source ) . 'wc-amazon-sync';
		$source_trimmed = untrailingslashit( $source );
		if ( basename( $source_trimmed ) === 'wc-amazon-sync' ) {
			return $source;
		}
		// Reject nested zip mistakes early (wc-amazon-sync/wc-amazon-sync/…).
		$nested = trailingslashit( $source_trimmed ) . 'wc-amazon-sync/wc-amazon-sync.php';
		if ( $wp_filesystem->exists( $nested ) ) {
			$inner = trailingslashit( $source_trimmed ) . 'wc-amazon-sync';
			if ( $wp_filesystem->move( $inner, $desired ) ) {
				return trailingslashit( $desired );
			}
			return new \WP_Error(
				'bad_zip_layout',
				__( 'Update-ZIP hat eine verschachtelte Ordnerstruktur. Bitte Release neu bauen.', 'wc-amazon-sync' )
			);
		}
		if ( $wp_filesystem->move( $source, $desired ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}

	/**
	 * AJAX: force remote check and return status for settings UI.
	 *
	 * @return void
	 */
	public static function ajax_check_update() {
		check_ajax_referer( 'wc_amazon_sync_check_plugin_update', 'nonce' );
		if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'wc-amazon-sync' ) ) );
		}

		$remote = self::get_remote_info( true );
		$status = self::get_status_payload( is_array( $remote ) ? $remote : null );
		if ( ! is_array( $remote ) ) {
			$error = (string) get_option( self::OPTION_LAST_ERROR, '' );
			wp_send_json_error(
				array(
					'message' => $error !== '' ? $error : __( 'Update-Server nicht erreichbar.', 'wc-amazon-sync' ),
					'status'  => $status,
				)
			);
		}

		delete_site_transient( 'update_plugins' );

		wp_send_json_success(
			array(
				'message' => ! empty( $status['update_available'] )
					? sprintf(
						/* translators: %s: remote version */
						__( 'Neue Version verfügbar: %s', 'wc-amazon-sync' ),
						$status['remote_version']
					)
					: __( 'Sie verwenden die aktuelle Version.', 'wc-amazon-sync' ),
				'status' => $status,
			)
		);
	}

	/**
	 * AJAX: install available update from within the module settings page.
	 *
	 * @return void
	 */
	public static function ajax_install_update() {
		check_ajax_referer( 'wc_amazon_sync_check_plugin_update', 'nonce' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Keine Berechtigung zum Aktualisieren von Plugins (Recht „update_plugins“ nötig).', 'wc-amazon-sync' ),
				)
			);
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$remote = self::get_remote_info( true );
		if ( ! is_array( $remote ) ) {
			$error = (string) get_option( self::OPTION_LAST_ERROR, '' );
			wp_send_json_error(
				array(
					'message' => $error !== '' ? $error : __( 'Update-Server nicht erreichbar.', 'wc-amazon-sync' ),
				)
			);
		}

		$status  = self::get_status_payload( $remote );
		$package = self::extract_package( $remote );
		$target  = self::extract_version( $remote );
		if ( empty( $status['update_available'] ) ) {
			wp_send_json_success(
				array(
					'message'  => __( 'Sie verwenden bereits die aktuelle Version.', 'wc-amazon-sync' ),
					'status'   => $status,
					'reloaded' => false,
				)
			);
		}
		if ( $package === '' || $target === '' ) {
			wp_send_json_error( array( 'message' => __( 'Download-URL oder Version fehlt in den Update-Metadaten.', 'wc-amazon-sync' ) ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! class_exists( '\Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		// Pre-flight: download + validate ZIP before WordPress tears anything down.
		$tmp = download_url( $package, 120 );
		if ( is_wp_error( $tmp ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error */
						__( 'Download fehlgeschlagen: %s', 'wc-amazon-sync' ),
						$tmp->get_error_message()
					),
					'status'  => $status,
				)
			);
		}

		$validated = self::validate_update_zip( $tmp, $target );
		if ( is_wp_error( $validated ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_send_json_error(
				array(
					'message' => $validated->get_error_message(),
					'status'  => $status,
				)
			);
		}

		// Mark restore-active BEFORE upgrader deactivates (also set again in on_pre_install).
		update_option( self::OPTION_RESTORE_ACTIVE, is_plugin_active( self::plugin_basename() ) ? 1 : 0, false );

		// Point WP at the already-validated local ZIP (no second untrusted download).
		self::prime_update_transient( $remote, $tmp );

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		// Standard upgrade(): deactivate → replace → activate. Our hooks restore if activate fails.
		$result = $upgrader->upgrade( self::plugin_basename() );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_wp_error( $result ) ) {
			self::ensure_active_after_update();
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'status'  => $status,
				)
			);
		}
		if ( false === $result ) {
			self::ensure_active_after_update();
			$messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
			$detail   = is_array( $messages ) && ! empty( $messages ) ? implode( ' ', $messages ) : '';
			wp_send_json_error(
				array(
					'message' => $detail !== ''
						? $detail
						: __( 'Update fehlgeschlagen (Dateisystem / Berechtigungen).', 'wc-amazon-sync' ),
					'status'  => $status,
				)
			);
		}

		delete_site_transient( 'update_plugins' );
		delete_transient( self::TRANSIENT_REMOTE );

		$restored = self::ensure_active_after_update();
		if ( is_wp_error( $restored ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: version, 2: error */
						__( 'Dateien für %1$s wurden installiert, Aktivierung fehlgeschlagen: %2$s. Bitte unter Plugins manuell aktivieren — der Shop bleibt sonst ohne Amazon-Menü.', 'wc-amazon-sync' ),
						$target,
						$restored->get_error_message()
					),
					'status'  => $status,
				)
			);
		}

		$disk_version = self::version_on_disk();
		if ( $disk_version !== '' && version_compare( $disk_version, $target, '<' ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: expected, 2: found */
						__( 'Update unvollständig: erwartet %1$s, auf Disk %2$s. Bitte erneut versuchen.', 'wc-amazon-sync' ),
						$target,
						$disk_version
					),
					'status'  => $status,
				)
			);
		}

		$status_after                   = self::get_status_payload( null );
		$status_after['installed_version'] = $disk_version !== '' ? $disk_version : $target;
		$status_after['update_available']  = false;
		$status_after['remote_version']    = $target;

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %s: new version */
					__( 'Update auf %s installiert und aktiv. Seite wird neu geladen…', 'wc-amazon-sync' ),
					$target
				),
				'status'   => $status_after,
				'reloaded' => true,
			)
		);
	}

	/**
	 * Validate ZIP layout before swapping files on the live shop.
	 *
	 * @param string $zip_path Local ZIP path.
	 * @param string $expected_version Remote version.
	 * @return true|\WP_Error
	 */
	private static function validate_update_zip( $zip_path, $expected_version ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// Cannot deeply validate — still require non-empty file.
			if ( ! is_readable( $zip_path ) || filesize( $zip_path ) < 1000 ) {
				return new \WP_Error( 'bad_zip', __( 'Update-ZIP ist leer oder unlesbar.', 'wc-amazon-sync' ) );
			}
			return true;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'bad_zip', __( 'Update-ZIP konnte nicht geöffnet werden.', 'wc-amazon-sync' ) );
		}

		$main = null;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$name = str_replace( '\\', '/', (string) $zip->getNameIndex( $i ) );
			if ( $name === 'wc-amazon-sync/wc-amazon-sync.php' || preg_match( '#^wc-amazon-sync/wc-amazon-sync\.php$#', $name ) ) {
				$main = $name;
				break;
			}
		}
		if ( $main === null ) {
			$zip->close();
			return new \WP_Error(
				'bad_zip_layout',
				__( 'Update-ZIP ungültig: erwartet Ordner wc-amazon-sync/ mit wc-amazon-sync.php.', 'wc-amazon-sync' )
			);
		}

		$contents = $zip->getFromName( $main );
		$zip->close();
		if ( ! is_string( $contents ) || $contents === '' ) {
			return new \WP_Error( 'bad_zip', __( 'Hauptdatei im ZIP ist leer.', 'wc-amazon-sync' ) );
		}
		if ( strncmp( $contents, "\xEF\xBB\xBF", 3 ) === 0 ) {
			return new \WP_Error(
				'zip_bom',
				__( 'Update-ZIP enthält UTF-8 BOM in PHP-Dateien — Release neu ohne BOM bauen.', 'wc-amazon-sync' )
			);
		}
		if ( ! preg_match( '/^\s*<\?php/', $contents ) ) {
			return new \WP_Error( 'bad_zip', __( 'Hauptdatei im ZIP beginnt nicht mit <?php.', 'wc-amazon-sync' ) );
		}
		if ( preg_match( '/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/m', $contents, $m ) ) {
			$zip_ver = $m[1];
			if ( version_compare( $zip_ver, $expected_version, '!=' ) ) {
				return new \WP_Error(
					'version_mismatch',
					sprintf(
						/* translators: 1: zip version, 2: api version */
						__( 'Versionskonflikt: ZIP=%1$s, API=%2$s. Release nicht veröffentlichen.', 'wc-amazon-sync' ),
						$zip_ver,
						$expected_version
					)
				);
			}
		}
		return true;
	}

	/**
	 * Version string read from disk (after swap).
	 *
	 * @return string
	 */
	private static function version_on_disk() {
		$file = WP_PLUGIN_DIR . '/' . self::plugin_basename();
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$data = get_file_data( $file, array( 'Version' => 'Version' ), 'plugin' );
		return isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';
	}

	/**
	 * Ensure WP knows about our private package before Plugin_Upgrader runs.
	 *
	 * @param array<string, mixed> $remote      Remote metadata.
	 * @param string               $local_package Optional local ZIP path (already validated).
	 * @return void
	 */
	private static function prime_update_transient( array $remote, $local_package = '' ) {
		$plugin = self::plugin_basename();
		$obj    = self::build_plugin_info_object( $remote, true );
		if ( is_string( $local_package ) && $local_package !== '' && is_readable( $local_package ) ) {
			$obj->package = $local_package;
		}

		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
			$transient->checked = array();
		}
		$transient->checked[ $plugin ]  = self::installed_version();
		$transient->response[ $plugin ] = $obj;
		$transient->last_checked        = time();
		set_site_transient( 'update_plugins', $transient );
	}

	/**
	 * Status array for admin UI.
	 *
	 * @param array|null $remote Optional preloaded remote.
	 * @return array<string, mixed>
	 */
	public static function get_status_payload( $remote = null ) {
		if ( $remote === null ) {
			$cached = get_transient( self::TRANSIENT_REMOTE );
			$remote = is_array( $cached ) ? $cached : null;
			if ( $remote === null ) {
				$opt    = get_option( self::OPTION_REMOTE_CACHE, null );
				$remote = is_array( $opt ) ? $opt : null;
			}
		}

		$installed        = self::installed_version();
		$remote_version   = is_array( $remote ) ? self::extract_version( $remote ) : '';
		$update_available = ( $remote_version !== '' && version_compare( $remote_version, $installed, '>' ) );

		return array(
			'installed_version' => $installed,
			'remote_version'    => $remote_version,
			'update_available'  => $update_available,
			'update_uri'        => self::update_uri(),
			'health_uri'        => self::HEALTH_URI,
			'package'           => is_array( $remote ) ? self::extract_package( $remote ) : '',
			'last_check'        => (string) get_option( self::OPTION_LAST_CHECK, '' ),
			'last_error'        => (string) get_option( self::OPTION_LAST_ERROR, '' ),
			'plugins_url'       => admin_url( 'plugins.php' ),
			'update_core_url'   => admin_url( 'update-core.php' ),
		);
	}

	/**
	 * Fetch (or return cached) remote metadata.
	 *
	 * @param bool $force Force HTTP request.
	 * @return array|null
	 */
	public static function get_remote_info( $force = false ) {
		if ( ! $force ) {
			$fail_flag = get_transient( self::TRANSIENT_REMOTE . '_fail' );
			if ( $fail_flag ) {
				return null;
			}
			$cached = get_transient( self::TRANSIENT_REMOTE );
			if ( is_array( $cached ) && self::extract_version( $cached ) !== '' ) {
				return $cached;
			}
		}

		$uri        = self::update_uri();
		$urls       = self::build_request_urls( $uri );
		$last_error = '';
		$remote     = null;

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 12,
					'headers' => array(
						'Accept'                 => 'application/json',
						'User-Agent'             => 'WCAmazonSync/' . self::installed_version() . '; ' . home_url( '/' ),
						'X-wc-amazon-sync-Version' => self::installed_version(),
						'X-wc-amazon-sync-Site'    => home_url( '/' ),
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = trim( (string) wp_remote_retrieve_body( $response ) );
			if ( $code < 200 || $code >= 300 || $body === '' ) {
				$last_error = sprintf(
					/* translators: 1: HTTP status, 2: URL */
					__( 'Update-Server HTTP %1$d (%2$s)', 'wc-amazon-sync' ),
					$code,
					$url
				);
				continue;
			}
			$decoded = json_decode( $body, true );
			if ( ! is_array( $decoded ) ) {
				$last_error = __( 'Update-Server lieferte ungültiges JSON.', 'wc-amazon-sync' );
				continue;
			}
			$normalized = self::normalize_remote_payload( $decoded );
			if ( self::extract_version( $normalized ) === '' ) {
				if ( isset( $decoded['service'] ) && (string) $decoded['service'] === 'module-updates-api' ) {
					$last_error = __( 'API erreichbar, aber Plugin-Metadaten noch nicht veröffentlicht.', 'wc-amazon-sync' );
					continue;
				}
				$last_error = __( 'Update-Metadaten ohne Versionsnummer.', 'wc-amazon-sync' );
				continue;
			}
			$remote     = $normalized;
			$last_error = '';
			break;
		}

		update_option( self::OPTION_LAST_CHECK, current_time( 'mysql' ), false );
		update_option( self::OPTION_LAST_ERROR, $last_error, false );

		if ( is_array( $remote ) ) {
			delete_transient( self::TRANSIENT_REMOTE . '_fail' );
			set_transient( self::TRANSIENT_REMOTE, $remote, self::CACHE_TTL_OK );
			update_option( self::OPTION_REMOTE_CACHE, $remote, false );
			return $remote;
		}

		set_transient( self::TRANSIENT_REMOTE . '_fail', 1, self::CACHE_TTL_FAIL );
		delete_transient( self::TRANSIENT_REMOTE );
		return null;
	}

	/**
	 * Candidate request URLs for a base Update URI.
	 *
	 * @param string $uri Base URI.
	 * @return string[]
	 */
	private static function build_request_urls( $uri ) {
		$uri  = untrailingslashit( trim( (string) $uri ) );
		$urls = array();
		if ( $uri !== '' ) {
			$query  = array(
				'slug'              => 'wc-amazon-sync',
				'installed_version' => self::installed_version(),
			);
			$urls[] = $uri;
			$urls[] = $uri . '/';
			$urls[] = add_query_arg( $query, $uri );
			$urls[] = $uri . '/metadata';
			$urls[] = add_query_arg( $query, $uri . '/metadata' );
			$urls[] = $uri . '/latest';
			$urls[] = $uri . '.json';
			$urls[] = $uri . '/info.json';
			$urls[] = add_query_arg(
				array(
					'action'  => 'get_metadata',
					'slug'    => 'wc-amazon-sync',
					'version' => self::installed_version(),
				),
				$uri
			);
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Unwrap Laravel-style envelopes to a flat metadata array.
	 *
	 * @param array<string, mixed> $payload Raw JSON.
	 * @return array<string, mixed>
	 */
	private static function normalize_remote_payload( array $payload ) {
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			$inner = $payload['data'];
			if ( isset( $inner['plugin'] ) && is_array( $inner['plugin'] ) ) {
				return $inner['plugin'];
			}
			if ( self::extract_version( $inner ) !== '' || self::extract_package( $inner ) !== '' ) {
				return $inner;
			}
		}
		if ( isset( $payload['plugin'] ) && is_array( $payload['plugin'] ) ) {
			return $payload['plugin'];
		}
		return $payload;
	}

	/**
	 * @param array<string, mixed> $remote Remote meta.
	 * @return string
	 */
	private static function extract_version( array $remote ) {
		foreach ( array( 'version', 'new_version', 'Version' ) as $key ) {
			if ( ! empty( $remote[ $key ] ) && is_scalar( $remote[ $key ] ) ) {
				return trim( (string) $remote[ $key ] );
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $remote Remote meta.
	 * @return string
	 */
	private static function extract_package( array $remote ) {
		foreach ( array( 'download_url', 'package', 'download_link', 'package_url' ) as $key ) {
			if ( ! empty( $remote[ $key ] ) && is_string( $remote[ $key ] ) ) {
				return esc_url_raw( $remote[ $key ] );
			}
		}
		return '';
	}

	/**
	 * Object for update_plugins transient response/no_update.
	 *
	 * @param array<string, mixed> $remote    Remote meta.
	 * @param bool                 $is_update Whether this is an available update.
	 * @return object
	 */
	private static function build_plugin_info_object( array $remote, $is_update ) {
		$version = self::extract_version( $remote );
		$package = self::extract_package( $remote );
		return (object) array(
			'id'           => 'wc-amazon-sync',
			'slug'         => 'wc-amazon-sync',
			'plugin'       => self::plugin_basename(),
			'new_version'  => $version,
			'url'          => ! empty( $remote['homepage'] ) ? (string) $remote['homepage'] : self::update_uri(),
			'package'      => $is_update ? $package : '',
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
			'requires'     => isset( $remote['requires'] ) ? (string) $remote['requires'] : '',
			'tested'       => isset( $remote['tested'] ) ? (string) $remote['tested'] : '',
			'requires_php' => isset( $remote['requires_php'] ) ? (string) $remote['requires_php'] : '',
		);
	}

	/**
	 * Object for plugins_api plugin_information.
	 *
	 * @param array<string, mixed> $remote Remote meta.
	 * @return object|null
	 */
	private static function build_plugins_api_object( array $remote ) {
		$version = self::extract_version( $remote );
		if ( $version === '' ) {
			return null;
		}
		$sections = array();
		if ( isset( $remote['sections'] ) && is_array( $remote['sections'] ) ) {
			foreach ( $remote['sections'] as $k => $v ) {
				$sections[ (string) $k ] = (string) $v;
			}
		}
		if ( empty( $sections['description'] ) && ! empty( $remote['description'] ) ) {
			$sections['description'] = (string) $remote['description'];
		}
		if ( empty( $sections['changelog'] ) && ! empty( $remote['changelog'] ) ) {
			$sections['changelog'] = (string) $remote['changelog'];
		}

		return (object) array(
			'name'          => ! empty( $remote['name'] ) ? (string) $remote['name'] : 'Amazon Sync',
			'slug'          => 'wc-amazon-sync',
			'version'       => $version,
			'author'        => ! empty( $remote['author'] ) ? (string) $remote['author'] : 'Amazon Sync',
			'homepage'      => ! empty( $remote['homepage'] ) ? (string) $remote['homepage'] : self::update_uri(),
			'requires'      => isset( $remote['requires'] ) ? (string) $remote['requires'] : '6.0',
			'tested'        => isset( $remote['tested'] ) ? (string) $remote['tested'] : '',
			'requires_php'  => isset( $remote['requires_php'] ) ? (string) $remote['requires_php'] : '8.1',
			'download_link' => self::extract_package( $remote ),
			'sections'      => $sections,
			'last_updated'  => isset( $remote['last_updated'] ) ? (string) $remote['last_updated'] : '',
		);
	}
}
