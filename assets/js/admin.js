/**
 * Admin Scripts
 *
 * @package WCAmazonSync
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Test connection - use nonce from button data attribute if available
		$('#test-connection').on('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			
			var $button = $(this);
			
			// Prevent double-click
			if ($button.prop('disabled')) {
				return false;
			}
			
			$button.prop('disabled', true).text('Testing...');

			// Get nonce from button data attribute or fallback to localized script
			var nonce = $button.data('nonce') || wcAmazonSync?.testConnectionNonce || wcAmazonSync?.nonce || '';
			if (!nonce) {
				alert('Error: Security nonce is missing. Please refresh the page.');
				$button.prop('disabled', false).text('Test Connection');
				return false;
			}

			$.ajax({
				url: wcAmazonSync?.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'wc_amazon_sync_test_connection',
					nonce: nonce
				},
				success: function(response) {
					if (response.success) {
						alert('Connection successful!');
					} else {
						var errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error';
						alert('Connection failed: ' + errorMsg);
					}
				},
				error: function(xhr, status, error) {
					var errorMsg = 'Request failed';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMsg = xhr.responseJSON.data.message;
					} else if (xhr.responseText) {
						try {
							var parsed = JSON.parse(xhr.responseText);
							if (parsed.data && parsed.data.message) {
								errorMsg = parsed.data.message;
							}
						} catch(e) {
							errorMsg = error || status || 'Unknown error';
						}
					}
					alert('Error: ' + errorMsg);
				},
				complete: function() {
					$button.prop('disabled', false).text('Test Connection');
				}
			});
			
			return false;
		});

		// Load Amazon products
		$('#load-amazon-products').on('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			
			var $button = $(this);
			
			// Prevent double-click
			if ($button.prop('disabled')) {
				return false;
			}
			
			$button.prop('disabled', true).text('Loading... (this may take several minutes)');

			$.ajax({
				url: wcAmazonSync?.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'wc_amazon_sync_import_products',
					nonce: wcAmazonSync?.nonce || ''
				},
				timeout: 300000, // 5 minutes timeout (reports can take time)
				success: function(response) {
					if (response.success && response.data) {
						var products = response.data.products || [];
						var count = response.data.count || 0;
						
						if (Array.isArray(products) && products.length > 0) {
							// Populate table and auto-match products by SKU
							populateProductsTable(products);
							$('#amazon-products-list').show();
							
							// Auto-match products by SKU
							autoMatchProductsBySku(products);
							
							alert('Successfully loaded ' + count + ' products from Amazon!');
						} else {
							alert('No products found or products array is empty.');
						}
					} else {
						var errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error';
						alert('Failed to load products: ' + errorMsg);
						console.error('Import products error:', response);
					}
				},
				error: function(xhr, status, error) {
					var errorMsg = 'Request failed';
					
					if (status === 'timeout') {
						errorMsg = 'Request timed out. This is normal for large imports - check logs for progress.';
					} else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMsg = xhr.responseJSON.data.message;
					} else if (xhr.responseText) {
						try {
							var parsed = JSON.parse(xhr.responseText);
							if (parsed.data && parsed.data.message) {
								errorMsg = parsed.data.message;
							}
						} catch(e) {
							errorMsg = error || status || 'Unknown error';
						}
					}
					
					alert('Error: ' + errorMsg);
					console.error('Import products AJAX error:', {
						status: status,
						error: error,
						response: xhr.responseText
					});
				},
				complete: function() {
					$button.prop('disabled', false).text('Load Products from Amazon');
				}
			});
			
			return false;
		});

		// Sync product
		$('.sync-product').on('click', function() {
			var $button = $(this);
			var productId = $button.data('product-id');
			$button.prop('disabled', true).text(wcAmazonSync.i18n.syncing);

			$.ajax({
				url: wcAmazonSync.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wc_amazon_sync_sync_product',
					product_id: productId,
					nonce: wcAmazonSync.nonce
				},
				success: function(response) {
					if (response.success) {
						alert(wcAmazonSync.i18n.syncComplete);
						location.reload();
					} else {
						alert(wcAmazonSync.i18n.syncFailed);
					}
				},
				error: function() {
					alert('Error: Request failed');
				},
				complete: function() {
					$button.prop('disabled', false).text('Sync Now');
				}
			});
		});

		// Delete mapping
		$(document).on('click', '.delete-mapping', function() {
			if (!confirm(wcAmazonSync.i18n?.confirmDelete || 'Are you sure you want to delete this mapping?')) {
				return;
			}

			var $button = $(this);
			var mappingId = $button.data('mapping-id');
			$button.prop('disabled', true);

			$.ajax({
				url: wcAmazonSync?.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'wc_amazon_sync_delete_mapping',
					mapping_id: mappingId,
					nonce: wcAmazonSync?.nonce || ''
				},
				success: function(response) {
					if (response.success) {
						$button.closest('tr').fadeOut(function() {
							$(this).remove();
						});
					} else {
						var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to delete mapping';
						alert(errorMsg);
						$button.prop('disabled', false);
					}
				},
				error: function() {
					alert('Error: Request failed');
					$button.prop('disabled', false);
				}
			});
		});

		// Update price
		$(document).on('click', '.update-price-btn', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var productId = $button.data('product-id');
			var $row = $button.closest('tr');
			var $priceInput = $row.find('.amazon-price-input');
			var price = parseFloat($priceInput.val());

			if (isNaN(price) || price < 0) {
				alert('Please enter a valid price');
				return;
			}

			$button.prop('disabled', true).text('Updating...');

			$.ajax({
				url: wcAmazonSync?.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'wc_amazon_sync_update_price',
					product_id: productId,
					price: price,
					nonce: wcAmazonSync?.nonce || ''
				},
				success: function(response) {
					if (response.success) {
						var successMsg = response.data && response.data.message ? response.data.message : 'Price updated successfully';
						alert(successMsg);
					} else {
						var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to update price';
						alert(errorMsg);
					}
				},
				error: function(xhr) {
					var errorMsg = 'Request failed';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMsg = xhr.responseJSON.data.message;
					}
					alert('Error: ' + errorMsg);
				},
				complete: function() {
					$button.prop('disabled', false).text('Update');
				}
			});
		});

		// Update inventory for single product
		$(document).on('click', '.update-inventory-btn', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var productId = $button.data('product-id');

			$button.prop('disabled', true).text('Updating...');

			$.ajax({
				url: wcAmazonSync?.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'wc_amazon_sync_update_inventory',
					product_id: productId,
					nonce: wcAmazonSync?.nonce || ''
				},
				success: function(response) {
					if (response.success) {
						var successMsg = response.data && response.data.message ? response.data.message : 'Inventory updated successfully';
						alert(successMsg);
						// Optionally reload the page to show updated quantity
						if (response.data && response.data.quantity !== undefined) {
							// Update displayed quantity
							$button.closest('td').find('span, strong').first().text(response.data.quantity);
						}
					} else {
						var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to update inventory';
						alert(errorMsg);
					}
				},
				error: function(xhr) {
					var errorMsg = 'Request failed';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMsg = xhr.responseJSON.data.message;
					}
					alert('Error: ' + errorMsg);
				},
				complete: function() {
					$button.prop('disabled', false).text('Update');
				}
			});
		});

		// Update all inventory
		$('#update-all-inventory').on('click', function(e) {
			e.preventDefault();
			
			if (!confirm('Are you sure you want to update inventory for all mapped products? This may take a while.')) {
				return;
			}

			var $button = $(this);
			$button.prop('disabled', true).text('Updating...');

			$.ajax({
				url: wcAmazonSync?.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'wc_amazon_sync_update_all_inventory',
					nonce: wcAmazonSync?.nonce || ''
				},
				timeout: 300000, // 5 minutes timeout
				success: function(response) {
					if (response.success) {
						var successMsg = response.data && response.data.message ? response.data.message : 'All inventory updated successfully';
						alert(successMsg);
					} else {
						var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to update all inventory';
						alert(errorMsg);
					}
				},
				error: function(xhr, status, error) {
					var errorMsg = 'Request failed';
					if (status === 'timeout') {
						errorMsg = 'Request timed out. Check logs for progress.';
					} else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMsg = xhr.responseJSON.data.message;
					}
					alert('Error: ' + errorMsg);
				},
				complete: function() {
					$button.prop('disabled', false).text('Update All Inventory');
				}
			});
		});

		// Save mapping
		$('#save-mapping').on('click', function(e) {
			e.preventDefault();
			
			var mappings = [];
			$('.wc-product-select').each(function() {
				var $select = $(this);
				var wcProductId = $select.val();
				var asin = $select.data('asin');
				
				// Only include if a WooCommerce product is selected
				if (wcProductId && asin) {
					mappings.push({
						wc_product_id: parseInt(wcProductId),
						amazon_asin: asin,
						mapping_type: 'sku' // Default mapping type
					});
				}
			});
			
			if (mappings.length === 0) {
				alert('Please select at least one WooCommerce product to map.');
				return;
			}
			
			if (!confirm('Save ' + mappings.length + ' product mapping(s)?')) {
				return;
			}
			
			var $button = $(this);
			$button.prop('disabled', true);
			
			// Process in batches of 50 to avoid timeout
			var batchSize = 50;
			var batches = [];
			for (var i = 0; i < mappings.length; i += batchSize) {
				batches.push(mappings.slice(i, i + batchSize));
			}
			
			var totalBatches = batches.length;
			var currentBatch = 0;
			var totalSuccess = 0;
			var totalError = 0;
			var allErrors = [];
			var totalProcessed = 0;
			
			// Show progress overlay
			showSaveProgressOverlay(mappings.length);
			
			console.log('Saving ' + mappings.length + ' mappings in ' + totalBatches + ' batches of ' + batchSize);
			
			function saveBatch(batchIndex) {
				if (batchIndex >= totalBatches) {
					// All batches processed
					hideSaveProgressOverlay();
					var message = wcAmazonSync?.strings?.mappingsSaved || 'Mappings saved: ' + totalSuccess + ' succeeded';
					if (totalError > 0) {
						message += ', ' + totalError + ' failed';
					}
					alert(message);
					location.reload();
					return;
				}
				
				var batch = batches[batchIndex];
				
				// Update progress before sending (showing which batch we're on)
				var processedBefore = totalProcessed;
				var percentBefore = Math.round((processedBefore / mappings.length) * 100);
				updateSaveProgress(percentBefore, processedBefore, mappings.length, totalSuccess, totalError);
				
				console.log('Saving batch ' + (batchIndex + 1) + '/' + totalBatches + ': ' + batch.length + ' items');
				
				$.ajax({
					url: wcAmazonSync?.ajaxUrl || ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'wc_amazon_sync_save_mappings',
						nonce: wcAmazonSync?.nonce || '',
						mappings_json: JSON.stringify(batch)
					},
					timeout: 60000, // 60 seconds per batch
					success: function(response) {
						if (response && response.success) {
							if (response.data && response.data.results) {
								totalSuccess += response.data.results.success || 0;
								totalError += response.data.results.error || 0;
								if (response.data.results.errors && response.data.results.errors.length > 0) {
									allErrors = allErrors.concat(response.data.results.errors);
								}
							} else {
								totalSuccess += batch.length;
							}
						} else {
							var errorMsg = response && response.data && response.data.message ? response.data.message : 'Failed to save batch';
							console.error('Batch ' + (batchIndex + 1) + ' error:', errorMsg);
							totalError += batch.length;
							allErrors.push('Batch ' + (batchIndex + 1) + ': ' + errorMsg);
						}
						
						// Update progress after batch is processed
						totalProcessed += batch.length;
						var percent = Math.round((totalProcessed / mappings.length) * 100);
						updateSaveProgress(percent, totalProcessed, mappings.length, totalSuccess, totalError);
						
						console.log('Batch ' + (batchIndex + 1) + ' completed. Total: ' + totalProcessed + '/' + mappings.length + ' (' + percent + '%)');
						
						// Process next batch
						setTimeout(function() {
							saveBatch(batchIndex + 1);
						}, 100); // Small delay between batches
					},
					error: function(xhr, status, error) {
						console.error('Batch ' + (batchIndex + 1) + ' AJAX error:', status, error);
						totalError += batch.length;
						
						var errorMsg = 'Request failed';
						if (status === 'timeout') {
							errorMsg = 'Request timed out';
						} else if (xhr.status === 503) {
							errorMsg = 'Service unavailable (server overloaded)';
						} else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							errorMsg = xhr.responseJSON.data.message;
						}
						allErrors.push('Batch ' + (batchIndex + 1) + ': ' + errorMsg);
						
						// Update progress after error
						totalProcessed += batch.length;
						var percent = Math.round((totalProcessed / mappings.length) * 100);
						updateSaveProgress(percent, totalProcessed, mappings.length, totalSuccess, totalError);
						
						// Continue with next batch
						setTimeout(function() {
							saveBatch(batchIndex + 1);
						}, 100);
					}
				});
			}
			
			// Start processing batches
			saveBatch(0);
		});

		// Delete all mappings
		$('#delete-all-mappings').on('click', function(e) {
			e.preventDefault();
			
			if (!confirm('Are you sure you want to delete ALL product mappings? This action cannot be undone!')) {
				return;
			}

			if (!confirm('This will delete ALL mappings. Are you absolutely sure?')) {
				return;
			}

			var $button = $(this);
			$button.prop('disabled', true).text('Deleting...');

			$.ajax({
				url: wcAmazonSync?.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'wc_amazon_sync_delete_all_mappings',
					nonce: wcAmazonSync?.nonce || ''
				},
				success: function(response) {
					if (response.success) {
						var successMsg = response.data && response.data.message ? response.data.message : 'All mappings deleted successfully';
						alert(successMsg);
						location.reload();
					} else {
						var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to delete all mappings';
						alert(errorMsg);
						$button.prop('disabled', false).text('Delete All Mappings');
					}
				},
				error: function(xhr) {
					var errorMsg = 'Request failed';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMsg = xhr.responseJSON.data.message;
					}
					alert('Error: ' + errorMsg);
					$button.prop('disabled', false).text('Delete All Mappings');
				}
			});
		});

		function populateProductsTable(products) {
			var tbody = $('#amazon-products-tbody');
			tbody.empty();

			products.forEach(function(product, index) {
				// Format price - can be number or string
				var price = product.price || 0;
				if (typeof price === 'number') {
					price = price.toFixed(2).replace('.', ',');
				} else if (typeof price === 'string') {
					// Already formatted or numeric string
					price = parseFloat(price).toFixed(2).replace('.', ',');
				}
				if (price === '0,00' || price === '0') {
					price = '-';
				}
				
				// Get stock - can be in quantity or stock field
				var stock = product.stock || product.quantity || 0;
				
				// Get title - can be in title or name field
				var title = product.title || product.name || '-';
				
				// Get ASIN - can be in asin or product_id field
				var asin = product.asin || product['product-id'] || product['Produkt-ID'] || '-';
				
				// Get SKU
				var sku = product.sku || '';
				
				var row = '<tr data-product-index="' + index + '" data-amazon-sku="' + (sku || '') + '">' +
					'<td>' + (asin !== '-' ? asin : '-') + '</td>' +
					'<td>' + (title !== '-' ? title : '-') + '</td>' +
					'<td>' + (sku || '-') + '</td>' +
					'<td>' + price + '</td>' +
					'<td>' + stock + '</td>' +
					'<td><select class="wc-product-select" data-asin="' + (asin !== '-' ? asin : '') + '" data-amazon-sku="' + (sku || '') + '" style="width: 100%;"><option value="">' + (wcAmazonSync?.i18n?.selectProduct || 'Select product...') + '</option></select></td>' +
					'<td><button class="button create-product" data-asin="' + (asin !== '-' ? asin : '') + '">' + (wcAmazonSync?.i18n?.createNew || 'Create New') + '</button></td>' +
					'</tr>';
				tbody.append(row);
			});
			
			// Log first product for debugging
			if (products.length > 0) {
				console.log('First product sample:', products[0]);
			}
		}

		// Auto-match products by SKU and populate dropdowns
		function autoMatchProductsBySku(amazonProducts) {
			var total = amazonProducts.length;
			var processed = 0;
			var matched = 0;
			var notMatched = 0;
			
			// Show progress overlay
			showProgressOverlay(total);
			
			// Disable table and buttons
			$('#amazon-products-list').css('opacity', '0.5').css('pointer-events', 'none');
			$('#save-mapping, #import-selected').prop('disabled', true);
			
			// Load all WooCommerce products first
			loadAllWcProducts(function(allWcProducts) {
				console.log('Loaded ' + allWcProducts.length + ' WooCommerce products for matching');
				
				// Process products in batches for better performance
				var batchSize = 20; // Process 20 products in parallel
				var currentBatch = 0;
				var inProgress = 0;
				
				function processBatch() {
					var startIndex = currentBatch * batchSize;
					var endIndex = Math.min(startIndex + batchSize, total);
					
					if (startIndex >= total) {
						// All batches processed
						if (inProgress === 0) {
							hideProgressOverlay();
							$('#amazon-products-list').css('opacity', '1').css('pointer-events', 'auto');
							$('#save-mapping, #import-selected').prop('disabled', false);
							
							console.log('Matching complete: ' + matched + ' matched, ' + notMatched + ' not found');
							var completeText = (wcAmazonSync?.i18n?.matchingComplete || 'Automatic matching complete! Matched: %1$d, Not found: %2$d')
								.replace('%1$d', matched)
								.replace('%2$d', notMatched);
							alert(completeText);
						}
						return;
					}
					
					// Process batch
					for (var i = startIndex; i < endIndex; i++) {
						var amazonProduct = amazonProducts[i];
						var sku = amazonProduct.sku || '';
						var $row = $('tr[data-product-index="' + i + '"]');
						var $select = $row.find('.wc-product-select');
						
						if (!sku) {
							// No SKU - just populate dropdown with all products
							populateDropdown($select, allWcProducts);
							processed++;
							notMatched++;
							continue;
						}
						
						inProgress++;
						
						// Try to find WooCommerce product by SKU
						// Use IIFE to capture loop variables
						(function(currentIndex, currentSku) {
							$.ajax({
								url: wcAmazonSync?.ajaxUrl || ajaxurl,
								type: 'POST',
								data: {
									action: 'wc_amazon_sync_find_product_by_sku',
									sku: currentSku,
									nonce: wcAmazonSync?.nonce || ''
								},
								success: function(response) {
									var $row = $('tr[data-product-index="' + currentIndex + '"]');
									var $select = $row.find('.wc-product-select');
									
									if (response.success && response.data) {
										if (response.data.found && response.data.product) {
											// Product found by SKU - auto-select it
											var wcProduct = response.data.product;
											populateDropdown($select, allWcProducts, wcProduct.id);
											matched++;
											console.log('Auto-matched SKU:', currentSku, 'to product:', wcProduct.name);
										} else {
											// Product not found - populate dropdown with all products for manual selection
											populateDropdown($select, allWcProducts);
											notMatched++;
											console.log('SKU not found:', currentSku);
										}
									} else {
										// Error - populate dropdown anyway
										populateDropdown($select, allWcProducts);
										notMatched++;
									}
									
									processed++;
									var percent = Math.round((processed / total) * 100);
									updateProgress(percent, processed, total, matched, notMatched);
									
									inProgress--;
									
									// If all in batch are done, process next batch
									if (inProgress === 0) {
										currentBatch++;
										processBatch();
									}
								},
								error: function() {
									var $row = $('tr[data-product-index="' + currentIndex + '"]');
									var $select = $row.find('.wc-product-select');
									
									// Error - populate dropdown anyway
									populateDropdown($select, allWcProducts);
									notMatched++;
									processed++;
									var percent = Math.round((processed / total) * 100);
									updateProgress(percent, processed, total, matched, notMatched);
									
									inProgress--;
									
									// If all in batch are done, process next batch
									if (inProgress === 0) {
										currentBatch++;
										processBatch();
									}
								}
							});
						})(i, sku);
					}
					
					// Update progress after starting batch
					var percent = Math.round((processed / total) * 100);
					updateProgress(percent, processed, total, matched, notMatched);
				}
				
				// Start processing first batch
				processBatch();
			});
		}
		
		// Show progress overlay
		function showProgressOverlay(total) {
			$('#amazon-sync-progress-overlay').show();
			updateProgress(0, 0, total, 0, 0);
		}
		
		// Hide progress overlay
		function hideProgressOverlay() {
			$('#amazon-sync-progress-overlay').hide();
		}
		
		// Update progress
		// Progress functions for saving mappings
		function showSaveProgressOverlay(total) {
			$('#amazon-sync-save-progress-overlay').show();
			updateSaveProgress(0, 0, total, 0, 0);
		}

		function hideSaveProgressOverlay() {
			$('#amazon-sync-save-progress-overlay').hide();
		}

		function updateSaveProgress(percent, processed, total, succeeded, failed) {
			percent = Math.min(100, Math.max(0, percent));
			$('#amazon-sync-save-progress-fill').css('width', percent + '%');
			$('#amazon-sync-save-progress-percent').text(percent + '%');
			
			var text = (wcAmazonSync?.strings?.savingMappings || 'Saving mappings: {processed} of {total}').replace('{processed}', processed).replace('{total}', total);
			if (succeeded > 0 || failed > 0) {
				text += ' (' + (wcAmazonSync?.strings?.succeeded || 'Succeeded') + ': ' + succeeded;
				if (failed > 0) {
					text += ', ' + (wcAmazonSync?.strings?.failed || 'Failed') + ': ' + failed;
				}
				text += ')';
			}
			$('#amazon-sync-save-progress-text').text(text);
			$('#amazon-sync-save-progress-details').text(processed + ' / ' + total);
		}

		function updateProgress(percent, processed, total, matched, notMatched) {
			$('#amazon-sync-progress-fill').css('width', percent + '%');
			$('#amazon-sync-progress-percent').text(percent + '%');
			var progressText = (wcAmazonSync?.i18n?.progressText || 'Processed: %1$d / %2$d | Matched: %3$d | Not found: %4$d')
				.replace('%1$d', processed)
				.replace('%2$d', total)
				.replace('%3$d', matched)
				.replace('%4$d', notMatched);
			$('#amazon-sync-progress-text').text(progressText);
		}

		// Load all WooCommerce products
		function loadAllWcProducts(callback) {
			var allProducts = [];
			var page = 1;
			var perPage = 100;
			var hasMore = true;
			
			// Load products in batches
			function loadBatch() {
				if (!hasMore) {
					console.log('Loaded total: ' + allProducts.length + ' WooCommerce products');
					callback(allProducts);
					return;
				}
				
				$.ajax({
					url: wcAmazonSync?.ajaxUrl || ajaxurl,
					type: 'GET',
					data: {
						action: 'wc_amazon_sync_get_wc_products',
						limit: perPage,
						offset: (page - 1) * perPage,
						nonce: wcAmazonSync?.nonce || ''
					},
					success: function(response) {
						if (response.success && response.data && response.data.products) {
							var products = response.data.products;
							allProducts = allProducts.concat(products);
							
							if (products.length < perPage) {
								// Last batch
								hasMore = false;
							} else {
								// Load next batch
								page++;
							}
							
							loadBatch();
						} else {
							console.error('Failed to load WooCommerce products');
							hasMore = false;
							callback(allProducts);
						}
					},
					error: function() {
						console.error('Error loading WooCommerce products');
						hasMore = false;
						callback(allProducts);
					}
				});
			}
			
			loadBatch();
		}

		// Populate dropdown with WooCommerce products
		function populateDropdown($select, wcProducts, selectedId) {
			// Clear existing options except first (Select product...)
			$select.find('option:not(:first)').remove();
			
			// Always add all WooCommerce products to dropdown
			wcProducts.forEach(function(product) {
				var optionText = product.formatted_name || product.name;
				if (product.sku) {
					optionText += ' (SKU: ' + product.sku + ')';
				}
				var $option = $('<option></option>')
					.attr('value', product.id)
					.text(optionText)
					.data('sku', product.sku || '')
					.data('name', product.name || '');
				
				if (selectedId && product.id == selectedId) {
					$option.attr('selected', 'selected');
				}
				
				$select.append($option);
			});
			
			// Initialize SelectWoo (WooCommerce's Select2 fork) for searchable dropdown
			// SelectWoo will search through already loaded options
			var selectOptions = {
				placeholder: wcAmazonSync?.i18n?.selectProduct || 'Select product...',
				allowClear: true,
				width: '100%',
				minimumResultsForSearch: 0, // Always show search box for filtering
				language: {
					noResults: function() {
						return wcAmazonSync?.i18n?.noResults || 'No products found';
					},
					searching: function() {
						return wcAmazonSync?.i18n?.searching || 'Searching...';
					}
				}
			};
			
			if ($.fn.selectWoo) {
				$select.selectWoo(selectOptions);
			} else if ($.fn.select2) {
				// Fallback to Select2 if SelectWoo is not available
				$select.select2(selectOptions);
			}
			
			// If product was pre-selected, trigger change to show it in SelectWoo
			if (selectedId) {
				$select.val(selectedId).trigger('change');
			}
		}
	});
})(jQuery);

