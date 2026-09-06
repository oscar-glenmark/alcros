(function () {
    'use strict';

    var panel = document.getElementById('requestDetailPanel');
    var emptyState = document.getElementById('requestDetailEmpty');
    var content = document.getElementById('requestDetailContent');
    var bodyWrap = document.getElementById('manageRequestsBody');
    var panelActionsWrap = document.getElementById('requestDetailActions');
    var modal = document.getElementById('requestReviewModal');
    var modalActionsWrap = document.getElementById('modalRequestDetailActions');
    var authFieldsEl = document.getElementById('requestActionAuthFields');
    var pageConfig = window.AlcrosPage && typeof window.AlcrosPage.readConfig === 'function'
        ? window.AlcrosPage.readConfig('page-config')
        : {};
    var useSidePanel = pageConfig.useSidePanel !== false;

    if (!panel || !emptyState || !content || !bodyWrap) {
        if (!bodyWrap) return;
    }

    var panelView = {
        prefix: '',
        title: 'requestViewTitle',
        code: 'requestViewCode',
        submitted: 'view-submitted',
        actions: panelActionsWrap
    };

    var modalView = {
        prefix: 'modal-',
        title: 'modalRequestViewTitle',
        code: 'modalRequestViewCode',
        submitted: 'modal-view-submitted',
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
        var wrap = document.getElementById(prefix + 'view-dom-wrap');
        if (!wrap) return;
        var isMarriage = data.document_type_key === 'marriage';
        var hasDom = data.date_of_marriage && data.date_of_marriage !== '—';
        if (isMarriage || hasDom) {
            setText(prefix + 'view-dom', data.date_of_marriage);
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
        if (action === 'verified' || action === 'completed') {
            return 'manage-detail-action manage-detail-action--primary';
        }
        if (action === 'rejected') {
            return 'manage-detail-action manage-detail-action--danger';
        }
        return 'manage-detail-action';
    }

    function actionConfirmMessage(action, data) {
        var code = data.tracking_code || 'this request';
        if (action === 'verified') {
            return 'Accept request ' + code + '? The citizen will be notified that their document is ready for pickup.';
        }
        if (action === 'rejected') {
            return 'Reject ' + code + '? The citizen will be notified that the request was declined.';
        }
        if (action === 'completed') {
            return 'Mark ' + code + ' as completed? Use this when the citizen has claimed the document.';
        }
        return 'Update ' + code + '?';
    }

    function buildStatusForm(data, action, label) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = pageConfig.formAction || 'manage_request.php';
        form.className = 'manage-detail-action-form';
        form.dataset.noConfirm = '';
        form.dataset.ajax = '1';
        form.dataset.noLoading = '';

        if (authFieldsEl) {
            form.innerHTML = authFieldsEl.innerHTML;
        }

        form.insertAdjacentHTML('beforeend',
            '<input type="hidden" name="redirect_status" value="' + escapeHtml(pageConfig.redirectStatus || 'all') + '">' +
            '<input type="hidden" name="redirect_q" value="' + escapeHtml(pageConfig.redirectQ || '') + '">' +
            '<input type="hidden" name="request_id" value="' + escapeHtml(data.id) + '">' +
            '<input type="hidden" name="update_status" value="1">' +
            '<input type="hidden" name="status" value="' + escapeHtml(action) + '">' +
            '<button type="button" class="' + actionButtonClass(action) + ' manage-action-trigger" data-manage-action="' + escapeHtml(action) + '" data-loading-text="Saving…">' + escapeHtml(label) + '</button>'
        );

        return form;
    }

    function buildDeleteForm(data) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = pageConfig.formAction || 'manage_request.php';
        form.className = 'manage-detail-action-form';
        form.dataset.noConfirm = '';
        form.dataset.ajax = '1';
        form.dataset.noLoading = '';

        if (authFieldsEl) {
            form.innerHTML = authFieldsEl.innerHTML;
        }

        form.insertAdjacentHTML('beforeend',
            '<input type="hidden" name="redirect_status" value="' + escapeHtml(pageConfig.redirectStatus || 'all') + '">' +
            '<input type="hidden" name="redirect_q" value="' + escapeHtml(pageConfig.redirectQ || '') + '">' +
            '<input type="hidden" name="request_id" value="' + escapeHtml(data.id) + '">' +
            '<input type="hidden" name="delete_request" value="1">' +
            '<button type="button" class="manage-detail-action manage-detail-action--danger manage-detail-action--icon manage-action-trigger" data-manage-action="delete" data-loading-text="Deleting…" title="Delete completed request" aria-label="Delete completed request">' +
                '<i data-lucide="trash-2" class="w-4 h-4"></i>' +
            '</button>'
        );

        return form;
    }

    function inlineConfirmYesLabel(action) {
        if (action === 'verified') return 'Yes, accept';
        if (action === 'rejected') return 'Yes, reject';
        if (action === 'completed') return 'Yes, complete';
        if (action === 'delete') return 'Yes, delete';
        return 'Yes, continue';
    }

    function submitManageForm(form, loadingMessage) {
        if (!form) return;

        if (window.AlcrosLoading && typeof window.AlcrosLoading.page === 'function') {
            window.AlcrosLoading.page(true, loadingMessage || 'Saving…');
        }

        var formData = new FormData(form);
        formData.set('ajax', '1');

        fetch(form.action || pageConfig.formAction || 'manage_request.php', {
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
        if (window.AlcrosConfirm && typeof window.AlcrosConfirm.dismissBlockingLayers === 'function') {
            window.AlcrosConfirm.dismissBlockingLayers();
        }

        var msg = manageActionConfirmMessage(data, action);

        actionsWrap.innerHTML =
            '<div class="manage-inline-confirm">' +
                '<p class="manage-inline-confirm__msg">' + escapeHtml(msg) + '</p>' +
                '<div class="manage-detail-actions__buttons">' +
                    '<button type="button" class="manage-detail-action manage-detail-action--primary" data-inline-confirm-yes>' + escapeHtml(inlineConfirmYesLabel(action)) + '</button>' +
                    '<button type="button" class="manage-detail-action" data-inline-confirm-back>Go back</button>' +
                '</div>' +
            '</div>';
        actionsWrap.classList.remove('hidden');

        actionsWrap.querySelector('[data-inline-confirm-yes]').addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var loadingMessage = action === 'delete' ? 'Deleting…' : 'Saving request…';
            submitManageForm(form, loadingMessage);
        });

        actionsWrap.querySelector('[data-inline-confirm-back]').addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            renderDetailActions(actionsWrap, data);
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    }

    function manageActionConfirmMessage(data, action) {
        if (action === 'delete') {
            return 'Delete this completed request? This cannot be undone.';
        }
        return actionConfirmMessage(action, data);
    }

    function bindManageActionTriggers() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.manage-action-trigger');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            var form = btn.closest('form.manage-detail-action-form');
            var actionsWrap = btn.closest('#modalRequestDetailActions, #requestDetailActions');
            if (!form || !actionsWrap || !actionsWrap._requestData) return;

            var action = btn.getAttribute('data-manage-action') || '';
            var data = actionsWrap._requestData;
            var msg = manageActionConfirmMessage(data, action);
            var loadingMessage = action === 'delete' ? 'Deleting…' : 'Saving request…';

            if (window.AlcrosConfirm && typeof window.AlcrosConfirm.ask === 'function') {
                window.AlcrosConfirm.ask(msg).then(function (ok) {
                    if (!ok) return;
                    submitManageForm(form, loadingMessage);
                });
                return;
            }

            showInlineConfirm(actionsWrap, data, form, action);
        });
    }

    function renderDetailActions(actionsWrap, data) {
        if (!actionsWrap) return;

        actionsWrap._requestData = data;
        actionsWrap.innerHTML = '';
        var hasActions = Array.isArray(data.actions) && data.actions.length > 0;
        var canDelete = !!data.can_delete;
        var canPrint = !!data.can_print;

        if (!hasActions && !canDelete && !canPrint) {
            actionsWrap.classList.add('hidden');
            return;
        }

        var hint = document.createElement('p');
        hint.className = 'manage-detail-actions__hint';
        if (canPrint) {
            hint.textContent = 'Print the certificate, then mark completed when the citizen claims the document.';
        } else if (hasActions && data.status_key === 'pending') {
            hint.textContent = 'Review the citizen details and IDs above, then accept or reject.';
        } else if (hasActions) {
            hint.textContent = 'Update this request when the citizen has claimed the document.';
        } else {
            hint.textContent = 'This request is finished.';
        }
        actionsWrap.appendChild(hint);

        var group = document.createElement('div');
        group.className = 'manage-detail-actions__buttons';

        if (canPrint && data.print_url) {
            var printLink = document.createElement('a');
            printLink.href = data.print_url;
            printLink.className = 'manage-detail-action manage-detail-action--primary';
            printLink.innerHTML = '<i data-lucide="printer"></i> Print Certificate';
            group.appendChild(printLink);
        }

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

        setText(view.title, data.citizen_name || 'Request');
        setText(view.code, data.tracking_code || '');
        setText(prefix + 'view-dob', data.date_of_birth);
        toggleDomRow(prefix, data);
        setText(prefix + 'view-sex', data.sex);
        setText(prefix + 'view-phone', data.phone);
        setText(prefix + 'view-email', data.email);
        setText(prefix + 'view-email-verified', data.email_verified);
        setText(prefix + 'view-document-type', data.document_type);
        setText(prefix + 'view-purpose', data.purpose);
        setText(prefix + 'view-appointment', data.appointment);
        if (data.status_badge_html) {
            setHtml(prefix + 'view-status', data.status_badge_html);
        } else {
            setText(prefix + 'view-status', data.status);
        }
        setText(prefix + 'view-privacy', data.privacy_agreed);
        setText(view.submitted, data.submitted_at ? ('Submitted ' + data.submitted_at) : '');
        setText(prefix + 'view-updated', data.updated_at);

        renderIdFiles(document.getElementById(prefix + 'view-id-files'), data);
        renderDetailActions(view.actions, data);

        var notesWrap = document.getElementById(prefix + 'view-notes-wrap');
        var notesEl = document.getElementById(prefix + 'view-notes');
        if (notesWrap && notesEl) {
            if (data.notes) {
                notesEl.textContent = data.notes;
                notesWrap.classList.remove('hidden');
            } else {
                notesWrap.classList.add('hidden');
            }
        }
    }

    function openRequestView(data, row) {
        if (useSidePanel && panel && content && emptyState) {
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

    function parseRequestData(el) {
        if (!el) return null;
        var raw = el.getAttribute('data-request');
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
        document.querySelectorAll('.view-request-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var viewData = parseRequestData(btn) || parseRequestData(btn.closest('.manage-requests-row'));
                if (viewData) openModal(viewData, btn.closest('.manage-requests-row'));
            });
        });
    }

    function bindModalClose() {
        if (!modal) return;

        modal.querySelectorAll('[data-close-request-modal]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        });
    }

    document.addEventListener('click', function (e) {
        if (isInteractiveTarget(e.target)) {
            return;
        }

        var row = e.target.closest('.manage-requests-row');
        if (row) {
            var rowData = parseRequestData(row);
            if (rowData) openRequestView(rowData, row);
            return;
        }

        if (useSidePanel && e.target === bodyWrap && bodyWrap.classList.contains('has-detail')) {
            closeDetail();
        }
    });

    if (useSidePanel) {
        document.getElementById('requestDetailClose')?.addEventListener('click', closeDetail);
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
    bindManageActionTriggers();

    var firstRow = document.querySelector('.manage-requests-row');
    if (useSidePanel && !pageConfig.bulkActions && firstRow && window.matchMedia('(min-width: 1280px)').matches) {
        var firstData = parseRequestData(firstRow);
        if (firstData) openDetail(firstData, firstRow);
    }
})();
