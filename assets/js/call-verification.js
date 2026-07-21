(() => {
	'use strict';

	const config = window.DigitalogicCallVerification || {};
	const messages = config.messages || {};

	async function request(url, options = {}) {
		const response = await window.fetch(url, {
			credentials: 'same-origin',
			cache: 'no-store',
			redirect: 'error',
			...options,
		});
		let data = null;
		try {
			data = await response.json();
		} catch (_error) {
			data = null;
		}
		if (!response.ok || !data) {
			const error = new Error(data && data.message ? data.message : (messages.error || 'Verification failed.'));
			error.status = response.status;
			throw error;
		}
		return data;
	}

	function headers(csrfToken = '', includeNonce = false) {
		const result = { 'Content-Type': 'application/json' };
		if (csrfToken) {
			result['X-Digitalogic-CSRF'] = csrfToken;
		}
		if (includeNonce && config.wpNonce) {
			result['X-WP-Nonce'] = config.wpNonce;
		}
		return result;
	}

	function initializeWidget(widget) {
		if (!(widget instanceof HTMLElement) || widget.dataset.digitalogicCallInitialized === 'true') {
			return;
		}

		const toggle = widget.querySelector('.digitalogic-call-toggle');
		const panel = widget.querySelector('.digitalogic-call-panel');
		const phone = widget.querySelector('[data-call-phone]');
		const start = widget.querySelector('[data-call-start]');
		const instructions = widget.querySelector('[data-call-instructions]');
		const code = widget.querySelector('[data-call-code]');
		const status = widget.querySelector('[data-call-status]');
		const cancel = widget.querySelector('[data-call-cancel]');
		const error = widget.querySelector('[data-call-error]');
		const dial = widget.querySelector('[data-call-dial]');
		const ivr = widget.querySelector('[data-call-ivr]');
		if (!panel || !phone || !start || !instructions || !code || !status || !error) {
			return;
		}

		widget.dataset.digitalogicCallInitialized = 'true';
		const purpose = widget.dataset.purpose || 'login';
		let challengeId = '';
		let csrfToken = '';
		let pollTimer = 0;
		let stopped = false;
		let attemptGeneration = 0;

		function showError(value) {
			error.textContent = value || messages.error || 'Verification failed.';
			error.hidden = false;
		}

		function stopPolling() {
			stopped = true;
			window.clearTimeout(pollTimer);
		}

		function resetForRetry(message) {
			stopPolling();
			attemptGeneration += 1;
			challengeId = '';
			csrfToken = '';
			code.textContent = '';
			instructions.hidden = true;
			start.disabled = false;
			if (cancel) {
				cancel.disabled = false;
			}
			widget.removeAttribute('aria-busy');
			showError(message);
		}

		async function consume(expectedGeneration, expectedChallengeId, expectedCsrfToken) {
			const data = await request(`${config.challengeUrl}/${encodeURIComponent(expectedChallengeId)}/consume`, {
				method: 'POST',
				headers: headers(expectedCsrfToken, purpose === 'add_contact'),
				body: '{}',
			});
			if (
				stopped
				|| expectedGeneration !== attemptGeneration
				|| expectedChallengeId !== challengeId
			) {
				return;
			}
			status.textContent = messages.verified || 'Verified.';
			if (data.redirect_url) {
				window.location.assign(data.redirect_url);
			} else {
				window.location.reload();
			}
		}

		async function poll(expectedGeneration) {
			if (
				stopped
				|| expectedGeneration !== attemptGeneration
				|| !challengeId
				|| !widget.isConnected
			) {
				stopPolling();
				return;
			}
			const expectedChallengeId = challengeId;
			const expectedCsrfToken = csrfToken;
			try {
				const data = await request(`${config.challengeUrl}/${encodeURIComponent(expectedChallengeId)}`, {
					method: 'GET',
					headers: headers(expectedCsrfToken),
				});
				if (
					stopped
					|| expectedGeneration !== attemptGeneration
					|| expectedChallengeId !== challengeId
				) {
					return;
				}
				if (data.status === 'verified') {
					window.clearTimeout(pollTimer);
					if (cancel) {
						cancel.disabled = true;
					}
					widget.setAttribute('aria-busy', 'true');
					try {
						await consume(expectedGeneration, expectedChallengeId, expectedCsrfToken);
					} catch (consumeError) {
						if (
							stopped
							|| expectedGeneration !== attemptGeneration
							|| expectedChallengeId !== challengeId
						) {
							return;
						}
						resetForRetry(consumeError.message);
					}
					return;
				}
				if (['expired', 'cancelled', 'consumed'].includes(data.status)) {
					status.textContent = messages.expired || 'Code expired.';
					resetForRetry(messages.expired || 'Code expired.');
					return;
				}
			} catch (pollError) {
				if (
					stopped
					|| expectedGeneration !== attemptGeneration
					|| expectedChallengeId !== challengeId
				) {
					return;
				}
				resetForRetry(pollError.message);
				return;
			}
			pollTimer = window.setTimeout(() => poll(expectedGeneration), 2000);
		}

		if (toggle) {
			toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
		}

		toggle?.addEventListener('click', () => {
			panel.hidden = !panel.hidden;
			toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
			if (!panel.hidden) {
				phone.focus();
			}
		});

		start?.addEventListener('click', async () => {
			stopPolling();
			attemptGeneration += 1;
			const expectedGeneration = attemptGeneration;
			stopped = false;
			error.hidden = true;
			instructions.hidden = true;
			code.textContent = '';
			start.disabled = true;
			if (cancel) {
				cancel.disabled = false;
			}
			widget.setAttribute('aria-busy', 'true');
			try {
				const data = await request(config.challengeUrl, {
					method: 'POST',
					headers: headers('', purpose === 'add_contact'),
					body: JSON.stringify({ phone: phone.value, purpose }),
				});
				if (stopped || expectedGeneration !== attemptGeneration) {
					return;
				}
				challengeId = data.challenge_id;
				csrfToken = data.csrf_token;
				code.textContent = data.code;
				if (dial && data.dial) {
					const display = String(data.dial.display || '');
					const tel = String(data.dial.tel || '');
					dial.textContent = display;
					if (/^\+?[0-9]+$/.test(tel)) {
						dial.setAttribute('href', `tel:${tel}`);
					}
				}
				if (ivr) {
					ivr.textContent = String(data.ivr_option || '');
				}
				status.textContent = messages.waiting || 'Waiting for your call…';
				instructions.hidden = false;
				widget.removeAttribute('aria-busy');
				pollTimer = window.setTimeout(() => poll(expectedGeneration), 1200);
			} catch (startError) {
				if (stopped || expectedGeneration !== attemptGeneration) {
					return;
				}
				start.disabled = false;
				widget.removeAttribute('aria-busy');
				showError(startError.message);
			}
		});

		cancel?.addEventListener('click', async () => {
			stopPolling();
			attemptGeneration += 1;
			const cancelledChallengeId = challengeId;
			const cancelledCsrfToken = csrfToken;
			challengeId = '';
			csrfToken = '';
			code.textContent = '';
			instructions.hidden = true;
			start.disabled = false;
			widget.removeAttribute('aria-busy');
			if (cancelledChallengeId) {
				try {
					await request(`${config.challengeUrl}/${encodeURIComponent(cancelledChallengeId)}`, {
						method: 'DELETE',
						headers: headers(cancelledCsrfToken),
					});
				} catch (_error) {
					// Cancellation is best effort; the server expiry remains authoritative.
				}
			}
			phone.focus();
		});
	}

	function initializeWidgets(root = document) {
		if (root instanceof Element && root.matches('[data-digitalogic-call-widget]')) {
			initializeWidget(root);
		}
		if (typeof root.querySelectorAll === 'function') {
			root.querySelectorAll('[data-digitalogic-call-widget]').forEach(initializeWidget);
		}
	}

	initializeWidgets();
	const widgetObserver = new MutationObserver((records) => {
		records.forEach((record) => {
			record.addedNodes.forEach((node) => {
				if (node instanceof Element) {
					initializeWidgets(node);
				}
			});
		});
	});
	widgetObserver.observe(document.body, { childList: true, subtree: true });

	const contacts = document.querySelector('[data-digitalogic-contacts]');
	if (!contacts || !config.contactsUrl || !config.wpNonce) {
		return;
	}

	contacts.querySelector('[data-add-email-submit]')?.addEventListener('click', async (event) => {
		const form = event.currentTarget.closest('[data-add-email]');
		const email = form.querySelector('[data-contact-email]');
		const label = form.querySelector('[data-contact-label]');
		if (!email.value.trim() || !email.checkValidity()) {
			email.focus();
			return;
		}
		try {
			await request(config.contactsUrl, {
				method: 'POST',
				headers: headers('', true),
				body: JSON.stringify({ email: email.value, label: label.value }),
			});
			window.location.reload();
		} catch (contactError) {
			window.alert(contactError.message);
		}
	});

	contacts.addEventListener('change', async (event) => {
		const voice = event.target.closest('[data-contact-voice]');
		const eventCheckbox = event.target.closest('[data-contact-event]');
		if (!voice && !eventCheckbox) {
			return;
		}
		const row = event.target.closest('[data-contact-id]');
		if (!row || row.dataset.contactSaving === '1') {
			return;
		}
		row.dataset.contactSaving = '1';
		row.setAttribute('aria-busy', 'true');
		const controls = Array.from(row.querySelectorAll('input, button'));
		controls.forEach((control) => { control.disabled = true; });
		const payload = {
			voice_opt_in: Boolean(row.querySelector('[data-contact-voice]')?.checked),
			voice_events: {},
		};
		row.querySelectorAll('[data-contact-event]').forEach((checkbox) => {
			payload.voice_events[checkbox.value] = checkbox.checked;
		});
		try {
			await request(`${config.contactsUrl}/${encodeURIComponent(row.dataset.contactId)}`, {
				method: 'PATCH',
				headers: headers('', true),
				body: JSON.stringify(payload),
			});
		} catch (contactError) {
			window.alert(contactError.message);
			window.location.reload();
			return;
		}
		delete row.dataset.contactSaving;
		row.removeAttribute('aria-busy');
		controls.forEach((control) => { control.disabled = false; });
	});

	contacts.addEventListener('click', async (event) => {
		const remove = event.target.closest('[data-contact-delete]');
		if (!remove) {
			return;
		}
		const row = remove.closest('[data-contact-id]');
		if (!window.confirm(messages.confirmDrop || 'Remove contact?')) {
			return;
		}
		try {
			await request(`${config.contactsUrl}/${encodeURIComponent(row.dataset.contactId)}`, {
				method: 'DELETE',
				headers: headers('', true),
			});
			row.remove();
		} catch (contactError) {
			window.alert(contactError.message);
		}
	});
})();
