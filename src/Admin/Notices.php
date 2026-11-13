<?php
/**
 * Admin Notices
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Notices Class
 */
class Notices {

	/**
	 * Instance
	 *
	 * @var Notices|null
	 */
	private static ?Notices $instance = null;

	/**
	 * Notices array
	 *
	 * @var array
	 */
	private array $notices = array();

	/**
	 * Get instance
	 *
	 * @return Notices
	 */
	public static function instance(): Notices {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Add notice
	 *
	 * @param string $type Notice type (success, error, warning, info)
	 * @param string $message Notice message
	 * @return void
	 */
	public function add_notice( string $type, string $message ): void {
		$this->notices[] = array(
			'type' => sanitize_text_field( $type ),
			'message' => $message,
		);
	}

	/**
	 * Render notices
	 *
	 * @return void
	 */
	public function render_notices(): void {
		// Only show on our admin pages
		if ( ! $this->is_plugin_page() ) {
			return;
		}

		foreach ( $this->notices as $notice ) {
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
				<p><?php echo wp_kses_post( $notice['message'] ); ?></p>
			</div>
			<?php
		}

		// Clear notices after rendering
		$this->notices = array();
	}

	/**
	 * Check if we're on a plugin admin page
	 *
	 * @return bool
	 */
	private function is_plugin_page(): bool {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		// Check if current page is one of our plugin pages
		return strpos( $screen->id, 'wc-amazon-sync' ) !== false;
	}
}

