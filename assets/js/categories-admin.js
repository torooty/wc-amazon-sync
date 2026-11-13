/**
 * Amazon Sync — Categories admin (Kategorie-Zuordnung): save/delete mapping, refresh Amazon categories, searchable dropdown.
 */
(function($) {
	'use strict';

	var cfg = typeof wcAmazonSyncCategoriesAdmin !== 'undefined' ? wcAmazonSyncCategoriesAdmin : { ajax_url: '', nonce: '', placeholder: 'Kategorie suchen oder auswählen…' };

	// Build category list once from localized data (avoids 1000+ options in each select DOM)
	function getAmazonCategoriesData() {
		var raw = (cfg.amazon_categories && typeof cfg.amazon_categories === 'object') ? cfg.amazon_categories : {};
		return Object.keys(raw).map(function(id) { return { id: id, text: raw[id] || id }; });
	}

	function initSearchableSelects() {
		var categoryData = getAmazonCategoriesData();
		$('.wc-amazon-sync-default-category-select').each(function() {
			var $el = $(this);
			if ($el.hasClass('select2-hidden-accessible') || $el.hasClass('selectWoo-hidden-accessible')) return;
			function labelOnly(obj) {
				var t = (obj && (obj.text || obj.id)) || '';
				var idx = t.indexOf(' – ');
				if (idx < 0) idx = t.indexOf(' - ');
				return idx >= 0 ? t.substring(idx + 3).trim() : t;
			}
			$el.select2({
				placeholder: cfg.placeholder || 'Kategorie suchen oder auswählen…',
				allowClear: true,
				width: '100%',
				data: categoryData,
				templateSelection: labelOnly,
				templateResult: labelOnly
			});
		});
	}

	function showNotice(message, isError) {
		var $wrap = $('.wc-amazon-sync-categories');
		var $notice = $wrap.find('.wc-amazon-sync-category-notice');
		$notice.remove();
		var cls = isError ? 'notice-error' : 'notice-success';
		$('<div class="notice ' + cls + ' is-dismissible wc-amazon-sync-category-notice"><p>' + (message || '') + '</p></div>')
			.insertAfter($wrap.find('h1').first());
	}

	// Save mapping on select change
	$(document).on('change', '.wc-amazon-sync-default-category-select', function() {
		var $select = $(this);
		var wooId = $select.data('category-id');
		var amazonId = $select.val() || '';
		$select.prop('disabled', true);
		$.post(cfg.ajax_url, {
			action: 'wc_amazon_sync_categories_save_mapping',
			nonce: cfg.nonce,
			woo_category_id: wooId,
			amazon_category_id: amazonId
		}, function(res) {
			if (res && res.success) {
				showNotice(res.data && res.data.message ? res.data.message : 'Gespeichert.', false);
				if (amazonId) {
					$select.closest('tr').find('.dashicons-dismiss').closest('td').html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> Zugeordnet');
				} else {
					$select.closest('tr').find('.dashicons-yes-alt').closest('td').html('<span class="dashicons dashicons-dismiss" style="color: #999;"></span> Nicht zugeordnet');
				}
			} else {
				showNotice((res && res.data && res.data.message) ? res.data.message : 'Fehler.', true);
			}
		}).fail(function() {
			showNotice('Anfrage fehlgeschlagen.', true);
		}).always(function() {
			$select.prop('disabled', false);
		});
	});

	// Clear mapping button
	$(document).on('click', '.wc-amazon-sync-clear-mapping-btn', function() {
		var $btn = $(this);
		var wooId = $btn.data('category-id');
		if (!wooId) return;
		$btn.prop('disabled', true);
		$.post(cfg.ajax_url, {
			action: 'wc_amazon_sync_categories_delete_mapping',
			nonce: cfg.nonce,
			woo_category_id: wooId
		}, function(res) {
			if (res && res.success) {
				showNotice(res.data && res.data.message ? res.data.message : 'Zuordnung gelöscht.', false);
				var $row = $btn.closest('tr');
				var $sel = $row.find('.wc-amazon-sync-default-category-select');
				$sel.val('').trigger('change');
				$row.find('td').eq(2).html('<span class="dashicons dashicons-dismiss" style="color: #999;"></span> Nicht zugeordnet');
			} else {
				showNotice((res && res.data && res.data.message) ? res.data.message : 'Fehler.', true);
			}
		}).fail(function() {
			showNotice('Anfrage fehlgeschlagen.', true);
		}).always(function() {
			$btn.prop('disabled', false);
		});
	});

	// WooCommerce-Kategorien synchronisieren: reload page so get_terms runs again (new categories appear)
	$(document).on('click', '#wc-amazon-sync-sync-woo-categories', function() {
		window.location.reload();
	});

	// Debug panel toggle
	function toggleDebugPanel(show) {
		var $content = $('#wc-amazon-sync-categories-debug-content');
		var $toggle = $('#wc-amazon-sync-categories-debug-toggle');
		if (show === undefined) show = !$content.is(':visible');
		if (show) {
			$content.slideDown();
			$toggle.attr('aria-expanded', 'true');
			$toggle.find('.dashicons').removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
		} else {
			$content.slideUp();
			$toggle.attr('aria-expanded', 'false');
			$toggle.find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
		}
	}
	$('#wc-amazon-sync-categories-debug-toggle').on('click', function() {
		toggleDebugPanel();
	});
	$('#wc-amazon-sync-show-debug-categories').on('click', function() {
		toggleDebugPanel(true);
	});

	// Refresh Amazon categories button
	$(document).on('click', '#wc-amazon-sync-refresh-amazon-categories', function() {
		var $btn = $(this);
		var source = ($('#wc-amazon-sync-categories-source').val() || 'product_types');
		$btn.prop('disabled', true).text('…');
		$.post(cfg.ajax_url, {
			action: 'wc_amazon_sync_categories_refresh_amazon',
			nonce: cfg.nonce,
			categories_source: source
		}, function(res) {
			if (res && res.success) {
				showNotice(res.data && res.data.message ? res.data.message : 'Aktualisiert.', false);
				if (res.data && res.data.debug) {
					// Update debug panel with latest run
					var d = res.data.debug;
					var rows = [
						'Last run status: ' + (d.last_run_status || '—'),
						'Endpoint called: ' + (d.endpoint_called || '—'),
						'marketplaceId: ' + (d.marketplaceId || '—'),
						'region: ' + (d.region || '—'),
						'sellerId: ' + (d.sellerId || '—'),
						'HTTP status: ' + (d.http_status != null ? d.http_status : '—'),
						'Response size: ' + (d.response_size != null ? d.response_size : '—'),
						'Response top-level keys: ' + (Array.isArray(d.response_top_level_keys) ? d.response_top_level_keys.join(', ') : '—'),
						'Count parsed: ' + (d.count_parsed != null ? d.count_parsed : '—'),
						'If error: ' + (d.error_message || '—')
					];
					var $logs = $('#wc-amazon-sync-categories-debug-logs');
					var logLines = (res.data.log_entries || []).slice().reverse().map(function(l) {
						return '[' + (l.created_at || '') + '] [' + (l.level || '') + '] ' + (l.message || '') + (l.context_json ? ' ' + l.context_json : '');
					}).join('\n');
					if ($logs.length) {
						$logs.text(logLines || 'No log entries.');
					}
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(0).text(d.last_run_status || '—');
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(1).html('<code>' + (d.endpoint_called || '—') + '</code>');
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(2).html('<code>' + (d.marketplaceId || '—') + '</code>');
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(3).html('<code>' + (d.region || '—') + '</code>');
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(4).html('<code>' + (d.sellerId || '—') + '</code>');
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(5).text(d.http_status != null ? d.http_status : '—');
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(6).text(d.response_size != null ? d.response_size : '—');
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(7).html('<code>' + (Array.isArray(d.response_top_level_keys) ? d.response_top_level_keys.join(', ') : '—') + '</code>');
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(8).text(d.count_parsed != null ? d.count_parsed : '—');
					$('#wc-amazon-sync-categories-debug-content table').find('td').eq(9).css('color', '#b32d2e').text(d.error_message || '—');
					$('#wc-amazon-sync-categories-debug-content').show();
					$('#wc-amazon-sync-categories-debug-toggle').attr('aria-expanded', 'true').find('.dashicons').removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
				}
				if (res.data && (res.data.reload === true || (res.data.count !== undefined && res.data.count > 0))) {
					window.location.reload();
				}
			} else {
				showNotice((res && res.data && res.data.message) ? res.data.message : 'Fehler.', true);
				if (res && res.data && res.data.debug) {
					var d = res.data.debug;
					var $content = $('#wc-amazon-sync-categories-debug-content');
					if ($content.length && $content.find('table').length) {
						$content.find('table').find('td').eq(0).text(d.last_run_status || '—');
						$content.find('table').find('td').eq(1).html('<code>' + (d.endpoint_called || '—') + '</code>');
						$content.find('table').find('td').eq(2).html('<code>' + (d.marketplaceId || '—') + '</code>');
						$content.find('table').find('td').eq(3).html('<code>' + (d.region || '—') + '</code>');
						$content.find('table').find('td').eq(4).html('<code>' + (d.sellerId || '—') + '</code>');
						$content.find('table').find('td').eq(5).text(d.http_status != null ? d.http_status : '—');
						$content.find('table').find('td').eq(6).text(d.response_size != null ? d.response_size : '—');
						$content.find('table').find('td').eq(7).html('<code>' + (Array.isArray(d.response_top_level_keys) ? d.response_top_level_keys.join(', ') : '—') + '</code>');
						$content.find('table').find('td').eq(8).text(d.count_parsed != null ? d.count_parsed : '—');
						$content.find('table').find('td').eq(9).css('color', '#b32d2e').text(d.error_message || '—');
						var logLines = (res.data.log_entries || []).slice().reverse().map(function(l) {
							return '[' + (l.created_at || '') + '] [' + (l.level || '') + '] ' + (l.message || '') + (l.context_json ? ' ' + l.context_json : '');
						}).join('\n');
						$('#wc-amazon-sync-categories-debug-logs').text(logLines || 'No log entries.');
					}
					$content.show();
					$('#wc-amazon-sync-categories-debug-toggle').attr('aria-expanded', 'true').find('.dashicons').removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
				}
			}
		}).fail(function() {
			showNotice('Anfrage fehlgeschlagen.', true);
		}).always(function() {
			$btn.prop('disabled', false).text('Amazon-Kategorien aktualisieren');
		});
	});

	// Init searchable dropdowns on load (Select2/SelectWoo)
	$(function() {
		initSearchableSelects();
	});

	// Search: on Enter or button submit form
	$(document).on('keypress', '#wc-amazon-sync-category-search', function(e) {
		if (e.which === 13) {
			var q = $(this).val();
			var url = $('.wc-amazon-sync-categories .nav-tab-active').attr('href') || window.location.href;
			if (q) url += (url.indexOf('?') >= 0 ? '&' : '?') + 'search=' + encodeURIComponent(q) + '&filter=all';
			window.location.href = url;
		}
	});
})(jQuery);
