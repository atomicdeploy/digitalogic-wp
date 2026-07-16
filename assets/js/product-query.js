(function(root, factory) {
    'use strict';

    var service = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = service;
    }
    if (root) {
        root.DigitalogicProductQuery = service;
    }
})(typeof window !== 'undefined' ? window : globalThis, function() {
    'use strict';

    function positiveInteger(value, fallback, maximum) {
        value = parseInt(value, 10);
        if (!Number.isFinite(value) || value < 1) return fallback;
        return Math.min(value, maximum || Number.MAX_SAFE_INTEGER);
    }

    function cloneFilters(filters) {
        if (!filters || typeof filters !== 'object' || Array.isArray(filters)) return {};
        return Object.keys(filters).reduce(function(result, key) {
            var value = filters[key];
            if (value === '' || value === null || typeof value === 'undefined') return result;
            if (value && typeof value === 'object') {
                var range = {};
                if (value.min !== '' && value.min !== null && typeof value.min !== 'undefined') range.min = String(value.min);
                if (value.max !== '' && value.max !== null && typeof value.max !== 'undefined') range.max = String(value.max);
                if (Object.keys(range).length) result[key] = range;
                return result;
            }
            result[key] = String(value);
            return result;
        }, {});
    }

    function buildPayload(state) {
        state = state || {};
        var sorts = Array.isArray(state.sorts) ? state.sorts : [];
        var primarySort = sorts.find(function(sort) {
            return sort && sort.field;
        });

        return {
            page: positiveInteger(state.page, 1),
            limit: positiveInteger(state.pageSize, 50, 100),
            search: String(state.search || '').trim(),
            filters: cloneFilters(state.filters),
            image: state.image === 'with' || state.image === 'without' ? state.image : 'all',
            sorts: primarySort ? [{
                field: String(primarySort.field),
                direction: primarySort.direction === 'asc' ? 'asc' : 'desc'
            }] : []
        };
    }

    function sameValue(left, right) {
        if (Array.isArray(left) || Array.isArray(right) || (left && typeof left === 'object') || (right && typeof right === 'object')) {
            return JSON.stringify(left) === JSON.stringify(right);
        }
        return String(left === null || typeof left === 'undefined' ? '' : left) === String(right === null || typeof right === 'undefined' ? '' : right);
    }

    function reconcileEdits(current, savedSnapshot) {
        current = current && typeof current === 'object' ? current : {};
        savedSnapshot = savedSnapshot && typeof savedSnapshot === 'object' ? savedSnapshot : {};
        return Object.keys(current).reduce(function(remaining, field) {
            if (!Object.prototype.hasOwnProperty.call(savedSnapshot, field) || !sameValue(current[field], savedSnapshot[field])) {
                remaining[field] = current[field];
            }
            return remaining;
        }, {});
    }

    function applyPendingEdits(rows, edits) {
        rows = Array.isArray(rows) ? rows : [];
        edits = edits && typeof edits === 'object' ? edits : {};
        return rows.map(function(row) {
            var pending = row && edits[row.id] && typeof edits[row.id] === 'object' ? edits[row.id] : {};
            return Object.assign({}, row, pending);
        });
    }

    function pageWindow(page, pages, radius) {
        pages = Math.max(0, parseInt(pages, 10) || 0);
        page = Math.max(1, Math.min(parseInt(page, 10) || 1, Math.max(1, pages)));
        radius = Math.max(1, parseInt(radius, 10) || 2);
        var first = Math.max(1, page - radius);
        var last = Math.min(pages, page + radius);
        var result = [];
        for (var current = first; current <= last; current++) result.push(current);
        return result;
    }

    return {
        buildPayload: buildPayload,
        reconcileEdits: reconcileEdits,
        applyPendingEdits: applyPendingEdits,
        pageWindow: pageWindow
    };
});
