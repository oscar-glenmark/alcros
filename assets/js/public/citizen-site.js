(function () {
    'use strict';

    var toggle = document.getElementById('citizenMobileNavToggle');
    var mobileNav = document.getElementById('citizenMobileNav');
    if (!toggle || !mobileNav) return;

    function closeNav() {
        mobileNav.classList.add('hidden');
        mobileNav.classList.remove('is-open');
    }

    function openNav() {
        mobileNav.classList.remove('hidden');
        mobileNav.classList.add('is-open');
    }

    toggle.addEventListener('click', function () {
        if (mobileNav.classList.contains('is-open')) {
            closeNav();
        } else {
            openNav();
        }
    });

    mobileNav.querySelectorAll('a, button[data-open-track]').forEach(function (link) {
        link.addEventListener('click', closeNav);
    });
})();
