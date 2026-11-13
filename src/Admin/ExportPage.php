<?php
/**
 * Export Page
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Database\ProductMappingRepository;

/**
 * Export Page Class
 */
class ExportPage {

	/**
	 * Render export page
	 *
	 * @return void
	 */
	public function render(): void {
		$mapping_repo = new ProductMappingRepository();
		$mappings = $mapping_repo->get_all( array( 'limit' => 100 ) );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Export Products to Amazon', 'wc-amazon-sync' ); ?></h1>

			<div class="wc-amazon-sync-export">
				<p><?php esc_html_e( 'Manage products that are synced to Amazon.', 'wc-amazon-sync' ); ?></p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product ID', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'SKU', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Price', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Stock', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Amazon Status', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wc-amazon-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $mappings as $mapping ) : ?>
							<?php
							$product = wc_get_product( $mapping->wc_product_id );
							if ( ! $product ) {
								continue;
							}
							?>
							<tr>
								<td><?php echo esc_html( $product->get_id() ); ?></td>
								<td><?php echo esc_html( $product->get_name() ); ?></td>
								<td><?php echo esc_html( $product->get_sku() ?: '-' ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $product->get_price() ) ); ?></td>
								<td><?php echo esc_html( $product->get_stock_quantity() ?? 0 ); ?></td>
								<td>
									<span class="status-<?php echo esc_attr( $mapping->sync_status ); ?>">
										<?php echo esc_html( ucfirst( $mapping->sync_status ) ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button sync-product" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
										<?php esc_html_e( 'Sync Now', 'wc-amazon-sync' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" id="sync-all" class="button button-primary">
						<?php esc_html_e( 'Sync All Products', 'wc-amazon-sync' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}
}

