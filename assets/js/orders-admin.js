(function($) {
	'use strict';

	var cfg = typeof wcAmazonSyncOrders !== 'undefined' ? wcAmazonSyncOrders : {};

	function money(amount, currency) {
		var n = parseFloat(amount);
		if (isNaN(n)) {
			return '—';
		}
		return n.toFixed(2) + ' ' + (currency || 'EUR');
	}

	function storefrontLabel(d) {
		if (d.country_code) {
			return d.country_code.toLowerCase();
		}
		var ch = d.sales_channel || '';
		var m = ch.match(/\.([a-z]{2})$/i);
		return m ? m[1].toLowerCase() : (ch || '—');
	}

	function renderAddressBlock(title, name, email, addr) {
		var html = '<h3>' + escHtml(title) + '</h3>';
		html += '<div class="amazon-order-modal__address">';
		if (name) {
			html += '<strong>' + escHtml(name) + '</strong><br>';
		}
		if (email) {
			html += '<a href="mailto:' + escAttr(email) + '">' + escHtml(email) + '</a><br>';
		}
		if (addr && addr.phone) {
			html += escHtml(cfg.i18n_phone || 'Phone') + ': ' + escHtml(addr.phone) + '<br>';
		}
		if (addr && addr.line1) {
			html += escHtml(addr.line1) + '<br>';
		}
		if (addr && addr.line2) {
			html += escHtml(addr.line2) + '<br>';
		}
		var cityLine = '';
		if (addr && addr.postal_code) {
			cityLine = addr.postal_code + ' ';
		}
		if (addr && addr.city) {
			cityLine += addr.city;
		}
		if (cityLine) {
			html += escHtml(cityLine.trim()) + '<br>';
		}
		if (addr && addr.state) {
			html += escHtml(addr.state) + '<br>';
		}
		if (addr && (addr.country_name || addr.country_code)) {
			html += escHtml(addr.country_name || addr.country_code);
		}
		html += '</div>';
		return html;
	}

	function renderTrackingBlock(d) {
		var orderId = d.order_id || '';
		var html = '<div class="amazon-order-modal__tracking" data-order-id="' + escAttr(orderId) + '">';
		html += '<h3>' + escHtml(cfg.i18n_tracking || 'Tracking number') + '</h3>';
		html += '<div class="amazon-order-modal__tracking-row">';
		html += '<input type="text" class="amazon-modal-tracking-input regular-text" value="' + escAttr(d.tracking_number || '') + '" placeholder="' + escAttr(cfg.i18n_tracking || 'Tracking number') + '" />';
		html += '<input type="text" class="amazon-modal-carrier-input" value="' + escAttr(d.shipping_carrier || '') + '" placeholder="' + escAttr(cfg.i18n_carrier || 'Carrier') + '" />';
		html += '<button type="button" class="button button-primary amazon-modal-tracking-save">' + escHtml(cfg.i18n_save_tracking || 'Save tracking') + '</button>';
		html += '<span class="amazon-tracking-status"></span>';
		html += '</div></div>';
		return html;
	}

	function renderOrderDetail(data) {
		var d = data.display || {};
		var fallbackOrder = data.order || {};
		var currency = (d.totals && d.totals.currency) ? d.totals.currency : (d.currency || 'EUR');

		var orderId = d.order_id || fallbackOrder.AmazonOrderId || '';
		var html = '<h2 class="amazon-order-modal__title">' + escHtml((cfg.i18n_order_details || 'Order Details') + ' #' + orderId) + '</h2>';

		html += '<div class="amazon-order-modal__meta">';
		html += '<strong>' + escHtml(cfg.i18n_status || 'Status') + ':</strong> ' + escHtml(d.status || fallbackOrder.OrderStatus || '—') + '<br>';
		html += '<strong>' + escHtml(cfg.i18n_created || 'Created') + ':</strong> ' + escHtml(d.created || fallbackOrder.PurchaseDate || '—') + '<br>';
		html += '<strong>' + escHtml(cfg.i18n_storefront || 'Storefront') + ':</strong> ' + escHtml(storefrontLabel(d)) + '<br>';
		if (d.fulfillment) {
			html += '<strong>' + escHtml(cfg.i18n_fulfillment || 'Fulfillment') + ':</strong> ' + escHtml(d.fulfillment) + '<br>';
		}
		if (d.address_from_report) {
			html += '<span class="amazon-order-modal__badge">' + escHtml(cfg.i18n_from_report || 'Address from uploaded report') + '</span>';
		}
		html += '</div>';

		var buyerName = d.customer_name || '';
		var buyerEmail = d.buyer_email || '';
		var ship = d.shipping_address || {};

		if (buyerName || buyerEmail) {
			html += renderAddressBlock(cfg.i18n_billing || 'Billing Address', buyerName, buyerEmail, null);
		}

		if (ship.line1 || ship.formatted) {
			var shipName = ship.name || buyerName;
			html += renderAddressBlock(cfg.i18n_shipping || 'Shipping Address', shipName, '', ship);
		} else if (!d.pii_available) {
			html += '<p class="amazon-order-modal__notice"><em>' + escHtml(cfg.i18n_pii_missing || 'No shipping address yet.') + '</em></p>';
		}

		html += renderTrackingBlock(d);

		var items = d.line_items || [];
		if (items.length) {
			html += '<h3>' + escHtml(cfg.i18n_items || 'Order Items') + '</h3>';
			html += '<table class="amazon-order-modal__items-table wp-list-table widefat fixed striped">';
			html += '<thead><tr>';
			html += '<th>' + escHtml(cfg.i18n_product || 'Product') + '</th>';
			html += '<th>' + escHtml(cfg.i18n_sku || 'SKU') + '</th>';
			html += '<th>' + escHtml(cfg.i18n_qty || 'Quantity') + '</th>';
			html += '<th>' + escHtml(cfg.i18n_price || 'Price') + '</th>';
			html += '<th>' + escHtml(cfg.i18n_total || 'Total') + '</th>';
			html += '<th>' + escHtml(cfg.i18n_weight || 'Weight') + '</th>';
			html += '<th>' + escHtml(cfg.i18n_status || 'Status') + '</th>';
			html += '</tr></thead><tbody>';

			items.forEach(function(item) {
				var itemCurrency = item.currency || currency;
				var status = (item.status || d.status || '').toString();
				var statusClass = 'status-' + status.toLowerCase().replace(/[^a-z0-9]+/g, '-');
				html += '<tr>';
				html += '<td><strong>' + escHtml(item.title || '—') + '</strong></td>';
				html += '<td>' + escHtml(item.sku || '—') + '</td>';
				html += '<td>' + escHtml(String(item.qty != null ? item.qty : '—')) + '</td>';
				html += '<td>' + escHtml(money(item.price, itemCurrency)) + '</td>';
				html += '<td>' + escHtml(money(item.line_total, itemCurrency)) + '</td>';
				html += '<td>' + escHtml(item.weight_label || '—') + '</td>';
				html += '<td><mark class="order-status ' + escAttr(statusClass) + '">' + escHtml(status || '—') + '</mark></td>';
				html += '</tr>';
			});

			html += '</tbody><tfoot>';
			var totals = d.totals || {};
			var colspan = 4;
			html += '<tr><td colspan="' + colspan + '" class="amazon-order-modal__tfoot-label"><strong>' + escHtml(cfg.i18n_subtotal || 'Subtotal') + ':</strong></td>';
			html += '<td><strong>' + escHtml(money(totals.subtotal, totals.currency || currency)) + '</strong></td><td colspan="2"></td></tr>';

			if (totals.shipping != null && parseFloat(totals.shipping) > 0) {
				html += '<tr><td colspan="' + colspan + '" class="amazon-order-modal__tfoot-label"><strong>' + escHtml(cfg.i18n_shipping_cost || 'Shipping') + ':</strong></td>';
				html += '<td><strong>' + escHtml(money(totals.shipping, totals.currency || currency)) + '</strong></td><td colspan="2"></td></tr>';
			}

			html += '<tr class="amazon-order-modal__tfoot-total"><td colspan="' + colspan + '" class="amazon-order-modal__tfoot-label"><strong>' + escHtml(cfg.i18n_grand_total || 'Total') + ':</strong></td>';
			html += '<td><strong>' + escHtml(money(totals.total, totals.currency || currency)) + '</strong></td><td colspan="2"></td></tr>';
			html += '</tfoot></table>';
		} else {
			html += '<p><em>' + escHtml(cfg.i18n_no_items || 'No order items loaded.') + '</em></p>';
		}

		return html;
	}

	function escHtml(str) {
		return $('<div>').text(str == null ? '' : String(str)).html();
	}

	function escAttr(str) {
		return escHtml(str).replace(/"/g, '&quot;');
	}

	function openModal(html) {
		$('#amazon-order-modal-body').html(html);
		$('#amazon-order-modal').show();
	}

	function closeModal() {
		$('#amazon-order-modal').hide();
		$('#amazon-order-modal-body').empty();
	}

	function saveTracking(orderId, tracking, carrier, $status, $btn) {
		if (!orderId) {
			return;
		}
		tracking = String(tracking || '').trim();
		carrier = String(carrier || '').trim();
		if (!tracking) {
			if ($status && $status.length) {
				$status.text(cfg.i18n_tracking_required || 'Tracking number is required.');
			}
			return;
		}
		if ($btn && $btn.length) {
			$btn.prop('disabled', true);
		}
		if ($status && $status.length) {
			$status.text(cfg.i18n_saving || 'Saving…');
		}
		$.post(cfg.ajax_url, {
			action: 'amazon_save_tracking',
			nonce: cfg.tracking_nonce,
			order_id: orderId,
			tracking_number: tracking,
			carrier: carrier
		}).done(function(res) {
			if ($btn && $btn.length) {
				$btn.prop('disabled', false);
			}
			if (res && res.success) {
				if ($status && $status.length) {
					$status.text((res.data && res.data.message) ? res.data.message : (cfg.i18n_saved || 'Saved.'));
				}
				var $listCell = $('.amazon-tracking-cell[data-order-id="' + orderId + '"]');
				if ($listCell.length) {
					$listCell.find('.amazon-tracking-input').val(tracking);
					$listCell.find('.amazon-carrier-input').val(carrier);
				}
			} else {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Error';
				if ($status && $status.length) {
					$status.text((cfg.i18n_save_fail || 'Save failed: %s').replace('%s', msg));
				}
			}
		}).fail(function(xhr) {
			if ($btn && $btn.length) {
				$btn.prop('disabled', false);
			}
			var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'HTTP error';
			if ($status && $status.length) {
				$status.text((cfg.i18n_save_fail || 'Save failed: %s').replace('%s', msg));
			}
		});
	}

	$('#amazon-orders-sync-btn').on('click', function() {
		var $btn = $(this);
		var $status = $('#amazon-orders-sync-status');
		$btn.prop('disabled', true);
		$status.text(cfg.i18n_syncing || 'Syncing…');
		$.post(cfg.ajax_url, {
			action: 'amazon_sync_orders',
			nonce: cfg.sync_nonce
		}, function(res) {
			$btn.prop('disabled', false);
			if (res && res.success) {
				$status.text((res.data && res.data.message) ? res.data.message : (cfg.i18n_sync_ok || 'OK'));
				setTimeout(function() { location.reload(); }, 800);
			} else {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Error';
				$status.text((cfg.i18n_sync_fail || 'Failed: %s').replace('%s', msg));
			}
		}).fail(function(xhr) {
			$btn.prop('disabled', false);
			var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'HTTP error';
			$status.text((cfg.i18n_sync_fail || 'Failed: %s').replace('%s', msg));
		});
	});

	$('#amazon-orders-report-upload-btn').on('click', function() {
		var $btn = $(this);
		var $status = $('#amazon-orders-report-status');
		var fileInput = document.getElementById('amazon-orders-report-file');
		if (!fileInput || !fileInput.files || !fileInput.files.length) {
			$status.text(cfg.i18n_choose_file || 'Please choose a report file first.');
			return;
		}
		var fd = new FormData();
		fd.append('action', 'amazon_upload_order_report');
		fd.append('nonce', cfg.upload_nonce);
		fd.append('report_file', fileInput.files[0]);

		$btn.prop('disabled', true);
		$status.text(cfg.i18n_uploading || 'Uploading…');

		$.ajax({
			url: cfg.ajax_url,
			method: 'POST',
			data: fd,
			processData: false,
			contentType: false
		}).done(function(res) {
			$btn.prop('disabled', false);
			if (res && res.success) {
				$status.text((res.data && res.data.message) ? res.data.message : (cfg.i18n_upload_ok || 'OK'));
				setTimeout(function() { location.reload(); }, 900);
			} else {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Error';
				$status.text((cfg.i18n_upload_fail || 'Upload failed: %s').replace('%s', msg));
			}
		}).fail(function(xhr) {
			$btn.prop('disabled', false);
			var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'HTTP error';
			$status.text((cfg.i18n_upload_fail || 'Upload failed: %s').replace('%s', msg));
		});
	});

	$(document).on('click', '.amazon-tracking-save-btn', function() {
		var $cell = $(this).closest('.amazon-tracking-cell');
		saveTracking(
			$cell.data('order-id'),
			$cell.find('.amazon-tracking-input').val(),
			$cell.find('.amazon-carrier-input').val(),
			$cell.find('.amazon-tracking-status'),
			$(this)
		);
	});

	$(document).on('click', '.amazon-modal-tracking-save', function() {
		var $wrap = $(this).closest('.amazon-order-modal__tracking');
		saveTracking(
			$wrap.data('order-id'),
			$wrap.find('.amazon-modal-tracking-input').val(),
			$wrap.find('.amazon-modal-carrier-input').val(),
			$wrap.find('.amazon-tracking-status'),
			$(this)
		);
	});

	$(document).on('click', '.amazon-order-view', function(e) {
		e.preventDefault();
		var orderId = $(this).data('order-id');
		if (!orderId) return;
		openModal('<p>' + escHtml(cfg.i18n_loading || 'Loading…') + '</p>');
		$.post(cfg.ajax_url, {
			action: 'amazon_get_order_detail',
			nonce: cfg.detail_nonce,
			order_id: orderId
		}, function(res) {
			if (res && res.success && res.data) {
				openModal(renderOrderDetail(res.data));
			} else {
				openModal('<p class="amazon-order-modal__error">' + escHtml((res && res.data && res.data.message) ? res.data.message : 'Error') + '</p>');
			}
		}).fail(function(xhr) {
			var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Request failed';
			openModal('<p class="amazon-order-modal__error">' + escHtml(msg) + '</p>');
		});
	});

	$(document).on('click', '.amazon-order-modal__close, .amazon-order-modal__backdrop', function() {
		closeModal();
	});
})(jQuery);
