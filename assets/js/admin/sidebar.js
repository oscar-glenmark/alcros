(function () {
    'use strict';

    var STORAGE_KEY = 'admin_sidebar_minimized';
    var sidebarToggle = null;

    function isCompactViewport() {
        return window.matchMedia('(max-width: 1023px)').matches;
    }

    function setSidebarDrawerOpen(open) {
        document.body.classList.toggle('admin-sidebar-drawer-open', open);
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function setSidebarMinimized(minimized, persist) {
        document.body.classList.toggle('admin-sidebar-minimized', minimized);
        if (persist !== false) {
            try {
                sessionStorage.setItem(STORAGE_KEY, minimized ? '1' : '0');
            } catch (e) { /* ignore */ }
        }
        if (sidebarToggle && !isCompactViewport()) {
            sidebarToggle.setAttribute('aria-expanded', minimized ? 'false' : 'true');
        }
    }

    function restoreSidebarState() {
        try {
            if (sessionStorage.getItem(STORAGE_KEY) === '1') {
                if (!document.body.classList.contains('admin-sidebar-minimized')) {
                    setSidebarMinimized(true, false);
                }
            }
        } catch (e) { /* ignore */ }
    }

    function bindSidebarToggle() {
        sidebarToggle = document.getElementById('adminSidebarToggle');
        if (!sidebarToggle || sidebarToggle.dataset.bound === '1') {
            return;
        }
        sidebarToggle.dataset.bound = '1';

        sidebarToggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (isCompactViewport()) {
                setSidebarDrawerOpen(!document.body.classList.contains('admin-sidebar-drawer-open'));
                return;
            }
            var willMinimize = !document.body.classList.contains('admin-sidebar-minimized');
            setSidebarMinimized(willMinimize);
            if (!willMinimize) {
                setSidebarDrawerOpen(false);
            }
        });
    }

    function initSidebar() {
        restoreSidebarState();
        bindSidebarToggle();

        document.addEventListener('click', function (e) {
            if (!document.body.classList.contains('admin-sidebar-drawer-open')) {
                return;
            }
            if (e.target.closest('.admin-sidebar') || e.target.closest('#adminSidebarToggle')) {
                return;
            }
            setSidebarDrawerOpen(false);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                setSidebarDrawerOpen(false);
            }
        });

        window.addEventListener('resize', function () {
            if (!isCompactViewport()) {
                setSidebarDrawerOpen(false);
            }
        });

        var navScroll = document.getElementById('sidebarNavScroll');
        if (navScroll && navScroll.dataset.scrollBound !== '1') {
            navScroll.dataset.scrollBound = '1';
            var savedScroll = sessionStorage.getItem('sidebar_scroll_pos');
            if (savedScroll !== null) {
                navScroll.scrollTop = parseInt(savedScroll, 10);
            }
            navScroll.addEventListener('scroll', function () {
                sessionStorage.setItem('sidebar_scroll_pos', navScroll.scrollTop);
            });
        }
    }

    function initLogoutModal() {
        var modal = document.getElementById('logoutConfirmModal');
        var cancelBtn = document.getElementById('logoutCancelBtn');
        if (!modal || !cancelBtn || modal.dataset.bound === '1') {
            return;
        }
        modal.dataset.bound = '1';

        function openModal() {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }

        function closeModal() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.querySelectorAll('[data-logout-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeProfileDropdown();
                openModal();
            });
        });

        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('flex')) closeModal();
        });
    }

    var profileDropdownOpen = false;

    function closeProfileDropdown() {
        var wrapper = document.getElementById('profile-wrapper');
        var menuBtn = document.getElementById('profile-menu-btn');
        var dropdown = document.getElementById('profile-dropdown');
        if (!wrapper || !menuBtn || !dropdown) return;
        profileDropdownOpen = false;
        dropdown.classList.add('hidden');
        menuBtn.setAttribute('aria-expanded', 'false');
    }

    function initProfileDropdown() {
        var wrapper = document.getElementById('profile-wrapper');
        var menuBtn = document.getElementById('profile-menu-btn');
        var dropdown = document.getElementById('profile-dropdown');
        if (!wrapper || !menuBtn || !dropdown || wrapper.dataset.bound === '1') {
            return;
        }
        wrapper.dataset.bound = '1';

        menuBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            profileDropdownOpen = !profileDropdownOpen;
            dropdown.classList.toggle('hidden', !profileDropdownOpen);
            menuBtn.setAttribute('aria-expanded', profileDropdownOpen ? 'true' : 'false');
            if (profileDropdownOpen && typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        document.addEventListener('click', function (e) {
            if (profileDropdownOpen && !wrapper.contains(e.target)) {
                closeProfileDropdown();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && profileDropdownOpen) {
                closeProfileDropdown();
            }
        });
    }

    function init() {
        initSidebar();
        initProfileDropdown();
        initLogoutModal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
