(function () {
    'use strict';

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
