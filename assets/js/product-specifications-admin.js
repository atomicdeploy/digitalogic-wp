(function () {
	'use strict';

	function updateSmdFields() {
		var config = window.digitalogicProductSpecificationsAdmin || {};
		var smdIds = (config.smdCategoryIds || []).map(String);
		var fields = document.querySelector('[data-digitalogic-smd-fields]');
		if (!fields) {
			return;
		}

		var selected = Array.prototype.some.call(
			document.querySelectorAll('#product_catchecklist input[type="checkbox"]:checked'),
			function (checkbox) {
				return smdIds.indexOf(String(checkbox.value)) !== -1;
			}
		);
		fields.hidden = !selected;
		fields.setAttribute('aria-hidden', selected ? 'false' : 'true');
	}

	document.addEventListener('DOMContentLoaded', function () {
		updateSmdFields();
		var checklist = document.getElementById('product_catchecklist');
		if (checklist) {
			checklist.addEventListener('change', updateSmdFields);
		}
	});
}());
