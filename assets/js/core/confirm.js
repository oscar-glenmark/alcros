(function (global) {
    'use strict';

    if (global.__alcrosConfirmModule) return;
    global.__alcrosConfirmModule = true;

    var modal = null;
    var messageEl = null;
    var pendingResolve = null;
    var pendingForm = null;
    var pendingSubmitter = null;
    var hiddenReviewModals = [];

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
        if (form.id === 'identificationForm' || form.id === 'requirementsForm' || form.id === 'requestScheduleForm') return true;
        if (form.id === 'bookAppointmentForm') return true;

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

    function hideReviewModalsForConfirm() {
        hiddenReviewModals = [];
        document.querySelectorAll('.manage-request-modal:not(.hidden):not(.is-hidden)').forEach(function (el) {
            hiddenReviewModals.push(el);
            el.classList.add('hidden');
            el.setAttribute('data-alcros-hidden-for-confirm', '1');
        });
    }

    function restoreReviewModalsAfterConfirm() {
        hiddenReviewModals.forEach(function (el) {
            if (el.getAttribute('data-alcros-hidden-for-confirm') === '1') {
                el.classList.remove('hidden');
                el.removeAttribute('data-alcros-hidden-for-confirm');
            }
        });
        hiddenReviewModals = [];
    }

    function ensureModal() {
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'alcrosConfirmModal';
        modal.className = 'alcros-confirm-modal is-hidden';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'alcrosConfirmTitle');
        modal.innerHTML =
            '<div class="alcros-confirm-modal__panel">' +
                '<div class="alcros-confirm-modal__head">' +
                    '<div class="alcros-confirm-modal__icon" aria-hidden="true">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>' +
                    '</div>' +
                    '<div class="alcros-confirm-modal__copy">' +
                        '<h3 id="alcrosConfirmTitle" class="alcros-confirm-modal__title">Confirm Action</h3>' +
                        '<p id="alcrosConfirmMessage" class="alcros-confirm-modal__message"></p>' +
                    '</div>' +
                '</div>' +
                '<div class="alcros-confirm-modal__actions">' +
                    '<button type="button" id="alcrosConfirmCancelBtn" class="alcros-confirm-modal__btn alcros-confirm-modal__btn--cancel">Cancel</button>' +
                    '<button type="button" id="alcrosConfirmOkBtn" class="alcros-confirm-modal__btn alcros-confirm-modal__btn--ok">Confirm</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);

        messageEl = modal.querySelector('#alcrosConfirmMessage');
        var okBtn = modal.querySelector('#alcrosConfirmOkBtn');
        var cancelBtn = modal.querySelector('#alcrosConfirmCancelBtn');
        var panel = modal.querySelector('.alcros-confirm-modal__panel');

        okBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            closeModal(true);
        });
        cancelBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            closeModal(false);
        });
        if (panel) {
            panel.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal(false);
            }
        });

        return modal;
    }

    function dismissBlockingLayers() {
        if (global.AlcrosActionResult && typeof global.AlcrosActionResult.close === 'function') {
            global.AlcrosActionResult.close();
        }
        if (global.AlcrosLoading && typeof global.AlcrosLoading.page === 'function') {
            global.AlcrosLoading.page(false);
        }
    }

    function openModal(message) {
        ensureModal();
        dismissBlockingLayers();
        messageEl.textContent = message || 'Are you sure you want to continue?';
        hideReviewModalsForConfirm();
        document.body.classList.add('alcros-confirm-open');
        document.body.appendChild(modal);
        modal.classList.remove('is-hidden');
        modal.classList.add('is-open');
        var okBtn = modal.querySelector('#alcrosConfirmOkBtn');
        if (okBtn) {
            window.requestAnimationFrame(function () {
                okBtn.focus();
            });
        }
    }

    function closeModal(result) {
        if (!modal) return;
        modal.classList.add('is-hidden');
        modal.classList.remove('is-open');
        document.body.classList.remove('alcros-confirm-open');
        if (!result) {
            restoreReviewModalsAfterConfirm();
        }
        var resolve = pendingResolve;
        var form = pendingForm;
        var submitter = pendingSubmitter;
        pendingResolve = null;
        pendingForm = null;
        pendingSubmitter = null;
        if (resolve) resolve(!!result);
        if (result && form) {
            resubmitForm(form, submitter);
        }
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
        if (!form) return;
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

    function initConfirm() {
        if (global.__alcrosConfirmInit) return;
        global.__alcrosConfirmInit = true;
        initFormConfirm();
        initClickConfirm();
    }

    function initFormConfirm() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;

            var submitter = resolveSubmitter(form, e.submitter);
            if (shouldSkipForm(form, submitter)) return;

            e.preventDefault();
            e.stopImmediatePropagation();

            pendingForm = form;
            pendingSubmitter = submitter;

            ask(inferMessage(form, submitter));
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
        inferMessage: inferMessage,
        dismissBlockingLayers: dismissBlockingLayers
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initConfirm);
    } else {
        initConfirm();
    }
})(window);
