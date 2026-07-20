(function($) {
	'use strict';

	var toastTimer = null;

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
})(jQuery);
