(function () {
    'use strict';

    function refreshEmptyState(root) {
        var emptyState = root.querySelector('.digitalogic-supplier-links__empty');
        var hasRows = root.querySelector('.digitalogic-supplier-link') !== null;

        if (emptyState) {
            emptyState.classList.toggle('is-hidden', hasRows);
        }
    }

    function addRow(root) {
        var template = document.getElementById('tmpl-digitalogic-supplier-link-row');
        var rows = root.querySelector('.digitalogic-supplier-links__rows');
        var index = Number.parseInt(root.dataset.nextIndex || '0', 10);

        if (!template || !rows || !Number.isFinite(index)) {
            return;
        }

        rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
        root.dataset.nextIndex = String(index + 1);
        refreshEmptyState(root);

        var newRows = rows.querySelectorAll('.digitalogic-supplier-link');
        var lastRow = newRows[newRows.length - 1];
        var urlInput = lastRow ? lastRow.querySelector('input[type="url"]') : null;
        if (urlInput) {
            urlInput.focus();
        }
    }

    function initialize() {
        var root = document.getElementById('digitalogic-private-supplier-links');
        if (!root) {
            return;
        }

        root.addEventListener('click', function (event) {
            var addButton = event.target.closest('.digitalogic-supplier-links__add');
            if (addButton) {
                event.preventDefault();
                addRow(root);
                return;
            }

            var removeButton = event.target.closest('.digitalogic-supplier-link__remove');
            if (!removeButton) {
                return;
            }

            event.preventDefault();
            var row = removeButton.closest('.digitalogic-supplier-link');
            if (row) {
                row.remove();
                refreshEmptyState(root);
            }
        });

        refreshEmptyState(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
