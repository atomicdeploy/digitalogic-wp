(function () {
    var config = window.DigitalogicBranding || {};
    var storageKey = config.storageKey || 'digitalogic-admin-theme';
    var root = document.documentElement;
    var lastPersistedTheme = config.initialTheme === 'dark' ? 'dark' : 'light';
    var loadingFailSafeTimer = null;
    var digitsSmsWaits = new WeakMap();
    var digitsOtpEditors = new WeakMap();
    var digitsResendTimers = new WeakMap();
    var digitsEmailChecks = new WeakMap();
    var digitsSmsSelections = new WeakSet();
    var authRecovery = false;
    var authSocket = null;
    var authSocketSequence = 0;
    var authSocketPending = new Map();

    function openAuthSocket() {
        var settings = config.authWebSocket;
        if (!settings || !settings.enabled || !window.WebSocket) {
            return null;
        }
        if (authSocket && authSocket.readyState < 2) {
            return authSocket;
        }
        var url = new window.URL(settings.url, window.location.href);
        if (url.protocol !== 'wss:' || url.host !== window.location.host) {
            return null;
        }
        url.searchParams.set(settings.token ? 'token' : 'nonce', settings.token || settings.nonce);
        var socket = new window.WebSocket(url);
        authSocket = socket;
        socket.addEventListener('message', function (event) {
            try {
                var response = JSON.parse(event.data);
                var pending = authSocketPending.get(response.id);
                if (pending && pending.socket === socket) {
                    pending.finish(response.success ? response.data : null);
                }
            } catch (error) { /* Other events are irrelevant to this request. */ }
        });
        socket.addEventListener('close', function () {
            authSocketPending.forEach(function (pending) {
                if (pending.socket === socket) {
                    pending.finish(null);
                }
            });
        });
        return socket;
    }

    function authSocketRequest(command, data) {
        return new Promise(function (resolve) {
            var socket;
            try { socket = openAuthSocket(); } catch (error) { resolve(null); return; }
            if (!socket) {
                resolve(null);
                return;
            }
            var id = 'auth-' + (++authSocketSequence);
            var timeout = window.setTimeout(function () { finish(null); }, 1500);
            function finish(result) {
                window.clearTimeout(timeout);
                socket.removeEventListener('open', send);
                authSocketPending.delete(id);
                resolve(result);
            }
            function send() {
                try { socket.send(JSON.stringify({ id: id, command: command, data: data })); }
                catch (error) { finish(null); }
            }
            authSocketPending.set(id, { socket: socket, finish: finish });
            if (socket.readyState === 1) {
                send();
            } else {
                socket.addEventListener('open', send, { once: true });
            }
        });
    }

    // The first call challenge sets a new HttpOnly cookie. Reconnect so the
    // server sees it in the handshake rather than sharing it with JavaScript.
    window.DigitalogicAuthRealtime = {
        refreshBinding: function () { if (authSocket) { authSocket.close(); authSocket = null; } },
        callStatus: function (id, proof) {
            return authSocketRequest('digitalogic_call_status', { challenge_id: id, csrf_token: proof });
        }
    };
    var feedbackSelector = [
        '#login_error',
        '.notice-error',
        '.error',
        '.notice-warning',
        '.update-nag',
        '.digits-form_message',
        '.digits-form_message_text',
        '.digits-form_error',
        '.digits-form_error_text',
        '.digits_error',
        '.digits-error',
        '.digits_notice',
        '.digits-login-error',
        '.digits_login_error',
        '.dig_error',
        '.dig_notice',
        '.dig_error_message',
        '.dig_notice_message',
        '.dig_popmessage',
        '.dig_ma-box',
        '.woocommerce-error',
        '.woocommerce-message'
    ].join(',');

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

    function isPersianLocale() {
        var locale = (document.documentElement.lang || navigator.language || '').toLowerCase();
        return locale.indexOf('fa') === 0 || !!(document.body && document.body.classList.contains('locale-fa-ir'));
    }

    function cleanText(value, fallback) {
        if (value === undefined || value === null) {
            return fallback || '';
        }

        var text = String(value).replace(/\s+/g, ' ').trim();
        if (!text || text.toLowerCase() === 'undefined') {
            return fallback || '';
        }

        return text;
    }

    function readStoredTheme() {
        try {
            return window.localStorage ? window.localStorage.getItem(storageKey) : null;
        } catch (error) {
            return null;
        }
    }

    function currentTheme() {
        var attr = root.getAttribute('data-dg-theme');
        if (attr === 'light' || attr === 'dark') {
            return attr;
        }

        if (config.initialTheme === 'light' || config.initialTheme === 'dark') {
            return config.initialTheme;
        }

        return 'light';
    }

    function themeToggleMarkup(theme, label) {
        var icon = theme === 'dark' ? 'dashicons-lightbulb' : 'dashicons-hidden';

        return '<span class="dashicons ' + icon + '" aria-hidden="true"></span>' +
            '<span class="dg-toggle-text">' + escapeHtml(label) + '</span>';
    }

    function broadcastTheme(theme) {
        try {
            if (window.localStorage) {
                window.localStorage.setItem(storageKey, theme);
                window.localStorage.setItem(storageKey + ':updated', String(Date.now()));
            }
        } catch (error) {}
    }

    function persistTheme(theme) {
        if (!config.themeAjaxAction || !config.themeNonce || lastPersistedTheme === theme) {
            return;
        }

        var body = new URLSearchParams();
        body.set('action', config.themeAjaxAction);
        body.set('nonce', config.themeNonce);
        body.set('theme', theme);

        lastPersistedTheme = theme;

        window.fetch(config.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(function(response) {
            return response.json();
        }).then(function(json) {
            if (!json || !json.success || !json.data) {
                return;
            }

            config.initialTheme = json.data.theme || theme;
            config.adminColor = json.data.admin_color || config.adminColor;
        }).catch(function() {
            lastPersistedTheme = '';
        });
    }

    function setTheme(theme, persist) {
        var value = theme === 'dark' ? 'dark' : 'light';
        root.setAttribute('data-dg-theme', value);

        if (document.body) {
            document.body.setAttribute('data-dg-theme', value);
        }

        if (persist !== false) {
            broadcastTheme(value);
            persistTheme(value);
        }

        syncToggleLabels(value);
    }

    function toggleTheme(event) {
        if (event) {
            event.preventDefault();
        }

        setTheme(currentTheme() === 'dark' ? 'light' : 'dark');
    }

    function migrateLegacyStoredTheme() {
        var stored = readStoredTheme();
        var isDigitalogicScheme = config.adminColor === 'digitalogic-light' || config.adminColor === 'digitalogic-dark';

        if (!isDigitalogicScheme && (stored === 'light' || stored === 'dark') && stored !== currentTheme()) {
            setTheme(stored);
        }
    }

    function bindThemeStorageSync() {
        window.addEventListener('storage', function (event) {
            if (event.key !== storageKey) {
                return;
            }

            if (event.newValue === 'light' || event.newValue === 'dark') {
                setTheme(event.newValue, false);
                config.initialTheme = event.newValue;
                lastPersistedTheme = event.newValue;
            }
        });
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
        message = cleanText(message, '');

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

            if (document.body.classList.contains('login') && node.closest(
                '#login, .dig_popmessage, .dig_error_message, .dig_notice_message, .dig_ma-box'
            )) { return; }

            var text = cleanText(node.textContent, '');
            if (!text || seen.has(text)) {
                return;
            }

            seen.add(text);
            clearLoading();
            showToast(text, node.matches('#login_error, .notice-error, .error') ? 'error' : 'warning');
        }

        document.querySelectorAll(feedbackSelector).forEach(relayText);

        var observer = new MutationObserver(function () {
            document.querySelectorAll(feedbackSelector).forEach(relayText);
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    function injectLoginToggle() {
        if (!config.showLoginThemeSwitcher || !document.body?.classList.contains('login') || document.querySelector('[data-dg-theme-toggle-button]')) {
            return;
        }

        var login = document.getElementById('login');
        if (!login) { return; }
        var controls = document.createElement('div');
        controls.className = 'dg-login-preferences';
        login.after(controls);
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'dg-theme-toggle-button';
        button.setAttribute('data-dg-theme-toggle-button', 'true');
        button.addEventListener('click', toggleTheme);
        controls.append(button);
        var language = document.querySelector('.language-switcher');
        if (language) { controls.append(language); }
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

        function submitLanguageForm() {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            nativeSubmit.click();
        }

        var picker = document.createElement('div');
        picker.className = 'dg-language-picker';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'button dg-language-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', 'dg-language-menu');
        trigger.setAttribute('aria-label', getLabel('language', 'Language'));

        var menu = document.createElement('div');
        menu.id = 'dg-language-menu';
        menu.className = 'dg-language-menu';
        menu.setAttribute('role', 'listbox');
        menu.setAttribute('aria-hidden', 'true');

        function syncChevron(open) {
            var chevron = trigger.querySelector('.dg-language-chevron');
            if (!chevron) {
                return;
            }

            chevron.classList.toggle('dashicons-arrow-up-alt2', open);
            chevron.classList.toggle('dashicons-arrow-down-alt2', !open);
        }

        function closeMenu(restoreFocus) {
            picker.classList.remove('is-open');
            menu.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            syncChevron(false);
            if (restoreFocus) {
                trigger.focus({ preventScroll: true });
            }
        }

        function optionButtons() {
            return Array.prototype.slice.call(menu.querySelectorAll('.dg-language-option'));
        }

        function focusOption(index) {
            var options = optionButtons();
            if (!options.length) {
                return;
            }

            var normalized = (index + options.length) % options.length;
            options[normalized].focus({ preventScroll: true });
        }

        function openMenu(focusIndex) {
            picker.classList.add('is-open');
            menu.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            syncChevron(true);
            if (typeof focusIndex === 'number') {
                focusOption(focusIndex);
            }
        }

        function syncSelection() {
            var selected = select.options[select.selectedIndex];
            var text = selected ? selected.text : 'فارسی';
            trigger.innerHTML = '<span class="dashicons dashicons-translation" aria-hidden="true"></span>' +
                '<span class="dg-select-text">' + escapeHtml(text) + '</span>' +
                '<span class="dashicons dg-language-chevron dashicons-arrow-down-alt2" aria-hidden="true"></span>';

            menu.querySelectorAll('.dg-language-option').forEach(function (option) {
                var active = option.getAttribute('data-value') === select.value;
                option.classList.toggle('is-active', active);
                option.setAttribute('aria-selected', active ? 'true' : 'false');
                option.setAttribute('tabindex', active ? '0' : '-1');
            });
            syncChevron(picker.classList.contains('is-open'));
        }

        trigger.addEventListener('click', function () {
            if (!picker.classList.contains('is-open')) {
                var selectedIndex = Math.max(0, select.selectedIndex);
                openMenu(selectedIndex);
            } else {
                closeMenu(false);
            }
        });

        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                openMenu(event.key === 'ArrowUp' ? optionButtons().length - 1 : Math.max(0, select.selectedIndex));
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeMenu(true);
            }
        });

        Array.prototype.slice.call(select.options).forEach(function (option, index) {
            var item = document.createElement('button');
            item.type = 'button';
            item.id = 'dg-language-option-' + index;
            item.className = 'dg-language-option';
            item.setAttribute('role', 'option');
            item.setAttribute('data-value', option.value);
            item.setAttribute('tabindex', '-1');
            item.innerHTML = '<span class="dashicons dashicons-translation" aria-hidden="true"></span>' +
                '<span>' + escapeHtml(option.text) + '</span>';
            item.addEventListener('click', function () {
                select.value = option.value;
                syncSelection();
                closeMenu(false);
                submitLanguageForm();
            });
            menu.appendChild(item);
        });

        menu.addEventListener('keydown', function (event) {
            var options = optionButtons();
            var current = options.indexOf(document.activeElement);
            var target = current;

            if (event.key === 'ArrowDown') {
                target = current + 1;
            } else if (event.key === 'ArrowUp') {
                target = current - 1;
            } else if (event.key === 'Home') {
                target = 0;
            } else if (event.key === 'End') {
                target = options.length - 1;
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeMenu(true);
                return;
            } else if ((event.key === 'Enter' || event.key === ' ') && current >= 0) {
                event.preventDefault();
                options[current].click();
                return;
            } else {
                return;
            }

            event.preventDefault();
            focusOption(target);
        });

        select.addEventListener('change', submitLanguageForm);

        picker.appendChild(trigger);
        picker.appendChild(menu);
        form.insertBefore(picker, nativeSubmit);

        form.querySelectorAll('label, select, input[type="submit"], button[type="submit"]').forEach(function (node) {
            if (node !== trigger && node !== menu && !picker.contains(node)) {
                node.classList.add('dg-visually-hidden');
                node.setAttribute('tabindex', '-1');
            }
        });

        document.addEventListener('click', function (event) {
            if (!picker.contains(event.target)) {
                closeMenu(false);
            }
        });

        syncSelection();
    }

    function injectLateLoginOverrides() {
        if (!document.body || !document.body.classList.contains('login') || document.getElementById('digitalogic-login-late-overrides')) {
            return;
        }

        var style = document.createElement('style');
        style.id = 'digitalogic-login-late-overrides';
        style.textContent = [
            'body.login .dg-digits-login-shell :is(form, .digits_form_index_section, .digits_secure_modal_box, .digits_ui, .digits-form_container, .digits-form_page, .digits-form_wrapper, .digits_modal_box, .digits2_box, .digits-form_login, .digits-form_body, .digits-form_body_wrapper, .digits-form_tab_wrapper, .digits-form_tab_container, .digits-form_tab_body, .digits_signup_form_step, .digits-form_footer){background:transparent!important;border:0!important;box-shadow:none!important;backdrop-filter:none!important;overflow:visible!important;}',
            'body.login .dg-digits-login-shell :is(form, .digits_form_index_section, .digits-form_wrapper, .digits_modal_box, .digits2_box){margin:0!important;padding:0!important;overflow:visible!important;}',
            'body.login .dg-digits-login-shell :is(.digits-form_tabs, .digits-form_tab-bar),body.login :is(.dg-digits-login-shell,.dg-digits-register-shell) .digits_captcha_row[hidden]{display:none!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity .digits-form_tab_body:not(.digits-tab_active),body.login .dg-digits-login-shell.dg-digits-auto-identity .digits-form_tab_body[hidden]{display:none!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity .digits-form_tab_body.digits-tab_active{display:block!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity:not([data-dg-identity="phone"]) .digits-form_countrycode{display:none!important;}',
            'body.login .dg-digits-login-shell :is(.digits-form_input_row, .digits-form_input){border-color:var(--dg-border)!important;border-radius:16px!important;color:var(--dg-text)!important;}',
            'body.login .dg-digits-login-shell .digits-form_input_row:not(.digits-form_rememberme,:has(.dg-otp-editor,.dg-password-field)){min-height:58px!important;margin-block:11px!important;padding-inline:14px!important;background:var(--dg-input-bg)!important;}',
            'body.login .dg-digits-login-shell .digits-form_rememberme:not([hidden]){display:flex!important;align-items:center!important;justify-content:flex-start!important;min-height:auto!important;height:auto!important;margin-block:4px 24px!important;padding:0!important;border:0!important;background:transparent!important;overflow:visible!important;}',
            'body.login .dg-digits-login-shell .digits-form_rememberme :is(.digits-form_input,.dig_opt_mult_con,.dig_opt_mult,label,.dig_input_wrapper,.dig_input_wrapper>div){display:inline-flex!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important;width:auto!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important;background:transparent!important;line-height:1.35!important;}',
            'body.login .dg-digits-login-shell .digits-form_rememberme .dig_input_wrapper::before,body.login .dg-digits-login-shell .digits-form_rememberme .dig_input_wrapper::after,body.login .dg-digits-login-shell .digits-form_input_row .digits-field-type_tac .dig_input_wrapper::before,body.login .dg-digits-login-shell .digits-form_input_row .digits-field-type_tac .dig_input_wrapper::after,html[dir="rtl"] body.login .dg-digits-login-shell .digits-form_input_row .digits-field-type_tac .dig_input_wrapper::before,html[dir="rtl"] body.login .dg-digits-login-shell .digits-form_input_row .digits-field-type_tac .dig_input_wrapper::after{content:none!important;display:none!important;opacity:0!important;visibility:hidden!important;}',
            'body.login .dg-digits-login-shell .digits-form_input input:not(.digits_login_remember_me,.dg-otp-slot input){min-height:48px!important;margin:0!important;border:0!important;background:transparent!important;color:var(--dg-text)!important;box-shadow:none!important;font-family:var(--dg-font)!important;}',
            'body.login .dg-digits-login-shell .digits-form_rememberme .digits_login_remember_me{appearance:none!important;-webkit-appearance:none!important;display:inline-grid!important;place-items:center!important;visibility:visible!important;opacity:1!important;inline-size:22px!important;block-size:22px!important;width:22px!important;height:22px!important;min-width:22px!important;min-height:22px!important;max-width:22px!important;max-height:22px!important;flex:0 0 22px!important;margin:0!important;padding:0!important;line-height:1!important;border:1px solid var(--dg-border)!important;border-radius:8px!important;background:color-mix(in srgb,var(--dg-panel-soft) 72%,transparent)!important;box-shadow:inset 0 1px 0 color-mix(in srgb,#ffffff 10%,transparent),var(--dg-shadow-soft)!important;transform:scale(1)!important;transition:background-color .18s ease,background-image .18s ease,border-color .18s ease,box-shadow .18s ease,transform .18s cubic-bezier(.2,.8,.2,1)!important;}',
            'body.login .dg-digits-login-shell .digits-form_rememberme .digits_login_remember_me::before{content:""!important;display:block!important;visibility:visible!important;width:6px!important;height:11px!important;margin:-1px 0 0!important;border:solid #fff!important;border-width:0 2px 2px 0!important;opacity:0;transform:rotate(45deg) scale(.62);transform-origin:center;transition:opacity .16s ease,transform .18s cubic-bezier(.2,.8,.2,1);}',
            'body.login .dg-digits-login-shell .digits-form_rememberme .digits_login_remember_me:active,body.login #rememberme:active{transform:scale(.92)!important;}',
            'body.login .dg-digits-login-shell .digits-form_rememberme .digits_login_remember_me:checked,body.login #rememberme:checked{border-color:transparent!important;background-color:var(--dg-accent)!important;background-image:linear-gradient(135deg,var(--dg-accent),var(--dg-accent-strong))!important;background-position:center!important;background-repeat:no-repeat!important;background-size:100% 100%!important;box-shadow:0 10px 24px color-mix(in srgb,var(--dg-accent-strong) 28%,transparent)!important;transform:scale(1.06)!important;}',
            'body.login .dg-digits-login-shell .digits-form_rememberme .digits_login_remember_me:checked::before{opacity:1;transform:rotate(45deg) scale(1);}',
            'body.login .dg-digits-login-shell .digits-form_rememberme .digits_login_remember_me::after{content:none!important;display:none!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity .digits-mobile_wrapper{direction:ltr!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity[data-dg-identity="email"] .digits-form_input_row:has(input[name="digits_email"]){direction:ltr!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity[data-dg-identity="phone"] .digits-mobile_wrapper{display:flex!important;align-items:center!important;gap:10px!important;width:100%!important;direction:ltr;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity[data-dg-identity="phone"] .digits-mobile_wrapper:before{content:none!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity[data-dg-identity="phone"] .digits-form_countrycode{position:relative!important;z-index:60!important;display:inline-flex!important;min-width:0!important;min-height:40px!important;justify-content:center!important;padding-inline:0 6px!important;border:0!important;border-inline-end:1px solid var(--dg-border)!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity[data-dg-identity="phone"] .digits-form_countrycode input{width:42px!important;min-height:0!important;height:auto!important;padding:0!important;line-height:1!important;text-align:left!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity[data-dg-identity="phone"] .digits-mobile_wrapper>.digits-form_input:not(.digits-form_countrycode){flex:1 1 auto!important;min-width:0!important;width:auto!important;}',
            'body.login .dg-digits-login-shell.dg-digits-auto-identity[data-dg-identity="phone"] .mobile_field{flex:1 1 auto!important;min-width:0!important;width:100%!important;direction:ltr!important;text-align:left!important;}',
            'body.login .dg-digits-login-shell :is(.digits-form_button,.digits-form_submit-btn):not(.digits-form_passwordless_login){min-height:56px!important;border:0!important;border-radius:18px!important;background:linear-gradient(135deg,var(--dg-accent),var(--dg-accent-strong))!important;color:#fff!important;font-family:var(--dg-font)!important;font-size:16px!important;font-weight:800!important;overflow:hidden!important;}',
            'body.login .dg-digits-login-shell :is(.digits-form_button,.digits-form_submit,.digits-form_submit-btn).is-loading .digits-form_button_ic{display:none!important;inline-size:0!important;flex-basis:0!important;opacity:0!important;visibility:hidden!important;}',
            'body.login .dg-digits-login-shell .digits-social-logins-box{background:transparent!important;border-top:0!important;border-color:transparent!important;}',
            'body.login .dg-digits-login-shell .digits-social-btn{min-height:56px!important;padding:12px 18px!important;border-color:var(--dg-border)!important;border-radius:18px!important;background:var(--dg-panel)!important;color:var(--dg-text)!important;font-size:16px!important;}',
            'body.login .dg-digits-login-shell .digits-social-btn:hover{border-color:var(--dg-border-strong)!important;background:var(--dg-panel-strong)!important;}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function normalizeDigits(value) {
        var map = {
            '\u06f0': '0', '\u06f1': '1', '\u06f2': '2', '\u06f3': '3', '\u06f4': '4',
            '\u06f5': '5', '\u06f6': '6', '\u06f7': '7', '\u06f8': '8', '\u06f9': '9',
            '\u0660': '0', '\u0661': '1', '\u0662': '2', '\u0663': '3', '\u0664': '4',
            '\u0665': '5', '\u0666': '6', '\u0667': '7', '\u0668': '8', '\u0669': '9'
        };

        return String(value || '').replace(/[\u06f0-\u06f9\u0660-\u0669]/g, function (digit) {
            return map[digit] || digit;
        });
    }

    function classifyIdentity(value) {
        var normalized = normalizeDigits(value).trim();
        var compact = normalized.replace(/[\s().-]+/g, '');
        var digits = compact.replace(/\D/g, '');

        if (!normalized) {
            return 'unknown';
        }

        if (normalized.indexOf('@') !== -1 || /^[^\s@]+@[^\s@]*$/.test(normalized)) {
            return 'email';
        }

        if (phonePartsFromValue(normalized).type === 'phone') {
            return 'phone';
        }

        return 'username';
    }

    function emailDomain(value) {
        if (value.length > 254 || !/^[a-z0-9.!#$%&'*+/=?^_`{|}~-]{1,64}@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i.test(value)) {
            return '';
        }
        var local = value.split('@', 1)[0];
        return /(^\.|\.\.|\.$)/.test(local) ? '' : value.slice(local.length + 1).toLowerCase();
    }

    function phonePartsFromValue(value, selectedCountry) {
        var normalized = normalizeDigits(value).trim();
        var compact = normalized.replace(/[\s().-]+/g, '');
        var country = selectedCountry || '+98';
        // The picker already displays +98. Wait for the second digit so a typed 00 can still start an international number.
        var iranTrunkPrefix = country === '+98' && /^0[1-9]\d*$/.test(compact);
        if (window.libphonenumber && /^(?:\+\d*|\d+)$/.test(compact)) {
            var explicit = /^(?:\+|00)/.test(compact);
            var candidate = compact.replace(/^00/, '+');
            var countries = window.libphonenumber.getCountries();
            var region = country === '+98' ? 'IR' : countries.find(function (code) {
                return '+' + window.libphonenumber.getCountryCallingCode(code) === country;
            });
            if (!explicit && !region) { candidate = country + candidate; }
            var parsed = window.libphonenumber.parsePhoneNumberFromString(candidate, region);
            if (parsed && parsed.isValid()) {
                return { type: 'phone', national: parsed.nationalNumber, normalized: normalized, valid: true,
                    countryCode: '+' + parsed.countryCallingCode, kind: parsed.getType(), e164: parsed.number };
            }
            return { type: 'phone', national: iranTrunkPrefix ? compact.slice(1) : compact,
                normalized: normalized, valid: false, countryCode: country };
        }
        var numeric = compact.replace(/[^\d+]/g, '');
        var digits = numeric.replace(/\D/g, '');
        var national = digits;
        var explicitInternational = compact.charAt(0) === '+' || compact.indexOf('00') === 0;
        var matchedCountry = null;

        if (!normalized || normalized.indexOf('@') !== -1 || /[A-Za-z_]/.test(compact) || (!/^\+?\d+$/.test(numeric) && !/^00\d+$/.test(numeric))) {
            return { type: 'not-phone', national: '', normalized: normalized, valid: false, countryCode: '' };
        }

        if (digits.indexOf('0098') === 0) {
            national = digits.slice(4);
            matchedCountry = '+98';
        } else if (digits.indexOf('98') === 0 && (explicitInternational || digits.length > 10)) {
            national = digits.slice(2);
            matchedCountry = '+98';
        } else if (iranTrunkPrefix) {
            national = digits.slice(1);
            matchedCountry = '+98';
        } else if (explicitInternational) {
            var internationalDigits = compact.indexOf('00') === 0 ? digits.slice(2) : digits;
            matchedCountry = dgCountryOptions
                .map(function (option) {
                    return { option: option, digits: option.code.replace(/\D/g, '') };
                })
                .filter(function (item) {
                    return internationalDigits.indexOf(item.digits) === 0;
                })
                .sort(function (a, b) {
                    return b.digits.length - a.digits.length;
                })[0];

            if (matchedCountry) {
                national = internationalDigits.slice(matchedCountry.digits.length);
                matchedCountry = matchedCountry.option.code;
            }
        }

        if (/^9\d{0,9}$/.test(national)) {
            return {
                type: 'phone',
                national: national.slice(0, 10),
                normalized: normalized,
                valid: /^9\d{9}$/.test(national),
                countryCode: matchedCountry || '+98'
            };
        }

        if (explicitInternational && national.length > 0) {
            return {
                type: 'phone',
                national: national.slice(0, 18),
                normalized: normalized,
                valid: national.length >= 5,
                countryCode: matchedCountry || ''
            };
        }

        return { type: 'not-phone', national: '', normalized: normalized, valid: false, countryCode: '' };
    }

    function setInputValuePreservingSelection(input, value, previousValue) {
        var start = 0;
        var end = 0;
        var direction = 'none';
        var delta = String(value).length - String(previousValue || '').length;

        try {
            start = input.selectionStart;
            end = input.selectionEnd;
            direction = input.selectionDirection || 'none';
        } catch (error) {
            start = null;
            end = null;
        }

        input.value = value;

        if (typeof start === 'number' && typeof end === 'number') {
            try {
                input.setSelectionRange(Math.max(0, start + delta), Math.max(0, end + delta), direction);
            } catch (error) {
                // Some input states do not support selection restoration.
            }
        }
    }

    function sanitizeIdentityValue(value) {
        var normalized = normalizeDigits(value).replace(/\s/gu, '');
        return normalized.includes('@') ? normalized.toLowerCase() : normalized;
    }

    function normalizePhoneOnBlur(input) {
        var phone = phonePartsFromValue(input.value);
        if (phone.type !== 'phone') {
            return;
        }

        if (phone.national) {
            input.value = phone.national;
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
            var normalized = sanitizeIdentityValue(original);
            var phone = phonePartsFromValue(normalized);

            if (phone.type === 'phone') {
                normalized = phone.national;
            }

            if (original !== normalized) {
                setInputValuePreservingSelection(input, normalized, original);
            }

            var type = phone.type === 'phone' ? 'phone' : classifyIdentity(input.value);
            var meta = {
                phone: {
                    icon: 'dashicons-smartphone',
                    text: 'IR +98',
                    label: getLabel('phoneIdentity', 'موبایل ایران'),
                    inputMode: 'tel',
                    autocomplete: 'tel-national'
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

            if (!meta) {
                meta = {
                    icon: 'dashicons-id',
                    text: getLabel('identity', 'ورود'),
                    label: getLabel('identity', 'ورود'),
                    inputMode: 'text',
                    autocomplete: 'username'
                };
            }

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
        if (authPageAction() !== 'lostPassword') {
            setLabel(
                document.querySelector('label[for="user_login"]'),
                getLabel('usernameEmailPhone', 'نام کاربری، ایمیل یا شماره موبایل'),
                'dashicons-id'
            );
        }
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

    var passwordFieldSelector = 'input:is([type="password"], .dg-password-input, [autocomplete="current-password"], [autocomplete="new-password"], #user_pass, #pass1):not([type="hidden"])';

    function syncPasswordToggle(button, input) {
        var isVisible = input.type === 'text';
        var label = isVisible
            ? getLabel('hidePassword', 'Hide password')
            : getLabel('showPassword', 'Show password');
        button.disabled = input.disabled;
        button.setAttribute('aria-label', label);
        putAuthIcon(button, isVisible ? 'eyeOff' : 'eye');
    }

    function mountPasswordField(input) {
        var parent = input.parentElement;
        if (parent.classList.contains('dg-password-field')) {
            syncPasswordToggle(parent.querySelector('.dg-password-toggle'), input);
            return;
        }

        // Keep the provider's input, including its name, validation and event handlers.
        var providerField = input.closest('.wp-pwd, .digits_password_inp_row, .password-input-wrapper') || parent;
        providerField.querySelectorAll('.wp-hide-pw, .digits_password_eye').forEach(function (button) { button.remove(); });
        var field = document.createElement('div');
        field.className = 'dg-password-field';
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'dg-password-toggle';
        if (input.id) { toggle.setAttribute('aria-controls', input.id); }
        input.classList.add('dg-password-input');
        input.dir = 'ltr';
        input.before(field);
        field.append(input, toggle);
        putAuthIcon(field, 'lock');
        syncPasswordToggle(toggle, input);

        toggle.addEventListener('pointerdown', function (event) {
            if (event.button === 0 && document.activeElement === input) {
                event.preventDefault();
            }
        });
        toggle.addEventListener('click', function () {
            if (input.disabled || toggle.getAttribute('aria-disabled') === 'true') { return; }
            var restore = capturePasswordSelection(input);
            input.type = input.type === 'password' ? 'text' : 'password';
            syncPasswordToggle(toggle, input);
            restore();
        });
    }

    function observePasswordFields() {
        var login = document.querySelector('#login');
        if (!login) { return; }
        login.querySelectorAll(passwordFieldSelector).forEach(mountPasswordField);
        new MutationObserver(function (mutations) {
            var fields = new Set();
            mutations.forEach(function (mutation) {
                if (mutation.type === 'attributes' && mutation.target.matches(passwordFieldSelector)) {
                    fields.add(mutation.target);
                }
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1 || node.matches('.dg-password-field')) { return; }
                    if (node.matches(passwordFieldSelector)) { fields.add(node); }
                    node.querySelectorAll(passwordFieldSelector).forEach(function (input) { fields.add(input); });
                });
            });
            fields.forEach(function (input) {
                if (login.contains(input)) { mountPasswordField(input); }
            });
        }).observe(login, { childList: true, subtree: true, attributes: true, attributeFilter: ['type', 'disabled'] });
    }

    function lockPendingForm(form, button) {
        if (!form || form.dataset.dgPending === 'true') {
            return;
        }

        form.dataset.dgPending = 'true';
        form.classList.add('dg-form-pending');
        form.setAttribute('aria-busy', 'true');

        form.querySelectorAll('input, select, textarea, button').forEach(function (field) {
            var type = String(field.type || '').toLowerCase();

            field.setAttribute('aria-disabled', 'true');
            field.dataset.dgLockedByBranding = 'true';

            if (field === button || type === 'hidden') {
                return;
            }

            if ('readOnly' in field && type !== 'checkbox' && type !== 'radio' && type !== 'submit' && type !== 'button') {
                field.dataset.dgPreviousReadonly = field.readOnly ? 'true' : 'false';
                field.readOnly = true;
            }
        });

        if (button && !button.disabled) {
            button.dataset.dgDisabledByBranding = 'true';
            button.disabled = true;
        }
    }

    function unlockPendingForms() {
        document.querySelectorAll('form.dg-form-pending, form[data-dg-pending="true"]').forEach(function (form) {
            form.classList.remove('dg-form-pending');
            form.removeAttribute('aria-busy');
            delete form.dataset.dgPending;

            form.querySelectorAll('[data-dg-locked-by-branding="true"]').forEach(function (field) {
                if (field.dataset.dgPreviousReadonly === 'false') {
                    field.readOnly = false;
                }

                delete field.dataset.dgPreviousReadonly;
                delete field.dataset.dgLockedByBranding;
                field.removeAttribute('aria-disabled');
            });
        });

        document.querySelectorAll('[data-dg-disabled-by-branding="true"]').forEach(function (button) {
            button.disabled = button.dataset.dgIdentityBlocked === 'true';
            delete button.dataset.dgDisabledByBranding;
        });
    }

    function scheduleLoadingFailSafe() {
        window.clearTimeout(loadingFailSafeTimer);
        loadingFailSafeTimer = window.setTimeout(function () {
            var button = document.querySelector('[data-dg-loading="true"]');
            if (!button) {
                return;
            }

            clearLoading();
            var message = digitsRequestFailure(null, 'timeout');
            var form = button.closest('form');
            if (form) { showDigitsInlineMessage(form, message, 'error'); }
            else { showToast(message, 'error'); }
        }, 35000);
    }

    function markLoading(button, disable, form) {
        if (!button || button.dataset.dgLoading === 'true') {
            return;
        }

        button.dataset.dgLoading = 'true';
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        document.body.classList.add('dg-login-submitting');
        lockPendingForm(form || button.closest('form'), button);
        scheduleLoadingFailSafe();

        if (disable) {
            button.disabled = true;
        }
    }

    function clearLoading() {
        window.clearTimeout(loadingFailSafeTimer);
        document.body.classList.remove('dg-login-submitting');
        unlockPendingForms();
        document.querySelectorAll('.digits_form_processing').forEach(function (form) {
            form.classList.remove('digits_form_processing');
        });
        document.querySelectorAll('[data-dg-loading="true"]').forEach(function (button) {
            button.dataset.dgLoading = 'false';
            button.classList.remove('is-loading');
            button.removeAttribute('aria-busy');
            if (button.matches('#wp-submit') || button.dataset.dgDisabledByBranding === 'true') {
                button.disabled = button.dataset.dgIdentityBlocked === 'true';
            }
        });
    }

    function isDigitsSecureForm(form) {
        return form && form.matches('form.digits_form_index_section, form.digits-form, .digits-form_page form');
    }

    function isVisibleElement(node) {
        return !!(node && (node.offsetWidth || node.offsetHeight || node.getClientRects().length));
    }

    function digitsSubmitButton(form) {
        if (!form) {
            return null;
        }

        var buttons = Array.prototype.slice.call(form.querySelectorAll(':is(.digits-form_submit-btn, .digits-form_submit, [type="submit"]):not(.digits-form_passwordless_login)'));
        return buttons.find(function (button) {
            return isVisibleElement(button) && !button.hidden;
        }) || buttons[0] || null;
    }

    function responseMessage(payload, fallback) {
        var data = payload && payload.data !== undefined ? payload.data : payload;
        fallback = cleanText(fallback, '');

        if (typeof data === 'string') {
            return cleanText(data, fallback);
        }

        if (data && typeof data === 'object') {
            return cleanText(data.message || data.error || data.notice, fallback);
        }

        return fallback;
    }

    function showDigitsInlineMessage(form, message, tone) {
        message = cleanText(message, '');

        if (!message) {
            return;
        }

        var otp = form && visibleDigitsOtpInput(form);
        var otpRow = otp?.closest('.digits-form_input_row') || otp?.parentElement;
        var host = form && (form.querySelector('.digits-form_heading') || form.firstElementChild);
        var box = form && form.querySelector('.dg-digits-inline-message');

        if (!box && form) {
            box = document.createElement('div');
            box.className = 'dg-digits-inline-message';
            box.setAttribute('role', 'alert');

            if (!otpRow && host && host.parentNode) {
                host.parentNode.insertBefore(box, host.nextSibling);
            } else if (!otpRow) {
                form.prepend(box);
            }
        }

        if (box) {
            if (otpRow && box.previousElementSibling !== otpRow) { otpRow.after(box); }
            box.classList.toggle('dg-otp-feedback', !!otpRow);
            box.classList.toggle('dg-digits-inline-message--error', tone !== 'success');
            box.classList.toggle('dg-digits-inline-message--success', tone === 'success');
            box.textContent = message;
        }
    }

    function authRequestLabel(english, persian) {
        return isPersianLocale() ? persian : english;
    }

    function digitsRequestFailure(request, state) {
        var status = Number(request?.status) || 0;
        if (state === 'timeout' || status === 408 || status === 504) {
            return authRequestLabel('The sign-in service took too long to respond. Try again.', 'سرویس ورود به‌موقع پاسخ نداد. دوباره تلاش کنید.');
        }
        if (navigator.onLine === false) {
            return authRequestLabel('You’re offline. Reconnect to the internet and try again.', 'اینترنت قطع است. اتصال را برقرار کنید و دوباره تلاش کنید.');
        }
        if (status === 429) {
            return authRequestLabel('Too many attempts. Wait a few minutes before trying again.', 'تعداد تلاش‌ها بیش از حد مجاز است. چند دقیقه صبر کنید و دوباره تلاش کنید.');
        }
        if (status === 401 || status === 403) {
            return authRequestLabel('The sign-in request was refused (HTTP ' + status + '). Reload this page to start a fresh session.',
                'سرور درخواست ورود را نپذیرفت (HTTP ' + status + '). برای شروع نشست تازه، صفحه را دوباره بارگذاری کنید.');
        }
        if (status >= 400) {
            return authRequestLabel('The sign-in service returned HTTP ' + status + '. Try again in a moment.',
                'سرویس ورود با وضعیت HTTP ' + status + ' پاسخ داد. کمی بعد دوباره تلاش کنید.');
        }
        if (state === 'parsererror' || state === 'malformed') {
            return authRequestLabel('The sign-in service returned an unreadable response. Reload and try again.',
                'پاسخ سرویس ورود قابل پردازش نبود. صفحه را دوباره بارگذاری کنید و تلاش کنید.');
        }
        if (state === 'callback') {
            return authRequestLabel('The browser received the sign-in response but could not open the next step. Reload and try again.',
                'پاسخ ورود دریافت شد، اما مرورگر نتوانست مرحلهٔ بعد را باز کند. صفحه را دوباره بارگذاری کنید و تلاش کنید.');
        }
        return authRequestLabel('The browser could not reach the sign-in service. Check your connection and try again.',
            'مرورگر نتوانست به سرویس ورود وصل شود. اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.');
    }

    function digitsResponseFailure(form, payload, verifiesCode, sendsCode) {
        var detail = responseMessage(payload, '');
        if (!detail || /^(?:error|failed|undefined|null|false|خطا)[.!\s]*$/i.test(detail)
            || detail === window.dig_script?.ErrorPleasetryagainlater) {
            detail = verifiesCode
                ? authRequestLabel('The service did not confirm this code. Check the digits or request a new code.',
                    'سرویس ورود این کد را تأیید نکرد. رقم‌ها را بررسی کنید یا کد تازه بگیرید.')
                : sendsCode
                    ? authRequestLabel('The service did not send a verification code. Wait a moment and request it again.',
                        'سرویس ورود کد تأیید را ارسال نکرد. کمی صبر کنید و دوباره کد بگیرید.')
                    : authRequestLabel('The service did not complete this sign-in step. Check the details you entered and try again.',
                        'سرویس ورود این مرحله را تکمیل نکرد. اطلاعات واردشده را بررسی کنید و دوباره تلاش کنید.');
        }
        if (verifiesCode && !payload?.data?.notice) {
            digitsOtpEditors.get(visibleDigitsOtpInput(form))?.reject();
        }
        showDigitsInlineMessage(form, detail, payload?.data?.notice ? 'warning' : 'error');
    }

    function withoutDigitsToast(callback, context, args) {
        if (typeof callback !== 'function') { return; }
        // The provider callbacks also release its loading and captcha state. Keep those side effects.
        var names = ['showDigErrorMessage', 'showDigNoticeMessage'];
        var previous = names.map(function (name) { return window[name]; });
        names.forEach(function (name) { window[name] = function () {}; });
        try {
            return callback.apply(context, args);
        } finally {
            names.forEach(function (name, index) {
                if (previous[index] === undefined) { delete window[name]; }
                else { window[name] = previous[index]; }
            });
        }
    }

    function digitsIdentityValue(form) {
        if (!form) {
            return '';
        }

        var shell = form.closest('.dg-digits-login-shell');
        var emailInput = form.querySelector('input[name="digits_email"]');
        var phoneInput = form.querySelector('input[name="digits_phone"]');
        var mode = shell && shell.dataset ? shell.dataset.dgIdentity : '';
        var input = mode === 'email' ? emailInput : phoneInput;

        return normalizeDigits(input ? input.value : '').trim();
    }

    function digitsLoginRedirect(form) {
        var redirect = form && form.querySelector('input[name="digits_redirect_page"]');
        var value = redirect ? cleanText(redirect.value, '') : '';

        if (value.indexOf('//') === 0) {
            return window.location.protocol + value;
        }

        return value || window.location.href;
    }

    function finishBrandedDigitsLogin(form, data) {
        if (!data || !data.process || !form || !form.closest || !form.closest('.dg-digits-login-shell')
            || (data.process_type !== 'login' && !form.matches('.digits_register'))) {
            return;
        }

        // Digits has already authenticated the user. Enrollment is a separate action.
        data.setup_biometric = false;
        data.force_biometric = false;
        data.login_reg_success_msg = 0;
        data.delay = 0;

        try {
            var page = new window.URL(window.location.href);
            var destination = page.searchParams.get('redirect_to') || digitsLoginRedirect(form);
            var target = new window.URL(destination, page);
            if (target.origin === page.origin && !target.username && !target.password && target.pathname === '/wp-login.php'
                && target.searchParams.get('action') === 'openid-authenticate' && target.searchParams.get('client_id')
                && target.searchParams.get('response_type') === 'code') {
                data.redirect = target.href;
            } else if (page.searchParams.has('redirect_to') && target.origin === page.origin && !target.username && !target.password
                && !/^\/(?:login\/?|wp-login\.php)$/.test(target.pathname)) {
                data.redirect = target.href;
            }
        } catch (error) {
            // Keep the native Digits redirect when the return URL is malformed.
        }
    }

    function completeRecovery(data) {
        if (!data || !data.process || !authRecovery) { return false; }
        showRecoveryDialog(data.redirect);
        return true;
    }

    async function showRecoveryDialog(destination) {
        var endpoint = config.ajaxUrl || '/wp-admin/admin-ajax.php';
        async function post(action, fields) {
            var body = new URLSearchParams(fields);
            body.set('action', action);
            var response = await window.fetch(endpoint, { method: 'POST', credentials: 'same-origin', body: body });
            return response.json();
        }

        var next = new window.URL('/my-account/', window.location.origin);
        if (typeof destination === 'string' && destination && !['-1', '-2'].includes(destination)) {
            try {
                var target = new window.URL(destination, window.location.origin);
                if (target.origin === next.origin && !target.username && !target.password && target.pathname !== '/login/') {
                    next = target;
                }
            } catch (error) { /* Keep the account page as the fallback. */ }
        }
        function continueSignIn() { window.location.assign(next.href); }

        var proof;
        try { proof = await post('digitalogic_recovery_nonce'); } catch (error) { /* Continue on network failure. */ }
        if (!proof || !proof.success || !proof.data || !proof.data.nonce) {
            continueSignIn();
            return;
        }

        var persian = isPersianLocale();
        var dialog = document.createElement('dialog');
        dialog.className = 'dg-recovery-dialog';
        dialog.setAttribute('aria-labelledby', 'dg-recovery-dialog-title');
        var form = document.createElement('form');
        form.innerHTML = [
            '<h2 id="dg-recovery-dialog-title">' + (persian ? 'تعیین رمز عبور جدید' : 'Set a new password') + '</h2>',
            '<label for="dg-recovery-password">' + (persian ? 'رمز عبور جدید' : 'New password') + '</label>',
            '<input id="dg-recovery-password" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required>',
            '<p class="dg-recovery-password-hint">' + (persian ? 'حداقل ۱۲ نویسه وارد کنید.' : 'Use at least 12 characters.') + '</p>',
            '<p class="dg-recovery-password-error" role="alert" hidden></p>',
            '<button class="button button-primary" type="submit">' + (persian ? 'ذخیره رمز عبور' : 'Save password') + '</button>',
            '<button class="button dg-recovery-cancel" type="button">' + (persian ? 'ادامه بدون تغییر رمز' : 'Continue without changing it') + '</button>'
        ].join('');
        var input = form.querySelector('input');
        var submit = form.querySelector('[type="submit"]');
        var skip = form.querySelector('[type="button"]');
        var error = form.querySelector('[role="alert"]');
        input.setAttribute('aria-describedby', 'dg-recovery-password-hint');
        form.querySelector('.dg-recovery-password-hint').id = 'dg-recovery-password-hint';
        dialog.appendChild(form);
        document.body.appendChild(dialog);
        mountPasswordField(input);
        skip.addEventListener('click', continueSignIn);
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            if (!submit.disabled) { continueSignIn(); }
        });
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (submit.disabled) { return; }
            submit.disabled = true;
            skip.disabled = true;
            error.hidden = true;
            try {
                var result = await post('digitalogic_recovery_password', { nonce: proof.data.nonce, password: input.value });
                if (result && result.success) {
                    continueSignIn();
                    return;
                }
            } catch (failure) { /* Keep the signed-in user on the dialog. */ }
            error.textContent = persian ? 'ذخیره رمز عبور انجام نشد. دوباره تلاش کنید یا بدون تغییر ادامه دهید.'
                : 'Could not save the password. Try again or continue without changing it.';
            error.hidden = false;
            submit.disabled = false;
            skip.disabled = false;
        });
        dialog.showModal();
    }

    function hideDigitsIdentityContainer(form) {
        var phoneInput = form && form.querySelector('input[name="digits_phone"]');
        var container = phoneInput ? phoneInput.closest('.digits-form_tab_container') : null;

        if (container) {
            container.hidden = true;
            container.style.display = 'none';
        }
    }

    function showDigitsIdentityContainer(form) {
        var phoneInput = form && form.querySelector('input[name="digits_phone"]');
        var container = phoneInput ? phoneInput.closest('.digits-form_tab_container') : null;

        if (container) {
            container.hidden = false;
            container.style.display = '';
        }
    }

    function visibleDigitsPassword(form) {
        return Array.prototype.find.call(
            form.querySelectorAll('.digits-form_tab_wrapper input:is([type="password"], [autocomplete="current-password"], .dg-password-input)'),
            function (input) { return !input.disabled && isVisibleElement(input); }
        );
    }

    function digitsOtpSelector(smsOnly) {
        return smsOnly
            ? '.digits-form_otp_selector[data-type="sms_otp"]'
            : '.digits-form_otp_selector:is([data-type="sms_otp"], [data-type="email_otp"])';
    }

    function digitsOtpTab(password, smsOnly) {
        var step = password && password.closest('.digits-form_tab_container');
        var input = smsOnly ? 'input[name="sms_otp"]' : 'input:is([name="sms_otp"], [name="email_otp"])';
        if (step && step.querySelector(digitsOtpSelector(smsOnly) + ', ' + input)) {
            return step.querySelector('.digits-form_tab-item[data-value="otp"]');
        }
        return null;
    }

    function startNativeDigitsSms(password, tab, smsOnly) {
        password.value = '';
        password.type = 'password';
        tab.click();
        var step = tab.closest?.('.digits-form_tab_container');
        var channel = step?.querySelector('.digits-tab_active ' + digitsOtpSelector(smsOnly) + ':not(.auto-click)');
        if (channel && !channel.closest('.digits-tab_active').querySelector('input:is([name="sms_otp"], [name="email_otp"])')) {
            channel.click();
        }
    }

    function setDigitsIdentityPrompt(form, mode) {
        var contactOnly = form.dataset.dgSmsIdentity === 'true' || form.closest('[data-dg-recovery]');
        var prompt = contactOnly
            ? getLabel('contactPlaceholder', 'Email address or phone number')
            : getLabel('identityPlaceholder', 'Username, email, or phone number');
        form.querySelectorAll('input:is([name="digits_phone"], [name="digits_email"])').forEach(function (input) {
            // An empty verification field still accepts either email or phone.
            var label = mode === 'phone' && input.name === 'digits_phone' && (!contactOnly || input.value)
                ? getLabel('phonePlaceholder', 'Phone number')
                : prompt;
            input.placeholder = label;
            input.setAttribute('aria-label', label);
        });
    }

    function showDigitsSmsIdentity(form) {
        var identity = digitsIdentityValue(form);
        var email = !!emailDomain(identity);
        var back = form.querySelector('.dg-username-password-step:not([hidden]) .dg-username-login-back, .digits_form_back:not(.digits_hide_back)');
        if (back) { back.click(); }
        form.dataset.dgSmsIdentity = 'true';
        if (email) {
            selectDigitsMode('email', false);
            submitDigitsSecureForm(form);
            return;
        }
        var currentPhone = form.querySelector('[name="digits_phone"]');
        if (classifyIdentity(currentPhone.value) !== 'phone') {
            form.querySelector('[name="digits_email"]').value = '';
            currentPhone.value = '';
        }
        selectDigitsMode('phone', true);
        var help = form.querySelector('.dg-sms-identity-help');
        if (help) { help.hidden = false; }
    }

    function isDigitsIdentityStep(form) {
        return digitsEmailChecks.has(form) && form.classList.contains('digits_form_index_section')
            && form.dataset.dgUsernameStep !== 'password';
    }

    function callVerificationEnabled(shell) {
        return !!shell.querySelector('[data-call-unified][data-call-available="true"]');
    }

    function canChoosePhoneChannel(number, shell) {
        return number.valid && ['MOBILE', 'FIXED_LINE_OR_MOBILE'].includes(number.kind) && callVerificationEnabled(shell);
    }

    function digitsIdentityAction(form) {
        var shell = form.closest('.dg-digits-login-shell');
        var mode = shell.dataset.dgIdentity;
        var value = digitsIdentityValue(form);
        var passwordless = form.dataset.dgSmsIdentity === 'true';
        if (mode === 'email' && emailDomain(value)) {
            return { type: passwordless ? 'email' : 'password', enabled: digitsEmailChecks.get(form).isVerified(value) };
        }
        if (mode === 'username' && value && !passwordless) {
            return { type: 'password', enabled: true };
        }
        if (mode === 'phone') {
            var number = phonePartsFromValue(value, form.querySelector('[name="login_digt_countrycode"]')?.value);
            if (number.valid && (!number.kind || ['MOBILE', 'FIXED_LINE_OR_MOBILE', 'FIXED_LINE'].includes(number.kind))) {
                var byCall = number.kind === 'FIXED_LINE'
                    || (form.dataset.dgPhoneChannel === 'call' && canChoosePhoneChannel(number, shell));
                return { type: byCall ? 'call' : 'sms', enabled: !byCall || callVerificationEnabled(shell), number: number };
            }
        }
        return { type: 'continue', enabled: false };
    }

    function syncDigitsIdentityButton(form) {
        if (!digitsEmailChecks.has(form)) { return; }
        var button = form.querySelector('.digits-form_login > .digits-form_submit-btn');
        if (!button) { return; }
        var action = isDigitsIdentityStep(form) ? digitsIdentityAction(form) : null;
        var label = getLabel('continue', 'Continue');
        if (action?.type === 'password') {
            label = getLabel('enterPassword', 'Enter password');
        } else if (action?.type === 'email') {
            label = getLabel('sendEmailCode', 'Send email code');
        } else if (action?.type === 'call') {
            label = getLabel('getCallCode', 'Get call code');
        } else if (action?.type === 'sms') {
            label = action.number.kind === 'MOBILE' ? getLabel('sendSmsCode', 'Send SMS code') : getLabel('continueBySms', 'Continue by SMS');
        } else if (!action && form.classList.contains('dg-otp-step')) {
            label = getLabel('verifyCode', 'Verify code');
        } else if (!action && visibleDigitsPassword(form)) {
            label = getLabel('login', 'Log in');
        }
        var text = button.querySelector('.digits-form_button-text');
        if (text && text.textContent !== label) { text.textContent = label; }
        if (action && !action.enabled) {
            button.dataset.dgIdentityBlocked = 'true';
            button.disabled = true;
        } else if (button.dataset.dgIdentityBlocked === 'true') {
            delete button.dataset.dgIdentityBlocked;
            if (form.dataset.dgPending === 'true') {
                button.dataset.dgDisabledByBranding = 'true';
            } else {
                button.disabled = false;
            }
        }
    }

    function createPasswordlessAction(captionId, onClick) {
        var action = document.createElement('div');
        action.className = 'dg-passwordless-action';

        var button = document.createElement('button');
        button.className = 'dg-skip-password dg-icon-label-action';
        button.type = 'button';
        button.setAttribute('aria-describedby', captionId);
        button.addEventListener('click', onClick);

        var label = document.createElement('span');
        label.className = 'dg-passwordless-label';
        label.textContent = getLabel('skipPassword', 'Sign in without a password');
        button.appendChild(label);
        putAuthIcon(button, 'sms');

        var caption = document.createElement('p');
        caption.className = 'dg-passwordless-caption';
        caption.id = captionId;
        caption.textContent = getLabel('skipPasswordCaption', 'Forgot your password?');
        action.append(caption, button);
        return action;
    }

    function prepareDigitsSkipPassword(form) {
        var wrapper = form.querySelector('.digits-form_tab_wrapper');
        var primary = form.querySelector('.digits-form_login > .digits-form_submit-btn');

        if (!primary || !wrapper || form.dataset.dgSmsReady === 'true') {
            return;
        }

        form.dataset.dgSmsReady = 'true';
        var action = createPasswordlessAction('dg-native-passwordless-caption', function () {
            var password = visibleDigitsPassword(form);
            var tab = digitsOtpTab(password);
            if (tab && form.dataset.dgPending !== 'true') {
                authRecovery = true;
                startNativeDigitsSms(password, tab);
            }
        });
        primary.after(action);
        var remember = form.querySelector('.digits-form_rememberme');
        var rememberInput = remember?.querySelector('input[name="rememberme"]');
        var shell = form.closest('.dg-digits-login-shell');

        function updateRememberMe(finalStep) {
            if (!rememberInput) { return; }
            var dial = shell?.querySelector('.dg-call-unified [data-call-instructions]:not([hidden]) [data-call-dial]');
            var anchor = dial || form.querySelector('.dg-username-password-step:not([hidden]) .dg-username-login-actions') || primary;
            if (remember.nextElementSibling !== anchor) { anchor.before(remember); }
            remember.hidden = !dial && !finalStep;
            if (remember.hidden) { rememberInput.checked = false; }
        }

        function updateVisibility() {
            var password = visibleDigitsPassword(form);
            var tab = digitsOtpTab(password);
            var otp = visibleDigitsOtpInput(form);
            action.hidden = !tab;

            var smsSelected = !form.classList.contains('digits_form_index_section') && digitsSmsSelections.delete(form);
            if ((form.dataset.dgSmsIdentity === 'true' || smsSelected) && !form.classList.contains('digits_form_index_section')) {
                delete form.dataset.dgSmsIdentity;
                setDigitsIdentityPrompt(form, form.closest('.dg-digits-login-shell')?.dataset.dgIdentity);
                var help = form.querySelector('.dg-sms-identity-help');
                if (help) { help.hidden = true; }
                if (smsSelected) { tab = digitsOtpTab(password, true); }
                if (tab) {
                    startNativeDigitsSms(password, tab, smsSelected);
                } else {
                    var selector = wrapper.querySelector('.digits-form_tab_body.digits-tab_active ' + digitsOtpSelector(smsSelected) + ':not(.auto-click)');
                    if (!otp && selector && isVisibleElement(selector)) { selector.click(); }
                }
            }

            if (otp) {
                // An SMS code has no remote approval step. Keep Digits from polling it on focus.
                if (!otp.classList.contains('disable_auto_read')) {
                    otp.classList.add('disable_auto_read');
                }
                prepareDigitsOtpEditor(otp);
            }
            if (form.classList.contains('dg-otp-step') !== Boolean(otp)) {
                form.classList.toggle('dg-otp-step', Boolean(otp));
            }
            updateRememberMe(Boolean(otp || visibleDigitsPassword(form)));
            updateDigitsResend(form);
            syncDigitsIdentityButton(form);
        }

        updateVisibility();
        var observer = new MutationObserver(updateVisibility);
        observer.observe(wrapper, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style', 'hidden', 'type', 'disabled']
        });
        observer.observe(form, { attributes: true, attributeFilter: ['class'] });
        if (rememberInput && shell) { observer.observe(shell, { attributes: true, attributeFilter: ['class'] }); }
        form.addEventListener('input', function (event) {
            if (event.target.matches('input:is([name="sms_otp"], [name="email_otp"])')) {
                stopDigitsSmsWait(form);
            }
        });
        form.addEventListener('click', function (event) {
            if (event.target.closest('.digits_form_back')) {
                digitsSmsSelections.delete(form);
                stopDigitsSmsWait(form);
                if (!form.closest('[data-dg-recovery]')) { authRecovery = false; }
            }
            var resend = event.target.closest('.dg-otp-resend');
            if (resend && resend.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
        window.addEventListener('pagehide', function () { stopDigitsSmsWait(form); });
    }

    function showDigitsUsernamePasswordStep(form, username) {
        var wrapper = form.querySelector('.digits-form_tab_wrapper') || form;
        var step = form.querySelector('.dg-username-password-step');

        hideDigitsIdentityContainer(form);
        form.dataset.dgUsernameStep = 'password';
        form.dataset.dgUsernameValue = username;
        form.classList.add('dg-username-step-active');

        if (!step) {
            var backLabel = getLabel('back', 'Back');
            var loginLabel = getLabel('login', 'Log in');
            var passwordLabel = escapeHtml(getLabel('password', 'Password'));
            step = document.createElement('div');
            step.className = 'digits-form_tab_container dg-username-password-step';
            step.innerHTML = [
                '<div class="digits-form_body_wrapper">',
                '<div class="digits-form_tab_body digits-tab_active">',
                '<div class="dg-username-login-summary">',
                '<span class="dg-username-login-avatar" aria-hidden="true"></span>',
                '<bdi class="dg-username-login-value"></bdi>',
                '</div>',
                '<input class="dg-username-password-input" type="password" autocomplete="current-password" required aria-label="' + passwordLabel + '" placeholder="' + passwordLabel + '">',
                '<div class="dg-username-login-actions">',
                '<button type="button" class="button dg-username-login-back"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span><span>' + escapeHtml(backLabel) + '</span></button>',
                '<button type="submit" class="digits-form_button digits-form_submit digits-form_submit-btn dg-username-login-submit"><span>' + escapeHtml(loginLabel) + '</span></button>',
                '</div>',
                '</div>',
                '</div>'
            ].join('');
            wrapper.appendChild(step);
            mountPasswordField(step.querySelector('.dg-username-password-input'));

            step.querySelector('.dg-username-login-actions').appendChild(createPasswordlessAction('dg-identity-passwordless-caption', function () {
                authRecovery = true;
                showDigitsSmsIdentity(form);
            }));

            step.querySelector('.dg-username-login-back').addEventListener('click', function () {
                // Digits counts every tab container when handling Back, including hidden ones.
                var remember = step.querySelector('.digits-form_rememberme');
                if (remember) {
                    remember.hidden = true;
                    form.querySelector('.digits-form_login > .digits-form_submit-btn').before(remember);
                }
                step.remove();
                delete form.dataset.dgUsernameStep;
                form.classList.remove('dg-username-step-active');
                showDigitsIdentityContainer(form);

                var input = form.querySelector('.digits-tab_active input[autocomplete="username"]');
                if (input) {
                    input.focus({ preventScroll: true });
                }
            });
        }

        step.hidden = false;
        step.style.display = '';
        step.querySelector('.dg-username-login-value').textContent = username;
        var avatar = step.querySelector('.dg-username-login-avatar');
        avatar.replaceChildren();
        putAuthIcon(avatar, 'user');
        var accounts = Array.isArray(config.rememberedAccounts) ? config.rememberedAccounts : [];
        var account = [config.rememberedAccount].concat(accounts).find(function (candidate) {
            return candidate && candidate.login === username;
        });
        if (account?.avatar) {
            var photo = document.createElement('img');
            photo.alt = '';
            photo.hidden = true;
            photo.addEventListener('load', function () { photo.hidden = false; }, { once: true });
            photo.addEventListener('error', function () { photo.remove(); }, { once: true });
            avatar.appendChild(photo);
            photo.src = account.avatar;
        }
        var password = step.querySelector('.dg-username-password-input');
        password.disabled = false;
        password.focus({ preventScroll: true });
    }

    function submitDigitsUsernameLogin(form) {
        var username = cleanText(form.dataset.dgUsernameValue, '');
        var password = form.querySelector('.dg-username-password-input');

        if (!username) {
            delete form.dataset.dgUsernameStep;
            showDigitsIdentityContainer(form);
            return false;
        }

        var coreForm = document.createElement('form');
        var remember = form.querySelector('input[name="rememberme"]');
        var submit = digitsSubmitButton(form);

        coreForm.method = 'post';
        coreForm.action = '/wp-login.php';
        coreForm.style.display = 'none';

        [
            ['log', username],
            ['pwd', password.value],
            ['dg_branded_email', classifyIdentity(username) === 'email' ? '1' : ''],
            ['rememberme', remember && remember.checked ? 'forever' : ''],
            ['redirect_to', digitsLoginRedirect(form)],
            ['wp-submit', 'Log In']
        ].forEach(function (pair) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = pair[0];
            input.value = pair[1];
            coreForm.appendChild(input);
        });

        document.body.appendChild(coreForm);
        markLoading(submit, true, form);
        coreForm.submit();
        return true;
    }

    function appendDigitsStep(form, html, data) {
        if (!form || !html) {
            return false;
        }

        var wrapper = form.querySelector('.digits-form_tab_wrapper') || form;
        var activeContainer = wrapper.querySelector('.digits-form_tab_container:not([hidden])') || wrapper.querySelector('.digits-form_tab_container');
        var template = document.createElement('template');
        template.innerHTML = String(html).trim();

        if (!template.content.childElementCount) {
            return false;
        }

        if (activeContainer) {
            activeContainer.hidden = true;
            activeContainer.style.display = 'none';
        }

        wrapper.appendChild(template.content);
        form.classList.remove('digits_form_index_section');

        var back = form.querySelector('.digits_form_back');
        if (back) {
            back.classList.remove('digits_hide_back');
        }

        if (data && data.input_info_html) {
            var info = wrapper.querySelector('.digits-form_input_info:last-child');
            if (info) {
                info.insertAdjacentHTML('beforeend', data.input_info_html);
            }
        }

        window.setTimeout(function () {
            var input = wrapper.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
            if (input) {
                input.focus({ preventScroll: true });
            }
        }, 0);

        return true;
    }

    function handleDigitsAjaxResult(form, payload) {
        var data = payload && payload.data !== undefined ? payload.data : payload;

        if (!hasUsableDigitsPayload(payload)) {
            clearLoading();
            showDigitsInlineMessage(form, digitsRequestFailure(null, 'malformed'), 'error');
            return;
        }

        if (payload && payload.success === false) {
            clearLoading();
            if (continueToDigitsSignup(form, data)) { return; }
            digitsResponseFailure(form, payload, !!visibleDigitsOtpInput(form)?.value, false);
            return;
        }

        if (payload && payload.success === true) {
            finishBrandedDigitsLogin(form, data);
        }

        if (data && data.reload) {
            window.location.reload();
            return;
        }

        if (data && data.redirect) {
            window.location.assign(data.redirect);
            return;
        }

        if (data && data.html && appendDigitsStep(form, data.html, data)) {
            startDigitsResendTimer(form, data);
            updateDigitsResend(form);
            clearLoading();
            bindDigitsAutoIdentity();
            return;
        }

        clearLoading();

        if (data && data.message) {
            showDigitsInlineMessage(form, data.message, 'success');
        }
    }

    function sendDigitsAjaxFallback(form, button) {
        var endpoint = (window.dig_script && window.dig_script.ajax_url) || '/wp-admin/admin-ajax.php';
        var body = new FormData(form);
        var controller = window.AbortController ? new AbortController() : null;
        var timeout = window.setTimeout(function () {
            if (controller) {
                controller.abort();
            }
        }, 12000);

        markLoading(button || digitsSubmitButton(form), false, form);

        fetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            if (!response.ok) {
                clearLoading();
                showDigitsInlineMessage(form, digitsRequestFailure(response), 'error');
                return;
            }
            return response.json().then(function (payload) {
                handleDigitsAjaxResult(form, payload);
            }, function (error) {
                clearLoading();
                showDigitsInlineMessage(form, digitsRequestFailure(null, error?.name === 'AbortError' ? 'timeout' : 'malformed'), 'error');
            });
        }).catch(function (error) {
            clearLoading();
            showDigitsInlineMessage(form, digitsRequestFailure(null, error?.name === 'AbortError' ? 'timeout' : ''), 'error');
        }).finally(function () { window.clearTimeout(timeout); });
    }

    function isDigitsAjaxRequest(settings) {
        var data = settings && settings.data;

        if (!settings) {
            return false;
        }

        if (typeof data === 'string') {
            return data.indexOf('digits_forms_ajax') !== -1 || data.indexOf('digits=1') !== -1;
        }

        if (Array.isArray(data)) {
            return data.some(function (field) {
                return field && ((field.name === 'action' && field.value === 'digits_forms_ajax')
                    || (field.name === 'digits' && String(field.value) === '1'));
            });
        }

        if (data && typeof data.get === 'function') {
            return data.get('action') === 'digits_forms_ajax' || data.get('digits') === '1';
        }

        if (data && typeof data === 'object') {
            return data.action === 'digits_forms_ajax' || data.digits === '1';
        }

        return false;
    }

    function hasUsableDigitsPayload(payload) {
        var data = payload && payload.data !== undefined ? payload.data : payload;

        if (!payload || typeof payload !== 'object' || typeof payload.success !== 'boolean') {
            return false;
        }

        return !!(data && typeof data === 'object' && Object.keys(data).length);
    }

    function digitsRequestForm() {
        var active = document.activeElement;
        var focused = active && active.closest ? active.closest('form') : null;
        if (isVisibleElement(focused) && (isDigitsSecureForm(focused) || focused.closest('.dg-digits-login-shell'))) {
            return focused;
        }
        return Array.prototype.find.call(
            document.querySelectorAll('.dg-digits-login-shell form, form.digits_form_index_section, form.digits-form, .digits-form_page form'),
            isVisibleElement
        ) || null;
    }

    function visibleDigitsSmsInput(form) {
        return Array.prototype.find.call(form.querySelectorAll('input.otp_input[name="sms_otp"]'), isVisibleElement);
    }

    function visibleDigitsOtpInput(form) {
        return form && (visibleDigitsSmsInput(form)
            || Array.prototype.find.call(form.querySelectorAll('input[name="email_otp"]:not([type="hidden"])'), isVisibleElement));
    }

    function otpDigits(value) {
        return normalizeDigits(value).replace(/[^0-9]/g, '');
    }

    function prepareDigitsOtpEditor(input) {
        if (digitsOtpEditors.has(input)) {
            return;
        }

        var count = input.maxLength >= 4 && input.maxLength <= 8 ? input.maxLength : 6;
        var editor = document.createElement('div');
        editor.className = 'dg-otp-editor';
        editor.dir = 'ltr';
        var form = input.form;
        if (form && !form.hasAttribute('data-dg-otp-cursor')) {
            form.dataset.dgOtpCursor = config.otpCursor === 'bar' ? 'bar' : 'circle';
        }
        editor.style.setProperty('--dg-otp-count', String(count));
        editor.setAttribute('role', 'group');
        editor.setAttribute('aria-label', getLabel('otpCode', 'Verification code'));
        var row = input.closest('.digits-form_input_row') || input.parentElement;
        var stack = document.createElement('div');
        stack.className = 'dg-otp-stack';
        var help = document.createElement('p');
        help.className = 'dg-otp-instructions';
        var caption = document.createElement('span');
        caption.textContent = input.name === 'email_otp'
            ? getLabel('emailCodeInstructions', 'Enter the code sent to your email address.')
            : getLabel('smsCodeInstructions', 'Enter the code sent to your phone.');
        putAuthIcon(help, 'info');
        help.prepend(caption);
        var info = row.querySelector('.digits-form_input_info');
        if (info) { help.append(info); }
        stack.append(help, editor);
        var heading = input.form?.querySelector('.digits-form_heading');
        if (heading && !heading.querySelector('.dg-otp-heading')) {
            var title = document.createElement('span');
            title.className = 'dg-otp-heading';
            title.textContent = getLabel('otpCode', 'Verification code');
            heading.appendChild(title);
        }
        var cells = [];
        var digitRolls = new WeakMap();
        var staticDigits = window.matchMedia('(prefers-reduced-motion: reduce), (forced-colors: active)');

        function setDigit(cell, digit, animate) {
            var previous = cell.value;
            cell.value = digit;
            if (digit === previous) { return; }
            digitRolls.get(cell)?.();
            if (!digit || !animate || form?.dataset.dgOtpCursor !== 'bar' || staticDigits.matches || !cell.animate) { return; }

            var roll = document.createElement('span');
            roll.className = 'dg-otp-roll';
            roll.setAttribute('aria-hidden', 'true');
            cell.after(roll);
            function animateGlyph(text, from, to) {
                var glyph = document.createElement('span');
                glyph.textContent = text;
                roll.appendChild(glyph);
                return glyph.animate([from, to], { duration: 180, easing: 'cubic-bezier(.2,.72,.25,1)', fill: 'both' });
            }
            var outgoing = previous ? animateGlyph(previous,
                { opacity: 1, transform: 'translateY(0)' },
                { opacity: 0, transform: 'translateY(-.5em)' }) : null;
            var incoming = animateGlyph(digit,
                { opacity: 0, transform: 'translateY(.55em)' },
                { opacity: 1, transform: 'translateY(0)' });
            function finish() {
                if (digitRolls.get(cell) !== finish) { return; }
                digitRolls.delete(cell);
                roll.remove();
                outgoing?.cancel();
                incoming.cancel();
            }
            digitRolls.set(cell, finish);
            incoming.finished.then(finish, finish);
        }

        function syncCode() {
            input.value = cells.every(function (cell) { return cell.value !== ''; })
                ? cells.map(function (cell) { return cell.value; }).join('') : '';
            if (editor.hasAttribute('aria-invalid')) { form?.querySelector('.dg-otp-feedback')?.remove(); }
            editor.removeAttribute('aria-invalid');
        }

        function focusDigit(index) {
            var cell = cells[Math.max(0, Math.min(index, count - 1))];
            cell.focus({ preventScroll: true });
            cell.select();
        }

        function fillDigits(value, index, moveFocus) {
            var digits = otpDigits(value);
            var start = digits.length >= count ? 0 : index;
            digits.slice(0, count - start).split('').forEach(function (digit, offset) {
                setDigit(cells[start + offset], digit, moveFocus);
            });
            syncCode();
            if (moveFocus && digits.length) {
                if (digits.length >= count) {
                    var submit = digitsSubmitButton(input.form);
                    if (submit && !submit.disabled) { submit.focus({ preventScroll: true }); }
                    else if (cells.includes(document.activeElement)) { document.activeElement.blur(); }
                } else {
                    focusDigit(start + digits.length);
                }
            }
        }

        for (var index = 0; index < count; index++) {
            var slot = document.createElement('span');
            slot.className = 'dg-otp-slot';
            var cell = document.createElement('input');
            cell.type = 'text';
            cell.inputMode = 'numeric';
            cell.autocomplete = index === 0 ? 'one-time-code' : 'off';
            cell.placeholder = ' ';
            cell.setAttribute('aria-label', getLabel('otpDigit', 'Digit %d').replace('%d', String(index + 1)));
            slot.appendChild(cell);
            editor.appendChild(slot);
            cells.push(cell);
        }

        cells.forEach(function (cell, index) {
            cell.addEventListener('focus', function () { cell.select(); });
            cell.addEventListener('click', function () { cell.select(); });
            cell.addEventListener('select', function () {
                if (document.activeElement === cell && cell.value && (cell.selectionStart !== 0 || cell.selectionEnd !== cell.value.length)) {
                    cell.select();
                }
            });
            cell.addEventListener('beforeinput', function (event) {
                if (event.inputType === 'insertText' && event.data !== null) {
                    event.preventDefault();
                    stopDigitsSmsWait(input.form);
                    fillDigits(event.data, index, true);
                }
            });
            cell.addEventListener('input', function () {
                stopDigitsSmsWait(input.form);
                var value = cell.value;
                setDigit(cell, '', false);
                fillDigits(value, index, true);
            });
            cell.addEventListener('paste', function (event) {
                if (!event.clipboardData) {
                    return;
                }
                event.preventDefault();
                stopDigitsSmsWait(input.form);
                fillDigits(event.clipboardData.getData('text'), index, true);
            });
            cell.addEventListener('keydown', function (event) {
                var key = event.key;
                var target = { ArrowLeft: index - 1, ArrowRight: index + 1, Home: 0, End: count - 1 }[key];
                if (typeof target === 'number') {
                    event.preventDefault();
                    focusDigit(target);
                } else if (key === 'Backspace' || key === 'Delete') {
                    event.preventDefault();
                    stopDigitsSmsWait(input.form);
                    if (key === 'Backspace' && !cell.value && index > 0) {
                        setDigit(cells[index - 1], '', false);
                        focusDigit(index - 1);
                    } else {
                        setDigit(cell, '', false);
                    }
                    syncCode();
                } else if (key === 'Enter') {
                    event.preventDefault();
                    var button = digitsSubmitButton(input.form);
                    if (button) {
                        button.click();
                    }
                }
            });
        });

        var hadFocus = document.activeElement === input;
        digitsOtpEditors.set(input, {
            fill: function (code) {
                cells.forEach(function (cell) { setDigit(cell, '', false); });
                fillDigits(code, 0, false);
            },
            focus: function () {
                focusDigit(cells.findIndex(function (cell) { return !cell.value; }));
            },
            reject: function () {
                editor.setAttribute('aria-invalid', 'true');
                focusDigit(0);
            }
        });
        input.before(stack);
        input.classList.add('dg-otp-native');
        input.tabIndex = -1;
        input.setAttribute('aria-hidden', 'true');
        input.addEventListener('focus', function () { digitsOtpEditors.get(input).focus(); });
        fillDigits(input.value, 0, false);
        if (hadFocus) {
            focusDigit(0);
        }
    }

    function prepareRegistrationOtp(form) {
        if (form.dataset.dgRegistrationOtp) {
            return;
        }
        form.dataset.dgRegistrationOtp = 'true';
        var buttonText = form.querySelector('.digits-form_submit-btn .digits-form_button-text');
        var initialLabel = buttonText?.textContent;
        function update() {
            var otp = visibleDigitsOtpInput(form);
            if (otp) {
                if (!otp.classList.contains('disable_auto_read')) { otp.classList.add('disable_auto_read'); }
                prepareDigitsOtpEditor(otp);
            }
            if (form.classList.contains('dg-otp-step') !== Boolean(otp)) {
                form.classList.toggle('dg-otp-step', Boolean(otp));
            }
            var label = otp ? getLabel('verifyCode', 'Verify code') : initialLabel;
            if (buttonText && buttonText.textContent !== label) { buttonText.textContent = label; }
            updateDigitsResend(form);
        }
        var observer = new MutationObserver(update);
        observer.observe(form, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'hidden'] });
        update();
        form.addEventListener('click', function (event) {
            var resend = event.target.closest('.dg-otp-resend[aria-disabled="true"]');
            if (resend) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
            if (event.target.closest('.digits_form_back')) {
                stopDigitsSmsWait(form);
            }
        }, true);
        window.addEventListener('pagehide', function () { stopDigitsSmsWait(form); });
    }

    function setBrandedAuthForm(shell, signup) {
        var login = shell.querySelector('form.digloginpage');
        var register = shell.querySelector('form.digits_register');
        if (!login || !register) {
            return null;
        }
        stopDigitsSmsWait(signup ? login : register);
        [login, register].forEach(function (form) {
            var active = form === (signup ? register : login);
            form.hidden = !active;
            form.style.display = active ? '' : 'none';
        });
        shell.dataset.dgAuthMode = signup ? 'signup' : 'login';
        return signup ? register : login;
    }

    function continueToDigitsSignup(form, data) {
        var shell = form && form.closest && form.closest('.dg-digits-login-shell');
        if (!shell || !form.matches('.digloginpage') || !data || data.notice !== true
            || !['Please signup before logging in.', 'لطفا قبل از ورود عضو شوید.'].concat(config.digitsSignupNotice || [])
                .includes(cleanText(data.message, ''))
            || shell.dataset.dgIdentity !== 'phone') {
            return false;
        }
        var phone = form.querySelector('[name="digits_phone"]');
        var country = form.querySelector('[name="login_digt_countrycode"]');
        var register = shell.querySelector('form.digits_register');
        var signupPhone = register && register.querySelector('[name="phone"]');
        if (!phone || !signupPhone || !register.classList.contains('digits_form_index_section')) {
            return false;
        }
        setBrandedAuthForm(shell, true);
        var signupCountry = register.querySelector('[name="digt_countrycode"]');
        if (country && signupCountry) {
            signupCountry.value = country.value;
        }
        signupPhone.value = phone.value;
        signupPhone.dispatchEvent(new Event('input', { bubbles: true }));
        signupPhone.focus({ preventScroll: true });
        window.setTimeout(function () {
            if (shell.dataset.dgAuthMode === 'signup') {
                var submit = digitsSubmitButton(register);
                if (submit) { submit.click(); }
            }
        }, 0);
        return true;
    }

    function startDigitsResendTimer(form, data) {
        var seconds = Number(data?.resend_timer);
        if (form && Number.isFinite(seconds) && seconds > 0) {
            digitsResendTimers.set(form, { seconds: seconds, until: Date.now() + seconds * 1000 });
        }
    }

    function updateDigitsResend(form) {
        var timer = digitsResendTimers.get(form);
        if (!timer || !visibleDigitsOtpInput(form)) {
            return;
        }

        var button = Array.from(form.querySelectorAll('.digits-form_resend_otp')).find(function (item) {
            return isVisibleElement(item) && !item.closest('.digits-form_footer_content');
        });
        if (!button || timer.button === button) {
            return;
        }
        timer.button = button;
        button.classList.add('dg-otp-resend');
        putAuthIcon(button, 'sms');
        button.setAttribute('aria-label', getLabel('otpResend', 'Resend code'));
        if (!button.matches('button, input') && !button.matches('a[href]')) {
            button.setAttribute('role', 'button');
            button.tabIndex = 0;
        }
        if (!button.matches('button, input') && !button.dataset.dgResendKeyboard) {
            button.dataset.dgResendKeyboard = 'true';
            button.addEventListener('keydown', function (event) {
                if (event.key === ' ' || (event.key === 'Enter' && !button.matches('a[href]'))) {
                    event.preventDefault();
                    button.click();
                }
            });
        }

        function tick() {
            if (!button.isConnected || timer.button !== button || digitsResendTimers.get(form) !== timer) {
                return;
            }
            var remaining = Math.max(0, timer.until - Date.now());
            var progress = Math.min(100, Math.max(0, 100 * (1 - remaining / (timer.seconds * 1000))));
            button.style.setProperty('--dg-resend-progress', progress + '%');
            button.setAttribute('aria-disabled', remaining > 0 ? 'true' : 'false');
            if (remaining > 0) {
                window.setTimeout(tick, 100);
            } else {
                button.classList.remove('digits_resend_disabled');
            }
        }
        tick();
    }

    function stopDigitsSmsWait(form) {
        var wait = digitsSmsWaits.get(form);
        if (wait) {
            digitsSmsWaits.delete(form);
            wait.controller.abort();
        }
    }

    function submitReceivedSmsCode(form, wait) {
        if (digitsSmsWaits.get(form) !== wait || !wait.ready || !wait.code) {
            return;
        }

        var input = visibleDigitsSmsInput(form);
        var button = digitsSubmitButton(form);
        stopDigitsSmsWait(form);
        if (input && button && !input.value && !button.disabled) {
            var editor = digitsOtpEditors.get(input);
            if (editor) {
                editor.fill(wait.code);
            } else {
                input.value = wait.code;
            }
            // Autofill the code and leave time to choose whether to remember this device.
            if (!form.querySelector('.digits-form_rememberme input[name="rememberme"]')) { button.click(); }
        }
    }

    function startDigitsSmsWait(form) {
        stopDigitsSmsWait(form);
        if (!('OTPCredential' in window) || !window.AbortController) {
            return null;
        }

        var wait = { controller: new window.AbortController(), ready: false, code: '' };
        digitsSmsWaits.set(form, wait);

        // Register before the SMS request goes out, even if the carrier delivers immediately.
        navigator.credentials.get({ otp: { transport: ['sms'] }, signal: wait.controller.signal }).then(function (credential) {
            if (credential && /^[0-9]{4,8}$/.test(credential.code)) {
                wait.code = credential.code;
                submitReceivedSmsCode(form, wait);
            }
        }).catch(function () {
            if (digitsSmsWaits.get(form) === wait) {
                stopDigitsSmsWait(form);
            }
        });
        return wait;
    }

    function patchDigitsAjaxFailureGuard() {
        if (!window.jQuery || !window.jQuery.ajax || window.jQuery.ajax.dgDigitsGuarded) {
            return;
        }

        var originalAjax = window.jQuery.ajax;

        window.jQuery.ajax = function () {
            var args = Array.prototype.slice.call(arguments);
            var settingsIndex = typeof args[0] === 'object' ? 0 : 1;
            var settings = args[settingsIndex];

            if (settings && typeof settings === 'object' && isDigitsAjaxRequest(settings)) {
                var guardedSettings = Object.assign({}, settings);
                var success = guardedSettings.success;
                var errorHandler = guardedSettings.error;
                var beforeSend = guardedSettings.beforeSend;
                var complete = guardedSettings.complete;
                var requestForm = digitsRequestForm();
                var brandedAuth = requestForm && requestForm.closest && requestForm.closest('.dg-digits-login-shell, .dg-digits-register-shell');
                var fields = brandedAuth && Array.isArray(settings.data) ? settings.data : [];
                var checksStatus = fields.some(function (field) { return field && field.name === 'check_status'; });
                var sendsSms = fields.some(function (field) { return field && field.name === 'sub_action' && field.value === 'sms_otp'; });
                var sendsEmail = fields.some(function (field) { return field && field.name === 'sub_action' && field.value === 'email_otp'; });
                var sendsCode = sendsSms || sendsEmail;
                var verifiesCode = !sendsCode && !checksStatus && fields.some(function (field) {
                    return field && ['sms_otp', 'email_otp'].includes(field.name) && field.value;
                });
                if (authRecovery && verifiesCode) {
                    guardedSettings.data = fields.concat({ name: 'dg_recovery', value: '1' });
                }
                if (sendsCode || checksStatus) {
                    guardedSettings.data = fields.map(function (field) {
                        return field && ['sms_otp', 'email_otp'].includes(field.name) ? { name: field.name, value: '' } : field;
                    });
                }
                if (checksStatus) {
                    args[settingsIndex] = guardedSettings;
                    return originalAjax.apply(this, args);
                }
                var smsWait = null;

                if (!guardedSettings.timeout) {
                    guardedSettings.timeout = 30000;
                }

                guardedSettings.beforeSend = function () {
                    if (typeof beforeSend === 'function' && beforeSend.apply(this, arguments) === false) {
                        return false;
                    }
                    if (brandedAuth) { requestForm.querySelector('.dg-digits-inline-message')?.remove(); }
                    if (sendsSms) {
                        var previousCode = visibleDigitsSmsInput(requestForm);
                        if (previousCode) {
                            var editor = digitsOtpEditors.get(previousCode);
                            if (editor) {
                                editor.fill('');
                            } else {
                                previousCode.value = '';
                            }
                        }
                        smsWait = startDigitsSmsWait(requestForm);
                    } else {
                        if (verifiesCode) {
                            stopDigitsSmsWait(requestForm);
                        }
                        var action = document.activeElement;
                        markLoading(action && action.matches && action.matches('.dg-passkey-options .digits-form_passwordless_login')
                            ? action : digitsSubmitButton(requestForm), false, requestForm);
                    }
                };

                guardedSettings.complete = function () {
                    clearLoading();
                    if (smsWait && digitsSmsWaits.get(requestForm) === smsWait) {
                        if (smsWait.ready && visibleDigitsSmsInput(requestForm)) {
                            submitReceivedSmsCode(requestForm, smsWait);
                        } else {
                            stopDigitsSmsWait(requestForm);
                        }
                    }
                    if (typeof complete === 'function') {
                        return complete.apply(this, arguments);
                    }
                };

                guardedSettings.success = function (payload) {
                    try {
                        if (!hasUsableDigitsPayload(payload)) {
                            clearLoading();
                            if (brandedAuth) {
                                // Give Digits a safe failure shape so it can release its own submit lock.
                                withoutDigitsToast(success, this, [{ success: false, data: {} }]);
                                showDigitsInlineMessage(requestForm, digitsRequestFailure(null, 'malformed'), 'error');
                            }
                            return;
                        }
                        if (payload.success) {
                            finishBrandedDigitsLogin(requestForm, payload.data);
                            if (verifiesCode && brandedAuth && completeRecovery(payload.data)) {
                                return;
                            }
                        } else if (brandedAuth && continueToDigitsSignup(requestForm, payload.data)) {
                            // Release the vendor's submit lock without displaying its signup notice.
                            if (typeof success === 'function') {
                                success.call(this, { success: true, data: {} });
                            }
                            return;
                        } else if (brandedAuth && !payload.data?.reload) {
                            var callbackArgs = Array.from(arguments);
                            callbackArgs[0] = Object.assign({}, payload, {
                                data: typeof payload.data === 'string' ? { message: payload.data } : payload.data
                            });
                            withoutDigitsToast(success, this, callbackArgs);
                            clearLoading();
                            digitsResponseFailure(requestForm, payload, verifiesCode, sendsCode);
                            return;
                        }
                        if (brandedAuth && payload.success) {
                            if (sendsSms) { payload.data.check_remote_status = false; }
                            startDigitsResendTimer(requestForm, payload.data);
                        }
                        if (smsWait && payload.success) {
                            smsWait.ready = true;
                            // This form already has a cancellable WebOTP request.
                            payload.data.auto_fill = false;
                        }
                        if (typeof success === 'function') {
                            var result = success.apply(this, arguments);
                            if (brandedAuth && payload.success && digitsResendTimers.has(requestForm)) {
                                updateDigitsResend(requestForm);
                            }
                            return result;
                        }
                    } catch (error) {
                        clearLoading();
                        window.console && window.console.error && window.console.error(error);
                        if (brandedAuth) { showDigitsInlineMessage(requestForm, digitsRequestFailure(null, 'callback'), 'error'); }
                    }
                };

                guardedSettings.error = function (request, state) {
                    clearLoading();
                    if (!brandedAuth) {
                        return typeof errorHandler === 'function' ? errorHandler.apply(this, arguments) : undefined;
                    }
                    withoutDigitsToast(errorHandler, this, arguments);
                    if (state !== 'abort') { showDigitsInlineMessage(requestForm, digitsRequestFailure(request, state), 'error'); }
                };

                args[settingsIndex] = guardedSettings;
            }

            return originalAjax.apply(this, args);
        };

        window.jQuery.ajax.dgDigitsGuarded = true;
        window.jQuery.ajax.dgOriginalAjax = originalAjax;
    }

    function scheduleDigitsAjaxFailureGuard() {
        patchDigitsAjaxFailureGuard();
        [50, 250, 750, 1500, 3000].forEach(function (delay) {
            window.setTimeout(patchDigitsAjaxFailureGuard, delay);
        });
        window.addEventListener('load', patchDigitsAjaxFailureGuard, { once: true });
    }

    function digitsRecaptchaSiteKey() {
        return typeof config.digitsRecaptchaSiteKey === 'string' ? config.digitsRecaptchaSiteKey.trim() : '';
    }

    function ensureRecaptchaScript(siteKey) {
        if (!siteKey || window.grecaptcha || document.querySelector('script[data-dg-digits-recaptcha="true"]')) {
            return;
        }

        var script = document.createElement('script');
        var base = config.recaptchaScriptBase || 'https://www.recaptcha.net/recaptcha/api.js';

        script.src = base + '?render=' + encodeURIComponent(siteKey);
        script.async = true;
        script.defer = true;
        script.dataset.dgDigitsRecaptcha = 'true';
        document.head.appendChild(script);
    }

    function prepareDigitsRecaptcha(row) {
        var siteKey = digitsRecaptchaSiteKey();

        if (!row || !row.classList.contains('digits_captcha_row') || !siteKey) {
            return false;
        }

        row.querySelectorAll('.dg_min_capt, .dig_captcha, input[name="digits_reg_logincaptcha"], input[name="dig_captcha_ses"]').forEach(function (node) {
            node.remove();
        });

        if (!row.querySelector('.invi-recaptcha')) {
            var holder = document.createElement('div');
            holder.className = 'invi-recaptcha';
            holder.id = 'captcha_dg_' + Math.random().toString(36).slice(2, 10);
            holder.setAttribute('isolated', 'true');
            holder.dataset.ctype = 'v3';
            holder.dataset.callback = 'digits_recaptcha_callback';
            holder.dataset.render = 'explicit';
            holder.dataset.size = 'normal';
            holder.dataset.sitekey = siteKey;
            row.appendChild(holder);
        }

        ensureRecaptchaScript(siteKey);
        row.hidden = true;
        row.setAttribute('aria-hidden', 'true');
        return true;
    }

    function hasPendingDigitsRecaptcha(form) {
        return !!(form && form.querySelector('.invi-recaptcha'));
    }

    function waitForDigitsRecaptcha(form, button) {
        var siteKey = digitsRecaptchaSiteKey();
        var attempts = Number(form.dataset.dgRecaptchaWaits || '0');

        ensureRecaptchaScript(siteKey);
        markLoading(button, false, form);

        if (window.grecaptcha) {
            delete form.dataset.dgRecaptchaWaits;
            return true;
        }

        if (attempts < 20) {
            form.dataset.dgRecaptchaWaits = String(attempts + 1);
            window.setTimeout(function () {
                submitDigitsSecureForm(form);
            }, 250);
            return false;
        }

        delete form.dataset.dgRecaptchaWaits;
        clearLoading();
        showDigitsInlineMessage(form, getLabel('recaptchaUnavailable', 'محافظت ورود هنوز کامل بارگذاری نشده است. چند لحظه دیگر دوباره تلاش کنید.'), 'warning');
        return false;
    }

    function submitDigitsSecureForm(form) {
        var identityAction = isDigitsIdentityStep(form) ? digitsIdentityAction(form) : null;
        if (identityAction) { digitsSmsSelections.delete(form); }
        if (identityAction && !identityAction.enabled) {
            syncDigitsIdentityButton(form);
            return;
        }
        var button = digitsSubmitButton(form);
        var otp = visibleDigitsOtpInput(form);

        if (otp && digitsOtpEditors.has(otp) && !otp.value) {
            showDigitsInlineMessage(form, getLabel('otpIncomplete', 'Enter the full verification code.'), 'warning');
            digitsOtpEditors.get(otp).focus();
            return;
        }

        prepareDigitsCaptchaProtection();

        var shell = form.closest('.dg-digits-login-shell');
        var mode = shell?.dataset.dgIdentity;
        if (form.dataset.dgSmsIdentity === 'true' && mode !== 'phone' && mode !== 'email') {
            showDigitsInlineMessage(form, getLabel('smsIdentity', 'Enter the mobile number on your account to receive a code.'), 'warning');
            return;
        }
        if (form.dataset.dgSmsIdentity === 'true' && mode === 'email' && form.classList.contains('digits_form_index_section')
            && !emailDomain(digitsIdentityValue(form))) {
            showDigitsInlineMessage(form, getLabel('invalidEmail', 'Enter a valid email address.'), 'warning');
            return;
        }
        if (identityAction?.type === 'call') {
            form.querySelector('.dg-digits-inline-message')?.remove();
            if (!window.DigitalogicCallLogin?.start(identityAction.number.e164)) {
                showDigitsInlineMessage(form, getLabel('callLoadFailed', 'The call verification controls did not load. Reload this page and try again.'), 'warning');
            }
            return;
        }

        var password = form.matches('form.digloginpage') ? visibleDigitsPassword(form) : null;
        if ((form.dataset.dgUsernameStep === 'password' || password?.required) && !validateSignInPassword(password, form)) {
            clearLoading();
            return;
        }

        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            form.reportValidity?.();
            clearLoading();
            return;
        }

        if (form.dataset.dgUsernameStep === 'password') {
            submitDigitsUsernameLogin(form);
            return;
        }
        if (identityAction?.type === 'password') {
            showDigitsUsernamePasswordStep(form, digitsIdentityValue(form));
            return;
        }

        if (hasPendingDigitsRecaptcha(form) && !waitForDigitsRecaptcha(form, button)) {
            return;
        }

        if (identityAction?.type === 'sms') { digitsSmsSelections.add(form); }
        if (window.jQuery && typeof window.digitsSecureFormSubmit === 'function') {
            try {
                if (window.digitsSecureFormSubmit(window.jQuery(form)) === false) {
                    clearLoading();
                }
                return;
            } catch (error) {
                clearLoading();
                window.console && window.console.error && window.console.error(error);
                sendDigitsAjaxFallback(form, button);
                return;
            }
        }

        sendDigitsAjaxFallback(form, button);
    }

    function bindLoadingStates() {
        window.addEventListener('click', function (event) {
            var target = event.target && event.target.closest ? event.target : null;
            var button = target && target.closest('.dg-digits-login-shell .digits-form_submit-btn, .dg-digits-login-shell .digits-form_submit, .dg-digits-login-shell .digits-form_button');
            if (!button) {
                return;
            }

            var form = button.closest('form');
            if (button.matches('.digits-form_passwordless_login')) {
                if (!button.closest('details.dg-passkey-options[open]')) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }
                return;
            }
            if (!isDigitsSecureForm(form)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            submitDigitsSecureForm(form);
        }, true);

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches('#loginform, #lostpasswordform, #registerform, .digits-form_page form, form.digits-form, form.digits_form_index_section')) {
                return;
            }

            if (isDigitsSecureForm(form)) {
                event.preventDefault();
                submitDigitsSecureForm(form);
                return;
            }

            window.setTimeout(function () {
                if (event.defaultPrevented) {
                    clearLoading();
                    return;
                }

                var button = form.querySelector('#wp-submit, [type="submit"], .digits-form_submit-btn');
                markLoading(button, form.matches('#loginform'), form);
            }, 0);
        }, true);

        document.addEventListener('click', function (event) {
            var button = event.target.closest('.digits-form_submit-btn, .digits-form_submit, .digits-form_button, .digits-social-btn');
            if (!button) {
                return;
            }

            // Native Digits validation can reject before sending any request.
            // Its AJAX beforeSend hook owns loading, so validation is not a timeout.
            if (isDigitsSecureForm(button.closest('form'))) {
                return;
            }

            window.setTimeout(function () {
                var form = button.closest('form');
                if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    clearLoading();
                    return;
                }

                markLoading(button, false, form);
            }, 0);
        });

        document.addEventListener('invalid', clearLoading, true);
        window.addEventListener('pageshow', clearLoading);

        if (window.jQuery) {
            var finishDigitsRequest = function (event, request, settings) {
                if (isDigitsAjaxRequest(settings)) {
                    clearLoading();
                }
            };
            window.jQuery(document).ajaxComplete(finishDigitsRequest);
            window.jQuery(document).ajaxError(finishDigitsRequest);
        }
    }

    function selectDigitsMode(mode, focusInput) {
        var shell = document.querySelector('.dg-digits-login-shell');
        if (!shell) {
            return;
        }

        var nextMode = mode === 'email' ? 'email' : (mode === 'phone' ? 'phone' : (mode === 'username' ? 'username' : 'unknown'));
        var visibleMode = nextMode === 'email' ? 'email' : 'phone';
        var tab = shell.querySelector('.digits-form_tab-item[data-value="' + nextMode + '"]');
        var hidden = shell.querySelector('input[name="action_type"]');
        var phoneInput = shell.querySelector('input[name="digits_phone"]');
        var emailInput = shell.querySelector('input[name="digits_email"]');
        var countryCode = shell.querySelector('input[name="login_digt_countrycode"]');
        var phoneBody = phoneInput ? phoneInput.closest('.digits-form_tab_body') : null;
        var emailBody = emailInput ? emailInput.closest('.digits-form_tab_body') : null;

        if (hidden) {
            hidden.value = nextMode === 'username' || nextMode === 'unknown' ? 'phone' : nextMode;
        }

        if (tab && nextMode !== 'username' && nextMode !== 'unknown' && !tab.classList.contains('digits-tab_active')) {
            tab.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        }

        [phoneBody, emailBody].forEach(function (body) {
            if (!body) {
                return;
            }

            var active = visibleMode === 'email'
                ? body.contains(emailInput)
                : body.contains(phoneInput);
            body.classList.toggle('digits-tab_active', active);
            body.hidden = !active;
        });

        if (phoneInput) {
            phoneInput.disabled = visibleMode !== 'phone';
            phoneInput.required = visibleMode === 'phone';
            phoneInput.type = nextMode === 'phone' ? 'tel' : 'text';
            phoneInput.setAttribute('inputmode', nextMode === 'phone' ? 'tel' : 'text');
            phoneInput.setAttribute('autocomplete', nextMode === 'phone' ? 'tel-national' : 'username');
            var firstLetter = phoneInput.value.match(/\p{Letter}/u)?.[0] || '';
            phoneInput.dir = nextMode === 'username' && /[\p{Script=Arabic}\p{Script=Hebrew}]/u.test(firstLetter) ? 'rtl' : 'ltr';
        }

        if (emailInput) {
            emailInput.disabled = visibleMode !== 'email';
            emailInput.required = visibleMode === 'email';
        }

        if (countryCode) {
            countryCode.disabled = nextMode !== 'phone';
            if (nextMode === 'phone' && !countryCode.value) {
                countryCode.value = config.defaultCountryCode || '+98';
            }
        }

        shell.dataset.dgIdentity = nextMode;
        var form = phoneInput?.form || emailInput?.form;
        if (form) {
            setDigitsIdentityPrompt(form, nextMode);
            syncDigitsIdentityButton(form);
        }
        [phoneInput, emailInput].filter(Boolean).forEach(function (input) {
            var holder = input.closest('.digits-form_input');
            if (!holder) { return; }
            holder.classList.add('dg-auth-input-icon');
            putAuthIcon(holder, nextMode === 'email' ? 'email' : nextMode === 'phone' ? 'phone' : 'user');
        });

        if (focusInput) {
            var nextInput = visibleMode === 'email' ? emailInput : phoneInput;
            if (nextInput) {
                nextInput.focus({ preventScroll: true });
            }
        }
    }

    function bindEmailDomainFeedback(input) {
        var form = input.form;
        var row = input.closest('.digits-form_input_row') || input;
        var status = document.createElement('p');
        status.id = 'dg-email-domain-status';
        status.className = 'dg-email-domain-status';
        status.setAttribute('role', 'status');
        row.after(status);
        input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), status.id].filter(Boolean).join(' '));
        var persian = document.documentElement.lang.toLowerCase().startsWith('fa');
        var labels = {
            checking: persian ? 'در حال بررسی دامنهٔ ایمیل…' : 'Checking the email domain…',
            valid: persian ? 'دامنهٔ ایمیل معتبر است.' : 'The email domain accepts mail.',
            invalid: persian ? 'برای این دامنه سرویس ایمیل پیدا نشد. نشانی را بررسی کنید.' : 'No mail service was found for this domain. Check the address.',
            format: persian ? 'نشانی ایمیل معتبر وارد کنید.' : 'Enter a valid email address.',
            unavailable: persian ? 'بررسی دامنه فعلاً ممکن نیست.' : 'The domain check is temporarily unavailable.'
        };
        var cache = new Map();
        var timer;
        var pending;
        var failedDomain;

        function render(state) {
            input.setAttribute('aria-invalid', String(state === 'invalid' || state === 'format'));
            syncDigitsIdentityButton(form);
            if (status.dataset.state === state) { return; }
            status.dataset.state = state;
            status.replaceChildren();
            if (state) {
                var text = document.createElement('span');
                text.textContent = labels[state];
                status.appendChild(text);
                putAuthIcon(status, state === 'valid' ? 'check' : (state === 'checking' ? 'email' : 'info'));
                if (state === 'unavailable') {
                    var retry = document.createElement('button');
                    retry.type = 'button';
                    retry.className = 'dg-email-domain-retry';
                    retry.textContent = persian ? 'تلاش دوباره' : 'Try again';
                    retry.addEventListener('click', function () {
                        failedDomain = null;
                        input.focus();
                        update(true);
                    });
                    status.appendChild(retry);
                }
            }
        }

        async function check(domain) {
            var request = { domain: domain, controller: new AbortController() };
            pending = request;
            var timeout = window.setTimeout(function () { request.controller.abort(); }, 6500);
            try {
                var body = new URLSearchParams({
                    action: 'digitalogic_email_domain',
                    nonce: config.emailDomainNonce,
                    domain: domain
                });
                var response = await window.fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: body,
                    signal: request.controller.signal
                });
                var result = response.ok ? await response.json() : null;
                if (!result?.success || typeof result.data?.valid !== 'boolean') { throw new Error('Email domain check failed'); }
                if (pending === request && !input.disabled && emailDomain(input.value) === domain) {
                    if (cache.size >= 24) { cache.clear(); }
                    cache.set(domain, result.data.valid);
                    render(result.data.valid ? 'valid' : 'invalid');
                }
            } catch (error) {
                if (pending === request && !input.disabled && emailDomain(input.value) === domain) {
                    failedDomain = domain;
                    render('unavailable');
                }
            } finally {
                window.clearTimeout(timeout);
                if (pending === request) { pending = null; }
            }
        }

        function update(onBlur) {
            var value = input.disabled ? '' : input.value;
            var domain = emailDomain(value);
            window.clearTimeout(timer);
            if (failedDomain !== domain) { failedDomain = null; }
            if (pending && pending.domain !== domain) {
                pending.controller.abort();
                pending = null;
            }
            if (!domain) {
                render(value && onBlur ? 'format' : '');
                return;
            }
            if (cache.has(domain)) {
                render(cache.get(domain) ? 'valid' : 'invalid');
                return;
            }
            if (failedDomain === domain || !config.emailDomainNonce) {
                render('unavailable');
                return;
            }
            if (pending) { return; }
            render('checking');
            if (onBlur) {
                void check(domain);
            } else {
                timer = window.setTimeout(function () { void check(domain); }, 300);
            }
        }

        digitsEmailChecks.set(form, {
            isVerified: function (value) { return cache.get(emailDomain(value)) === true; }
        });
        return update;
    }

    var dgCountryOptions = [
        { code: '+98', flag: 'ir-tricolor', label: 'Iran', labelFa: 'ایران' },
        { code: '+1', flag: 'us', label: 'United States / Canada', labelFa: 'آمریکا / کانادا' },
        { code: '+971', flag: 'ae', label: 'United Arab Emirates', labelFa: 'امارات متحده عربی' },
        { code: '+90', flag: 'tr', label: 'Turkey', labelFa: 'ترکیه' },
        { code: '+44', flag: 'gb', label: 'United Kingdom', labelFa: 'بریتانیا' },
        { code: '+49', flag: 'de', label: 'Germany', labelFa: 'آلمان' },
        { code: '+33', flag: 'fr', label: 'France', labelFa: 'فرانسه' },
        { code: '+31', flag: 'nl', label: 'Netherlands', labelFa: 'هلند' },
        { code: '+964', flag: 'iq', label: 'Iraq', labelFa: 'عراق' },
        { code: '+93', flag: 'af', label: 'Afghanistan', labelFa: 'افغانستان' }
    ];

    function countryLabel(option) {
        return isPersianLocale() ? (option.labelFa || option.label) : option.label;
    }

    function countryFlagUrl(option) {
        var base = config.flagBaseUrl || '/wp-content/plugins/digitalogic-wp/assets/images/flags/';
        return base.replace(/\/?$/, '/') + encodeURIComponent(option.flag || 'intl') + '.svg';
    }

    function countryFlagMarkup(option, className) {
        return '<span class="' + className + '" style="--dg-flag:url(' + escapeHtml(countryFlagUrl(option)) + ')" aria-hidden="true"></span>';
    }

    function normalizeCountryCode(value) {
        var digits = normalizeDigits(value).replace(/\D/g, '');
        if (!digits) {
            return '';
        }

        if (digits.indexOf('00') === 0) {
            digits = digits.slice(2);
        }

        return '+' + digits.slice(0, 4);
    }

    function countryOptionForCode(code) {
        var normalized = normalizeCountryCode(code);
        return dgCountryOptions.find(function (option) {
            return option.code === normalized;
        }) || { code: normalized || (config.defaultCountryCode || '+98'), flag: 'intl', label: 'International', labelFa: 'بین المللی' };
    }

    function bindCountryCodePicker(shell) {
        var wrap = shell && shell.querySelector('.digits-form_countrycode');
        var input = wrap && wrap.querySelector('input[name="login_digt_countrycode"]');

        if (!wrap || !input || wrap.dataset.dgCountryReady === 'true') {
            return;
        }

        wrap.dataset.dgCountryReady = 'true';
        wrap.classList.add('dg-country-code-control');
        input.setAttribute('inputmode', 'tel');
        input.setAttribute('autocomplete', 'tel-country-code');

        var flag = document.createElement('button');
        flag.type = 'button';
        flag.className = 'dg-country-flag';
        function focusPhone() {
            var phone = shell.querySelector('input[name="digits_phone"]');
            if (phone) { phone.focus({ preventScroll: true }); }
        }
        flag.addEventListener('click', function () {
            closeMenu();
            focusPhone();
        });

        var chevron = document.createElement('button');
        chevron.type = 'button';
        chevron.className = 'dg-country-trigger';
        chevron.setAttribute('aria-label', getLabel('chooseCountryCode', 'Choose country code'));
        chevron.setAttribute('aria-haspopup', 'listbox');
        chevron.setAttribute('aria-expanded', 'false');
        chevron.innerHTML = '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';

        var menu = document.createElement('div');
        menu.className = 'dg-country-menu';
        menu.setAttribute('role', 'listbox');
        menu.setAttribute('aria-hidden', 'true');

        function closeMenu() {
            wrap.classList.remove('is-open');
            chevron.setAttribute('aria-expanded', 'false');
            menu.setAttribute('aria-hidden', 'true');
        }

        function toggleMenu() {
            var open = !wrap.classList.contains('is-open');
            wrap.classList.toggle('is-open', open);
            chevron.setAttribute('aria-expanded', open ? 'true' : 'false');
            menu.setAttribute('aria-hidden', open ? 'false' : 'true');
        }

        function syncFlag() {
            var value = normalizeCountryCode(input.value) || (config.defaultCountryCode || '+98');
            input.value = value;
            var option = countryOptionForCode(value);
            flag.textContent = '';
            flag.style.setProperty('--dg-flag', 'url(' + countryFlagUrl(option) + ')');
            flag.setAttribute('title', countryLabel(option));
            flag.setAttribute('aria-label', isPersianLocale() ? 'وارد کردن شماره تلفن' : 'Enter phone number');

            menu.querySelectorAll('.dg-country-option').forEach(function (button) {
                var active = button.dataset.code === value;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }

        dgCountryOptions.forEach(function (option) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'dg-country-option';
            button.dataset.code = option.code;
            button.setAttribute('role', 'option');
            button.innerHTML = countryFlagMarkup(option, 'dg-country-option-flag') +
                '<span class="dg-country-option-code">' + escapeHtml(option.code) + '</span>' +
                '<span class="dg-country-option-name">' + escapeHtml(countryLabel(option)) + '</span>';
            button.addEventListener('click', function () {
                input.value = option.code;
                syncFlag();
                closeMenu();
                input.dispatchEvent(new Event('input', { bubbles: true }));
                focusPhone();
            });
            menu.appendChild(button);
        });

        wrap.insertBefore(flag, input);
        wrap.appendChild(chevron);
        wrap.appendChild(menu);

        chevron.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            toggleMenu();
        });

        input.addEventListener('input', syncFlag);
        input.addEventListener('focus', function () { input.select(); });
        menu.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMenu();
                chevron.focus();
            }
        });
        document.addEventListener('click', function (event) {
            if (!wrap.contains(event.target)) {
                closeMenu();
            }
        });

        syncFlag();
    }

    function prepareDigitsCaptchaProtection() {
        document.querySelectorAll(':is(.dg-digits-login-shell, .dg-digits-register-shell) .digits_captcha_row').forEach(function (row) {
            prepareDigitsRecaptcha(row);
        });
    }

    function bindDigitsAutoIdentity() {
        prepareDigitsCaptchaProtection();

        var shell = document.querySelector('.dg-digits-login-shell');
        if (!shell || shell.dataset.dgAutoIdentityReady === 'true') {
            return;
        }

        var phoneInput = shell.querySelector('input[name="digits_phone"]');
        var emailInput = shell.querySelector('input[name="digits_email"]');
        var countryCode = shell.querySelector('input[name="login_digt_countrycode"]');

        if (!phoneInput || !emailInput) {
            prepareDigitsCaptchaProtection();
            return;
        }

        shell.dataset.dgAutoIdentityReady = 'true';
        shell.classList.add('dg-digits-auto-identity');

        if (shell.dataset.dgCaptchaObserverReady !== 'true') {
            shell.dataset.dgCaptchaObserverReady = 'true';
            new MutationObserver(prepareDigitsCaptchaProtection).observe(shell, { childList: true, subtree: true });
        }

        if (countryCode && !countryCode.value) {
            countryCode.value = config.defaultCountryCode || '+98';
        }

        bindCountryCodePicker(shell);

        [phoneInput, emailInput].forEach(function (input) {
            input.setAttribute('autocomplete', 'username');
            input.setAttribute('autocapitalize', 'none');
            input.setAttribute('autocorrect', 'off');
            input.spellcheck = false;
            input.addEventListener('beforeinput', function (event) {
                if (event.data && /^\s+$/u.test(event.data) && !event.isComposing && input.value.includes('@')) {
                    event.preventDefault();
                }
            });
        });
        phoneInput.dir = 'ltr';
        emailInput.dir = 'ltr';
        emailInput.type = 'text';
        emailInput.inputMode = 'email';
        var updateEmailFeedback = bindEmailDomainFeedback(emailInput);
        var login = phoneInput.form;
        if (login) {
            shell.addEventListener('digitalogic:call-error', function (event) {
                showDigitsInlineMessage(login, event.detail, 'warning');
            });
        }
        var channels = document.createElement('fieldset');
        channels.className = 'dg-phone-channels';
        channels.hidden = true;
        var legend = document.createElement('legend');
        legend.textContent = getLabel('phoneChannel', 'How would you like to verify?');
        channels.appendChild(legend);
        ['sms', 'call'].forEach(function (channel) {
            var label = document.createElement('label');
            var choice = document.createElement('input');
            choice.type = 'radio';
            choice.name = 'dg_phone_channel';
            choice.value = channel;
            choice.checked = channel === 'sms';
            choice.disabled = true;
            choice.addEventListener('change', function () {
                login.dataset.dgPhoneChannel = choice.value;
                syncDigitsIdentityButton(login);
            });
            label.append(choice, document.createTextNode(channel === 'sms'
                ? getLabel('phoneSms', 'Text message') : getLabel('phoneCall', 'Phone call')));
            channels.appendChild(label);
        });
        phoneInput.closest('.digits-mobile_wrapper')?.after(channels);
        function updatePhoneChannels() {
            var number = phonePartsFromValue(phoneInput.value, countryCode?.value);
            channels.hidden = shell.dataset.dgIdentity !== 'phone' || !canChoosePhoneChannel(number, shell);
            channels.querySelectorAll('input').forEach(function (choice) {
                choice.disabled = channels.hidden;
                if (channels.hidden) { choice.checked = choice.value === 'sms'; }
            });
            if (channels.hidden) { delete login.dataset.dgPhoneChannel; }
            syncDigitsIdentityButton(login);
        }

        function syncFrom(input, currentMode) {
            var original = input.value;
            var value = sanitizeIdentityValue(original);
            var selectedCountry = countryCode ? normalizeCountryCode(countryCode.value) : '';
            var phone = phonePartsFromValue(value, selectedCountry);
            var keepSelectedCountryPhone = !window.libphonenumber && currentMode === 'phone' && selectedCountry && selectedCountry !== '+98' && /^\d{1,18}$/.test(value);

            if (phone.type === 'phone' && !keepSelectedCountryPhone) {
                value = phone.national;
            }

            var start = input.selectionStart;
            var end = input.selectionEnd;
            function restoreCaret(field) {
                if (start === null || end === null) { return; }
                var removedPrefix = Math.max(0, sanitizeIdentityValue(original).length - value.length);
                var from = Math.max(0, sanitizeIdentityValue(original.slice(0, start)).length - removedPrefix);
                var to = Math.max(0, sanitizeIdentityValue(original.slice(0, end)).length - removedPrefix);
                field.setSelectionRange(from, to);
            }
            if (original !== value) {
                input.value = value;
                restoreCaret(input);
            }

            var smsIdentity = phoneInput.form?.dataset.dgSmsIdentity === 'true';
            var identity = phone.type === 'phone' || keepSelectedCountryPhone || (smsIdentity && !value)
                ? 'phone' : classifyIdentity(value);

            if (keepSelectedCountryPhone) {
                phone.countryCode = selectedCountry;
            }
            shell.dataset.dgIdentity = identity;

            if (identity === 'email' && currentMode !== 'email') {
                emailInput.value = value;
                phoneInput.value = '';
                selectDigitsMode('email', true);
                restoreCaret(emailInput);
                return;
            }

            if (identity === 'email') {
                phoneInput.value = '';
            }

            if (identity === 'phone' && currentMode !== 'phone') {
                phoneInput.value = value;
                emailInput.value = '';
                if (countryCode) {
                    countryCode.value = phone.countryCode || countryCode.value || config.defaultCountryCode || '+98';
                    countryCode.dispatchEvent(new Event('input', { bubbles: true }));
                }
                selectDigitsMode('phone', true);
                restoreCaret(phoneInput);
                return;
            }

            if (identity === 'phone') {
                emailInput.value = '';
                if (countryCode) {
                    countryCode.value = phone.countryCode || countryCode.value || config.defaultCountryCode || '+98';
                    countryCode.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }

            if ((identity === 'username' || identity === 'unknown') && currentMode !== 'phone') {
                phoneInput.value = value;
                emailInput.value = '';
                selectDigitsMode(identity, true);
                restoreCaret(phoneInput);
                return;
            }

            if (identity === 'username' || identity === 'unknown') {
                emailInput.value = '';
            }

            selectDigitsMode(identity, false);
        }

        phoneInput.addEventListener('input', function () {
            syncFrom(phoneInput, 'phone');
            updatePhoneChannels();
            updateEmailFeedback?.(false);
        });

        emailInput.addEventListener('input', function () {
            syncFrom(emailInput, 'email');
            updatePhoneChannels();
            updateEmailFeedback?.(false);
        });
        emailInput.addEventListener('blur', function () { updateEmailFeedback?.(true); });

        countryCode?.addEventListener('input', updatePhoneChannels);
        countryCode?.addEventListener('change', updatePhoneChannels);

        selectDigitsMode(classifyIdentity(phoneInput.value || emailInput.value), false);
        updatePhoneChannels();
        updateEmailFeedback?.(false);
        prepareDigitsCaptchaProtection();
    }

    function bindBrandedAuthNavigation() {
        var shell = document.querySelector('.dg-digits-login-shell');
        var login = shell && shell.querySelector('form.digloginpage');
        var register = shell && shell.querySelector('form.digits_register');
        if (!login || shell.dataset.dgAuthMode) { return; }

        var page = new window.URL(window.location.href);
        authRecovery = page.searchParams.get('recover') === '1';
        var lang = document.documentElement.lang;
        var persian = lang ? /^fa(?:[-_]|$)/i.test(lang) : isPersianLocale();
        var pageHeading = document.querySelector('body.login > h1.screen-reader-text');
        var signInTitle = pageHeading && pageHeading.textContent;
        function addAction(form, label, callback) {
            var actions = form.querySelector('.dg-auth-card-actions');
            if (!actions) {
                actions = document.createElement('div');
                actions.className = 'dg-auth-card-actions';
                var heading = form.querySelector('.digits-form_heading');
                if (!heading) { return; }
                heading.after(actions);
            }
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'dg-auth-back';
            var text = document.createElement('span');
            text.textContent = label;
            button.appendChild(text);
            putAuthIcon(button, 'back');
            button.addEventListener('click', callback);
            actions.appendChild(button);
        }
        function beginRecovery() {
            authRecovery = true;
            setBrandedAuthForm(shell, false);
            var title = getLabel('lostPassword', persian ? 'بازیابی رمز عبور' : 'Reset password');
            var recoveryHeading = document.createElement('span');
            recoveryHeading.className = 'dg-recovery-heading';
            recoveryHeading.textContent = title;
            login.querySelector('.digits-form_heading')?.appendChild(recoveryHeading);
            if (pageHeading) { pageHeading.textContent = title; }
            showDigitsSmsIdentity(login);
            shell.dataset.dgRecovery = 'true';
            var url = new window.URL(window.location.href);
            url.searchParams.delete('action');
            url.searchParams.set('recover', '1');
            window.history.replaceState(null, '', url);
        }
        function returnToSignIn() {
            setBrandedAuthForm(shell, false);
            authRecovery = false;
            delete shell.dataset.dgRecovery;
            delete login.dataset.dgSmsIdentity;
            login.querySelector('.dg-recovery-heading')?.remove();
            if (pageHeading) { pageHeading.textContent = signInTitle; }
            var help = login.querySelector('.dg-sms-identity-help');
            if (help) { help.hidden = true; }
            selectDigitsMode(classifyIdentity(digitsIdentityValue(login)), true);
            var url = new window.URL(window.location.href);
            url.searchParams.delete('recover');
            url.searchParams.delete('action');
            window.history.replaceState(null, '', url);
        }
        var help = document.createElement('p');
        help.className = 'dg-sms-identity-help';
        help.hidden = true;
        help.textContent = persian ? 'ایمیل یا شماره تلفن خود را برای دریافت کد تأیید وارد کنید.'
            : 'Enter your email address or phone number to receive a verification code.';
        login.querySelector('.digits-form_heading')?.after(help);
        var backLabel = persian ? 'بازگشت به ورود' : 'Back to sign in';
        addAction(login, backLabel, function () {
            var back = login.querySelector('.digits_form_back:not(.digits_hide_back)');
            if (back) { back.click(); }
            returnToSignIn();
        });
        if (register) {
            addAction(register, backLabel, returnToSignIn);
            setBrandedAuthForm(shell, false);
        }
        if (authRecovery) { beginRecovery(); }
    }

    function enforceDigitsRegisterMobile() {
        document.querySelectorAll('.dg-digits-register-shell input[name="phone"], .dg-digits-register-shell input[name="digits_reg_mobile"], .dg-digits-register-shell input[name="mobile"]').forEach(function (input) {
            input.required = true;
            input.setAttribute('required', 'required');
            input.setAttribute('autocomplete', 'tel-national');
        });
    }

    function prepareDigitsRegistrationAutofill() {
        var tokens = {
            digits_reg_name: 'given-name',
            digits_reg_lastname: 'family-name',
            digits_reg_username: 'username',
            phone: 'tel-national',
            digits_reg_mobile: 'tel-national',
            digits_reg_password: 'new-password'
        };
        document.querySelectorAll(':is(.dg-digits-login-shell, .dg-digits-register-shell) form.digits_register').forEach(function (form) {
            form.setAttribute('autocomplete', 'on');
            Object.keys(tokens).forEach(function (name) {
                var input = form.querySelector('input[name="' + name + '"]');
                if (!input) {
                    return;
                }
                input.setAttribute('autocomplete', 'section-digitalogic-register ' + tokens[name]);
                var id = 'dg-register-' + name;
                if (!input.id && !document.getElementById(id)) {
                    input.id = id;
                }
            });
        });
    }

    function bindLoginValidation() {
        if (!document.body || !document.body.classList.contains('login')) {
            return;
        }

        var form = document.querySelector('#loginform');
        var username = document.querySelector('#user_login');
        var password = document.querySelector('#user_pass');

        enhanceLoginLabels();
        if (form && username) {
            enhanceIdentityField(username);
        }
        observePasswordFields();
        bindDigitsAutoIdentity();

        if (!form || !username || !password) {
            return;
        }

        form.setAttribute('novalidate', 'novalidate');
        username.placeholder = getLabel('identityPlaceholder', 'Username, email, or mobile number');

        form.addEventListener('submit', function (event) {
            if (!String(username.value || '').trim()) {
                event.preventDefault();
                showToast(getLabel('requiredUsername', 'نام کاربری، ایمیل یا شماره موبایل را وارد کنید.'), 'warning');
                username.focus();
                return;
            }

            if (!validateSignInPassword(password)) {
                event.preventDefault();
                return;
            }

            if (window.WFLSVars && window.WFLSVars.useCAPTCHA === '1' && !document.querySelector('iframe[title="reCAPTCHA"]')) {
                showToast(getLabel('recaptchaUnavailable', 'محافظت ورود هنوز کامل بارگذاری نشده است. چند لحظه دیگر دوباره تلاش کنید.'), 'warning');
            }
        });
    }

    function validateSignInPassword(input, form) {
        if (input?.value.trim()) { return true; }
        var message = getLabel('requiredPassword', 'Enter your password.');
        if (form) {
            showDigitsInlineMessage(form, message, 'warning');
        } else {
            showToast(message, 'warning');
        }
        input?.focus({ preventScroll: true });
        return false;
    }

    function bindThemeToggles() {
        document.querySelectorAll('#wp-admin-bar-digitalogic-theme-toggle a').forEach(function (anchor) {
            anchor.addEventListener('click', toggleTheme);
        });
    }

    async function buildLoginHeroCarousel() {
        var shell = document.querySelector('.dg-digits-login-shell, .dg-digits-register-shell');
        if (!config.loginSlidesUrl || !document.body.classList.contains('login')) {
            return;
        }

        var slides;
        try {
            var presentation = await authSocketRequest('digitalogic_auth_presentation', {});
            if (presentation && Array.isArray(presentation.slides)) {
                slides = presentation.slides;
            } else {
                var response = await window.fetch(config.loginSlidesUrl, { credentials: 'same-origin' });
                slides = response.ok ? await response.json() : null;
            }
        } catch (error) {
            return;
        }
        if (!shell || !Array.isArray(slides)) {
            return;
        }
        var images = slides.flatMap(function (slide) {
            try {
                var url = new window.URL(slide.image);
                return /^https?:$/.test(url.protocol) ? [url.href] : [];
            } catch (error) {
                return [];
            }
        });
        if (!images.length) {
            return;
        }

        var hero = document.createElement('div');
        hero.className = 'dg-login-hero-carousel';
        hero.dataset.dgSlide = '0';
        var track = document.createElement('span');
        track.className = 'dg-hero-track';
        track.setAttribute('aria-hidden', 'true');
        hero.appendChild(track);
        var dots = document.createElement('span');
        dots.className = 'dg-hero-dots';
        images.forEach(function (url, index) {
            var slide = document.createElement('span');
            slide.className = 'dg-hero-slide';
            slide.style.backgroundImage = 'url("' + url.replace(/"/g, '%22') + '")';
            track.appendChild(slide);
            if (images.length > 1) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.dataset.dgSlide = String(index);
                dot.setAttribute('aria-label', getLabel('heroSlide', 'Show image %d').replace('%d', String(index + 1)));
                dots.appendChild(dot);
            }
        });

        shell.classList.add('dg-has-hero-carousel');
        shell.insertBefore(hero, shell.firstElementChild);
        if (images.length > 1) {
            hero.appendChild(dots);
            bindHeroCarousel(hero);
        } else {
            hero.classList.add('dg-hero-static');
            hero.style.setProperty('--dg-track-index', '0');
        }
    }

    function bindHeroCarousel(hero) {
        if (!hero || hero.dataset.dgCarouselReady === 'true') {
            return;
        }

        hero.dataset.dgCarouselReady = 'true';

        var track = hero.querySelector('.dg-hero-track');
        if (!track) {
            track = document.createElement('span');
            track.className = 'dg-hero-track';
            Array.prototype.slice.call(hero.querySelectorAll(':scope > .dg-hero-slide')).forEach(function (slide) {
                track.appendChild(slide);
            });
            hero.insertBefore(track, hero.firstElementChild);
        }

        var dots = Array.prototype.slice.call(hero.querySelectorAll('[data-dg-slide]'));
        var originalSlides = Array.prototype.slice.call(track.querySelectorAll('.dg-hero-slide:not([data-dg-clone])'));
        var slideCount = Math.max(1, originalSlides.length);
        var activeTrackIndex = normalizeSlide(currentSlide()) + (slideCount > 1 ? 1 : 0);
        var pendingSnapIndex = null;
        var startX = 0;
        var currentX = 0;
        var dragDelta = 0;
        var lastMoveX = 0;
        var lastMoveTime = 0;
        var dragVelocity = 0;
        var activePointerId = null;
        var activeDragType = null;
        var autoplayTimer = null;

        if (slideCount > 1 && !track.querySelector('[data-dg-clone="true"]')) {
            var firstClone = originalSlides[0].cloneNode(true);
            var lastClone = originalSlides[slideCount - 1].cloneNode(true);

            firstClone.dataset.dgClone = 'true';
            lastClone.dataset.dgClone = 'true';
            firstClone.setAttribute('aria-hidden', 'true');
            lastClone.setAttribute('aria-hidden', 'true');
            track.insertBefore(lastClone, originalSlides[0]);
            track.appendChild(firstClone);
        }

        function currentSlide() {
            return Number(hero.dataset.dgSlide || '0') || 0;
        }

        function normalizeSlide(index) {
            return ((index % slideCount) + slideCount) % slideCount;
        }

        function syncTrack(dragPx) {
            hero.style.setProperty('--dg-slide', String(currentSlide()));
            hero.style.setProperty('--dg-track-index', String(activeTrackIndex));
            hero.style.setProperty('--dg-drag', (dragPx || 0) + 'px');
            hero.style.setProperty('--dg-drag-progress', String(Math.min(1, Math.abs(dragPx || 0) / Math.max(1, hero.getBoundingClientRect().width || 1))));
        }

        function stopAutoplay() {
            window.clearInterval(autoplayTimer);
            autoplayTimer = null;
        }

        function startAutoplay() {
            stopAutoplay();

            if (slideCount < 2) {
                return;
            }

            autoplayTimer = window.setInterval(function () {
                if (activeDragType || document.hidden) {
                    return;
                }

                setSlide(currentSlide() + 1, false);
            }, 9200);
        }

        function updateDots(next) {
            dots.forEach(function (dot) {
                var active = dot.dataset.dgSlide === String(next);
                dot.classList.toggle('is-active', active);
                dot.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        function snapTrack(index) {
            activeTrackIndex = index;
            hero.classList.add('is-jump-reset');
            syncTrack(0);
            track.offsetHeight;
            window.requestAnimationFrame(function () {
                hero.classList.remove('is-jump-reset');
            });
        }

        function setSlide(index, manual) {
            var next = normalizeSlide(index);
            var targetTrackIndex = next + (slideCount > 1 ? 1 : 0);

            if (slideCount > 1 && index >= slideCount && currentSlide() === slideCount - 1) {
                targetTrackIndex = slideCount + 1;
                pendingSnapIndex = 1;
            } else if (slideCount > 1 && index < 0 && currentSlide() === 0) {
                targetTrackIndex = 0;
                pendingSnapIndex = slideCount;
            } else {
                pendingSnapIndex = null;
            }

            hero.dataset.dgSlide = String(next);
            activeTrackIndex = targetTrackIndex;

            if (manual) {
                hero.classList.add('is-manual');
                startAutoplay();
            }

            updateDots(next);
            syncTrack(0);
        }

        track.addEventListener('transitionend', function (event) {
            if (event.target !== track || event.propertyName !== 'transform' || pendingSnapIndex === null) {
                return;
            }

            snapTrack(pendingSnapIndex);
            pendingSnapIndex = null;
        });

        dots.forEach(function (dot) {
            dot.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                setSlide(Number(dot.dataset.dgSlide || '0'), true);
            });
        });

        function beginDrag(clientX, type, event) {
            if (event && event.target && event.target.closest && event.target.closest('.dg-hero-dots [data-dg-slide]')) {
                return false;
            }

            if (activeDragType) {
                return false;
            }

            stopAutoplay();
            activeDragType = type;
            startX = clientX;
            currentX = clientX;
            dragDelta = 0;
            lastMoveX = clientX;
            lastMoveTime = Date.now();
            dragVelocity = 0;
            pendingSnapIndex = null;
            hero.classList.add('is-dragging');
            syncTrack(0);

            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }

            return true;
        }

        function updateDrag(clientX, type, event) {
            if (activeDragType !== type) {
                return;
            }

            currentX = clientX;
            dragDelta = currentX - startX;
            var now = Date.now();
            var elapsed = Math.max(1, now - lastMoveTime);
            dragVelocity = (clientX - lastMoveX) / elapsed;
            lastMoveX = clientX;
            lastMoveTime = now;
            syncTrack(dragDelta);

            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
        }

        function finishDrag(clientX, type) {
            if (activeDragType !== type) {
                return;
            }

            var delta = clientX - startX;
            var width = hero.getBoundingClientRect().width || 1;
            var threshold = Math.min(118, Math.max(48, width * 0.2));
            var velocityHint = Math.abs(dragVelocity) > 0.58 && Math.abs(delta) > 18;
            var shouldMove = Math.abs(delta) >= threshold || velocityHint;
            var direction = delta < 0 ? 1 : -1;

            hero.classList.remove('is-dragging');
            activeDragType = null;
            activePointerId = null;
            dragDelta = 0;
            dragVelocity = 0;

            if (!shouldMove) {
                syncTrack(0);
                startAutoplay();
                return;
            }

            setSlide(currentSlide() + direction, true);
        }

        hero.addEventListener('pointerdown', function (event) {
            if (!beginDrag(event.clientX, 'pointer', event)) {
                return;
            }

            activePointerId = event.pointerId;

            if (hero.setPointerCapture) {
                hero.setPointerCapture(activePointerId);
            }
        });

        hero.addEventListener('pointerup', function (event) {
            if (activePointerId !== event.pointerId) {
                return;
            }

            finishDrag(event.clientX, 'pointer');
        });

        hero.addEventListener('pointermove', function (event) {
            if (activePointerId !== event.pointerId) {
                return;
            }

            updateDrag(event.clientX, 'pointer', event);
        });

        hero.addEventListener('pointercancel', function () {
            hero.classList.remove('is-dragging');
            activeDragType = null;
            activePointerId = null;
            syncTrack(0);
            startAutoplay();
        });

        hero.addEventListener('mousedown', function (event) {
            if (event.button !== 0) {
                return;
            }

            beginDrag(event.clientX, 'mouse', event);
        });

        window.addEventListener('mouseup', function (event) {
            finishDrag(event.clientX, 'mouse');
        });

        window.addEventListener('mousemove', function (event) {
            updateDrag(event.clientX, 'mouse', event);
        });

        hero.addEventListener('touchstart', function (event) {
            if (!event.touches || event.touches.length !== 1) {
                return;
            }

            beginDrag(event.touches[0].clientX, 'touch', event);
        }, { passive: false });

        hero.addEventListener('touchmove', function (event) {
            if (!event.touches || event.touches.length !== 1) {
                return;
            }

            updateDrag(event.touches[0].clientX, 'touch', event);
        }, { passive: false });

        hero.addEventListener('touchend', function (event) {
            if (!event.changedTouches || !event.changedTouches.length) {
                return;
            }

            finishDrag(event.changedTouches[0].clientX, 'touch');
        });

        hero.addEventListener('touchcancel', function () {
            hero.classList.remove('is-dragging');
            activeDragType = null;
            activePointerId = null;
            syncTrack(0);
            startAutoplay();
        });

        setSlide(currentSlide(), false);
        snapTrack(activeTrackIndex);
        startAutoplay();
    }

    function authPageAction() {
        if (!document.body || !document.body.classList) {
            return '';
        }

        if (
            document.body.classList.contains('login-action-lostpassword') ||
            document.body.classList.contains('login-action-retrievepassword') ||
            document.body.classList.contains('login-action-rp') ||
            document.body.classList.contains('login-action-resetpass')
        ) {
            return 'lostPassword';
        }

        if (document.body.classList.contains('login-action-register')) {
            return 'register';
        }

        if (document.body.classList.contains('login-action-login')) {
            return 'login';
        }

        return '';
    }

    function authLinkAction(link) {
        if (!link) {
            return '';
        }

        var rawHref = cleanText(link.getAttribute && link.getAttribute('href'), '');
        var parsed = null;
        var action = '';

        if (rawHref && rawHref.charAt(0) !== '#') {
            try {
                parsed = new window.URL(rawHref, window.location.href);
                action = cleanText(parsed.searchParams.get('action'), '').toLowerCase();
            } catch (error) {
                parsed = null;
            }
        }

        if (action === 'register') {
            return 'register';
        }

        if (['lostpassword', 'retrievepassword', 'rp', 'resetpass'].indexOf(action) !== -1) {
            return 'lostPassword';
        }

        if (action === 'login') {
            return 'login';
        }

        if (link.classList) {
            if (link.classList.contains('wp-login-register')) {
                return 'register';
            }

            if (link.classList.contains('wp-login-lost-password')) {
                return 'lostPassword';
            }

            if (link.classList.contains('wp-login-log-in')) {
                return 'login';
            }
        }

        if (link.getAttribute && link.getAttribute('aria-current') === 'page') {
            return authPageAction();
        }

        if (parsed && !action) {
            try {
                var canonical = new window.URL(config.loginUrl || '/login/', window.location.href);
                var parsedPath = parsed.pathname.replace(/\/+$/, '') || '/';
                var canonicalPath = canonical.pathname.replace(/\/+$/, '') || '/';

                if (parsed.origin === canonical.origin && parsedPath === canonicalPath) {
                    return 'login';
                }

                if (parsed.origin === canonical.origin && /\/wp-login\.php$/.test(parsedPath)) {
                    return 'login';
                }
            } catch (error) {}
        }

        return '';
    }

    function normalizeAuthNavigation() {
        var labels = {
            login: getLabel('login', 'Log in'),
            register: getLabel('register', 'Register'),
            lostPassword: getLabel('lostPassword', 'Reset password')
        };

        document.querySelectorAll('body.login #nav a').forEach(function (link) {
            var action = authLinkAction(link);
            var label = action ? labels[action] : '';

            if (label && cleanText(link.textContent, '') !== label) {
                link.textContent = label;
            }
        });
    }

    function normalizeDigitsLoginFallback() {
        var loginUrl = cleanText(config.loginUrl, '/login/');

        document.querySelectorAll('.digits-form_toggle_login_register.show_login').forEach(function (link) {
            var href = cleanText(link.getAttribute('href'), '');

            if (!href || href === '#' || /^javascript:/i.test(href)) {
                link.setAttribute('href', loginUrl);
                link.setAttribute('data-dg-http-fallback', 'login');
            }
        });
    }

    function normalizeRecoveryIdentity() {
        if (authPageAction() !== 'lostPassword') {
            return;
        }

        var label = getLabel('recoveryIdentity', 'Username or email address');
        var fieldLabel = document.querySelector('body.login #lostpasswordform label[for="user_login"]');
        var field = document.querySelector('body.login #lostpasswordform #user_login');

        if (fieldLabel && cleanText(fieldLabel.textContent, '') !== label) {
            fieldLabel.textContent = label;
        }
        if (field) {
            field.setAttribute('dir', 'auto');
        }

        var form = document.querySelector('#lostpasswordform');
        if (!form || form.querySelector('.dg-recovery-title')) {
            return;
        }

        var persian = isPersianLocale();
        var heading = document.createElement('h2');
        heading.className = 'dg-recovery-title';
        heading.textContent = getLabel('lostPassword', 'Reset password');
        putAuthIcon(heading, 'lock');
        form.prepend(heading);

        var notice = document.querySelector('#login > .notice-info.message');
        if (notice) {
            notice.classList.add('dg-recovery-instructions');
            notice.id = notice.id || 'dg-recovery-instructions';
            heading.after(notice);
            if (field) {
                var descriptions = (field.getAttribute('aria-describedby') || '').trim();
                field.setAttribute('aria-describedby', [descriptions, notice.id].filter(Boolean).join(' '));
            }
        }

        var submit = form.querySelector('#wp-submit');
        if (submit) {
            submit.value = persian ? 'ارسال پیوند بازیابی' : 'Send reset link';
        }

        var navigation = document.querySelector('body.login #nav');
        if (navigation) {
            Array.from(navigation.childNodes).forEach(function (node) {
                if (node.nodeType === 3 && /^\s*\|\s*$/.test(node.textContent)) {
                    node.remove();
                }
            });
        }
    }

    function normalizeAuthChrome() {
        if (!document.body || !document.body.classList.contains('login')) {
            return;
        }

        document.querySelectorAll('.dg-digits-login-shell .altEmail_container').forEach(function (node) {
            if (!node.classList.contains('dg-visually-hidden')) {
                node.classList.add('dg-visually-hidden');
            }

            if (!node.hidden) {
                node.hidden = true;
            }

            if (node.style.getPropertyValue('display') !== 'none' || node.style.getPropertyPriority('display') !== 'important') {
                node.style.setProperty('display', 'none', 'important');
            }

            if (node.getAttribute('aria-hidden') !== 'true') {
                node.setAttribute('aria-hidden', 'true');
            }
        });

        normalizeAuthNavigation();
        var authShell = document.querySelector('.dg-digits-login-shell');
        if (authShell) {
            var footer = document.querySelector('body.login #nav');
            if (footer) { footer.hidden = true; }
            var home = document.querySelector('#backtoblog .dg-auth-home');
            if (home) { putAuthIcon(home, home.dataset.dgExitIcon === 'back' ? 'back' : 'home'); }
        }
        document.querySelectorAll('body.login #backtoblog a').forEach(function (link) {
            var label = link.textContent.replace(/^\s*[←→]\s*/, '');
            if (label !== link.textContent) {
                link.textContent = label;
            }
        });
        document.querySelectorAll('.dg-digits-login-shell form').forEach(prepareDigitsSkipPassword);
        document.querySelectorAll(':is(.dg-digits-login-shell, .dg-digits-register-shell) form.digits_register').forEach(prepareRegistrationOtp);
        mountLoginAlternatives();
        decorateAuthControls();
        mountRememberedAccount();
        normalizeDigitsLoginFallback();
        normalizeRecoveryIdentity();
        prepareDigitsRegistrationAutofill();
    }

    var authIcons = {
        user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        home: 'M3 11l9-8 9 8 M5 10v10h14V10 M10 20v-6h4v6',
        email: 'M4 5h16v14H4z M4 6l8 6 8-6',
        eye: 'M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6S2 12 2 12Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        eyeOff: 'M3 3l18 18 M10.6 6.1 12 6c6.4 0 10 6 10 6a16 16 0 0 1-3.1 3.4 M6.1 7.9A16.4 16.4 0 0 0 2 12s3.6 6 10 6a11 11 0 0 0 4.1-.8',
        phone: 'M8 3h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z M10 17h4',
        lock: 'M7 10V7a5 5 0 0 1 10 0v3 M5 10h14v11H5z M12 14v3',
        key: 'M15 3a6 6 0 1 1-3 11L5 21H2v-3l7-7a6 6 0 0 1 6-8Z M16 8h.01',
        sms: 'M4 4h16v12H9l-5 4z M8 9h8 M8 12h5',
        arrow: 'M5 12h14m-6-6 6 6-6 6',
        back: 'M19 12H5m6-6-6 6 6 6',
        check: 'M5 12l4 4L19 6',
        info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20ZM12 11v5m0-8h.01',
        more: 'M4 7h16 M4 12h16 M4 17h16 M8 5v4 M15 10v4 M10 15v4'
    };

    function putAuthIcon(node, name) {
        if (!node || !node.querySelector) { return; }
        var previous = node.querySelector(':scope > .dg-auth-icon');
        if (previous) {
            if (previous.classList.contains('dg-auth-icon--' + name)) { return; }
            previous.remove();
        }
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.classList.add('dg-auth-icon', 'dg-auth-icon--' + name);
        var path = document.createElementNS(svg.namespaceURI, 'path');
        path.setAttribute('d', authIcons[name]);
        svg.appendChild(path);
        node.prepend(svg);
    }

    function decorateAuthControls() {
        var selectors = {
            '.digits-form_heading:not([data-call-code-title])': 'user',
            '.dg-passkey-options summary': 'more',
            '.dg-passkey-options .digitalogic-call-toggle, [data-call-start]': 'phone',
            '.dg-otp-resend': 'sms',
            '.digits-form_button_ic': 'arrow'
        };
        Object.keys(selectors).forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (node) { putAuthIcon(node, selectors[selector]); });
        });
        document.querySelectorAll('.dg-digits-login-shell, .dg-digits-register-shell').forEach(function (shell) {
            shell.querySelectorAll('input[name="phone"]').forEach(function (input) {
                var holder = input.closest('.digits-form_input') || input.parentElement;
                holder.classList.add('dg-auth-input-icon');
                putAuthIcon(holder, 'phone');
            });
        });
        document.querySelectorAll('#digits_google_login').forEach(function (button) {
            if (button.dataset.dgGoogleReady) { return; }
            button.dataset.dgGoogleReady = 'true';
            button.setAttribute('role', 'button');
            button.tabIndex = 0;
            var label = button.querySelector('.digits-social-text');
            if (label) { label.textContent = isPersianLocale() ? 'ادامه با گوگل' : 'Continue with Google'; }
            var divider = button.closest('.digits-social-logins-box').querySelector('.digits-use_social-logins');
            var list = button.closest('.digits-social-logins');
            if (divider && list) { list.before(divider); }
            if (divider && divider.firstElementChild) {
                divider.firstElementChild.textContent = isPersianLocale() ? 'یا ادامه با' : 'Or continue with';
            }
            button.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    button.click();
                }
            });
        });
    }

    function mountRememberedAccount() {
        var assigned = config.rememberedAccount;
        var accounts = Array.isArray(config.rememberedAccounts) ? config.rememberedAccounts : [];
        var identity = document.querySelector('.dg-digits-login-shell input[name="digits_phone"]');
        if ((!accounts.length && !(assigned && assigned.login)) || !identity || document.querySelector('.dg-account-hint')) { return; }
        var hint = document.createElement('details');
        hint.className = 'dg-account-hint';
        var summary = document.createElement('summary');
        summary.textContent = isPersianLocale() ? 'انتخاب حساب' : 'Choose an account';
        putAuthIcon(summary, 'user');
        hint.appendChild(summary);
        function selectAccount(value) {
            selectDigitsMode('unknown', false);
            identity.value = value;
            identity.dispatchEvent(new Event('input', { bubbles: true }));
            hint.open = false;
            identity.closest('form').querySelector('.digits-tab_active input:is([name="digits_phone"], [name="digits_email"])')?.focus();
        }
        function addChoice(label, value, account) {
            var choice = document.createElement('button');
            choice.type = 'button';
            choice.textContent = label;
            if (account && account.avatar) {
                var img = document.createElement('img');
                img.alt = '';
                img.src = account.avatar;
                choice.prepend(img);
            } else {
                putAuthIcon(choice, 'user');
            }
            choice.addEventListener('click', function () { selectAccount(value); });
            hint.appendChild(choice);
        }
        if (assigned && assigned.login) {
            addChoice(assigned.name || assigned.login, assigned.login, assigned);
            if (!identity.value) {
                identity.value = assigned.login;
                identity.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
        if (accounts.length) {
            var heading = document.createElement('p');
            heading.className = 'dg-account-hint-divider';
            heading.textContent = isPersianLocale() ? 'حساب‌های خارج‌شده' : 'Signed out';
            hint.appendChild(heading);
            accounts.forEach(function (account) {
                addChoice(account.name || account.login, account.login, account);
            });
        }
        addChoice(isPersianLocale() ? 'ورود با حساب دیگر' : 'Use another account', '');
        identity.closest('.digits-form_tab_container')?.before(hint);
    }

    function prefetchAuthPages() {
        var seen = new Set();
        document.querySelectorAll('#nav a[href]').forEach(function (anchor) {
            var target = new window.URL(anchor.href, window.location.href);
            var keys = Array.from(target.searchParams.keys());
            if (target.origin !== window.location.origin || target.pathname !== '/login/' || target.href === window.location.href
                || keys.some(function (key) { return key !== 'action' && key !== 'wp_lang'; }) || seen.has(target.href)) {
                return;
            }
            var action = target.searchParams.get('action');
            if (action && action !== 'register' && action !== 'lostpassword') { return; }
            seen.add(target.href);
            var link = document.createElement('link');
            link.rel = 'prefetch';
            link.href = target.href;
            document.head.appendChild(link);
        });
    }

    function bindAuthPressFeedback() {
        var controls = '#login :is(button, a[href], summary, [role="button"], label:has(input[type="checkbox"]))';
        var pressed;
        function release() {
            if (pressed) { pressed.classList.remove('dg-touch-down'); pressed = null; }
        }
        document.addEventListener('pointerdown', function (event) {
            release();
            if (event.button !== 0 || !event.target.closest) { return; }
            pressed = event.target.closest(controls);
            if (!pressed || pressed.matches(':disabled,[aria-disabled="true"]')) { return; }
            pressed.classList.add('dg-touch-down');
        }, { passive: true });
        window.addEventListener('pointerup', function (event) {
            if (pressed && event.pointerType === 'touch' && navigator.vibrate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                navigator.vibrate(9);
            }
            release();
        }, { passive: true });
        ['pointercancel', 'scroll'].forEach(function (name) {
            window.addEventListener(name, release, { passive: true });
        });
    }

    function mountLoginAlternatives() {
        var form = document.querySelector('.dg-digits-login-shell .digits-form_login');
        if (!form) {
            return;
        }

        var shell = form.closest('.dg-digits-login-shell');
        var widget = document.querySelector('[data-call-unified]');
        if (widget && !shell.contains(widget)) {
            shell.appendChild(widget);
        }
        var passkey = form.querySelector('.digits-form_passwordless_login');
        var login = form.closest('form') || form;
        var social = login.querySelector('.digits-social-logins-box');
        var disclosure = (social && social.querySelector('.dg-passkey-options')) || form.querySelector('.dg-passkey-options');
        if (!disclosure && passkey) {
            disclosure = document.createElement('details');
            disclosure.className = 'dg-passkey-options';
            var summary = document.createElement('summary');
            summary.textContent = getLabel('passkeyOptions', 'Other sign-in options');
            disclosure.appendChild(summary);
            form.appendChild(disclosure);
        }
        if (social && disclosure && disclosure.parentElement !== social) {
            social.appendChild(disclosure);
        }

        if (passkey && passkey.parentElement !== disclosure) {
            passkey.type = 'button';
            passkey.classList.add('dg-icon-label-action');
            var label = document.createElement('span');
            label.textContent = getLabel('passkeyLogin', 'Sign in with a passkey');
            passkey.replaceChildren(label);
            putAuthIcon(passkey, 'key');
            disclosure.appendChild(passkey);
        }
    }

    function observeAuthChrome() {
        normalizeAuthChrome();

        if (!document.body || document.body.dataset.dgAuthChromeObserver === 'true' || !window.MutationObserver) {
            return;
        }

        document.body.dataset.dgAuthChromeObserver = 'true';
        new MutationObserver(function (mutations) {
            var shouldNormalize = mutations.some(function (mutation) {
                return Array.prototype.some.call(mutation.addedNodes, function (node) {
                    return node.nodeType === 1 && (
                        node.matches && node.matches('#nav, .altEmail_container, .dg-digits-login-shell, .digits_register, .digits-form_container, .digits-form_toggle_login_register') ||
                        node.querySelector && node.querySelector('#nav, .altEmail_container, .dg-digits-login-shell, .digits_register, .digits-form_container, .digits-form_toggle_login_register')
                    );
                });
            });

            if (shouldNormalize) {
                window.requestAnimationFrame(normalizeAuthChrome);
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    function animateBrandedAuthSteps() {
        var shell = document.querySelector('.dg-digits-login-shell');
        if (!shell || !window.MutationObserver) { return; }
        var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        var previous;
        var transition;

        function activeStep() {
            if (shell.classList.contains('dg-call-code-active')) {
                return shell.querySelector('.dg-call-unified [data-call-instructions]');
            }
            var form = Array.from(shell.querySelectorAll('form:is(.digloginpage, .digits_register)')).find(isVisibleElement);
            if (!form) { return null; }
            var steps = Array.from(form.querySelectorAll('.digits-form_tab_container')).filter(isVisibleElement);
            var step = steps[steps.length - 1] || form;
            var code = step.querySelector('.dg-otp-editor');
            return code && isVisibleElement(code) ? code : step;
        }

        previous = activeStep();
        new MutationObserver(function () {
            var step = activeStep();
            if (step === previous) { return; }
            previous = step;
            transition?.cancel();
            if (!step?.animate || motion.matches) { return; }
            transition = step.animate([{ opacity: 0.58 }, { opacity: 1 }], {
                duration: 180,
                easing: 'cubic-bezier(0.2, 0.75, 0.25, 1)'
            });
        }).observe(shell, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['class', 'style', 'hidden']
        });
        motion.addEventListener?.('change', function () {
            if (motion.matches) { transition?.cancel(); }
        });
    }

    if (config.testHooks && typeof config.testHooks === 'object') {
        config.testHooks.authLinkAction = authLinkAction;
        config.testHooks.authPageAction = authPageAction;
        config.testHooks.capturePasswordSelection = capturePasswordSelection;
        config.testHooks.classifyIdentity = classifyIdentity;
        config.testHooks.enhanceLoginLabels = enhanceLoginLabels;
        config.testHooks.isDigitsAjaxRequest = isDigitsAjaxRequest;
        config.testHooks.hasUsableDigitsPayload = hasUsableDigitsPayload;
        config.testHooks.patchDigitsAjaxFailureGuard = patchDigitsAjaxFailureGuard;
        config.testHooks.bindLoadingStates = bindLoadingStates;
        config.testHooks.prepareDigitsRegistrationAutofill = prepareDigitsRegistrationAutofill;
        config.testHooks.normalizeDigits = normalizeDigits;
        config.testHooks.normalizeAuthChrome = normalizeAuthChrome;
        config.testHooks.phonePartsFromValue = phonePartsFromValue;
        config.testHooks.sanitizeIdentityValue = sanitizeIdentityValue;
    }

    function enableRipples() {
        document.addEventListener('pointerdown', function (event) {
            var target = event.target.closest('button, .button, .page-title-action, #wpadminbar a, .digits-social-btn');
            if (!target || target.disabled || target.matches('.ab-empty-item, .dg-skip-password')) {
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
        migrateLegacyStoredTheme();
        bindThemeStorageSync();
        bindThemeToggles();
        injectLoginToggle();
        buildLanguageSwitcher();
        injectLateLoginOverrides();
        buildLoginHeroCarousel();
        bindAuthPressFeedback();
        window.addEventListener('pagehide', function () { if (authSocket) { authSocket.close(); } });
        (window.requestIdleCallback || window.setTimeout)(prefetchAuthPages);
        bindLoginValidation();
        bindDigitsAutoIdentity();
        bindBrandedAuthNavigation();
        observeAuthChrome();
        animateBrandedAuthSteps();
        enforceDigitsRegisterMobile();
        scheduleDigitsAjaxFailureGuard();
        bindLoadingStates();
        observeInlineMessages();
        if (!document.body.classList.contains('login')) { enableRipples(); }
    });
})();
