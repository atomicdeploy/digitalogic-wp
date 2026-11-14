/* global woodmart_settings */
(function($) {
	woodmartThemeModule.searchFullScreen = function() {
		woodmartThemeModule.$body.on('click', '.wd-header-search.wd-display-full-screen > a, .wd-header-search.wd-display-full-screen-2 > a, .wd-search-form.wd-display-full-screen, .wd-search-form.wd-display-full-screen-2', function(e) {
			e.preventDefault();

			var $this = $(this);
			var $wrapper = $('.wd-search-full-screen-2');

			if ($this.parent().find('.wd-search-dropdown').length > 0 || woodmartThemeModule.$body.hasClass('global-search-dropdown')) {
				return;
			}

			if (isOpened()) {
				closeWidget();
			} else {
				if ( ! $this.hasClass('wd-display-full-screen-2') && ! $this.parent().hasClass('wd-display-full-screen-2') ) {
					$wrapper = $('.wd-search-full-screen');
					calculationOffset();
				}

				setTimeout(function() {
					openWidget($wrapper);
				}, 10);
			}
		});

		woodmartThemeModule.$body.on('click', '.wd-close-search a, .wd-page-wrapper, .wd-hb', function(event) {
			var isCloseBtn   = $(event.target).closest('.wd-close-search a').length;
			var isFullScreen = $(event.target).closest('.wd-search-full-screen').length;

			if (!isCloseBtn && isFullScreen) {
				return;
			}

			if ( isCloseBtn ) {
				event.preventDefault();
			}

			if (isOpened()) {
				closeWidget();
			}
		});

		var closeByEsc = function(e) {
			if (e.keyCode === 27) {
				closeWidget();
				woodmartThemeModule.$body.unbind('keyup', closeByEsc);
			}
		};

		var closeWidget = function() {
			var $searchWrapper = $('[class*=wd-search-full-screen]');

			$('html').removeClass('wd-search-opened');
			$searchWrapper.removeClass('wd-opened');
			$searchWrapper.removeClass('wd-searched');
			$searchWrapper.trigger('wdCloseSearch');
		};

		var calculationOffset = function () {
			var $bar = $('#wpadminbar');
			var barHeight = $bar.length > 0 ? $bar.outerHeight() : 0;
			var $sticked = $('.whb-sticked');
			var $mainHeader = $('.whb-main-header');
			var offset;

			if ($sticked.length > 0) {
				if ($('.whb-clone').length > 0) {
					offset = $sticked.outerHeight() + barHeight;
				} else {
					offset = $mainHeader.outerHeight() + barHeight;
				}
			} else {
				offset = $mainHeader.outerHeight() + barHeight;

				$headerBanner = $('.wd-hb-wrapp');

				if ($headerBanner.length > 0 && $headerBanner.hasClass('wd-display')) {
					offset += $headerBanner.outerHeight();
				}
			}

			$('.wd-search-full-screen').css('top', offset);
		}

		var openWidget = function($wrapper) {
			// Close by esc
			woodmartThemeModule.$body.on('keyup', closeByEsc);
			$('html').addClass('wd-search-opened');

			$wrapper.addClass('wd-opened');
			$wrapper.trigger('wdOpenSearch');

			setTimeout(function() {
				var $input = $wrapper.find('input[type="text"]');
				var length = $input.val().length;

				$input[0].setSelectionRange(length, length);
				$input.trigger('focus');
			}, 500);
		};

		var isOpened = function() {
			return $('html').hasClass('wd-search-opened');
		};
	};

	$(document).ready(function() {
		woodmartThemeModule.searchFullScreen();
	});
})(jQuery);
