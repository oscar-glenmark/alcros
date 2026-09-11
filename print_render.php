<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/printing.php';
require_once __DIR__ . '/includes/certification_print.php';
requireStaffLogin();

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$pdo = getDB();
@set_time_limit(120);
try {
    ensurePrintTables($pdo);
} catch (Throwable $e) {
    // Continue if tables already exist.
}

$pageSide = ($_GET['page'] ?? 'front') === 'back' ? 'back' : 'front';
$testMode = !empty($_GET['test']);
$mode = $_GET['mode'] ?? printMode();
if (!in_array($mode, ['preprinted', 'digital'], true)) {
    $mode = printMode();
}

$calibrationPreview = !empty($_GET['calibration_preview']);
$requestId = (int) ($_GET['request_id'] ?? 0);
$recordId = (int) ($_GET['record_id'] ?? 0);
$documentKind = normalizePrintDocumentKind($_GET['kind'] ?? 'certificate');

if ($calibrationPreview) {
    requireAdmin();
    $certificateType = (string) ($_GET['type'] ?? 'birth');
    if (!in_array($certificateType, printCertificateTypes(), true)) {
        $certificateType = 'birth';
    }
    if ($documentKind === 'certification') {
        $record = certificationCalibrationSampleRecord($certificateType);
        $pageSide = 'front';
    } else {
        $record = printCalibrationSampleRecord($certificateType);
    }
    $request = null;
} else {
    if ($documentKind === 'certification') {
        $context = printCertificationContext($pdo, $recordId, $requestId);
    } else {
        $context = printRequestContext($pdo, $requestId ?: null, $recordId ?: null);
    }
    if (!$context['ok']) {
        http_response_code(404);
        echo '<!DOCTYPE html><html><body><p>' . htmlspecialchars($context['error']) . '</p></body></html>';
        exit;
    }

    $record = $context['record'];
    $request = $context['request'];
    $certificateType = $context['certificate_type'];
}

$printOptions = [
    'document_kind'                     => $documentKind,
    'include_paternity_affidavit'       => !empty($_GET['paternity']),
    'include_delayed_birth_affidavit'   => !empty($_GET['delayed_birth']),
    'include_delayed_marriage_affidavit'=> !empty($_GET['delayed_marriage']),
    'include_delayed_death_affidavit'   => !empty($_GET['delayed_death']),
    'include_infant_section'            => !empty($_GET['infant_section']),
    'include_postmortem'                => !empty($_GET['postmortem']),
    'test_mode'                         => $testMode,
    'mode'                              => $mode,
];
if ($calibrationPreview) {
    $printOptions['include_disabled_fields'] = true;
    $printOptions['keep_empty'] = true;
}

$printData = printCertificate($pdo, $certificateType, $pageSide, $record, $printOptions);
if (!$printData) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><p>Print template not found.</p></body></html>';
    exit;
}

$calLiveRaw = '';
if ($calibrationPreview) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireStaffPostCsrf();
        $calLiveRaw = (string) ($_POST['cal_live'] ?? '');
    } elseif (!empty($_GET['cal_live'])) {
        $calLiveRaw = (string) $_GET['cal_live'];
    }
    if ($calLiveRaw !== '') {
        $live = printParseCalibrationLivePayload($calLiveRaw);
        $printData = printApplyCalibrationLiveOverrides($printData, $live);
    }
}

$fillOverrides = printDecodeFillOverrides($_GET['fill'] ?? null);
if ($fillOverrides !== []) {
    $printData['values'] = printApplyFillOverrides($printData['values'], $fillOverrides);
}

