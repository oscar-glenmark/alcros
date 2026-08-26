(function () {
    'use strict';

    var sidebar = document.getElementById('mainAdminSidebar');
    var backdrop = document.getElementById('adminSidebarBackdrop');
    var closeBtn = document.getElementById('sidebarCloseBtn');

    function setSidebarOpen(open) {
        if (!sidebar || !backdrop) return;
        sidebar.classList.toggle('is-open', open);
        backdrop.classList.toggle('is-open', open);
        document.body.classList.toggle('admin-sidebar-open', open);
        backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    }

    window.toggleAdminSidebar = function (force) {
        var next = typeof force === 'boolean' ? force : !sidebar.classList.contains('is-open');
        setSidebarOpen(next);
    };

    window.closeAdminSidebar = function () {
        if (window.matchMedia('(min-width: 1024px)').matches) return;
        setSidebarOpen(false);
    };

    backdrop?.addEventListener('click', function () { setSidebarOpen(false); });
    closeBtn?.addEventListener('click', function () { setSidebarOpen(false); });
    sidebar?.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () { window.closeAdminSidebar(); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar?.classList.contains('is-open')) setSidebarOpen(false);
    });
    window.addEventListener('resize', function () {
        if (window.matchMedia('(min-width: 1024px)').matches) setSidebarOpen(false);
    });

    var navScroll = document.getElementById('sidebarNavScroll');
    if (navScroll) {
        var savedScroll = sessionStorage.getItem('sidebar_scroll_pos');
        if (savedScroll !== null) {
            navScroll.scrollTop = parseInt(savedScroll, 10);
        }
        navScroll.addEventListener('scroll', function () {
            sessionStorage.setItem('sidebar_scroll_pos', navScroll.scrollTop);
        });
    }

    var modal = document.getElementById('logoutConfirmModal');
    var openBtn = document.getElementById('logoutOpenBtn');
    var cancelBtn = document.getElementById('logoutCancelBtn');
    if (!modal || !openBtn || !cancelBtn) return;

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
})();
