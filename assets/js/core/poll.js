(function (global) {
    'use strict';

    function authQuery() {
        var token = sessionStorage.getItem('alcros_auth') || '';
        return token ? 'alcros_auth=' + encodeURIComponent(token) : '';
    }

    function buildUrl(base, params) {
        var url = new URL(base, window.location.href);
        Object.keys(params || {}).forEach(function (key) {
            if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                url.searchParams.set(key, params[key]);
            }
        });
        var auth = authQuery();
        if (auth) {
            url.search += (url.search ? '&' : '?') + auth;
        }
        return url.toString();
    }

    function pollJson(url, params, intervalMs, onData, onError, options) {
        options = options || {};
        var pollInBackground = options.pollInBackground === true;
        var stopped = false;
        var timer = null;
        var inflight = null;
        var revision = options.initialRevision || '';
        var failCount = 0;
        var lastGoodAt = Date.now();

        function resolveInterval() {
            if (typeof options.getInterval === 'function') {
                return options.getInterval({
                    revision: revision,
                    failCount: failCount,
                    lastGoodAt: lastGoodAt
                });
            }
            if (typeof intervalMs === 'function') {
                return intervalMs({
                    revision: revision,
                    failCount: failCount,
                    lastGoodAt: lastGoodAt
                });
            }
            return intervalMs;
        }

        function schedule() {
            if (timer) clearInterval(timer);
            tick();
            timer = setInterval(tick, resolveInterval());
        }

        function setSyncState(state) {
            var el = document.getElementById('live-sync-indicator');
            if (!el) return;
            if (state === 'live') {
                el.textContent = 'Live · ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                el.classList.remove('opacity-50', 'is-stale', 'is-reconnecting');
                return;
            }
            if (state === 'stale') {
                el.textContent = 'Offline · showing last update';
                el.classList.add('is-stale');
                el.classList.remove('is-reconnecting');
                return;
            }
            el.textContent = 'Reconnecting…';
            el.classList.add('is-reconnecting');
            el.classList.remove('is-stale');
        }

        function tick() {
            if (stopped || (!pollInBackground && document.hidden)) return;

            if (inflight) {
                try { inflight.abort(); } catch (e) { /* ignore */ }
            }

            var resolved = typeof params === 'function' ? params(revision) : (params || {});
            if (revision && resolved.since === undefined) {
                resolved.since = revision;
            }

            var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            inflight = controller;

            fetch(buildUrl(url, resolved), {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller ? controller.signal : undefined
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    inflight = null;
                    if (!data || data.ok === false) {
                        failCount++;
                        if (onError) onError(data);
                        if (failCount >= 2) setSyncState('stale');
                        return;
                    }

                    failCount = 0;
                    lastGoodAt = Date.now();

                    if (data.unchanged) {
                        if (data.revision) revision = data.revision;
                        if (options.onUnchanged) options.onUnchanged(data);
                        setSyncState('live');
                        markLiveIndicator();
                        if (timer) {
                            clearInterval(timer);
                            timer = setInterval(tick, resolveInterval());
                        }
                        return;
                    }

                    if (data.revision) revision = data.revision;
                    onData(data);
                    setSyncState('live');
                    markLiveIndicator();
                    if (timer) {
                        clearInterval(timer);
                        timer = setInterval(tick, resolveInterval());
                    }
                })
                .catch(function (err) {
                    inflight = null;
                    if (err && err.name === 'AbortError') return;
                    failCount++;
                    if (onError) onError(err);
                    setSyncState(failCount >= 2 ? 'stale' : 'reconnecting');
                });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !stopped) tick();
        });

        schedule();

        return function stop() {
            stopped = true;
            if (timer) clearInterval(timer);
            if (inflight) {
                try { inflight.abort(); } catch (e) { /* ignore */ }
            }
        };
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function markLiveIndicator() {
        var el = document.getElementById('live-sync-indicator');
        if (!el) return;
        el.textContent = 'Live · ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        el.classList.remove('opacity-50', 'is-stale', 'is-reconnecting');
    }

    global.AlcrosPoll = {
        pollJson: pollJson,
        buildUrl: buildUrl,
        setText: setText,
        markLiveIndicator: markLiveIndicator,
        authToken: function () { return sessionStorage.getItem('alcros_auth') || ''; }
    };
})(window);