if (!empty($_GET['log']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireStaffPostCsrf();
    $printModeValue = $testMode ? 'test' : 'production';
    logPrintJob($pdo, [
        'request_id'       => $request['id'] ?? null,
        'civil_record_id'  => $record['id'] ?? null,
        'template_id'      => (int) $printData['template']['id'],
        'page_side'        => $pageSide,
        'certificate_type' => $certificateType,
        'registry_number'  => $record['registry_number'] ?? null,
        'print_mode'       => $printModeValue,
        'copies'           => max(1, (int) ($_POST['copies'] ?? 1)),
        'notes'            => $_POST['notes'] ?? null,
    ]);
    if ($request && !$testMode && !empty($_POST['update_status'])) {
        advanceRequestAfterPrint($pdo, (int) $request['id'], false);
    }
}

$template = $printData['template'];
$paperW = (float) $template['paper_width_mm'];
$paperH = (float) $template['paper_height_mm'];
$pageCssSize = printPageCssSize($paperW, $paperH);
$printerSetupCss = printPrinterSetupStylesheet();
$printPaperPrefs = printPaperPreferences();
$printCsrfToken = csrfToken();
$isPreview = !empty($_GET['preview']);
$autoPrint = !empty($_GET['autoprint']);
$showBackground = !empty($_GET['background']) || $isPreview || $autoPrint;
$overlayHtml = renderPrintOverlayHtml($printData, [
    'mode'                    => $mode,
    'test_mode'               => $testMode,
    'show_background'         => $showBackground,
    'editable'                => $isPreview && !$autoPrint && !$testMode && !$calibrationPreview,
    'prefer_scan_background'  => $showBackground,
    'calibration_preview'     => $calibrationPreview,
    'use_effective_positions' => !empty($printData['use_effective_positions']),
]);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print <?= htmlspecialchars(ucfirst($certificateType)) ?> · <?= htmlspecialchars(ucfirst($pageSide)) ?></title>
    <?= $printerSetupCss ?>
    <style>
        /* Legal / 8.5×14.1 in — browsers recognize this better than raw mm (avoids Postcard default). */
        @page {
            size: legal portrait;
            size: <?= htmlspecialchars($pageCssSize) ?> portrait;
            margin: 0;
        }
        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
        }
        body.print-render--preview,
        body.print-render--autoprint {
            background: #e2e8f0;
            padding: 12mm 0;
            min-height: 100vh;
            box-sizing: border-box;
        }
        body.print-render--autoprint .print-render-wrap {
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.18);
        }
        .print-render-wrap {
            margin: 0 auto;
            width: <?= $paperW ?>mm;
        }
        .print-sheet {
            width: <?= $paperW ?>mm !important;
            height: <?= $paperH ?>mm !important;
            max-height: <?= $paperH ?>mm !important;
            overflow: hidden !important;
            position: relative;
            background: #fff;
            box-sizing: border-box;
        }
        .print-setup-notice {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: rgba(15, 23, 42, 0.72);
        }
        body.print-render--autoprint .print-setup-notice {
            background: rgba(15, 23, 42, 0.28);
            pointer-events: none;
        }
        .print-setup-notice__card {
            max-width: 30rem;
            background: #fff;
            border-radius: 1rem;
            padding: 1.25rem 1.5rem 1.5rem;
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.28);
            font: 14px/1.5 system-ui, sans-serif;
            color: #0f172a;
        }
        body.print-render--autoprint .print-setup-notice__card {
            pointer-events: auto;
        }
        .print-setup-notice__card button {
            width: 100%;
            border: 0;
            border-radius: 0.625rem;
            padding: 0.75rem 1rem;
            background: #2563eb;
            color: #fff;
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 1rem;
        }
        @media print {
            .print-setup-notice {
                display: none !important;
            }
            html, body {
                width: <?= $paperW ?>mm !important;
                height: <?= $paperH ?>mm !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
            }
            body.print-render--preview {
                background: #fff;
                padding: 0;
            }
            .print-render-wrap {
                margin: 0 !important;
                width: <?= $paperW ?>mm !important;
                height: <?= $paperH ?>mm !important;
                overflow: hidden !important;
                page-break-after: avoid;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .print-sheet {
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .print-test-marker {
                display: none !important;
            }
            .print-sheet__background {
                display: none !important;
            }
            .print-field--editable {
                outline: none !important;
                box-shadow: none !important;
                background: transparent !important;
                border: none !important;
            }
        }
        .print-field {
            font-weight: 700 !important;
            text-transform: uppercase !important;
        }
        body.print-render--certification .print-field {
            font-weight: 700 !important;
            text-transform: none !important;
        }
        .print-field--editable:empty::before {
            font-weight: 400;
        }
        .print-field--editable {
            cursor: text;
            background: rgba(255, 255, 255, 0.55);
            border: 1px dashed rgba(37, 99, 235, 0.35);
            border-radius: 1px;
        }
        .print-field--editable:focus {
            outline: 2px solid rgba(37, 99, 235, 0.45);
            background: rgba(255, 255, 255, 0.85);
        }
        .print-field--editable:empty::before {
            content: 'Click to type…';
            color: #94a3b8;
            font-style: italic;
            pointer-events: none;
        }
    </style>
</head>
<body class="<?= trim(($isPreview ? 'print-render--preview ' : '') . ($autoPrint ? 'print-render--autoprint ' : '') . ($documentKind === 'certification' ? ' print-render--certification' : '')) ?>">
    <?php if ($autoPrint && $documentKind !== 'certification'): ?>
    <div class="print-setup-notice" id="printSetupNotice" role="dialog" aria-labelledby="printSetupTitle">
        <div class="print-setup-notice__card">
            <?php renderPrintBuiltInPrinterSetup([
                'variant'           => 'dialog',
                'default_width_mm'  => $paperW,
                'default_height_mm' => $paperH,
            ]); ?>
            <p style="margin:0;color:#64748b;font-size:12px;">If the print dialog shows “Postcard” or “2 sheets”, set the paper size above and match it in your printer dialog before continuing.</p>
            <button type="button" id="printSetupContinue">Continue to Print</button>
        </div>
    </div>
    <?php endif; ?>
    <div class="print-render-wrap">
        <?= $overlayHtml ?>
    </div>
    <?php if ($autoPrint): ?>
    <?php
    $templatePaperForPrint = [
        'preset'    => printPaperPresetKeyForSize($paperW, $paperH),
        'width_mm'  => $paperW,
        'height_mm' => $paperH,
    ];
    ?>
    <script>
        (function () {
            var templatePaper = <?= json_encode($templatePaperForPrint, JSON_UNESCAPED_UNICODE) ?>;
            var serverPaper = <?= json_encode($printPaperPrefs, JSON_UNESCAPED_UNICODE) ?>;
            var lockTemplatePaper = <?= $documentKind === 'certification' ? 'true' : 'false' ?>;
            var skipSetupDialog = <?= $documentKind === 'certification' ? 'true' : 'false' ?>;
            var savePaperPrefs = !lockTemplatePaper;
            var csrfToken = <?= json_encode($printCsrfToken) ?>;
            var notice = document.getElementById('printSetupNotice');
            var btn = document.getElementById('printSetupContinue');
            var presetEl = document.querySelector('[data-print-paper-preset]');
            var widthEl = document.querySelector('[data-print-paper-width]');
            var heightEl = document.querySelector('[data-print-paper-height]');
            var hintEl = document.querySelector('[data-print-printer-hint]');
            var presetsJson = document.querySelector('[data-print-paper-presets]');
            var presets = {};
            var dynamicStyle = document.getElementById('printDynamicPaperStyle');

            if (presetsJson) {
                try {
                    presets = JSON.parse(presetsJson.textContent || '{}');
                } catch (err) {
                    presets = {};
                }
            }

            function readMm(input) {
                var value = parseFloat(input && input.value ? input.value : '');
                return value > 0 ? value : 0;
            }

            function mmToIn(mm) {
                return Math.round((mm / 25.4) * 1000) / 1000;
            }

            function applyPreset(key) {
                var preset = presets[key];
                if (!preset || key === 'custom') {
                    if (widthEl) widthEl.disabled = false;
                    if (heightEl) heightEl.disabled = false;
                    if (hintEl) hintEl.textContent = preset && preset.printer_hint ? preset.printer_hint : 'Custom or User defined';
                    return;
                }
                if (widthEl) {
                    widthEl.value = String(preset.width_mm);
                    widthEl.disabled = true;
                }
                if (heightEl) {
                    heightEl.value = String(preset.height_mm);
                    heightEl.disabled = true;
                }
                if (hintEl && preset.printer_hint) {
                    hintEl.textContent = preset.printer_hint;
                }
            }

            function applyPaperSize(persistPrefs) {
                var widthMm = lockTemplatePaper
                    ? Number(templatePaper.width_mm || 0)
                    : readMm(widthEl);
                var heightMm = lockTemplatePaper
                    ? Number(templatePaper.height_mm || 0)
                    : readMm(heightEl);
                if (!(widthMm > 0 && heightMm > 0)) {
                    return false;
                }

                var widthIn = mmToIn(widthMm);
                var heightIn = mmToIn(heightMm);
                var css = '@page { size: ' + widthIn + 'in ' + heightIn + 'in portrait; margin: 0; }' +
                    'html, body { width: ' + widthMm + 'mm !important; height: ' + heightMm + 'mm !important; }' +
                    '.print-render-wrap { width: ' + widthMm + 'mm !important; height: ' + heightMm + 'mm !important; }' +
                    '.print-sheet { width: ' + widthMm + 'mm !important; height: ' + heightMm + 'mm !important; max-height: ' + heightMm + 'mm !important; }';

                if (!dynamicStyle) {
                    dynamicStyle = document.createElement('style');
                    dynamicStyle.id = 'printDynamicPaperStyle';
                    document.head.appendChild(dynamicStyle);
                }
                dynamicStyle.textContent = css;

                if (persistPrefs && savePaperPrefs) {
                    var form = new FormData();
                    form.append('action', 'save_paper_preferences');
                    form.append('csrf_token', csrfToken);
                    form.append('preset', presetEl ? presetEl.value : 'custom');
                    form.append('width_mm', String(widthMm));
                    form.append('height_mm', String(heightMm));
                    fetch('api/print.php', { method: 'POST', body: form, credentials: 'same-origin' }).catch(function () {});
                }

                return true;
            }

            function resolveJobPaper() {
                if (lockTemplatePaper) {
                    return templatePaper;
                }
                if (serverPaper && serverPaper.width_mm && serverPaper.height_mm) {
                    var dw = Math.abs(Number(serverPaper.width_mm) - Number(templatePaper.width_mm));
                    var dh = Math.abs(Number(serverPaper.height_mm) - Number(templatePaper.height_mm));
                    if (dw <= 1 && dh <= 1) {
                        return serverPaper;
                    }
                }
                return templatePaper;
            }

            function initTemplatePaperFields() {
                if (!presetEl || !templatePaper || !templatePaper.width_mm) {
                    return;
                }

                var paper = resolveJobPaper();
                var presetKey = paper.preset || templatePaper.preset || 'custom';
                if (presets[presetKey]) {
                    presetEl.value = presetKey;
                    applyPreset(presetKey);
                } else {
                    presetEl.value = 'custom';
                    applyPreset('custom');
                }

                if (widthEl) {
                    widthEl.value = String(paper.width_mm);
                    widthEl.disabled = lockTemplatePaper;
                }
                if (heightEl) {
                    heightEl.value = String(paper.height_mm);
                    heightEl.disabled = lockTemplatePaper;
                }
                if (presetEl) {
                    presetEl.disabled = lockTemplatePaper;
                }
                if (hintEl && lockTemplatePaper) {
                    hintEl.textContent = 'Use these exact certification dimensions in your printer dialog.';
                }

                applyPaperSize(false);
            }

            if (presetEl) {
                presetEl.addEventListener('change', function () {
                    if (lockTemplatePaper) {
                        initTemplatePaperFields();
                        return;
                    }
                    applyPreset(presetEl.value);
                    applyPaperSize(false);
                });
                initTemplatePaperFields();
            }

            function startPrint() {
                if (!applyPaperSize(true)) {
                    window.alert('Enter a valid paper width and height in millimeters.');
                    return;
                }
                if (notice) notice.style.display = 'none';
                if (window.AlcrosPrintFitText) {
                    AlcrosPrintFitText.fitAll(document, function () {
                        window.print();
                    });
                    return;
                }
                window.print();
            }

            if (skipSetupDialog) {
                applyPaperSize(false);
                window.addEventListener('load', function () {
                    window.setTimeout(startPrint, 600);
                });
            } else if (btn) {
                btn.addEventListener('click', startPrint);
            } else {
                window.addEventListener('load', function () { window.setTimeout(startPrint, 600); });
            }
        })();
    </script>
    <?php endif; ?>
    <?php if ($autoPrint): ?>
    <?php require_once __DIR__ . '/includes/scripts.php'; ?>
    <?= scriptTag('admin/print-fit-text.js') ?>
    <script>
        window.addEventListener('load', function () {
            if (window.AlcrosPrintFitText) {
                AlcrosPrintFitText.fitAll(document);
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
