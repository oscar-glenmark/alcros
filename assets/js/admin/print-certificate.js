(function () {
    'use strict';

    var cfg = {};
    var csrfToken = '';
    var fillOverrides = {};
    var initialFillValues = {};
    var refreshTimer = null;
    var syncingPreview = false;
    var lastCalibrationStamp = '';
    var previewZoomLevel = 0.84;
    var previewViewMode = 'front';

    var CALIBRATION_STAMP_KEY = 'alcros-print-cal-updated';

    function refreshIfCalibrationChanged() {
        var stamp = '';
        try {
            stamp = sessionStorage.getItem(CALIBRATION_STAMP_KEY) || '';
        } catch (err) {
            stamp = '';
        }
        if (!stamp || stamp === lastCalibrationStamp) {
            return false;
        }
        lastCalibrationStamp = stamp;
        refreshPreviews();
        return true;
    }

    function readConfig() {
        if (window.AlcrosPage && typeof AlcrosPage.readConfig === 'function') {
            cfg = AlcrosPage.readConfig('page-config') || {};
        }
        var csrfEl = document.querySelector('input[name="csrf_token"]');
        csrfToken = csrfEl ? csrfEl.value : '';
        initialFillValues = cfg.initialFillValues || {};
        fillOverrides = {};
        Object.keys(initialFillValues).forEach(function (key) {
            var stored = storedPrintFieldValue(initialFillValues[key], key);
            if (stored !== '') {
                fillOverrides[key] = stored;
            }
        });
    }

    function numericId(value) {
        var id = parseInt(value, 10);
        return id > 0 ? id : 0;
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

    function storedPrintFieldValue(value, fieldName) {
        var trimmed = String(value || '').trim();
        if (isLcroFooterField(fieldName)) {
            return normalizeLcroFooterText(trimmed);
        }
        return formatPrintFieldText(trimmed, fieldName);
    }

    function formatPrintFieldText(value, fieldName) {
        if (cfg.documentKind === 'certification') {
            return String(value || '');
        }
        if (isLcroFooterField(fieldName)) {
            return formatLcroFooterDisplayText(value);
        }
        return String(value || '').toUpperCase();
    }

    function collectFillOverrides() {
        document.querySelectorAll('[data-field-name]').forEach(function (input) {
            var name = input.getAttribute('data-field-name');
            if (!name) return;
            var value = storedPrintFieldValue(input.value, name);
            if (value === '') {
                delete fillOverrides[name];
                return;
            }
            fillOverrides[name] = value;
        });
    }

    function syncFillInput(fieldName, value) {
        var stored = storedPrintFieldValue(value, fieldName);
        var formatted = formatPrintFieldText(stored, fieldName);
        var input = document.querySelector('[data-field-name="' + fieldName + '"]');
        if (input && document.activeElement !== input) {
            input.value = formatted;
        }
        if (stored === '') {
            delete fillOverrides[fieldName];
            return;
        }
        fillOverrides[fieldName] = stored;
    }

    function encodeFillOverrides() {
        collectFillOverrides();
        var payload = {};
        Object.keys(fillOverrides).forEach(function (key) {
            var value = String(fillOverrides[key] || '').trim();
            var initial = storedPrintFieldValue(initialFillValues[key] || '', key);
            if (value === initial) {
                return;
            }
            if (value !== '') {
                payload[key] = value;
            }
        });
        if (Object.keys(payload).length === 0) {
            return '';
        }
        var json = JSON.stringify(payload);
        return btoa(unescape(encodeURIComponent(json)))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '');
    }

    function optionFlags() {
        return {
            paternity: document.getElementById('optPaternity') && document.getElementById('optPaternity').checked,
            delayed_birth: document.getElementById('optDelayedBirth') && document.getElementById('optDelayedBirth').checked,
            delayed_marriage: document.getElementById('optDelayedMarriage') && document.getElementById('optDelayedMarriage').checked,
            delayed_death: document.getElementById('optDelayedDeath') && document.getElementById('optDelayedDeath').checked,
            infant_section: document.getElementById('optInfantSection') && document.getElementById('optInfantSection').checked,
            postmortem: document.getElementById('optPostmortem') && document.getElementById('optPostmortem').checked
        };
    }

    function showBackgroundEnabled() {
        var el = document.getElementById('optShowBackground');
        return !!(el && el.checked);
    }

    function isLocalCertificate() {
        return cfg.documentKind !== 'certification';
    }

    function localPreviewSection() {
        return document.querySelector('.print-cert-previews--local');
    }

    function applyLocalPaperCssVars() {
        var section = document.querySelector('.print-cert-previews');
        if (!section) return;

        var defaultW = cfg.documentKind === 'certification' ? '210' : '215.9';
        var defaultH = cfg.documentKind === 'certification' ? '297' : '358.9';
        var paperW = parseFloat(section.getAttribute('data-paper-w') || cfg.paperWidthMm || defaultW);
        var paperH = parseFloat(section.getAttribute('data-paper-h') || cfg.paperHeightMm || defaultH);
        if (!(paperW > 0 && paperH > 0)) return;

        section.style.setProperty('--print-cert-paper-w', paperW + 'mm');
        section.style.setProperty('--print-cert-paper-h', paperH + 'mm');
    }

    function setBothPreviewButtonActive(active) {
        var bothBtn = document.getElementById('previewViewBoth');
        if (!bothBtn) return;
        bothBtn.classList.toggle('is-active', !!active);
        bothBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    function syncBackPageOptionsVisibility() {
        var section = document.getElementById('backPageOptions');
        if (!section) return;
        section.hidden = previewViewMode === 'front';
    }

    function setPreviewView(mode) {
        if (!isLocalCertificate()) return;

        if (mode === 'both' || mode === 'back' || mode === 'front') {
            previewViewMode = mode;
        } else {
            previewViewMode = 'front';
        }
        var section = localPreviewSection();
        if (section) {
            section.setAttribute('data-preview-view', previewViewMode);
        }
        setBothPreviewButtonActive(previewViewMode === 'both');
        syncBackPageOptionsVisibility();
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(fitLocalPreviewFrames);
        });
    }

    function isLocalPreviewSideVisible(side) {
        var section = localPreviewSection();
        if (!section) return true;
        var view = section.getAttribute('data-preview-view') || previewViewMode || 'front';
        if (view === 'both') return true;
        return view === side;
    }

    function fitLocalPreviewFrame(side) {
        if (!isLocalPreviewSideVisible(side)) return;

        var viewport = document.querySelector('[data-preview-viewport="' + side + '"]');
        var scaler = document.querySelector('[data-preview-scaler="' + side + '"]');
        var iframe = side === 'back' ? document.getElementById('previewBack') : document.getElementById('previewFront');
        if (!viewport || !scaler || !iframe) return;

        scaler.style.transform = 'none';
        var naturalWidth = iframe.offsetWidth;
        var naturalHeight = iframe.offsetHeight;
        if (!naturalWidth || !naturalHeight) return;

        var availableWidth = Math.max(viewport.clientWidth - 24, 240);
        var widthScale = availableWidth / naturalWidth;
        var scale;

        if (previewViewMode === 'both') {
            var availableHeight = Math.max(viewport.clientHeight - 24, 240);
            var heightScale = availableHeight / naturalHeight;
            var fitScale = Math.min(widthScale, heightScale);
            scale = Math.min(Math.max(fitScale, 0.9), 2) * previewZoomLevel;
        } else {
            scale = Math.min(Math.max(widthScale, 0.9), 2) * previewZoomLevel;
        }

        scale = Math.round(scale * 50) / 50;
        scaler.style.transform = 'scale(' + scale.toFixed(2) + ') translateZ(0)';
        scaler.style.width = naturalWidth + 'px';
        scaler.style.height = (naturalHeight * scale) + 'px';

        if (previewViewMode === 'both') {
            viewport.scrollLeft = 0;
            viewport.scrollTop = 0;
        }
    }

    function fitLocalPreviewFrames() {
        if (!isLocalCertificate()) return;
        applyLocalPaperCssVars();
        fitLocalPreviewFrame('front');
        fitLocalPreviewFrame('back');
    }

    function applyQueryParams(url, extra) {
        var parsed = new URL(url, window.location.href);
        if (cfg.manualMode) {
            parsed.searchParams.set('manual', '1');
            parsed.searchParams.set('type', cfg.certificateType || 'birth');
            parsed.searchParams.delete('request_id');
            parsed.searchParams.delete('record_id');
        } else {
            var requestId = numericId(cfg.requestId);
            var recordId = numericId(cfg.recordId);
            if (requestId > 0) parsed.searchParams.set('request_id', String(requestId));
            if (recordId > 0) parsed.searchParams.set('record_id', String(recordId));
        }
        if (cfg.documentKind === 'certification') {
            parsed.searchParams.set('kind', 'certification');
        } else {
            parsed.searchParams.delete('kind');
        }

        if (extra) {
            Object.keys(extra).forEach(function (key) {
                if (extra[key]) {
                    parsed.searchParams.set(key, '1');
                } else {
                    parsed.searchParams.delete(key);
                }
            });
        }

        var fill = encodeFillOverrides();
        if (fill) {
            parsed.searchParams.set('fill', fill);
        } else {
            parsed.searchParams.delete('fill');
        }

        return parsed;
    }

    function renderUrl(page, testMode, opts) {
        opts = opts || {};
        var parsed = applyQueryParams(cfg.printAuthUrl || 'print_render.php', optionFlags());
        parsed.searchParams.set('page', page);
        if (opts.preview !== false) {
            parsed.searchParams.set('preview', '1');
            if (cfg.documentKind === 'certification') {
                if (showBackgroundEnabled()) {
                    parsed.searchParams.set('background', '1');
                } else {
                    parsed.searchParams.delete('background');
                }
            } else {
                parsed.searchParams.set('background', '1');
            }
        } else {
            parsed.searchParams.delete('preview');
        }
        if (testMode) {
            parsed.searchParams.set('test', '1');
        } else {
            parsed.searchParams.delete('test');
        }
        if (cfg.calibrationRev) {
            parsed.searchParams.set('cal', String(cfg.calibrationRev));
        }
        parsed.searchParams.set('_ts', String(Date.now()));
        return parsed.pathname + parsed.search;
    }

    function syncAffidavitFillFields() {
        var flags = optionFlags();
        document.querySelectorAll('[data-fill-group]').forEach(function (el) {
            var group = el.getAttribute('data-fill-group');
            el.hidden = !flags[group];
        });
    }

    function bindEditablePreview(iframe) {
        if (!iframe) return;

        var doc = iframe.contentDocument;
        if (!doc) return;

        iframe.classList.remove('is-loading');

        if (window.AlcrosPrintFitText) {
            AlcrosPrintFitText.fitAll(doc);
        }

        if (isLocalCertificate()) {
            window.requestAnimationFrame(fitLocalPreviewFrames);
        }

        doc.querySelectorAll('.print-field--editable').forEach(function (el) {
            if (el.dataset.fillBound === '1') return;
            el.dataset.fillBound = '1';

            el.addEventListener('input', function () {
                var name = el.getAttribute('data-field');
                if (!name) return;
                syncFillInput(name, storedPrintFieldValue((el.textContent || '').trim(), name));
                if (isLcroFooterField(name)) {
                    el.textContent = formatPrintFieldText(fillOverrides[name] || '', name);
                }
                if (window.AlcrosPrintFitText) {
                    AlcrosPrintFitText.fitOne(el);
                }
            });

            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });
        });
    }

    function schedulePreviewRefresh(reload) {
        if (refreshTimer) window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(function () {
            if (reload) {
                refreshPreviews();
            }
        }, reload ? 350 : 0);
    }

    function syncPreviewFrameSize() {
        if (isLocalCertificate()) {
            fitLocalPreviewFrames();
            return;
        }

        var paperW = parseFloat(cfg.paperWidthMm || '215.9');
        var paperH = parseFloat(cfg.paperHeightMm || '358.9');
        if (!(paperW > 0 && paperH > 0)) return;

        document.querySelectorAll('.print-cert-frame:not(.print-cert-frame--local)').forEach(function (iframe) {
            var block = iframe.closest('.print-cert-preview-block');
            var width = block ? block.clientWidth : iframe.clientWidth;
            if (width > 0) {
                iframe.style.height = Math.round(width * (paperH / paperW)) + 'px';
            }
        });
    }

    function refreshPreviews() {
        syncingPreview = true;
        var front = document.getElementById('previewFront');
        var back = document.getElementById('previewBack');

        function markLoading(iframe) {
            if (!iframe) return;
            iframe.classList.add('is-loading');
            var block = iframe.closest('.print-cert-preview-block');
            if (!block || block.querySelector('.alcros-sk-preview-overlay')) return;
            var overlay = document.createElement('div');
            overlay.className = 'alcros-sk-preview-overlay';
            if (window.AlcrosLoading && typeof window.AlcrosLoading.skeleton === 'function') {
                overlay.innerHTML = window.AlcrosLoading.skeleton('preview');
            }
            block.appendChild(overlay);
        }

        function clearLoading(iframe) {
            if (!iframe) return;
            iframe.classList.remove('is-loading');
            var block = iframe.closest('.print-cert-preview-block');
            var overlay = block && block.querySelector('.alcros-sk-preview-overlay');
            if (overlay) overlay.remove();
        }

        function loadBack() {
            if (!back) {
                syncingPreview = false;
                return;
            }

            markLoading(back);
            var backTimer = window.setTimeout(function () {
                if (back.classList.contains('is-loading')) {
                    clearLoading(back);
                    syncingPreview = false;
                }
            }, 60000);
            back.onload = function () {
                window.clearTimeout(backTimer);
                clearLoading(back);
                bindEditablePreview(back);
                syncingPreview = false;
            };
            back.onerror = function () {
                window.clearTimeout(backTimer);
                clearLoading(back);
                syncingPreview = false;
            };
            back.src = renderUrl('back', false);
        }

        if (front) {
            markLoading(front);
            var frontTimer = window.setTimeout(function () {
                if (front.classList.contains('is-loading')) {
                    clearLoading(front);
                }
                loadBack();
            }, 60000);
            front.onload = function () {
                window.clearTimeout(frontTimer);
                clearLoading(front);
                bindEditablePreview(front);
                loadBack();
            };
            front.onerror = function () {
                window.clearTimeout(frontTimer);
                clearLoading(front);
                loadBack();
            };
            front.src = renderUrl('front', false);
        } else {
            loadBack();
        }

        syncAffidavitFillFields();
    }

    function logPrint(page, testMode, callback) {
        if (cfg.manualMode) {
            if (callback) callback();
            return;
        }

        var body = new FormData();
        body.append('action', 'log_print');
        body.append('csrf_token', csrfToken);
        body.append('page', page);
        if (cfg.documentKind) {
            body.append('kind', cfg.documentKind);
        }
        var requestId = numericId(cfg.requestId);
        var recordId = numericId(cfg.recordId);
        if (requestId > 0) body.append('request_id', String(requestId));
        if (recordId > 0) body.append('record_id', String(recordId));
        if (testMode) body.append('test', '1');

        var flags = optionFlags();
        Object.keys(flags).forEach(function (key) {
            if (flags[key]) body.append(key, '1');
        });

        collectFillOverrides();
        body.append('fill', JSON.stringify(fillOverrides));

        fetch(cfg.apiPrintUrl || 'api/print.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) { return res.json(); })
            .then(function () { if (callback) callback(); })
            .catch(function () { if (callback) callback(); });
    }

    function openPrintWindow(page, testMode) {
        var flags = optionFlags();
        var parsed = applyQueryParams(cfg.printAuthUrl || 'print_render.php', flags);
        parsed.searchParams.set('page', page);
        parsed.searchParams.delete('preview');
        parsed.searchParams.delete('background');
        if (cfg.documentKind === 'certification' && showBackgroundEnabled()) {
            parsed.searchParams.set('background', '1');
        }
        parsed.searchParams.set('mode', 'preprinted');
        if (testMode) {
            parsed.searchParams.set('test', '1');
        } else {
            parsed.searchParams.delete('test');
        }
        if (cfg.calibrationRev) {
            parsed.searchParams.set('cal', String(cfg.calibrationRev));
        }
        parsed.searchParams.set('autoprint', '1');
        var url = parsed.pathname + parsed.search;
        logPrint(page, testMode, function () {
            window.open(url, '_blank', 'noopener,noreferrer');
        });
    }

    function bindFillEditor() {
        document.querySelectorAll('[data-field-name]').forEach(function (input) {
            input.addEventListener('input', function () {
                var name = input.getAttribute('data-field-name');
                var stored = storedPrintFieldValue(input.value, name);
                var display = formatPrintFieldText(stored, name);
                if (input.value !== display) {
                    input.value = display;
                }
                if (stored === '') {
                    delete fillOverrides[name];
                } else {
                    fillOverrides[name] = stored;
                }
                schedulePreviewRefresh(true);
            });
        });

        var resetBtn = document.getElementById('resetFillData');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                fillOverrides = Object.assign({}, initialFillValues);
                document.querySelectorAll('[data-field-name]').forEach(function (input) {
                    var name = input.getAttribute('data-field-name');
                    var stored = fillOverrides[name] || '';
                    input.value = stored ? formatPrintFieldText(stored, name) : '';
                });
                refreshPreviews();
            });
        }

        function syncCalibrationLink(pageSide) {
            var link = document.getElementById('openPrintCalibration');
            if (!link || !cfg.calibrationUrl) return;
            try {
                var parsed = new URL(cfg.calibrationUrl, window.location.href);
                parsed.searchParams.set('page', pageSide === 'back' ? 'back' : 'front');
                link.href = parsed.pathname + parsed.search;
            } catch (err) {
                // Keep the default calibration link.
            }
        }

        document.querySelectorAll('[data-fill-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var side = btn.getAttribute('data-fill-tab');
                syncCalibrationLink(side);
                document.querySelectorAll('[data-fill-tab]').forEach(function (tab) {
                    var active = tab.getAttribute('data-fill-tab') === side;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                document.querySelectorAll('[data-fill-panel]').forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-fill-panel') !== side;
                });
                if (isLocalCertificate()) {
                    setPreviewView(side);
                }
            });
        });

        var bothPreviewBtn = document.getElementById('previewViewBoth');
        if (bothPreviewBtn) {
            bothPreviewBtn.addEventListener('click', function () {
                setPreviewView('both');
            });
        }
    }

    function askConfirm(message) {
        if (window.AlcrosConfirm && typeof window.AlcrosConfirm.ask === 'function') {
            return window.AlcrosConfirm.ask(message);
        }
        return Promise.resolve(window.confirm(message));
    }

    function showActionResult(type, message) {
        if (window.AlcrosActionResult && typeof window.AlcrosActionResult.show === 'function') {
            window.AlcrosActionResult.show(type, message);
            return;
        }
        window.alert(message);
    }

    function postCreateRecord(body) {
        return fetch(cfg.createRecordApiUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken
            }
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try {
                    data = text ? JSON.parse(text) : null;
                } catch (parseErr) {
                    data = null;
                }
                if (!res.ok || !data || data.ok === false) {
                    var err = (data && (data.error || data.message))
                        || (text && text.length < 280 ? text.trim() : '')
                        || ('Could not save the record (HTTP ' + res.status + ').');
                    throw new Error(err);
                }
                return data;
            });
        });
    }

    function bindAddRecord() {
        if (!cfg.manualMode || !cfg.createRecordApiUrl) {
            return;
        }

        var btn = document.getElementById('addRecordFromDocument');
        if (!btn) {
            return;
        }

        btn.addEventListener('click', function () {
            collectFillOverrides();
            if (Object.keys(fillOverrides).length === 0) {
                window.alert('Fill in at least the required name fields before saving a record.');
                return;
            }

            var typeLabel = (cfg.certificateType || 'birth').charAt(0).toUpperCase()
                + (cfg.certificateType || 'birth').slice(1);

            askConfirm('Save this ' + typeLabel + ' form as a new civil record?').then(function (ok) {
                if (!ok) {
                    return;
                }

                btn.disabled = true;
                var originalLabel = btn.textContent;
                btn.textContent = 'Saving…';

                var body = new FormData();
                body.append('action', 'create_record');
                body.append('csrf_token', csrfToken);
                body.append('record_type', cfg.certificateType || 'birth');
                body.append('print_fill', JSON.stringify(fillOverrides));

                postCreateRecord(body).then(function (data) {
                    var message = 'Record saved successfully';
                    if (data.display_name) {
                        message += ': ' + data.display_name;
                    }
                    message += '.';
                    showActionResult('success', message);
                }).catch(function (err) {
                    showActionResult('error', err.message || 'Could not save the record.');
                }).finally(function () {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                });
            });
        });
    }

    function bindActions() {
        document.querySelectorAll('[data-print-side]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openPrintWindow(btn.getAttribute('data-print-side'), btn.getAttribute('data-test') === '1');
            });
        });

        var printBoth = document.getElementById('printFrontBack');
        if (printBoth) {
            printBoth.addEventListener('click', function () {
                openPrintWindow('front', false);
                window.setTimeout(function () {
                    window.alert('Front sent to printer. Reinsert the form according to your printer orientation, then print the back page.');
                    openPrintWindow('back', false);
                }, 1200);
            });
        }

        var testBoth = document.getElementById('printTestBoth');
        if (testBoth) {
            testBoth.addEventListener('click', function () {
                openPrintWindow('front', true);
                window.setTimeout(function () {
                    openPrintWindow('back', true);
                }, 800);
            });
        }

        ['optPaternity', 'optDelayedBirth', 'optDelayedMarriage', 'optDelayedDeath', 'optInfantSection', 'optPostmortem', 'optShowBackground'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', refreshPreviews);
        });
    }

    readConfig();
    lastCalibrationStamp = '';
    try {
        lastCalibrationStamp = sessionStorage.getItem(CALIBRATION_STAMP_KEY) || '';
    } catch (err) {
        lastCalibrationStamp = '';
    }
    function initLocationPickers() {
        if (!cfg.locationsApiUrl || !window.AlcrosCascadingLocation) {
            return;
        }
        window.AlcrosCascadingLocation.init({ apiUrl: cfg.locationsApiUrl });
    }

    bindFillEditor();
    initLocationPickers();
    bindAddRecord();
    bindActions();
    applyLocalPaperCssVars();
    setPreviewView('front');
    syncPreviewFrameSize();
    window.addEventListener('resize', syncPreviewFrameSize);
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            refreshIfCalibrationChanged();
        }
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refreshIfCalibrationChanged();
        }
    });
    refreshPreviews();
})();
