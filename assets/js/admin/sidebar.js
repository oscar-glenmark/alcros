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
        var openBtn = document.getElementById('logoutOpenBtn');
        var cancelBtn = document.getElementById('logoutCancelBtn');
        if (!modal || !openBtn || !cancelBtn || modal.dataset.bound === '1') {
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

        openBtn.addEventListener('click', openModal);
        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('flex')) closeModal();
        });
    }

    function init() {
        initSidebar();
        initLogoutModal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
