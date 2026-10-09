(function () {
    'use strict';

    var cfg = {};
    var csrfToken = '';
    var fillOverrides = {};
    var initialFillValues = {};
    var refreshTimer = null;
    var syncingPreview = false;
    var lastCalibrationStamp = '';
    var previewZoomLevel = 1;
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

    var PRINT_FILL_MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    var PRINT_FILL_BIRTH_TYPES = ['Single', 'Twin', 'Triplet', 'Quadruplet', 'Quintuplet'];

    function isPrintFillBirthTypeField(fieldName) {
        var name = String(fieldName || '');
        return name === 'birth_type' || name === 'child_birth_type';
    }

    function isPrintFillChoiceField(fieldName) {
        var name = String(fieldName || '');
        if (name === 'sex' || /_sex$/.test(name)) {
            return true;
        }
        if (isPrintFillBirthTypeField(name)) {
            return true;
        }
        if (/_month$/.test(name)) {
            return true;
        }
        if (/_day$/.test(name)) {
            return true;
        }
        return /_year$/.test(name);
    }

    function normalizePrintFillChoiceValue(value, fieldName) {
        var trimmed = String(value || '').trim();
        if (!trimmed || !isPrintFillChoiceField(fieldName)) {
            return trimmed;
        }
        if (fieldName === 'sex' || /_sex$/.test(fieldName)) {
            var sex = trimmed.toLowerCase();
            if (sex === 'male') {
                return 'Male';
            }
            if (sex === 'female') {
                return 'Female';
            }
            return trimmed;
        }
        if (isPrintFillBirthTypeField(fieldName)) {
            var birthLower = trimmed.toLowerCase();
            for (var b = 0; b < PRINT_FILL_BIRTH_TYPES.length; b++) {
                if (PRINT_FILL_BIRTH_TYPES[b].toLowerCase() === birthLower) {
                    return PRINT_FILL_BIRTH_TYPES[b];
                }
            }
            return trimmed;
        }
        if (/_day$/.test(fieldName)) {
            var dayNum = parseInt(trimmed, 10);
            if (dayNum >= 1 && dayNum <= 31) {
                return dayNum < 10 ? '0' + dayNum : String(dayNum);
            }
            return trimmed;
        }
        if (/_year$/.test(fieldName)) {
            var yearDigits = trimmed.replace(/\D/g, '');
            return yearDigits.length === 4 ? yearDigits : trimmed;
        }
        var lower = trimmed.toLowerCase();
        for (var i = 0; i < PRINT_FILL_MONTHS.length; i++) {
            if (PRINT_FILL_MONTHS[i].toLowerCase() === lower) {
                return PRINT_FILL_MONTHS[i];
            }
        }
        return trimmed;
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
        if (window.AlcrosPrintFillDates && AlcrosPrintFillDates.isPrintFillCalendarDateField(fieldName)) {
            return AlcrosPrintFillDates.storageValue(trimmed, fieldName);
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
        if (window.AlcrosPrintFillDates && AlcrosPrintFillDates.isPrintFillCalendarDateField(fieldName)) {
            return AlcrosPrintFillDates.storageValue(value, fieldName);
        }
        if (isPrintFillChoiceField(fieldName)) {
            return normalizePrintFillChoiceValue(value, fieldName);
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
        var input = document.querySelector('[data-field-name="' + fieldName + '"]');
        if (input && document.activeElement !== input) {
            if (window.AlcrosPrintFillDates && AlcrosPrintFillDates.isPrintFillCalendarDateField(fieldName)) {
                input.value = AlcrosPrintFillDates.certDateToIso(stored) || '';
            } else {
                input.value = formatPrintFieldText(stored, fieldName);
            }
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

    function isCertificationDocument() {
        return cfg.documentKind === 'certification';
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

    function isBackFillTabActive() {
        return !!(
            document.querySelector('.print-cert-fill-tab[data-fill-tab="back"].is-active')
            || document.querySelector('.records-entry-print-fill__tab[data-entry-fill-tab="back"].is-active')
        );
    }

    function syncBackPageOptionsVisibility() {
        var section = document.getElementById('backPageOptions');
        if (!section) return;
        section.hidden = !isBackFillTabActive();
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

    function hasScaledPreviewViewport() {
        return !!document.querySelector('[data-preview-viewport]');
    }

    function localPreviewPaperPixels() {
        var section = document.querySelector('.print-cert-previews');
        var defaultW = cfg.documentKind === 'certification' ? '210' : '215.9';
        var defaultH = cfg.documentKind === 'certification' ? '297' : '358.9';
        var paperW = parseFloat((section && section.getAttribute('data-paper-w')) || cfg.paperWidthMm || defaultW);
        var paperH = parseFloat((section && section.getAttribute('data-paper-h')) || cfg.paperHeightMm || defaultH);
        var pxPerMm = 96 / 25.4;
        return {
            width: Math.max(Math.round(paperW * pxPerMm), 1),
            height: Math.max(Math.round(paperH * pxPerMm), 1)
        };
    }

    function localPreviewContentSize(iframe) {
        var size = localPreviewPaperPixels();
        var width = size.width;
        var height = size.height;
        try {
            var doc = iframe.contentDocument;
            if (doc && doc.body) {
                var root = doc.documentElement;
                var body = doc.body;
                var sheet = doc.querySelector('.print-sheet');
                var contentW = Math.ceil(Math.max(
                    sheet ? sheet.scrollWidth : 0,
                    sheet ? sheet.offsetWidth : 0,
                    root ? root.scrollWidth : 0,
                    root ? root.offsetWidth : 0,
                    body.scrollWidth,
                    body.offsetWidth
                ));
                var contentH = Math.ceil(Math.max(
                    sheet ? sheet.scrollHeight : 0,
                    sheet ? sheet.offsetHeight : 0,
                    root ? root.scrollHeight : 0,
                    root ? root.offsetHeight : 0,
                    body.scrollHeight,
                    body.offsetHeight
                ));
                if (contentW > 0) width = contentW;
                if (contentH > 0) height = contentH;
            }
        } catch (err) {
            // ignore
        }
        return { width: width, height: height };
    }

    function bindPreviewViewportWheel(iframe) {
        if (!iframe || iframe.dataset.viewportWheelBound === '1') return;
        var viewport = iframe.closest('[data-preview-viewport]');
        if (!viewport) return;
        iframe.dataset.viewportWheelBound = '1';

        function forwardWheel(e) {
            var maxScrollTop = viewport.scrollHeight - viewport.clientHeight;
            var maxScrollLeft = viewport.scrollWidth - viewport.clientWidth;
            if (maxScrollTop <= 0 && maxScrollLeft <= 0) return;
            if (e.deltaY && maxScrollTop > 0) {
                viewport.scrollTop = Math.min(maxScrollTop, Math.max(0, viewport.scrollTop + e.deltaY));
            }
            if (e.deltaX && maxScrollLeft > 0) {
                viewport.scrollLeft = Math.min(maxScrollLeft, Math.max(0, viewport.scrollLeft + e.deltaX));
            }
            e.preventDefault();
        }

        iframe.addEventListener('wheel', forwardWheel, { passive: false });
        try {
            var doc = iframe.contentDocument;
            if (doc) {
                doc.addEventListener('wheel', forwardWheel, { passive: false });
            }
        } catch (err) {
            // ignore
        }
    }

    function computePreviewFitScale(naturalWidth, naturalHeight, availableWidth, availableHeight, coverGray) {
        var widthScale = availableWidth / naturalWidth;
        var heightScale = availableHeight / naturalHeight;
        var fitScale;
        if (coverGray) {
            fitScale = Math.max(widthScale, heightScale);
        } else {
            // Bond certificates: fit width so tall forms (e.g. death) stay fully scrollable vertically.
            fitScale = widthScale;
            if (!isFinite(fitScale) || fitScale <= 0) {
                fitScale = Math.min(widthScale, heightScale);
            }
        }
        var scale = fitScale * previewZoomLevel;
        scale = Math.min(Math.max(scale, 0.05), 2);
        return Math.round(scale * 50) / 50;
    }

    function fitCertificationPreviewFrame(side) {
        var viewport = document.querySelector('[data-preview-viewport="' + side + '"]');
        var scaler = document.querySelector('[data-preview-scaler="' + side + '"]');
        var iframe = side === 'back' ? document.getElementById('previewBack') : document.getElementById('previewFront');
        if (!viewport || !scaler || !iframe) return;

        scaler.style.transform = 'none';
        iframe.style.position = '';
        iframe.style.top = '';
        iframe.style.left = '';
        iframe.style.transform = 'none';
        iframe.style.width = '';
        iframe.style.height = '';

        var paperPx = localPreviewPaperPixels();
        var contentSize = localPreviewContentSize(iframe);
        var naturalWidth = Math.max(paperPx.width, contentSize.width);
        var naturalHeight = Math.max(paperPx.height, contentSize.height);

        var availableWidth = Math.max(viewport.clientWidth, 120);
        var availableHeight = Math.max(viewport.clientHeight, 120);
        var scale = computePreviewFitScale(paperPx.width, paperPx.height, availableWidth, availableHeight, true);
        var scaledWidth = Math.ceil(naturalWidth * scale);
        var scaledHeight = Math.ceil(naturalHeight * scale) + 4;

        viewport.style.overflowX = 'hidden';
        viewport.style.overflowY = 'auto';

        scaler.style.transform = 'none';
        scaler.style.overflow = 'hidden';
        scaler.style.width = scaledWidth + 'px';
        scaler.style.height = scaledHeight + 'px';
        scaler.style.flexShrink = '0';
        scaler.style.position = 'relative';
        scaler.style.margin = '0 auto';

        iframe.style.width = naturalWidth + 'px';
        iframe.style.height = naturalHeight + 'px';
        iframe.style.position = 'static';
        iframe.style.transform = 'scale(' + scale.toFixed(2) + ') translateZ(0)';
        iframe.style.transformOrigin = 'top left';
    }

    function fitBondCertificatePreviewFrame(side) {
        if (!isLocalPreviewSideVisible(side)) return;

        var viewport = document.querySelector('[data-preview-viewport="' + side + '"]');
        var scaler = document.querySelector('[data-preview-scaler="' + side + '"]');
        var iframe = side === 'back' ? document.getElementById('previewBack') : document.getElementById('previewFront');
        if (!viewport || !scaler || !iframe) return;

        scaler.style.transform = 'none';
        iframe.style.position = 'static';
        iframe.style.transform = 'none';
        iframe.style.width = '';
        iframe.style.height = '';
        iframe.style.top = '';
        iframe.style.left = '';

        var contentSize = localPreviewContentSize(iframe);
        var naturalWidth = contentSize.width;
        var naturalHeight = contentSize.height;
        var layoutW = iframe.offsetWidth;
        var layoutH = iframe.offsetHeight;
        if (layoutW > naturalWidth) naturalWidth = layoutW;
        if (layoutH > naturalHeight) naturalHeight = layoutH;

        var availableWidth = Math.max(viewport.clientWidth, 120);
        var availableHeight = Math.max(viewport.clientHeight, 120);
        var scale = computePreviewFitScale(naturalWidth, naturalHeight, availableWidth, availableHeight, false);
        var scaledWidth = Math.ceil(naturalWidth * scale);
        var scaledHeight = Math.ceil(naturalHeight * scale) + 4;

        viewport.style.overflow = 'auto';

        scaler.style.transform = 'none';
        scaler.style.overflow = 'visible';
        scaler.style.width = scaledWidth + 'px';
        scaler.style.height = scaledHeight + 'px';
        scaler.style.flexShrink = '0';
        scaler.style.position = 'relative';

        iframe.style.width = naturalWidth + 'px';
        iframe.style.height = naturalHeight + 'px';
        iframe.style.position = 'absolute';
        iframe.style.top = '0';
        iframe.style.left = '0';
        iframe.style.transform = 'scale(' + scale.toFixed(2) + ') translateZ(0)';
        iframe.style.transformOrigin = 'top left';

        if (scaledWidth <= availableWidth && scaledHeight <= availableHeight) {
            scaler.style.margin = 'auto';
        } else {
            scaler.style.margin = '0';
        }
    }

    function fitLocalPreviewFrame(side) {
        if (isCertificationDocument()) {
            fitCertificationPreviewFrame(side);
            return;
        }
        fitBondCertificatePreviewFrame(side);
    }

    function fitLocalPreviewFrames() {
        if (!hasScaledPreviewViewport()) return;
        applyLocalPaperCssVars();
        fitLocalPreviewFrame('front');
        fitLocalPreviewFrame('back');
    }

    function bindPreviewViewportResizeObservers() {
        if (!hasScaledPreviewViewport() || typeof window.ResizeObserver !== 'function') {
            return;
        }
        document.querySelectorAll('[data-preview-viewport]').forEach(function (viewport) {
            if (viewport.dataset.resizeObserved === '1') {
                return;
            }
            viewport.dataset.resizeObserved = '1';
            var ro = new ResizeObserver(function () {
                window.requestAnimationFrame(fitLocalPreviewFrames);
            });
            ro.observe(viewport);
        });
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
            if (hasScaledPreviewViewport() && (isLocalCertificate() || isCertificationDocument())) {
                parsed.searchParams.set('embedded', '1');
            }
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

    function isPrintFillInFormBackPanel(el) {
        if (!el || !el.closest) return false;
        return !!(
            el.closest('.print-cert-fill-grid[data-fill-panel="back"]')
            || el.closest('.records-entry-print-fill__panel[data-entry-fill-panel="back"]')
        );
    }

    function optionalSectionEnabled(group, flags) {
        if (!group) {
            return true;
        }
        return !!flags[group];
    }

    /** Back-page fill panels: visible on Back tab; optional sections also require Back Page Options checkboxes. */
    function syncAffidavitFillFields() {
        var flags = optionFlags();
        var backFillActive = isBackFillTabActive();
        document.querySelectorAll('[data-fill-group]').forEach(function (el) {
            var group = el.getAttribute('data-fill-group');
            if (isPrintFillInFormBackPanel(el)) {
                el.hidden = !backFillActive || !optionalSectionEnabled(group, flags);
                return;
            }
            el.hidden = !optionalSectionEnabled(group, flags);
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

        if (hasScaledPreviewViewport()) {
            if (!isCertificationDocument()) {
                bindPreviewViewportWheel(iframe);
            }
            window.requestAnimationFrame(function () {
                fitLocalPreviewFrames();
                window.requestAnimationFrame(fitLocalPreviewFrames);
            });
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
        if (hasScaledPreviewViewport()) {
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

        fetch(apiPrintRequestUrl(), {
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

    function bindFillFieldControl(input) {
        function applyFillFieldValue() {
            var name = input.getAttribute('data-field-name');
            if (!name) {
                return;
            }
            var stored = storedPrintFieldValue(input.value, name);
            if (window.AlcrosPrintFillDates && AlcrosPrintFillDates.isPrintFillCalendarDateField(name)) {
                if (stored === '') {
                    delete fillOverrides[name];
                } else {
                    fillOverrides[name] = stored;
                }
                schedulePreviewRefresh(true);
                return;
            }
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
        }

        input.addEventListener('input', applyFillFieldValue);
        input.addEventListener('change', applyFillFieldValue);
    }

    function bindFillEditor() {
        document.querySelectorAll('[data-field-name]').forEach(function (input) {
            bindFillFieldControl(input);
        });

        var resetBtn = document.getElementById('resetFillData');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                fillOverrides = Object.assign({}, initialFillValues);
                document.querySelectorAll('[data-field-name]').forEach(function (input) {
                    var name = input.getAttribute('data-field-name');
                    var stored = fillOverrides[name] || '';
                    if (!stored) {
                        input.value = '';
                        return;
                    }
                    if (window.AlcrosPrintFillDates && AlcrosPrintFillDates.isPrintFillCalendarDateField(name)) {
                        input.value = AlcrosPrintFillDates.certDateToIso(stored) || '';
                        return;
                    }
                    input.value = formatPrintFieldText(stored, name);
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

        document.querySelectorAll('.print-cert-fill [data-fill-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var fillRoot = btn.closest('.print-cert-fill');
                if (!fillRoot) return;
                var side = btn.getAttribute('data-fill-tab');
                if (!side) return;
                syncCalibrationLink(side);
                fillRoot.querySelectorAll('[data-fill-tab]').forEach(function (tab) {
                    var active = tab.getAttribute('data-fill-tab') === side;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                fillRoot.querySelectorAll('[data-fill-panel]').forEach(function (panel) {
                    var isActive = panel.getAttribute('data-fill-panel') === side;
                    panel.hidden = !isActive;
                    panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                });
                syncAffidavitFillFields();
                syncBackPageOptionsVisibility();
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

    function activateDocumentFillTab(side) {
        var btn = document.querySelector('.print-cert-fill [data-fill-tab="' + side + '"]');
        if (btn) {
            btn.click();
        }
    }

    function validateDocumentFillBeforeSave() {
        var V = window.AlcrosCivilRecordEntryValidation;
        if (!V) {
            return false;
        }
        var fillRoot = document.querySelector('.print-cert-fill');
        var blocked = V.validateManualEntrySections(cfg.certificateType || 'birth', cfg.manualEntryRequiredFields || {}, {
            root: fillRoot || document,
            resolveInput: function (fieldName) {
                return document.querySelector('.print-cert-fill [data-field-name="' + fieldName + '"]');
            }
        });
        if (blocked) {
            activateDocumentFillTab('front');
            window.setTimeout(function () {
                V.scrollToFirstError(fillRoot || document);
            }, 50);
        }
        return blocked;
    }

    function bindAddRecord() {
        if (!cfg.manualMode || !cfg.createRecordApiUrl) {
            return;
        }

        var btn = document.getElementById('addRecordFromDocument');
        if (!btn) {
            return;
        }

        var fillRoot = document.querySelector('.print-cert-fill');
        if (fillRoot && window.AlcrosCivilRecordEntryValidation) {
            AlcrosCivilRecordEntryValidation.bindLiveClear(fillRoot);
        }

        btn.addEventListener('click', function () {
            collectFillOverrides();
            if (validateDocumentFillBeforeSave()) {
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
                    if (data.registry_number) {
                        message += ' Registry number: ' + data.registry_number + '.';
                        syncFillInput('registry_number', data.registry_number);
                        refreshPreviews();
                    } else {
                        message += '.';
                    }
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
            if (!el) return;
            el.addEventListener('change', function () {
                syncAffidavitFillFields();
                refreshPreviews();
            });
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
    syncAffidavitFillFields();
    syncBackPageOptionsVisibility();
    syncPreviewFrameSize();
    bindPreviewViewportResizeObservers();
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
