(function () {
    'use strict';

    var DEBOUNCE_MS = 500;
    var MIN_CHARS = 2;

    function bindDebouncedGetSearch(input) {
        var form = input.closest('form');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') {
            return;
        }

        var serverQuery = (input.value || '').trim();
        var timer = null;

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                if (timer) {
                    clearTimeout(timer);
                    timer = null;
                }
            }
        });

        input.addEventListener('input', function () {
            if (timer) {
                clearTimeout(timer);
            }

            timer = setTimeout(function () {
                var query = input.value.trim();
                if (query === serverQuery) {
                    return;
                }
                if (query !== '' && query.length < MIN_CHARS && !adminSearchLooksLikeDateInput(query)) {
                    return;
                }

                var pageInput = form.querySelector('input[name="page"]');
                if (pageInput) {
                    pageInput.remove();
                }

                // form.submit() skips the submit event, so no loading overlay while typing.
                form.submit();
            }, DEBOUNCE_MS);
        });
    }

    function adminSearchLooksLikeDateInput(value) {
        value = String(value || '').trim();
        if (!value) {
            return false;
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return true;
        }
        if (/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}$/.test(value)) {
            return true;
        }
        return /^\d{4}$/.test(value);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll(
            'form.manage-controls__search input[name="q"], form.admin-toolbar input[name="q"]'
        ).forEach(bindDebouncedGetSearch);
    });
})();
