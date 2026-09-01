(function (global) {
    'use strict';

    var modal = null;
    var messageEl = null;
    var pendingResolve = null;

    function hiddenValue(form, name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? String(el.value || '').trim() : '';
    }

    function submitterText(submitter) {
        if (!submitter) return '';
        return String(submitter.textContent || submitter.value || '').replace(/\s+/g, ' ').trim();
    }

    function formMethod(form) {
        return String(form.getAttribute('method') || 'get').toLowerCase();
    }

    function queueActionValue(form, submitter) {
        if (submitter && submitter.name === 'action') {
            return String(submitter.value || '').trim().toLowerCase();
        }
        return hiddenValue(form, 'action').toLowerCase();
    }

    function isQueueForm(form, submitter) {
        if (form.classList.contains('kiosk-form')) return true;
        if (document.body && document.body.dataset.realtime === 'queue') return true;

        var action = queueActionValue(form, submitter);
        if (!action) return false;

        var queueActions = ['next', 'call_again', 'serve', 'complete', 'skip', 'no_show'];
        if (queueActions.indexOf(action) === -1) return false;
        if (form.querySelector('[name="ticket_id"]')) return true;
        if (form.querySelector('[name="purpose"]') && !form.querySelector('[name="step"]')) return true;

        return false;
    }

    function shouldSkipForm(form, submitter) {
        if (form.dataset.noConfirm !== undefined) return true;
        if (form.dataset.alcrosConfirmed === '1') {
            delete form.dataset.alcrosConfirmed;
            return true;
        }
        if (submitter && submitter.dataset.noConfirm !== undefined) return true;
        if (formMethod(form) === 'get') return true;
        if (submitter && submitter.name === 'action' && submitter.value === 'back') return true;
        if (isQueueForm(form, submitter)) return true;

        var actionPath = String(form.getAttribute('action') || '').toLowerCase();
        if (/login\.php|forgot_password\.php/.test(actionPath)) return true;
        if (form.id === 'loginForm' || form.id === 'forgotPasswordForm') return true;

        return false;
    }

    function resolveSubmitter(form, submitter) {
        if (submitter && submitter instanceof HTMLElement) return submitter;
        return form.querySelector('button[type="submit"]:focus, input[type="submit"]:focus')
            || form.querySelector('button[type="submit"], input[type="submit"]');
    }

    function inferMessage(form, submitter) {
        if (submitter && submitter.dataset.confirm) return submitter.dataset.confirm;
        if (form.dataset.confirm) return form.dataset.confirm;

        if (form.querySelector('[name="delete_request"]')) {
            return 'Delete this completed request? This cannot be undone.';
        }
        if (form.querySelector('[name="delete_appointment"]')) {
            return 'Delete this appointment permanently?';
        }

        var settingsAction = hiddenValue(form, 'settings_action');
        if (settingsAction) {
            var settingsMessages = {
                update_profile: 'Save your profile changes?',
                change_password: 'Change your password?',
                add_staff: 'Add this staff member?',
                update_staff: 'Save staff account changes?',
                reset_staff_password: 'Reset this staff member\'s password?',
                upload_staff_photo: 'Upload this profile photo?',
                remove_staff_photo: 'Remove profile photo for this account?',
                remove_staff: 'Remove this staff account permanently?',
                save_settings: 'Save system settings?',
                clear_old_logs: 'Delete old activity logs permanently?'
            };
            if (settingsMessages[settingsAction]) {
                return settingsMessages[settingsAction];
            }
        }

        var queueAction = queueActionValue(form, submitter);

        if (queueAction === 'import_csv') {
            return 'Import records from this CSV file?';
        }
        if (queueAction === 'create') {
            return 'Add this civil record?';
        }
        if (queueAction === 'update') {
            return 'Save changes to this record?';
        }

        if (form.querySelector('select[name="status"]')) {
            var select = form.querySelector('select[name="status"]');
            if (select && select.value) {
                var label = select.options[select.selectedIndex].text.replace(/\s+/g, ' ').trim();
                return 'Save status as "' + label + '"?';
            }
        }

        if (submitter && submitter.name === 'action' && submitter.value === 'next') {
            if (form.id === 'identificationForm' || form.id === 'requirementsForm') {
                return 'Continue to the next step?';
            }
            if (submitter.hasAttribute('data-appointment-submit')) {
                return 'Submit this document request?';
            }
        }

        if (form.id === 'bookAppointmentForm') {
            return 'Book this appointment?';
        }

        var text = submitterText(submitter).toLowerCase();
        if (/delete|remove|clear|reset password/.test(text)) {
            return 'Are you sure? This action may not be undoable.';
        }
        if (/save|update|submit|book|add|import|apply|send|continue|sign in|signing in/.test(text)) {
            return 'Confirm: ' + submitterText(submitter) + '?';
        }

        return 'Are you sure you want to continue?';
    }

    function ensureModal() {
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'alcrosConfirmModal';
        modal.className = 'fixed inset-0 bg-black/40 z-[200] hidden items-center justify-center p-4';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'alcrosConfirmTitle');
        modal.innerHTML =
            '<div class="bg-white rounded-2xl border border-gray-100 shadow-xl w-full max-w-sm p-6">' +
                '<div class="flex items-start gap-3 mb-5">' +
                    '<div class="bg-blue-50 text-blue-600 p-2 rounded-xl shrink-0">' +
                        '<i data-lucide="help-circle" class="w-5 h-5"></i>' +
                    '</div>' +
                    '<div class="min-w-0">' +
                        '<h3 id="alcrosConfirmTitle" class="text-base font-black text-slate-900">Confirm Action</h3>' +
                        '<p id="alcrosConfirmMessage" class="text-sm text-gray-500 mt-1 leading-relaxed"></p>' +
                    '</div>' +
                '</div>' +
                '<div class="flex gap-2">' +
                    '<button type="button" id="alcrosConfirmCancelBtn" class="flex-1 border border-gray-200 rounded-xl py-2.5 text-sm font-bold text-gray-600 hover:bg-gray-50">Cancel</button>' +
                    '<button type="button" id="alcrosConfirmOkBtn" class="flex-1 bg-[#071428] hover:bg-[#0c2247] text-white rounded-xl py-2.5 text-sm font-bold">Confirm</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);

        messageEl = modal.querySelector('#alcrosConfirmMessage');
        var okBtn = modal.querySelector('#alcrosConfirmOkBtn');
        var cancelBtn = modal.querySelector('#alcrosConfirmCancelBtn');

        okBtn.addEventListener('click', function () {
            closeModal(true);
        });
        cancelBtn.addEventListener('click', function () {
            closeModal(false);
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('flex')) {
                closeModal(false);
            }
        });

        return modal;
    }

    function openModal(message) {
        ensureModal();
        messageEl.textContent = message || 'Are you sure you want to continue?';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        if (typeof lucide !== 'undefined') lucide.createIcons();
        var okBtn = modal.querySelector('#alcrosConfirmOkBtn');
        if (okBtn) okBtn.focus();
    }

    function closeModal(result) {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        var resolve = pendingResolve;
        pendingResolve = null;
        if (resolve) resolve(!!result);
    }

    function ask(message) {
        return new Promise(function (resolve) {
            pendingResolve = resolve;
            openModal(message);
        });
    }

    function markConfirmed(form) {
        if (form) {
            form.dataset.alcrosConfirmed = '1';
        }
    }

    function resubmitForm(form, submitter) {
        markConfirmed(form);
        if (typeof form.requestSubmit === 'function') {
            try {
                form.requestSubmit(submitter || undefined);
                return;
            } catch (err) {
                /* fall through */
            }
        }
        form.submit();
    }

    function initFormConfirm() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;

            var submitter = resolveSubmitter(form, e.submitter);
            if (shouldSkipForm(form, submitter)) return;

            e.preventDefault();
            e.stopImmediatePropagation();

            ask(inferMessage(form, submitter)).then(function (ok) {
                if (ok) resubmitForm(form, submitter);
            });
        }, true);
    }

    function initClickConfirm() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-confirm-click]');
            if (!btn) return;
            if (btn.dataset.alcrosConfirmBypass === '1') {
                delete btn.dataset.alcrosConfirmBypass;
                return;
            }

            e.preventDefault();
            e.stopImmediatePropagation();

            var msg = btn.dataset.confirmClick || btn.dataset.confirm || 'Are you sure you want to continue?';
            ask(msg).then(function (ok) {
                if (!ok) return;
                btn.dataset.alcrosConfirmBypass = '1';
                btn.click();
            });
        }, true);
    }

    global.AlcrosConfirm = {
        ask: ask,
        markConfirmed: markConfirmed,
        inferMessage: inferMessage
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initFormConfirm();
            initClickConfirm();
        });
    } else {
        initFormConfirm();
        initClickConfirm();
    }
})(window);
