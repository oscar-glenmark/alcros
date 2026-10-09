(function () {
    'use strict';

    var panel = document.getElementById('appointmentDetailPanel');
    var emptyState = document.getElementById('appointmentDetailEmpty');
    var content = document.getElementById('appointmentDetailContent');
    var bodyWrap = document.getElementById('appointmentsBody');
    var panelActionsWrap = document.getElementById('appointmentDetailActions');
    var modal = document.getElementById('appointmentReviewModal');
    var modalActionsWrap = document.getElementById('modalAppointmentDetailActions');
    var authFieldsEl = document.getElementById('appointmentActionAuthFields');
    var pageConfig = window.AlcrosPage && typeof window.AlcrosPage.readConfig === 'function'
        ? window.AlcrosPage.readConfig('page-config')
        : {};
    var useSidePanel = pageConfig.useSidePanel === true;

    function dismissBlockingUi() {
        if (window.AlcrosConfirm && typeof window.AlcrosConfirm.dismissBlockingLayers === 'function') {
            window.AlcrosConfirm.dismissBlockingLayers();
        }
        if (window.AlcrosLoading && typeof window.AlcrosLoading.page === 'function') {
            window.AlcrosLoading.page(false);
        }
        document.body.classList.remove('alcros-loading-open', 'overflow-hidden');
    }

    if (!bodyWrap) return;

    var panelView = {
        prefix: '',
        title: 'appointmentViewTitle',
        code: 'appointmentViewCode',
        created: 'appt-view-created',
        actions: panelActionsWrap
    };

    var modalView = {
        prefix: 'modal-',
        title: 'modalAppointmentViewTitle',
        code: 'modalAppointmentViewCode',
        created: 'modal-appt-view-created',
        actions: modalActionsWrap
    };

        function setText(id, value) {
            var el = document.getElementById(id);
        if (!el) return;
        var display = value || '—';
        el.textContent = display;
        el.classList.toggle('manage-detail-value--empty', !value || value === '—');
    }

    function setHtml(id, html) {
        var el = document.getElementById(id);
        if (!el) return;
        if (html) {
            el.innerHTML = html;
            el.classList.remove('manage-detail-value--empty');
        } else {
            el.textContent = '—';
            el.classList.add('manage-detail-value--empty');
        }
    }

    function toggleDomRow(prefix, data) {
        var wrap = document.getElementById(prefix + 'appt-view-dom-wrap');
        if (!wrap) return;
        var isMarriage = data.document_type_key === 'marriage';
        var hasDom = data.date_of_marriage && data.date_of_marriage !== '—';
        if (isMarriage || hasDom) {
            setText(prefix + 'appt-view-dom', data.date_of_marriage);
            wrap.classList.remove('hidden');
        } else {
            wrap.classList.add('hidden');
        }
        }

        function renderIdFiles(container, data) {
            if (!container) return;
            if (window.AlcrosIdPreview && typeof window.AlcrosIdPreview.renderGrid === 'function') {
                container.innerHTML = window.AlcrosIdPreview.renderGrid(data.id_front_path, data.id_back_path);
                if (typeof window.AlcrosIdPreview.wireCards === 'function') {
                    window.AlcrosIdPreview.wireCards(container);
                }
                if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                    lucide.createIcons({ nodes: [container] });
                }
                return;
            }
            var html = idLink('Front ID', data.id_front_path) + idLink('Back ID', data.id_back_path);
            container.innerHTML = html || '<span class="text-xs text-gray-400 italic">No ID files uploaded.</span>';
        }

        function idLink(label, path) {
            if (!path) return '';
            if (window.AlcrosIdPreview && typeof window.AlcrosIdPreview.resolveUrl === 'function') {
                path = window.AlcrosIdPreview.resolveUrl(path);
            }
            var isPdf = /\.pdf$/i.test(path);
            return '<a href="' + path.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 bg-blue-50 border border-blue-100 px-3 py-2 rounded-lg hover:bg-blue-100">' +
                '<i data-lucide="' + (isPdf ? 'file-text' : 'image') + '" class="w-3.5 h-3.5"></i>' + label + '</a>';
        }

    function markSelectedRow(row) {
        document.querySelectorAll('.manage-requests-row.is-selected').forEach(function (el) {
            el.classList.remove('is-selected');
        });
        if (row) row.classList.add('is-selected');
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function actionButtonClass(action) {
        if (action === 'confirmed' || action === 'completed') {
            return 'manage-detail-action manage-detail-action--primary';
        }
        if (action === 'cancelled' || action === 'no_show') {
            return 'manage-detail-action manage-detail-action--danger';
        }
        return 'manage-detail-action';
    }

    function actionConfirmMessage(action, data) {
        var code = data.appointment_code || 'this appointment';
        if (action === 'confirmed') {
            return 'Confirm appointment ' + code + '? The citizen will be notified of the confirmed visit.';
        }
        if (action === 'completed') {
            return 'Mark ' + code + ' as completed? Use this when the citizen was served.';
        }
        if (action === 'cancelled') {
            return 'Reject appointment ' + code + '? The citizen will be notified that the appointment was declined.';
        }
        if (action === 'no_show') {
            return 'Mark ' + code + ' as no-show? The citizen did not arrive for this visit.';
        }
        return 'Update ' + code + '?';
    }

    function buildStatusForm(data, action, label) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = pageConfig.formAction || 'appointment.php';
        form.className = 'manage-detail-action-form';
        form.dataset.noConfirm = '';
        form.dataset.ajax = '1';
        form.dataset.noLoading = '';

        if (authFieldsEl) {
            form.innerHTML = authFieldsEl.innerHTML;
        }

        form.insertAdjacentHTML('beforeend',
            '<input type="hidden" name="redirect_status" value="' + escapeHtml(pageConfig.redirectStatus || 'all') + '">' +
            '<input type="hidden" name="redirect_date" value="' + escapeHtml(pageConfig.redirectDate || '') + '">' +
            '<input type="hidden" name="redirect_q" value="' + escapeHtml(pageConfig.redirectQ || '') + '">' +
            '<input type="hidden" name="appointment_id" value="' + escapeHtml(data.id) + '">' +
            '<input type="hidden" name="update_status" value="1">' +
            '<input type="hidden" name="status" value="' + escapeHtml(action) + '">' +
            '<button type="button" class="' + actionButtonClass(action) + ' manage-action-trigger" data-manage-action="' + escapeHtml(action) + '" data-loading-text="Saving…">' + escapeHtml(label) + '</button>'
        );

        return form;
    }

    function buildDeleteForm(data) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = pageConfig.formAction || 'appointment.php';
        form.className = 'manage-detail-action-form';
        form.dataset.noConfirm = '';
        form.dataset.ajax = '1';
        form.dataset.noLoading = '';

        if (authFieldsEl) {
            form.innerHTML = authFieldsEl.innerHTML;
        }

        form.insertAdjacentHTML('beforeend',
            '<input type="hidden" name="redirect_status" value="' + escapeHtml(pageConfig.redirectStatus || 'all') + '">' +
            '<input type="hidden" name="redirect_date" value="' + escapeHtml(pageConfig.redirectDate || '') + '">' +
            '<input type="hidden" name="redirect_q" value="' + escapeHtml(pageConfig.redirectQ || '') + '">' +
            '<input type="hidden" name="appointment_id" value="' + escapeHtml(data.id) + '">' +
            '<input type="hidden" name="delete_appointment" value="1">' +
            '<button type="button" class="manage-detail-action manage-detail-action--danger manage-detail-action--icon manage-action-trigger" data-manage-action="delete" data-loading-text="Deleting…" title="Delete completed appointment" aria-label="Delete completed appointment">' +
                '<i data-lucide="trash-2" class="w-4 h-4"></i>' +
            '</button>'
        );

        return form;
    }

    function inlineConfirmYesLabel(action) {
        if (action === 'confirmed') return 'Yes, confirm';
        if (action === 'completed') return 'Yes, complete';
        if (action === 'cancelled') return 'Yes, reject';
        if (action === 'no_show') return 'Yes, mark no-show';
        if (action === 'delete') return 'Yes, delete';
        return 'Yes, continue';
    }

    function appointmentActionConfirmMessage(data, action) {
        if (action === 'delete') {
            return 'Move this completed appointment to recently deleted?';
        }
        return actionConfirmMessage(action, data);
    }

    function setFormRejectionReason(form, reason) {
        if (!form) return;
        var el = form.querySelector('input[name="rejection_reason"]');
        if (!el) {
            el = document.createElement('input');
            el.type = 'hidden';
            el.name = 'rejection_reason';
            form.appendChild(el);
        }
        el.value = reason || '';
    }

    function isAppointmentRejectAction(action) {
        return action === 'cancelled';
    }

    function confirmAppointmentAction(form, msg, action, loadingMessage, actionsWrap, data) {
        if (isAppointmentRejectAction(action) && window.AlcrosConfirm && typeof window.AlcrosConfirm.askWithReason === 'function') {
            window.AlcrosConfirm.askWithReason(msg, {
                label: 'Reason for rejection',
                placeholder: 'Explain why this appointment is being rejected…',
                okLabel: 'Reject'
            }).then(function (reason) {
                if (!reason) return;
                setFormRejectionReason(form, reason);
                submitAppointmentForm(form, loadingMessage);
            });
            return;
        }

        if (window.AlcrosConfirm && typeof window.AlcrosConfirm.ask === 'function') {
            window.AlcrosConfirm.ask(msg).then(function (ok) {
                if (!ok) return;
                submitAppointmentForm(form, loadingMessage);
            });
            return;
        }

        showInlineConfirm(actionsWrap, data, form, action);
    }

    function submitAppointmentForm(form, loadingMessage) {
        if (!form) return;

        if (window.AlcrosLoading && typeof window.AlcrosLoading.page === 'function') {
            window.AlcrosLoading.page(true, loadingMessage || 'Saving…');
        }

        var formData = new FormData(form);
        formData.set('ajax', '1');

        fetch(form.action || pageConfig.formAction || 'appointment.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return { ok: false, message: 'Could not save. Please try again.' };
                });
            })
            .then(function (data) {
                if (window.AlcrosLoading && typeof window.AlcrosLoading.page === 'function') {
                    window.AlcrosLoading.page(false);
                }
                closeModal();

                if (data.ok) {
                    if (window.AlcrosActionResult && typeof window.AlcrosActionResult.show === 'function') {
                        window.AlcrosActionResult.show(data.type || 'success', data.message || 'Saved successfully.');
                    }
                    var redirectUrl = data.redirect_url || '';
                    window.setTimeout(function () {
                        if (redirectUrl) {
                            window.location.href = redirectUrl;
                            return;
                        }
                        window.location.reload();
                    }, 900);
                    return;
                }

                if (window.AlcrosActionResult && typeof window.AlcrosActionResult.show === 'function') {
                    window.AlcrosActionResult.show('error', data.message || 'Could not save. Please try again.');
                }
            })
            .catch(function () {
                if (window.AlcrosLoading && typeof window.AlcrosLoading.page === 'function') {
                    window.AlcrosLoading.page(false);
                }
                if (window.AlcrosActionResult && typeof window.AlcrosActionResult.show === 'function') {
                    window.AlcrosActionResult.show('error', 'Could not save. Please check your connection and try again.');
                }
            });
    }

    function showInlineConfirm(actionsWrap, data, form, action) {
        var rejectField = isAppointmentRejectAction(action)
            ? '<label class="manage-inline-confirm__label">Reason for rejection<textarea class="manage-inline-confirm__reason" rows="3" maxlength="2000" placeholder="Explain why this appointment is being rejected…"></textarea></label>'
            : '';

        actionsWrap.innerHTML =
            '<div class="manage-inline-confirm">' +
                '<p class="manage-inline-confirm__msg">' + escapeHtml(appointmentActionConfirmMessage(data, action)) + '</p>' +
                rejectField +
                '<div class="manage-detail-actions__buttons">' +
                    '<button type="button" class="manage-detail-action manage-detail-action--primary" data-inline-confirm-yes>' + escapeHtml(inlineConfirmYesLabel(action)) + '</button>' +
                    '<button type="button" class="manage-detail-action" data-inline-confirm-back>Go back</button>' +
                '</div>' +
            '</div>';
        actionsWrap.classList.remove('hidden');

        actionsWrap.querySelector('[data-inline-confirm-yes]').addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var loadingMessage = action === 'delete' ? 'Deleting…' : 'Saving appointment…';
            if (isAppointmentRejectAction(action)) {
                var reasonEl = actionsWrap.querySelector('.manage-inline-confirm__reason');
                var reason = reasonEl ? String(reasonEl.value || '').trim() : '';
                if (!reason) {
                    if (reasonEl) reasonEl.focus();
                    return;
                }
                setFormRejectionReason(form, reason);
            }
            submitAppointmentForm(form, loadingMessage);
        });

        actionsWrap.querySelector('[data-inline-confirm-back]').addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            renderDetailActions(actionsWrap, data);
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    }

    function followUpFormatLongDate(iso) {
        if (!iso) {
            return '';
        }
        if (window.AlcrosDateDisplay && typeof window.AlcrosDateDisplay.formatLongDate === 'function') {
            return window.AlcrosDateDisplay.formatLongDate(iso);
        }
        var parts = String(iso).trim().split('-');
        if (parts.length !== 3) {
            return iso;
        }
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10) - 1;
        var d = parseInt(parts[2], 10);
        var date = new Date(y, m, d);
        if (isNaN(date.getTime())) {
            return iso;
        }
        return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }

    function followUpDateBlockMessage(reason) {
        if (reason === 'weekend') {
            return 'Follow-up date cannot fall on a weekend. Choose Monday to Friday.';
        }
        if (reason === 'holiday') {
            return 'Follow-up date cannot fall on a holiday. Choose another weekday.';
        }
        if (reason === 'past') {
            return 'Follow-up date must be after today.';
        }
        return 'Choose a valid follow-up date.';
    }

    function followUpDateBlockReason(iso) {
        if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
            return 'invalid';
        }
        var minDate = pageConfig.minFollowUpDate || '';
        if (minDate && iso < minDate) {
            return 'past';
        }
        if (pageConfig.todayDate && iso <= pageConfig.todayDate) {
            return 'past';
        }
        var parts = iso.split('-');
        var date = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        if (isNaN(date.getTime())) {
            return 'invalid';
        }
        var dow = date.getDay();
        if (dow === 0 || dow === 6) {
            return 'weekend';
        }
        var holidays = pageConfig.officeHolidayDates || [];
        if (holidays.indexOf(iso) !== -1) {
            return 'holiday';
        }
        var suffix = iso.slice(4);
        var recurring = pageConfig.officeHolidayRecurring || [];
        if (recurring.indexOf(suffix) !== -1) {
            return 'holiday';
        }
        return null;
    }

    function wireFollowUpDateField(container) {
        if (!container || container.getAttribute('data-follow-up-date-wired') === '1') {
            return;
        }
        container.setAttribute('data-follow-up-date-wired', '1');

        var input = container.querySelector('input[name="follow_up_date"]');
        var displayEl = container.querySelector('[data-follow-up-date-display]');
        var controlEl = container.querySelector('[data-follow-up-date-control]');
        var errEl = container.querySelector('[data-follow-up-date-error]');
        if (!input || !displayEl) {
            return;
        }

        function setDisplay(iso) {
            var placeholder = displayEl.getAttribute('data-placeholder') || 'mm/dd/yyyy';
            if (!iso) {
                displayEl.textContent = placeholder;
                displayEl.classList.add('is-placeholder');
                return;
            }
            displayEl.textContent = followUpFormatLongDate(iso);
            displayEl.classList.remove('is-placeholder');
        }

        function setError(reason) {
            if (!errEl) {
                return;
            }
            if (reason) {
                errEl.textContent = followUpDateBlockMessage(reason);
                errEl.classList.remove('hidden');
                input.setCustomValidity(followUpDateBlockMessage(reason));
            } else {
                errEl.textContent = '';
                errEl.classList.add('hidden');
                input.setCustomValidity('');
            }
        }

        function syncFromInput() {
            if (!input.value) {
                setError(null);
                setDisplay('');
                return;
            }
            var reason = followUpDateBlockReason(input.value);
            if (reason) {
                input.value = '';
                setDisplay('');
                setError(reason);
                return;
            }
            setError(null);
            setDisplay(input.value);
        }

        input.addEventListener('change', syncFromInput);
        input.addEventListener('input', syncFromInput);
        if (pageConfig.minFollowUpDate) {
            input.setAttribute('min', pageConfig.minFollowUpDate);
        }
        syncFromInput();
    }

    function followUpDateFieldValid(form) {
        var input = form && form.querySelector('input[name="follow_up_date"]');
        if (!input) {
            return true;
        }
        if (!input.value || followUpDateBlockReason(input.value)) {
            return false;
        }
        return true;
    }

    function followUpSaveConfirmMessage(mode) {
        if (mode === 'update') {
            return 'Update this follow-up schedule? The return visit date and note will be saved.';
        }
        return 'Save this follow-up schedule? The citizen may receive a reminder the day before the follow-up date if they opted in at booking.';
    }

    function isFollowUpSaveTrigger(btn, form) {
        if (!btn || !form) return false;
        if (btn.getAttribute('data-follow-up-action') === 'save') return true;
        return btn.classList.contains('manage-follow-up__save') && !!form.querySelector('[name="follow_up_save"]');
    }

    function confirmThenSubmitFollowUp(followForm, followBtn, loadingMessage) {
        var dateInput = followForm.querySelector('input[name="follow_up_date"]');
        if (dateInput && !followUpDateFieldValid(followForm)) {
            var reason = followUpDateBlockReason(dateInput.value) || 'past';
            var errEl = followForm.querySelector('[data-follow-up-date-error]');
            if (errEl) {
                errEl.textContent = followUpDateBlockMessage(reason);
                errEl.classList.remove('hidden');
            }
            dateInput.setCustomValidity(followUpDateBlockMessage(reason));
            if (dateInput.reportValidity) {
                dateInput.reportValidity();
            }
            return;
        }

        var mode = followBtn.getAttribute('data-follow-up-mode') || 'create';
        var msg = followUpSaveConfirmMessage(mode);

        if (window.AlcrosConfirm && typeof window.AlcrosConfirm.ask === 'function') {
            window.AlcrosConfirm.ask(msg).then(function (ok) {
                if (!ok) return;
                submitAppointmentForm(followForm, loadingMessage);
            });
            return;
        }

        if (window.confirm(msg)) {
            submitAppointmentForm(followForm, loadingMessage);
        }
    }

    function bindActionTriggers() {
        document.addEventListener('click', function (e) {
            var followBtn = e.target.closest('.manage-follow-up-trigger');
            if (followBtn) {
                e.preventDefault();
                e.stopPropagation();
                var followForm = followBtn.closest('form.manage-detail-action-form');
                if (!followForm) {
                    var formId = followBtn.getAttribute('form');
                    if (formId) {
                        followForm = document.getElementById(formId);
                    }
                }
                if (!followForm) return;

                var loadingMessage = followBtn.getAttribute('data-loading-text') || 'Saving follow-up…';
                if (isFollowUpSaveTrigger(followBtn, followForm)) {
                    confirmThenSubmitFollowUp(followForm, followBtn, loadingMessage);
                    return;
                }

                submitAppointmentForm(followForm, loadingMessage);
                return;
            }

            var btn = e.target.closest('.manage-action-trigger');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            var form = btn.closest('form.manage-detail-action-form');
            var actionsWrap = btn.closest('#modalAppointmentDetailActions, #appointmentDetailActions');
            if (!form || !actionsWrap || !actionsWrap._appointmentData) return;

            var action = btn.getAttribute('data-manage-action') || '';
            var data = actionsWrap._appointmentData;
            var msg = appointmentActionConfirmMessage(data, action);
            var loadingMessage = action === 'delete' ? 'Deleting…' : 'Saving appointment…';

            confirmAppointmentAction(form, msg, action, loadingMessage, actionsWrap, data);
        });
    }

    function buildFollowUpHiddenFields() {
        return '<input type="hidden" name="redirect_status" value="' + escapeHtml(pageConfig.redirectStatus || 'all') + '">' +
            '<input type="hidden" name="redirect_date" value="' + escapeHtml(pageConfig.redirectDate || '') + '">' +
            '<input type="hidden" name="redirect_q" value="' + escapeHtml(pageConfig.redirectQ || '') + '">';
    }

    function wireFollowUpPopovers(wrap) {
        if (!wrap || wrap.getAttribute('data-follow-up-popovers-wired') === '1') {
            return;
        }
        wrap.setAttribute('data-follow-up-popovers-wired', '1');

        var logDetails = wrap.querySelector('[data-follow-up-log]');
        var infoDetails = wrap.querySelector('.manage-follow-up-info');
        var popovers = [logDetails, infoDetails].filter(Boolean);

        function closeOthers(exceptEl) {
            popovers.forEach(function (el) {
                if (el !== exceptEl && el.open) {
                    el.open = false;
                }
            });
        }

        popovers.forEach(function (el) {
            el.addEventListener('toggle', function () {
                if (el.open) {
                    closeOthers(el);
                }
            });
        });
    }

    function renderFollowUpLog(wrap, data) {
        if (!wrap) return;
        wireFollowUpPopovers(wrap);
        var logDetails = wrap.querySelector('[data-follow-up-log]');
        var panel = wrap.querySelector('[data-follow-up-log-panel]');
        if (!logDetails || !panel) return;

        var log = Array.isArray(data.follow_up_log) ? data.follow_up_log : [];
        logDetails.classList.toggle('hidden', log.length === 0);

        if (log.length === 0) {
            panel.innerHTML = '';
            return;
        }

        var html = '<p class="manage-follow-up-log__title">Follow-up history</p><ul class="manage-follow-up-log__list">';
        log.forEach(function (entry) {
            var statusClass = 'is-' + String(entry.status || 'pending');
            html += '<li class="manage-follow-up-log__item ' + statusClass + '">' +
                '<div class="manage-follow-up-log__item-head">' +
                '<span class="manage-follow-up-log__status">' + escapeHtml(entry.status_label || entry.status || '—') + '</span>' +
                '<span class="manage-follow-up-log__date">' + escapeHtml(entry.follow_up_date || '—') + '</span>' +
                '</div>';
            if (entry.staff_note) {
                html += '<p class="manage-follow-up-log__note">' + escapeHtml(entry.staff_note) + '</p>';
            }
            if (entry.reminder_summary) {
                html += '<p class="manage-follow-up-log__meta">' + escapeHtml(entry.reminder_summary) + '</p>';
            }
            if (entry.cancel_reason) {
                html += '<p class="manage-follow-up-log__meta">Cancel reason: ' + escapeHtml(entry.cancel_reason) + '</p>';
            }
            var audit = [];
            if (entry.created_at) audit.push('Set ' + entry.created_at);
            if (entry.updated_at && entry.updated_at !== entry.created_at) audit.push('Updated ' + entry.updated_at);
            if (entry.created_by) audit.push('By ' + entry.created_by);
            if (audit.length) {
                html += '<p class="manage-follow-up-log__audit">' + escapeHtml(audit.join(' · ')) + '</p>';
            }
            html += '</li>';
        });
        html += '</ul>';
        panel.innerHTML = html;
    }

    function renderFollowUp(prefix, data) {
        var wrap = document.getElementById(prefix + 'appt-view-follow-up-wrap');
        var body = document.getElementById(prefix + 'appt-view-follow-up-body');
        if (!wrap || !body) return;

        var followUp = data.follow_up;
        var canSchedule = data.can_schedule_follow_up === true;
        var hasPending = followUp && followUp.status === 'pending';
        var hasLog = Array.isArray(data.follow_up_log) && data.follow_up_log.length > 0;

        renderFollowUpLog(wrap, data);

        if (!canSchedule && !hasPending && !hasLog) {
            wrap.classList.add('hidden');
            body.innerHTML = '';
            return;
        }

        wrap.classList.remove('hidden');

        var html = '<div class="manage-follow-up-panel">';

        if (hasPending) {
            html += '<div class="manage-follow-up-current">' +
                '<p class="manage-follow-up-current__eyebrow">Scheduled follow-up</p>' +
                '<p class="manage-follow-up-current__date">' + escapeHtml(followUp.follow_up_date || '—') + '</p>';
            if (followUp.staff_note) {
                html += '<p class="manage-follow-up-current__note"><span>Note:</span> ' + escapeHtml(followUp.staff_note) + '</p>';
            }
            if (followUp.reminder_sent) {
                html += '<p class="manage-follow-up-current__badge">Reminder already sent</p>';
            }
            html += '</div>';
        }

        var onFollowUpsView = pageConfig.redirectStatus === 'follow_ups';
        var inlineSaveAndCancel = canSchedule && hasPending && followUp && followUp.id && !onFollowUpsView;

        function buildFollowUpCancelForm(followUpId) {
            var cancelHtml = '<form method="POST" action="' + escapeHtml(pageConfig.formAction || 'appointment.php') + '" class="manage-detail-action-form manage-detail-action-form--stacked manage-follow-up__secondary-form" data-no-confirm data-ajax="1" data-no-loading>';
            if (authFieldsEl) {
                cancelHtml += authFieldsEl.innerHTML;
            }
            cancelHtml += buildFollowUpHiddenFields() +
                '<input type="hidden" name="follow_up_id" value="' + escapeHtml(followUpId) + '">' +
                '<input type="hidden" name="follow_up_cancel" value="1">' +
                '<button type="button" class="manage-detail-action manage-detail-action--danger manage-follow-up-trigger" data-loading-text="Saving…">Cancel reminder</button></form>';
            return cancelHtml;
        }

        if (canSchedule) {
            var customDate = hasPending && followUp.follow_up_date_iso ? followUp.follow_up_date_iso : '';
            var noteVal = hasPending && followUp.staff_note ? followUp.staff_note : '';
            var followUpDateInputId = prefix.replace(/-$/, '') + '-follow-up-date-input';
            var formTitle = hasPending ? 'Update schedule' : 'Set follow-up date';
            var followUpSaveMode = hasPending ? 'update' : 'create';
            var followUpSaveAttrs = ' data-follow-up-action="save" data-follow-up-mode="' + followUpSaveMode + '"';

            html += '<form method="POST" action="' + escapeHtml(pageConfig.formAction || 'appointment.php') + '" id="appointmentFollowUpSaveForm" class="manage-follow-up__form manage-detail-action-form manage-detail-action-form--stacked" data-no-confirm data-ajax="1" data-no-loading>';
            if (authFieldsEl) {
                html += authFieldsEl.innerHTML;
            }
            html += buildFollowUpHiddenFields() +
                '<input type="hidden" name="appointment_id" value="' + escapeHtml(data.id) + '">' +
                '<input type="hidden" name="follow_up_save" value="1">' +
                '<p class="manage-follow-up__form-title">' + escapeHtml(formTitle) + '</p>' +
                '<div class="manage-follow-up__date-label" data-follow-up-date-field>' +
                '<label class="manage-follow-up__field-label" for="' + escapeHtml(followUpDateInputId) + '">Follow-up date</label>' +
                '<div class="manage-follow-up__date-control" data-follow-up-date-control>' +
                '<span class="manage-follow-up__date-face" data-follow-up-date-display data-placeholder="mm/dd/yyyy">mm/dd/yyyy</span>' +
                '<input type="date" id="' + escapeHtml(followUpDateInputId) + '" name="follow_up_date" value="' + escapeHtml(customDate) + '"' +
                (pageConfig.minFollowUpDate ? ' min="' + escapeHtml(pageConfig.minFollowUpDate) + '"' : '') +
                ' class="manage-follow-up__date-input-native" aria-label="Follow-up date" required>' +
                '</div>' +
                '<p class="manage-follow-up__date-hint">Monday to Friday only, excluding holidays.</p>' +
                '<p class="manage-follow-up__date-error hidden" data-follow-up-date-error role="alert"></p>' +
                '</div>' +
                '<label class="manage-follow-up__note-label">' +
                '<span class="manage-follow-up__field-label">Note for the citizen (optional)</span>' +
                '<textarea name="follow_up_note" rows="3" class="manage-follow-up__note-input" placeholder="e.g. Bring updated IDs">' + escapeHtml(noteVal) + '</textarea>' +
                '</label>';

            if (inlineSaveAndCancel) {
                html += '</form>';
                html += '<div class="manage-follow-up__actions-row manage-follow-up__actions-row--inline">';
                html += buildFollowUpCancelForm(followUp.id);
                html += '<div class="manage-follow-up__actions-primary manage-follow-up__actions-primary--inline">' +
                    '<button type="button" form="appointmentFollowUpSaveForm" class="manage-detail-action manage-detail-action--primary manage-follow-up-trigger manage-follow-up__save"' + followUpSaveAttrs + ' data-loading-text="Saving…">Update follow-up</button>' +
                    '</div></div>';
            } else {
                html += '<div class="manage-follow-up__actions-primary">' +
                    '<button type="button" class="manage-detail-action manage-detail-action--primary manage-follow-up-trigger manage-follow-up__save"' + followUpSaveAttrs + ' data-loading-text="Saving…">' +
                    (hasPending ? 'Update follow-up' : 'Save follow-up') +
                    '</button></div></form>';
            }
        }

        if (hasPending && followUp.id && onFollowUpsView) {
            html += '<div class="manage-follow-up__actions-secondary">';
            html += '<form method="POST" action="' + escapeHtml(pageConfig.formAction || 'appointment.php') + '" class="manage-detail-action-form manage-detail-action-form--stacked manage-follow-up__secondary-form" data-no-confirm data-ajax="1" data-no-loading>';
            if (authFieldsEl) {
                html += authFieldsEl.innerHTML;
            }
            html += buildFollowUpHiddenFields() +
                '<input type="hidden" name="follow_up_id" value="' + escapeHtml(followUp.id) + '">' +
                '<input type="hidden" name="follow_up_complete" value="1">' +
                '<button type="button" class="manage-detail-action manage-follow-up-trigger" data-loading-text="Saving…">Mark follow-up done</button></form>';
            html += buildFollowUpCancelForm(followUp.id);
            html += '</div>';
        }

        html += '</div>';
        body.innerHTML = html;

        var followUpDateField = body.querySelector('[data-follow-up-date-field]');
        if (followUpDateField) {
            wireFollowUpDateField(followUpDateField);
        }
    }

    function renderDetailActions(actionsWrap, data) {
        if (!actionsWrap) return;

        actionsWrap._appointmentData = data;
        actionsWrap.innerHTML = '';
        var hasActions = Array.isArray(data.actions) && data.actions.length > 0;
        var canDelete = !!data.can_delete && pageConfig.redirectStatus !== 'follow_ups';

        if (!hasActions && !canDelete) {
            actionsWrap.classList.add('hidden');
            return;
        }

        var hint = document.createElement('p');
        hint.className = 'manage-detail-actions__hint';
        if (hasActions && data.status_key === 'scheduled') {
            hint.textContent = 'Review the citizen details above, then confirm or reject the appointment.';
        } else if (hasActions) {
            hint.textContent = 'Update this visit when the citizen has been served or did not arrive.';
        } else if (canDelete) {
            hint.textContent = 'This visit is finished. You can remove it from the active list when records are filed.';
        }
        actionsWrap.appendChild(hint);

        var group = document.createElement('div');
        group.className = 'manage-detail-actions__buttons';

        if (hasActions) {
            data.actions.forEach(function (action) {
                group.appendChild(buildStatusForm(data, action.value, action.label));
            });
        }

        if (canDelete) {
            group.appendChild(buildDeleteForm(data));
        }

        actionsWrap.appendChild(group);
        actionsWrap.classList.remove('hidden');
        if (window.lucide && typeof lucide.createIcons === 'function') {
            lucide.createIcons();
        }
    }

    function populateDetailView(view, data) {
        var prefix = view.prefix;

        setText(view.title, data.citizen_name || 'Appointment');
        setText(view.code, data.appointment_code || '');
        setText(prefix + 'appt-view-name', data.citizen_name);
        setText(prefix + 'appt-view-dob', data.date_of_birth);
        toggleDomRow(prefix, data);
        setText(prefix + 'appt-view-sex', data.sex);
        setText(prefix + 'appt-view-phone', data.phone);
        setText(prefix + 'appt-view-email', data.email);
        setText(prefix + 'appt-view-notify', data.notify_email);
        setText(prefix + 'appt-view-service', data.service_type);
        setText(prefix + 'appt-view-schedule', data.schedule);
        if (data.status_badge_html) {
            setHtml(prefix + 'appt-view-status', data.status_badge_html);
        } else {
            setText(prefix + 'appt-view-status', data.status);
        }
        setText(prefix + 'appt-view-source', data.source);
        setText(view.created, data.created_at ? ('Booked ' + data.created_at) : '');

        var trackingWrap = document.getElementById(prefix + 'appt-view-tracking-wrap');
            if (trackingWrap) {
                if (data.tracking_code) {
                setText(prefix + 'appt-view-tracking', data.tracking_code);
                    trackingWrap.classList.remove('hidden');
                } else {
                    trackingWrap.classList.add('hidden');
                }
            }

        renderIdFiles(document.getElementById(prefix + 'appt-view-id-files'), data);
        renderDetailActions(view.actions, data);

        var notesWrap = document.getElementById(prefix + 'appt-view-notes-wrap');
        var notesEl = document.getElementById(prefix + 'appt-view-notes');
            if (notesWrap && notesEl) {
                if (data.notes) {
                    notesEl.textContent = data.notes;
                    notesWrap.classList.remove('hidden');
                } else {
                    notesWrap.classList.add('hidden');
                }
            }

        var rejectionWrap = document.getElementById(prefix + 'appt-view-rejection-wrap');
        var rejectionEl = document.getElementById(prefix + 'appt-view-rejection');
        if (rejectionWrap && rejectionEl) {
            if (data.rejection_reason) {
                rejectionEl.textContent = data.rejection_reason;
                rejectionWrap.classList.remove('hidden');
            } else {
                rejectionWrap.classList.add('hidden');
            }
        }

        renderFollowUp(prefix, data);
        applyModalDetailLayout(prefix, data);
    }

    function applyModalDetailLayout(prefix, data) {
        if (prefix !== 'modal-') {
            return;
        }

        var body = document.getElementById('modalAppointmentDetailBody');
        var details = document.getElementById('modal-appt-appointment-details');
        var followUp = document.getElementById('modal-appt-view-follow-up-wrap');
        var detailsHeading = document.getElementById('modal-appt-details-heading');
        if (!body || !details || !followUp) {
            return;
        }

        var listStatus = pageConfig.redirectStatus || '';
        var showFollowUp = !followUp.classList.contains('hidden');
        var followUpFirst = showFollowUp && (listStatus === 'follow_ups' || listStatus === 'completed');

        if (detailsHeading) {
            detailsHeading.classList.toggle('hidden', !followUpFirst);
        }
        details.classList.toggle('manage-appointment-details--below-follow-up', followUpFirst);
        followUp.classList.toggle('manage-follow-up--featured', followUpFirst);

        var ordered = followUpFirst
            ? [followUp, detailsHeading, details]
            : [details, detailsHeading, followUp];

        ordered.forEach(function (node) {
            if (node) {
                body.appendChild(node);
            }
        });

        if (followUpFirst && data) {
            window.requestAnimationFrame(function () {
                refreshModalIdPreviews(data);
            });
        }
    }

    function shouldUseSidePanel(data) {
        return useSidePanel && data && data.status_key === 'scheduled';
    }

    function openAppointmentView(data, row, options) {
        options = options || {};
        var openInModal = options.modal === true
            || !shouldUseSidePanel(data)
            || !panel
            || !content
            || !emptyState;

        if (openInModal) {
            openModal(data, row);
            return;
        }

        openDetail(data, row);
    }

    function openDetail(data, row) {
        if (!panel || !content || !emptyState) return;
        populateDetailView(panelView, data);

        emptyState.classList.add('hidden');
        content.classList.remove('hidden');
        bodyWrap.classList.add('has-detail');
        panel.classList.add('is-open');
        markSelectedRow(row || null);

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeDetail() {
        if (!panel || !emptyState || !content) return;
        emptyState.classList.remove('hidden');
        content.classList.add('hidden');
        bodyWrap.classList.remove('has-detail');
        panel.classList.remove('is-open');
        markSelectedRow(null);
        if (panelActionsWrap) {
            panelActionsWrap.innerHTML = '';
            panelActionsWrap.classList.add('hidden');
        }
    }

    function refreshModalIdPreviews(data) {
        if (!data) return;
        var container = document.getElementById('modal-appt-view-id-files');
        if (!container) return;
        renderIdFiles(container, data);
        if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
            lucide.createIcons({ nodes: [container] });
        }
    }

    function openModal(data, row) {
        if (!modal) return;

        closeDetail();
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('manage-request-modal-open');
        populateDetailView(modalView, data);
        markSelectedRow(row || null);

        if (typeof lucide !== 'undefined') lucide.createIcons();
        refreshModalIdPreviews(data);
        window.requestAnimationFrame(function () {
            refreshModalIdPreviews(data);
        });
    }

    function closeModal() {
        if (!modal) return;

            modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('manage-request-modal-open');
        if (modalActionsWrap) {
            modalActionsWrap.innerHTML = '';
            modalActionsWrap.classList.add('hidden');
        }
    }

    function parseAppointmentData(el) {
        if (!el) return null;
        var raw = el.getAttribute('data-appointment');
        if (!raw) return null;
        try {
            return JSON.parse(raw);
        } catch (err) {
            return null;
        }
    }

    /** Table rows only — buttons also carry data-appointment-row and must not be counted twice. */
    function appointmentTableRows() {
        return document.querySelectorAll('tr.manage-requests-row[data-appointment-row]');
    }

    function syncRowAppointmentPayload(row, payload) {
        if (!row || !payload) return;
        var json = JSON.stringify(payload);
        row.setAttribute('data-appointment', json);
        var btn = row.querySelector('.view-appointment-btn');
        if (btn) {
            btn.setAttribute('data-appointment', json);
        }
    }

    function isInteractiveTarget(target) {
        return !!target.closest('form, button, select, option, a, input, textarea, label, .manage-bulk-col');
    }

    function fetchAppointmentFocus(rowId) {
        if (!rowId || !pageConfig.pollUrl) {
            return Promise.resolve(null);
        }

        var params = new URLSearchParams();
        params.set('focus_id', String(rowId));
        if (pageConfig.redirectDate) {
            params.set('date', pageConfig.redirectDate);
        }

        var focusUrl = pageConfig.pollUrl + '?' + params.toString();
        var authToken = sessionStorage.getItem('alcros_auth') || '';
        if (authToken) {
            focusUrl += (focusUrl.indexOf('?') !== -1 ? '&' : '?') + 'alcros_auth=' + encodeURIComponent(authToken);
        }

        return fetch(focusUrl, {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('http_' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                return data && data.focus ? data.focus : null;
            })
            .catch(function () {
                return null;
            });
    }

    function handleViewAppointmentClick(btn, options) {
        if (!btn) return;
        options = options || { modal: true };

        var row = btn.closest('.manage-requests-row');
        var viewData = parseAppointmentData(btn) || parseAppointmentData(row);
        if (viewData) {
            openAppointmentView(viewData, row, options);
            return;
        }

        var rowId = row
            ? parseInt(row.getAttribute('data-appointment-row') || btn.getAttribute('data-appointment-row'), 10)
            : parseInt(btn.getAttribute('data-appointment-row'), 10);
        if (!rowId) {
            if (window.AlcrosActionResult) {
                AlcrosActionResult.show('error', 'Unable to open this appointment. Refresh the page and try again.');
            }
            return;
        }

        dismissBlockingUi();
        if (window.AlcrosLoading) {
            AlcrosLoading.page(true, 'Loading appointment…');
        }

        fetchAppointmentFocus(rowId).then(function (focus) {
            if (window.AlcrosLoading) {
                AlcrosLoading.page(false);
            }
            if (!focus) {
                if (window.AlcrosActionResult) {
                    AlcrosActionResult.show('error', 'Unable to load appointment details. Refresh the page and try again.');
                }
                return;
            }

            if (row) {
                row.setAttribute('data-appointment', JSON.stringify(focus));
            }
            btn.setAttribute('data-appointment', JSON.stringify(focus));
            openAppointmentView(focus, row, options);
        });
    }

    function bindModalClose() {
        if (!modal) return;

        modal.querySelectorAll('[data-close-appointment-modal]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        });
    }

    function bindDatePicker() {
        var picker = document.getElementById('appointmentDatePicker');
        if (!picker) return;

        picker.addEventListener('change', function () {
            if (!picker.value) return;
            var navLabel = document.getElementById('appointmentDateNavLabel');
            if (navLabel && window.AlcrosDateDisplay) {
                navLabel.textContent = AlcrosDateDisplay.formatLongDate(picker.value);
            }
            var href = window.AlcrosPoll
                ? AlcrosPoll.buildUrl('appointment.php', { date: picker.value })
                : 'appointment.php?date=' + encodeURIComponent(picker.value);
            window.location.href = href;
        });
    }

    document.addEventListener('click', function (e) {
        var viewBtn = e.target.closest('.view-appointment-btn');
        if (!viewBtn) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        dismissBlockingUi();
        handleViewAppointmentClick(viewBtn, { modal: true });
    }, true);

    document.addEventListener('click', function (e) {
        if (e.target.closest('.view-appointment-btn')) {
            return;
        }

        if (isInteractiveTarget(e.target)) {
            return;
        }

        var row = e.target.closest('.manage-requests-row');
        if (row) {
            var rowData = parseAppointmentData(row);
            if (rowData) openAppointmentView(rowData, row, { modal: false });
            return;
        }

        if (useSidePanel && e.target === bodyWrap && bodyWrap.classList.contains('has-detail')) {
            closeDetail();
        }
    });

    if (useSidePanel) {
        document.getElementById('appointmentDetailClose')?.addEventListener('click', closeDetail);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (document.body.classList.contains('alcros-confirm-open')) return;

        if (modal && !modal.classList.contains('hidden')) {
            closeModal();
            return;
        }

        if (useSidePanel && bodyWrap.classList.contains('has-detail')) {
            closeDetail();
        }
    });

    bindModalClose();
    bindActionTriggers();
    bindDatePicker();

    if (pageConfig.overviewCardHighlight) {
        updateOverviewCardHighlight(pageConfig.overviewCardHighlight);
    }

    var statFieldMap = {
        all_appointments: 'total',
        scheduled: 'scheduled',
        confirmed: 'confirmed',
        completed: 'completed',
        no_show: 'no_show',
        follow_ups: 'follow_ups'
    };

    function listSignature(appointments) {
        return (appointments || []).map(function (a) {
            return String(a.id) + ':' + String(a.revision);
        }).join('|');
    }

    function updateStatCards(stats) {
        if (!stats) return;
        document.querySelectorAll('[data-stat-key]').forEach(function (card) {
            var key = card.getAttribute('data-stat-key') || '';
            var field = statFieldMap[key];
            if (!field || stats[field] === undefined) return;
            var valueEl = card.querySelector('.manage-stat-card__value');
            if (valueEl) valueEl.textContent = Number(stats[field]).toLocaleString();
        });
    }

    function updateOverviewCardHighlight(highlightKey) {
        if (!highlightKey) return;
        document.querySelectorAll('[data-stat-key]').forEach(function (card) {
            var key = card.getAttribute('data-stat-key') || '';
            card.classList.toggle('is-active', key === highlightKey);
        });
    }

    function refreshOpenDetail(focus) {
        if (!focus || !focus.id) return;

        var selected = document.querySelector('.manage-requests-row.is-selected');
        var selectedId = selected ? parseInt(selected.getAttribute('data-appointment-row'), 10) : 0;
        if (!selectedId || selectedId !== focus.id) return;

        var current = parseAppointmentData(selected);
        if (current && current.revision === focus.revision) return;

        if (useSidePanel && bodyWrap && bodyWrap.classList.contains('has-detail')) {
            populateDetailView(panelView, focus);
        }
        if (modal && !modal.classList.contains('hidden')) {
            populateDetailView(modalView, focus);
        }

        selected.setAttribute('data-appointment', JSON.stringify(focus));
        var viewBtn = selected.querySelector('.view-appointment-btn');
        if (viewBtn) viewBtn.setAttribute('data-appointment', JSON.stringify(focus));
    }

    function applyListUpdate(data) {
        var appointments = data.appointments || [];
        var signature = listSignature(appointments);
        if (data.overview_card_highlight) {
            updateOverviewCardHighlight(data.overview_card_highlight);
        }

        if (signature !== lastListSignature) {
            lastListSignature = signature;
            updateStatCards(data.stats);

            var apiById = {};
            appointments.forEach(function (item) {
                apiById[item.id] = item;
            });

            var refreshTasks = [];

            appointmentTableRows().forEach(function (row) {
                var rowId = parseInt(row.getAttribute('data-appointment-row'), 10);
                var item = apiById[rowId];
                if (!item) {
                    if (row.classList.contains('is-selected')) {
                        if (useSidePanel) closeDetail();
                        closeModal();
                    }
                    row.remove();
                    return;
                }

                var statusCell = row.querySelector('.manage-cell-status');
                if (statusCell && item.status_badge_html) {
                    statusCell.innerHTML = item.status_badge_html;
                }

                var existing = parseAppointmentData(row);
                if (existing && item.revision && item.revision !== existing.revision) {
                    refreshTasks.push(
                        fetchAppointmentFocus(rowId).then(function (focus) {
                            if (!focus) return;
                            syncRowAppointmentPayload(row, focus);
                            if (row.classList.contains('is-selected')) {
                                refreshOpenDetail(focus);
                            }
                        })
                    );
                }
            });

            var domCount = appointmentTableRows().length;
            if (domCount !== appointments.length) {
                Promise.all(refreshTasks).finally(function () {
                    window.location.reload();
                });
                return;
            }

            Promise.all(refreshTasks).then(function () {
                if (data.focus) {
                    refreshOpenDetail(data.focus);
                }
            });
        } else {
            updateStatCards(data.stats);
            if (data.focus) {
                refreshOpenDetail(data.focus);
            }
        }

        if (window.AlcrosAdminLive && typeof window.AlcrosAdminLive.refresh === 'function') {
            window.AlcrosAdminLive.refresh();
        }
    }

    var lastListSignature = listSignature(
        Array.prototype.map.call(
            appointmentTableRows(),
            function (row) {
                var parsed = parseAppointmentData(row);
                return {
                    id: parseInt(row.getAttribute('data-appointment-row'), 10),
                    revision: parsed && parsed.revision ? parsed.revision : ''
                };
            }
        )
    );

    ['appt-view-follow-up-wrap', 'modal-appt-view-follow-up-wrap'].forEach(function (wrapId) {
        var followWrap = document.getElementById(wrapId);
        if (followWrap) {
            wireFollowUpPopovers(followWrap);
        }
    });

    if (window.AlcrosPoll && pageConfig.pollUrl && pageConfig.pollEnabled !== false) {
        AlcrosPoll.pollJson(
            pageConfig.pollUrl,
            function () {
                var params = {
                    date: pageConfig.redirectDate || undefined,
                    status: pageConfig.redirectStatus || 'all',
                    q: pageConfig.redirectQ || undefined
                };
                var selected = document.querySelector('.manage-requests-row.is-selected');
                if (selected) {
                    params.focus_id = selected.getAttribute('data-appointment-row');
                }
                return params;
            },
            (window.AlcrosPollConfig && AlcrosPollConfig.adminListMs) || 15000,
            applyListUpdate
        );
    }
})();
