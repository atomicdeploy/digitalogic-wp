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

    function currentTheme() {
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
                // Ignore storage failures.
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
            ? (config.labels && config.labels.toggleToLight) || 'تغییر به حالت روشن'
            : (config.labels && config.labels.toggleToDark) || 'تغییر به حالت تیره';
        var buttonLabel = theme === 'dark'
            ? (config.labels && config.labels.light) || 'روشن'
            : (config.labels && config.labels.dark) || 'تیره';

        document.querySelectorAll('[data-dg-theme-toggle-label]').forEach(function (node) {
            node.textContent = nextLabel;
        });

        document.querySelectorAll('[data-dg-theme-toggle-button]').forEach(function (node) {
            node.setAttribute('aria-label', nextLabel);
            node.innerHTML = themeToggleMarkup(theme, buttonLabel);
        });
    }

    function showToast(message, tone) {
        if (!message) {
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
        if (!document.body || !document.body.classList.contains('login')) {
            return;
        }

        if (document.querySelector('[data-dg-theme-toggle-button]')) {
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
                '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';

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
            '<span>' + escapeHtml((config.labels && config.labels.applyLanguage) || 'تغییر') + '</span>';
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

    function bindLoginValidation() {
        if (!document.body || !document.body.classList.contains('login')) {
            return;
        }

        var form = document.querySelector('#loginform');
        var username = document.querySelector('#user_login');
        var password = document.querySelector('#user_pass');
        var visibilityButton = document.querySelector('.wp-hide-pw');
        var usernameLabel = document.querySelector('label[for="user_login"]');

        if (!form || !username || !password) {
            return;
        }

        form.setAttribute('novalidate', 'novalidate');
        username.placeholder = '0912... / name@example.com / username';

        if (usernameLabel) {
            usernameLabel.textContent = 'نام کاربری، ایمیل یا شماره موبایل';
        }

        if (visibilityButton) {
            visibilityButton.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            visibilityButton.addEventListener('click', function () {
                window.setTimeout(function () {
                    syncPasswordToggle();
                    password.focus({ preventScroll: true });
                }, 0);
            });
            syncPasswordToggle();
        }

        form.addEventListener('submit', function (event) {
            if (!String(username.value || '').trim()) {
                event.preventDefault();
                showToast((config.labels && config.labels.requiredUsername) || 'نام کاربری، ایمیل یا شماره موبایل را وارد کنید.', 'warning');
                username.focus();
                return;
            }

            if (!String(password.value || '').trim()) {
                event.preventDefault();
                showToast((config.labels && config.labels.requiredPassword) || 'رمز عبور را وارد کنید.', 'warning');
                password.focus();
                return;
            }

            if (window.WFLSVars && window.WFLSVars.useCAPTCHA === '1' && !document.querySelector('iframe[title="reCAPTCHA"]')) {
                showToast((config.labels && config.labels.recaptchaUnavailable) || 'محافظت ورود هنوز کامل بارگذاری نشده است.', 'warning');
            }
        });
    }

    function bindErrorHandling() {
        window.addEventListener('error', function () {
            showToast((config.labels && config.labels.runtimeError) || 'یک خطای غیرمنتظره در صفحه رخ داد.', 'warning');
        });

        window.addEventListener('unhandledrejection', function () {
            showToast((config.labels && config.labels.runtimeError) || 'یک خطای غیرمنتظره در صفحه رخ داد.', 'warning');
        });

        if (window.jQuery) {
            window.jQuery(document).ajaxError(function () {
                showToast((config.labels && config.labels.requestError) || 'یک درخواست پس زمینه با خطا روبه رو شد.', 'error');
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
        observeInlineMessages();
        bindErrorHandling();
        enableRipples();
    });
})();
