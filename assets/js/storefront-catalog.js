(function($) {
	'use strict';

	var toastTimer = null;
	var catalogNavigationId = 0;
	var activeCatalogRequest = null;
	var catalogConfig = window.digitalogicCatalog || {};

	function showToast(message) {
		var toast = document.querySelector('.dgl-catalog-toast');
		if (!toast) return;
		window.clearTimeout(toastTimer);
		toast.textContent = message;
		toast.hidden = false;
		toastTimer = window.setTimeout(function() {
			toast.hidden = true;
		}, 3600);
	}

	function catalogPayload(url) {
		var target;

		try {
			target = new window.URL(url, window.location.href);
		} catch (error) {
			return null;
		}

		if (target.origin !== window.location.origin) {
			return null;
		}

		return {
			target: target,
			data: {
				dgl_search: target.searchParams.get('dgl_search') || '',
				dgl_category: target.searchParams.get('dgl_category') || '0',
				dgl_sort: target.searchParams.get('dgl_sort') || 'recommended',
				dgl_page: target.searchParams.get('dgl_page') || '1',
				base_url: catalogConfig.baseUrl || (target.origin + target.pathname)
			}
		};
	}

	function normalizeCatalogResponse(response) {
		if (response && response.success === true && response.data) {
			return response.data;
		}

		return response;
	}

	function validCatalogResponse(response) {
		return response && typeof response.html === 'string' && response.page && response.max_pages;
	}

	function requestCatalogPage(payload) {
		var deferred = $.Deferred();
		var fallbackRequest = null;
		var aborted = false;

		function resolve(response) {
			response = normalizeCatalogResponse(response);
			if (aborted) return;

			if (!validCatalogResponse(response)) {
				deferred.reject({message: 'Invalid catalog response'});
				return;
			}

			deferred.resolve(response);
		}

		function fallback() {
			if (aborted || fallbackRequest) return;

			fallbackRequest = $.ajax({
				url: catalogConfig.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php',
				type: 'GET',
				dataType: 'json',
				digitalogicWebSocket: false,
				data: $.extend({
					action: catalogConfig.pageAction || 'digitalogic_catalog_page'
				}, payload)
			});

			fallbackRequest.done(resolve).fail(function() {
				if (!aborted) deferred.reject.apply(deferred, arguments);
			});
		}

		if (typeof window.digitalogicFrontendSearchWsRequest === 'function') {
			try {
				window.digitalogicFrontendSearchWsRequest(
					catalogConfig.pageAction || 'digitalogic_catalog_page',
					payload
				).done(resolve).fail(fallback);
			} catch (error) {
				fallback();
			}
		} else {
			fallback();
		}

		return deferred.promise({
			abort: function() {
				aborted = true;
				if (fallbackRequest && fallbackRequest.abort) {
					fallbackRequest.abort();
				}
				deferred.reject({message: 'Catalog request aborted'});
			}
		});
	}

	function setCatalogLoading(catalog, loading, message) {
		var results = catalog.querySelector('.dgl-catalog-results');
		var status = catalog.querySelector('.dgl-catalog-load-status');

		if (results) {
			results.classList.toggle('is-loading', loading);
			results.setAttribute('aria-busy', loading ? 'true' : 'false');
		}

		if (status) {
			status.textContent = message || '';
		}
	}

	function markCatalogHistory(url, replace) {
		if (!window.history || !window.history.pushState) return;

		var current = window.history.state;
		var state = current && typeof current === 'object' ? $.extend({}, current) : {};
		state.digitalogicCatalog = {url: url};

		if (replace) {
			window.history.replaceState(state, '', url);
		} else {
			window.history.pushState(state, '', url);
		}
	}

	function focusCatalogResults(results, shouldScroll) {
		try {
			results.focus({preventScroll: true});
		} catch (error) {
			results.focus();
		}

		if (!shouldScroll) return;

		var top = results.getBoundingClientRect().top + window.pageYOffset - 24;
		if (window.pageYOffset <= top) return;

		var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		window.scrollTo({top: top, behavior: reducedMotion ? 'auto' : 'smooth'});
	}

	function navigateCatalog(catalog, url, options) {
		var parsed = catalogPayload(url);
		var results = catalog.querySelector('.dgl-catalog-results');
		options = options || {};

		if (!parsed || !results) {
			return false;
		}

		var navigationId = ++catalogNavigationId;
		if (activeCatalogRequest && activeCatalogRequest.abort) {
			activeCatalogRequest.abort();
		}

		setCatalogLoading(catalog, true, catalogConfig.pageLoading || 'در حال بارگذاری محصولات...');
		activeCatalogRequest = requestCatalogPage(parsed.data);

		activeCatalogRequest.done(function(response) {
			if (navigationId !== catalogNavigationId) return;

			results.innerHTML = response.html;
			results.setAttribute('data-page', response.page);
			results.setAttribute('data-total-pages', response.max_pages);

			var count = catalog.querySelector('.dgl-catalog-count strong');
			if (count && response.found_posts_label) {
				count.textContent = response.found_posts_label;
			}

			if (options.push !== false) {
				markCatalogHistory(parsed.target.href, false);
			}

			setCatalogLoading(catalog, false, catalogConfig.pageLoaded || 'صفحه محصولات بارگذاری شد.');
			focusCatalogResults(results, options.scroll !== false);
			activeCatalogRequest = null;
		}).fail(function() {
			if (navigationId !== catalogNavigationId) return;

			var message = catalogConfig.pageError || 'بارگذاری سریع انجام نشد؛ صفحه معمولی باز می‌شود.';
			setCatalogLoading(catalog, false, message);
			activeCatalogRequest = null;

			if (options.hardFallback !== false) {
				window.location.assign(parsed.target.href);
			} else {
				showToast(message);
			}
		});

		return true;
	}

	$(document).on('click', '.dgl-catalog-pagination a', function(event) {
		if (
			event.isDefaultPrevented() ||
			event.which !== 1 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey ||
			this.hasAttribute('download') ||
			(this.target && this.target !== '_self')
		) {
			return;
		}

		var catalog = this.closest('.dgl-catalog');
		if (!catalog || !catalogPayload(this.href)) return;

		event.preventDefault();
		navigateCatalog(catalog, this.href, {push: true, scroll: true, hardFallback: true});
	});

	window.addEventListener('popstate', function(event) {
		if (!event.state || !event.state.digitalogicCatalog) return;

		var catalog = document.querySelector('.dgl-catalog');
		if (catalog) {
			navigateCatalog(catalog, window.location.href, {push: false, scroll: false, hardFallback: true});
		}
	});

	$(function() {
		if (document.querySelector('.dgl-catalog')) {
			markCatalogHistory(window.location.href, true);
		}
	});

	$(document).on('input change', '.dgl-quick-qty', function() {
		var qty = Math.max(1, parseInt(this.value, 10) || 1);
		var row = this.closest('tr');
		var button = row ? row.querySelector('.dgl-quick-button') : null;
		this.value = qty;
		if (button) {
			$(button).data('quantity', qty).attr('data-quantity', qty);
		}
	});

	$(document.body).on('adding_to_cart', function(event, button) {
		if (!button || !button.hasClass('dgl-quick-button')) return;
		button.addClass('loading').attr('aria-busy', 'true');
	});

	$(document.body).on('added_to_cart', function(event, fragments, cartHash, button) {
		if (!button || !button.hasClass('dgl-quick-button')) return;
		button.removeClass('loading').addClass('added').removeAttr('aria-busy');
		button.find('span').text('اضافه شد');
		showToast((window.digitalogicCatalog && digitalogicCatalog.added) || 'به سبد خرید اضافه شد.');
		window.setTimeout(function() {
			button.removeClass('added').find('span').text('افزودن سریع');
		}, 2600);
	});

	$(document).ajaxError(function(event, request, settings) {
		var url = settings && settings.url ? settings.url : '';
		if (url.indexOf('wc-ajax=add_to_cart') === -1) return;

		var buttons = $('.dgl-quick-button[aria-busy="true"]');
		if (!buttons.length) return;

		// WooCommerce's completion callback runs after ajaxError and can add
		// the "added" class even for a failed request, so reset on the next tick.
		window.setTimeout(function() {
			buttons.removeClass('loading added').removeAttr('aria-busy');
			buttons.find('span').text('افزودن سریع');
			showToast((window.digitalogicCatalog && digitalogicCatalog.error) || 'اضافه نشد؛ یه بار دیگه امتحانش کن.');
		}, 0);
	});
})(jQuery);
