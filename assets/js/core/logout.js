(function () {
    'use strict';

    if (window.alcrosClearAuth) {
        window.alcrosClearAuth();
    } else {
        sessionStorage.removeItem('alcros_auth');
    }
    window.location.href = 'login.php';
})();
