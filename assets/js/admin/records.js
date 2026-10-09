(function () {
    'use strict';

    var cfg = {};
    var recordsAuthUrl = 'records.php';
    var recordLockTimer = null;
    var activeEditRecordId = null;
    var entryFormSubmitting = false;
    var pendingEditReasonSubmit = null;

    function readPageConfig() {
        if (window.AlcrosPage && typeof AlcrosPage.readConfig === 'function') {
            cfg = AlcrosPage.readConfig('records-config') || {};
        }
        recordsAuthUrl = cfg.recordsAuthUrl || 'records.php';
        activeEditRecordId = cfg.editRecordId || null;
    }

    function csrfInputValue() {
        var el = document.querySelector('#importForm input[name="csrf_token"]')
            || document.querySelector('#entryForm input[name="csrf_token"]')
            || document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function authInputValue() {
        var el = document.querySelector('#entryForm input[name="alcros_auth"]')
            || document.querySelector('input[name="alcros_auth"]');
        if (el && el.value) {
            return el.value;
        }
        try {
            return sessionStorage.getItem('alcros_auth') || '';
        } catch (err) {
            return '';
        }
    }

    function postRecordLock(action, recordId) {
        var apiUrl = cfg.recordsLockApiUrl || 'api/records.php';
        var form = new FormData();
        form.append('action', action);
        form.append('csrf_token', csrfInputValue());
        form.append('record_id', String(recordId));
        var authToken = authInputValue();
        if (authToken) {
            form.append('alcros_auth', authToken);
        }
        return fetch(apiUrl, { method: 'POST', body: form, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, status: res.status, data: data };
                });
            });
    }

    function releaseActiveRecordLock(useBeacon) {
        if (!activeEditRecordId || !cfg.editLockHeld) {
            return Promise.resolve();
        }
        var recordId = activeEditRecordId;
        activeEditRecordId = null;
        cfg.editLockHeld = false;
        clearRecordLockTimer();

        if (useBeacon && navigator.sendBeacon) {
            var apiUrl = cfg.recordsLockApiUrl || 'api/records.php';
            var body = new URLSearchParams();
            body.append('action', 'release_lock');
            body.append('csrf_token', csrfInputValue());
            body.append('record_id', String(recordId));
            var authToken = authInputValue();
            if (authToken) {
                body.append('alcros_auth', authToken);
            }
            navigator.sendBeacon(apiUrl, body);
            return Promise.resolve();
        }

        return postRecordLock('release_lock', recordId).catch(function () {});
    }

    function clearRecordLockTimer() {
        if (recordLockTimer) {
            window.clearInterval(recordLockTimer);
            recordLockTimer = null;
        }
    }

    function handleRecordLockLost(message) {
        var form = document.getElementById('entryForm');
        var fieldset = form ? form.querySelector('.records-entry-fieldset') : null;
        if (fieldset) {
            fieldset.disabled = true;
        }
        var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        if (message) {
            window.alert(message);
        }
    }

    function startRecordLockHeartbeat() {
        clearRecordLockTimer();
        if (!activeEditRecordId || !cfg.editLockHeld) {
            return;
        }

        recordLockTimer = window.setInterval(function () {
            postRecordLock('refresh_lock', activeEditRecordId).then(function (result) {
                if (result.status === 401 || result.status === 419) {
                    if (typeof window.alcrosHandleAuthFailure === 'function') {
                        window.alcrosHandleAuthFailure(result.status);
                    }
                    return;
                }
                if (result.status === 409) {
                    handleRecordLockLost('Another staff member is editing this record. Close this form and try again.');
                    return;
                }
                if (!result.ok || !result.data || result.data.ok === false) {
                    return;
                }
            }).catch(function () {
                // Ignore transient network errors; the lock will expire on its own if the tab is abandoned.
            });
        }, 60000);
    }

    function recordsListUrlWithoutEdit() {
        try {
            var url = new URL(recordsAuthUrl, window.location.href);
            url.searchParams.delete('edit');
            return url.pathname + url.search;
        } catch (err) {
            return recordsAuthUrl.split('?')[0];
        }
    }

    function refreshIcons(root) {
        if (!window.lucide || typeof lucide.createIcons !== 'function') {
            return;
        }
        var scope = root && root.nodeType === 1 ? root : (document.querySelector('.admin-page-wrap') || document.querySelector('.admin-main') || document.body);
        var nodes = scope.querySelectorAll('[data-lucide]');
        if (!nodes.length) {
            return;
        }
        lucide.createIcons({ nameAttr: 'data-lucide', nodes: Array.prototype.slice.call(nodes) });
    }

    function openModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        if (id === 'viewModal') {
            el.setAttribute('aria-hidden', 'false');
            document.body.classList.add('records-view-modal-open');
        } else if (id === 'entryModal') {
            el.classList.add('is-open');
            document.body.classList.add('records-entry-modal-open');
        } else {
            el.classList.add('flex');
            document.body.classList.add('records-entry-modal-open');
        }
        refreshIcons(el);
    }

    function closeAllModals() {
        closeRecordUpdatesModal();
        var entryModal = document.getElementById('entryModal');
        var entryWasOpen = entryModal && !entryModal.classList.contains('hidden');
        document.querySelectorAll('#entryModal, #importModal, #viewModal').forEach(function (el) {
            el.classList.add('hidden');
            el.classList.remove('flex', 'is-open');
            if (el.id === 'viewModal') {
                el.setAttribute('aria-hidden', 'true');
            }
        });
        document.body.classList.remove('records-view-modal-open', 'records-entry-modal-open');
        if (entryWasOpen && activeEditRecordId && cfg.editLockHeld) {
            releaseActiveRecordLock(false).finally(function () {
                if (window.location.search.indexOf('edit=') !== -1) {
                    window.location.replace(recordsListUrlWithoutEdit());
                }
            });
        }
    }

    function formatDate(val) {
        if (val === null || val === undefined || val === '') return '—';
        var raw = String(val).substring(0, 10);
        var d = new Date(raw + 'T00:00:00');
        return Number.isNaN(d.getTime()) ? raw : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function displayValue(val) {
        if (val === null || val === undefined || String(val).trim() === '') return '—';
        return String(val);
    }

    function formatPersonName(r) {
        if (!r) return '—';
        if (r.record_type === 'marriage') {
            var husband = (r.husband_name || '').trim();
            var wife = (r.wife_name || '').trim();
            if (husband && wife) return husband + ' & ' + wife;
        }
        return [
            (r.first_name || '').trim(),
            (r.middle_name || '').trim(),
            (r.last_name || '').trim()
        ].filter(Boolean).join(' ') || (r.person_name || '—');
    }

    function recordRegistryNumber(r) {
        var registry = (r.registry_number ?? '').toString().trim();
        if (registry !== '') return registry;
        var code = (r.code_number ?? '').toString().trim();
        return code !== '' ? code : '';
    }

    function recordEventDate(r) {
        if (r.record_type === 'birth') {
            return r.birth_date || r.event_date;
        }
        return r.event_date || r.birth_date;
    }

    var recordTypeStyles = {
        birth: 'border-blue-500 bg-blue-50 text-blue-700',
        death: 'border-slate-400 bg-slate-50 text-slate-700',
        marriage: 'border-pink-400 bg-pink-50 text-pink-700'
    };
    var recordTypeIdle = 'border-gray-200 text-gray-500 hover:border-gray-300';

    function entryTypeLabel(type) {
        var labels = { birth: 'Birth', death: 'Death', marriage: 'Marriage' };
        return labels[type] || (type ? type.charAt(0).toUpperCase() + type.slice(1) : 'Birth');
    }

    function updateEntryModalTitle(type) {
        if (cfg.editRecordId || cfg.lockRecordType) {
            return;
        }
        var titleEl = document.getElementById('entryModalTitle');
        if (!titleEl || !type) {
            return;
        }
        titleEl.textContent = 'Add ' + entryTypeLabel(type) + ' Record';
    }

    function syncNamePartsAcrossPanels(sourcePrefix, targetPrefix) {
        ['FirstName', 'MiddleName', 'LastName'].forEach(function (part) {
            var source = document.getElementById(sourcePrefix + part);
            var target = document.getElementById(targetPrefix + part);
            if (source && target && !target.value) target.value = source.value;
        });
    }

    /** Server validates section fields; avoid HTML5 required on hidden/inactive panels (blocks submit). */
    function clearEntrySectionHtmlRequired() {
        document.querySelectorAll('#entryForm input, #entryForm select, #entryForm textarea').forEach(function (el) {
            el.required = false;
        });
    }

    function enabledEntryField(form, name) {
        if (!form || !name) return null;
        var els = form.elements[name];
        if (!els) return null;
        if (typeof els.length === 'number' && els.length > 0) {
            for (var i = 0; i < els.length; i++) {
                if (!els[i].disabled) return els[i];
            }
            return null;
        }
        return els.disabled ? null : els;
    }

    function entryFieldIsEmpty(el) {
        if (!el) return true;
        var val = el.value;
        if (val === null || val === undefined) return true;
        return String(val).trim() === '';
    }

    function entryFieldContainer(el) {
        if (!el) return null;
        var printFillWrap = el.closest('.records-entry-print-fill__grid > div');
        if (printFillWrap) {
            return printFillWrap;
        }
        if (el.closest('.grid')) {
            return el.closest('.grid').parentElement;
        }
        return el.parentElement;
    }

    function entryPrintFillField(form, fieldName) {
        return enabledEntryField(form, 'print_fill[' + fieldName + ']');
    }

    function ensureEntryFieldErrorEl(container) {
        if (!container) return null;
        var err = container.querySelector('.records-field-error');
        if (!err) {
            err = document.createElement('p');
            err.className = 'records-field-error hidden';
            err.setAttribute('role', 'alert');
            container.appendChild(err);
        }
        return err;
    }

    function clearEntryFormValidation(form) {
        if (!form) return;
        form.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('.records-field-error').forEach(function (el) {
            el.textContent = '';
            el.classList.add('hidden');
        });
    }

    function setEntryFieldError(el, message) {
        if (!el || !message) return;
        el.classList.add('is-invalid');
        el.setAttribute('aria-invalid', 'true');
        var container = entryFieldContainer(el);
        var err = ensureEntryFieldErrorEl(container);
        if (err) {
            err.textContent = message;
            err.classList.remove('hidden');
        }
    }

    function entryFieldErrorMessage(el, label) {
        label = label || 'This field';
        if (el.tagName === 'SELECT') {
            return 'Select ' + label.toLowerCase() + '.';
        }
        return 'Enter ' + label.toLowerCase() + '.';
    }

    function scrollEntryFormToFirstError(form) {
        var invalid = form.querySelector('.is-invalid');
        if (!invalid) return;
        if (typeof invalid.scrollIntoView === 'function') {
            invalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        if (typeof invalid.focus === 'function') {
            invalid.focus();
        }
    }

    function validateEntryFormBeforeConfirm(form) {
        readPageConfig();
        var V = window.AlcrosCivilRecordEntryValidation;
        if (!V) {
            return false;
        }
        var typeInput = form.querySelector('#recordTypeInput');
        var type = typeInput ? String(typeInput.value || '').trim() : '';
        return V.validateManualEntrySections(type, cfg.manualEntryRequiredFields || {}, {
            form: form,
            root: form,
            resolveInput: function (fieldName) {
                return entryPrintFillField(form, fieldName);
            }
        });
    }

    window.__alcrosValidateEntryForm = validateEntryFormBeforeConfirm;

    function syncSingleBirthDetails() {
        var panel = document.getElementById('singleBirthDetails');
        var select = document.getElementById('birthTypeSelect');
        var birthPanel = document.getElementById('birthFieldsPanel');
        if (!panel || !select || !birthPanel) return;
        var isSingle = select.value === 'Single';
        var birthActive = !birthPanel.classList.contains('hidden');
        panel.classList.toggle('hidden', !isSingle);
        panel.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.disabled = !isSingle || !birthActive;
        });
    }

    function setRecordType(type) {
        var recordTypeInput = document.getElementById('recordTypeInput');
        if (!recordTypeInput || !type) return;
        recordTypeInput.value = type;
        document.querySelectorAll('.record-type-tab').forEach(function (tab) {
            var active = tab.dataset.recordType === type;
            tab.className = 'record-type-tab rounded-xl border-2 px-3 py-3 text-center transition ' + (active ? (recordTypeStyles[type] || recordTypeIdle) : recordTypeIdle);
        });

        var panels = {
            birth: document.getElementById('birthFieldsPanel'),
            death: document.getElementById('deathFieldsPanel'),
            marriage: document.getElementById('marriageFieldsPanel')
        };
        var printFillPanels = {
            birth: document.getElementById('birthPrintFillPanel'),
            death: document.getElementById('deathPrintFillPanel'),
            marriage: document.getElementById('marriagePrintFillPanel')
        };

        Object.keys(panels).forEach(function (key) {
            var panel = panels[key];
            if (!panel) return;
            var active = key === type;
            panel.classList.toggle('hidden', !active);
            panel.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = !active;
            });
        });

        Object.keys(printFillPanels).forEach(function (key) {
            var panel = printFillPanels[key];
            if (!panel) return;
            var active = key === type;
            panel.classList.toggle('hidden', !active);
            panel.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = !active;
            });
        });

        clearEntrySectionHtmlRequired();
        if (type === 'birth') syncSingleBirthDetails();
        updateEntryModalTitle(type);
        var entryModal = document.getElementById('entryModal');
        if (entryModal) {
            refreshIcons(entryModal);
        }
    }

    function openSingleEntryModal(type) {
        type = type || cfg.defaultEntryType || 'birth';
        openModal('entryModal');
        setRecordType(type);
    }

    function openImportModal(type) {
        if (!type) return;
        var importType = document.getElementById('importType');
        var importModalTitle = document.getElementById('importModalTitle');
        var importTemplateLink = document.getElementById('importTemplateLink');
        var importColumnsHelp = document.getElementById('importColumnsHelp');
        if (!importType || !importModalTitle || !importTemplateLink || !importColumnsHelp) return;

        importType.value = type;
        importModalTitle.textContent = 'Import ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Records';
        importTemplateLink.href = recordsAuthUrl + (recordsAuthUrl.indexOf('?') !== -1 ? '&' : '?') + 'action=template&format=xlsx&type=' + encodeURIComponent(type) + '&v=6';
        importTemplateLink.download = 'alcros_' + type + '_import_template.xlsx';
        var requiredHint = type === 'marriage'
            ? 'husband and wife first and last names'
            : (type === 'death'
                ? 'deceased first and last name'
                : 'child first and last name');
        importColumnsHelp.textContent =
            'Download the Excel template below, enter one record per row, then upload the file. Required: ' + requiredHint + '. The sample row is skipped automatically.';
        var fileInput = document.querySelector('#importForm input[name="csv_file"]');
        if (fileInput) fileInput.value = '';
        openModal('importModal');
    }

    function bindRecordsExportMenu() {
        var menu = document.getElementById('recordsExportMenu');
        var btn = document.getElementById('recordsExportBtn');
        var panel = document.getElementById('recordsExportPanel');
        if (!menu || !btn || !panel) return;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.classList.toggle('hidden');
        });

        document.addEventListener('click', function (e) {
            if (!menu.contains(e.target)) {
                panel.classList.add('hidden');
            }
        });
    }

    function bindNewEntryMenu() {
        var newEntryBtn = document.getElementById('newEntryBtn');
        var newEntryMenu = document.getElementById('newEntryMenu');
        var newEntryWrapper = document.getElementById('newEntryWrapper');
        if (!newEntryBtn || !newEntryMenu || !newEntryWrapper) return;

        newEntryBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            newEntryMenu.classList.toggle('hidden');
        });

        newEntryWrapper.addEventListener('click', function (e) {
            var importBtn = e.target.closest('[data-import-type]');
            if (importBtn) {
                e.preventDefault();
                e.stopPropagation();
                newEntryMenu.classList.add('hidden');
                openImportModal(importBtn.getAttribute('data-import-type'));
                return;
            }
            if (e.target.closest('[data-single-entry-type]')) {
                e.preventDefault();
                e.stopPropagation();
                newEntryMenu.classList.add('hidden');
                openSingleEntryModal(e.target.closest('[data-single-entry-type]').getAttribute('data-single-entry-type'));
            }
        });

        document.addEventListener('click', function (e) {
            if (!newEntryWrapper.contains(e.target)) {
                newEntryMenu.classList.add('hidden');
            }
        });
    }

    function isLcroFooterField(fieldName) {
        return /^lcro_box_/.test(String(fieldName || ''));
    }

    function normalizeLcroFooterText(value) {
        return String(value || '').replace(/\s+/g, '');
    }

    function formatLcroFooterDisplayText(value) {
        var compact = normalizeLcroFooterText(value);
        if (!compact) {
            return '';
        }
        return compact.split('').join('  ');
    }

    function formatEntryPrintFillInput(input) {
        var name = input.getAttribute('data-field-name') || '';
        if (!isLcroFooterField(name)) {
            return;
        }
        var stored = normalizeLcroFooterText(input.value);
        var display = formatLcroFooterDisplayText(stored);
        if (input.value !== display) {
            input.value = display;
        }
    }

    function bindEntryPrintFillTabs() {
        document.querySelectorAll('.records-entry-print-fill').forEach(function (section) {
            section.querySelectorAll('[data-entry-fill-tab]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var side = btn.getAttribute('data-entry-fill-tab');
                    section.querySelectorAll('[data-entry-fill-tab]').forEach(function (tab) {
                        var active = tab.getAttribute('data-entry-fill-tab') === side;
                        tab.classList.toggle('is-active', active);
                        tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    section.querySelectorAll('[data-entry-fill-panel]').forEach(function (panel) {
                        var isActive = panel.getAttribute('data-entry-fill-panel') === side;
                        panel.hidden = !isActive;
                        panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                    });
                    var backFillActive = side === 'back';
                    section.querySelectorAll('[data-fill-group]').forEach(function (el) {
                        if (el.closest('[data-entry-fill-panel="back"]')) {
                            el.hidden = !backFillActive;
                        }
                    });
                });
            });

            section.querySelectorAll('[data-field-name]').forEach(function (input) {
                formatEntryPrintFillInput(input);
                function onFillFieldEdit() {
                    formatEntryPrintFillInput(input);
                }
                input.addEventListener('input', onFillFieldEdit);
                input.addEventListener('change', onFillFieldEdit);
            });
        });
    }

    function bindRecordTypeTabs() {
        if (cfg.lockRecordType || cfg.editRecordId) {
            return;
        }

        document.querySelectorAll('.record-type-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var nextType = tab.dataset.recordType;
                var recordTypeInput = document.getElementById('recordTypeInput');
                var previousType = recordTypeInput ? recordTypeInput.value : '';
                if (previousType === 'birth' && nextType === 'death') {
                    syncNamePartsAcrossPanels('birth', 'death');
                } else if (previousType === 'death' && nextType === 'birth') {
                    syncNamePartsAcrossPanels('death', 'birth');
                }
                setRecordType(nextType);
            });
        });

        var birthTypeSelect = document.getElementById('birthTypeSelect');
        if (birthTypeSelect) birthTypeSelect.addEventListener('change', syncSingleBirthDetails);
    }

    function syncEditReasonModalDetailRequired() {
        var category = document.getElementById('editReasonModalCategory');
        var detailOptional = document.getElementById('editReasonModalDetailOptional');
        if (!category || !detailOptional) {
            return;
        }
        var isOther = category.value === 'other';
        detailOptional.textContent = isOther ? '(required for Other)' : '(optional)';
    }

    function openRecordEditReasonModal(form, submitter) {
        var modal = document.getElementById('recordEditReasonModal');
        if (!modal) {
            return;
        }
        pendingEditReasonSubmit = { form: form, submitter: submitter || null };
        var categoryHidden = form.querySelector('#editReasonCategory');
        var detailHidden = form.querySelector('#editReasonDetail');
        var categoryModal = document.getElementById('editReasonModalCategory');
        var detailModal = document.getElementById('editReasonModalDetail');
        if (categoryModal && categoryHidden) {
            categoryModal.value = categoryHidden.value || '';
        }
        if (detailModal && detailHidden) {
            detailModal.value = detailHidden.value || '';
        }
        syncEditReasonModalDetailRequired();
        if (window.AlcrosCivilRecordEntryValidation && typeof AlcrosCivilRecordEntryValidation.clearValidation === 'function') {
            AlcrosCivilRecordEntryValidation.clearValidation(modal);
        }
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        refreshIcons(modal);
        if (categoryModal && typeof categoryModal.focus === 'function') {
            categoryModal.focus();
        }
    }

    function closeRecordEditReasonModal() {
        var modal = document.getElementById('recordEditReasonModal');
        pendingEditReasonSubmit = null;
        if (!modal) {
            return;
        }
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    }

    function validateEditReasonModalFields() {
        var modal = document.getElementById('recordEditReasonModal');
        var category = document.getElementById('editReasonModalCategory');
        var detail = document.getElementById('editReasonModalDetail');
        var V = window.AlcrosCivilRecordEntryValidation;
        if (V && modal) {
            V.clearValidation(modal);
        }

        var invalid = false;
        if (!category || !category.value) {
            invalid = true;
            if (V && category) {
                V.setFieldError(category, 'Select a reason for this update.');
            }
        }
        if (category && category.value === 'other' && detail && !String(detail.value || '').trim()) {
            invalid = true;
            if (V) {
                V.setFieldError(detail, 'Describe the reason for this update.');
            }
        }
        if (invalid && V && modal) {
            V.scrollToFirstError(modal);
        }
        return !invalid;
    }

    function confirmRecordEditReason() {
        if (!pendingEditReasonSubmit) {
            return;
        }
        if (!validateEditReasonModalFields()) {
            return;
        }

        var form = pendingEditReasonSubmit.form;
        var submitter = pendingEditReasonSubmit.submitter;
        var category = document.getElementById('editReasonModalCategory');
        var detail = document.getElementById('editReasonModalDetail');
        var categoryHidden = form.querySelector('#editReasonCategory');
        var detailHidden = form.querySelector('#editReasonDetail');
        if (categoryHidden && category) {
            categoryHidden.value = category.value;
        }
        if (detailHidden && detail) {
            detailHidden.value = detail.value;
        }
        closeRecordEditReasonModal();

        function proceedEntryUpdateSubmit() {
            form.dataset.alcrosEditReasonConfirmed = '1';
            if (window.AlcrosConfirm && typeof window.AlcrosConfirm.markConfirmed === 'function') {
                window.AlcrosConfirm.markConfirmed(form);
            }
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

        var confirmMessage = 'Save changes to this record?';
        if (window.AlcrosConfirm && typeof window.AlcrosConfirm.ask === 'function') {
            window.AlcrosConfirm.ask(confirmMessage).then(function (ok) {
                if (ok) {
                    proceedEntryUpdateSubmit();
                }
            });
            return;
        }

        proceedEntryUpdateSubmit();
    }

    function bindRecordEditReasonModal() {
        var modal = document.getElementById('recordEditReasonModal');
        if (!modal) {
            return;
        }

        modal.querySelectorAll('[data-edit-reason-close]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                closeRecordEditReasonModal();
            });
        });

        var confirmBtn = document.getElementById('recordEditReasonConfirmBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function (e) {
                e.preventDefault();
                confirmRecordEditReason();
            });
        }

        var category = document.getElementById('editReasonModalCategory');
        if (category) {
            category.addEventListener('change', syncEditReasonModalDetailRequired);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            if (modal.classList.contains('hidden')) {
                return;
            }
            e.stopPropagation();
            closeRecordEditReasonModal();
        });
    }

    function bindEntryForm() {
        var entryForm = document.getElementById('entryForm');
        if (!entryForm) {
            return;
        }

        if (window.AlcrosCivilRecordEntryValidation) {
            AlcrosCivilRecordEntryValidation.bindLiveClear(entryForm);
        }

        entryForm.addEventListener('submit', function (e) {
            readPageConfig();
            var actionEl = entryForm.querySelector('#entryAction');
            var isUpdate = actionEl && actionEl.value === 'update';
            var categoryHidden = entryForm.querySelector('#editReasonCategory');

            if (isUpdate && categoryHidden) {
                if (entryForm.dataset.alcrosEditReasonConfirmed === '1') {
                    delete entryForm.dataset.alcrosEditReasonConfirmed;
                } else {
                    if (validateEntryFormBeforeConfirm(entryForm)) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return;
                    }
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    openRecordEditReasonModal(entryForm, e.submitter);
                    return;
                }
            }

            clearEntrySectionHtmlRequired();
            entryFormSubmitting = true;
            clearRecordLockTimer();
            var type = document.getElementById('recordTypeInput');
            if (type && type.value) {
                setRecordType(type.value);
            }
        }, true);
    }

    function stripCsvBom(text) {
        if (!text) return '';
        if (text.charCodeAt(0) === 0xFEFF) {
            return text.slice(1);
        }
        return text;
    }

    function parseCsvText(text) {
        var rows = [];
        var row = [];
        var field = '';
        var inQuotes = false;

        for (var i = 0; i < text.length; i++) {
            var ch = text.charAt(i);
            if (inQuotes) {
                if (ch === '"') {
                    if (text.charAt(i + 1) === '"') {
                        field += '"';
                        i++;
                    } else {
                        inQuotes = false;
                    }
                } else {
                    field += ch;
                }
                continue;
            }

            if (ch === '"') {
                inQuotes = true;
            } else if (ch === ',') {
                row.push(field);
                field = '';
            } else if (ch === '\r') {
                continue;
            } else if (ch === '\n') {
                row.push(field);
                rows.push(row);
                row = [];
                field = '';
            } else {
                field += ch;
            }
        }

        if (field !== '' || row.length > 0) {
            row.push(field);
            rows.push(row);
        }

        return rows;
    }

    function csvRowLooksLikeHeader(row) {
        var joined = row.join(' ').toLowerCase();
        return /record_type|first_name|child_first|deceased_first|husband_first|registry_number|registry_no/.test(joined);
    }

    function setImportProgress(percent, message) {
        var wrap = document.getElementById('importProgressWrap');
        var text = document.getElementById('importProgressText');
        var bar = document.getElementById('importProgressBar');
        if (wrap) wrap.classList.remove('hidden');
        if (text) text.textContent = message;
        if (bar) bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
    }

    function resetImportProgress() {
        var wrap = document.getElementById('importProgressWrap');
        var bar = document.getElementById('importProgressBar');
        if (wrap) wrap.classList.add('hidden');
        if (bar) bar.style.width = '0%';
    }

    function postImportBatch(payload) {
        var apiUrl = cfg.recordsImportApiUrl || 'api/records_import.php';
        return fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfInputValue(),
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.text().then(function (body) {
                var data = null;
                try {
                    data = body ? JSON.parse(body) : null;
                } catch (parseErr) {
                    data = null;
                }
                if (!res.ok || !data || data.ok === false) {
                    var err = (data && (data.error || data.message))
                        || (body && body.length < 280 ? body.trim() : '')
                        || ('Import batch failed (HTTP ' + res.status + ').');
                    throw new Error(err);
                }
                return data;
            });
        });
    }

    function runChunkedCsvImport(importForm) {
        var importTypeEl = document.getElementById('importType');
        var fileInput = importForm.querySelector('input[name="csv_file"]');
        var submitBtn = document.getElementById('importSubmitBtn');
        var importType = importTypeEl ? importTypeEl.value : '';
        var totals = {
            imported: 0,
            skipped: 0,
            sample_skipped: 0,
            errors: []
        };

        if (!importType) {
            alert('Please choose an import type from New Entry → Import.');
            return Promise.resolve();
        }
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            alert('Please choose a CSV file to import.');
            return Promise.resolve();
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Importing…';
        }

        setImportProgress(0, 'Reading CSV file…');

        function showImportOutcome(extraNote) {
            var msg = 'Successfully imported ' + totals.imported.toLocaleString() + ' record(s).';
            if (totals.sample_skipped > 0) {
                msg += ' Skipped ' + totals.sample_skipped.toLocaleString() + ' template sample row(s).';
            }
            if (totals.skipped > 0) {
                msg += ' Skipped ' + totals.skipped.toLocaleString() + ' invalid row(s).';
            }
            if (totals.errors.length) {
                msg += ' ' + totals.errors.slice(0, 2).join(' ');
            }
            if (extraNote) {
                msg += ' ' + extraNote;
            }

            var outcomeType = totals.imported > 0 ? 'success' : 'error';
            if (window.AlcrosActionResult && typeof AlcrosActionResult.show === 'function') {
                AlcrosActionResult.show(outcomeType, msg);
            } else {
                alert(msg);
            }

            if (totals.imported > 0) {
                window.setTimeout(function () {
                    window.location.reload();
                }, 900);
            }
        }

        return fileInput.files[0].text().then(function (rawText) {
            var parsedRows = parseCsvText(stripCsvBom(rawText));
            var headers = [];
            var dataRows = parsedRows;
            var startLine = 1;

            if (parsedRows.length > 0 && csvRowLooksLikeHeader(parsedRows[0])) {
                headers = parsedRows[0].map(function (cell) {
                    return String(cell || '').trim();
                });
                dataRows = parsedRows.slice(1);
                startLine = 2;
            }

            if (!dataRows.length) {
                throw new Error('The CSV file has no data rows to import.');
            }

            var batchSize = 250;
            var lineOffset = startLine;
            var batchIndex = 0;
            var totalBatches = Math.ceil(dataRows.length / batchSize);

            function sendNextBatch() {
                if (batchIndex >= totalBatches) {
                    return Promise.resolve();
                }

                var slice = dataRows.slice(batchIndex * batchSize, (batchIndex + 1) * batchSize);
                var percent = Math.round((batchIndex / totalBatches) * 100);
                setImportProgress(
                    percent,
                    'Importing batch ' + (batchIndex + 1) + ' of ' + totalBatches +
                    ' (' + totals.imported.toLocaleString() + ' saved so far)…'
                );

                var isLastBatch = batchIndex === totalBatches - 1;
                var payload = {
                    import_type: importType,
                    headers: batchIndex === 0 ? headers : headers,
                    rows: slice,
                    start_line: lineOffset,
                    finalize: isLastBatch,
                    imported_total: totals.imported
                };

                return postImportBatch(payload).then(function (result) {
                    totals.imported += Number(result.imported || 0);
                    totals.skipped += Number(result.skipped || 0);
                    totals.sample_skipped += Number(result.sample_skipped || 0);
                    if (Array.isArray(result.errors)) {
                        totals.errors = totals.errors.concat(result.errors);
                    }
                    lineOffset += slice.length;
                    batchIndex++;
                    return sendNextBatch();
                });
            }

            return sendNextBatch().then(function () {
                setImportProgress(100, 'Import complete.');
                showImportOutcome();
            });
        }).catch(function (err) {
            var message = err && err.message ? err.message : 'Import failed. Please try again.';
            if (totals.imported > 0) {
                showImportOutcome('However, the import could not finish cleanly: ' + message);
                return;
            }
            if (window.AlcrosActionResult && typeof AlcrosActionResult.show === 'function') {
                AlcrosActionResult.show('error', message);
            } else {
                alert(message);
            }
        }).finally(function () {
            resetImportProgress();
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Import Records';
            }
        });
    }

    function bindImportForm() {
        var importForm = document.getElementById('importForm');
        if (!importForm) return;

        importForm.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            if (importForm.dataset.alcrosImportGo === '1') {
                delete importForm.dataset.alcrosImportGo;
                runChunkedCsvImport(importForm);
                return;
            }

            var confirmPromise = window.AlcrosConfirm && typeof window.AlcrosConfirm.ask === 'function'
                ? window.AlcrosConfirm.ask('Import records from this CSV file?')
                : Promise.resolve(window.confirm('Import records from this CSV file?'));

            confirmPromise.then(function (ok) {
                if (!ok) return;
                importForm.dataset.alcrosImportGo = '1';
                if (typeof importForm.requestSubmit === 'function') {
                    importForm.requestSubmit();
                } else {
                    importForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                }
            });
        }, true);
    }

    var viewRecordIdForHistory = null;

    function recordHistoryEmptyMarkup() {
        return '<p class="records-recent-updates__empty">No edits yet for this record. Field changes will appear here after someone saves an update.</p>';
    }

    function fetchRecordUpdateHistoryHtml(recordId) {
        var url = new URL(recordsAuthUrl, window.location.href);
        url.searchParams.set('action', 'record_update_history');
        url.searchParams.set('id', String(recordId));
        return fetch(url.pathname + url.search, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('History request failed.');
                }
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    throw new Error((data && data.error) || 'Could not load history.');
                }
                return typeof data.history_html === 'string' ? data.history_html : recordHistoryEmptyMarkup();
            });
    }

    function loadRecordUpdatesHistoryPanel(recordId) {
        var historyPanel = document.getElementById('recordUpdatesHistoryPanel');
        if (!historyPanel) {
            return;
        }
        if (!recordId) {
            historyPanel.innerHTML = recordHistoryEmptyMarkup();
            return;
        }

        historyPanel.innerHTML = '<p class="records-detail-loading">Loading recent updates…</p>';
        fetchRecordUpdateHistoryHtml(recordId)
            .then(function (html) {
                historyPanel.innerHTML = html || recordHistoryEmptyMarkup();
            })
            .catch(function () {
                historyPanel.innerHTML = '<p class="records-recent-updates__empty">Could not load update history. Close and try again.</p>';
            });
    }

    function openRecordUpdatesModal(fromView) {
        var modal = document.getElementById('recordUpdatesModal');
        if (!modal) return;
        var editNotice = document.getElementById('recordUpdatesEditNotice');
        if (editNotice) {
            if (fromView) {
                editNotice.classList.add('hidden');
                editNotice.setAttribute('hidden', '');
            } else {
                editNotice.classList.remove('hidden');
                editNotice.removeAttribute('hidden');
            }
        }
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        refreshIcons(modal);

        var recordId = fromView ? viewRecordIdForHistory : (cfg.editRecordId || null);
        loadRecordUpdatesHistoryPanel(recordId);
    }

    function closeRecordUpdatesModal() {
        var modal = document.getElementById('recordUpdatesModal');
        if (!modal) return;
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    }

    function bindRecordUpdatesModal() {
        var openBtn = document.getElementById('recordUpdatesInfoBtn');
        var viewOpenBtn = document.getElementById('viewRecordUpdatesInfoBtn');
        var modal = document.getElementById('recordUpdatesModal');
        if (!modal) return;

        if (openBtn) {
            openBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openRecordUpdatesModal(false);
            });
        }

        if (viewOpenBtn) {
            viewOpenBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openRecordUpdatesModal(true);
            });
        }

        modal.querySelectorAll('[data-record-updates-close]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeRecordUpdatesModal();
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (modal.classList.contains('hidden')) return;
            e.stopPropagation();
            closeRecordUpdatesModal();
        });
    }

    function bindModalClose() {
        document.querySelectorAll('.close-modal').forEach(function (btn) {
            btn.addEventListener('click', closeAllModals);
        });
        ['entryModal', 'importModal', 'viewModal'].forEach(function (id) {
            var modal = document.getElementById(id);
            if (!modal) return;
            modal.addEventListener('click', function (e) {
                if (e.target === e.currentTarget) closeAllModals();
            });
        });
    }

    function formatAgeAtDeath(r) {
        var units = [
            ['age_death_years', 'y'],
            ['age_death_months', 'm'],
            ['age_death_days', 'd'],
            ['age_death_hours', 'h'],
            ['age_death_minutes', 'min']
        ];
        var parts = units
            .filter(function (entry) {
                var key = entry[0];
                return r[key] !== null && r[key] !== '' && r[key] !== undefined;
            })
            .map(function (entry) {
                return r[entry[0]] + entry[1];
            });
        var text = parts.length ? parts.join(' ') : '—';
        if (r.stillbirth == 1) text += (text === '—' ? '' : ' ') + '(Still-birth)';
        return text;
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function detailRow(label, value, full) {
        var text = value === null || value === undefined ? '—' : String(value);
        var empty = text.trim() === '' || text === '—';
        if (empty) {
            text = '—';
        }
        var rowClass = 'records-detail-row' + (full ? ' records-detail-row--full' : '');
        var valueClass = empty ? ' records-detail-value--empty' : '';
        return '<div class="' + rowClass + '"><dt>' + escapeHtml(label) + '</dt><dd class="' + valueClass.trim() + '">' + escapeHtml(text) + '</dd></div>';
    }

    function detailSection(title, rows, fullLabels) {
        var fullSet = fullLabels || [];
        var rowHtml = rows.map(function (entry) {
            var full = fullSet.indexOf(entry[0]) !== -1;
            return detailRow(entry[0], entry[1], full);
        }).join('');
        return '<section class="records-detail-block"><h3>' + escapeHtml(title) + '</h3><dl class="records-detail-rows">' + rowHtml + '</dl></section>';
    }

    function recordTypeLabel(type) {
        var labels = { birth: 'Birth', death: 'Death', marriage: 'Marriage' };
        return labels[type] || displayValue(type);
    }

    function formatFieldValue(r, fieldDef) {
        var key = fieldDef.key;
        var format = fieldDef.format;

        if (key === '_person_name') {
            return displayValue(formatPersonName(r));
        }
        if (key === '_age_at_death') {
            return formatAgeAtDeath(r);
        }
        if (key === '_death_time') {
            var time = displayValue(r.death_time);
            if (time === '—') {
                return '—';
            }
            return time + (r.death_time_period ? ' ' + r.death_time_period : '');
        }
        if (format === 'date') {
            if (key === 'created_at') {
                return formatDate(String(r.created_at || '').substring(0, 10));
            }
            return formatDate(r[key]);
        }
        if (format === 'bool') {
            return r[key] == 1 ? 'Yes' : 'No';
        }

        return displayValue(r[key]);
    }

    function buildPrintFillSection(r, printValues) {
        var type = r.record_type || '';
        var labels = (cfg.printFillFieldLabels && cfg.printFillFieldLabels[type]) || {};
        var rows = [];

        Object.keys(labels).forEach(function (key) {
            var value = printValues && printValues[key] !== undefined ? printValues[key] : '';
            if (String(value || '').trim() === '') {
                return;
            }
            rows.push([labels[key], value]);
        });

        if (!rows.length) {
            return '';
        }

        return detailSection(
            'Print Certificate Fields',
            rows,
            rows.map(function (entry) { return entry[0]; })
        );
    }

    function buildViewRecordPresentation(r, printValues) {
        var sections = [];
        var type = r.record_type || '';
        var registry = displayValue(recordRegistryNumber(r));
        var eventDate = formatDate(recordEventDate(r));
        var created = formatDate(String(r.created_at || '').substring(0, 10));
        var title = displayValue(formatPersonName(r));
        var subtitleParts = [];
        var viewSections = (cfg.recordViewSections && cfg.recordViewSections[type]) || [];

        if (registry !== '—') {
            subtitleParts.push('Registry #' + registry);
        }
        if (eventDate !== '—') {
            subtitleParts.push(type === 'birth' ? 'Born ' + eventDate : type === 'death' ? 'Died ' + eventDate : 'Married ' + eventDate);
        }

        viewSections.forEach(function (section) {
            var rows = section.fields.map(function (field) {
                return [field.label, formatFieldValue(r, field), !!field.full];
            });
            var fullLabels = rows.filter(function (entry) { return entry[2]; }).map(function (entry) { return entry[0]; });
            sections.push(detailSection(
                section.title,
                rows.map(function (entry) { return [entry[0], entry[1]]; }),
                fullLabels
            ));
        });

        var printSection = buildPrintFillSection(r, printValues || {});
        if (printSection) {
            sections.push(printSection);
        }

        if (!viewSections.length) {
            sections.push(detailSection('Record', [
                ['Person Name', displayValue(formatPersonName(r))],
                ['Birth Date', formatDate(r.birth_date)],
                ['Event Date', formatDate(recordEventDate(r))],
                ['Place', displayValue(r.place)]
            ], ['Place']));
        }

        sections.push(detailSection('LCRO Reference', [
            ['Registry Number', registry],
            ['Book Number', displayValue(r.book_number)],
            ['Page Number', displayValue(r.page_number)],
        ]));

        sections.push(detailSection('System', [
            ['Record Type', recordTypeLabel(type)],
            ['Created', created]
        ]));

        return {
            title: title,
            subtitle: subtitleParts.join(' · ') || 'Civil registry record',
            badgeLabel: recordTypeLabel(type),
            badgeClass: 'records-type-badge records-type-badge--' + (type || 'birth'),
            html: sections.join('')
        };
    }

    function renderViewRecordPresentation(r, printValues) {
        var viewContent = document.getElementById('viewContent');
        if (viewContent && window.AlcrosLoading && typeof window.AlcrosLoading.clearSkeletonHost === 'function') {
            window.AlcrosLoading.clearSkeletonHost(viewContent);
        }
        var viewEditLink = document.getElementById('viewEditLink');
        var viewPrintCertificateLink = document.getElementById('viewPrintCertificateLink');
        var viewPrintCertificationLink = document.getElementById('viewPrintCertificationLink');
        var viewModalTitle = document.getElementById('viewModalTitle');
        var viewModalSubtitle = document.getElementById('viewModalSubtitle');
        var viewModalBadge = document.getElementById('viewModalBadge');
        if (!viewContent || !viewEditLink || !r || !r.id) {
            return;
        }

        viewRecordIdForHistory = r.id;

        var presentation = buildViewRecordPresentation(r, printValues);
        viewContent.innerHTML = presentation.html;
        if (viewModalTitle) {
            viewModalTitle.textContent = presentation.title;
        }
        if (viewModalSubtitle) {
            viewModalSubtitle.textContent = presentation.subtitle;
        }
        if (viewModalBadge) {
            viewModalBadge.textContent = presentation.badgeLabel;
            viewModalBadge.className = presentation.badgeClass;
        }

        var lock = r.edit_lock || null;
        var lockActive = lock && lock.active && !lock.held_by_you;
        var existingNote = viewContent ? viewContent.querySelector('.records-view-lock-note') : null;
        if (existingNote) {
            existingNote.remove();
        }
        if (lockActive && viewContent) {
            var note = document.createElement('p');
            note.className = 'records-view-lock-note';
            note.textContent = (lock.staff_name || 'Another staff member') + ' is currently editing this record.';
            viewContent.insertBefore(note, viewContent.firstChild);
        }

        viewEditLink.href = recordsAuthUrl + (recordsAuthUrl.indexOf('?') !== -1 ? '&' : '?') + 'edit=' + r.id;
        viewEditLink.classList.toggle('is-disabled', !!lockActive);
        viewEditLink.setAttribute('aria-disabled', lockActive ? 'true' : 'false');
        viewEditLink.onclick = lockActive
            ? function (event) {
                event.preventDefault();
            }
            : null;
        if (viewPrintCertificateLink) {
            var printBase = cfg.printCertificateUrl || 'print_certificate.php';
            viewPrintCertificateLink.href = printBase + (printBase.indexOf('?') !== -1 ? '&' : '?') + 'record_id=' + r.id;
        }
        if (viewPrintCertificationLink) {
            var certBase = cfg.printCertificationUrl || 'print_certificate.php?kind=certification';
            viewPrintCertificationLink.href = certBase + (certBase.indexOf('?') !== -1 ? '&' : '?') + 'record_id=' + r.id;
        }
    }

    function resetPrintDropdownPosition(panel) {
        if (!panel) return;
        panel.classList.remove('manage-print-dropdown--floating');
        panel.style.top = '';
        panel.style.left = '';
        panel.style.right = '';
        panel.style.bottom = '';
    }

    function positionPrintDropdown(trigger, panel) {
        if (!trigger || !panel) return;

        var rect = trigger.getBoundingClientRect();
        var gap = 6;
        var margin = 8;
        var panelWidth = panel.offsetWidth || 152;
        var panelHeight = panel.offsetHeight || 88;
        var left = rect.right - panelWidth;
        var top = rect.bottom + gap;

        if (left < margin) {
            left = margin;
        }
        if (left + panelWidth > window.innerWidth - margin) {
            left = Math.max(margin, window.innerWidth - panelWidth - margin);
        }
        if (top + panelHeight > window.innerHeight - margin) {
            top = rect.top - panelHeight - gap;
        }
        if (top < margin) {
            top = margin;
        }

        panel.style.left = Math.round(left) + 'px';
        panel.style.top = Math.round(top) + 'px';
    }

    function closePrintMenus() {
        document.querySelectorAll('.manage-print-menu.is-open').forEach(function (menu) {
            menu.classList.remove('is-open');
        });
        document.querySelectorAll('.manage-print-dropdown').forEach(function (el) {
            el.classList.add('hidden');
            resetPrintDropdownPosition(el);
        });
        document.querySelectorAll('.manage-print-trigger').forEach(function (btn) {
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    function bindPrintMenu(menu) {
        if (!menu || menu.dataset.printMenuBound === '1') {
            return;
        }

        var trigger = menu.querySelector('.manage-print-trigger');
        var panel = menu.querySelector('.manage-print-dropdown');
        if (!trigger || !panel) {
            return;
        }

        menu.dataset.printMenuBound = '1';
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !panel.classList.contains('hidden');
            closePrintMenus();
            if (!open) {
                panel.classList.remove('hidden');
                panel.classList.add('manage-print-dropdown--floating');
                trigger.setAttribute('aria-expanded', 'true');
                menu.classList.add('is-open');
                window.requestAnimationFrame(function () {
                    positionPrintDropdown(trigger, panel);
                });
            }
        });

        panel.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    }

    var recordsPrintMenuGlobalsBound = false;

    function bindRecordsPrintMenus() {
        document.querySelectorAll('.manage-print-menu').forEach(bindPrintMenu);
        if (recordsPrintMenuGlobalsBound) {
            return;
        }
        recordsPrintMenuGlobalsBound = true;
        document.addEventListener('click', closePrintMenus);
        window.addEventListener('resize', closePrintMenus);
        window.addEventListener('scroll', closePrintMenus, true);
    }

    function showEditNavigationLoading() {
        if (window.AlcrosLoading && typeof window.AlcrosLoading.page === 'function') {
            window.AlcrosLoading.page(true, 'Opening editor…');
        }
    }

    function bindEditRecordLinks() {
        if (document.documentElement.dataset.alcrosRecordsEditNavBound === '1') {
            return;
        }
        document.documentElement.dataset.alcrosRecordsEditNavBound = '1';
        document.addEventListener('click', function (e) {
            var link = e.target.closest('a.manage-row-action[href*="edit="], a#viewEditLink[href*="edit="]');
            if (!link || link.getAttribute('aria-disabled') === 'true' || link.classList.contains('is-disabled')) {
                return;
            }
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }
            var href = link.getAttribute('href');
            if (!href || href === '#') {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            showEditNavigationLoading();
            window.location.assign(href);
        }, true);
    }

    function openViewRecordFromButton(btn) {
        if (!btn) {
            return;
        }
        var r;
        try {
            r = JSON.parse(btn.getAttribute('data-record') || '{}');
        } catch (err) {
            return;
        }
        if (!r.id) {
            return;
        }

        viewRecordIdForHistory = r.id || null;

        var viewContent = document.getElementById('viewContent');
        if (viewContent) {
            if (window.AlcrosLoading && typeof window.AlcrosLoading.skeletonInto === 'function') {
                window.AlcrosLoading.skeletonInto(viewContent, 'detail', 8);
            } else {
                viewContent.innerHTML = '<p class="records-detail-loading">Loading record details…</p>';
            }
        }
        openModal('viewModal');

        var viewUrl = new URL(recordsAuthUrl, window.location.href);
        viewUrl.searchParams.set('action', 'view_record');
        viewUrl.searchParams.set('id', String(r.id));

        fetch(viewUrl.pathname + viewUrl.search, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Record request failed.');
                }
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.ok || !data.record) {
                    throw new Error((data && data.error) || 'Could not load record.');
                }
                if (data.edit_lock) {
                    data.record.edit_lock = data.edit_lock;
                }
                renderViewRecordPresentation(data.record, data.print_values || {});
            })
            .catch(function () {
                renderViewRecordPresentation(r, {});
            });
    }

    function bindRecordsViewDelegation() {
        if (document.documentElement.dataset.alcrosRecordsViewDelegation === '1') {
            return;
        }
        document.documentElement.dataset.alcrosRecordsViewDelegation = '1';
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('#recordsListPanel .view-record-btn');
            if (!btn) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            openViewRecordFromButton(btn);
        });
    }

    var recordsListAbort = null;
    var recordsListRequestId = 0;
    var recordsSearchDebounceTimer = null;
    var recordsListAppliedQuery = '';
    var RECORDS_SEARCH_DEBOUNCE_MS = 450;
    var RECORDS_SEARCH_MIN_CHARS = 2;

    function recordsSearchLooksLikeDate(value) {
        value = String(value || '').trim();
        if (!value) {
            return false;
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return true;
        }
        if (/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}$/.test(value)) {
            return true;
        }
        return /^\d{4}$/.test(value);
    }

    function recordsFiltersFromNavUrl(urlString) {
        var url = new URL(urlString, window.location.href);
        return {
            type: url.searchParams.get('type') || 'all',
            q: url.searchParams.get('q') || '',
            sort: url.searchParams.get('sort') || 'name',
            dir: url.searchParams.get('dir') || 'asc',
            page: Math.max(1, parseInt(url.searchParams.get('page') || '1', 10) || 1)
        };
    }

    function readRecordsFiltersFromForm(pageOverride) {
        var form = document.getElementById('recordsToolbarForm');
        if (!form) {
            return null;
        }
        var qInput = form.querySelector('input[name="q"]');
        var typeInput = form.querySelector('input[name="type"]');
        var sortInput = form.querySelector('input[name="sort"]');
        var dirInput = form.querySelector('input[name="dir"]');
        return {
            type: typeInput && typeInput.value ? typeInput.value : 'all',
            q: qInput ? qInput.value.trim() : '',
            sort: sortInput && sortInput.value ? sortInput.value : 'name',
            dir: dirInput && dirInput.value ? dirInput.value : 'asc',
            page: pageOverride || 1
        };
    }

    function syncRecordsToolbarHiddenFields(filters) {
        var wrap = document.getElementById('recordsToolbarHiddenFields');
        if (!wrap || !filters) {
            return;
        }
        wrap.innerHTML = '';
        if (filters.type && filters.type !== 'all') {
            var typeEl = document.createElement('input');
            typeEl.type = 'hidden';
            typeEl.name = 'type';
            typeEl.value = filters.type;
            wrap.appendChild(typeEl);
        }
        if (filters.sort && filters.sort !== 'name') {
            var sortEl = document.createElement('input');
            sortEl.type = 'hidden';
            sortEl.name = 'sort';
            sortEl.value = filters.sort;
            wrap.appendChild(sortEl);
        }
        if (filters.dir && filters.dir !== 'asc') {
            var dirEl = document.createElement('input');
            dirEl.type = 'hidden';
            dirEl.name = 'dir';
            dirEl.value = filters.dir;
            wrap.appendChild(dirEl);
        }
    }

    function updateRecordsTypeFilterChips(activeType) {
        document.querySelectorAll('#recordsTypeFilters [data-records-type]').forEach(function (chip) {
            var key = chip.getAttribute('data-records-type');
            var active = key === activeType;
            chip.classList.toggle('bg-white', active);
            chip.classList.toggle('shadow-sm', active);
            chip.classList.toggle('text-blue-600', active);
            chip.classList.toggle('text-gray-400', !active);
            chip.classList.toggle('hover:text-gray-600', !active);
        });
    }

    function buildRecordsListRequestUrl(filters) {
        var url = new URL(recordsAuthUrl, window.location.href);
        url.searchParams.set('action', 'list_fragment');
        url.searchParams.set('type', filters.type || 'all');
        if (filters.q) {
            url.searchParams.set('q', filters.q);
        } else {
            url.searchParams.delete('q');
        }
        if (filters.sort && filters.sort !== 'name') {
            url.searchParams.set('sort', filters.sort);
        } else {
            url.searchParams.delete('sort');
        }
        if (filters.dir && filters.dir !== 'asc') {
            url.searchParams.set('dir', filters.dir);
        } else {
            url.searchParams.delete('dir');
        }
        if (filters.page && filters.page > 1) {
            url.searchParams.set('page', String(filters.page));
        } else {
            url.searchParams.delete('page');
        }
        return url;
    }

    function setRecordsListLoading(on) {
        var panel = document.getElementById('recordsListPanel');
        if (!panel) {
            return;
        }
        panel.classList.toggle('is-loading', !!on);
        panel.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    function updateRecordsSearchStatus(totalRecords, query) {
        var status = document.getElementById('recordsSearchStatus');
        if (!status) {
            return;
        }
        if (query) {
            status.textContent = totalRecords + ' record' + (totalRecords === 1 ? '' : 's') + ' found for “' + query + '”.';
        } else {
            status.textContent = totalRecords + ' record' + (totalRecords === 1 ? '' : 's') + ' shown.';
        }
    }

    function applyRecordsListPayload(data, options) {
        options = options || {};
        if (!data || !data.ok) {
            return;
        }
        var panel = document.getElementById('recordsListPanel');
        if (panel && data.html) {
            panel.outerHTML = data.html;
        }
        if (data.filters) {
            syncRecordsToolbarHiddenFields(data.filters);
            updateRecordsTypeFilterChips(data.filters.type || 'all');
            recordsListAppliedQuery = (data.filters.q || '').trim();
            var qInput = document.querySelector('#recordsToolbarForm input[name="q"]');
            if (qInput && qInput.value.trim() !== recordsListAppliedQuery) {
                qInput.value = recordsListAppliedQuery;
            }
        }
        updateRecordsSearchStatus(data.totalRecords || 0, data.filters ? data.filters.q : '');
        bindRecordsPrintMenus();
        if (!cfg.editRecordId && options.pushState !== false && data.url) {
            try {
                window.history.pushState({ alcrosRecordsList: true }, '', data.url);
            } catch (err) {
                /* ignore */
            }
        }
    }

    function fetchRecordsList(filters, options) {
        options = options || {};
        var requestId = ++recordsListRequestId;
        if (recordsListAbort) {
            recordsListAbort.abort();
        }
        recordsListAbort = new AbortController();
        setRecordsListLoading(true);

        var url = buildRecordsListRequestUrl(filters);
        return fetch(url.pathname + url.search, {
            credentials: 'same-origin',
            signal: recordsListAbort.signal,
            headers: { Accept: 'application/json' }
        })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Records search failed.');
                }
                return res.json();
            })
            .then(function (data) {
                if (requestId !== recordsListRequestId) {
                    return;
                }
                applyRecordsListPayload(data, options);
                return data;
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
                var status = document.getElementById('recordsSearchStatus');
                if (status) {
                    status.textContent = 'Could not update the list. Try again.';
                }
            })
            .finally(function () {
                if (requestId === recordsListRequestId) {
                    setRecordsListLoading(false);
                }
            });
    }

    function recordsSearchShouldRun(query) {
        if (query === recordsListAppliedQuery) {
            return false;
        }
        if (query !== '' && query.length < RECORDS_SEARCH_MIN_CHARS && !recordsSearchLooksLikeDate(query)) {
            return false;
        }
        return true;
    }

    function scheduleRecordsSearchFromInput(immediate) {
        var form = document.getElementById('recordsToolbarForm');
        var input = form ? form.querySelector('input[name="q"]') : null;
        if (!input) {
            return;
        }
        if (recordsSearchDebounceTimer) {
            clearTimeout(recordsSearchDebounceTimer);
            recordsSearchDebounceTimer = null;
        }
        var run = function () {
            var query = input.value.trim();
            if (query !== '' && query.length < RECORDS_SEARCH_MIN_CHARS && !recordsSearchLooksLikeDate(query)) {
                if (recordsListAppliedQuery !== '') {
                    var resetFilters = readRecordsFiltersFromForm(1);
                    if (resetFilters) {
                        resetFilters.q = '';
                        resetFilters.page = 1;
                        fetchRecordsList(resetFilters, { pushState: !cfg.editRecordId });
                    }
                }
                return;
            }
            if (!recordsSearchShouldRun(query)) {
                return;
            }
            var filters = readRecordsFiltersFromForm(1);
            if (!filters) {
                return;
            }
            filters.q = query;
            filters.page = 1;
            fetchRecordsList(filters, { pushState: !cfg.editRecordId });
        };
        if (immediate) {
            run();
        } else {
            recordsSearchDebounceTimer = setTimeout(run, RECORDS_SEARCH_DEBOUNCE_MS);
        }
    }

    function bindRecordsLiveSearch() {
        var form = document.getElementById('recordsToolbarForm');
        if (!form || form.dataset.recordsLiveSearchBound === '1') {
            return;
        }
        form.dataset.recordsLiveSearchBound = '1';

        var input = form.querySelector('input[name="q"]');
        if (input) {
            recordsListAppliedQuery = (input.value || '').trim();
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    scheduleRecordsSearchFromInput(true);
                }
            });
            input.addEventListener('input', function () {
                scheduleRecordsSearchFromInput(false);
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            scheduleRecordsSearchFromInput(true);
        });

        document.addEventListener('click', function (e) {
            var link = e.target.closest('a.records-list-nav');
            if (!link || !link.getAttribute('href')) {
                return;
            }
            if (!link.closest('#recordsToolbarForm') && !link.closest('#recordsListPanel')) {
                return;
            }
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var filters = recordsFiltersFromNavUrl(link.href);
            var qInput = form.querySelector('input[name="q"]');
            if (qInput && link.closest('#recordsTypeFilters')) {
                filters.q = qInput.value.trim();
            }
            syncRecordsToolbarHiddenFields(filters);
            if (qInput) {
                qInput.value = filters.q;
            }
            recordsListAppliedQuery = filters.q;
            fetchRecordsList(filters, { pushState: !cfg.editRecordId });
        });

        window.addEventListener('popstate', function () {
            if (!document.getElementById('recordsToolbarForm')) {
                return;
            }
            var filters = recordsFiltersFromNavUrl(window.location.href);
            var qInput = form.querySelector('input[name="q"]');
            if (qInput) {
                qInput.value = filters.q;
            }
            syncRecordsToolbarHiddenFields(filters);
            updateRecordsTypeFilterChips(filters.type);
            recordsListAppliedQuery = filters.q;
            fetchRecordsList(filters, { pushState: false });
        });
    }

    function initRecordsPage() {
        readPageConfig();
        var entryModalEl = document.getElementById('entryModal');
        var entryAlreadyOpen = entryModalEl && entryModalEl.classList.contains('is-open');
        if (entryAlreadyOpen) {
            refreshIcons(entryModalEl);
        } else {
            refreshIcons();
        }
        bindRecordsExportMenu();
        bindNewEntryMenu();
        bindRecordTypeTabs();
        bindEntryForm();
        bindRecordEditReasonModal();
        bindEntryPrintFillTabs();
        bindImportForm();
        bindModalClose();
        bindRecordUpdatesModal();
        bindRecordsViewDelegation();
        bindRecordsLiveSearch();
        bindEditRecordLinks();
        bindRecordsPrintMenus();

        var recordTypeInput = document.getElementById('recordTypeInput');
        if (recordTypeInput) {
            setRecordType(recordTypeInput.value || 'birth');
        }

        if (cfg.openEntryModal && !entryAlreadyOpen) {
            openSingleEntryModal(cfg.defaultEntryType || 'birth');
        } else if (entryAlreadyOpen) {
            document.body.classList.add('records-entry-modal-open');
            refreshIcons(entryModalEl);
        }

        if (window.AlcrosLoading && typeof window.AlcrosLoading.page === 'function') {
            window.AlcrosLoading.page(false);
        }

        if (cfg.editRecordId && cfg.editLockHeld) {
            startRecordLockHeartbeat();
        }

        if (window.AlcrosCascadingLocation && cfg.locationsApiUrl) {
            window.AlcrosCascadingLocation.init({ apiUrl: cfg.locationsApiUrl });
        }

        // Do not release edit locks on beforeunload — that raced with Update Record and
        // falsely blocked the same staff member as "another staff" on submit.
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRecordsPage);
    } else {
        initRecordsPage();
    }
})();
