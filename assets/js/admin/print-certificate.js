(function () {
    'use strict';

    var cfg = {};
    var csrfToken = '';
    var fillOverrides = {};
    var initialFillValues = {};
    var refreshTimer = null;
    var syncingPreview = false;
    var lastCalibrationStamp = '';

    var CALIBRATION_STAMP_KEY = 'alcros-print-cal-updated';

    function markCalibrationRefreshPending() {
        try {
            sessionStorage.setItem(CALIBRATION_STAMP_KEY, String(Date.now()));
        } catch (err) {
            /* ignore */
        }
    }

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
        fillOverrides = Object.assign({}, initialFillValues);
    }

    function numericId(value) {
        var id = parseInt(value, 10);
        return id > 0 ? id : 0;
    }

    function formatPrintFieldText(value) {
        return String(value || '').toUpperCase();
    }

    function collectFillOverrides() {
        document.querySelectorAll('[data-field-name]').forEach(function (input) {
            var name = input.getAttribute('data-field-name');
            if (!name) return;
            var value = formatPrintFieldText(String(input.value || '').trim());
            if (value === '') {
                delete fillOverrides[name];
                return;
            }
            fillOverrides[name] = value;
        });
    }

    function syncFillInput(fieldName, value) {
        var formatted = formatPrintFieldText(String(value || '').trim());
        var input = document.querySelector('[data-field-name="' + fieldName + '"]');
        if (input && document.activeElement !== input) {
            input.value = formatted;
        }
        if (formatted === '') {
            delete fillOverrides[fieldName];
            return;
        }
        fillOverrides[fieldName] = formatted;
    }

    function encodeFillOverrides() {
        collectFillOverrides();
        var payload = {};
        Object.keys(fillOverrides).forEach(function (key) {
            var value = String(fillOverrides[key] || '').trim();
            var initial = String(initialFillValues[key] || '').trim();
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
            postmortem: document.getElementById('optPostmortem') && document.getElementById('optPostmortem').checked,
            background: document.getElementById('optShowBackground') && document.getElementById('optShowBackground').checked
        };
    }

    function applyQueryParams(url, extra) {
        var parsed = new URL(url, window.location.href);
        var requestId = numericId(cfg.requestId);
        var recordId = numericId(cfg.recordId);
        if (requestId > 0) parsed.searchParams.set('request_id', String(requestId));
        if (recordId > 0) parsed.searchParams.set('record_id', String(recordId));

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
            parsed.searchParams.set('background', '1');
        } else {
            parsed.searchParams.delete('preview');
            if (document.getElementById('optShowBackground') && document.getElementById('optShowBackground').checked) {
                parsed.searchParams.set('background', '1');
            } else {
                parsed.searchParams.delete('background');
            }
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

        doc.querySelectorAll('.print-field--editable').forEach(function (el) {
            if (el.dataset.fillBound === '1') return;
            el.dataset.fillBound = '1';

            el.addEventListener('input', function () {
                var name = el.getAttribute('data-field');
                if (!name) return;
                syncFillInput(name, formatPrintFieldText((el.textContent || '').trim()));
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
        var paperW = parseFloat(cfg.paperWidthMm || '215.9');
        var paperH = parseFloat(cfg.paperHeightMm || '358.9');
        if (!(paperW > 0 && paperH > 0)) return;

        document.querySelectorAll('.print-cert-frame').forEach(function (iframe) {
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
            if (iframe) {
                iframe.classList.add('is-loading');
            }
        }

        function loadBack() {
            if (!back) {
                syncingPreview = false;
                return;
            }

            markLoading(back);
            var backTimer = window.setTimeout(function () {
                if (back.classList.contains('is-loading')) {
                    back.classList.remove('is-loading');
                    syncingPreview = false;
                }
            }, 60000);
            back.onload = function () {
                window.clearTimeout(backTimer);
                bindEditablePreview(back);
                syncingPreview = false;
            };
            back.onerror = function () {
                window.clearTimeout(backTimer);
                back.classList.remove('is-loading');
                syncingPreview = false;
            };
            back.src = renderUrl('back', false);
        }

        if (front) {
            markLoading(front);
            var frontTimer = window.setTimeout(function () {
                if (front.classList.contains('is-loading')) {
                    front.classList.remove('is-loading');
                }
                loadBack();
            }, 60000);
            front.onload = function () {
                window.clearTimeout(frontTimer);
                bindEditablePreview(front);
                loadBack();
            };
            front.onerror = function () {
                window.clearTimeout(frontTimer);
                front.classList.remove('is-loading');
                loadBack();
            };
            front.src = renderUrl('front', false);
        } else {
            loadBack();
        }

        syncAffidavitFillFields();
    }

    function logPrint(page, testMode, callback) {
        var body = new FormData();
        body.append('action', 'log_print');
        body.append('csrf_token', csrfToken);
        body.append('page', page);
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
        if (cfg.printMode !== 'digital') {
            flags.background = false;
        }
        var parsed = applyQueryParams(cfg.printAuthUrl || 'print_render.php', flags);
        parsed.searchParams.set('page', page);
        parsed.searchParams.delete('preview');
        if (testMode) {
            parsed.searchParams.set('test', '1');
        } else {
            parsed.searchParams.delete('test');
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
                var value = formatPrintFieldText(String(input.value || '').trim());
                if (input.value !== value) {
                    input.value = value;
                }
                if (value === '') {
                    delete fillOverrides[name];
                } else {
                    fillOverrides[name] = value;
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
                    input.value = fillOverrides[name] || '';
                });
                refreshPreviews();
            });
        }

        document.querySelectorAll('[data-fill-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var side = btn.getAttribute('data-fill-tab');
                document.querySelectorAll('[data-fill-tab]').forEach(function (tab) {
                    var active = tab.getAttribute('data-fill-tab') === side;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                document.querySelectorAll('[data-fill-panel]').forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-fill-panel') !== side;
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
    bindFillEditor();
    bindActions();
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
