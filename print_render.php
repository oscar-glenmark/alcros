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
$manualDocument = !empty($_GET['manual']);
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
} elseif ($manualDocument) {
    $certificateType = (string) ($_GET['type'] ?? 'birth');
    if (!in_array($certificateType, printCertificateTypes(), true)) {
        $certificateType = 'birth';
    }
    if ($documentKind === 'certification') {
        $pageSide = 'front';
    }
    $record = printManualBlankRecord($certificateType);
    $request = null;
} else {
    if ($documentKind === 'certification') {
        $context = printCertificationContext($pdo, $recordId, $requestId);
    } else {
        $context = printRequestContext($pdo, $requestId ?: null, $recordId ?: null);
    }
    if (!$context['ok']) {
        http_response_code(404);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">' . faviconLinkTag() . '<title>Print · ALCROS</title></head><body><p>' . htmlspecialchars($context['error']) . '</p></body></html>';
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
} elseif ($manualDocument) {
    $printOptions['manual_blank'] = true;
    $printOptions['keep_empty'] = true;
    $printOptions['skip_fill_overrides'] = true;
}

if ($documentKind === 'certification' && !empty($request)) {
    $issuedTo = certificationIssuedToOverridesForPrint($request, $record);
    if (!empty($issuedTo['purpose'])) {
        $printOptions['purpose'] = $issuedTo['purpose'];
    }
}

$printData = printCertificate($pdo, $certificateType, $pageSide, $record, $printOptions);
if (!$printData) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">' . faviconLinkTag() . '<title>Print · ALCROS</title></head><body><p>Print template not found.</p></body></html>';
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
$printFieldLabels = [];
foreach ($printData['fields'] ?? [] as $printFieldRow) {
    $printFieldLabels[(string) $printFieldRow['field_name']] = (string) ($printFieldRow['label'] ?? '');
}
$printData['values'] = printStripExcludedOptionalSections(
    $printData['values'],
    $printOptions,
    $printFieldLabels,
    $certificateType,
    $pageSide
);

$template = $printData['template'];
$isCertificationDoc = $documentKind === 'certification';
if ($isCertificationDoc) {
    $certPaper = certificationPaperSize($certificateType);
    $paperW = (float) $certPaper['paper_width_mm'];
    $paperH = (float) $certPaper['paper_height_mm'];
} else {
    $paperW = printOfficialPaperWidthMm();
    $paperH = printOfficialPaperHeightMm();
}
$pageCssSize = printPageCssSize($paperW, $paperH);
$pageSizeFallback = $isCertificationDoc ? 'A4 portrait' : '8.5in 13in portrait';
$isPreview = !empty($_GET['preview']);
$embeddedPreview = $isPreview && !empty($_GET['embedded']);
$autoPrint = !empty($_GET['autoprint']);
$showPreviewFieldBoxes = $isPreview || $calibrationPreview;
$fieldPreviewColors = printFormFieldPreviewColors($certificateType);
if ($isCertificationDoc) {
    $showBackground = !empty($_GET['background']);
} else {
    $showBackground = !empty($_GET['background']) || $isPreview || $autoPrint;
}
$printWithBackground = $showBackground && $isCertificationDoc;
if ($isCertificationDoc) {
    $printData['template']['paper_width_mm'] = $paperW;
    $printData['template']['paper_height_mm'] = $paperH;
}
$overlayHtml = renderPrintOverlayHtml($printData, array_merge($printOptions, [
    'mode'                         => $mode,
    'test_mode'                    => $testMode,
    'show_background'              => $showBackground,
    'editable'                     => $isPreview && !$autoPrint && !$testMode && !$calibrationPreview,
    'prefer_scan_background'       => $showBackground,
    'calibration_preview'          => $calibrationPreview,
    'use_effective_positions'      => !empty($printData['use_effective_positions']),
    // Certificates: book/page live on the civil record only - never overlay them on print/preview (certification unchanged).
    'hide_record_registry_fields'  => !$isCertificationDoc && !$calibrationPreview,
]));
?><!DOCTYPE html>
<html lang="en" class="print-render-root<?= $embeddedPreview ? ' print-render--embedded' : '' ?>">
<head>
    <meta charset="UTF-8">
    <?= faviconLinkTag() ?>
    <title>Print <?= htmlspecialchars(ucfirst($certificateType)) ?> · <?= htmlspecialchars(ucfirst($pageSide)) ?></title>
    <style>
        @page {
            <?php if ($isCertificationDoc): ?>
            size: A4 portrait;
            <?php else: ?>
            size: <?= htmlspecialchars($pageSizeFallback) ?>;
            size: <?= htmlspecialchars($pageCssSize) ?> portrait;
            <?php endif; ?>
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
        html.print-render-root.print-render--embedded,
        html.print-render-root.print-render--embedded body {
            margin: 0;
            padding: 0;
            min-height: 0;
            height: auto;
            overflow: visible;
            background: #fff;
        }
        html.print-render-root.print-render--embedded body.print-render--preview {
            padding: 0;
            min-height: 0;
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
        @media print {
            <?php if ($isCertificationDoc): ?>
            @page {
                size: A4 portrait;
                margin: 0;
            }
            <?php endif; ?>
            html.print-render-root,
            html.print-render-root body {
                margin: 0 !important;
                padding: 0 !important;
                width: auto !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: hidden !important;
            }
            body.print-render--preview,
            body.print-render--autoprint {
                background: #fff !important;
                padding: 0 !important;
                min-height: 0 !important;
            }
            body.print-render--autoprint .print-render-wrap,
            body.print-render--preview .print-render-wrap {
                box-shadow: none !important;
            }
            html.print-render-root .print-render-wrap {
                margin: 0 auto !important;
                padding: 0 !important;
                width: <?= $paperW ?>mm !important;
                height: auto !important;
                max-height: none !important;
                overflow: visible !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                break-after: avoid-page !important;
            }
            html.print-render-root .print-sheet {
                width: <?= $paperW ?>mm !important;
                height: <?= $paperH ?>mm !important;
                max-height: <?= $paperH ?>mm !important;
                overflow: hidden !important;
                margin: 0 !important;
                page-break-before: avoid !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                break-after: avoid-page !important;
            }
            .print-test-marker {
                display: none !important;
            }
            body:not(.print-render--with-background) .print-sheet__background {
                display: none !important;
            }
            .print-field--editable,
            body.print-render--field-boxes .print-field {
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
        body.print-render--certification .print-field--editable[data-empty-hint]:empty::before {
            content: attr(data-empty-hint);
            font-weight: 400;
            font-size: 9pt;
        }
        .print-field--editable:empty::before {
            font-weight: 400;
        }
        body.print-render--field-boxes .print-field {
            z-index: 2;
            background-color: <?= htmlspecialchars($fieldPreviewColors['bg']) ?>;
            border: 1px solid <?= htmlspecialchars($fieldPreviewColors['border']) ?>;
            border-radius: 1px;
            box-sizing: border-box;
        }
        body.print-render--field-boxes .print-field--editable {
            cursor: text;
            border-style: dashed;
            border-color: <?= htmlspecialchars($fieldPreviewColors['border_active']) ?>;
        }
        body.print-render--field-boxes .print-field--editable:focus {
            outline: 2px solid <?= htmlspecialchars(printFormAccent($certificateType)) ?>;
            background-color: <?= htmlspecialchars($fieldPreviewColors['focus_bg']) ?>;
        }
        .print-field--editable:empty::before {
            content: 'Click to type…';
            color: #94a3b8;
            font-style: italic;
            pointer-events: none;
        }
    </style>
</head>
<body class="<?= trim(($isPreview ? 'print-render--preview ' : '') . ($showPreviewFieldBoxes ? 'print-render--field-boxes ' : '') . ($autoPrint ? 'print-render--autoprint ' : '') . ($documentKind === 'certification' ? ' print-render--certification' : '') . ($printWithBackground ? ' print-render--with-background' : '')) ?>">
    <div class="print-render-wrap">
        <?= $overlayHtml ?>
    </div>
    <?php if ($autoPrint): ?>
    <script>
        (function () {
            function startPrint() {
                if (window.AlcrosPrintFitText) {
                    AlcrosPrintFitText.fitAll(document, function () {
                        window.print();
                    });
                    return;
                }
                window.print();
            }

            window.addEventListener('load', function () {
                window.setTimeout(startPrint, 600);
            });
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
