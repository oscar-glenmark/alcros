(function () {
    'use strict';

    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(function (el) { el.classList.add('hidden'); });
        document.querySelectorAll('.tab-btn').forEach(function (btn) { btn.classList.remove('active'); });
        var target = document.getElementById('tab-' + tabId);
        var btn = document.getElementById('btn-' + tabId);
        if (target) target.classList.remove('hidden');
        if (btn) btn.classList.add('active');
        var url = new URL(window.location);
        if (tabId === 'my-account') url.searchParams.delete('tab');
        else url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url);
    }

    window.switchTab = switchTab;

    function openModal(id) {
        document.getElementById(id).classList.remove('hidden');
        document.getElementById(id).classList.add('flex');
    }

    function closeModals() {
        document.querySelectorAll('#editStaffModal, #resetStaffModal').forEach(function (el) {
            el.classList.add('hidden');
            el.classList.remove('flex');
        });
    }

    document.querySelectorAll('.edit-staff-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('editStaffId').value = btn.dataset.staffId;
            document.getElementById('editStaffName').value = btn.dataset.staffName;
            document.getElementById('editStaffRole').value = btn.dataset.staffRole;
            openModal('editStaffModal');
        });
    });

    document.querySelectorAll('.reset-staff-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('resetStaffId').value = btn.dataset.staffId;
            document.getElementById('resetStaffLabel').textContent = btn.dataset.staffId;
            openModal('resetStaffModal');
        });
    });

    document.querySelectorAll('.staff-photo-form').forEach(function (form) {
        var input = form.querySelector('.staff-photo-input');
        var trigger = form.querySelector('.staff-photo-trigger');
        if (!input || !trigger) return;

        trigger.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () {
            if (input.files && input.files.length > 0) form.submit();
        });
    });

    document.querySelectorAll('.close-modal').forEach(function (btn) {
        btn.addEventListener('click', closeModals);
    });

    setTimeout(function () {
        var alert = document.getElementById('alert-banner');
        if (alert) {
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 500);
        }
    }, 5000);
})();
