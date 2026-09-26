(() => {
    'use strict';

    const config = window.DigitalogicCheckoutExperience || {};
    const messages = config.messages || {};
    const formSelector = 'form.checkout';
    const noticeClass = 'digitalogic-checkout-notice';
    const errorClass = 'digitalogic-field-error';
    let refreshTimer = null;

    const deliveryFields = [
        {
            selector: '#jckwds-delivery-date',
            required: Boolean(config.deliveryDateRequired ?? config.deliveryRequired),
        },
        {
            selector: '#jckwds-delivery-time',
            required: Boolean(config.deliveryTimeRequired ?? config.deliveryRequired),
        },
    ];

    const deliveryRequirement = (control) => deliveryFields.find(({ selector }) => selector === `#${control.id}`)?.required;

    const persianDigits = (value) => String(value).replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);

    const gregorianToJalali = (year, month, day) => {
        const monthDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        let jalaliYear;
        if (year > 1600) {
            jalaliYear = 979;
            year -= 1600;
        } else {
            jalaliYear = 0;
            year -= 621;
        }

        const adjustedYear = month > 2 ? year + 1 : year;
        let days = (365 * year) + Math.floor((adjustedYear + 3) / 4)
            - Math.floor((adjustedYear + 99) / 100) + Math.floor((adjustedYear + 399) / 400)
            - 80 + day + monthDays[month - 1];
        jalaliYear += 33 * Math.floor(days / 12053);
        days %= 12053;
        jalaliYear += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) {
            jalaliYear += Math.floor((days - 1) / 365);
            days = (days - 1) % 365;
        }

        const jalaliMonth = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
        const jalaliDay = days < 186 ? 1 + (days % 31) : 1 + ((days - 186) % 30);
        return [jalaliYear, jalaliMonth, jalaliDay];
    };

    const formatJalaliYmd = (value) => {
        const match = String(value || '').match(/^(\d{4})(\d{2})(\d{2})$/);
        if (!match) {
            return '';
        }
        const [year, month, day] = gregorianToJalali(Number(match[1]), Number(match[2]), Number(match[3]));
        return persianDigits(`${year}/${String(month).padStart(2, '0')}/${String(day).padStart(2, '0')}`);
    };

    const syncDeliveryDateDisplay = (form) => {
        const input = form.querySelector('#jckwds-delivery-date');
        const machineInput = form.querySelector('#jckwds-delivery-date-ymd');
        const host = input?.parentElement;
        if (!input || !machineInput || !host) {
            return;
        }

        let display = host.querySelector('.digitalogic-jalali-date-display');
        const jalali = formatJalaliYmd(machineInput.value);
        if (!jalali) {
            host.classList.remove('digitalogic-jalali-date-host');
            input.classList.remove('digitalogic-has-jalali-display');
            input.removeAttribute('data-jalali-date');
            display?.remove();
            return;
        }

        if (!display) {
            display = document.createElement('span');
            display.className = 'digitalogic-jalali-date-display';
            display.setAttribute('aria-hidden', 'true');
            input.insertAdjacentElement('afterend', display);
        }
        display.textContent = jalali;
        host.classList.add('digitalogic-jalali-date-host');
        input.classList.add('digitalogic-has-jalali-display');
        input.dataset.jalaliDate = jalali;
        input.setAttribute('aria-label', `تاریخ تحویل ${jalali}`);
    };

    const isVisible = (element) => {
        if (!element || element.disabled || element.type === 'hidden') {
            return false;
        }

        if (element.offsetWidth || element.offsetHeight || element.getClientRects().length) {
            return true;
        }

        if (element.classList.contains('select2-hidden-accessible')) {
            const select2 = element.parentElement?.querySelector('.select2-container');
            return Boolean(select2 && (select2.offsetWidth || select2.offsetHeight || select2.getClientRects().length));
        }

        if (['checkbox', 'radio'].includes(element.type)) {
            const visualGroup = element.closest('li, .form-row, .woocommerce-terms-and-conditions-wrapper');
            return Boolean(visualGroup && (visualGroup.offsetWidth || visualGroup.offsetHeight || visualGroup.getClientRects().length));
        }

        return false;
    };

    const syncDeliveryRequirement = (form) => {
        deliveryFields.forEach(({ selector, required }) => {
            const control = form.querySelector(selector);
            const row = control?.closest('.form-row, .wds-fieldbox-sub-field');
            if (!control || !row) {
                return;
            }

            if (required) {
                return;
            }

            control.required = false;
            control.removeAttribute('aria-required');
            row.classList.remove('validate-required', 'woocommerce-invalid-required-field');
            row.classList.add('validate-optional');
            row.querySelector('label .required')?.remove();

            const label = row.querySelector('label');
            if (label && !/(اختیاری|optional)/iu.test(label.textContent || '')) {
                const optional = document.createElement('span');
                optional.className = 'digitalogic-optional-label';
                optional.textContent = ' (اختیاری)';
                label.append(optional);
            }
        });
    };

    const requiredControls = (form) => {
        syncDeliveryRequirement(form);
        const controls = new Set();
        form.querySelectorAll('.validate-required input, .validate-required select, .validate-required textarea').forEach((control) => {
            if (deliveryRequirement(control) === false) {
                control.required = false;
                control.removeAttribute('aria-required');
                return;
            }
            if (isVisible(control) && !['checkbox', 'radio'].includes(control.type)) {
                controls.add(control);
            }
        });

        deliveryFields.forEach(({ selector, required }) => {
            if (required) {
                const control = form.querySelector(selector);
                if (isVisible(control)) {
                    controls.add(control);
                }
            }
        });

        return [...controls];
    };

    const fieldLabel = (control) => {
        const row = control.closest('.form-row, .wds-fieldbox-sub-field');
        const label = row?.querySelector('label');
        const text = label?.textContent
            ?.replace(/[\s*]+$/u, '')
            .replace(/\(اختیاری\)|\(optional\)/giu, '')
            .trim();

        return text || control.getAttribute('aria-label') || control.getAttribute('placeholder') || messages.fieldFallback || 'this field';
    };

    const completeFieldMessage = (control) => {
        const template = messages.completeField || 'Please complete %s.';
        return template.replace('%s', `«${fieldLabel(control)}»`);
    };

    const invalidFieldMessage = (control) => {
        const template = messages.invalidField || 'Please enter a valid value for %s.';
        return template.replace('%s', `«${fieldLabel(control)}»`);
    };

    const fieldErrorContainer = (control) => control.closest('.form-row, .wds-fieldbox-sub-field') || control.parentElement;

    const clearFieldError = (control) => {
        control.removeAttribute('aria-invalid');
        control.setCustomValidity('');
        fieldErrorContainer(control)?.querySelector(`.${errorClass}`)?.remove();
    };

    const showFieldError = (control, message) => {
        const container = fieldErrorContainer(control);
        control.setAttribute('aria-invalid', 'true');
        control.setCustomValidity(message);
        container?.classList.add('digitalogic-field-invalid');

        let error = container?.querySelector(`.${errorClass}`);
        if (!error && container) {
            error = document.createElement('span');
            error.className = errorClass;
            error.setAttribute('role', 'alert');
            container.append(error);
        }
        if (error) {
            error.textContent = message;
        }
    };

    const controlIsEmpty = (control) => {
        if (control.tagName === 'SELECT') {
            return !control.value || control.value === '0';
        }

        return !String(control.value || '').trim();
    };

    const addressIsComplete = (form) => {
        const addressSelectors = ['#billing_country', '#billing_state', '#billing_city', '#billing_address_1', '#billing_postcode'];
        return addressSelectors.every((selector) => {
            const control = form.querySelector(selector);
            return !control || !isVisible(control) || !controlIsEmpty(control);
        });
    };

    const shippingValidation = (form) => {
        if (!config.needsShipping) {
            return null;
        }

        const methods = [...form.querySelectorAll('input[name^="shipping_method"]')].filter(isVisible);
        if (!methods.length) {
            return addressIsComplete(form) ? (messages.shippingUnavailable || messages.chooseShipping) : null;
        }

        return methods.some((method) => method.checked) ? null : messages.chooseShipping;
    };

    const paymentValidation = (form) => {
        const methods = [...form.querySelectorAll('input[name="payment_method"]')].filter(isVisible);
        if (!methods.length) {
            return null;
        }

        return methods.some((method) => method.checked) ? null : messages.choosePayment;
    };

    const termsValidation = (form) => {
        const terms = form.querySelector('#terms');
        if (!terms || !isVisible(terms)) {
            return null;
        }

        terms.required = true;
        terms.setAttribute('aria-required', 'true');
        return terms.checked ? null : messages.acceptTerms;
    };

    const renderNotice = (form, errors) => {
        form.querySelector(`.${noticeClass}`)?.remove();
        if (!errors.length) {
            return;
        }

        const notice = document.createElement('div');
        notice.className = noticeClass;
        notice.setAttribute('role', 'alert');
        notice.setAttribute('tabindex', '-1');

        const title = document.createElement('strong');
        title.textContent = messages.reviewFields || 'Please review the highlighted fields before placing the order.';
        notice.append(title);

        const list = document.createElement('ul');
        [...new Set(errors)].forEach((message) => {
            const item = document.createElement('li');
            item.textContent = message;
            list.append(item);
        });
        notice.append(list);
        form.prepend(notice);
        notice.focus({ preventScroll: true });
    };

    const readinessElement = (form) => {
        const button = form.querySelector('#place_order');
        if (!button) {
            return null;
        }

        let status = form.querySelector('.digitalogic-checkout-readiness');
        if (!status) {
            status = document.createElement('p');
            status.className = 'digitalogic-checkout-readiness';
            status.setAttribute('aria-live', 'polite');
            button.insertAdjacentElement('beforebegin', status);
        }
        return status;
    };

    const validate = (form, render = false) => {
        const controls = requiredControls(form);
        const errors = [];
        let firstInvalid = null;

        controls.forEach((control) => {
            control.required = true;
            control.setAttribute('aria-required', 'true');
            clearFieldError(control);
            fieldErrorContainer(control)?.classList.remove('digitalogic-field-invalid');

            if (controlIsEmpty(control)) {
                const message = completeFieldMessage(control);
                errors.push(message);
                firstInvalid ||= control;
                if (render) {
                    showFieldError(control, message);
                }
            } else if (!control.checkValidity()) {
                const message = invalidFieldMessage(control);
                errors.push(message);
                firstInvalid ||= control;
                if (render) {
                    showFieldError(control, message);
                }
            }
        });

        const shippingError = shippingValidation(form);
        if (shippingError) {
            errors.push(shippingError);
            const shippingControl = form.querySelector('input[name^="shipping_method"]');
            firstInvalid ||= shippingControl;
        }

        const paymentError = paymentValidation(form);
        if (paymentError) {
            errors.push(paymentError);
            firstInvalid ||= form.querySelector('input[name="payment_method"]');
        }

        const termsError = termsValidation(form);
        if (termsError) {
            errors.push(termsError);
            firstInvalid ||= form.querySelector('#terms');
        }

        const button = form.querySelector('#place_order');
        const status = readinessElement(form);
        const valid = errors.length === 0;
        if (button) {
            button.classList.toggle('digitalogic-checkout-incomplete', !valid);
            button.dataset.checkoutReady = valid ? 'true' : 'false';
        }
        if (status) {
            status.classList.toggle('is-ready', valid);
            const statusText = valid ? (messages.ready || '') : (messages.incomplete || '');
            if (status.textContent !== statusText) {
                status.textContent = statusText;
            }
        }

        if (render) {
            renderNotice(form, errors);
            firstInvalid?.focus({ preventScroll: true });
            firstInvalid?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        return valid;
    };

    const blockInvalidAttempt = (event) => {
        const form = event.currentTarget.matches?.(formSelector)
            ? event.currentTarget
            : event.currentTarget.closest?.(formSelector) || event.target.closest?.(formSelector);
        if (form && !validate(form, true)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    };

    const bindForm = (form) => {
        const button = form.querySelector('#place_order');
        if (button && !button.dataset.digitalogicValidationBound) {
            button.dataset.digitalogicValidationBound = 'true';
            button.addEventListener('click', blockInvalidAttempt, true);
        }

        if (!form.dataset.digitalogicValidationBound) {
            form.dataset.digitalogicValidationBound = 'true';
            form.addEventListener('submit', blockInvalidAttempt, true);
        }
    };

    const syncForm = () => {
        const form = document.querySelector(formSelector);
        if (form) {
            bindForm(form);
            syncDeliveryDateDisplay(form);
            validate(form, false);
        }
    };

    const scheduleSync = () => {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(syncForm, 40);
    };

    document.addEventListener('click', (event) => {
        if (!event.target.closest('#place_order')) {
            return;
        }

        blockInvalidAttempt(event);
    }, true);

    document.addEventListener('submit', (event) => {
        if (!event.target.matches(formSelector)) {
            return;
        }

        blockInvalidAttempt(event);
    }, true);

    document.addEventListener('input', scheduleSync, true);
    document.addEventListener('change', scheduleSync, true);
    document.addEventListener('DOMContentLoaded', syncForm);

    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', scheduleSync);
    }

    const observer = new MutationObserver((records) => {
        const checkoutChanged = records.some((record) => {
            if (!record.addedNodes.length && !record.removedNodes.length) {
                return false;
            }

            const feedbackSelector = '.digitalogic-checkout-readiness, .digitalogic-checkout-notice, .digitalogic-field-error';
            if (record.target.closest?.(feedbackSelector)) {
                return false;
            }

            return [...record.addedNodes, ...record.removedNodes].some((node) => {
                if (node.nodeType !== Node.ELEMENT_NODE) {
                    return true;
                }

                return !node.matches(feedbackSelector) && !node.closest(feedbackSelector);
            });
        });
        if (checkoutChanged) {
            scheduleSync();
        }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
