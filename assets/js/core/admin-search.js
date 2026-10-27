(function () {
    'use strict';

    /**
     * GET search forms: do not reload the page while typing.
     * Submit only on Enter (activity log toolbar) or the form's Search button (manage pages).
     * Records uses data-records-ajax-search; manage pages and activity log use data-manage-ajax-search (manage-list-search.js).
     */
    function bindGetSearchOnEnter(input) {
        var form = input.closest('form');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') {
            return;
        }
        if (form.hasAttribute('data-records-ajax-search')) {
            return;
        }
        if (form.hasAttribute('data-manage-ajax-search')) {
            return;
        }
        if (form.hasAttribute('data-search-manual')) {
            return;
        }
        if (form.classList.contains('manage-controls__search')) {
            return;
        }

        input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }
            event.preventDefault();
            var pageInput = form.querySelector('input[name="page"]');
            if (pageInput) {
                pageInput.remove();
            }
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll(
            'form.manage-controls__search input[name="q"], form.admin-toolbar input[name="q"]'
        ).forEach(bindGetSearchOnEnter);
    });
})();
