(function () {
    'use strict';

    var toggle = document.getElementById('mobileNavToggle');
    var mobileNav = document.getElementById('mobileNav');
    if (toggle && mobileNav) {
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
    }

    var homeTrackForm = document.getElementById('home-track-form');
    var homeTrackInput = document.getElementById('home-track-input');
    if (homeTrackForm) {
        homeTrackForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var code = homeTrackInput ? homeTrackInput.value.trim() : '';
            if (window.AlcrosTrack) {
                window.AlcrosTrack.open(code);
            }
        });
    }
})();
