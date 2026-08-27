(function (global) {
    'use strict';

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

    function shouldSkipForm(form, submitter) {
        if (form.dataset.noConfirm !== undefined) return true;
        if (form.dataset.alcrosConfirmed === '1') {
            delete form.dataset.alcrosConfirmed;
            return true;
        }
        if (submitter && submitter.dataset.noConfirm !== undefined) return true;
        if (formMethod(form) === 'get') return true;
        if (submitter && submitter.name === 'action' && submitter.value === 'back') return true;

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

        var queueAction = '';
        if (submitter && submitter.name === 'action') {
            queueAction = String(submitter.value || '').trim().toLowerCase();
        }
        if (!queueAction) {
            queueAction = hiddenValue(form, 'action').toLowerCase();
        }

        var queueMessages = {
            next: 'Call the next person in queue?',
            call_again: 'Call the current ticket again?',
            serve: 'Start serving this ticket?',
            complete: 'Mark this ticket as done?',
            skip: 'Skip this ticket?',
            no_show: 'Mark current ticket as no-show?'
        };
        if (queueMessages[queueAction]) {
            if (queueAction === 'skip' && /no-show|no show/.test(submitterText(submitter).toLowerCase())) {
                return 'Mark current ticket as no-show?';
            }
            return queueMessages[queueAction];
        }

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
        if (form.classList.contains('kiosk-form')) {
            return 'Get a queue number for this service?';
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

    function ask(message) {
        return global.confirm(message || 'Are you sure you want to continue?');
    }

    function markConfirmed(form) {
        if (form) {
            form.dataset.alcrosConfirmed = '1';
        }
    }

    function initFormConfirm() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;

            var submitter = resolveSubmitter(form, e.submitter);
            if (shouldSkipForm(form, submitter)) return;

            if (!ask(inferMessage(form, submitter))) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }, true);
    }

    function initClickConfirm() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-confirm-click]');
            if (!btn) return;

            if (!ask(btn.dataset.confirmClick || btn.dataset.confirm || 'Are you sure you want to continue?')) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
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
