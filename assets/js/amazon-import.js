(function($) {
	'use strict';

	var $btn = $('#amazon-import-btn');
	var $spinner = $('#amazon-import-spinner');
	var $msg = $('#amazon-import-result-msg');
	var $logsContainer = $('#amazon-logs-container');
	var $logLevel = $('#amazon-log-level-filter');
	var $logScope = $('#amazon-log-scope-filter');

	function setBusy(busy) {
		$btn.prop('disabled', busy);
		$spinner.css('visibility', busy ? 'visible' : 'hidden');
		if (!busy) $msg.removeClass('notice-success notice-error');
	}

	function showMessage(text, isError) {
		$msg.removeClass('notice-success notice-error').addClass(isError ? 'notice-error' : 'notice-success').text(text).show();
	}

	function renderLogs(entries) {
		if (!entries || !entries.length) {
			$logsContainer.html('<p style="color:#888;">No log entries.</p>');
			return;
		}
		var html = '';
		entries.reverse().forEach(function(row) {
			var level = (row.level || 'info').toLowerCase();
			var color = level === 'error' ? '#f66' : (level === 'warn' ? '#fa0' : '#ddd');
			var scope = (row.scope || '').toLowerCase();
			var scopeLabel = scope ? ' [' + scope + '] ' : ' ';
			var line = row.created_at + ' [' + level + ']' + scopeLabel + (row.message || '');
			if (row.context_json) {
				try {
					var ctx = JSON.parse(row.context_json);
					line += ' ' + JSON.stringify(ctx);
				} catch (e) {}
				}
			html += '<div style="margin-bottom:2px; color:' + color + ';">' + escapeHtml(line) + '</div>';
		});
		$logsContainer.html(html);
	}

	function escapeHtml(s) {
		var div = document.createElement('div');
		div.textContent = s;
		return div.innerHTML;
	}

	function loadLogs() {
		var level = ($logLevel && $logLevel.length) ? $logLevel.val() || '' : '';
		var scope = ($logScope && $logScope.length) ? $logScope.val() || '' : '';
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_logs_list',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.logs_list_nonce : '',
				level: level,
				scope: scope,
				limit: 100
			},
			function(res) {
				if (res && res.success && res.data && res.data.logs) {
					renderLogs(res.data.logs);
				}
			}
		);
	}

	// Clear list (then run fresh import)
	$('#amazon-clear-list-btn').on('click', function() {
		var $clearBtn = $(this);
		var i18nConfirm = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_clear_confirm) ? wcAmazonSyncImport.i18n_clear_confirm : 'Delete all imported products for this marketplace? You can run a fresh import after.';
		if (!confirm(i18nConfirm)) return;
		$clearBtn.prop('disabled', true);
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_clear_imported_products',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.clear_list_nonce : ''
			},
			function(res) {
				if (res && res.success && res.data) {
					showMessage(res.data.message || 'List cleared.', false);
					window.location.reload();
				} else {
					showMessage((res && res.data && res.data.message) ? res.data.message : 'Failed to clear list.', true);
				}
			}
		).fail(function(xhr, status, err) {
			showMessage('Request failed: ' + (err || status), true);
		}).always(function() {
			$clearBtn.prop('disabled', false);
		});
	});

	// Refresh statuses from Amazon (no full re-import)
	$('#amazon-refresh-statuses-btn').on('click', function() {
		var $refreshBtn = $(this);
		if ($refreshBtn.prop('disabled')) return;
		$refreshBtn.prop('disabled', true);
		$spinner.css('visibility', 'visible');
		$msg.hide();
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_refresh_statuses',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.refresh_statuses_nonce : ''
			},
			function(res) {
				$refreshBtn.prop('disabled', false);
				$spinner.css('visibility', 'hidden');
				if (res && res.success && res.data) {
					showMessage(res.data.message || 'Done.', false);
					window.location.reload();
				} else {
					showMessage((res && res.data && res.data.message) ? res.data.message : 'Failed.', true);
				}
			}
		).fail(function(xhr, status, err) {
			$refreshBtn.prop('disabled', false);
			$spinner.css('visibility', 'hidden');
			showMessage('Request failed: ' + (err || status), true);
		});
	});

	// Products page: single-row "Update" triggers full status refresh
	$(document).on('click', '.wc-amazon-sync-refresh-one-status-btn', function() {
		var $main = $('#amazon-refresh-statuses-btn');
		if ($main.length) $main.trigger('click');
	});

	// Verbindung prüfen: open product on Amazon and trigger status refresh
	$(document).on('click', '.wc-amazon-sync-check-link-btn', function() {
		var url = $(this).data('amazon-url');
		if (url) {
			window.open(url, '_blank', 'noopener');
		}
		var $main = $('#amazon-refresh-statuses-btn');
		if ($main.length) $main.trigger('click');
	});

	// Unlink by SKU (single product)
	$('#amazon-unlink-by-sku-btn').on('click', function() {
		var sku = ($('#amazon-unlink-sku-input').val() || '').trim();
		var $msg = $('#amazon-unlink-sku-msg');
		if (!sku) {
			$msg.css('color', '#b32d2e').text(typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_enter_sku ? wcAmazonSyncImport.i18n_enter_sku : 'Bitte SKU eingeben.');
			return;
		}
		var $btn = $(this);
		$btn.prop('disabled', true);
		$msg.text('');
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_unlink_by_sku',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.unlink_by_sku_nonce : '',
				seller_sku: sku
			},
			function(res) {
				$btn.prop('disabled', false);
				if (res && res.success && res.data && res.data.message) {
					$msg.css('color', '#00a32a').text(res.data.message);
					$('#amazon-unlink-sku-input').val('');
					window.location.reload();
				} else {
					$msg.css('color', '#b32d2e').text((res && res.data && res.data.message) ? res.data.message : 'Fehler.');
				}
			}
		).fail(function(xhr, status, err) {
			$btn.prop('disabled', false);
			$msg.css('color', '#b32d2e').text('Request failed: ' + (err || status));
		});
	});

	// Clean broken links (unlink where Woo product no longer exists)
	$('#amazon-clean-broken-links-btn').on('click', function() {
		var $cleanBtn = $(this);
		if ($cleanBtn.prop('disabled')) return;
		$cleanBtn.prop('disabled', true);
		$spinner.css('visibility', 'visible');
		$msg.hide();
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_clean_broken_links',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.clean_broken_links_nonce : ''
			},
			function(res) {
				$cleanBtn.prop('disabled', false);
				$spinner.css('visibility', 'hidden');
				if (res && res.success && res.data) {
					showMessage(res.data.message || 'Done.', false);
					window.location.reload();
				} else {
					showMessage((res && res.data && res.data.message) ? res.data.message : 'Failed.', true);
				}
			}
		).fail(function(xhr, status, err) {
			$cleanBtn.prop('disabled', false);
			$spinner.css('visibility', 'hidden');
			showMessage('Request failed: ' + (err || status), true);
		});
	});

	// Import from Amazon
	$btn.on('click', function() {
		if ($btn.prop('disabled')) return;
		setBusy(true);
		$msg.hide();
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_import_products',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.import_nonce : ''
			},
			function(res) {
				setBusy(false);
				var i18nDone = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_import_done) ? wcAmazonSyncImport.i18n_import_done : 'Import done. New: %d, Skipped: %d';
				var i18nError = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_import_error) ? wcAmazonSyncImport.i18n_import_error : 'Import failed: %s';
				if (res && res.success && res.data) {
					var doneMsg = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_import_done) ? wcAmazonSyncImport.i18n_import_done : 'Import done. New: %d, Updated: %d, Skipped: %d';
					doneMsg = doneMsg.replace('%d', res.data.new_count || 0).replace('%d', res.data.updated_count || 0).replace('%d', res.data.skipped_count || 0);
					showMessage(doneMsg, false);
					if (res.data.last_log_entries && res.data.last_log_entries.length) {
						renderLogs(res.data.last_log_entries);
					} else {
						loadLogs();
					}
					// Reload page so "Saved imported products" table shows new data
					window.location.reload();
				} else {
					var err = (res && res.data && res.data.message) ? res.data.message : (res && res.message) ? res.message : 'Unknown error';
					showMessage(i18nError.replace('%s', err), true);
					if (res && res.data && res.data.last_log_entries) {
						renderLogs(res.data.last_log_entries);
					} else {
						loadLogs();
					}
				}
			}
		).fail(function(xhr, status, err) {
			setBusy(false);
			showMessage('Request failed: ' + (err || status), true);
			loadLogs();
		});
	});

	// Logs: Refresh
	$('#amazon-logs-refresh').on('click', function() {
		loadLogs();
	});

	// Logs: Copy
	$('#amazon-logs-copy').on('click', function() {
		var text = $logsContainer.text();
		if (!text) return;
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text);
		} else {
			var ta = document.createElement('textarea');
			ta.value = text;
			document.body.appendChild(ta);
			ta.select();
			document.execCommand('copy');
			document.body.removeChild(ta);
		}
	});

	// Logs: Clear
	$('#amazon-logs-clear').on('click', function() {
		if (!confirm('Clear all import logs?')) return;
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_logs_clear',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.logs_clear_nonce : '',
				scope: 'import'
			},
			function(res) {
				if (res && res.success) {
					$logsContainer.html('<p style="color:#888;">Logs cleared.</p>');
				}
			}
		);
	});

	$logLevel.on('change', loadLogs);
	if ($logScope && $logScope.length) $logScope.on('change', loadLogs);

	// Initial load of logs
	loadLogs();

	// Toggle sync (Aktivieren / Deaktivieren) — intent from button data-intent
	$(document).on('click', '.wc-amazon-sync-toggle-status-btn', function() {
		var $btn = $(this);
		var rowId = $btn.data('amazon-row-id');
		var intent = $btn.data('intent') || 'deactivate';
		if (!rowId) return;
		$btn.prop('disabled', true);
		$msg.removeClass('notice-success notice-error').hide();
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_toggle_sync_disabled',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.toggle_status_nonce : '',
				amazon_row_id: rowId,
				intent: intent
			},
			function(res) {
				if (res && res.success && res.data) {
					showMessage(res.data.message || (res.data.sync_disabled ? 'Deaktiviert.' : 'Aktiviert.'), false);
					window.location.reload();
				} else {
					showMessage((res && res.data && res.data.message) ? res.data.message : 'Fehler.', true);
				}
			}
		).fail(function(xhr, status, err) {
			showMessage('Request failed: ' + (err || status), true);
		}).always(function() {
			$btn.prop('disabled', false);
		});
	});

	// Unlink WC product
	$(document).on('click', '.wc-amazon-sync-unlink-btn', function() {
		var $btn = $(this);
		var rowId = $btn.data('amazon-row-id');
		if (!rowId || !confirm('Verknüpfung wirklich aufheben?')) return;
		$btn.prop('disabled', true);
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_unlink_wc_product',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.unlink_wc_nonce : '',
				amazon_row_id: rowId
			},
			function(res) {
				if (res && res.success) {
					window.location.reload();
				}
			}
		).always(function() {
			$btn.prop('disabled', false);
		});
	});

	// Live search WC products on input (debounced)
	var searchDebounce = {};
	$(document).on('input', '.wc-amazon-sync-search-input', function() {
		var $input = $(this);
		var rowId = $input.data('amazon-row-id');
		var $results = $('.wc-amazon-sync-search-results[data-amazon-row-id="' + rowId + '"]');
		var q = ($input.val() || '').trim();
		if (searchDebounce[rowId]) {
			clearTimeout(searchDebounce[rowId]);
		}
		if (q.length < 2) {
			$results.html('<span style="color:#888;">Mind. 2 Zeichen eingeben.</span>');
			return;
		}
		$results.html('<span style="color:#888;">Suche…</span>');
		searchDebounce[rowId] = setTimeout(function() {
			$.post(
				typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
				{
					action: 'amazon_search_wc_products',
					nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.search_wc_nonce : '',
					q: q
				},
				function(res) {
					if (!res || !res.success || !res.data || !res.data.products) {
						$results.html('<span style="color:#888;">Keine Produkte gefunden.</span>');
						return;
					}
					var products = res.data.products;
					if (products.length === 0) {
						$results.html('<span style="color:#888;">Keine Produkte gefunden.</span>');
						return;
					}
					var html = '';
					products.forEach(function(p) {
						var method = (q === p.sku || (p.sku && p.sku.indexOf(q) !== -1)) ? 'sku' : ((p.ean && p.ean.indexOf(q) !== -1) ? 'ean' : 'title');
						html += '<a href="#" class="wc-amazon-sync-link-choice" data-amazon-row-id="' + rowId + '" data-wc-product-id="' + p.id + '" data-link-method="' + method + '">#' + p.id + ' ' + escapeHtml(p.title) + ' (SKU: ' + escapeHtml(p.sku) + ')</a>';
					});
					$results.html(html);
				}
			);
		}, 350);
	});

	// Link chosen WC product
	$(document).on('click', '.wc-amazon-sync-link-choice', function(e) {
		e.preventDefault();
		var rowId = $(this).data('amazon-row-id');
		var wcProductId = $(this).data('wc-product-id');
		var linkMethod = $(this).data('link-method') || 'manual';
		var $row = $(this).closest('tr');
		var $cell = $row.find('.wc-amazon-sync-woo-status-cell');
		$cell.html('<span style="color:#888;">Verknüpfe…</span>');
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_link_wc_product',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.link_wc_nonce : '',
				amazon_row_id: rowId,
				wc_product_id: wcProductId,
				link_method: linkMethod
			},
			function(res) {
				if (res && res.success && res.data) {
					var i18nWc = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_wc_product) ? wcAmazonSyncImport.i18n_wc_product : 'WC-Produkt #%d';
					var i18nMethod = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_link_method) ? wcAmazonSyncImport.i18n_link_method : 'Methode: %s';
					var i18nUnlink = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_unlink) ? wcAmazonSyncImport.i18n_unlink : 'Verknüpfung aufheben';
					var html = '<a href="' + (res.data.edit_url || ('post.php?post=' + wcProductId + '&action=edit')) + '" target="_blank" rel="noopener" class="wc-amazon-sync-wc-product-link">' + i18nWc.replace('%d', wcProductId) + '</a>';
					html += ' <span class="dashicons dashicons-external" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>';
					html += '<br><small>' + i18nMethod.replace('%s', linkMethod) + '</small>';
					html += '<br><button type="button" class="button button-small wc-amazon-sync-unlink-btn" data-amazon-row-id="' + rowId + '">' + i18nUnlink + '</button>';
					$cell.html(html);
				}
			}
		);
	});

	// Export to Amazon: open preview modal (Products page)
	var $exportModal = $('#wc-amazon-sync-export-modal');
	var $exportLoading = $('#wc-amazon-sync-export-modal-loading');
	var $exportMain = $('#wc-amazon-sync-export-modal-main');
	var $exportErrors = $('#wc-amazon-sync-export-errors');
	var $exportWarnings = $('#wc-amazon-sync-export-warnings');
	var $exportPreviewData = $('#wc-amazon-sync-export-preview-data');
	var $exportPayloadJson = $('#wc-amazon-sync-export-payload-json');
	var $exportSendBtn = $('#wc-amazon-sync-export-send-btn');
	var exportCurrentProductId = null;

	function openExportModal(wcProductId) {
		exportCurrentProductId = wcProductId;
		$exportModal.show().attr('aria-hidden', 'false');
		$exportLoading.show();
		$exportMain.hide();
		$exportErrors.hide().empty();
		$exportWarnings.hide().empty();
		$exportPreviewData.empty();
		$exportPayloadJson.hide().empty();
		$exportSendBtn.hide();
		var i18nLoading = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_export_loading) ? wcAmazonSyncImport.i18n_export_loading : 'Loading…';
		$exportLoading.text(i18nLoading);
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_export_preview',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.export_preview_nonce : '',
				wc_product_id: wcProductId
			},
			function(res) {
				$exportLoading.hide();
				$exportMain.show();
				if (!res || !res.success || !res.data) {
					$exportErrors.show().html('<ul><li>' + (res && res.data && res.data.message ? escapeHtml(res.data.message) : 'Request failed.') + '</li></ul>');
					return;
				}
				var d = res.data;
				var i18nWarnings = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_export_warnings) ? wcAmazonSyncImport.i18n_export_warnings : 'Warnings';
				var i18nErrors = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_export_errors) ? wcAmazonSyncImport.i18n_export_errors : 'Errors';
				var i18nDataSent = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_export_data_sent) ? wcAmazonSyncImport.i18n_export_data_sent : 'Data to be sent to Amazon';
				if (d.errors && d.errors.length) {
					var errHtml = '<strong>' + i18nErrors + ':</strong><ul>';
					d.errors.forEach(function(msg) { errHtml += '<li>' + escapeHtml(msg) + '</li>'; });
					errHtml += '</ul>';
					$exportErrors.show().html(errHtml);
				}
				if (d.warnings && d.warnings.length) {
					var warnHtml = '<strong>' + i18nWarnings + ':</strong><ul>';
					d.warnings.forEach(function(msg) { warnHtml += '<li>' + escapeHtml(msg) + '</li>'; });
					warnHtml += '</ul>';
					$exportWarnings.show().html(warnHtml);
				}
				if (d.preview) {
					var p = d.preview;
					var tbl = '<table><tbody>';
					tbl += '<tr><th>SKU</th><td>' + escapeHtml(p.sku || '—') + '</td></tr>';
					tbl += '<tr><th>Title</th><td>' + escapeHtml(p.title || '—') + '</td></tr>';
					tbl += '<tr><th>Price</th><td>' + escapeHtml(p.price_formatted || (p.price + ' ' + (p.currency || ''))) + '</td></tr>';
					tbl += '<tr><th>Quantity</th><td>' + (p.quantity !== undefined ? p.quantity : '—') + '</td></tr>';
					tbl += '<tr><th>EAN</th><td>' + escapeHtml(p.ean || '—') + '</td></tr>';
					tbl += '<tr><th>Woo Category</th><td>' + escapeHtml(p.woo_category || '—') + '</td></tr>';
					tbl += '<tr><th>Amazon Category ID</th><td>' + escapeHtml(p.amazon_category_id || '—') + '</td></tr>';
					if (p.merchant_shipping_group) tbl += '<tr><th>Shipping template</th><td>' + escapeHtml(p.merchant_shipping_group) + '</td></tr>';
					if (p.product_type) tbl += '<tr><th>Product type</th><td>' + escapeHtml(p.product_type) + '</td></tr>';
					tbl += '</tbody></table>';
					var html = '<strong>' + i18nDataSent + '</strong>' + tbl;
					if (p.schema_error) {
						html += '<p class="wc-amazon-sync-export-schema-warn" style="margin-top:10px;color:#856404;background:#fff3cd;padding:8px;border-radius:4px;">' + escapeHtml(p.schema_error) + '</p>';
					}
					if (p.required_attributes && p.required_attributes.length) {
						html += '<p style="margin-top:14px;"><strong>Required by Amazon (product type ' + escapeHtml(p.product_type || '') + ')</strong></p>';
						html += '<table class="wc-amazon-sync-export-attr-table"><thead><tr><th>Attribute</th><th>Value</th><th>Status</th></tr></thead><tbody>';
						p.required_attributes.forEach(function(r) {
							var statusClass = r.status === 'filled' ? 'wc-amazon-sync-attr-filled' : 'wc-amazon-sync-attr-missing';
							html += '<tr><td>' + escapeHtml(r.label || r.name) + '</td><td>' + escapeHtml(r.value) + '</td><td><span class="' + statusClass + '">' + (r.status === 'filled' ? '✓' : '—') + '</span></td></tr>';
						});
						html += '</tbody></table>';
					}
					if (p.optional_attributes && p.optional_attributes.length) {
						html += '<p style="margin-top:14px;"><strong>Optional attributes</strong></p>';
						html += '<table class="wc-amazon-sync-export-attr-table"><thead><tr><th>Attribute</th><th>Value</th><th>Status</th></tr></thead><tbody>';
						p.optional_attributes.forEach(function(r) {
							var statusClass = r.status === 'filled' ? 'wc-amazon-sync-attr-filled' : 'wc-amazon-sync-attr-missing';
							html += '<tr><td>' + escapeHtml(r.label || r.name) + '</td><td>' + escapeHtml(r.value) + '</td><td><span class="' + statusClass + '">' + (r.status === 'filled' ? '✓' : '—') + '</span></td></tr>';
						});
						html += '</tbody></table>';
					}
					$exportPreviewData.html(html);
				}
				if (d.payload_json) {
					$exportPayloadJson.show().text(d.payload_json);
				}
				if (d.success && d.payload && d.sku) {
					$exportSendBtn.show();
				}
			}
		).fail(function(xhr, status, err) {
			$exportLoading.hide();
			$exportMain.show();
			$exportErrors.show().html('<ul><li>Request failed: ' + escapeHtml(err || status) + '</li></ul>');
		});
	}

	function closeExportModal() {
		$exportModal.hide().attr('aria-hidden', 'true');
		exportCurrentProductId = null;
	}

	$exportModal.find('.wc-amazon-sync-export-modal-backdrop, .wc-amazon-sync-export-modal-close, #wc-amazon-sync-export-cancel-btn').on('click', function() {
		closeExportModal();
	});

	$(document).on('click', '.wc-amazon-sync-export-preview-btn', function() {
		var wcProductId = $(this).data('wc-product-id');
		if (wcProductId && $exportModal.length) {
			openExportModal(wcProductId);
		}
	});

	$exportSendBtn.on('click', function() {
		if (!exportCurrentProductId || $exportSendBtn.prop('disabled')) return;
		var i18nSending = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_export_sending) ? wcAmazonSyncImport.i18n_export_sending : 'Sending…';
		$exportSendBtn.prop('disabled', true).text(i18nSending);
		$.post(
			typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.ajax_url : ajaxurl,
			{
				action: 'amazon_export_submit',
				nonce: typeof wcAmazonSyncImport !== 'undefined' ? wcAmazonSyncImport.export_submit_nonce : '',
				wc_product_id: exportCurrentProductId
			},
			function(res) {
				var i18nSend = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_export_send) ? wcAmazonSyncImport.i18n_export_send : 'Send';
				$exportSendBtn.prop('disabled', false).text(i18nSend);
				if (res && res.success && res.data && res.data.message) {
					closeExportModal();
					alert(res.data.message);
					window.location.reload();
				} else {
					alert((res && res.data && res.data.message) ? res.data.message : 'Export failed.');
				}
			}
		).fail(function(xhr, status, err) {
			var i18nSend = (typeof wcAmazonSyncImport !== 'undefined' && wcAmazonSyncImport.i18n_export_send) ? wcAmazonSyncImport.i18n_export_send : 'Send';
			$exportSendBtn.prop('disabled', false).text(i18nSend);
			alert('Request failed: ' + (err || status));
		});
	});
})(jQuery);
