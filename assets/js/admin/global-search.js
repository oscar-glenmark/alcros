(function () {
    'use strict';

    var root = document.getElementById('adminGlobalSearch');
    var input = document.getElementById('adminGlobalSearchInput');
    var panel = document.getElementById('adminGlobalSearchResults');
    if (!root || !input || !panel) return;

    var searchUrl = root.getAttribute('data-search-url') || 'api/admin_search.php';
    var debounceTimer = null;
    var inflight = null;
    var activeIndex = -1;
    var latestResults = [];

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildUrl(params) {
        if (window.AlcrosPoll && typeof AlcrosPoll.buildUrl === 'function') {
            return AlcrosPoll.buildUrl(searchUrl, params);
        }
        var url = new URL(searchUrl, window.location.href);
        Object.keys(params || {}).forEach(function (key) {
            if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                url.searchParams.set(key, params[key]);
            }
        });
        return url.toString();
    }

    function kindLabel(kind) {
        if (kind === 'request') return 'Request';
        if (kind === 'appointment') return 'Appointment';
        if (kind === 'record') return 'Record';
        return 'Result';
    }

    function hidePanel() {
        panel.classList.add('hidden');
        panel.innerHTML = '';
        activeIndex = -1;
        latestResults = [];
        input.setAttribute('aria-expanded', 'false');
    }

    function renderResults(data) {
        latestResults = data.results || [];
        activeIndex = -1;

        if (!latestResults.length) {
            panel.innerHTML =
                '<div class="admin-global-search__empty">No matches found.</div>' +
                (data.fallback_url
                    ? '<button type="button" class="admin-global-search__fallback" data-fallback-url="' + escapeHtml(data.fallback_url) + '">Search records for “' + escapeHtml(data.q || input.value.trim()) + '”</button>'
                    : '');
            panel.classList.remove('hidden');
            input.setAttribute('aria-expanded', 'true');
            bindPanelActions();
            return;
        }

        panel.innerHTML = latestResults.map(function (item, index) {
            return '<button type="button" class="admin-global-search__item" data-search-index="' + index + '" data-search-url="' + escapeHtml(item.url || '') + '">' +
                '<span class="admin-global-search__kind">' + escapeHtml(kindLabel(item.kind)) + '</span>' +
                '<span class="admin-global-search__label">' + escapeHtml(item.label) + '</span>' +
                '<span class="admin-global-search__meta">' + escapeHtml(item.meta) + '</span>' +
            '</button>';
        }).join('');
        panel.classList.remove('hidden');
        input.setAttribute('aria-expanded', 'true');
        bindPanelActions();
    }

    function bindPanelActions() {
        panel.querySelectorAll('[data-search-url]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-search-url');
                if (url) window.location.href = url;
            });
        });
        panel.querySelectorAll('[data-fallback-url]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-fallback-url');
                if (url) window.location.href = url;
            });
        });
    }

    function navigateActive() {
        if (activeIndex < 0 || !latestResults[activeIndex] || !latestResults[activeIndex].url) return;
        window.location.href = latestResults[activeIndex].url;
    }

    function highlightActive() {
        panel.querySelectorAll('.admin-global-search__item').forEach(function (el, index) {
            el.classList.toggle('is-active', index === activeIndex);
        });
    }

    function fetchResults() {
        var query = input.value.trim();
        if (query.length < 2) {
            hidePanel();
            return;
        }

        if (inflight) {
            try { inflight.abort(); } catch (e) { /* ignore */ }
        }

        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        inflight = controller;

        fetch(buildUrl({ q: query }), {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller ? controller.signal : undefined
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                inflight = null;
                if (!data || data.ok === false) {
                    hidePanel();
                    return;
                }
                renderResults(data);
            })
            .catch(function (err) {
                inflight = null;
                if (err && err.name === 'AbortError') return;
                hidePanel();
            });
    }

    input.addEventListener('input', function () {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchResults, 250);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            hidePanel();
            input.blur();
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!latestResults.length) return;
            activeIndex = Math.min(activeIndex + 1, latestResults.length - 1);
            highlightActive();
            return;
        }

        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (!latestResults.length) return;
            activeIndex = Math.max(activeIndex - 1, 0);
            highlightActive();
            return;
        }

        if (e.key === 'Enter') {
            if (activeIndex >= 0) {
                e.preventDefault();
                navigateActive();
                return;
            }

            var query = input.value.trim();
            if (query.length < 2) return;

            e.preventDefault();
            if (debounceTimer) clearTimeout(debounceTimer);
            fetch(buildUrl({ q: query }), {
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || data.ok === false) return;
                    if (data.results && data.results.length === 1 && data.results[0].url) {
                        window.location.href = data.results[0].url;
                        return;
                    }
                    if (data.fallback_url) {
                        window.location.href = data.fallback_url;
                    }
                })
                .catch(function () { /* ignore */ });
        }
    });

    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) {
            hidePanel();
        }
    });
})();
