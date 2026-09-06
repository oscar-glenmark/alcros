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
    var useSidePanel = pageConfig.useSidePanel !== false;

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
                return;
            }
            var html = idLink('Front ID', data.id_front_path) + idLink('Back ID', data.id_back_path);
            container.innerHTML = html || '<span class="text-xs text-gray-400 italic">No ID files uploaded.</span>';
        }

        function idLink(label, path) {
            if (!path) return '';
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

    function inlineConfirmYesLabel(action) {
        if (action === 'confirmed') return 'Yes, confirm';
        if (action === 'completed') return 'Yes, complete';
        if (action === 'cancelled') return 'Yes, reject';
        if (action === 'no_show') return 'Yes, mark no-show';
        return 'Yes, continue';
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
                        window.AlcrosActionResult.show('success', data.message || 'Saved successfully.');
                    }
                    window.setTimeout(function () {
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
        actionsWrap.innerHTML =
            '<div class="manage-inline-confirm">' +
                '<p class="manage-inline-confirm__msg">' + escapeHtml(actionConfirmMessage(action, data)) + '</p>' +
                '<div class="manage-detail-actions__buttons">' +
                    '<button type="button" class="manage-detail-action manage-detail-action--primary" data-inline-confirm-yes>' + escapeHtml(inlineConfirmYesLabel(action)) + '</button>' +
                    '<button type="button" class="manage-detail-action" data-inline-confirm-back>Go back</button>' +
                '</div>' +
            '</div>';
        actionsWrap.classList.remove('hidden');

        actionsWrap.querySelector('[data-inline-confirm-yes]').addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var loadingMessage = 'Saving appointment…';
            submitAppointmentForm(form, loadingMessage);
        });

        actionsWrap.querySelector('[data-inline-confirm-back]').addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            renderDetailActions(actionsWrap, data);
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    }

    function bindActionTriggers() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.manage-action-trigger');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            var form = btn.closest('form.manage-detail-action-form');
            var actionsWrap = btn.closest('#modalAppointmentDetailActions, #appointmentDetailActions');
            if (!form || !actionsWrap || !actionsWrap._appointmentData) return;

            showInlineConfirm(actionsWrap, actionsWrap._appointmentData, form, btn.getAttribute('data-manage-action') || '');
        });
    }

    function renderDetailActions(actionsWrap, data) {
        if (!actionsWrap) return;

        actionsWrap._appointmentData = data;
        actionsWrap.innerHTML = '';
        var hasActions = Array.isArray(data.actions) && data.actions.length > 0;

        if (!hasActions) {
            actionsWrap.classList.add('hidden');
            return;
        }

        var hint = document.createElement('p');
        hint.className = 'manage-detail-actions__hint';
        if (hasActions && data.status_key === 'scheduled') {
            hint.textContent = 'Review the citizen details above, then confirm or reject the appointment.';
        } else if (hasActions) {
            hint.textContent = 'Update this visit when the citizen has been served or did not arrive.';
        }
        actionsWrap.appendChild(hint);

        var group = document.createElement('div');
        group.className = 'manage-detail-actions__buttons';

        data.actions.forEach(function (action) {
            group.appendChild(buildStatusForm(data, action.value, action.label));
        });

        actionsWrap.appendChild(group);
        actionsWrap.classList.remove('hidden');
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
    }

    function shouldUseSidePanel(data) {
        return useSidePanel && data && data.status_key === 'scheduled';
    }

    function openAppointmentView(data, row) {
        if (shouldUseSidePanel(data) && panel && content && emptyState) {
            openDetail(data, row);
            return;
        }
        openModal(data, row);
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

    function openModal(data, row) {
        if (!modal) return;

        populateDetailView(modalView, data);
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('manage-request-modal-open');
        markSelectedRow(row || null);

        if (typeof lucide !== 'undefined') lucide.createIcons();
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

    function isInteractiveTarget(target) {
        return !!target.closest('form, button, select, option, a, input, textarea, label, .manage-bulk-col');
    }

    function bindViewButtons() {
        document.querySelectorAll('.view-appointment-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var viewData = parseAppointmentData(btn) || parseAppointmentData(btn.closest('.manage-requests-row'));
                if (viewData) openModal(viewData, btn.closest('.manage-requests-row'));
            });
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
            var params = new URLSearchParams(window.location.search);
            params.set('date', picker.value);
            window.location.href = 'appointment.php?' + params.toString();
        });
    }

    document.addEventListener('click', function (e) {
        if (isInteractiveTarget(e.target)) {
            return;
        }

        var row = e.target.closest('.manage-requests-row');
        if (row) {
            var rowData = parseAppointmentData(row);
            if (rowData) openAppointmentView(rowData, row);
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

    bindViewButtons();
    bindModalClose();
    bindActionTriggers();
    bindDatePicker();

    var firstRow = document.querySelector('.manage-requests-row');
    if (useSidePanel && !pageConfig.bulkActions && firstRow && window.matchMedia('(min-width: 1280px)').matches) {
        var firstData = parseAppointmentData(firstRow);
        if (firstData && firstData.status_key === 'scheduled') {
            openDetail(firstData, firstRow);
        }
    }
})();
