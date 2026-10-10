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

    function initLandingNavHighlight() {
        var navLinks = document.querySelectorAll('[data-nav-section]');
        if (!navLinks.length) {
            return;
        }

        var sectionIds = ['home', 'services', 'about', 'faqs', 'contact'];
        var sections = sectionIds.map(function (id) {
            return document.getElementById(id);
        }).filter(Boolean);

        function setActiveSection(sectionId) {
            var active = sectionId || 'home';
            navLinks.forEach(function (link) {
                var key = link.getAttribute('data-nav-section') || '';
                link.classList.toggle('is-active', key === active);
            });
        }

        function sectionFromHash() {
            var hash = (window.location.hash || '').replace(/^#/, '').toLowerCase();
            if (!hash) {
                return 'home';
            }
            return sectionIds.indexOf(hash) !== -1 ? hash : 'home';
        }

        navLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                var key = link.getAttribute('data-nav-section');
                if (key) {
                    setActiveSection(key);
                }
            });
        });

        window.addEventListener('hashchange', function () {
            setActiveSection(sectionFromHash());
        });

        if ('IntersectionObserver' in window && sections.length) {
            var ratios = {};
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    ratios[entry.target.id] = entry.isIntersecting ? entry.intersectionRatio : 0;
                });
                var bestId = 'home';
                var bestRatio = 0;
                sectionIds.forEach(function (id) {
                    var ratio = ratios[id] || 0;
                    if (ratio > bestRatio) {
                        bestRatio = ratio;
                        bestId = id;
                    }
                });
                if (bestRatio >= 0.15) {
                    setActiveSection(bestId);
                }
            }, {
                root: null,
                rootMargin: '-35% 0px -45% 0px',
                threshold: [0, 0.15, 0.35, 0.55, 0.75, 1]
            });

            sections.forEach(function (section) {
                observer.observe(section);
            });
        }

        setActiveSection(sectionFromHash());
    }

    initLandingNavHighlight();

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
