(function () {
    'use strict';

    function rowChecks(form) {
        return Array.from(form.querySelectorAll('.manage-bulk-row-check'));
    }

    function updateBulkState(form) {
        var checks = rowChecks(form);
        var selected = checks.filter(function (cb) { return cb.checked; });
        var selectAll = form.querySelector('.manage-bulk-check--all');
        var toolbar = form.querySelector('[data-bulk-toolbar]');
        var deleteBtn = form.querySelector('[data-bulk-delete-btn]');

        if (toolbar) {
            var showToolbar = selected.length > 0;
            toolbar.classList.toggle('hidden', !showToolbar);
            toolbar.setAttribute('aria-hidden', showToolbar ? 'false' : 'true');
        }

        if (selectAll) {
            var selectable = checks.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < selectable;
            selectAll.checked = selectable > 0 && selected.length === selectable;
        }

        if (deleteBtn) {
            deleteBtn.textContent = selected.length > 1 ? 'Delete (' + selected.length + ')' : 'Delete';
        }
    }

    function bindBulkForm(form) {
        var selectAll = form.querySelector('.manage-bulk-check--all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rowChecks(form).forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
                updateBulkState(form);
            });
        }

        document.addEventListener('change', function (e) {
            if (!e.target || !e.target.classList.contains('manage-bulk-row-check')) return;
            if (e.target.getAttribute('form') !== form.id && !form.contains(e.target)) return;
            updateBulkState(form);
        });

        form.addEventListener('submit', function (e) {
            var submitter = e.submitter;
            if (!submitter) return;

            if (submitter.hasAttribute('data-bulk-require-selection')) {
                var selected = rowChecks(form).filter(function (cb) { return cb.checked; });
                if (!selected.length) {
                    e.preventDefault();
                    return;
                }
            }

            var confirmMessage = submitter.getAttribute('data-bulk-confirm');
            if (confirmMessage && !window.confirm(confirmMessage)) {
                e.preventDefault();
                return;
            }

            var loadingText = submitter.getAttribute('data-loading-text');
            if (loadingText && window.AlcrosLoading && typeof window.AlcrosLoading.page === 'function') {
                window.AlcrosLoading.page(true, loadingText);
            }
        });

        updateBulkState(form);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-manage-bulk-form]').forEach(bindBulkForm);
    });
})();
