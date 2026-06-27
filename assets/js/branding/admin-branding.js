(function () {
    var config = window.DigitalogicBranding || {};
    var storageKey = config.storageKey || 'digitalogic-admin-theme';
    var root = document.documentElement;

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getLabel(key, fallback) {
        var value = config.labels && config.labels[key];

        if (typeof value !== 'string' || !value || /[ØÙÛÚ]/.test(value)) {
            return fallback;
        }

        return value;
    }

    function readStoredTheme() {
        try {
            return window.localStorage ? window.localStorage.getItem(storageKey) : null;
        } catch (error) {
            return null;
        }
    }

    function currentTheme() {
        var stored = readStoredTheme();

        if (stored === 'light' || stored === 'dark') {
            return stored;
        }

        if (document.body && document.body.classList.contains('login')) {
            return 'light';
        }

        var attr = root.getAttribute('data-dg-theme');
        if (attr === 'light' || attr === 'dark') {
            return attr;
        }

        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function themeToggleMarkup(theme, label) {
        var icon = theme === 'dark' ? 'dashicons-lightbulb' : 'dashicons-star-filled';

        return '<span class="dashicons ' + icon + '" aria-hidden="true"></span>' +
            '<span class="dg-toggle-text">' + escapeHtml(label) + '</span>';
    }

    function setTheme(theme, persist) {
        var value = theme === 'dark' ? 'dark' : 'light';
        root.setAttribute('data-dg-theme', value);

        if (document.body) {
            document.body.setAttribute('data-dg-theme', value);
        }

        if (persist !== false) {
            try {
                window.localStorage.setItem(storageKey, value);
            } catch (error) {
                // Storage can be blocked in private browsing; the UI should still work.
            }
        }

        syncToggleLabels(value);
        syncPasswordToggle();
    }

    function toggleTheme(event) {
        if (event) {
            event.preventDefault();
        }

        setTheme(currentTheme() === 'dark' ? 'light' : 'dark');
    }

    function syncToggleLabels(theme) {
        var nextLabel = theme === 'dark'
            ? getLabel('toggleToLight', 'تغییر به حالت روشن')
            : getLabel('toggleToDark', 'تغییر به حالت تیره');
        var buttonLabel = theme === 'dark'
            ? getLabel('light', 'روشن')
            : getLabel('dark', 'تیره');

        document.querySelectorAll('[data-dg-theme-toggle-label]').forEach(function (node) {
            node.textContent = nextLabel;
        });

        document.querySelectorAll('[data-dg-theme-toggle-button]').forEach(function (node) {
            node.setAttribute('aria-label', nextLabel);
            node.innerHTML = themeToggleMarkup(theme, buttonLabel);
        });
    }

    function showToast(message, tone) {
        if (!message || !document.body) {
            return;
        }

        var container = document.querySelector('.dg-toast-stack');
        if (!container) {
            container = document.createElement('div');
            container.className = 'dg-toast-stack';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'dg-toast dg-toast--' + (tone || 'warning');
        toast.textContent = message;
        container.appendChild(toast);

        window.setTimeout(function () {
            toast.classList.add('is-leaving');
            window.setTimeout(function () {
                toast.remove();
            }, 220);
        }, 5200);
    }

    function observeInlineMessages() {
        if (!document.body) {
            return;
        }

        var seen = new Set();

        function relayText(node) {
            if (!node) {
                return;
            }

            var text = (node.textContent || '').replace(/\s+/g, ' ').trim();
            if (!text || seen.has(text)) {
                return;
            }

            seen.add(text);
            showToast(text, node.matches('#login_error, .notice-error, .error') ? 'error' : 'warning');
        }

        document.querySelectorAll('#login_error, .notice-error, .error, .notice-warning, .update-nag').forEach(relayText);

        var observer = new MutationObserver(function () {
            document.querySelectorAll('#login_error, .notice-error, .error, .notice-warning, .update-nag').forEach(relayText);
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    function injectLoginToggle() {
        if (!document.body || !document.body.classList.contains('login') || document.querySelector('[data-dg-theme-toggle-button]')) {
            return;
        }

        var switcher = document.querySelector('.language-switcher');
        if (!switcher) {
            return;
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'dg-theme-toggle-button';
        button.setAttribute('data-dg-theme-toggle-button', 'true');
        button.addEventListener('click', toggleTheme);
        switcher.prepend(button);
        syncToggleLabels(currentTheme());
    }

    function buildLanguageSwitcher() {
        if (!document.body || !document.body.classList.contains('login')) {
            return;
        }

        var wrapper = document.querySelector('.language-switcher');
        var form = document.querySelector('#language-switcher');
        var select = document.querySelector('#language-switcher-locales');
        if (!wrapper || !form || !select || form.querySelector('.dg-language-picker')) {
            return;
        }

        var nativeSubmit = form.querySelector('input[type="submit"], button[type="submit"]');
        if (!nativeSubmit) {
            return;
        }

        form.classList.add('dg-language-form');
        form.setAttribute('novalidate', 'novalidate');

        var picker = document.createElement('div');
        picker.className = 'dg-language-picker';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'button dg-language-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', 'dg-language-menu');

        var menu = document.createElement('div');
        menu.id = 'dg-language-menu';
        menu.className = 'dg-language-menu';
        menu.setAttribute('role', 'listbox');
        menu.setAttribute('aria-hidden', 'true');

        function closeMenu() {
            picker.classList.remove('is-open');
            menu.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
        }

        function openMenu() {
            picker.classList.add('is-open');
            menu.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
        }

        function syncSelection() {
            var selected = select.options[select.selectedIndex];
            var text = selected ? selected.text : 'فارسی';
            trigger.innerHTML = '<span class="dashicons dashicons-translation" aria-hidden="true"></span>' +
                '<span class="dg-select-text">' + escapeHtml(text) + '</span>' +
                '<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>';

            menu.querySelectorAll('.dg-language-option').forEach(function (option) {
                var active = option.getAttribute('data-value') === select.value;
                option.classList.toggle('is-active', active);
                option.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }

        trigger.addEventListener('click', function () {
            if (!picker.classList.contains('is-open')) {
                openMenu();
            } else {
                closeMenu();
            }
        });

        Array.prototype.slice.call(select.options).forEach(function (option) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'dg-language-option';
            item.setAttribute('role', 'option');
            item.setAttribute('data-value', option.value);
            item.innerHTML = '<span class="dashicons dashicons-translation" aria-hidden="true"></span>' +
                '<span>' + escapeHtml(option.text) + '</span>';
            item.addEventListener('click', function () {
                select.value = option.value;
                syncSelection();
                closeMenu();
            });
            menu.appendChild(item);
        });

        var applyButton = document.createElement('button');
        applyButton.type = 'button';
        applyButton.className = 'button dg-language-apply';
        applyButton.innerHTML = '<span class="dashicons dashicons-update" aria-hidden="true"></span>' +
            '<span>' + escapeHtml(getLabel('applyLanguage', 'تغییر')) + '</span>';
        applyButton.addEventListener('click', function () {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            nativeSubmit.click();
        });

        picker.appendChild(trigger);
        picker.appendChild(menu);
        form.insertBefore(picker, nativeSubmit);
        form.insertBefore(applyButton, nativeSubmit);

        form.querySelectorAll('label, select, input[type="submit"], button[type="submit"]').forEach(function (node) {
            if (node !== applyButton && node !== trigger && node !== menu && !picker.contains(node)) {
                node.classList.add('dg-visually-hidden');
                node.setAttribute('tabindex', '-1');
            }
        });

        document.addEventListener('click', function (event) {
            if (!picker.contains(event.target)) {
                closeMenu();
            }
        });

        syncSelection();
    }

    function normalizeDigits(value) {
        var source = '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩';
        var target = '01234567890123456789';

        return String(value || '').replace(/[۰-۹٠-٩]/g, function (digit) {
            return target.charAt(source.indexOf(digit));
        });
    }

    function classifyIdentity(value) {
        var normalized = normalizeDigits(value).trim();
        var numeric = normalized.replace(/[^\d+]/g, '');
        var digits = numeric.replace(/\D/g, '');

        if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized)) {
            return 'email';
        }

        if (/^(?:\+?98|0098|0)?9\d{9}$/.test(digits.length > 0 && numeric.indexOf('+') !== -1 ? numeric : digits)) {
            return 'phone';
        }

        if (/^(?:98|0098|0)?9\d{9}$/.test(digits)) {
            return 'phone';
        }

        return 'username';
    }

    function normalizePhoneOnBlur(input) {
        var type = classifyIdentity(input.value);
        if (type !== 'phone') {
            return;
        }

        var digits = normalizeDigits(input.value).replace(/\D/g, '');
        if (digits.indexOf('0098') === 0) {
            digits = digits.slice(2);
        }
        if (digits.indexOf('98') === 0 && digits.length > 10) {
            digits = digits.slice(2);
        }
        if (digits.indexOf('0') === 0 && digits.length === 11) {
            digits = digits.slice(1);
        }

        if (/^9\d{9}$/.test(digits)) {
            input.value = '0' + digits;
        }
    }

    function enhanceIdentityField(input) {
        if (!input || input.dataset.dgIdentityReady === 'true') {
            return;
        }

        input.dataset.dgIdentityReady = 'true';
        input.setAttribute('autocomplete', 'username');
        input.setAttribute('dir', 'auto');

        var wrapper = document.createElement('span');
        wrapper.className = 'dg-login-identity-control';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        var adornment = document.createElement('span');
        adornment.className = 'dg-login-identity-adornment';
        adornment.setAttribute('aria-live', 'polite');
        wrapper.appendChild(adornment);

        function syncIdentity() {
            var original = input.value;
            var normalized = normalizeDigits(original);
            if (original !== normalized) {
                var start = input.selectionStart;
                var end = input.selectionEnd;
                input.value = normalized;
                try {
                    input.setSelectionRange(start, end);
                } catch (error) {
                    // Some input types do not support selection APIs.
                }
            }

            var type = classifyIdentity(input.value);
            var meta = {
                phone: {
                    icon: 'dashicons-smartphone',
                    text: 'IR +98',
                    label: getLabel('phoneIdentity', 'موبایل ایران'),
                    inputMode: 'tel',
                    autocomplete: 'tel'
                },
                email: {
                    icon: 'dashicons-email-alt2',
                    text: getLabel('emailIdentity', 'ایمیل'),
                    label: getLabel('emailIdentity', 'ایمیل'),
                    inputMode: 'email',
                    autocomplete: 'email'
                },
                username: {
                    icon: 'dashicons-admin-users',
                    text: getLabel('usernameIdentity', 'نام کاربری'),
                    label: getLabel('usernameIdentity', 'نام کاربری'),
                    inputMode: 'text',
                    autocomplete: 'username'
                }
            }[type];

            input.dataset.dgIdentity = type;
            wrapper.dataset.dgIdentity = type;
            input.setAttribute('inputmode', meta.inputMode);
            input.setAttribute('autocomplete', meta.autocomplete);
            adornment.innerHTML = '<span class="dashicons ' + meta.icon + '" aria-hidden="true"></span>' +
                '<span class="dg-login-identity-text">' + escapeHtml(meta.text) + '</span>';
            adornment.setAttribute('aria-label', meta.label);
        }

        input.addEventListener('input', syncIdentity);
        input.addEventListener('blur', function () {
            normalizePhoneOnBlur(input);
            syncIdentity();
        });
        syncIdentity();
    }

    function setLabel(label, text, icon) {
        if (!label || label.dataset.dgLabelReady === 'true') {
            return;
        }

        label.dataset.dgLabelReady = 'true';
        label.classList.add('dg-login-label');
        label.innerHTML = '<span class="dashicons ' + icon + ' dg-login-label-icon" aria-hidden="true"></span>' +
            '<span>' + escapeHtml(text) + '</span>';
    }

    function enhanceLoginLabels() {
        setLabel(
            document.querySelector('label[for="user_login"]'),
            getLabel('usernameEmailPhone', 'نام کاربری، ایمیل یا شماره موبایل'),
            'dashicons-id'
        );
        setLabel(
            document.querySelector('label[for="user_pass"]'),
            getLabel('password', 'رمز عبور'),
            'dashicons-lock'
        );
    }

    function capturePasswordSelection(input) {
        var wasActive = document.activeElement === input;
        var start = null;
        var end = null;
        var direction = null;

        try {
            start = input.selectionStart;
            end = input.selectionEnd;
            direction = input.selectionDirection;
        } catch (error) {
            start = null;
            end = null;
        }

        return function restoreSelection() {
            if (!wasActive) {
                return;
            }

            input.focus({ preventScroll: true });

            if (typeof start === 'number' && typeof end === 'number') {
                try {
                    input.setSelectionRange(start, end, direction || 'none');
                } catch (error) {
                    // Password managers and browsers can temporarily block selection restoration.
                }
            }
        };
    }

    function syncPasswordToggle() {
        if (!document.body || !document.body.classList.contains('login')) {
            return;
        }

        var button = document.querySelector('.wp-hide-pw');
        var input = document.querySelector('#user_pass');
        if (!button || !input) {
            return;
        }

        var isVisible = input.type === 'text';
        var label = isVisible ? 'پنهان کردن رمز عبور' : 'نمایش رمز عبور';
        var icon = isVisible ? 'dashicons-hidden' : 'dashicons-visibility';

        button.classList.toggle('is-visible', isVisible);
        button.setAttribute('aria-label', label);
        button.innerHTML = '<span class="dashicons ' + icon + '" aria-hidden="true"></span>';
    }

    function bindPasswordToggle() {
        var visibilityButton = document.querySelector('.wp-hide-pw');
        var password = document.querySelector('#user_pass');
        var restoreSelection = null;

        if (!visibilityButton || !password || visibilityButton.dataset.dgPasswordReady === 'true') {
            return;
        }

        visibilityButton.dataset.dgPasswordReady = 'true';
        visibilityButton.addEventListener('mousedown', function (event) {
            restoreSelection = capturePasswordSelection(password);
            event.preventDefault();
        });
        visibilityButton.addEventListener('touchstart', function () {
            restoreSelection = capturePasswordSelection(password);
        }, { passive: true });
        visibilityButton.addEventListener('click', function () {
            var restore = restoreSelection || capturePasswordSelection(password);
            window.setTimeout(function () {
                syncPasswordToggle();
                restore();
                restoreSelection = null;
            }, 0);
        });
        syncPasswordToggle();
    }

    function markLoading(button, disable) {
        if (!button || button.dataset.dgLoading === 'true') {
            return;
        }

        button.dataset.dgLoading = 'true';
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        document.body.classList.add('dg-login-submitting');

        if (disable) {
            button.disabled = true;
        }
    }

    function clearLoading() {
        document.body.classList.remove('dg-login-submitting');
        document.querySelectorAll('[data-dg-loading="true"]').forEach(function (button) {
            button.dataset.dgLoading = 'false';
            button.classList.remove('is-loading');
            button.removeAttribute('aria-busy');
            if (button.matches('#wp-submit')) {
                button.disabled = false;
            }
        });
    }

    function bindLoadingStates() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches('#loginform, #lostpasswordform, #registerform, .digits-form_page form, form.digits-form')) {
                return;
            }

            var button = form.querySelector('#wp-submit, [type="submit"], .digits-form_submit-btn');
            markLoading(button, form.matches('#loginform'));
        }, true);

        document.addEventListener('click', function (event) {
            var button = event.target.closest('.digits-form_submit-btn, .digits-form_button, .digits-social-btn');
            if (!button) {
                return;
            }

            markLoading(button, false);
        });

        window.addEventListener('pageshow', clearLoading);

        if (window.jQuery) {
            window.jQuery(document).ajaxComplete(clearLoading);
            window.jQuery(document).ajaxError(clearLoading);
        }
    }

    function bindLoginValidation() {
        if (!document.body || !document.body.classList.contains('login')) {
            return;
        }

        var form = document.querySelector('#loginform');
        var username = document.querySelector('#user_login');
        var password = document.querySelector('#user_pass');

        enhanceLoginLabels();
        enhanceIdentityField(username);
        bindPasswordToggle();

        if (!form || !username || !password) {
            return;
        }

        form.setAttribute('novalidate', 'novalidate');
        username.placeholder = '0912... / name@example.com / username';

        form.addEventListener('submit', function (event) {
            if (!String(username.value || '').trim()) {
                event.preventDefault();
                showToast(getLabel('requiredUsername', 'نام کاربری، ایمیل یا شماره موبایل را وارد کنید.'), 'warning');
                username.focus();
                return;
            }

            if (!String(password.value || '').trim()) {
                event.preventDefault();
                showToast(getLabel('requiredPassword', 'رمز عبور را وارد کنید.'), 'warning');
                password.focus();
                return;
            }

            if (window.WFLSVars && window.WFLSVars.useCAPTCHA === '1' && !document.querySelector('iframe[title="reCAPTCHA"]')) {
                showToast(getLabel('recaptchaUnavailable', 'محافظت ورود هنوز کامل بارگذاری نشده است. چند لحظه دیگر دوباره تلاش کنید.'), 'warning');
            }
        });
    }

    function bindErrorHandling() {
        window.addEventListener('error', function () {
            showToast(getLabel('runtimeError', 'یک خطای غیرمنتظره در صفحه رخ داد. صفحه را تازه‌سازی کنید و دوباره ادامه دهید.'), 'warning');
        });

        window.addEventListener('unhandledrejection', function () {
            showToast(getLabel('runtimeError', 'یک خطای غیرمنتظره در صفحه رخ داد. صفحه را تازه‌سازی کنید و دوباره ادامه دهید.'), 'warning');
        });

        if (window.jQuery) {
            window.jQuery(document).ajaxError(function () {
                showToast(getLabel('requestError', 'درخواست با خطا روبه‌رو شد. دوباره تلاش کنید یا صفحه را تازه‌سازی کنید.'), 'error');
            });
        }
    }

    function bindThemeToggles() {
        document.querySelectorAll('#wp-admin-bar-digitalogic-theme-toggle a').forEach(function (anchor) {
            anchor.addEventListener('click', toggleTheme);
        });
    }

    function enableRipples() {
        document.addEventListener('pointerdown', function (event) {
            var target = event.target.closest('button, .button, .page-title-action, #wpadminbar a, .digits-social-btn');
            if (!target || target.disabled || target.classList.contains('ab-empty-item')) {
                return;
            }

            target.classList.add('dg-ripple-host');

            var circle = document.createElement('span');
            var rect = target.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height) * 1.35;
            circle.className = 'dg-ripple-circle';
            circle.style.width = size + 'px';
            circle.style.height = size + 'px';
            circle.style.left = (event.clientX - rect.left) + 'px';
            circle.style.top = (event.clientY - rect.top) + 'px';
            target.appendChild(circle);

            window.setTimeout(function () {
                circle.remove();
            }, 650);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setTheme(currentTheme(), false);
        bindThemeToggles();
        injectLoginToggle();
        buildLanguageSwitcher();
        bindLoginValidation();
        bindLoadingStates();
        observeInlineMessages();
        bindErrorHandling();
        enableRipples();
    });
})();
