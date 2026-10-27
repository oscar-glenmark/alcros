(function () {
    'use strict';

    var DEBOUNCE_MS = 900;
    var MIN_CHARS = 2;

    function looksLikeDateInput(value) {
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

    function buildUrlFromForm(form, extraParams) {
        var params = new URLSearchParams();
        var elements = form.querySelectorAll('input, select, textarea');
        elements.forEach(function (el) {
            if (!el.name || el.disabled) {
                return;
            }
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
                return;
            }
            var val = String(el.value || '').trim();
            if (val !== '') {
                params.set(el.name, val);
            }
        });
        if (extraParams) {
            Object.keys(extraParams).forEach(function (key) {
                var v = extraParams[key];
                if (v === null || v === undefined || v === '') {
                    params.delete(key);
                } else {
                    params.set(key, String(v));
                }
            });
        }
        params.delete('page');

        var action = form.getAttribute('action') || window.location.pathname;
        var qs = params.toString();
        return qs ? action + '?' + qs : action;
    }

    function bindManageListSearch(form) {
        if (!form || form.dataset.manageListSearchBound === '1') {
            return;
        }
        var panelId = form.getAttribute('data-list-panel-id');
        if (!panelId) {
            return;
        }
        form.dataset.manageListSearchBound = '1';

        var panel = document.getElementById(panelId);
        var input = form.querySelector('input[name="q"]');
        var appliedQuery = input ? String(input.value || '').trim() : '';
        var debounceTimer = null;
        var requestId = 0;
        var abortController = null;

        function setLoading(on) {
            if (!panel) {
                return;
            }
            panel.classList.toggle('is-list-search-loading', !!on);
            panel.setAttribute('aria-busy', on ? 'true' : 'false');
        }

        function applyPanelHtml(html) {
            if (!panel || !html) {
                return;
            }
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var next = doc.getElementById(panelId);
            if (!next) {
                return;
            }
            panel.outerHTML = next.outerHTML;
            panel = document.getElementById(panelId);
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons({ nameAttr: 'data-lucide' });
            }
            document.dispatchEvent(new CustomEvent('alcros:manage-list-updated', {
                detail: { panelId: panelId, form: form }
            }));
        }

        function runSearch(immediate) {
            if (!input) {
                if (immediate) {
                    window.location.href = buildUrlFromForm(form);
                }
                return;
            }

            var execute = function () {
                var query = input ? input.value.trim() : '';
                if (query === appliedQuery) {
                    return;
                }
                if (query !== '' && query.length < MIN_CHARS && !looksLikeDateInput(query)) {
                    return;
                }

                var url = buildUrlFromForm(form);
                var currentId = ++requestId;
                if (abortController) {
                    abortController.abort();
                }
                abortController = new AbortController();
                setLoading(true);

                fetch(url, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: abortController.signal,
                    headers: {
                        Accept: 'text/html',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(function (res) {
                        if (!res.ok) {
                            throw new Error('Search failed');
                        }
                        return res.text();
                    })
                    .then(function (html) {
                        if (currentId !== requestId) {
                            return;
                        }
                        applyPanelHtml(html);
                        appliedQuery = query;
                        try {
                            window.history.pushState({ alcrosManageListSearch: panelId }, '', url);
                        } catch (e) { /* ignore */ }
                    })
                    .catch(function (err) {
                        if (err && err.name === 'AbortError') {
                            return;
                        }
                        if (immediate) {
                            window.location.href = url;
                        }
                    })
                    .finally(function () {
                        if (currentId === requestId) {
                            setLoading(false);
                        }
                    });
            };

            if (immediate) {
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                    debounceTimer = null;
                }
                execute();
                return;
            }

            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            debounceTimer = setTimeout(execute, DEBOUNCE_MS);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            runSearch(true);
        });

        if (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    runSearch(true);
                }
            });
            input.addEventListener('input', function () {
                var query = input.value.trim();
                if (query === '' && appliedQuery !== '') {
                    runSearch(false);
                    return;
                }
                runSearch(false);
            });
        }

        var staffSelect = form.querySelector('select[name="staff"]');
        if (staffSelect) {
            staffSelect.addEventListener('change', function () {
                appliedQuery = input ? input.value.trim() : appliedQuery;
                runSearch(true);
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-manage-ajax-search="1"]').forEach(bindManageListSearch);
    });
})();
