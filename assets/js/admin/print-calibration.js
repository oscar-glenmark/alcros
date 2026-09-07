(function () {
    'use strict';

    var cfg = {};
    var csrfToken = '';
    var activeFieldId = null;
    var dragState = null;
    var zoomLevel = 1;
    var showSampleText = false;
    var showAllBoxes = true;
    var placedFieldIds = new Set();
    var dirtyFieldIds = new Set();
    var saveInFlight = false;
    var labelSaveTimer = null;
    var labelSaveInFlight = false;

    function placedStorageKey() {
        return 'alcros-print-cal-placed-' + (cfg.templateId || '0');
    }

    function loadPlacedFields() {
        placedFieldIds = new Set();
        try {
            var raw = sessionStorage.getItem(placedStorageKey());
            if (!raw) return;
            JSON.parse(raw).forEach(function (id) {
                placedFieldIds.add(parseInt(id, 10));
            });
        } catch (err) {
            placedFieldIds = new Set();
        }
    }

    function persistPlacedFields() {
        try {
            sessionStorage.setItem(placedStorageKey(), JSON.stringify(Array.from(placedFieldIds)));
        } catch (err) {
            /* ignore quota errors */
        }
    }

    function markFieldPlaced(fieldId) {
        if (!fieldId) return;
        placedFieldIds.add(fieldId);
        persistPlacedFields();
    }

    function isMarkerVisible(fieldId) {
        fieldId = Number(fieldId);
        return showAllBoxes || placedFieldIds.has(fieldId) || fieldId === activeFieldId;
    }

    function updateAllMarkerVisibility() {
        (cfg.fields || []).forEach(function (field) {
            var fieldId = Number(field.id);
            var marker = markerEl(fieldId);
            if (!marker) return;
            var placed = placedFieldIds.has(fieldId);
            marker.classList.toggle('is-box-hidden', !isMarkerVisible(fieldId));
            marker.classList.toggle('is-selected', fieldId === activeFieldId);
            marker.classList.toggle('is-placed', placed && fieldId !== activeFieldId);
            var btn = listBtnEl(fieldId);
            if (btn) btn.classList.toggle('is-placed', placed);
        });
    }

    function setEditorVisible(visible) {
        var empty = document.getElementById('calFieldEmpty');
        var controls = document.getElementById('calFieldControls');
        if (empty) empty.hidden = !!visible;
        if (controls) controls.hidden = !visible;
    }

    function readConfig() {
        if (window.AlcrosPage && typeof AlcrosPage.readConfig === 'function') {
            cfg = AlcrosPage.readConfig('page-config') || {};
        }
        cfg.fields = Array.isArray(cfg.fields) ? cfg.fields : [];
        cfg.sampleValues = cfg.sampleValues || {};
        cfg.fieldHints = cfg.fieldHints || {};
        cfg.templateCalibration = cfg.templateCalibration || { x_offset_mm: 0, y_offset_mm: 0, scale_x: 1, scale_y: 1 };
        cfg.globalCalibration = cfg.globalCalibration || { x_offset_mm: 0, y_offset_mm: 0, scale_x: 1, scale_y: 1 };
        if (cfg.csrfToken) {
            csrfToken = cfg.csrfToken;
        }
        if (!csrfToken) {
            var csrfEl = document.querySelector('main input[name="csrf_token"]') || document.querySelector('input[name="csrf_token"]');
            csrfToken = csrfEl ? csrfEl.value : '';
        }
    }

    function formFieldValue(form, name, fallback) {
        if (!form) return fallback;
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) return fallback;
        if (el.type === 'checkbox') {
            return el.checked;
        }
        return el.value;
    }

    function setFormFieldValue(form, name, value) {
        if (!form) return;
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) return;
        if (el.type === 'checkbox') {
            el.checked = !!value;
            return;
        }
        el.value = value;
    }

    function canvasEl() {
        return document.getElementById('calCanvas');
    }

    function paperSize() {
        var canvas = canvasEl();
        return {
            w: parseFloat(canvas ? canvas.getAttribute('data-paper-w') || '1' : '1'),
            h: parseFloat(canvas ? canvas.getAttribute('data-paper-h') || '1' : '1')
        };
    }

    function templateCal() {
        return cfg.templateCalibration || { x_offset_mm: 0, y_offset_mm: 0, scale_x: 1, scale_y: 1 };
    }

    function globalCal() {
        return cfg.globalCalibration || { x_offset_mm: 0, y_offset_mm: 0, scale_x: 1, scale_y: 1 };
    }

    function scaleFactors() {
        var t = templateCal();
        var g = globalCal();
        return {
            sx: (g.scale_x || 1) * (t.scale_x || 1),
            sy: (g.scale_y || 1) * (t.scale_y || 1)
        };
    }

    function effectivePosition(field) {
        var t = templateCal();
        var g = globalCal();
        var s = scaleFactors();
        return {
            x: ((field.x_mm + t.x_offset_mm + g.x_offset_mm) * s.sx),
            y: ((field.y_mm + t.y_offset_mm + g.y_offset_mm) * s.sy),
            width: Math.max(1, field.width_mm * s.sx),
            height: Math.max(1, field.height_mm * s.sy)
        };
    }

    function baseFromEffective(effX, effY, effW, effH) {
        var t = templateCal();
        var g = globalCal();
        var s = scaleFactors();
        return {
            x_mm: (effX / s.sx) - t.x_offset_mm - g.x_offset_mm,
            y_mm: (effY / s.sy) - t.y_offset_mm - g.y_offset_mm,
            width_mm: effW / s.sx,
            height_mm: effH / s.sy
        };
    }

    function mmFromEvent(canvas, clientX, clientY) {
        var rect = canvas.getBoundingClientRect();
        var paper = paperSize();
        return {
            x: ((clientX - rect.left) / rect.width) * paper.w,
            y: ((clientY - rect.top) / rect.height) * paper.h
        };
    }

    function clampEffective(x, y, width, height) {
        var paper = paperSize();
        return {
            x: Math.max(0, Math.min(x, paper.w - width)),
            y: Math.max(0, Math.min(y, paper.h - height)),
            width: width,
            height: height
        };
    }

    function findFieldConfig(fieldId) {
        var id = Number(fieldId);
        return (cfg.fields || []).find(function (f) { return Number(f.id) === id; });
    }

    function visibleFieldIds() {
        return (cfg.fields || [])
            .map(function (f) { return f.id; })
            .filter(function (id) {
                var btn = listBtnEl(id);
                return !btn || !btn.closest('li') || !btn.closest('li').hidden;
            });
    }

    function fieldForm() {
        return document.getElementById('calFieldForm');
    }

    function markerEl(fieldId) {
        return document.querySelector('.print-cal-marker[data-field-id="' + fieldId + '"]');
    }

    function listBtnEl(fieldId) {
        return document.querySelector('.print-cal-field-btn[data-field-id="' + fieldId + '"]');
    }

    function showToast(type, message) {
        if (window.AlcrosActionResult && typeof window.AlcrosActionResult.show === 'function') {
            window.AlcrosActionResult.show(type, message);
            return;
        }
        window.alert(message);
    }

    function markCalibrationRefreshPending() {
        try {
            sessionStorage.setItem('alcros-print-cal-updated', String(Date.now()));
        } catch (err) {
            /* ignore */
        }
    }

    function parseApiResponse(res, text) {
        var data = null;
        try {
            data = text ? JSON.parse(text) : null;
        } catch (err) {
            data = null;
        }

        if (!res.ok) {
            if (res.status === 419) {
                return { ok: false, error: 'Session expired. Refresh the page and try again.' };
            }
            if (res.status === 403) {
                return { ok: false, error: (data && data.error) || 'You do not have permission to save calibration changes.' };
            }
            return {
                ok: false,
                error: (data && data.error) || text || ('Save failed (HTTP ' + res.status + ').')
            };
        }

        if (!data) {
            return { ok: false, error: 'Unexpected server response. Refresh the page and try again.' };
        }

        return data;
    }

    function sampleTextForField(field) {
        var values = cfg.sampleValues || {};
        var text = values[field.field_name];
        if (text !== undefined && String(text).trim() !== '') {
            return String(text);
        }
        return field.label || field.field_name;
    }

    function readFieldFormValues() {
        var form = fieldForm();
        if (!form) return null;

        var enabledEl = form.querySelector('[name="enabled"]');
        var values = {
            x_mm: parseFloat(formFieldValue(form, 'x_mm', '0')) || 0,
            y_mm: parseFloat(formFieldValue(form, 'y_mm', '0')) || 0,
            width_mm: parseFloat(formFieldValue(form, 'width_mm', '0')) || 0,
            height_mm: parseFloat(formFieldValue(form, 'height_mm', '0')) || 0,
            font_size: parseFloat(formFieldValue(form, 'font_size', '10')) || 10,
            font_family: formFieldValue(form, 'font_family', 'Arial') || 'Arial',
            alignment: formFieldValue(form, 'alignment', 'left') || 'left',
            enabled: enabledEl && enabledEl.checked ? 1 : 0
        };

        var field = activeFieldId ? findFieldConfig(activeFieldId) : null;
        if (field) {
            var labelInput = document.getElementById('calFieldLabel');
            values.label = labelInput ? String(labelInput.value || '').trim() : (field.label || '');
        }

        return values;
    }

    function readTemplateFormValues() {
        var form = document.getElementById('calTemplateForm');
        if (!form) return null;
        return {
            x_offset_mm: parseFloat(formFieldValue(form, 'x_offset_mm', '0')) || 0,
            y_offset_mm: parseFloat(formFieldValue(form, 'y_offset_mm', '0')) || 0,
            scale_x: parseFloat(formFieldValue(form, 'scale_x', '1')) || 1,
            scale_y: parseFloat(formFieldValue(form, 'scale_y', '1')) || 1
        };
    }

    function fieldHint(field) {
        var hints = cfg.fieldHints || {};
        var hint = hints[field.field_name];
        if (hint && String(hint).trim() !== '') {
            return String(hint);
        }
        return field.label || field.field_name;
    }

    function markerLabel(field) {
        return showSampleText ? sampleTextForField(field) : fieldHint(field);
    }

    function syncPreviewTextDisplay(field) {
        var previewEl = document.getElementById('calPreviewText');
        if (!previewEl || !field) return;
        previewEl.value = markerLabel(field);
    }

    function markerJustifyContent(alignment) {
        if (alignment === 'right') return 'flex-end';
        if (alignment === 'center') return 'center';
        return 'flex-start';
    }

    function syncMarkerFromField(fieldId) {
        var marker = markerEl(fieldId);
        var field = findFieldConfig(fieldId);
        if (!marker || !field) return;

        var pos = effectivePosition(field);
        var alignment = field.alignment || 'left';
        marker.style.left = pos.x.toFixed(2) + 'mm';
        marker.style.top = pos.y.toFixed(2) + 'mm';
        marker.style.width = pos.width.toFixed(2) + 'mm';
        marker.style.height = pos.height.toFixed(2) + 'mm';
        marker.style.fontSize = field.font_size + 'pt';
        marker.style.textAlign = alignment;
        marker.style.justifyContent = markerJustifyContent(alignment);
        marker.style.fontFamily = field.font_family;
        marker.classList.toggle('is-disabled', !field.enabled);
        marker.classList.toggle('is-sample-mode', showSampleText);

        var textEl = marker.querySelector('.print-cal-marker-text');
        if (textEl) textEl.textContent = markerLabel(field);
    }

    function repositionAllMarkers() {
        (cfg.fields || []).forEach(function (field) {
            syncMarkerFromField(field.id);
        });
        updateAllMarkerVisibility();
    }

    function updateListItemState(fieldId, enabled) {
        var btn = listBtnEl(fieldId);
        if (!btn) return;

        btn.classList.toggle('is-hidden-field', !enabled);
        var badge = btn.querySelector('.print-cal-field-badge');
        if (enabled && badge) {
            badge.remove();
        } else if (!enabled && !badge) {
            badge = document.createElement('span');
            badge.className = 'print-cal-field-badge';
            badge.textContent = 'Hidden';
            btn.appendChild(badge);
        }
    }

    function isCustomField(field) {
        if (!field || !field.field_name) return false;
        return String(field.field_name).indexOf('custom_textbox_') === 0;
    }

    function initSavedFieldLabels() {
        (cfg.fields || []).forEach(function (field) {
            field._savedLabel = String(field.label || field.field_name || '').trim();
        });
    }

    function setLabelSaveStatus(state, detail) {
        var statusEl = document.getElementById('calLabelSaveStatus');
        if (!statusEl) return;

        statusEl.className = 'print-cal-label-save-status';
        if (state === 'saving') {
            statusEl.classList.add('is-saving');
            statusEl.textContent = 'Saving name…';
        } else if (state === 'saved') {
            statusEl.classList.add('is-saved');
            statusEl.textContent = 'Name saved';
        } else if (state === 'error') {
            statusEl.classList.add('is-error');
            statusEl.textContent = detail || 'Could not save name';
        } else {
            statusEl.textContent = '';
        }
    }

    function applyFieldLabel(fieldId, label, options) {
        options = options || {};
        var field = findFieldConfig(fieldId);
        if (!field) return false;

        var trimmed = String(label || '').trim();
        if (!trimmed) return false;

        field.label = trimmed;
        updateListItemLabel(fieldId, trimmed);

        if (activeFieldId === fieldId) {
            var title = document.getElementById('calFieldTitle');
            if (title) title.textContent = trimmed;
            syncPreviewTextDisplay(field);
        }

        syncMarkerFromField(fieldId);

        if (options.autoSave !== false) {
            scheduleLabelSave(fieldId, trimmed);
        }

        return true;
    }

    function scheduleLabelSave(fieldId, label) {
        clearTimeout(labelSaveTimer);
        labelSaveTimer = setTimeout(function () {
            saveFieldLabelNow(fieldId, label);
        }, 600);
    }

    function saveFieldLabelNow(fieldId, label) {
        if (labelSaveInFlight) return Promise.resolve();

        var field = findFieldConfig(fieldId);
        if (!field) return Promise.resolve();

        var trimmed = String(label || '').trim();
        if (!trimmed) {
            setLabelSaveStatus('error', 'Enter a display name.');
            return Promise.resolve();
        }

        if (trimmed === String(field._savedLabel || '').trim()) {
            setLabelSaveStatus('');
            return Promise.resolve();
        }

        if (!csrfToken) {
            setLabelSaveStatus('error', 'Session expired. Refresh the page.');
            return Promise.resolve();
        }

        labelSaveInFlight = true;
        setLabelSaveStatus('saving');

        return postForm('save_field', {
            field_id: String(fieldId),
            label: trimmed
        }).then(function (res) {
            labelSaveInFlight = false;

            if (!res || res.ok === false) {
                var err = (res && res.error) || 'Could not save field name.';
                setLabelSaveStatus('error', err);
                showToast('error', err);
                return;
            }

            if (res.field) {
                if (res.field.label !== undefined) {
                    field.label = res.field.label;
                    field._savedLabel = res.field.label;
                    updateListItemLabel(fieldId, res.field.label);
                    if (activeFieldId === fieldId) {
                        var title = document.getElementById('calFieldTitle');
                        if (title) title.textContent = res.field.label;
                        var labelInput = document.getElementById('calFieldLabel');
                        if (labelInput) labelInput.value = res.field.label;
                        syncPreviewTextDisplay(field);
                    }
                    syncMarkerFromField(fieldId);
                }
            }

            setLabelSaveStatus('saved');
            window.setTimeout(function () {
                if (activeFieldId === fieldId) {
                    setLabelSaveStatus('');
                }
            }, 1800);
        }).catch(function () {
            labelSaveInFlight = false;
            setLabelSaveStatus('error', 'Could not save name. Check your connection.');
        });
    }

    function updateCustomFieldActions(field) {
        var isCustom = isCustomField(field);
        var deleteBtn = document.getElementById('calDeleteFieldBtn');
        var resetForm = document.getElementById('calResetFieldForm');
        var labelWrap = document.getElementById('calFieldLabelWrap');
        var labelInput = document.getElementById('calFieldLabel');
        if (deleteBtn) deleteBtn.hidden = !isCustom;
        if (resetForm) resetForm.hidden = isCustom;
        if (labelWrap) labelWrap.hidden = false;
        if (labelInput) {
            labelInput.disabled = false;
            labelInput.value = field.label || field.field_name || '';
            setLabelSaveStatus('');
        }
    }

    function updateListItemLabel(fieldId, label) {
        var btn = listBtnEl(fieldId);
        if (!btn) return;
        btn.setAttribute('data-field-label', label);
        var span = btn.querySelector('.print-cal-field-label');
        if (span) span.textContent = label;
    }

    function removeFieldFromUi(fieldId) {
        fieldId = Number(fieldId);
        var field = findFieldConfig(fieldId);
        if (field && cfg.sampleValues) {
            delete cfg.sampleValues[field.field_name];
        }

        cfg.fields = (cfg.fields || []).filter(function (f) { return Number(f.id) !== fieldId; });

        placedFieldIds.delete(fieldId);
        dirtyFieldIds.delete(fieldId);
        persistPlacedFields();
        updateDirtyUi();

        var btn = listBtnEl(fieldId);
        if (btn && btn.closest('li')) {
            btn.closest('li').remove();
        }

        var marker = markerEl(fieldId);
        if (marker) marker.remove();
    }

    function deleteCustomField() {
        if (!activeFieldId) {
            showToast('error', 'Select a custom textbox to delete.');
            return Promise.resolve();
        }

        var field = findFieldConfig(activeFieldId);
        if (!field || !isCustomField(field)) {
            showToast('error', 'Only custom textboxes can be deleted.');
            return Promise.resolve();
        }

        if (!csrfToken) {
            showToast('error', 'Security token missing. Refresh the page and try again.');
            return Promise.resolve();
        }

        var fieldLabel = field.label || field.field_name || 'this textbox';
        var msg = 'Delete “' + fieldLabel + '”? This cannot be undone.';

        function performDelete() {
            var deleteBtn = document.getElementById('calDeleteFieldBtn');
            if (deleteBtn) deleteBtn.disabled = true;

            return postForm('delete_field', { field_id: String(field.id) }).then(function (res) {
                if (deleteBtn) deleteBtn.disabled = false;
                if (!res || res.ok === false) {
                    showToast('error', (res && res.error) || 'Could not delete textbox.');
                    return;
                }

                var deletedId = activeFieldId;
                removeFieldFromUi(deletedId);
                markCalibrationRefreshPending();

                var ids = visibleFieldIds();
                activeFieldId = null;
                if (ids.length) {
                    selectField(ids[0], false);
                } else {
                    setEditorVisible(false);
                    updateAllMarkerVisibility();
                }

                showToast('success', 'Custom textbox deleted.');
            }).catch(function () {
                if (deleteBtn) deleteBtn.disabled = false;
                showToast('error', 'Could not delete textbox. Check your connection.');
            });
        }

        if (window.AlcrosConfirm && typeof window.AlcrosConfirm.ask === 'function') {
            return window.AlcrosConfirm.ask(msg).then(function (ok) {
                if (ok) return performDelete();
            });
        }

        if (window.confirm(msg)) {
            return performDelete();
        }
        return Promise.resolve();
    }

    function setAlignmentButtons(alignment) {
        document.querySelectorAll('.print-cal-align-btn').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-align') === alignment);
        });
    }

    function flushPendingLabelSave() {
        if (!activeFieldId) return;
        clearTimeout(labelSaveTimer);
        var labelInput = document.getElementById('calFieldLabel');
        if (!labelInput) return;
        saveFieldLabelNow(activeFieldId, labelInput.value);
    }

    function selectField(fieldId, scrollList) {
        if (activeFieldId && activeFieldId !== Number(fieldId)) {
            flushPendingLabelSave();
        }

        var field = findFieldConfig(fieldId);
        if (!field) {
            return;
        }

        activeFieldId = Number(fieldId);

        document.querySelectorAll('.print-cal-field-btn').forEach(function (btn) {
            btn.classList.toggle('is-active', parseInt(btn.getAttribute('data-field-id'), 10) === activeFieldId);
        });

        setEditorVisible(true);

        var title = document.getElementById('calFieldTitle');
        var key = document.getElementById('calFieldKey');
        if (title) title.textContent = field.label || field.field_name;
        if (key) key.textContent = field.field_name;

        var form = fieldForm();
        if (!form) return;

        setFormFieldValue(form, 'field_id', field.id);
        setFormFieldValue(form, 'x_mm', field.x_mm.toFixed(2));
        setFormFieldValue(form, 'y_mm', field.y_mm.toFixed(2));
        setFormFieldValue(form, 'width_mm', field.width_mm);
        setFormFieldValue(form, 'height_mm', field.height_mm);
        setFormFieldValue(form, 'font_size', field.font_size);
        setFormFieldValue(form, 'font_family', field.font_family);
        setFormFieldValue(form, 'alignment', field.alignment || 'left');
        setFormFieldValue(form, 'enabled', field.enabled);
        setAlignmentButtons(field.alignment || 'left');
        syncPreviewTextDisplay(field);

        var labelInput = document.getElementById('calFieldLabel');
        if (labelInput) {
            labelInput.value = field.label || field.field_name || '';
            setLabelSaveStatus('');
        }

        var resetForm = document.getElementById('calResetFieldForm');
        if (resetForm) {
            var resetFieldId = resetForm.querySelector('[name="field_id"]');
            if (resetFieldId) resetFieldId.value = field.id;
        }

        syncMarkerFromField(fieldId);
        updateAllMarkerVisibility();
        updateCustomFieldActions(field);

        if (scrollList) {
            var btn = listBtnEl(fieldId);
            if (btn) btn.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function markFieldDirty(fieldId) {
        fieldId = Number(fieldId);
        if (!fieldId) return;
        dirtyFieldIds.add(fieldId);
        updateDirtyUi();
        updateSaveAllButton();
        setSaveStatus('pending');
    }

    function updateSaveAllButton() {
        var btn = document.getElementById('calSaveAllBtn');
        if (!btn) return;

        var count = dirtyFieldIds.size;
        if (!btn.dataset.defaultLabel) {
            btn.dataset.defaultLabel = 'Save all changes';
        }

        btn.textContent = count > 0
            ? ('Save all changes (' + count + ')')
            : btn.dataset.defaultLabel;
        btn.disabled = count === 0 || saveInFlight;
        btn.classList.toggle('is-dirty', count > 0);
    }

    function setSaveStatus(state, detail) {
        var statusEl = document.getElementById('calSaveStatus');
        var retryBtn = document.getElementById('calRetrySaveBtn');
        if (!statusEl) return;

        statusEl.className = 'print-cal-save-status is-' + state;
        var count = dirtyFieldIds.size;

        if (state === 'pending') {
            statusEl.textContent = count + ' unsaved change' + (count === 1 ? '' : 's') + '…';
        } else if (state === 'saving') {
            var savingCount = detail || count;
            statusEl.textContent = 'Saving ' + savingCount + ' field' + (savingCount === 1 ? '' : 's') + '…';
        } else if (state === 'saved') {
            statusEl.textContent = 'All changes saved';
        } else if (state === 'error') {
            statusEl.textContent = detail || 'Could not save changes.';
        }

        if (retryBtn) {
            retryBtn.hidden = state !== 'error';
        }

        updateSaveAllButton();
    }

    function saveAllChangesNow() {
        return saveAllChangedFields({ manual: true, toast: true });
    }

    function clearFieldsDirty(fieldIds) {
        fieldIds.forEach(function (fieldId) {
            dirtyFieldIds.delete(Number(fieldId));
        });
        updateDirtyUi();
        updateSaveAllButton();
        if (dirtyFieldIds.size === 0) {
            setSaveStatus('saved');
        }
    }

    function updateDirtyUi() {
        document.querySelectorAll('.print-cal-field-btn').forEach(function (btn) {
            var fieldId = parseInt(btn.getAttribute('data-field-id'), 10);
            btn.classList.toggle('is-dirty', dirtyFieldIds.has(fieldId));
        });
    }

    function applyFieldValues(fieldId, values, updateForm, skipDirty) {
        var field = findFieldConfig(fieldId);
        if (!field || !values) return;

        field.x_mm = values.x_mm;
        field.y_mm = values.y_mm;
        field.width_mm = Math.max(1, values.width_mm);
        field.height_mm = Math.max(1, values.height_mm);
        field.font_size = values.font_size;
        field.font_family = values.font_family;
        field.alignment = values.alignment;
        field.enabled = values.enabled;

        if (values.label !== undefined) {
            field.label = values.label;
            field._savedLabel = values.label;
            updateListItemLabel(fieldId, values.label);
            if (activeFieldId === fieldId) {
                var title = document.getElementById('calFieldTitle');
                if (title) title.textContent = values.label;
                var labelInput = document.getElementById('calFieldLabel');
                if (labelInput) labelInput.value = values.label;
            }
            if (isCustomField(field)) {
                cfg.sampleValues = cfg.sampleValues || {};
                cfg.sampleValues[field.field_name] = values.label;
            }
        }

        syncMarkerFromField(fieldId);
        updateListItemState(fieldId, !!values.enabled);
        updateAllMarkerVisibility();

        if (updateForm && activeFieldId === fieldId) {
            var form = fieldForm();
            if (!form) return;
            setFormFieldValue(form, 'x_mm', field.x_mm.toFixed(2));
            setFormFieldValue(form, 'y_mm', field.y_mm.toFixed(2));
            setFormFieldValue(form, 'width_mm', field.width_mm.toFixed(2));
            setFormFieldValue(form, 'height_mm', field.height_mm.toFixed(2));
            setFormFieldValue(form, 'font_size', field.font_size);
            setFormFieldValue(form, 'font_family', field.font_family);
            setFormFieldValue(form, 'alignment', field.alignment);
            setFormFieldValue(form, 'enabled', field.enabled);
            setAlignmentButtons(field.alignment);
        }

        if (!skipDirty) {
            markFieldDirty(fieldId);
        }
    }

    function moveActiveFieldEffective(effX, effY) {
        if (!activeFieldId) return;
        var field = findFieldConfig(activeFieldId);
        if (!field) return;

        var pos = effectivePosition(field);
        var clamped = clampEffective(effX, effY, pos.width, pos.height);
        var base = baseFromEffective(clamped.x, clamped.y, pos.width, pos.height);
        applyFieldValues(activeFieldId, {
            x_mm: base.x_mm,
            y_mm: base.y_mm,
            width_mm: field.width_mm,
            height_mm: field.height_mm,
            font_size: field.font_size,
            font_family: field.font_family,
            alignment: field.alignment,
            enabled: field.enabled
        }, true);
    }

    function nudgeField(dx, dy) {
        if (!activeFieldId) return;
        var field = findFieldConfig(activeFieldId);
        if (!field) return;
        var pos = effectivePosition(field);
        moveActiveFieldEffective(pos.x + dx, pos.y + dy);
    }

    function nudgeFieldSize(dw, dh) {
        if (!activeFieldId) return;
        var field = findFieldConfig(activeFieldId);
        if (!field) return;
        applyFieldValues(activeFieldId, {
            x_mm: field.x_mm,
            y_mm: field.y_mm,
            width_mm: Math.max(1, field.width_mm + dw),
            height_mm: Math.max(1, field.height_mm + dh),
            font_size: field.font_size,
            font_family: field.font_family,
            alignment: field.alignment,
            enabled: field.enabled
        }, true);
    }

    function navigateField(direction) {
        var ids = visibleFieldIds();
        if (!ids.length) return;
        var idx = activeFieldId ? ids.indexOf(activeFieldId) : -1;
        if (idx === -1) {
            selectField(ids[direction > 0 ? 0 : ids.length - 1], true);
            return;
        }
        idx = (idx + direction + ids.length) % ids.length;
        selectField(ids[idx], true);
    }

    function applyTemplatePreview(values) {
        if (!values) return;
        cfg.templateCalibration = values;
        repositionAllMarkers();
    }

    function nudgeTemplate(dx, dy) {
        var form = document.getElementById('calTemplateForm');
        if (!form) return;
        var xInput = form.querySelector('[name="x_offset_mm"]');
        var yInput = form.querySelector('[name="y_offset_mm"]');
        xInput.value = (parseFloat(xInput.value || '0') + dx).toFixed(1);
        yInput.value = (parseFloat(yInput.value || '0') + dy).toFixed(1);
        applyTemplatePreview(readTemplateFormValues());
    }

    function nudgeTemplateScale(delta) {
        var form = document.getElementById('calTemplateForm');
        if (!form) return;
        var sx = form.querySelector('[name="scale_x"]');
        var sy = form.querySelector('[name="scale_y"]');
        sx.value = Math.max(0.9, Math.min(1.1, (parseFloat(sx.value || '1') + delta))).toFixed(3);
        sy.value = Math.max(0.9, Math.min(1.1, (parseFloat(sy.value || '1') + delta))).toFixed(3);
        applyTemplatePreview(readTemplateFormValues());
    }

    function apiPrintRequestUrl() {
        var url = cfg.apiPrintUrl || 'api/print.php';
        if (url.indexOf('alcros_auth=') !== -1) {
            return url;
        }
        try {
            var token = sessionStorage.getItem('alcros_auth');
            if (token) {
                var sep = url.indexOf('?') !== -1 ? '&' : '?';
                return url + sep + 'alcros_auth=' + encodeURIComponent(token);
            }
        } catch (err) {
            /* ignore storage errors */
        }
        return url;
    }

    function syncActiveFieldFormFromConfig() {
        if (!activeFieldId) return;
        var field = findFieldConfig(activeFieldId);
        if (!field) return;

        var form = fieldForm();
        if (!form) return;

        setFormFieldValue(form, 'field_id', String(field.id));
        setFormFieldValue(form, 'x_mm', Number(field.x_mm).toFixed(2));
        setFormFieldValue(form, 'y_mm', Number(field.y_mm).toFixed(2));
        setFormFieldValue(form, 'width_mm', Number(field.width_mm).toFixed(2));
        setFormFieldValue(form, 'height_mm', Number(field.height_mm).toFixed(2));
        setFormFieldValue(form, 'font_size', String(field.font_size));
        setFormFieldValue(form, 'font_family', field.font_family || 'Arial');
        setFormFieldValue(form, 'alignment', field.alignment || 'left');
        setFormFieldValue(form, 'enabled', field.enabled);
        setAlignmentButtons(field.alignment || 'left');

        var labelInput = document.getElementById('calFieldLabel');
        if (labelInput) labelInput.value = field.label || field.field_name || '';
    }

    function postForm(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('csrf_token', csrfToken);
        try {
            var authToken = sessionStorage.getItem('alcros_auth');
            if (authToken) {
                body.append('alcros_auth', authToken);
            }
        } catch (err) {
            /* ignore storage errors */
        }
        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });
        return fetch(apiPrintRequestUrl(), {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.text().then(function (text) {
                return parseApiResponse(res, text);
            });
        });
    }

    function fieldPayloadForId(fieldId) {
        var field = findFieldConfig(fieldId);
        if (!field) return null;

        var data = {
            field_id: String(fieldId),
            x_mm: Number(field.x_mm).toFixed(2),
            y_mm: Number(field.y_mm).toFixed(2),
            width_mm: Number(field.width_mm).toFixed(2),
            height_mm: Number(field.height_mm).toFixed(2),
            font_size: String(field.font_size),
            font_family: field.font_family || 'Arial',
            alignment: field.alignment || 'left',
            enabled: field.enabled ? '1' : '0'
        };

        var label = String(field.label || '').trim();
        if (!label) {
            return {
                error: 'Enter a display name for “' + (field.field_name || ('field #' + fieldId)) + '”.'
            };
        }
        data.label = label;

        return data;
    }

    function collectDirtyFieldPayloads() {
        if (activeFieldId) {
            syncActiveFieldFormFromConfig();
            var activeField = findFieldConfig(activeFieldId);
            if (activeField) {
                var labelInput = document.getElementById('calFieldLabel');
                if (labelInput) {
                    activeField.label = String(labelInput.value || '').trim();
                }
            }
        }

        var payloads = [];
        var errors = [];
        dirtyFieldIds.forEach(function (fieldId) {
            var payload = fieldPayloadForId(fieldId);
            if (!payload) return;
            if (payload.error) {
                errors.push(payload.error);
                return;
            }
            payloads.push(payload);
        });

        return { payloads: payloads, errors: errors };
    }

    function applySavedFieldsResponse(fields) {
        (fields || []).forEach(function (field) {
            if (!field || !field.id) return;
            applyFieldValues(field.id, field, activeFieldId === field.id, true);
            markFieldPlaced(field.id);
        });
        updateAllMarkerVisibility();
    }

    function saveAllChangedFields(options) {
        options = options || {};

        if (saveInFlight) {
            return Promise.resolve();
        }

        var collected = collectDirtyFieldPayloads();
        if (collected.errors.length) {
            setSaveStatus('error', collected.errors[0]);
            showToast('error', collected.errors[0]);
            return Promise.resolve();
        }
        if (!collected.payloads.length) {
            setSaveStatus('saved');
            if (options.manual) {
                showToast('error', 'No changes to save.');
            }
            return Promise.resolve();
        }

        if (!csrfToken) {
            setSaveStatus('error', 'Session expired. Refresh the page.');
            showToast('error', 'Security token missing. Refresh the page and try again.');
            return Promise.resolve();
        }

        var count = collected.payloads.length;
        saveInFlight = true;
        updateSaveAllButton();
        setSaveStatus('saving', count);

        var saveBtn = document.getElementById('calSaveAllBtn');

        function finishSave(res) {
            saveInFlight = false;
            updateSaveAllButton();

            if (!res || res.ok === false) {
                var err = (res && res.error) || 'Could not save fields.';
                setSaveStatus('error', err);
                showToast('error', err);
                return;
            }

            var savedFields = res.fields || (res.field ? [res.field] : []);
            var savedIds = savedFields.map(function (field) { return field.id; });
            applySavedFieldsResponse(savedFields);
            clearFieldsDirty(savedIds);
            markCalibrationRefreshPending();
            setSaveStatus('saved');

            if (options.toast !== false) {
                var message = options.message;
                if (!message) {
                    message = savedFields.length > 1
                        ? ('Saved ' + savedFields.length + ' fields.')
                        : 'Field saved.';
                }
                if (res.warning) {
                    message += ' Some fields could not be saved.';
                }
                showToast('success', message);
            }
        }

        var request = postForm('save_fields', {
            fields_json: JSON.stringify(collected.payloads)
        }).then(finishSave).catch(function () {
            saveInFlight = false;
            updateSaveAllButton();
            setSaveStatus('error', 'Could not save. Check your connection.');
            showToast('error', 'Could not save fields. Check your connection.');
        });

        if (window.AlcrosLoading && saveBtn) {
            return window.AlcrosLoading.wrap(
                saveBtn,
                request,
                count > 1 ? ('Saving ' + count + ' fields…') : 'Saving…'
            );
        }

        return request;
    }

    function bindTabs() {
        document.querySelectorAll('.print-cal-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = tab.getAttribute('data-tab');
                document.querySelectorAll('.print-cal-tab').forEach(function (el) {
                    var active = el.getAttribute('data-tab') === target;
                    el.classList.toggle('is-active', active);
                    el.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                document.querySelectorAll('.print-cal-panel').forEach(function (panel) {
                    var active = panel.getAttribute('data-panel') === target;
                    panel.classList.toggle('is-active', active);
                    panel.hidden = !active;
                });
            });
        });
    }

    function bindFieldList() {
        document.querySelectorAll('.print-cal-field-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                selectField(parseInt(btn.getAttribute('data-field-id'), 10), false);
            });
        });
    }

    function bindFieldSearch() {
        var search = document.getElementById('calFieldSearch');
        if (!search) return;

        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase();
            document.querySelectorAll('.print-cal-field-btn').forEach(function (btn) {
                var label = (btn.getAttribute('data-field-label') || btn.textContent || '').toLowerCase();
                var name = (btn.getAttribute('data-field-name') || '').toLowerCase();
                var match = !query || label.indexOf(query) !== -1 || name.indexOf(query) !== -1;
                var item = btn.closest('li');
                if (item) item.hidden = !match;
            });
        });
    }

    function bindAlignment() {
        document.querySelectorAll('.print-cal-align-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var form = fieldForm();
                if (!form || !activeFieldId) return;
                var alignment = btn.getAttribute('data-align') || 'left';
                form.querySelector('[name="alignment"]').value = alignment;
                setAlignmentButtons(alignment);
                var values = readFieldFormValues();
                if (values) applyFieldValues(activeFieldId, values, false);
            });
        });
    }

    function bindNudge() {
        document.querySelectorAll('.print-cal-nudge-btn[data-nudge]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.classList.contains('print-cal-shift-nudge')) return;
                var parts = (btn.getAttribute('data-nudge') || '0,0').split(',');
                nudgeField(parseFloat(parts[0]) || 0, parseFloat(parts[1]) || 0);
            });
        });

        document.querySelectorAll('.print-cal-shift-nudge[data-nudge]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var parts = (btn.getAttribute('data-nudge') || '0,0').split(',');
                nudgeTemplate(parseFloat(parts[0]) || 0, parseFloat(parts[1]) || 0);
            });
        });

        document.querySelectorAll('[data-size-nudge]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var parts = (btn.getAttribute('data-size-nudge') || '0,0').split(',');
                nudgeFieldSize(parseFloat(parts[0]) || 0, parseFloat(parts[1]) || 0);
            });
        });

        document.querySelectorAll('[data-scale-nudge]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                nudgeTemplateScale(parseFloat(btn.getAttribute('data-scale-nudge')) || 0);
            });
        });

        document.querySelectorAll('[data-scale-reset]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var form = document.getElementById('calTemplateForm');
                if (!form) return;
                form.querySelector('[name="scale_x"]').value = '1';
                form.querySelector('[name="scale_y"]').value = '1';
                applyTemplatePreview(readTemplateFormValues());
            });
        });
    }

    function bindLivePreview() {
        var form = fieldForm();
        if (form) {
            form.querySelectorAll('input[name="x_mm"], input[name="y_mm"], input[name="width_mm"], input[name="height_mm"], input[name="font_size"], select[name="font_family"], input[name="enabled"]').forEach(function (el) {
                el.addEventListener('input', onFieldPreviewInput);
                el.addEventListener('change', onFieldPreviewInput);
            });
        }

        var labelInput = document.getElementById('calFieldLabel');
        if (labelInput) {
            labelInput.addEventListener('input', onFieldLabelInput);
            labelInput.addEventListener('blur', onFieldLabelBlur);
        }

        var templateForm = document.getElementById('calTemplateForm');
        if (templateForm) {
            templateForm.querySelectorAll('input').forEach(function (el) {
                el.addEventListener('input', function () {
                    applyTemplatePreview(readTemplateFormValues());
                });
            });
        }
    }

    function onFieldLabelInput() {
        if (!activeFieldId) return;
        var labelInput = document.getElementById('calFieldLabel');
        if (!labelInput) return;
        applyFieldLabel(activeFieldId, labelInput.value, { autoSave: true });
    }

    function onFieldLabelBlur() {
        if (!activeFieldId) return;
        clearTimeout(labelSaveTimer);
        var labelInput = document.getElementById('calFieldLabel');
        if (!labelInput) return;
        saveFieldLabelNow(activeFieldId, labelInput.value);
    }

    function onFieldPreviewInput() {
        if (!activeFieldId) return;
        var values = readFieldFormValues();
        if (values) applyFieldValues(activeFieldId, values, false);
    }

    function bindKeyboardNudge() {
        document.addEventListener('keydown', function (e) {
            if (!activeFieldId) return;
            if (e.target && /^(INPUT|SELECT|TEXTAREA)$/.test(e.target.tagName)) return;

            var step = e.shiftKey ? 0.5 : 1;
            if (e.key === 'ArrowUp') { e.preventDefault(); nudgeField(0, -step); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); nudgeField(0, step); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); nudgeField(-step, 0); }
            else if (e.key === 'ArrowRight') { e.preventDefault(); nudgeField(step, 0); }
        });
    }

    function bindCanvasToggles() {
        var boxesToggle = document.getElementById('calShowBoxes');
        var sampleToggle = document.getElementById('calShowSample');

        function syncBoxesVisibility() {
            showAllBoxes = !!boxesToggle.checked;
            updateAllMarkerVisibility();
        }

        function syncSampleTextVisibility() {
            showSampleText = !!sampleToggle.checked;
            repositionAllMarkers();
            if (activeFieldId) {
                var field = findFieldConfig(activeFieldId);
                if (field) syncPreviewTextDisplay(field);
            }
        }

        function bindToggleInput(input, syncFn) {
            if (!input) return;

            input.addEventListener('change', syncFn);

            var label = input.closest('label');
            if (!label) return;

            label.addEventListener('click', function (e) {
                if (e.target === input) return;
                e.preventDefault();
                input.checked = !input.checked;
                syncFn();
            });
        }

        if (boxesToggle) {
            showAllBoxes = !!boxesToggle.checked;
            bindToggleInput(boxesToggle, syncBoxesVisibility);
            updateAllMarkerVisibility();
        }

        if (sampleToggle) {
            showSampleText = !!sampleToggle.checked;
            bindToggleInput(sampleToggle, syncSampleTextVisibility);
        }
    }

    function bindFieldNav() {
        var prev = document.getElementById('calPrevField');
        var next = document.getElementById('calNextField');
        if (prev) prev.addEventListener('click', function () { navigateField(-1); });
        if (next) next.addEventListener('click', function () { navigateField(1); });
    }

    function appendFieldListItem(field) {
        var list = document.getElementById('calFieldList');
        if (!list) return;

        var li = document.createElement('li');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'print-cal-field-btn';
        btn.setAttribute('data-field-id', String(field.id));
        btn.setAttribute('data-field-name', field.field_name);
        btn.setAttribute('data-field-label', field.label || field.field_name);

        var labelSpan = document.createElement('span');
        labelSpan.className = 'print-cal-field-label';
        labelSpan.textContent = field.label || field.field_name;
        btn.appendChild(labelSpan);

        btn.addEventListener('click', function () {
            selectField(field.id, false);
        });

        li.appendChild(btn);
        list.appendChild(li);
    }

    function createMarkerElement(field) {
        var pos = effectivePosition(field);
        var alignment = field.alignment || 'left';
        var marker = document.createElement('div');
        marker.className = 'print-cal-marker';
        marker.setAttribute('data-field-id', String(field.id));
        marker.setAttribute('data-field-name', field.field_name);
        marker.setAttribute('data-base-x', String(field.x_mm));
        marker.setAttribute('data-base-y', String(field.y_mm));
        marker.setAttribute('data-base-w', String(field.width_mm));
        marker.setAttribute('data-base-h', String(field.height_mm));
        marker.style.left = pos.x.toFixed(2) + 'mm';
        marker.style.top = pos.y.toFixed(2) + 'mm';
        marker.style.width = pos.width.toFixed(2) + 'mm';
        marker.style.height = pos.height.toFixed(2) + 'mm';
        marker.style.fontSize = field.font_size + 'pt';
        marker.style.textAlign = alignment;
        marker.style.justifyContent = markerJustifyContent(alignment);
        marker.style.fontFamily = field.font_family || 'Arial';

        var textEl = document.createElement('span');
        textEl.className = 'print-cal-marker-text';
        marker.appendChild(textEl);

        var handle = document.createElement('span');
        handle.className = 'print-cal-resize-handle';
        handle.setAttribute('aria-hidden', 'true');
        marker.appendChild(handle);

        return marker;
    }

    function appendFieldMarker(field) {
        var canvas = canvasEl();
        if (!canvas) return null;

        var marker = createMarkerElement(field);
        canvas.appendChild(marker);
        bindSingleMarkerDrag(marker);
        syncMarkerFromField(field.id);
        return marker;
    }

    function registerField(field) {
        cfg.fields = cfg.fields || [];
        cfg.fields.push(field);
        cfg.sampleValues = cfg.sampleValues || {};
        cfg.sampleValues[field.field_name] = field.label || 'Sample';
        appendFieldListItem(field);
        appendFieldMarker(field);
        markFieldPlaced(field.id);
        selectField(field.id, true);
        fitCalibrationCanvas();
    }

    function addTextboxField() {
        var canvas = canvasEl();
        var templateId = cfg.templateId || (canvas ? parseInt(canvas.getAttribute('data-template-id') || '0', 10) : 0);
        if (!templateId) {
            showToast('error', 'Template not found. Refresh the page and try again.');
            return Promise.resolve();
        }

        if (!csrfToken) {
            showToast('error', 'Security token missing. Refresh the page and try again.');
            return Promise.resolve();
        }

        var addBtn = document.getElementById('calAddTextbox');
        var payload = { template_id: String(templateId) };

        function finish() {
            if (addBtn) addBtn.disabled = false;
        }

        if (addBtn) addBtn.disabled = true;

        return postForm('add_field', payload).then(function (res) {
            finish();
            if (!res || res.ok === false || !res.field) {
                showToast('error', (res && res.error) || 'Could not add textbox.');
                return;
            }
            registerField(res.field);
            markCalibrationRefreshPending();
            showToast('success', 'Textbox added. Drag it into place, then click Save all changes.');
        }).catch(function () {
            finish();
            showToast('error', 'Could not add textbox. Check your connection.');
        });
    }

    function bindAddTextbox() {
        var addBtn = document.getElementById('calAddTextbox');
        if (!addBtn) return;
        addBtn.addEventListener('click', function () {
            addTextboxField();
        });
    }

    function bindSingleMarkerDrag(marker) {
        var canvas = canvasEl();
        if (!canvas || !marker) return;

        marker.addEventListener('pointerdown', function (e) {
            if (e.target && e.target.classList.contains('print-cal-resize-handle')) return;
            e.preventDefault();
            var fieldId = parseInt(marker.getAttribute('data-field-id'), 10);
            var field = findFieldConfig(fieldId);
            if (!field) return;

            selectField(fieldId, true);
            var clickPos = mmFromEvent(canvas, e.clientX, e.clientY);
            var pos = effectivePosition(field);
            dragState = {
                mode: 'move',
                fieldId: fieldId,
                grabOffsetX: clickPos.x - pos.x,
                grabOffsetY: clickPos.y - pos.y
            };
            marker.setPointerCapture(e.pointerId);
        });

        marker.addEventListener('pointermove', function (e) {
            if (!dragState || dragState.fieldId !== parseInt(marker.getAttribute('data-field-id'), 10)) return;
            var pos = mmFromEvent(canvas, e.clientX, e.clientY);
            if (dragState.mode === 'move') {
                moveActiveFieldEffective(pos.x - dragState.grabOffsetX, pos.y - dragState.grabOffsetY);
            } else if (dragState.mode === 'resize') {
                var field = findFieldConfig(dragState.fieldId);
                if (!field) return;
                var start = dragState.startEffective;
                var newW = Math.max(3, pos.x - start.x);
                var newH = Math.max(2, pos.y - start.y);
                var base = baseFromEffective(start.x, start.y, newW, newH);
                applyFieldValues(dragState.fieldId, {
                    x_mm: field.x_mm,
                    y_mm: field.y_mm,
                    width_mm: base.width_mm,
                    height_mm: base.height_mm,
                    font_size: field.font_size,
                    font_family: field.font_family,
                    alignment: field.alignment,
                    enabled: field.enabled
                }, true);
            }
        });

        marker.addEventListener('pointerup', function () {
            if (dragState && dragState.fieldId === parseInt(marker.getAttribute('data-field-id'), 10)) {
                syncActiveFieldFormFromConfig();
            }
            dragState = null;
        });

        var handle = marker.querySelector('.print-cal-resize-handle');
        if (handle) {
            handle.addEventListener('pointerdown', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var fieldId = parseInt(marker.getAttribute('data-field-id'), 10);
                var field = findFieldConfig(fieldId);
                if (!field) return;
                selectField(fieldId, true);
                var pos = effectivePosition(field);
                dragState = {
                    mode: 'resize',
                    fieldId: fieldId,
                    startEffective: { x: pos.x, y: pos.y }
                };
                handle.setPointerCapture(e.pointerId);
            });

            handle.addEventListener('pointerup', function () {
                if (dragState && dragState.fieldId === parseInt(marker.getAttribute('data-field-id'), 10)) {
                    syncActiveFieldFormFromConfig();
                }
                dragState = null;
            });
        }
    }

    function bindDrag() {
        var canvas = canvasEl();
        if (!canvas) return;

        canvas.querySelectorAll('.print-cal-marker').forEach(function (marker) {
            bindSingleMarkerDrag(marker);
        });
    }

    function fitCalibrationCanvas() {
        var wrap = document.getElementById('calCanvasWrap');
        var scaler = document.getElementById('calCanvasScaler');
        var canvas = canvasEl();
        if (!wrap || !scaler || !canvas) return;

        scaler.style.transform = 'none';
        var naturalWidth = canvas.offsetWidth;
        var naturalHeight = canvas.offsetHeight;
        if (!naturalWidth || !naturalHeight) return;

        var availableWidth = Math.max(wrap.clientWidth - 24, 320);
        var widthScale = availableWidth / naturalWidth;
        var scale = Math.min(Math.max(widthScale, 0.9), 2) * zoomLevel;
        scale = Math.round(scale * 50) / 50;
        scaler.style.transform = 'scale(' + scale.toFixed(2) + ') translateZ(0)';
        scaler.style.width = naturalWidth + 'px';
        scaler.style.height = (naturalHeight * scale) + 'px';

        var zoomLabel = document.getElementById('calZoomLabel');
        if (zoomLabel) {
            zoomLabel.textContent = Math.round(scale * 100) + '%';
        }
    }

    function setZoomLevel(value) {
        zoomLevel = Math.max(0.75, Math.min(1.5, value));
        var slider = document.getElementById('calZoom');
        if (slider) slider.value = String(zoomLevel);
        fitCalibrationCanvas();
    }

    function bindZoom() {
        var slider = document.getElementById('calZoom');
        var zoomIn = document.getElementById('calZoomIn');
        var zoomOut = document.getElementById('calZoomOut');

        if (slider) {
            slider.addEventListener('input', function () {
                setZoomLevel(parseFloat(slider.value) || 1);
            });
        }
        if (zoomIn) zoomIn.addEventListener('click', function () { setZoomLevel(zoomLevel + 0.1); });
        if (zoomOut) zoomOut.addEventListener('click', function () { setZoomLevel(zoomLevel - 0.1); });
    }

    function bindForms() {
        var saveAllBtn = document.getElementById('calSaveAllBtn');
        if (saveAllBtn) {
            saveAllBtn.addEventListener('click', function () {
                saveAllChangesNow();
            });
        }

        var retryBtn = document.getElementById('calRetrySaveBtn');
        if (retryBtn) {
            retryBtn.addEventListener('click', function () {
                saveAllChangesNow();
            });
        }

        window.addEventListener('beforeunload', function (e) {
            if (dirtyFieldIds.size > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        var templateForm = document.getElementById('calTemplateForm');
        if (templateForm) {
            templateForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var data = {};
                new FormData(templateForm).forEach(function (value, key) { data[key] = value; });
                postForm('save_calibration', data).then(function (res) {
                    if (!res || res.ok === false) {
                        showToast('error', (res && res.error) || 'Could not save form shift.');
                        return;
                    }
                    cfg.templateCalibration = readTemplateFormValues();
                    markCalibrationRefreshPending();
                    showToast('success', 'Form shift saved.');
                }).catch(function () {
                    showToast('error', 'Could not save form shift.');
                });
            });
        }

        var globalForm = document.getElementById('calGlobalForm');
        if (globalForm) {
            globalForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var data = {};
                new FormData(globalForm).forEach(function (value, key) { data[key] = value; });
                postForm('save_global_calibration', data).then(function (res) {
                    if (!res || res.ok === false) {
                        showToast('error', (res && res.error) || 'Could not save printer setup.');
                        return;
                    }
                    showToast('success', 'Printer setup saved. Reloading…');
                    markCalibrationRefreshPending();
                    window.setTimeout(function () { window.location.reload(); }, 600);
                }).catch(function () {
                    showToast('error', 'Could not save printer setup.');
                });
            });
        }

        var resetAllForm = document.querySelector('.print-cal-reset-all');
        if (resetAllForm) {
            resetAllForm.addEventListener('submit', function () {
                if (resetAllForm.dataset.alcrosConfirmed === '1') {
                    try {
                        sessionStorage.removeItem(placedStorageKey());
                    } catch (err) {
                        /* ignore */
                    }
                }
            });
        }

        var resetFieldBtn = document.getElementById('calResetFieldBtn');
        var resetFieldForm = document.getElementById('calResetFieldForm');
        if (resetFieldBtn && resetFieldForm) {
            resetFieldBtn.addEventListener('click', function () {
                if (!activeFieldId) {
                    showToast('error', 'Select a field to reset.');
                    return;
                }

                var field = findFieldConfig(activeFieldId);
                if (!field) {
                    showToast('error', 'Select a field to reset.');
                    return;
                }

                var fieldLabel = field.label || field.field_name || 'this field';
                var msg = 'Reset “' + fieldLabel + '” to its default position, size, and font settings?';

                function submitReset(ok) {
                    if (!ok) return;

                    var resetFieldId = resetFieldForm.querySelector('[name="field_id"]');
                    if (resetFieldId) resetFieldId.value = String(field.id);

                    if (window.AlcrosConfirm && typeof window.AlcrosConfirm.markConfirmed === 'function') {
                        window.AlcrosConfirm.markConfirmed(resetFieldForm);
                    }

                    resetFieldForm.submit();
                }

                if (window.AlcrosConfirm && typeof window.AlcrosConfirm.ask === 'function') {
                    window.AlcrosConfirm.ask(msg).then(submitReset);
                    return;
                }

                submitReset(window.confirm(msg));
            });
        }

        var deleteFieldBtn = document.getElementById('calDeleteFieldBtn');
        if (deleteFieldBtn) {
            deleteFieldBtn.addEventListener('click', function () {
                deleteCustomField();
            });
        }
    }

    function safeRun(step, fn) {
        try {
            fn();
        } catch (err) {
            console.error('[print-calibration]', step, err);
        }
    }

    function bootPrintCalibration() {
        safeRun('readConfig', function () {
            readConfig();
            initSavedFieldLabels();
            loadPlacedFields();
        });

        safeRun('bindUi', function () {
            bindTabs();
            bindFieldList();
            bindFieldSearch();
            bindAlignment();
            bindNudge();
            bindLivePreview();
            bindKeyboardNudge();
            bindCanvasToggles();
            bindFieldNav();
            bindDrag();
            bindAddTextbox();
            bindForms();
            bindZoom();
        });

        safeRun('layout', function () {
            fitCalibrationCanvas();
            repositionAllMarkers();
        });

        safeRun('initialSelection', function () {
            if (cfg.selectedFieldId) {
                selectField(cfg.selectedFieldId, false);
            } else {
                activeFieldId = null;
                setEditorVisible(false);
            }
            updateAllMarkerVisibility();
            updateSaveAllButton();
            setSaveStatus('saved');
        });

        window.addEventListener('resize', function () {
            safeRun('resize', fitCalibrationCanvas);
        });

        var bg = document.querySelector('.print-cal-bg');
        if (bg) {
            bg.addEventListener('load', function () {
                safeRun('bgLoad', fitCalibrationCanvas);
            });
        }
        var formBgImg = document.querySelector('.print-cal-form-bg img');
        if (formBgImg) {
            formBgImg.addEventListener('load', function () {
                safeRun('formBgLoad', fitCalibrationCanvas);
            });
        }

        window.addEventListener('error', function (event) {
            if (!event || !String(event.filename || '').includes('print-calibration')) {
                return;
            }
            showToast('error', 'Print calibration hit an error. Refresh if controls stop responding.');
        });
    }

    bootPrintCalibration();
})();
