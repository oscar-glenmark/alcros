(function () {
    'use strict';

    function getAdminScrollContainer() {
        var main = document.querySelector('.admin-main');
        if (!main) return null;
        return main.querySelector(':scope > .admin-header + *, :scope > header + *') || main;
    }

    function switchTab(tabId) {
        var nav = document.querySelector('.settings-tab-nav');
        var scrollEl = getAdminScrollContainer();
        var navTop = nav ? nav.getBoundingClientRect().top : null;

        document.querySelectorAll('.tab-content').forEach(function (el) { el.classList.add('hidden'); });
        document.querySelectorAll('.tab-btn').forEach(function (btn) { btn.classList.remove('active'); });
        var target = document.getElementById('tab-' + tabId);
        var btn = document.getElementById('btn-' + tabId);
        if (target) target.classList.remove('hidden');
        if (btn) btn.classList.add('active');
        var url = new URL(window.location);
        if (tabId === 'my-account') url.searchParams.delete('tab');
        else url.searchParams.set('tab', tabId);
        if (tabId !== 'admin-tools') url.searchParams.delete('admin_sub');
        window.history.replaceState({}, '', url);

        if (nav && scrollEl && navTop !== null && window.matchMedia('(min-width: 1024px)').matches) {
            requestAnimationFrame(function () {
                var nextTop = nav.getBoundingClientRect().top;
                if (Math.abs(nextTop - navTop) > 1) {
                    scrollEl.scrollTop += (nextTop - navTop);
                }
            });
        }
    }

    window.switchTab = switchTab;

    document.querySelectorAll('.tab-btn[data-tab]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            switchTab(btn.getAttribute('data-tab'));
        });
    });

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
            document.getElementById('editStaffFirstName').value = btn.dataset.staffFirstName || '';
            document.getElementById('editStaffMiddleName').value = btn.dataset.staffMiddleName || '';
            document.getElementById('editStaffLastName').value = btn.dataset.staffLastName || '';
            var emailInput = document.getElementById('editStaffEmail');
            emailInput.value = btn.dataset.staffEmail || '';
            emailInput.dataset.originalEmail = btn.dataset.staffEmail || '';
            emailInput.dataset.staff2svConfirmed = btn.dataset.staff2svConfirmed || '0';
            document.getElementById('editStaffRole').value = btn.dataset.staffRole;
            var checkbox = document.getElementById('editStaff2svCheckbox');
            if (checkbox) checkbox.checked = false;
            updateRecovery2svField(emailInput, document.getElementById('editStaff2svField'), checkbox);
            openModal('editStaffModal');
        });
    });

    function normalizeEmail(value) {
        return (value || '').trim().toLowerCase();
    }

    function recovery2svRequired(emailInput) {
        var current = normalizeEmail(emailInput.value);
        var original = normalizeEmail(emailInput.dataset.originalEmail || '');
        var confirmed = emailInput.dataset.staff2svConfirmed === '1' || emailInput.dataset['2svConfirmed'] === '1';
        if (current === '') return true;
        if (current !== original) return true;
        return !confirmed;
    }

    function updateRecovery2svField(emailInput, fieldEl, checkboxEl) {
        if (!emailInput || !fieldEl) return;
        var required = recovery2svRequired(emailInput);
        fieldEl.classList.toggle('hidden', !required);
        if (checkboxEl) {
            checkboxEl.required = required;
            if (!required) checkboxEl.checked = false;
        }
    }

    var profileEmail = document.getElementById('profileEmail');
    if (profileEmail) {
        var profile2svField = document.getElementById('profile2svField');
        var profileCheckbox = profile2svField ? profile2svField.querySelector('.recovery-2sv-checkbox') : null;
        profileEmail.addEventListener('input', function () {
            updateRecovery2svField(profileEmail, profile2svField, profileCheckbox);
        });
    }

    var editStaffEmail = document.getElementById('editStaffEmail');
    if (editStaffEmail) {
        editStaffEmail.addEventListener('input', function () {
            updateRecovery2svField(editStaffEmail, document.getElementById('editStaff2svField'), document.getElementById('editStaff2svCheckbox'));
        });
    }

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

    var maintenanceToggle = document.getElementById('maintenanceModeToggle');
    var allowRequestsToggle = document.getElementById('allowPublicRequestsToggle');

    if (allowRequestsToggle && maintenanceToggle) {
        allowRequestsToggle.addEventListener('change', function () {
            if (allowRequestsToggle.checked) {
                maintenanceToggle.checked = false;
            }
        });

        maintenanceToggle.addEventListener('change', function () {
            if (maintenanceToggle.checked) {
                allowRequestsToggle.checked = false;
            }
        });

        if (maintenanceToggle.checked && allowRequestsToggle.checked) {
            allowRequestsToggle.checked = false;
        }
    }
})();
