(function () {
	'use strict';
	var messages = window.DigitalogicOrderExperience || {
		copied: 'Copied',
		copyUnavailable: 'Automatic copy is unavailable',
		invoicePending: 'The invoice PDF is not ready yet',
		shareTitle: 'Digitalogic order',
		shareText: 'Secure Digitalogic order and invoice link',
		noFile: 'No file selected'
	};

	function toast(root, message) {
		var target = root.querySelector('[data-dg-toast]');
		if (!target) return;
		target.textContent = message;
		target.classList.add('is-visible');
		window.clearTimeout(target.dgTimer);
		target.dgTimer = window.setTimeout(function () {
			target.classList.remove('is-visible');
		}, 2600);
	}

	function copyText(root, value) {
		var fallback = function () {
			var input = document.createElement('textarea');
			input.value = value;
			input.setAttribute('readonly', 'readonly');
			input.style.position = 'fixed';
			input.style.opacity = '0';
			document.body.appendChild(input);
			input.select();
			var copied = document.execCommand('copy');
			input.remove();
			return copied ? Promise.resolve() : Promise.reject(new Error('copy_failed'));
		};
		var action = navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(value) : fallback();
		action.then(function () { toast(root, messages.copied); }).catch(function () { toast(root, messages.copyUnavailable); });
	}

	function closeMenu(root) {
		var toggle = root.querySelector('[data-dg-menu-toggle]');
		var panel = root.querySelector('[data-dg-menu-panel]');
		if (!toggle || !panel) return;
		toggle.setAttribute('aria-expanded', 'false');
		panel.hidden = true;
	}

	function setupHub(root) {
		var sourceDownload = document.querySelector('a[href*="type=download_invoice"]');
		var sourcePrint = document.querySelector('a[href*="type=print_invoice"]');
		var downloadTargets = root.querySelectorAll('[data-dg-invoice-download], [data-dg-invoice-save]');
		var printTarget = root.querySelector('[data-dg-invoice-print]');
		var hasDirectDownload = Array.prototype.some.call(downloadTargets, function (target) {
			return target.getAttribute('href') && target.getAttribute('href') !== '#';
		});
		var hasDirectPrint = printTarget && printTarget.getAttribute('href') && printTarget.getAttribute('href') !== '#';

		if (sourceDownload) sourceDownload.classList.add('dg-original-invoice-action');
		if (hasDirectDownload) {
			downloadTargets.forEach(function (target) { target.removeAttribute('aria-disabled'); });
		} else if (sourceDownload) {
			downloadTargets.forEach(function (target) {
				target.href = sourceDownload.href;
				target.removeAttribute('aria-disabled');
			});
		} else {
			downloadTargets.forEach(function (target) {
				target.classList.add('is-disabled');
				target.addEventListener('click', function (event) {
					event.preventDefault();
					toast(root, messages.invoicePending);
				});
			});
		}

		if (sourcePrint) sourcePrint.classList.add('dg-original-invoice-action');
		if (!hasDirectPrint && sourcePrint && printTarget) {
			printTarget.href = sourcePrint.href;
		} else if (!hasDirectPrint && printTarget) {
			printTarget.hidden = true;
		}

		var toggle = root.querySelector('[data-dg-menu-toggle]');
		var panel = root.querySelector('[data-dg-menu-panel]');
		if (toggle && panel) {
			toggle.addEventListener('click', function () {
				var open = toggle.getAttribute('aria-expanded') === 'true';
				toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
				panel.hidden = open;
			});
			document.addEventListener('click', function (event) {
				if (!root.contains(event.target)) closeMenu(root);
			});
			root.addEventListener('keydown', function (event) {
				if (event.key === 'Escape') {
					closeMenu(root);
					toggle.focus();
				}
			});
		}

		root.querySelectorAll('[data-dg-copy]').forEach(function (button) {
			button.addEventListener('click', function () {
				copyText(root, button.getAttribute('data-dg-copy') || '');
			});
		});

		var share = root.querySelector('[data-dg-share]');
		if (share) {
			share.addEventListener('click', function () {
				var url = root.getAttribute('data-order-url') || window.location.href;
				var orderId = root.getAttribute('data-order-id') || '';
				if (navigator.share) {
					navigator.share({ title: messages.shareTitle + ' ' + orderId, text: messages.shareText, url: url }).catch(function (error) {
						if (error && error.name !== 'AbortError') copyText(root, url);
					});
				} else {
					copyText(root, url);
				}
			});
		}

		document.documentElement.classList.add('dg-order-actions-ready');
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.dg-order-hub').forEach(setupHub);
		document.querySelectorAll('[data-dg-copy]').forEach(function (button) {
			if (button.closest('.dg-order-hub')) return;
			button.addEventListener('click', function () {
				var root = document.querySelector('.dg-order-hub') || document.body;
				copyText(root, button.getAttribute('data-dg-copy') || '');
			});
		});
		document.querySelectorAll('[data-dg-receipt-form] input[type="file"]').forEach(function (input) {
			input.addEventListener('change', function () {
				var label = input.closest('label').querySelector('[data-dg-file-name]');
				if (label) label.textContent = input.files && input.files[0] ? input.files[0].name : messages.noFile;
			});
		});
	});
}());
