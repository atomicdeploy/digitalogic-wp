(function (window, document) {
	'use strict';

	var config = window.DigitalogicCurrencyAsync || {};
	var activeJob = null;
	var startedAt = 0;
	var statusNode = null;

	function semanticWrappers() {
		var allowed = Array.isArray(config.fieldNames) ? config.fieldNames : ['dollar_price', 'yuan_price'];
		return Array.prototype.filter.call(
			document.querySelectorAll('.acf-field[data-name]'),
			function (wrapper) {
				var name = wrapper.getAttribute('data-name');
				return allowed.indexOf(name) !== -1 && !wrapper.classList.contains('acf-clone');
			}
		);
	}

	function locateStatusNode() {
		var existing = document.getElementById('digitalogic-currency-async-status');
		if (existing) {
			return existing;
		}
		var wrappers = semanticWrappers();
		if (!wrappers.length) {
			return null;
		}
		var node = document.createElement('div');
		node.id = 'digitalogic-currency-async-status';
		node.className = 'notice notice-info inline';
		node.setAttribute('role', 'status');
		node.setAttribute('aria-live', 'polite');
		node.hidden = true;
		wrappers[0].appendChild(node);
		return node;
	}

	function render(job, fallback) {
		if (!statusNode) {
			return;
		}
		var status = job && job.status ? String(job.status) : 'idle';
		var message = job && job.message_fa ? String(job.message_fa) : String(fallback || '');
		statusNode.dataset.status = status;
		statusNode.textContent = message;
		statusNode.hidden = status === 'idle' && !message;
	}

	async function request(parameters) {
		var controller = new AbortController();
		var timeout = window.setTimeout(function () {
			controller.abort();
		}, Number(config.requestTimeoutMs) || 15000);
		try {
			var body = new URLSearchParams(parameters);
			body.set('nonce', String(config.nonce || ''));
			var response = await window.fetch(String(config.ajaxUrl || ''), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: body.toString(),
				signal: controller.signal
			});
			var payload = await response.json();
			if (!payload || payload.success !== true || !payload.data) {
				var failure = payload && payload.data ? payload.data : {};
				throw new Error(String(failure.message_fa || 'دریافت وضعیت کار قیمت ممکن نشد.'));
			}
			return payload.data;
		} finally {
			window.clearTimeout(timeout);
		}
	}

	function terminal(job) {
		return ['confirmed', 'failed', 'publication_failed', 'superseded', 'invalid_identity'].indexOf(String(job.status || '')) !== -1;
	}

	function deadlineReached(job) {
		var localDeadline = startedAt + (Number(config.terminalTimeoutMs) || 180000);
		if (String(job.status || '') === 'publishing') {
			return Date.now() >= localDeadline;
		}
		var serverDeadline = Number(job.deadline_at || 0) * 1000;
		var deadline = serverDeadline > 0 ? Math.min(localDeadline, serverDeadline + 2000) : localDeadline;
		return Date.now() >= deadline;
	}

	function schedulePoll(delay) {
		window.setTimeout(pollExactJob, delay);
	}

	async function pollExactJob() {
		if (!activeJob) {
			return;
		}
		try {
			var job = await request({
				action: 'digitalogic_currency_async_status',
				job_id: activeJob.job_id,
				generation: String(activeJob.generation)
			});
			render(job);
			if (terminal(job)) {
				activeJob = null;
				return;
			}
			if (deadlineReached(job)) {
				render({status: 'failed', message_fa: 'مهلت نمایش وضعیت پایان یافت؛ صفحه آزاد است و می‌توانید وضعیت را تازه‌سازی کنید.'});
				activeJob = null;
				return;
			}
			schedulePoll(Number(config.pollIntervalMs) || 1000);
		} catch (error) {
			if (activeJob && !deadlineReached(activeJob)) {
				render(activeJob, 'ارتباط وضعیت موقتاً قطع شد؛ تلاش محدود ادامه دارد.');
				schedulePoll(Number(config.reconnectIntervalMs) || 1500);
				return;
			}
			render({status: 'failed', message_fa: error && error.message ? error.message : 'دریافت وضعیت متوقف شد.'});
			activeJob = null;
		}
	}

	async function resumeActiveJob() {
		try {
			var job = await request({
				action: 'digitalogic_currency_async_status',
				active_only: '1'
			});
			if (String(job.error_code || '') === 'digitalogic_currency_async_observed_deadline_exceeded') {
				render(job);
				return;
			}
			if (String(job.status || '') === 'publication_failed') {
				render(job);
				return;
			}
			if (
				['queued', 'running', 'publishing'].indexOf(String(job.status || '')) === -1 ||
				!job.job_id ||
				Number(job.generation || 0) < 1
			) {
				if (String(job.status || '') === 'idle') {
					render(job);
				}
				return;
			}
			activeJob = {
				job_id: String(job.job_id),
				generation: Number(job.generation),
				deadline_at: Number(job.deadline_at || 0),
				status: String(job.status)
			};
			startedAt = Date.now();
			render(job);
			schedulePoll(0);
		} catch (error) {
			render({status: 'failed', message_fa: error && error.message ? error.message : 'وضعیت کار قیمت در دسترس نیست.'});
		}
	}

	function initialize() {
		if (!config.ajaxUrl || !config.nonce) {
			return;
		}
		statusNode = locateStatusNode();
		if (!statusNode) {
			return;
		}
		resumeActiveJob();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, {once: true});
	} else {
		initialize();
	}
}(window, document));
