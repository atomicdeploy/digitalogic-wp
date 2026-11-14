/* global woodmart_settings */
(function($) {
	woodmartThemeModule.$document.on('wdShopPageInit', function () {
		woodmartThemeModule.ajaxSearch();
	});

	$.each([
		'frontend/element_ready/wd_search.default'
	], function(index, value) {
		woodmartThemeModule.wdElementorAddAction(value, function() {
			woodmartThemeModule.ajaxSearch();
		});
	});

	woodmartThemeModule.ajaxSearch = function() {
		if (typeof ($.fn.devbridgeAutocomplete) == 'undefined') {
			return;
		}

		var escapeRegExChars = function(value) {
			return value.replace(/[\-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, '\\$&');
		};

		var escapeHtml = function(value) {
			return String(value)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		};

		/**
		 * Titles come from the server as plain text. Match on the raw text and escape
		 * every part separately, so the highlight never lands inside an HTML entity.
		 */
		var highlightTitle = function(title, query) {
			if (!query) {
				return escapeHtml(title);
			}

			return String(title).split(new RegExp('(' + escapeRegExChars(query) + ')', 'gi')).map(function(part, index) {
				return index % 2 ? '<strong>' + escapeHtml(part) + '</strong>' : escapeHtml(part);
			}).join('');
		};

		$('form.woodmart-ajax-search').each(function() {
			var $this         = $(this),
			    number        = parseInt($this.data('count')),
			    thumbnail     = parseInt($this.data('thumbnail')),
			    symbols_count = parseInt($this.data('symbols_count')),
			    productCat    = $this.find('[name="product_cat"]'),
				$parent       = $this.parent(),
			    postType      = $this.data('post_type'),
			    url           = woodmart_settings.ajaxurl + '?action=woodmart_ajax_search',
			    price         = parseInt($this.data('price')),
			    sku           = $this.data('sku'),
				isFullScreen  = $this.parents('.wd-search-full-screen').length,
				isFullScreen2 = $this.parents('.wd-search-full-screen-2').length,
				isDropdown    = $this.parents('.wd-search-dropdown').length,
				$results      = $parent.find(`.wd-search-results${ isFullScreen || isFullScreen2 ? '' : ' > ' }.wd-scroll-content`),
				$parentResult = $parent.find('.wd-search-results');

			var	enqueueProductCatResults = $this.data('include_cat_search');

			if (number > 0) {
				url += '&number=' + number;
			}
			url += '&post_type=' + postType;

			if (productCat.length && productCat.val() !== '') {
				url += '&product_cat=' + productCat.val();
			}

			if (enqueueProductCatResults && 'yes' === enqueueProductCatResults) {
				url += '&include_cat_search=' + enqueueProductCatResults;
			}

			$this.find('[type="text"]').on('focus keyup cat_selected', function(e) {
				let $input         = $(this);
				let serviceUrlData = {
					'action': 'woodmart_ajax_search',
					'number': number > 0 ? number : undefined,
					'post_type': postType,
				};

				if ( ! $input.hasClass('wd-search-inited') ) {
					$input.devbridgeAutocomplete({
						serviceUrl      : url,
						appendTo        : $results,
						minChars        : symbols_count,
						deferRequestBy  : woodmart_settings.ajax_search_delay,
						onHide          : function(container, isClearBtn) {
							if ( isFullScreen2 ) {
								$parentResult.removeClass('wd-no-results');
							}

							var $formWrapper          = isFullScreen2 ? $this.parent().parent() : $this.parent();
							var isBeforeSearchContent = 'function' === typeof woodmartThemeModule.beforeSearchcontent && $formWrapper.find('.wd-search-history, .wd-search-requests, .wd-search-area').length;

							if (!isClearBtn && !isBeforeSearchContent) {
								overlayBackground('close');
							}

							if (!isBeforeSearchContent) {
								$formWrapper.find('.wd-search-results').removeClass('wd-opened');
							}

							if (isClearBtn || isFullScreen2 || isFullScreen) {
								$formWrapper.removeClass('wd-searched');
							} else if ( ! isBeforeSearchContent ) {
								setTimeout(function() {
									$formWrapper.removeClass('wd-searched');
								}, 400);
							}
						},
						onSearchStart   : function() {
							$this.addClass('wd-search-loading');
						},
						beforeRender    : function(container) {
							if (!isDropdown) {
								overlayBackground('open');
							}

							$(container).find('.wd-not-found-msg').parent().addClass('wd-not-found');

							var showViewAllBtn = $(container).find('.wd-suggestion:not(.wd-not-found)').length > 0;

							if (! $(container).find('[class*="wd-type-"]')) {
								showViewAllBtn = container[0].childElementCount > 2;
							}

							if (showViewAllBtn) {
								var formData  = $this.serializeArray();
								var submitUrl = $this.attr('action') + '?' + $.param(formData);

								$(container).append('<a class="wd-all-results" href="' + submitUrl + '">' + woodmart_settings.all_results + '</a>');
							}

							$(container).removeAttr('style');
						},
						onSelect: function(suggestion) {
							if (suggestion.permalink.length > 0) {
								window.location.href = suggestion.permalink;
							}

							$this.parent().find('.wd-search-results').removeClass('wd-opened');
						},
						onSearchComplete: function() {
							$this.removeClass('wd-search-loading');

							woodmartThemeModule.$document.trigger('wood-images-loaded');
						},
						formatResult    : function(suggestion, currentValue) {
							var returnValue = '';

							if (suggestion.divider) {
								returnValue += ' <div class="suggestion-divider-title title">' + suggestion.divider + '</div>';
							}

							if (thumbnail && suggestion.thumbnail) {
								returnValue += ' <div class="wd-suggestion-thumb">' + suggestion.thumbnail + '</div>';
							}

							if (suggestion.value) {
								returnValue += ' <div class="wd-suggestion-content wd-set-mb reset-last-child">';
								returnValue += '<div class="wd-entities-title">' + highlightTitle(suggestion.value, currentValue) + '</div>';
							}

							if (sku && suggestion.sku) {
								returnValue += ' <p class="wd-suggestion-sku">' + suggestion.sku + '</p>';
							}

							if (price && suggestion.price) {
								returnValue += ' <p class="price">' + suggestion.price + '</p>';
							}

							if (suggestion.value) {
								returnValue += ' </div>';
							}

							if (suggestion.permalink) {
								var ariaLabel = '';

								if (suggestion.value) {
									ariaLabel = `aria-label="${escapeHtml(suggestion.value)}"`;
								}

								returnValue += ` <a class="wd-fill" href="${suggestion.permalink}" ${ariaLabel}></a>`;
							}

							if (suggestion.products_not_found) {
								returnValue = '<span class="wd-not-found-msg">' + suggestion.value + '</span>';
							}

							if ( isFullScreen2 ) {
								if (suggestion.no_results) {
									$parentResult.addClass('wd-no-results');
								} else {
									$parentResult.removeClass('wd-no-results');
								}
							}

							if (! isFullScreen && ! isFullScreen2) {
								$parentResult.addClass('wd-opened');
							}

							if (isFullScreen2) {
								$this.parent().parent().addClass('wd-searched');
							} else {
								$this.parent().addClass('wd-searched');
							}

							return returnValue;
						}
					});

					$input.addClass('wd-search-inited');
				}

				if ( productCat.length  && 'cat_selected' === e.type ) {
					if (  '' !== productCat.val() ) {
						serviceUrlData['product_cat'] = productCat.val();
					}

					let searchForm = $this.find('[type="text"]').devbridgeAutocomplete()
					let serviceUrl = woodmart_settings.ajaxurl + '?' + new URLSearchParams(serviceUrlData).toString();

					if (enqueueProductCatResults && 'yes' === enqueueProductCatResults) {
						serviceUrl += '&include_cat_search=' + enqueueProductCatResults;
					}

					searchForm.setOptions({
						serviceUrl: serviceUrl
					});

					searchForm.hide();
					searchForm.onValueChange();
				}
			});

			woodmartThemeModule.$document.on('click', function(e) {
				var target = e.target;

				if (!$(target).is('.wd-search-form') && !$(target).parents().is('.wd-search-form') && !$(target).is('.wd-search-full-screen') && !$(target).parents().is('.wd-search-full-screen') && !$(target).is('.wd-clear-search')) {
					$this.find('[type="text"]').devbridgeAutocomplete('hide');
				}
			});

			$('.wd-search-results > .wd-scroll-content').on('click', function(e) {
				e.stopPropagation();
			});

			function overlayBackground( action ) {
				if (0 === $this.parents('.wd-search-form.wd-display-form.wd-with-overlay').length) {
					return;
				}

				$('.wd-close-side').trigger('wdCloseSideAction', ['open' === action ? 'show' : 'hide', 'click']);
			}
		});

		$('.wd-header-search.wd-display-dropdown > a').on('click', function(e) {
			e.preventDefault();
		});
	};

	$(document).ready(function() {
		woodmartThemeModule.ajaxSearch();
	});

	window.addEventListener('wdUpdatedHeader',function() {
		woodmartThemeModule.ajaxSearch();
	});
})(jQuery);
