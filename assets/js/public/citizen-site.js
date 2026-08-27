(function () {
    'use strict';

    var toggle = document.getElementById('citizenMobileNavToggle');
    var mobileNav = document.getElementById('citizenMobileNav');
    if (!toggle || !mobileNav) return;

    toggle.addEventListener('click', function () {
        mobileNav.classList.toggle('hidden');
        mobileNav.classList.toggle('is-open');
    });
})();
