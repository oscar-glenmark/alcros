(function () {
    var STORAGE_KEY = 'alcros_auth';

    function getToken() {
        return sessionStorage.getItem(STORAGE_KEY) || '';
    }

    function saveTokenFromUrl() {
        var params = new URLSearchParams(window.location.search);
        if (!params.has('alcros_auth')) {
            return getToken();
        }

        var token = params.get('alcros_auth');
        sessionStorage.setItem(STORAGE_KEY, token);
        params.delete('alcros_auth');
        var next = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
        window.history.replaceState({}, '', next);
        return token;
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

    var token = saveTokenFromUrl();
    if (!token) {
        token = getToken();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            applyTokenToPage(token || getToken());
        });
    } else {
        applyTokenToPage(token || getToken());
    }

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
})();
