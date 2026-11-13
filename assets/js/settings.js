(function($) {
	'use strict';

	var cfg = typeof wcAmazonSyncSettings !== 'undefined' ? wcAmazonSyncSettings : { ajax_url: ajaxurl, test_nonce: '', logs_list_nonce: '' };

	function renderLogEntries(entries, $container) {
		$container.empty();
		if (!entries || entries.length === 0) {
			$container.html('<p style="color:#888;">No log entries yet. Click "Test Connection" to see results.</p>');
			return;
		}
		entries.reverse().forEach(function(row) {
			var level = (row.level || 'info').toLowerCase();
			var color = level === 'error' ? '#f66' : (level === 'warn' ? '#fa0' : '#ddd');
			var line = (row.created_at || '') + ' [' + level + '] ' + (row.message || '');
			if (row.context_json) {
				try {
					var ctx = JSON.parse(row.context_json);
					line += ' ' + JSON.stringify(ctx);
				} catch (e) {}
			}
			$container.append($('<div style="margin-bottom:2px; color:' + color + ';">').text(line));
		});
	}

	function showTestResult(message, isError) {
		var $result = $('#amazon-api-test-result');
		$result.css({ borderLeftColor: isError ? '#d63638' : '#00a32a', background: isError ? '#fcf0f1' : '#f0f6fc' }).text(message).show();
	}

	function loadTestLogs() {
		var $container = $('#amazon-api-test-logs-container');
		$.post(cfg.ajax_url, {
			action: 'amazon_logs_list',
			nonce: cfg.logs_list_nonce,
			scope: 'test_connection',
			limit: 30
		}, function(res) {
			if (res && res.success && res.data && res.data.logs) {
				renderLogEntries(res.data.logs, $container);
			} else {
				$container.html('<p style="color:#888;">No logs.</p>');
			}
		}).fail(function() {
			$container.html('<p style="color:#f66;">Failed to load logs.</p>');
		});
	}

	$('#amazon-test-connection-btn').on('click', function() {
		var $btn = $(this);
		var data = { action: 'amazon_test_connection', nonce: cfg.test_nonce };
		$btn.prop('disabled', true);
		$('#amazon-api-test-result').hide();
		$.post(cfg.ajax_url, data, function(res) {
			$btn.prop('disabled', false);
			var msg = (res && res.data && res.data.message) ? res.data.message : (res && res.success) ? (cfg.i18n_test_ok || 'Connection successful.') : 'Connection failed.';
			var isError = !(res && res.success);
			showTestResult(msg, isError);
			if (res && res.data && res.data.last_log_entries && res.data.last_log_entries.length) {
				renderLogEntries(res.data.last_log_entries, $('#amazon-api-test-logs-container'));
			} else {
				loadTestLogs();
			}
		}).fail(function(xhr) {
			$btn.prop('disabled', false);
			var msg = 'Request failed';
			if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) msg = xhr.responseJSON.data.message;
			else if (xhr && xhr.status) msg += ' (' + xhr.status + ')';
			showTestResult(msg, true);
			loadTestLogs();
		});
	});

	$('#amazon-api-test-logs-refresh').on('click', function() {
		loadTestLogs();
	});

	// Load test logs once when on API tab
	if ($('#amazon-api-test-logs-container').length) {
		loadTestLogs();
	}

	// Stock sync: "Jetzt starten" on Synchronization tab
	$('#wc-amazon-sync-stock-now').on('click', function() {
		var $btn = $(this);
		if (!$btn.length || !cfg.stock_sync_nonce) return;
		var $section = $btn.closest('.amazon-sync-section');
		var $progress = $('<div class="wc-amazon-sync-stock-progress" style="margin:10px 0;padding:12px;background:#f0f6fc;border-left:4px solid #2271b1;"><span class="spinner is-active" style="float:none;display:inline-block;vertical-align:middle;margin-right:8px;"></span><span class="wc-amazon-sync-stock-progress-text"></span></div>');
		$progress.find('.wc-amazon-sync-stock-progress-text').text(cfg.i18n_stock_sync_wait || 'Stock is being sent to Amazon. Please wait…');
		$section.find('.wc-amazon-sync-stock-progress').remove();
		$section.find('.sync-controls').after($progress);
		$btn.prop('disabled', true).text(cfg.i18n_stock_sync_loading || '…');
		$.post(cfg.ajax_url, {
			action: 'wc_amazon_sync_manual_stock_sync',
			nonce: cfg.stock_sync_nonce
		}, function(res) {
			$progress.remove();
			$btn.prop('disabled', false).text(cfg.i18n_stock_sync_btn || 'Jetzt starten');
			var msg = (res && res.data && res.data.message) ? res.data.message : (res && res.success) ? (cfg.i18n_stock_sync_ok || 'Stock applied.') : (cfg.i18n_stock_sync_fail || 'Stock sync failed.').replace('%s', (res && res.data && res.data.message) ? res.data.message : '');
			var $notice = $('<div class="notice notice-' + (res && res.success ? 'success' : 'error') + ' is-dismissible"><p></p></div>');
			$notice.find('p').text(msg);
			$section.prepend($notice);
			if (res && res.data && res.data.last_run_formatted) {
				var $block = $section.find('.last-run-info');
				if ($block.length) {
					$block.find('p').eq(0).html('<strong>Letzter Start:</strong> ' + res.data.last_run_formatted + ' (Manual)');
					$block.find('p').eq(1).html('<strong>Status:</strong> <span class="' + (res.data.last_status === 'success' ? 'status-ok' : 'status-error') + '">' + (res.data.last_status === 'success' ? 'OK' : 'ERROR') + '</span>');
					$block.find('p').eq(2).html('<strong>Zusammenfassung:</strong> ' + (res.data.last_message || 'Synced: 0, Failed: 0, Skipped: 0'));
				}
			}
			setTimeout(function() { $notice.fadeOut(function() { $(this).remove(); }); }, 8000);
		}).fail(function(xhr) {
			$progress.remove();
			$btn.prop('disabled', false).text(cfg.i18n_stock_sync_btn || 'Jetzt starten');
			var msg = (cfg.i18n_stock_sync_fail || 'Stock sync failed: %s').replace('%s', (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : (xhr && xhr.status ? 'HTTP ' + xhr.status : ''));
			$section.prepend($('<div class="notice notice-error is-dismissible"><p></p></div>').find('p').text(msg).end());
		});
	});
})(jQuery);
