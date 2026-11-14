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

	function asciiDigits(value) {
		return value.replace(/[\u0660-\u0669\u06f0-\u06f9]/g, (digit) => {
			const code = digit.charCodeAt(0);
			return String(code - (code >= 0x06f0 ? 0x06f0 : 0x0660));
		});
	}

	function normalizeCallPhone(value) {
		const input = asciiDigits(value).trim();
		if (!/^[+0-9().\-\s]+$/.test(input)) {
			return '';
		}

		const compact = input.replace(/[().\-\s]/g, '');
		const match = compact.match(/^(?:\+98|0098|98|0)?([0-9]{10})$/);
		if (!match) {
			return '';
		}

		const national = match[1];
		const area = `0${national.slice(0, 2)}`;
		const result = `+98${national}`;
		const isLocal = national.startsWith('9') || config.areaCodes?.includes(area);
		return isLocal && result !== config.dialNumber ? result : '';
	}

	const icons = {
		phone: 'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.69 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.26a2 2 0 0 1 2.11-.45c.91.33 1.85.56 2.81.69A2 2 0 0 1 22 16.92z',
		keypad: 'M4 4h16v16H4zM8 8h.01M12 8h.01M16 8h.01M8 12h.01M12 12h.01M16 12h.01M8 16h.01M12 16h.01M16 16h.01',
		arrow: 'M5 12h14m-7-7 7 7-7 7',
		back: 'M19 12H5m7 7-7-7 7-7',
	};

	function addIcon(target, name) {
		if (!target) {
			return;
		}
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		const path = document.createElementNS(svg.namespaceURI, 'path');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.classList.add('digitalogic-call-icon', `digitalogic-call-icon--${name}`);
		path.setAttribute('d', icons[name]);
		svg.appendChild(path);
		target.prepend(svg);
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
		const dialNumber = dial?.querySelector('[data-call-dial-number]') || dial;
		const entry = widget.querySelector('[data-call-entry]');
		const countdown = widget.querySelector('[data-call-countdown]');
		const codeTitle = widget.querySelector('[data-call-code-title]');
		const back = widget.querySelector('[data-call-back]');
		const unified = widget.hasAttribute?.('data-call-unified') || false;
		if (!panel || !phone || !start || !instructions || !code || !status || !error) {
			return;
		}

		widget.dataset.digitalogicCallInitialized = 'true';
		const purpose = widget.dataset.purpose || 'login';
		const isBrandedLogin = () => purpose === 'login' && !!widget.closest('.dg-digits-login-shell');
		if (entry) {
			addIcon(entry.querySelector('h2'), 'phone');
			addIcon(phone.parentElement, 'phone');
			addIcon(start, 'arrow');
			addIcon(back, 'back');
			addIcon(codeTitle, 'keypad');
			addIcon(dial, 'phone');
			addIcon(cancel, 'back');
		}
		const entryTitle = entry?.querySelector('h2');
		const busyRegion = entry || unified ? panel : widget;
		let challengeId = '';
		let csrfToken = '';
		let pollTimer = 0;
		let countdownTimer = 0;
		let pollController = null;
		let stopped = false;
		let attemptGeneration = 0;
		let expiresAt = 0;
		let useRealtime = false;
		const pollInterval = Math.max(400, Math.min(750, Number(config.pollMs) || 500));
		let consuming = false;

		function showStep(step) {
			instructions.hidden = step !== 'code';
			if (unified) {
				panel.hidden = step !== 'code';
				widget.closest('.dg-digits-login-shell')?.classList.toggle('dg-call-code-active', step === 'code');
				if (step === 'code') {
					codeTitle.focus();
				} else {
					widget.closest('.dg-digits-login-shell')?.querySelector('form.digloginpage [name="digits_phone"]')?.focus();
				}
				return;
			}
			if (!entry) {
				return;
			}
			entry.hidden = step === 'code';
			panel.setAttribute('aria-labelledby', step === 'code' ? codeTitle.id : entryTitle.id);
			if (step === 'code') {
				codeTitle.focus();
			} else {
				phone.focus();
			}
		}

		function showError(value) {
			error.textContent = value || messages.error || 'Verification failed.';
			error.hidden = false;
			if (unified) {
				widget.dispatchEvent(new CustomEvent('digitalogic:call-error', { bubbles: true, detail: error.textContent }));
			}
		}

		function stopPolling() {
			stopped = true;
			window.clearTimeout(pollTimer);
			window.clearTimeout(countdownTimer);
			if (pollController) {
				pollController.abort();
				pollController = null;
			}
		}

		function remainingText(seconds) {
			const template = messages.remaining || '%s seconds remaining';
			return String(template).replace('%s', String(seconds));
		}

		function updateCountdown(expectedGeneration) {
			window.clearTimeout(countdownTimer);
			if (stopped || expectedGeneration !== attemptGeneration || !expiresAt || document.hidden) {
				return;
			}
			const seconds = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
			if (countdown) {
				const minutes = Math.floor(seconds / 60);
				countdown.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
			} else {
				status.textContent = `${messages.waiting || 'Waiting for your call…'} ${remainingText(seconds)}`;
			}
			if (seconds > 0) {
				countdownTimer = window.setTimeout(() => updateCountdown(expectedGeneration), 250);
			}
		}

		function schedulePoll(expectedGeneration, delay = pollInterval) {
			window.clearTimeout(pollTimer);
			if (stopped || expectedGeneration !== attemptGeneration || document.hidden) {
				return;
			}
			pollTimer = window.setTimeout(() => poll(expectedGeneration), delay);
		}

		function resetForRetry(message) {
			stopPolling();
			consuming = false;
			attemptGeneration += 1;
			challengeId = '';
			csrfToken = '';
			expiresAt = 0;
			code.textContent = '';
			start.disabled = false;
			if (cancel) {
				cancel.disabled = false;
			}
			busyRegion.removeAttribute('aria-busy');
			showStep('entry');
			showError(message);
		}

		async function consume(expectedGeneration, expectedChallengeId, expectedCsrfToken) {
			const body = {};
			if (isBrandedLogin()) {
				const redirect = new URLSearchParams(window.location.search).get('redirect_to');
				if (redirect) { body.redirect_to = redirect; }
				if (unified && widget.querySelector('input[name="rememberme"]:checked')) { body.rememberme = true; }
			}
			const data = await request(`${config.challengeUrl}/${encodeURIComponent(expectedChallengeId)}/consume`, {
				method: 'POST',
				headers: headers(expectedCsrfToken, purpose === 'add_contact'),
				body: JSON.stringify(body),
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
				|| consuming
				|| !widget.isConnected
			) {
				return;
			}
			const expectedChallengeId = challengeId;
			const expectedCsrfToken = csrfToken;
			try {
				pollController = new AbortController();
				const realtime = useRealtime && window.DigitalogicAuthRealtime;
				let data = realtime ? await realtime.callStatus(expectedChallengeId, expectedCsrfToken) : null;
				if (stopped || consuming || expectedGeneration !== attemptGeneration || expectedChallengeId !== challengeId) {
					return;
				}
				if (!data) {
					useRealtime = false;
					data = await request(`${config.challengeUrl}/${encodeURIComponent(expectedChallengeId)}`, {
						method: 'GET',
						headers: headers(expectedCsrfToken),
						signal: pollController.signal,
					});
				}
				pollController = null;
				if (
					stopped
					|| consuming
					|| expectedGeneration !== attemptGeneration
					|| expectedChallengeId !== challengeId
				) {
					return;
				}
				if (data.status === 'verified') {
					consuming = true;
					window.clearTimeout(pollTimer);
					window.clearTimeout(countdownTimer);
					if (cancel) {
						cancel.disabled = true;
					}
					busyRegion.setAttribute('aria-busy', 'true');
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
						if (isBrandedLogin()) {
							const redirect = new URLSearchParams(window.location.search).get('redirect_to') || '';
							const query = new URLSearchParams({ redirect_to: redirect });
							const url = `${config.challengeUrl}/${encodeURIComponent(expectedChallengeId)}?${query}`;
							const recovered = await request(url, { headers: headers(expectedCsrfToken) }).catch(() => null);
							if (stopped || expectedGeneration !== attemptGeneration || expectedChallengeId !== challengeId) {
								return;
							}
							if (recovered?.redirect_url) {
								window.location.assign(recovered.redirect_url);
								return;
							}
						}
						resetForRetry(consumeError.message);
					}
					return;
				}
				if (Number.isFinite(Number(data.expires_in))) {
					expiresAt = Date.now() + (Math.max(0, Number(data.expires_in)) * 1000);
					updateCountdown(expectedGeneration);
				}
				if (['expired', 'cancelled', 'consumed'].includes(data.status)) {
					status.textContent = messages.expired || 'Code expired.';
					resetForRetry(messages.expired || 'Code expired.');
					return;
				}
			} catch (pollError) {
				pollController = null;
				if (
					stopped
					|| expectedGeneration !== attemptGeneration
					|| expectedChallengeId !== challengeId
					|| pollError.name === 'AbortError'
				) {
					return;
				}
				resetForRetry(pollError.message);
				return;
			}
			schedulePoll(expectedGeneration);
		}

		document.addEventListener('visibilitychange', () => {
			if (stopped || consuming || !challengeId || !widget.isConnected) {
				return;
			}
			if (document.hidden) {
				window.clearTimeout(pollTimer);
				window.clearTimeout(countdownTimer);
				return;
			}
			updateCountdown(attemptGeneration);
			schedulePoll(attemptGeneration, 0);
		});

		if (toggle) {
			toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
		}

		toggle?.addEventListener('click', () => {
			const shell = entry && widget.closest('.dg-digits-login-shell');
			if (shell && panel.hidden) {
				shell.appendChild(panel);
				shell.classList.add('digitalogic-call-active');
			}
			panel.hidden = !panel.hidden;
			toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
			if (!panel.hidden) {
				showStep('entry');
			}
		});

		back?.addEventListener('click', () => {
			const shell = panel.closest('.dg-digits-login-shell');
			panel.hidden = true;
			widget.appendChild(panel);
			shell?.classList.remove('digitalogic-call-active');
			toggle?.setAttribute('aria-expanded', 'false');
			toggle?.focus();
		});

		if (entry) {
			phone.addEventListener('input', () => {
				const normalized = asciiDigits(phone.value);
				if (normalized !== phone.value) {
					const position = phone.selectionStart;
					phone.value = normalized;
					if (position !== null) {
						phone.setSelectionRange(position, position);
					}
				}
				phone.removeAttribute('aria-invalid');
				phone.setAttribute('aria-describedby', 'digitalogic-login-call-phone-help');
				error.hidden = true;
			});
			phone.addEventListener('keydown', (event) => {
				if (event.key === 'Enter') {
					event.preventDefault();
					start.click();
				}
			});
		}

		start?.addEventListener('click', async () => {
			if (start.disabled) {
				return;
			}
			if (entry) {
				const normalized = normalizeCallPhone(phone.value);
				if (!normalized) {
					phone.setAttribute('aria-invalid', 'true');
					phone.setAttribute('aria-describedby', `digitalogic-login-call-phone-help ${error.id}`);
					phone.focus();
					showError(messages.invalidPhone || 'Enter a valid Iranian mobile or landline number with its area code.');
					return;
				}
				phone.value = normalized;
				back.disabled = true;
			}
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
			busyRegion.setAttribute('aria-busy', 'true');
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
				useRealtime = isBrandedLogin();
				if (useRealtime) {
					window.DigitalogicAuthRealtime?.refreshBinding();
				}
				code.textContent = data.code;
				if (unified && /^[0-9]{6}$/.test(String(data.code))) {
					code.classList.add('dg-otp-editor', 'dg-call-code-slots');
					code.setAttribute('aria-label', String(data.code));
					code.replaceChildren(...Array.from(String(data.code), (digit) => {
						const cell = document.createElement('span');
						cell.className = 'dg-call-code-digit';
						cell.setAttribute('aria-hidden', 'true');
						cell.textContent = digit;
						return cell;
					}));
				}
				if (dialNumber && data.dial) {
					const display = String(data.dial.display || '');
					const tel = String(data.dial.tel || '');
					dialNumber.textContent = display;
				if (dial.matches('a') && /^\+?[0-9]+$/.test(tel)) {
						dial.setAttribute('href', `tel:${tel}`);
					}
				}
				expiresAt = Date.now() + (Math.max(0, Number(data.expires_in) || 0) * 1000);
				if (countdown) {
					status.textContent = unified && document.documentElement.lang.startsWith('fa')
						? 'در انتظار تماس شما…'
						: (messages.waiting || 'Waiting for your call…');
				}
				updateCountdown(expectedGeneration);
				showStep('code');
				busyRegion.removeAttribute('aria-busy');
				if (back) {
					back.disabled = false;
				}
				schedulePoll(expectedGeneration);
			} catch (startError) {
				if (stopped || expectedGeneration !== attemptGeneration) {
					return;
				}
				start.disabled = false;
				if (back) {
					back.disabled = false;
				}
				busyRegion.removeAttribute('aria-busy');
				showError(startError.message);
			}
		});

		if (unified) {
			addIcon(codeTitle, 'keypad');
			addIcon(cancel, 'back');
			window.DigitalogicCallLogin = {
				start(value) {
					if (!widget.isConnected || widget.dataset.callAvailable !== 'true'
						|| !widget.closest('.dg-digits-login-shell') || !/^\+[1-9]\d{7,14}$/.test(value)) { return false; }
					if (!start.disabled) {
						phone.value = value;
						start.click();
					}
					return true;
				}
			};
		}

		cancel?.addEventListener('click', async () => {
			stopPolling();
			attemptGeneration += 1;
			const cancelledChallengeId = challengeId;
			const cancelledCsrfToken = csrfToken;
			challengeId = '';
			csrfToken = '';
			expiresAt = 0;
			code.textContent = '';
			start.disabled = false;
			busyRegion.removeAttribute('aria-busy');
			showStep('entry');
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
			if (!entry) {
				phone.focus();
			}
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
