(function () {
    'use strict';

    var toggle = document.getElementById('mobileNavToggle');
    var mobileNav = document.getElementById('mobileNav');
    if (toggle && mobileNav) {
        toggle.addEventListener('click', function () {
            mobileNav.classList.toggle('hidden');
            mobileNav.classList.toggle('is-open');
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
