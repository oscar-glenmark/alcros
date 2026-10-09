(function () {
    var STORAGE_KEY = 'alcros_auth';

    function getToken() {
        return sessionStorage.getItem(STORAGE_KEY) || '';
    }

    function staffIdFromAuthToken(token) {
        if (!token) {
            return '';
        }
        var parts = token.split('.');
        if (parts.length !== 2) {
            return '';
        }
        try {
            var b64 = parts[0].replace(/-/g, '+').replace(/_/g, '/');
            var pad = b64.length % 4;
            if (pad) {
                b64 += '===='.slice(pad);
            }
            var json = atob(b64);
            var data = JSON.parse(json);
            return data && data.staff_id ? String(data.staff_id) : '';
        } catch (e) {
            return '';
        }
    }

    function readServerSync() {
        if (window.AlcrosPage && typeof AlcrosPage.readConfig === 'function') {
            var sync = AlcrosPage.readConfig('alcros-staff-auth-sync') || {};
            return {
                token: sync.staffAuthToken || '',
                staffId: sync.staffId ? String(sync.staffId) : '',
                sessionStaffId: sync.sessionStaffId ? String(sync.sessionStaffId) : ''
            };
        }
        return { token: '', staffId: '', sessionStaffId: '' };
    }

    /** Merge server token only when this tab has no token or the same staff account. */
    function mergeServerSyncIntoStorage(sync) {
        sync = sync || readServerSync();
        if (!sync.token || !sync.staffId) {
            return getToken();
        }
        var existing = getToken();
        var existingId = staffIdFromAuthToken(existing);
        if (existingId && existingId !== sync.staffId) {
            return existing;
        }
        sessionStorage.setItem(STORAGE_KEY, sync.token);
        return sync.token;
    }

    function stripAuthQueryParam() {
        var params = new URLSearchParams(window.location.search);
        if (!params.has('alcros_auth')) {
            return null;
        }
        var urlToken = params.get('alcros_auth');
        params.delete('alcros_auth');
        var next = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
        window.history.replaceState({}, '', next);
        return urlToken;
    }

    function saveTokenFromUrl() {
        var urlToken = stripAuthQueryParam();
        if (!urlToken) {
            return '';
        }
        sessionStorage.setItem(STORAGE_KEY, urlToken);
        return urlToken;
    }

    function isInternalPhpLink(href) {
        if (!href || href.indexOf('javascript:') === 0 || href.charAt(0) === '#') {
            return false;
        }
        if (href.indexOf('http://') === 0 || href.indexOf('https://') === 0) {
            try {
                return new URL(href).origin === window.location.origin && href.indexOf('.php') !== -1;
            } catch (e) {
                return false;
            }
        }
        return href.indexOf('.php') !== -1;
    }

    function appendTokenToHref(href, token) {
        if (!href || href.indexOf('alcros_auth=') !== -1) {
            return href;
        }
        var sep = href.indexOf('?') !== -1 ? '&' : '?';
        return href + sep + 'alcros_auth=' + encodeURIComponent(token);
    }

    function applyTokenToPage(token) {
        if (!token) {
            return;
        }

        document.querySelectorAll('a[href]').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!isInternalPhpLink(href) || href.indexOf('alcros_auth=') !== -1) {
                return;
            }
            link.setAttribute('href', appendTokenToHref(href, token));
        });

        document.querySelectorAll('form').forEach(function (form) {
            if (form.querySelector('input[name="alcros_auth"]')) {
                return;
            }
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'alcros_auth';
            input.value = token;
            form.appendChild(input);
        });

        document.querySelectorAll('input[name="alcros_auth"]').forEach(function (input) {
            input.value = token;
        });
    }

    function ensureFormToken(form) {
        var token = getToken();
        if (!token || !form || form.querySelector('input[name="alcros_auth"]')) {
            return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'alcros_auth';
        input.value = token;
        form.appendChild(input);
    }

    function resolveCanonicalToken() {
        var fromUrl = saveTokenFromUrl();
        if (fromUrl) {
            return fromUrl;
        }
        var existing = getToken();
        if (existing) {
            return existing;
        }
        return mergeServerSyncIntoStorage(readServerSync());
    }

    var token = resolveCanonicalToken();

    function refreshPageTokens() {
        applyTokenToPage(getToken() || token);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshPageTokens);
    } else {
        refreshPageTokens();
    }

    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            refreshPageTokens();
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        ensureFormToken(form);
    }, true);

    window.alcrosClearAuth = function () {
        sessionStorage.removeItem(STORAGE_KEY);
    };

    window.alcrosHandleAuthFailure = function (status) {
        window.alcrosClearAuth();
        var page = window.location.pathname.split('/').pop() || 'dashboard.php';
        var message = status === 419
            ? 'Your security session expired. Please sign in again.'
            : 'Your staff session expired. Please sign in again.';
        if (window.AlcrosActionResult && typeof AlcrosActionResult.show === 'function') {
            AlcrosActionResult.show('error', message);
        }
        window.setTimeout(function () {
            window.location.href = 'login.php?redirect=' + encodeURIComponent(page);
        }, status === 419 ? 1200 : 600);
    };
})();
