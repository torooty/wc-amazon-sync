<?php
/**
 * Import Page
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Amazon\AmazonApiClient;
use WCAmazonSync\Amazon\ProductImporter;
use WCAmazonSync\Database\ProductMappingRepository;

/**
 * Import Page Class
 */
class ImportPage {

	/**
	 * Render import page
	 *
	 * @return void
	 */
	public function render(): void {
		$api_client = new \WCAmazonSync\Amazon\AmazonApiClient();
		$mapping_repo = new ProductMappingRepository();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Products from Amazon', 'wc-amazon-sync' ); ?></h1>

			<div class="wc-amazon-sync-import">
				<p>
					<button type="button" id="load-amazon-products" class="button button-primary">
						<?php esc_html_e( 'Load Products from Amazon', 'wc-amazon-sync' ); ?>
					</button>
				</p>

				<!-- Progress overlay for automatic matching -->
				<div id="amazon-sync-progress-overlay" style="display: none;">
					<div class="amazon-sync-progress-content">
						<h3><?php esc_html_e( 'Automatic Product Matching', 'wc-amazon-sync' ); ?></h3>
						<p id="amazon-sync-progress-text"><?php esc_html_e( 'Searching for matching products...', 'wc-amazon-sync' ); ?></p>
						<div class="amazon-sync-progress-bar">
							<div id="amazon-sync-progress-fill" style="width: 0%;"></div>
						</div>
						<p id="amazon-sync-progress-percent">0%</p>
					</div>
				</div>

				<!-- Progress overlay for saving mappings -->
				<div id="amazon-sync-save-progress-overlay" style="display: none;">
					<div class="amazon-sync-progress-content">
						<h3><?php esc_html_e( 'Saving Mappings', 'wc-amazon-sync' ); ?></h3>
						<p id="amazon-sync-save-progress-text"><?php esc_html_e( 'Saving product mappings...', 'wc-amazon-sync' ); ?></p>
						<div class="amazon-sync-progress-bar">
							<div id="amazon-sync-save-progress-fill" style="width: 0%;"></div>
						</div>
						<p id="amazon-sync-save-progress-percent">0%</p>
						<p id="amazon-sync-save-progress-details"><?php esc_html_e( 'Processing...', 'wc-amazon-sync' ); ?></p>
					</div>
				</div>

				<div id="amazon-products-list" style="display: none;">
					<h2><?php esc_html_e( 'Amazon Products', 'wc-amazon-sync' ); ?></h2>
					<p><?php esc_html_e( 'Map Amazon products to WooCommerce products or create new ones.', 'wc-amazon-sync' ); ?></p>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'ASIN', 'wc-amazon-sync' ); ?></th>
								<th><?php esc_html_e( 'Title', 'wc-amazon-sync' ); ?></th>
								<th><?php esc_html_e( 'SKU', 'wc-amazon-sync' ); ?></th>
								<th><?php esc_html_e( 'Price', 'wc-amazon-sync' ); ?></th>
								<th><?php esc_html_e( 'Stock', 'wc-amazon-sync' ); ?></th>
								<th><?php esc_html_e( 'WooCommerce Product', 'wc-amazon-sync' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wc-amazon-sync' ); ?></th>
							</tr>
						</thead>
						<tbody id="amazon-products-tbody">
							<!-- Products will be loaded via AJAX -->
						</tbody>
					</table>

					<p>
						<button type="button" id="save-mapping" class="button button-primary">
							<?php esc_html_e( 'Save Mapping', 'wc-amazon-sync' ); ?>
						</button>
						<button type="button" id="import-selected" class="button">
							<?php esc_html_e( 'Import Selected as New Products', 'wc-amazon-sync' ); ?>
						</button>
					</p>
				</div>
			</div>
		</div>
		<?php
	}
}

