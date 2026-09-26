(function () {
	'use strict';
	document.addEventListener('DOMContentLoaded', function () {
		var rows = document.querySelector('[data-dg-bank-rows]');
		var template = document.querySelector('[data-dg-bank-template]');
		var add = document.querySelector('[data-dg-add-bank]');
		if (!rows || !template || !add) return;
		add.addEventListener('click', function () {
			var index = String(Date.now());
			rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
		});
		rows.addEventListener('click', function (event) {
			var remove = event.target.closest('[data-dg-remove-bank]');
			if (remove) remove.closest('.dg-bank-admin__row').remove();
		});
	});
}());
