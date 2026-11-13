<?php
/**
 * Mapping Page
 *
 * @package WCAmazonSync
 */

namespace WCAmazonSync\Admin;

defined( 'ABSPATH' ) || exit;

use WCAmazonSync\Database\ProductMappingRepository;

/**
 * Mapping Page Class
 */
class MappingPage {

	/**
	 * Render mapping page
	 *
	 * @return void
	 */
	public function render(): void {
		$mapping_repo = new ProductMappingRepository();
		$mappings = $mapping_repo->get_all( array( 'limit' => 1000 ) );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Product Mapping', 'wc-amazon-sync' ); ?></h1>

			<div class="wc-amazon-sync-mapping">
				<p><?php esc_html_e( 'Manage product mappings between WooCommerce and Amazon. Edit prices and update inventory manually.', 'wc-amazon-sync' ); ?></p>

				<p>
					<button type="button" id="delete-all-mappings" class="button button-secondary" style="margin-right: 10px;">
						<?php esc_html_e( 'Delete All Mappings', 'wc-amazon-sync' ); ?>
					</button>
					<button type="button" id="update-all-inventory" class="button button-primary">
						<?php esc_html_e( 'Update All Inventory', 'wc-amazon-sync' ); ?>
					</button>
				</p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'WooCommerce Product', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Amazon ASIN', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Price (Amazon)', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Stock', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Mapping Type', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Sync Status', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Last Synced', 'wc-amazon-sync' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wc-amazon-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $mappings ) ) : ?>
							<tr>
								<td colspan="8" style="text-align: center;">
									<?php esc_html_e( 'No product mappings found.', 'wc-amazon-sync' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $mappings as $mapping ) : ?>
								<?php
								$product = wc_get_product( $mapping->wc_product_id );
								if ( ! $product ) {
									continue;
								}
								$currency_symbol = get_woocommerce_currency_symbol();
								$amazon_price = get_post_meta( $mapping->wc_product_id, '_amazon_price', true );
								?>
								<tr data-mapping-id="<?php echo esc_attr( $mapping->id ); ?>" data-product-id="<?php echo esc_attr( $mapping->wc_product_id ); ?>" data-asin="<?php echo esc_attr( $mapping->amazon_asin ); ?>">
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>">
											<?php echo esc_html( $product->get_name() ); ?>
										</a>
										<br>
										<small><?php echo esc_html( '#' . $product->get_id() . ' - ' . ( $product->get_sku() ?: __( 'No SKU', 'wc-amazon-sync' ) ) ); ?></small>
									</td>
									<td><?php echo esc_html( $mapping->amazon_asin ); ?></td>
									<td>
										<div class="price-editor" style="display: inline-flex; align-items: center; gap: 5px;">
											<span style="margin-right: 2px;"><?php echo esc_html( $currency_symbol ); ?></span>
											<input type="number" 
												class="amazon-price-input" 
												data-product-id="<?php echo esc_attr( $mapping->wc_product_id ); ?>"
												value="<?php echo esc_attr( $amazon_price ?: $product->get_price() ); ?>" 
												step="0.01" 
												min="0" 
												style="width: 80px; padding: 3px;">
											<button type="button" 
												class="button button-small update-price-btn" 
												data-product-id="<?php echo esc_attr( $mapping->wc_product_id ); ?>"
												style="padding: 2px 8px;">
												<?php esc_html_e( 'Update', 'wc-amazon-sync' ); ?>
											</button>
										</div>
									</td>
									<td>
										<?php echo esc_html( $product->get_stock_quantity() ?? 0 ); ?>
										<button type="button" 
											class="button button-small update-inventory-btn" 
											data-product-id="<?php echo esc_attr( $mapping->wc_product_id ); ?>"
											style="margin-left: 5px; padding: 2px 8px;">
											<?php esc_html_e( 'Update', 'wc-amazon-sync' ); ?>
										</button>
									</td>
									<td><?php echo esc_html( ucfirst( $mapping->mapping_type ) ); ?></td>
									<td>
										<span class="status-<?php echo esc_attr( $mapping->sync_status ); ?>">
											<?php echo esc_html( ucfirst( $mapping->sync_status ) ); ?>
										</span>
									</td>
									<td>
										<?php
										if ( $mapping->last_synced_at ) {
											echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $mapping->last_synced_at ) ) );
										} else {
											echo '-';
										}
										?>
									</td>
									<td>
										<button type="button" class="button delete-mapping" data-mapping-id="<?php echo esc_attr( $mapping->id ); ?>">
											<?php esc_html_e( 'Delete', 'wc-amazon-sync' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
