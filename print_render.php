<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/printing.php';
requireStaffLogin();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$pdo = getDB();
ensurePrintTables($pdo);

$pageSide = ($_GET['page'] ?? 'front') === 'back' ? 'back' : 'front';
$testMode = !empty($_GET['test']);
$showBackground = !empty($_GET['background']) || printMode() === 'digital' || !empty($_GET['preview']);
$mode = $_GET['mode'] ?? printMode();
if (!in_array($mode, ['preprinted', 'digital'], true)) {
    $mode = printMode();
}

$calibrationPreview = !empty($_GET['calibration_preview']);
$requestId = (int) ($_GET['request_id'] ?? 0);
$recordId = (int) ($_GET['record_id'] ?? 0);

if ($calibrationPreview) {
    requireAdmin();
    $certificateType = (string) ($_GET['type'] ?? 'birth');
    if (!in_array($certificateType, printCertificateTypes(), true)) {
        $certificateType = 'birth';
    }
    $record = printCalibrationSampleRecord($certificateType);
    $request = null;
} else {
    $context = printRequestContext($pdo, $requestId ?: null, $recordId ?: null);
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
    'include_paternity_affidavit'       => !empty($_GET['paternity']),
    'include_delayed_birth_affidavit'   => !empty($_GET['delayed_birth']),
    'include_delayed_marriage_affidavit'=> !empty($_GET['delayed_marriage']),
    'include_delayed_death_affidavit'   => !empty($_GET['delayed_death']),
    'include_infant_section'            => !empty($_GET['infant_section']),
    'include_postmortem'                => !empty($_GET['postmortem']),
    'test_mode'                         => $testMode,
    'mode'                              => $mode,
];

$printData = printCertificate($pdo, $certificateType, $pageSide, $record, $printOptions);
if (!$printData) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><p>Print template not found.</p></body></html>';
    exit;
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
$pageCssSize = printPageCssSize();
$paperHint = printPaperSizePrinterHint();
$isPreview = !empty($_GET['preview']);
$autoPrint = !empty($_GET['autoprint']);
$overlayHtml = renderPrintOverlayHtml($printData, [
    'mode'                    => $mode,
    'test_mode'               => $testMode,
    'show_background'         => $showBackground,
    'editable'                => $isPreview && !$autoPrint && !$testMode && !$calibrationPreview,
    'prefer_scan_background'  => $isPreview && !$calibrationPreview,
]);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print <?= htmlspecialchars(ucfirst($certificateType)) ?> · <?= htmlspecialchars(ucfirst($pageSide)) ?></title>
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
        body.print-render--preview {
            background: #e2e8f0;
            padding: 12mm 0;
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
        .print-setup-notice__card {
            max-width: 28rem;
            background: #fff;
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.28);
            font: 14px/1.5 system-ui, sans-serif;
            color: #0f172a;
        }
        .print-setup-notice__card h2 {
            margin: 0 0 0.75rem;
            font-size: 1rem;
            font-weight: 800;
        }
        .print-setup-notice__card ul {
            margin: 0 0 1rem;
            padding-left: 1.25rem;
        }
        .print-setup-notice__card li {
            margin-bottom: 0.35rem;
        }
        .print-setup-notice__card strong {
            color: #b45309;
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
            body.print-mode-digital .print-sheet__background {
                display: block !important;
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
<body class="<?= $isPreview ? 'print-render--preview' : '' ?> print-mode-<?= htmlspecialchars($mode) ?>">
    <?php if ($autoPrint): ?>
    <div class="print-setup-notice" id="printSetupNotice" role="dialog" aria-labelledby="printSetupTitle">
        <div class="print-setup-notice__card">
            <h2 id="printSetupTitle">Set paper size before printing</h2>
            <ul>
                <li><strong>Paper size:</strong> <?= htmlspecialchars($paperHint) ?></li>
                <li><strong>Scale:</strong> 100% — do not use “Fit to page”</li>
                <li><strong>Margins:</strong> None</li>
                <li><strong>Pages:</strong> Should show <strong>1 sheet</strong> only (not 2+)</li>
            </ul>
            <p style="margin:0 0 1rem;color:#64748b;font-size:12px;">If you see “Postcard” or “2 sheets”, change the paper size in the print dialog.</p>
            <button type="button" id="printSetupContinue">Continue to Print</button>
        </div>
    </div>
    <?php endif; ?>
    <div class="print-render-wrap">
        <?= $overlayHtml ?>
    </div>
    <?php if ($autoPrint): ?>
    <script>
        (function () {
            var notice = document.getElementById('printSetupNotice');
            var btn = document.getElementById('printSetupContinue');
            function startPrint() {
                if (notice) notice.style.display = 'none';
                window.print();
            }
            if (btn) {
                btn.addEventListener('click', startPrint);
            } else {
                window.addEventListener('load', function () { window.setTimeout(startPrint, 600); });
            }
        })();
    </script>
    <?php endif; ?>
</body>
</html>
