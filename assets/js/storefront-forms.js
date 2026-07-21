(function() {
	'use strict';

	function renumber(container) {
		container.querySelectorAll('[data-dgl-item]').forEach(function(row, index) {
			var number = row.querySelector('[data-dgl-item-number]');
			if (number) number.textContent = String(index + 1);
			row.querySelectorAll('[name]').forEach(function(field) {
				field.name = field.name.replace(/items\[[^\]]+\]/, 'items[' + index + ']');
			});
		});
	}

	document.querySelectorAll('[data-dgl-request-form]').forEach(function(form) {
		var items = form.querySelector('[data-dgl-items]');
		var template = form.querySelector('[data-dgl-item-template]');
		var addButton = form.querySelector('[data-dgl-add-row]');

		if (items && template && addButton) {
			addButton.addEventListener('click', function() {
				if (items.querySelectorAll('[data-dgl-item]').length >= 10) return;
				var index = items.querySelectorAll('[data-dgl-item]').length;
				var html = template.innerHTML.replaceAll('__INDEX__', String(index));
				items.insertAdjacentHTML('beforeend', html);
				renumber(items);
				var newRow = items.lastElementChild;
				var firstInput = newRow && newRow.querySelector('input');
				if (firstInput) firstInput.focus();
			});

			items.addEventListener('click', function(event) {
				var button = event.target.closest('[data-dgl-remove-row]');
				if (!button || items.querySelectorAll('[data-dgl-item]').length <= 1) return;
				button.closest('[data-dgl-item]').remove();
				renumber(items);
			});
		}

		form.querySelectorAll('input[type="file"]').forEach(function(input) {
			input.addEventListener('change', function() {
				var label = input.closest('.dgl-file-field');
				var output = label && label.querySelector('[data-dgl-file-name]');
				if (output) output.textContent = input.files && input.files[0] ? input.files[0].name : 'فایل رو انتخاب کن';
			});
		});

		form.addEventListener('submit', function() {
			var button = form.querySelector('[type="submit"]');
			if (button) {
				button.setAttribute('aria-busy', 'true');
				button.disabled = true;
			}
		});
	});
})();
