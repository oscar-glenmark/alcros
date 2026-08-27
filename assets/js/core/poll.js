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

        function tick() {
            if (stopped || (!pollInBackground && document.hidden)) return;
            var resolved = typeof params === 'function' ? params() : (params || {});
            fetch(buildUrl(url, resolved), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.ok !== false) {
                        onData(data);
                    } else if (onError) {
                        onError(data);
                    }
                })
                .catch(function (err) {
                    if (onError) onError(err);
                });
        }

        function schedule() {
            if (timer) clearInterval(timer);
            tick();
            timer = setInterval(tick, intervalMs);
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !stopped) tick();
        });

        schedule();

        return function stop() {
            stopped = true;
            if (timer) clearInterval(timer);
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
        el.classList.remove('opacity-50');
    }

    global.AlcrosPoll = {
        pollJson: pollJson,
        buildUrl: buildUrl,
        setText: setText,
        markLiveIndicator: markLiveIndicator,
        authToken: function () { return sessionStorage.getItem('alcros_auth') || ''; }
    };
})(window);
