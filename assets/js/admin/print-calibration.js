(function () {
    'use strict';

    var cfg = {};
    var csrfToken = '';
    var activeFieldId = null;
    var dragState = null;
    var zoomLevel = 1;
    var showSampleText = false;
    var showAllBoxes = false;
    var placedFieldIds = new Set();

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
        if (cfg.csrfToken) {
            csrfToken = cfg.csrfToken;
        }
        if (!csrfToken) {
            var csrfEl = document.querySelector('main input[name="csrf_token"]') || document.querySelector('input[name="csrf_token"]');
            csrfToken = csrfEl ? csrfEl.value : '';
        }
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
        return {
            x_mm: parseFloat(form.querySelector('[name="x_mm"]').value) || 0,
            y_mm: parseFloat(form.querySelector('[name="y_mm"]').value) || 0,
            width_mm: parseFloat(form.querySelector('[name="width_mm"]').value) || 0,
            height_mm: parseFloat(form.querySelector('[name="height_mm"]').value) || 0,
            font_size: parseFloat(form.querySelector('[name="font_size"]').value) || 10,
            font_family: form.querySelector('[name="font_family"]').value || 'Arial',
            alignment: form.querySelector('[name="alignment"]').value || 'left',
            enabled: form.querySelector('[name="enabled"]').checked ? 1 : 0
        };
    }

    function readTemplateFormValues() {
        var form = document.getElementById('calTemplateForm');
        if (!form) return null;
        return {
            x_offset_mm: parseFloat(form.querySelector('[name="x_offset_mm"]').value) || 0,
            y_offset_mm: parseFloat(form.querySelector('[name="y_offset_mm"]').value) || 0,
            scale_x: parseFloat(form.querySelector('[name="scale_x"]').value) || 1,
            scale_y: parseFloat(form.querySelector('[name="scale_y"]').value) || 1
        };
    }

    function fieldHint(field) {
        var hints = cfg.fieldHints || {};
        var hint = hints[field.field_name];
        if (hint && String(hint).trim() !== '') {
            return String(hint);
        }
        var label = field.label || field.field_name || '';
        label = String(label).replace(/^\d+[a-z]?\s+/i, '');
        label = label.replace(/\s*—\s*.+$/, '');
        var words = label.trim().split(/\s+/).slice(0, 3);
        return words.join(' ');
    }

    function isCheckboxField(field) {
        var types = cfg.fieldInputTypes || {};
        if (types[field.field_name] === 'checkbox') {
            return true;
        }
        var marker = markerEl(field.id);
        return marker ? marker.getAttribute('data-input-type') === 'checkbox' : false;
    }

    function checkboxMark(value) {
        return value && String(value).trim() !== '' ? '☑' : '☐';
    }

    function markerLabel(field) {
        if (showSampleText) {
            return sampleTextForField(field);
        }
        return '';
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
        marker.classList.toggle('is-checkbox', isCheckboxField(field));

        var hintEl = marker.querySelector('.print-cal-marker-hint');
        var hintText = fieldHint(field);
        if (hintText) {
            if (!hintEl) {
                hintEl = document.createElement('span');
                hintEl.className = 'print-cal-marker-hint';
                marker.insertBefore(hintEl, marker.firstChild);
            }
            hintEl.textContent = hintText;
        } else if (hintEl) {
            hintEl.remove();
        }

        var checkEl = marker.querySelector('.print-cal-marker-check');
        var textEl = marker.querySelector('.print-cal-marker-text');
        if (isCheckboxField(field)) {
            if (!checkEl) {
                checkEl = document.createElement('span');
                checkEl.className = 'print-cal-marker-check';
                if (textEl) {
                    marker.insertBefore(checkEl, textEl);
                } else {
                    marker.appendChild(checkEl);
                }
            }
            checkEl.textContent = showSampleText ? checkboxMark(markerLabel(field)) : '☐';
            if (textEl) textEl.textContent = '';
        } else {
            if (checkEl) checkEl.remove();
            if (textEl) textEl.textContent = markerLabel(field);
        }
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

    function setAlignmentButtons(alignment) {
        document.querySelectorAll('.print-cal-align-btn').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-align') === alignment);
        });
    }

    function selectField(fieldId, scrollList) {
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

        form.querySelector('[name="field_id"]').value = field.id;
        form.querySelector('[name="x_mm"]').value = field.x_mm.toFixed(2);
        form.querySelector('[name="y_mm"]').value = field.y_mm.toFixed(2);
        form.querySelector('[name="width_mm"]').value = field.width_mm;
        form.querySelector('[name="height_mm"]').value = field.height_mm;
        form.querySelector('[name="font_size"]').value = field.font_size;
        form.querySelector('[name="font_family"]').value = field.font_family;
        form.querySelector('[name="alignment"]').value = field.alignment || 'left';
        form.querySelector('[name="enabled"]').checked = !!field.enabled;
        setAlignmentButtons(field.alignment || 'left');
        syncPreviewTextDisplay(field);

        var resetForm = document.getElementById('calResetFieldForm');
        if (resetForm) {
            var resetFieldId = resetForm.querySelector('[name="field_id"]');
            if (resetFieldId) resetFieldId.value = field.id;
        }

        syncMarkerFromField(fieldId);
        updateAllMarkerVisibility();

        if (scrollList) {
            var btn = listBtnEl(fieldId);
            if (btn) btn.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function applyFieldValues(fieldId, values, updateForm) {
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

        syncMarkerFromField(fieldId);
        updateListItemState(fieldId, !!values.enabled);
        updateAllMarkerVisibility();

        if (updateForm && activeFieldId === fieldId) {
            var form = fieldForm();
            if (!form) return;
            form.querySelector('[name="x_mm"]').value = field.x_mm.toFixed(2);
            form.querySelector('[name="y_mm"]').value = field.y_mm.toFixed(2);
            form.querySelector('[name="width_mm"]').value = field.width_mm.toFixed(2);
            form.querySelector('[name="height_mm"]').value = field.height_mm.toFixed(2);
            form.querySelector('[name="font_size"]').value = field.font_size;
            form.querySelector('[name="font_family"]').value = field.font_family;
            form.querySelector('[name="alignment"]').value = field.alignment;
            form.querySelector('[name="enabled"]').checked = !!field.enabled;
            setAlignmentButtons(field.alignment);
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
        if (activeFieldId) selectField(activeFieldId, false);
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

    function postForm(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('csrf_token', csrfToken);
        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });
        return fetch(cfg.apiPrintUrl || 'api/print.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.text().then(function (text) {
                return parseApiResponse(res, text);
            });
        });
    }

    function fieldSavePayload() {
        var form = fieldForm();
        if (!form || !activeFieldId) return null;

        var data = {};
        new FormData(form).forEach(function (value, key) {
            data[key] = value;
        });
        data.field_id = String(activeFieldId);
        data.enabled = form.querySelector('[name="enabled"]').checked ? '1' : '0';
        return data;
    }

    function markSaveButtonSaved() {
        var saveBtn = document.getElementById('calSaveFieldBtn');
        if (!saveBtn || saveBtn.dataset.savedTimer) return;

        var original = saveBtn.textContent;
        saveBtn.textContent = 'Saved';
        saveBtn.dataset.savedTimer = '1';
        window.setTimeout(function () {
            saveBtn.textContent = original;
            delete saveBtn.dataset.savedTimer;
        }, 1500);
    }

    function saveActiveField(options) {
        options = options || {};
        if (!activeFieldId) {
            showToast('error', 'Select a field from the list first.');
            return Promise.resolve();
        }

        var data = fieldSavePayload();
        if (!data || !data.field_id) {
            showToast('error', 'Could not read field values. Select a field and try again.');
            return Promise.resolve();
        }

        if (!csrfToken) {
            showToast('error', 'Security token missing. Refresh the page and try again.');
            return Promise.resolve();
        }

        var saveBtn = document.getElementById('calSaveFieldBtn');
        if (window.AlcrosLoading && saveBtn) {
            return window.AlcrosLoading.wrap(saveBtn, postForm('save_field', data).then(function (res) {
                if (!res || res.ok === false) {
                    showToast('error', (res && res.error) || 'Could not save field.');
                    return;
                }
                applyFieldValues(activeFieldId, readFieldFormValues(), false);
                markFieldPlaced(activeFieldId);
                updateAllMarkerVisibility();
                markCalibrationRefreshPending();
                if (options.toast !== false) {
                    showToast('success', options.message || 'Field saved.');
                } else if (options.markSaved !== false) {
                    markSaveButtonSaved();
                }
            }).catch(function () {
                showToast('error', 'Could not save field. Check your connection.');
            }), 'Saving…');
        }

        return postForm('save_field', data).then(function (res) {
            if (!res || res.ok === false) {
                showToast('error', (res && res.error) || 'Could not save field.');
                return;
            }
            applyFieldValues(activeFieldId, readFieldFormValues(), false);
            markFieldPlaced(activeFieldId);
            updateAllMarkerVisibility();
            markCalibrationRefreshPending();
            if (options.toast !== false) {
                showToast('success', options.message || 'Field saved.');
            } else if (options.markSaved !== false) {
                markSaveButtonSaved();
            }
        }).catch(function () {
            showToast('error', 'Could not save field. Check your connection.');
        });
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

        var templateForm = document.getElementById('calTemplateForm');
        if (templateForm) {
            templateForm.querySelectorAll('input').forEach(function (el) {
                el.addEventListener('input', function () {
                    applyTemplatePreview(readTemplateFormValues());
                });
            });
        }
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

        function applyBoxesVisibility() {
            updateAllMarkerVisibility();
        }

        function applySampleText() {
            repositionAllMarkers();
            if (activeFieldId) {
                var field = findFieldConfig(activeFieldId);
                if (field) syncPreviewTextDisplay(field);
            }
        }

        if (boxesToggle) {
            showAllBoxes = !!boxesToggle.checked;
            boxesToggle.addEventListener('change', function () {
                showAllBoxes = !!boxesToggle.checked;
                applyBoxesVisibility();
            });
        }

        if (sampleToggle) {
            showSampleText = !!sampleToggle.checked;
            sampleToggle.addEventListener('change', function () {
                showSampleText = !!sampleToggle.checked;
                applySampleText();
            });
        }

        document.querySelectorAll('.print-cal-canvas-toolbar .print-cal-toggle').forEach(function (label) {
            label.addEventListener('click', function (e) {
                var input = label.querySelector('input[type="checkbox"]');
                if (!input || e.target === input) return;
                window.setTimeout(function () {
                    if (input.id === 'calShowBoxes') {
                        showAllBoxes = !!input.checked;
                        applyBoxesVisibility();
                    } else if (input.id === 'calShowSample') {
                        showSampleText = !!input.checked;
                        applySampleText();
                    }
                }, 0);
            });
        });
    }

    function bindFieldNav() {
        var prev = document.getElementById('calPrevField');
        var next = document.getElementById('calNextField');
        if (prev) prev.addEventListener('click', function () { navigateField(-1); });
        if (next) next.addEventListener('click', function () { navigateField(1); });
    }

    function bindDrag() {
        var canvas = canvasEl();
        if (!canvas) return;

        canvas.querySelectorAll('.print-cal-marker').forEach(function (marker) {
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
                    dragState = null;
                });
            }
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
        var saveBtn = document.getElementById('calSaveFieldBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                saveActiveField({ message: 'Field changes saved.' });
            });
        }

        var fieldFormEl = fieldForm();
        if (fieldFormEl) {
            fieldFormEl.addEventListener('submit', function (e) {
                e.preventDefault();
                saveActiveField({ message: 'Field changes saved.' });
            });
        }

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
    }

    readConfig();
    loadPlacedFields();
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
    bindForms();
    bindZoom();
    fitCalibrationCanvas();
    repositionAllMarkers();

    if (cfg.selectedFieldId) {
        selectField(cfg.selectedFieldId, false);
    } else {
        activeFieldId = null;
        setEditorVisible(false);
    }

    updateAllMarkerVisibility();

    window.addEventListener('resize', fitCalibrationCanvas);
    var bg = document.querySelector('.print-cal-bg');
    if (bg) bg.addEventListener('load', fitCalibrationCanvas);
    var formBgImg = document.querySelector('.print-cal-form-bg img');
    if (formBgImg) formBgImg.addEventListener('load', fitCalibrationCanvas);
})();
