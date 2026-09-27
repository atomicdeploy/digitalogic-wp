(function ($) {
	'use strict';

	function config() {
		return window.digitalogicProductIdentity || {};
	}

	function text(value) {
		return value === undefined || value === null ? '' : String(value).trim();
	}

	function productCodeIcon() {
		var namespace = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(namespace, 'svg');
		var bookmark = document.createElementNS(namespace, 'path');
		var detail = document.createElementNS(namespace, 'path');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('focusable', 'false');
		svg.setAttribute('aria-hidden', 'true');
		bookmark.setAttribute('d', 'M7 3h10a2 2 0 0 1 2 2v14l-7-3-7 3V5a2 2 0 0 1 2-2Z');
		detail.setAttribute('d', 'M9 8h6M9 12h4');
		svg.append(bookmark, detail);
		return svg;
	}

	function fillIdentity(identity, name, code, isVariable, childCodes, legacyChildReferences) {
		var settings = config();
		var nameLine;
		var codeLine;
		var label;
		var value;
		var list;
		var item;
		var itemName;
		var itemCode;
		var note;

		name = text(name);
		code = text(code);
		childCodes = Array.isArray(childCodes) ? childCodes : [];
		while (identity.firstChild) identity.removeChild(identity.firstChild);

		if (name !== '') {
			nameLine = document.createElement('div');
			nameLine.className = 'digitalogic-product-name';
			nameLine.dir = 'ltr';
			nameLine.lang = 'en';
			nameLine.textContent = name;
			identity.appendChild(nameLine);
		}

		if (code !== '') {
			codeLine = document.createElement('div');
			codeLine.className = 'digitalogic-product-code';
			label = document.createElement('span');
			label.textContent = text(settings.codeLabel) || 'کد کالا';
			value = document.createElement('code');
			value.dir = 'ltr';
			value.textContent = code;
			codeLine.appendChild(label);
			codeLine.appendChild(value);
			identity.appendChild(codeLine);
		} else if (childCodes.length) {
			codeLine = document.createElement('div');
			codeLine.className = 'digitalogic-product-code-list';
			label = document.createElement('span');
			label.textContent = 'مدل‌های قابل انتخاب';
			list = document.createElement('div');
			list.setAttribute('role', 'list');
			childCodes.forEach(function (child) {
				item = document.createElement(isVariable ? 'button' : 'span');
				item.className = 'digitalogic-product-code-item';
				item.setAttribute('role', 'listitem');
				if (isVariable) {
					item.type = 'button';
					item.setAttribute('data-product-code', text(child && child.code));
					item.setAttribute('aria-pressed', 'false');
				}
				var icon = document.createElement('span');
				icon.className = 'digitalogic-product-code-item__icon';
				icon.setAttribute('aria-hidden', 'true');
				icon.appendChild(productCodeIcon());
				itemName = document.createElement('i');
				itemName.textContent = text(child && child.name);
				itemCode = document.createElement('code');
				itemCode.dir = 'ltr';
				itemCode.textContent = text(child && child.code);
				item.appendChild(icon);
				item.appendChild(itemName);
				item.appendChild(itemCode);
				list.appendChild(item);
			});
			codeLine.appendChild(label);
			codeLine.appendChild(list);
			identity.appendChild(codeLine);
			if (legacyChildReferences) {
				note = document.createElement('p');
				note.className = 'digitalogic-product-code-note';
				note.textContent = text(settings.legacyChildNote) || 'این کدها مرجع مدل‌های ثبت‌شده هستند.';
				identity.appendChild(note);
			}
		} else if (isVariable) {
			codeLine = document.createElement('div');
			codeLine.className = 'digitalogic-product-code is-placeholder';
			label = document.createElement('span');
			label.textContent = text(settings.codeLabel) || 'کد کالا';
			value = document.createElement('em');
			value.textContent = text(settings.selectModelLabel) || 'مدل را انتخاب کنید';
			codeLine.appendChild(label);
			codeLine.appendChild(value);
			identity.appendChild(codeLine);
		}

		identity.hidden = name === '' && code === '' && !isVariable && !childCodes.length;
	}

	function singleIdentity() {
		return document.querySelector('[data-digitalogic-product-identity="single"]');
	}

	function markDuplicateSku(code, scope) {
		code = text(code);
		scope = scope && scope.querySelectorAll ? scope : document;
		scope.querySelectorAll('.product_meta .sku_wrapper').forEach(function (wrapper) {
			var sku = wrapper.querySelector('.sku');
			var duplicate = code !== '' && sku && text(sku.textContent) === code;
			wrapper.classList.toggle('digitalogic-duplicate-product-code-sku', Boolean(duplicate));
		});
	}

	function productScope($form) {
		return $form.closest('.product')[0] || $form.parent()[0] || document;
	}

	function markDuplicateLoopSkus(scope) {
		scope = scope && scope.querySelectorAll ? scope : document;
		scope.querySelectorAll('.product-grid-item, .wd-carousel-item, li.product').forEach(function (card) {
			var code = card.querySelector('[data-digitalogic-product-identity="loop"] .digitalogic-product-code code');
			var wrapper = card.querySelector('.wd-product-sku');
			var sku = wrapper ? wrapper.querySelector('.wd-sku') : null;
			var duplicate = code && sku && text(code.textContent) === text(sku.textContent);
			if (wrapper) wrapper.classList.toggle('digitalogic-duplicate-product-code-sku', Boolean(duplicate));
		});
	}

	function markDuplicateCustomerSkus(scope) {
		scope = scope && scope.querySelectorAll ? scope : document;
		scope.querySelectorAll('.digitalogic-cart-product-code').forEach(function (code) {
			var item = code.closest('.cart_item, .mini_cart_item, .woocommerce-mini-cart-item, .wc-block-cart-items__row, .woocommerce-order-details__line-item');
			var wrapper = item ? item.querySelector('.wd-product-sku') : null;
			var sku = wrapper ? wrapper.querySelector('.wd-sku') : null;
			var duplicate = sku && text(code.textContent) === text(sku.textContent);
			if (wrapper) wrapper.classList.toggle('digitalogic-duplicate-product-code-sku', Boolean(duplicate));
		});
	}

	function renderSingleProductIdentity() {
		var settings = config();
		var name = text(settings.singleProductName);
		var code = text(settings.singleProductCode);
		var isVariable = Boolean(settings.singleProductIsVariable);
		var childCodes = Array.isArray(settings.singleProductChildCodes) ? settings.singleProductChildCodes : [];
		var legacyChildReferences = Boolean(settings.singleProductLegacyChildReferences);
		var titles;
		var title;
		var identity;

		if (name === '' && code === '' && !isVariable) return;
		identity = singleIdentity();
		if (identity) {
			markDuplicateSku(code, identity.closest('.product') || document.querySelector('main'));
			return;
		}

		titles = document.querySelectorAll('main h1.product_title');
		if (titles.length !== 1) return;
		title = titles[0];
		identity = document.createElement('div');
		identity.className = 'digitalogic-product-identity';
		identity.setAttribute('data-digitalogic-product-identity', 'single');
		fillIdentity(identity, name, code, isVariable, childCodes, legacyChildReferences);
		title.insertAdjacentElement('afterend', identity);
		markDuplicateSku(code, identity.closest('.product') || document.querySelector('main'));
	}

	function variationSlot($form) {
		var $slot = $form.siblings('[data-digitalogic-variation-identity]').first();
		var slot;

		if (!$slot.length) $slot = $form.closest('.product').find('[data-digitalogic-variation-identity]').first();
		if (!$slot.length) $slot = $form.parent().find('[data-digitalogic-variation-identity]').first();
		if ($slot.length) return $slot[0];

		slot = document.createElement('div');
		slot.className = 'digitalogic-product-identity digitalogic-variation-identity';
		slot.setAttribute('data-digitalogic-variation-identity', '');
		slot.setAttribute('aria-live', 'polite');
		slot.hidden = true;
		$form[0].insertAdjacentElement('afterend', slot);
		return slot;
	}

	function renderVariationIdentity($form, variation) {
		var identity = variationSlot($form);
		var scope = productScope($form);
		var parentCode;
		var parentIdentity = scope.querySelector('[data-digitalogic-product-identity="single"]');
		var variationName;
		var variationCode;

		function selectCode(code) {
			scope.querySelectorAll('.digitalogic-product-code-item[data-product-code]').forEach(function (item) {
				var selected = code !== '' && text(item.getAttribute('data-product-code')) === code;
				item.classList.toggle('is-selected', selected);
				item.setAttribute('aria-pressed', selected ? 'true' : 'false');
			});
		}

		if (variation) {
			variationName = text(variation.digitalogic_product_name);
			variationCode = text(variation.digitalogic_product_code);
			if (variationName === '' && variationCode === '') {
				if (parentIdentity && parentIdentity.getAttribute('data-digitalogic-hidden-for-variation') === 'true') {
					parentIdentity.hidden = false;
					parentIdentity.removeAttribute('data-digitalogic-hidden-for-variation');
				}
				parentCode = scope.querySelector('[data-digitalogic-product-identity="single"] .digitalogic-product-code code');
				markDuplicateSku(parentCode ? text(parentCode.textContent) : '', scope);
				fillIdentity(identity, '', '', false, [], false);
				return;
			}
			selectCode(variationCode);
			markDuplicateSku(variationCode, scope);
			fillIdentity(
				identity,
				variationName,
				variationCode,
				false,
				[],
				false
			);
			return;
		}

		selectCode('');
		parentCode = scope.querySelector('[data-digitalogic-product-identity="single"] .digitalogic-product-code code');
		markDuplicateSku(parentCode ? text(parentCode.textContent) : '', scope);
		fillIdentity(identity, '', '', false, [], false);
	}

	$(function () {
		renderSingleProductIdentity();
		markDuplicateLoopSkus(document);
		markDuplicateCustomerSkus(document);
	});

	$(document)
		.ajaxComplete(function () {
			markDuplicateLoopSkus(document);
			markDuplicateCustomerSkus(document);
		})
		.on('found_variation', '.variations_form', function (event, variation) {
			renderVariationIdentity($(this), variation);
		})
		.on('reset_data hide_variation', '.variations_form', function () {
			renderVariationIdentity($(this), null);
		})
		.on('click', '.digitalogic-product-code-item[data-product-code]', function () {
			var code = text(this.getAttribute('data-product-code'));
			if (code === '') return;
			document.dispatchEvent(new CustomEvent('digitalogic:select-product-code', {
				detail: { code: code, identity: this.closest('.digitalogic-product-identity') }
			}));
		});
}(jQuery));
