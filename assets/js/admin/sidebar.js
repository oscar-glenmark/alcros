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
        document.dispatchEvent(new CustomEvent('alcros:sidebar-layout-change'));
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
        document.dispatchEvent(new CustomEvent('alcros:sidebar-layout-change'));
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

    function shouldShowSidebarTips() {
        if (document.body.classList.contains('admin-sidebar-drawer-open')) {
            return false;
        }
        return document.body.classList.contains('admin-sidebar-minimized') || isCompactViewport();
    }

    function initSidebarTooltips() {
        var sidebar = document.querySelector('.admin-sidebar');
        if (!sidebar || sidebar.dataset.tipsBound === '1') {
            return;
        }
        sidebar.dataset.tipsBound = '1';

        var tipEl = document.createElement('div');
        tipEl.className = 'admin-sidebar-tooltip';
        tipEl.setAttribute('role', 'tooltip');
        tipEl.hidden = true;
        document.body.appendChild(tipEl);

        var activeLink = null;

        function hideTip() {
            activeLink = null;
            tipEl.classList.remove('is-visible');
            tipEl.hidden = true;
        }

        function showTip(link) {
            var label = link.getAttribute('data-sidebar-tip');
            if (!label || !shouldShowSidebarTips()) {
                hideTip();
                return;
            }

            activeLink = link;
            tipEl.textContent = label;
            tipEl.hidden = false;

            window.requestAnimationFrame(function () {
                if (activeLink !== link) {
                    return;
                }
                var rect = link.getBoundingClientRect();
                tipEl.style.top = (rect.top + rect.height / 2) + 'px';
                tipEl.style.left = (rect.right + 10) + 'px';
                tipEl.style.transform = 'translateY(-50%)';
                tipEl.classList.add('is-visible');
            });
        }

        sidebar.querySelectorAll('[data-sidebar-tip]').forEach(function (link) {
            link.addEventListener('mouseenter', function () {
                showTip(link);
            });
            link.addEventListener('mouseleave', hideTip);
            link.addEventListener('focus', function () {
                showTip(link);
            });
            link.addEventListener('blur', hideTip);
        });

        window.addEventListener('resize', hideTip);
        window.addEventListener('scroll', hideTip, true);
        document.addEventListener('click', hideTip);
        document.addEventListener('alcros:sidebar-layout-change', hideTip);
    }

    function initSidebar() {
        restoreSidebarState();
        bindSidebarToggle();
        initSidebarTooltips();

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
        var closeBtn = document.getElementById('logoutCloseBtn');
        var confirmBtn = document.getElementById('logoutConfirmBtn');
        var panel = modal ? modal.querySelector('.alcros-confirm-modal__panel') : null;
        if (!modal || !cancelBtn || modal.dataset.bound === '1') {
            return;
        }
        modal.dataset.bound = '1';

        function openModal() {
            modal.classList.remove('is-hidden');
            modal.classList.add('is-open');
            document.body.classList.add('alcros-confirm-open');
            if (confirmBtn) {
                window.requestAnimationFrame(function () {
                    confirmBtn.focus();
                });
            }
        }

        function closeModal() {
            modal.classList.add('is-hidden');
            modal.classList.remove('is-open');
            document.body.classList.remove('alcros-confirm-open');
        }

        document.querySelectorAll('[data-logout-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                closeProfileDropdown();
                openModal();
            });
        });

        cancelBtn.addEventListener('click', function (e) {
            e.preventDefault();
            closeModal();
        });
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        }
        if (panel) {
            panel.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
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
