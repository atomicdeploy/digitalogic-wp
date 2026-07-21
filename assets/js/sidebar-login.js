(() => {
    'use strict';

    const sidebarSelector = '.login-form-side';
    const controlSelector = [
        '.digits-form_tab-item',
        '.digits-form_link',
        '.digits_password_eye',
    ].join(',');
    const keyboardAttribute = 'data-digitalogic-keyboard-control';
    const noticeClass = 'digitalogic-sidebar-login-notice';
    let sidebarNoticePending = false;
    let sidebarNoticeTimer = 0;

    const isNativeControl = (element) => element.matches(
        'a[href],button,input,select,textarea,summary'
    );

    const enhanceControl = (control) => {
        if (!(control instanceof HTMLElement) || !control.closest(sidebarSelector)) {
            return;
        }

        if (control.classList.contains('digits-form_tab-item')) {
            control.setAttribute('role', 'tab');
            control.setAttribute(
                'aria-selected',
                control.classList.contains('digits-tab_active') ? 'true' : 'false'
            );
        } else if (!isNativeControl(control)) {
            control.setAttribute('role', 'button');
        }

        if (!isNativeControl(control)) {
            control.tabIndex = 0;
            control.setAttribute(keyboardAttribute, 'true');
        }

        if (
            control.classList.contains('digits_password_eye')
            && !control.hasAttribute('aria-label')
        ) {
            const isPersian = document.documentElement.lang.toLowerCase().startsWith('fa');
            control.setAttribute(
                'aria-label',
                isPersian ? 'نمایش یا پنهان کردن رمز عبور' : 'Show or hide password'
            );
        }
    };

    const enhanceSidebar = (sidebar) => {
        if (!(sidebar instanceof HTMLElement)) {
            return;
        }

        sidebar.querySelectorAll('.digits-form_tab-bar').forEach((tabList) => {
            tabList.setAttribute('role', 'tablist');
        });
        sidebar.querySelectorAll(controlSelector).forEach(enhanceControl);
    };

    const enhanceAll = () => {
        document.querySelectorAll(sidebarSelector).forEach(enhanceSidebar);
    };

    const expectSidebarNotice = () => {
        sidebarNoticePending = true;
        window.clearTimeout(sidebarNoticeTimer);
        sidebarNoticeTimer = window.setTimeout(() => {
            sidebarNoticePending = false;
        }, 30000);
    };

    const makeKeyboardButton = (control, label) => {
        control.setAttribute('role', 'button');
        control.setAttribute('tabindex', '0');
        control.setAttribute(keyboardAttribute, 'true');
        if (label) {
            control.setAttribute('aria-label', label);
        }
    };

    const markSidebarNotice = (node) => {
        if (!(node instanceof Element) || !sidebarNoticePending) {
            return;
        }

        const notices = node.matches('.dig_popmessage')
            ? [node]
            : Array.from(node.querySelectorAll('.dig_popmessage'));
        if (!notices.length) {
            return;
        }

        const isPersian = document.documentElement.lang.toLowerCase().startsWith('fa');
        notices.forEach((notice) => {
            notice.classList.add(noticeClass);
            notice.setAttribute('role', 'alert');
            notice.setAttribute('aria-live', 'assertive');

            const dismiss = notice.querySelector('.dig_popdismiss');
            if (dismiss) {
                makeKeyboardButton(dismiss, isPersian ? 'بستن پیام' : 'Dismiss message');
            }
        });

        sidebarNoticePending = false;
        window.clearTimeout(sidebarNoticeTimer);
    };

    const enhanceWithin = (node) => {
        if (!(node instanceof Element)) {
            return;
        }

        const containingSidebar = node.closest(sidebarSelector);
        if (containingSidebar) {
            enhanceSidebar(containingSidebar);
        }

        node.querySelectorAll(sidebarSelector).forEach(enhanceSidebar);
    };

    document.addEventListener('keydown', (event) => {
        const control = event.target instanceof Element
            ? event.target.closest(`[${keyboardAttribute}="true"]`)
            : null;
        const inSidebar = control?.closest(sidebarSelector);
        const inSidebarNotice = control?.closest(`.${noticeClass}`);
        if (!control || (!inSidebar && !inSidebarNotice)) {
            return;
        }

        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        event.preventDefault();
        if (!event.repeat && (!inSidebar || !control.closest('.digits_form_processing'))) {
            control.click();
        }
    });

    document.addEventListener('submit', (event) => {
        if (event.target instanceof Element && event.target.closest(sidebarSelector)) {
            expectSidebarNotice();
        }
    }, true);

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element
            ? event.target.closest([
                `${sidebarSelector} .digits-form_submit-btn`,
                `${sidebarSelector} .digits-form_otp_selector`,
                `${sidebarSelector} .digits-form_resend_otp`,
                `${sidebarSelector} .digits-form_show_forgot_password`,
            ].join(','))
            : null;
        if (trigger) {
            expectSidebarNotice();
        }
    }, true);

    const start = () => {
        enhanceAll();

        const observer = new MutationObserver((records) => {
            records.forEach((record) => {
                if (record.type === 'attributes') {
                    enhanceWithin(record.target);
                    return;
                }

                record.addedNodes.forEach((node) => {
                    enhanceWithin(node);
                    markSidebarNotice(node);
                });
            });
        });
        observer.observe(document.body, {
            attributes: true,
            attributeFilter: ['class'],
            childList: true,
            subtree: true,
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
