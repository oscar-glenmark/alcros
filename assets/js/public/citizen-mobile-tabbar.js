(function () {
    'use strict';

    function isPhoneViewport() {
        return window.matchMedia('(max-width: 639px)').matches;
    }

    var moreOpen = false;

    function setCitizenMoreOpen(open) {
        var dropup = document.getElementById('citizenMobileMoreDropup');
        var backdrop = document.getElementById('citizenMobileMoreBackdrop');
        var moreBtn = document.getElementById('citizenMobileMoreTab');
        if (!dropup || !backdrop || !moreBtn) {
            return;
        }
        moreOpen = !!open;
        dropup.classList.toggle('hidden', !moreOpen);
        backdrop.classList.toggle('hidden', !moreOpen);
        moreBtn.classList.toggle('is-open', moreOpen);
        moreBtn.setAttribute('aria-expanded', moreOpen ? 'true' : 'false');
        document.body.classList.toggle('citizen-mobile-more-open', moreOpen);
    }

    function bindCitizenMoreDropup() {
        var moreBtn = document.getElementById('citizenMobileMoreTab');
        var backdrop = document.getElementById('citizenMobileMoreBackdrop');
        var dropup = document.getElementById('citizenMobileMoreDropup');
        if (!moreBtn || moreBtn.dataset.bound === '1') {
            return;
        }
        moreBtn.dataset.bound = '1';

        moreBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setCitizenMoreOpen(!moreOpen);
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                setCitizenMoreOpen(false);
            });
        }

        if (dropup) {
            dropup.querySelectorAll('a[href]').forEach(function (link) {
                link.addEventListener('click', function () {
                    setCitizenMoreOpen(false);
                });
            });
            dropup.querySelectorAll('[data-open-track]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setCitizenMoreOpen(false);
                });
            });
        }

        document.addEventListener('click', function (e) {
            if (!moreOpen) {
                return;
            }
            if (e.target.closest('#citizenMobileMoreDropup') || e.target.closest('#citizenMobileMoreTab')) {
                return;
            }
            setCitizenMoreOpen(false);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                setCitizenMoreOpen(false);
            }
        });

        window.addEventListener('resize', function () {
            if (!isPhoneViewport()) {
                setCitizenMoreOpen(false);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindCitizenMoreDropup);
    } else {
        bindCitizenMoreDropup();
    }
})();