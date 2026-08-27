(function () {
    'use strict';

    var config = window.DigitalogicCurrencyAsync || {};
    var fieldId = 'acf-field_68e12344bb0e4';
    var pollTimer = null;
	var terminalDeadline = 0;
	var updateInProgress = false;

    function statusSurface(field) {
        var surface = document.getElementById('digitalogic-currency-async-status');
        if (surface) {
            return surface;
        }
        surface = document.createElement('div');
        surface.id = 'digitalogic-currency-async-status';
        surface.setAttribute('role', 'status');
        surface.style.cssText = 'margin:12px 0;padding:10px 12px;border-right:4px solid #0168cd;background:#f0f6fc;font-weight:600;';
        surface.textContent = 'آماده';
		(field.closest('.acf-field') || field.parentElement).appendChild(surface);
        return surface;
    }

    function setStatus(surface, message, state) {
        surface.textContent = message || 'در حال بررسی وضعیت…';
        surface.style.borderRightColor = state === 'failed' ? '#b32d2e' : (state === 'confirmed' ? '#008a20' : '#dba617');
    }

	function request(action, values, timeoutMs) {
		var controller = new AbortController();
		var requestTimer = window.setTimeout(function () { controller.abort(); }, timeoutMs || 10000);
        var body = new URLSearchParams(Object.assign({
            action: action,
            nonce: config.nonce || ''
        }, values || {}));
        return fetch(config.ajaxUrl || window.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString(),
			signal: controller.signal
		}).then(function (response) {
			window.clearTimeout(requestTimer);
			return response.json();
		}, function (error) {
			window.clearTimeout(requestTimer);
			throw error;
		});
    }

    function poll(field, surface, button) {
		if (Date.now() >= terminalDeadline) {
			setStatus(surface, 'مهلت بررسی پایان یافت؛ مقدار تأییدشدهٔ قبلی حفظ شده است. صفحه را تازه کنید.', 'failed');
			button.disabled = false;
			updateInProgress = false;
			return;
		}
        request('digitalogic_currency_async_status').then(function (payload) {
            var job = payload && payload.success ? payload.data : null;
            if (!job) {
                throw new Error('وضعیت نرخ از سایت خوانده نشد.');
            }
            setStatus(surface, job.message_fa, job.status);
            if (job.status === 'confirmed') {
                field.value = String(job.confirmed_value);
                field.dataset.confirmedValue = field.value;
                button.disabled = false;
				updateInProgress = false;
                return;
            }
            if (job.status === 'failed') {
                if (job.confirmed_value) {
                    field.value = String(job.confirmed_value);
                }
                button.disabled = false;
				updateInProgress = false;
                return;
            }
            pollTimer = window.setTimeout(function () { poll(field, surface, button); }, 1000);
        }).catch(function (error) {
			if (Date.now() < terminalDeadline) {
				setStatus(surface, 'ارتباط موقتاً قطع شد؛ وضعیت دوباره بررسی می‌شود…', 'running');
				pollTimer = window.setTimeout(function () { poll(field, surface, button); }, 1500);
				return;
			}
			setStatus(surface, error.message || 'مهلت بررسی وضعیت پایان یافت.', 'failed');
			button.disabled = false;
			updateInProgress = false;
        });
    }

    function initialize() {
        var field = document.getElementById(fieldId);
        var button = document.getElementById('publish');
        if (!field || !button || !config.nonce) {
            return;
        }
        var form = field.closest('form');
        var surface = statusSurface(field);
        field.dataset.confirmedValue = field.value;
		function beginAsyncUpdate(event) {
            if (String(field.value).trim() === String(field.dataset.confirmedValue).trim()) {
                return;
            }
            event.preventDefault();
			event.stopImmediatePropagation();
			if (updateInProgress) {
				return;
			}
			updateInProgress = true;
            if (pollTimer) {
                window.clearTimeout(pollTimer);
            }
            button.disabled = true;
			terminalDeadline = Date.now() + 120000;
            setStatus(surface, 'تغییر ثبت شد؛ در حال آغاز بازتولید قیمت‌های سایت…', 'queued');
			request('digitalogic_currency_async_submit', {yuan_price: field.value}, 45000).then(function (payload) {
                if (!payload || !payload.success) {
                    throw new Error(payload && payload.data && payload.data.message_fa ? payload.data.message_fa : 'ثبت درخواست نرخ ممکن نشد.');
                }
                setStatus(surface, payload.data.message_fa, payload.data.status);
                poll(field, surface, button);
            }).catch(function (error) {
                setStatus(surface, error.message, 'failed');
                button.disabled = false;
				updateInProgress = false;
            });
		}

		function beginAsyncUpdateFromKey(event) {
			if (event.key !== 'Enter' && event.key !== ' ') {
				return;
			}
			beginAsyncUpdate(event);
		}

		// ACF's options page handles the publish button through its own click/AJAX
		// path from a document-level handler before a target click is reliable.
		// Intercept the earlier pointer/mouse/key activation, disable the button
		// immediately, and keep click/submit as accessibility fallbacks.
		button.addEventListener('pointerdown', beginAsyncUpdate, true);
		button.addEventListener('mousedown', beginAsyncUpdate, true);
		button.addEventListener('keydown', beginAsyncUpdateFromKey, true);
		button.addEventListener('click', beginAsyncUpdate, true);
		form.addEventListener('submit', beginAsyncUpdate, true);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, {once: true});
	} else {
		initialize();
	}
}());
