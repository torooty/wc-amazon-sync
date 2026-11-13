/**
 * Amazon Sync Log Viewer — same behaviour as Kaufland Logs page
 */
(function($) {
	'use strict';

	// Backend expects: action=amazon_logs_stream, nonce under key "nonce" (check_ajax_referer('amazon_logs_stream', 'nonce'))
	var config = window.wcAmazonSyncLogs || window.AmazonLogs || {};
	config.ajax_url = config.ajax_url || config.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
	config.nonce = config.nonce || '';
	config.poll_interval = config.poll_interval || config.pollInterval || 35000;
	config.i18n = config.i18n || {};
	var i18n = config.i18n;
	var STORAGE_KEY = 'amz-logviewer-state';
	var MAX_ROWS = 500;
	var MSG_COLLAPSE_CHARS = 180;
	var SEARCH_DEBOUNCE_MS = 250;

	var state = {
		levels: { error: true, warn: true, info: true, debug: false },
		autoRefresh: true,
		intervalSec: 35,
		autoScroll: true,
		theme: 'light',
		search: '',
		scope: '',
		pausedByUserScroll: false
	};
	var entries = [];
	var lastId = 0;
	var pollTimer = null;
	var searchDebounce = null;

	function saveState() {
		try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
	}
	function loadState() {
		try {
			var s = localStorage.getItem(STORAGE_KEY);
			if (s) {
				var parsed = JSON.parse(s);
				if (parsed.levels) state.levels = parsed.levels;
				if (parsed.autoRefresh !== undefined) state.autoRefresh = parsed.autoRefresh;
				if (parsed.intervalSec !== undefined) state.intervalSec = parsed.intervalSec;
				if (parsed.autoScroll !== undefined) state.autoScroll = parsed.autoScroll;
				if (parsed.theme) state.theme = parsed.theme;
				if (parsed.scope !== undefined) state.scope = parsed.scope;
			}
		} catch (e) {}
	}
	function getActiveLevels() {
		var arr = [];
		Object.keys(state.levels).forEach(function(k) { if (state.levels[k]) arr.push(k); });
		return arr;
	}
	function escapeHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}
	function escapeAttr(str) {
		if (!str) return '';
		return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}
	function formatTime(timeStr) {
		if (!timeStr) return { date: '—', time: '—' };
		var parts = String(timeStr).trim().split(/[\sT]/);
		return { date: parts[0] || '—', time: (parts[1] || '').substring(0, 8) || '—' };
	}
	function normLevel(l) {
		return (l || 'info').toLowerCase().replace(/^warning$/, 'warn');
	}
	function needsExpand(msg) {
		return msg && (msg.length > MSG_COLLAPSE_CHARS || (msg.match(/\n/g) || []).length >= 2);
	}

	function syncUI() {
		var $root = $('#amz-logviewer');
		if (!$root.length) return;
		$root.find('.amz-logviewer__pill').each(function() {
			var level = $(this).data('level');
			$(this).toggleClass('is-active', !!state.levels[level]);
		});
		$root.find('.amz-logviewer__no-levels').toggle(getActiveLevels().length === 0);
		$root.find('[data-setting="autoRefresh"]').prop('checked', state.autoRefresh);
		$root.find('[data-setting="autoScroll"]').prop('checked', state.autoScroll);
		$root.find('[data-setting="interval"]').val(String(state.intervalSec));
		$root.find('.amz-logviewer__toggle-input').each(function() {
			$(this).closest('.amz-logviewer__toggle-btn').toggleClass('amz-toggle-on', $(this).prop('checked'));
		});
		if ($root.find('#amz-logviewer-scope-filter').length) {
			$root.find('#amz-logviewer-scope-filter').val(state.scope || '');
		}
		$root.removeClass('amz-theme-light amz-theme-dark amz-theme-sepia').addClass('amz-theme-' + state.theme);
		$root.find('.amz-logviewer__theme-btn').removeClass('is-active').filter('[data-theme="' + state.theme + '"]').addClass('is-active');
	}

	function renderRow(entry) {
		var level = normLevel(entry.level);
		var ft = formatTime(entry.time);
		var msg = entry.msg || entry.raw || '';
		var dbId = entry.db_id;
		var long = needsExpand(msg);
		var short = long && msg.length > MSG_COLLAPSE_CHARS ? msg.substring(0, MSG_COLLAPSE_CHARS) + '…' : msg;
		var tr = '<tr class="amz-logviewer__row level-' + escapeHtml(level) + '" data-id="' + escapeHtml(entry.id) + '" data-level="' + escapeHtml(level) + '" data-msg="' + escapeAttr(msg) + '"' + (dbId ? ' data-db-id="' + dbId + '"' : '') + '>';
		tr += '<td class="amz-logviewer__cell amz-logviewer__cell--time"><span class="amz-logviewer__date">' + escapeHtml(ft.date) + '</span><span class="amz-logviewer__clock">' + escapeHtml(ft.time) + '</span></td>';
		tr += '<td class="amz-logviewer__cell amz-logviewer__cell--level"><span class="amz-logviewer__badge">' + escapeHtml(level.toUpperCase()) + '</span></td>';
		tr += '<td class="amz-logviewer__cell amz-logviewer__cell--msg"><span class="amz-logviewer__msgText' + (long ? ' is-collapsed' : '') + '">' + escapeHtml(long ? short : msg) + '</span>';
		if (long) {
			tr += '<div class="amz-logviewer__msgActions"><button type="button" class="amz-logviewer__msgBtn" data-action="expand">' + (i18n.expand || 'Erweitern') + '</button><button type="button" class="amz-logviewer__msgBtn" data-action="copyMsg">' + (i18n.copy || 'Kopieren') + '</button></div>';
		}
		tr += '</td></tr>';
		return tr;
	}

	function applyFilters() {
		var $root = $('#amz-logviewer');
		var $rows = $root.find('.amz-logviewer__row');
		var search = (state.search || '').toLowerCase().trim();
		var levels = getActiveLevels();
		var hasLevel = levels.length > 0;
		// When no level selected: show all. When levels selected: hide rows whose level is not in the list.
		$rows.each(function() {
			var $row = $(this);
			var level = $row.data('level');
			var msg = ($row.data('msg') || '').toLowerCase();
			var hiddenByLevel = hasLevel && levels.indexOf(level) < 0;
			var hiddenBySearch = search !== '' && msg.indexOf(search) < 0;
			$row.toggleClass('amz-hidden', hiddenByLevel || hiddenBySearch);
		});
		$root.find('.amz-logviewer__footerCount').text($rows.filter(':not(.amz-hidden)').length + ' ' + (i18n.entries || 'Einträge'));
	}

	function renderTable() {
		var $root = $('#amz-logviewer');
		var $tbody = $root.find('.amz-logviewer__tbody');
		if (!$tbody.length) return;
		var toShow = entries.slice(0, MAX_ROWS);
		var html = toShow.length ? toShow.map(renderRow).join('') : '<tr class="amz-logviewer__empty"><td colspan="3">' + (i18n.noEntries || 'Noch keine Einträge.') + '</td></tr>';
		$tbody.html(html);
		toShow.forEach(function(e) {
			if (needsExpand(e.msg || e.raw)) {
				$tbody.find('.amz-logviewer__row[data-id="' + escapeHtml(e.id) + '"]').data('fullMsg', e.msg || e.raw || '');
			}
		});
		applyFilters();
	}

	function fetchLogs(append) {
		var $root = $('#amz-logviewer');
		var $loading = $root.find('.amz-logviewer__loading');
		if (!append) $loading.show();
		// POST body: action + nonce (key "nonce") — backend check_ajax_referer('amazon_logs_stream', 'nonce')
		var params = { action: 'amazon_logs_stream', nonce: config.nonce, limit: append ? 100 : 200 };
		var levelParam = getActiveLevels().join(',');
		if (levelParam) params.level = levelParam;
		if (state.scope) params.scope = state.scope;
		if (append && lastId > 0) params.since_id = lastId;
		var dataSent = { action: params.action, nonce: params.nonce ? '(length ' + params.nonce.length + ')' : '(empty)', limit: params.limit, level: params.level, scope: params.scope, since_id: params.since_id };

		$.ajax({
			url: config.ajax_url,
			type: 'POST',
			data: params,
			timeout: 15000,
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		})
			.done(function(res) {
				if (res.success && res.data && res.data.entries) {
					var newEntries = res.data.entries;
					if (res.data.last_id > lastId) lastId = res.data.last_id;
					if (append) {
						var ids = {};
						entries.forEach(function(e) { ids[e.id] = true; });
						newEntries = newEntries.filter(function(e) { return !ids[e.id]; });
					}
					if (append && newEntries.length > 0) {
						newEntries.forEach(function(e) { entries.unshift(e); });
					} else if (!append) {
						// Don't replace with empty: keep server-rendered / initial entries visible
						if (newEntries.length > 0) {
							entries = newEntries;
						}
					}
					entries = entries.slice(0, MAX_ROWS * 2);
					renderTable();
					var now = new Date();
					var timeStr = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');
					$root.find('.amz-logviewer__footerUpdated').text((i18n.updated || 'Aktualisiert') + ': ' + timeStr);
					var $wrap = $root.find('.amz-logviewer__tableWrap');
					if (state.autoScroll && !state.pausedByUserScroll) {
						$wrap.scrollTop(0);
						$root.find('.amz-logviewer__jump').hide();
					} else if (append && newEntries.length > 0) {
						$root.find('.amz-logviewer__jump').show();
					}
				}
			})
			.fail(function(xhr, status, err) {
				console.log('amazon_logs_stream fail', xhr && xhr.status, xhr && xhr.responseText);
				console.log('request data', dataSent);
				if (!append) {
					entries = [];
					renderTable();
					$root.find('.amz-logviewer__footerUpdated').text('Fehler beim Laden');
				}
			})
			.always(function() { $loading.hide(); });
	}

	function startPolling() {
		if (pollTimer) clearInterval(pollTimer);
		pollTimer = null;
		if (state.autoRefresh) {
			pollTimer = setInterval(function() { fetchLogs(true); }, state.intervalSec * 1000);
		}
	}

	function init() {
		var $root = $('#amz-logviewer');
		if (!$root.length) return;
		loadState();
		syncUI();
		startPolling();

		var initial = $root.data('initial');
		if (initial && Array.isArray(initial) && initial.length > 0) {
			entries = initial;
			renderTable();
		} else {
			// Preserve server-rendered rows if data-initial was missing/broken (so fetchLogs doesn't overwrite with empty)
			var $rows = $root.find('.amz-logviewer__tbody .amz-logviewer__row');
			if ($rows.length > 0 && !entries.length) {
				entries = $rows.map(function() {
					var $r = $(this);
					var date = $r.find('.amz-logviewer__date').text();
					var clock = $r.find('.amz-logviewer__clock').text();
					return {
						id: $r.data('id'),
						db_id: $r.data('db-id'),
						level: $r.data('level') || 'info',
						time: (date && clock) ? (date + ' ' + clock) : '',
						msg: $r.data('msg') || ''
					};
				}).get();
			}
			if (!entries.length) {
				fetchLogs(false);
			}
		}

		$root.find('.amz-logviewer__tableWrap').on('scroll', function() {
			state.pausedByUserScroll = $(this).scrollTop() > 50;
		});

		$root.on('click', function(e) {
			var $t = $(e.target).closest('[data-level], [data-theme], [data-action], [data-setting]');
			if (!$t.length) return;
			if ($t.hasClass('amz-logviewer__pill')) {
				var lvl = $t.data('level');
				state.levels[lvl] = !state.levels[lvl];
				saveState();
				syncUI();
				applyFilters();
				lastId = 0;
				fetchLogs(false);
				e.preventDefault();
				return;
			}
			if ($t.hasClass('amz-logviewer__theme-btn')) {
				state.theme = $t.data('theme');
				saveState();
				syncUI();
				e.preventDefault();
				return;
			}
			var action = $t.data('action');
			if (action === 'refresh') {
				lastId = 0;
				fetchLogs(false);
				e.preventDefault();
				return;
			}
			if (action === 'clear') {
				entries = [];
				renderTable();
				lastId = 0;
				$root.find('.amz-logviewer__footerUpdated').text('—');
				e.preventDefault();
				return;
			}
			if (action === 'jumpToLatest') {
				$root.find('.amz-logviewer__tableWrap').scrollTop(0);
				state.pausedByUserScroll = false;
				state.autoScroll = true;
				saveState();
				syncUI();
				$root.find('.amz-logviewer__jump').hide();
				e.preventDefault();
				return;
			}
			if (action === 'copy') {
				var lines = [];
				$root.find('.amz-logviewer__row:not(.amz-hidden)').each(function() {
					var $r = $(this);
					lines.push('[' + $r.find('.amz-logviewer__date').text() + ' ' + $r.find('.amz-logviewer__clock').text() + '] [' + $r.find('.amz-logviewer__badge').text() + '] ' + $r.find('.amz-logviewer__msgText').text());
				});
				var text = lines.join('\n') || '(keine Einträge)';
				var $btn = $root.find('[data-action="copy"]');
				var origText = $btn.text();
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function() {
						$btn.text(i18n.copied || 'Kopiert!');
						setTimeout(function() { $btn.text(origText); }, 1500);
					});
				} else {
					var ta = document.createElement('textarea');
					ta.value = text;
					document.body.appendChild(ta);
					ta.select();
					document.execCommand('copy');
					document.body.removeChild(ta);
					$btn.text(i18n.copied || 'Kopiert!');
					setTimeout(function() { $btn.text(origText); }, 1500);
				}
				e.preventDefault();
				return;
			}
			if (action === 'download') {
				var lines = [];
				$root.find('.amz-logviewer__row:not(.amz-hidden)').each(function() {
					var $r = $(this);
					lines.push('[' + $r.find('.amz-logviewer__date').text() + ' ' + $r.find('.amz-logviewer__clock').text() + '] [' + $r.find('.amz-logviewer__badge').text() + '] ' + $r.find('.amz-logviewer__msgText').text());
				});
				var blob = new Blob([lines.join('\n') || '(keine Einträge)'], { type: 'text/plain;charset=utf-8' });
				var a = document.createElement('a');
				a.href = URL.createObjectURL(blob);
				a.download = 'amazon-sync-logs-' + new Date().toISOString().slice(0, 10) + '.txt';
				a.click();
				URL.revokeObjectURL(a.href);
				e.preventDefault();
				return;
			}
			if (action === 'expand') {
				var $row = $t.closest('.amz-logviewer__row');
				var $text = $row.find('.amz-logviewer__msgText');
				var full = $row.data('fullMsg');
				if ($text.hasClass('is-collapsed') && full) {
					$text.removeClass('is-collapsed').text(full);
					$t.text(i18n.collapse || 'Einklappen');
				} else {
					var short = ($text.text().length > MSG_COLLAPSE_CHARS ? $text.text().substring(0, MSG_COLLAPSE_CHARS) + '…' : $text.text());
					$row.data('fullMsg', $text.text());
					$text.addClass('is-collapsed').text(short);
					$t.text(i18n.expand || 'Erweitern');
				}
				e.stopPropagation();
				return;
			}
			if (action === 'copyMsg') {
				var $row = $t.closest('.amz-logviewer__row');
				var full = $row.data('fullMsg') || $row.find('.amz-logviewer__msgText').text();
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(full);
				} else {
					var ta = document.createElement('textarea');
					ta.value = full;
					document.body.appendChild(ta);
					ta.select();
					document.execCommand('copy');
					document.body.removeChild(ta);
				}
				$t.text(i18n.copied || 'Kopiert!');
				setTimeout(function() { $t.text(i18n.copy || 'Kopieren'); }, 1200);
				e.stopPropagation();
				return;
			}
		});

		$root.on('change', '#amz-logviewer-scope-filter', function() {
			state.scope = $(this).val() || '';
			saveState();
			lastId = 0;
			fetchLogs(false);
		});
		$root.on('change', '[data-setting="autoRefresh"]', function() {
			state.autoRefresh = $(this).prop('checked');
			saveState();
			startPolling();
			$root.find('.amz-logviewer__toggle-input').each(function() {
				$(this).closest('.amz-logviewer__toggle-btn').toggleClass('amz-toggle-on', $(this).prop('checked'));
			});
		});
		$root.on('change', '[data-setting="autoScroll"]', function() {
			state.autoScroll = $(this).prop('checked');
			saveState();
			$root.find('.amz-logviewer__toggle-input').each(function() {
				$(this).closest('.amz-logviewer__toggle-btn').toggleClass('amz-toggle-on', $(this).prop('checked'));
			});
			if (state.autoScroll) {
				state.pausedByUserScroll = false;
				$root.find('.amz-logviewer__tableWrap').scrollTop(0);
				$root.find('.amz-logviewer__jump').hide();
			}
		});
		$root.on('change', '[data-setting="interval"]', function() {
			state.intervalSec = parseInt($(this).val(), 10) || 35;
			saveState();
			startPolling();
		});
		$root.on('input', '.amz-logviewer__search', function() {
			var val = $(this).val();
			if (searchDebounce) clearTimeout(searchDebounce);
			searchDebounce = setTimeout(function() {
				state.search = val;
				applyFilters();
				searchDebounce = null;
			}, SEARCH_DEBOUNCE_MS);
		});

		if (getActiveLevels().length === 0) {
			state.levels = { error: true, warn: true, info: true, debug: false };
			saveState();
			syncUI();
		}
		$root.find('.amz-logviewer__footerCount').text(entries.length + ' ' + (i18n.entries || 'Einträge'));

		$(window).on('beforeunload', function() {
			if (pollTimer) clearInterval(pollTimer);
		});
	}

	$(init);
})(jQuery);
