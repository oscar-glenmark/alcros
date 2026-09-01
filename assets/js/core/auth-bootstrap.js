(function () {
    'use strict';

    function readRedirect() {
        var el = document.getElementById('auth-bootstrap-config');
        if (!el) return 'login.php';
        try {
            var data = JSON.parse(el.textContent || '{}');
            if (data.redirect) {
                return 'login.php?redirect=' + encodeURIComponent(data.redirect);
            }
        } catch (err) {
            /* fall through */
        }
        return 'login.php';
    }

    var token = sessionStorage.getItem('alcros_auth');
    if (token) {
        var params = new URLSearchParams(window.location.search);
        params.set('alcros_auth', token);
        window.location.replace(window.location.pathname + '?' + params.toString() + window.location.hash);
        return;
    }

    window.location.replace(readRedirect());
})();
