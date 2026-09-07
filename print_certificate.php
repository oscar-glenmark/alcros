<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/printing.php';
requireStaffLogin();
requirePageAccess('print_certificate.php');

$activePage = 'print_certificate.php';
$pdo = getDB();
ensurePrintTables($pdo);

$requestId = (int) ($_GET['request_id'] ?? 0);
$recordId = (int) ($_GET['record_id'] ?? 0);

$context = printRequestContext($pdo, $requestId ?: null, $recordId ?: null);
if (!$context['ok']) {
    $pageTitle = 'Print Certificate';
    $pageSubtitle = 'Unable to prepare print preview.';
    $printError = $context['error'];
} else {
    $request = $context['request'];
    $record = $context['record'];
    $certificateType = $context['certificate_type'];
    $pageTitle = 'Print Certificate';
    $pageSubtitle = printCertificateTitle($certificateType) . ' · Municipal Form No. ' . printCertificateFormNumber($certificateType);
    $printError = null;
    $recordSource = $context['record_source'] ?? 'civil_record';
    $fillEditorFields = printFillEditorFields($certificateType, $record);
    $frontTemplate = getPrintTemplate($pdo, $certificateType, 'front');
    $paperW = (float) ($frontTemplate['paper_width_mm'] ?? printOfficialPaperWidthMm());
    $paperH = (float) ($frontTemplate['paper_height_mm'] ?? printOfficialPaperHeightMm());
    $paperSizeLabel = printPaperSizeLabel($paperW, $paperH, true);
    $paperSizeTitle = printPaperSizeLabel($paperW, $paperH);
}

$globalCalibration = printGlobalCalibration();
$printModeSetting = printMode();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> · ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= vendorStylesheetTag('inter/inter.css') ?>
    <?= adminLayoutHeadStyles('print-certificate') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen">
<?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
<main class="admin-main">
    <?php require __DIR__ . '/includes/admin_header.php'; ?>

    <div class="admin-content print-certificate-page">
        <?php if ($printError): ?>
            <div class="print-cert-alert print-cert-alert--error">
                <i data-lucide="alert-circle"></i>
                <div>
                    <strong>Cannot open print preview</strong>
                    <p><?= htmlspecialchars($printError) ?></p>
                    <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="print-cert-link">Back to Manage Requests</a>
                </div>
            </div>
        <?php else: ?>
            <?php if (($recordSource ?? '') === 'request'): ?>
            <div class="print-cert-alert print-cert-alert--warn no-print">
                <i data-lucide="info"></i>
                <div>
                    <strong>Using request data</strong>
                    <p>No matching civil record was found in the registry. Fields are pre-filled from the document request — review and edit the fill-in data below before printing.</p>
                </div>
            </div>
            <?php endif; ?>
            <section class="print-cert-summary">
                <div class="print-cert-summary__grid">
                    <div class="print-cert-summary__item">
                        <span class="print-cert-label">Certificate</span>
                        <strong><?= htmlspecialchars(printCertificateTitle($certificateType)) ?></strong>
                    </div>
                    <?php if ($request): ?>
                    <div class="print-cert-summary__item">
                        <span class="print-cert-label">Request</span>
                        <strong><?= htmlspecialchars($request['tracking_code']) ?></strong>
                    </div>
                    <div class="print-cert-summary__item">
                        <span class="print-cert-label">Applicant</span>
                        <strong><?= htmlspecialchars(personNameFromRow($request)) ?></strong>
                    </div>
                    <?php endif; ?>
                    <div class="print-cert-summary__item">
                        <span class="print-cert-label">Registry No.</span>
                        <strong><?= htmlspecialchars($record['registry_number'] ?: '—') ?></strong>
                    </div>
                    <div class="print-cert-summary__item">
                        <span class="print-cert-label">Print Mode</span>
                        <strong><?= $printModeSetting === 'digital' ? 'Digital Form' : 'Pre-printed Overlay' ?></strong>
                    </div>
                    <div class="print-cert-summary__item print-cert-summary__item--paper">
                        <span class="print-cert-label">Paper Size</span>
                        <strong title="<?= htmlspecialchars($paperSizeTitle) ?>"><?= htmlspecialchars($paperSizeLabel) ?></strong>
                    </div>
                </div>
            </section>

            <section class="print-cert-options no-print">
                <div class="print-cert-options__inner">
                    <h2 class="print-cert-section-title">Back Page Options</h2>
                    <div class="print-cert-checks">
                        <?php if ($certificateType === 'birth'): ?>
                            <label><input type="checkbox" id="optPaternity"> Paternity affidavit</label>
                            <label><input type="checkbox" id="optDelayedBirth"> Delayed birth affidavit</label>
                        <?php elseif ($certificateType === 'marriage'): ?>
                            <label><input type="checkbox" id="optDelayedMarriage"> Delayed marriage affidavit</label>
                        <?php elseif ($certificateType === 'death'): ?>
                            <label><input type="checkbox" id="optInfantSection"> Infant 0–7 days</label>
                            <label><input type="checkbox" id="optPostmortem"> Postmortem (autopsy)</label>
                            <label><input type="checkbox" id="optDelayedDeath"> Delayed death affidavit</label>
                        <?php endif; ?>
                        <label><input type="checkbox" id="optShowBackground" checked> Show form background</label>
                    </div>
                </div>
                <p class="print-cert-hint print-cert-hint--compact">Affidavit sections fill only when checked. Signatures are never auto-generated.</p>
            </section>

            <section class="print-cert-fill no-print">
                <div class="print-cert-fill-head">
                    <div>
                        <h2 class="print-cert-section-title">Edit Fill-in Data</h2>
                        <p class="print-cert-hint">Change values before printing. You can also click text directly in the preview below.</p>
                    </div>
                    <button type="button" class="print-cert-btn print-cert-btn--ghost" id="resetFillData">Reset to record data</button>
                </div>
                <div class="print-cert-fill-tabs" role="tablist" aria-label="Fill-in page">
                    <button type="button" class="print-cert-fill-tab is-active" data-fill-tab="front" role="tab" aria-selected="true">Front page fields</button>
                    <button type="button" class="print-cert-fill-tab" data-fill-tab="back" role="tab" aria-selected="false">Back page fields</button>
                </div>
                <?php foreach (['front', 'back'] as $fillSide): ?>
                <div class="print-cert-fill-grid" id="fillFields<?= ucfirst($fillSide) ?>" data-fill-panel="<?= $fillSide ?>" role="tabpanel"<?= $fillSide === 'back' ? ' hidden' : '' ?>>
                    <?php foreach ($fillEditorFields as $fillField):
                        if ($fillField['page_side'] !== $fillSide) {
                            continue;
                        }
                    ?>
                    <label class="print-cert-fill-field"<?= ($fillGroup = printFillFieldGroup($fillField['field_name'])) !== '' ? ' data-fill-group="' . htmlspecialchars($fillGroup) . '"' : '' ?>>
                        <span><?= htmlspecialchars($fillField['label']) ?></span>
                        <input type="text"
                               data-field-name="<?= htmlspecialchars($fillField['field_name']) ?>"
                               value="<?= htmlspecialchars($fillField['value']) ?>"
                               autocomplete="off"
                               spellcheck="false">
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </section>

            <section class="print-cert-previews">
                <div class="print-cert-preview-block">
                    <div class="print-cert-preview-head">
                        <h2>Front Preview</h2>
                        <div class="print-cert-preview-actions no-print">
                            <button type="button" class="print-cert-btn print-cert-btn--ghost" data-print-side="front" data-test="1">Test Print Front</button>
                            <button type="button" class="print-cert-btn print-cert-btn--primary" data-print-side="front">Print Front</button>
                        </div>
                    </div>
                    <iframe id="previewFront" class="print-cert-frame" title="Front preview"></iframe>
                </div>
                <div class="print-cert-preview-block">
                    <div class="print-cert-preview-head">
                        <h2>Back Preview</h2>
                        <div class="print-cert-preview-actions no-print">
                            <button type="button" class="print-cert-btn print-cert-btn--ghost" data-print-side="back" data-test="1">Test Print Back</button>
                            <button type="button" class="print-cert-btn print-cert-btn--primary" data-print-side="back">Print Back</button>
                        </div>
                    </div>
                    <iframe id="previewBack" class="print-cert-frame" title="Back preview"></iframe>
                </div>
            </section>

            <section class="print-cert-actions no-print">
                <button type="button" class="print-cert-btn print-cert-btn--ghost" id="printTestBoth">Test Print Both Sides</button>
                <button type="button" class="print-cert-btn print-cert-btn--primary" id="printFrontBack">Print Front + Back</button>
                <a href="<?= htmlspecialchars(buildAuthUrl($request ? 'manage_request.php' : 'records.php')) ?>" class="print-cert-btn print-cert-btn--ghost">Cancel</a>
            </section>

            <section class="print-cert-duplex-hint no-print">
                <span class="print-cert-duplex-hint__label">Printer setup</span>
                <p class="print-cert-duplex-hint__text">
                    <strong>Legal (8.5 × 14 in)</strong> or <strong>215.9 × 358.9 mm</strong> · Scale <strong>100%</strong>, margins <strong>None</strong> · Not A4/Letter/Postcard ·
                    Back reinsert: <strong><?= htmlspecialchars(str_replace('_', ' ', $globalCalibration['back_orientation_hint'])) ?></strong>
                </p>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php if (!$printError): ?>
<?= csrfField() ?>
<?= pageConfigJson([
    'requestId' => (int) ($request['id'] ?? $requestId),
    'recordId' => (int) (($record['id'] ?? 0) ?: $recordId),
    'certificateType' => $certificateType,
    'printMode' => $printModeSetting,
    'printAuthUrl' => buildAuthUrl('print_render.php'),
    'apiPrintUrl' => buildAuthUrl('api/print.php'),
    'paperWidthMm' => $paperW,
    'paperHeightMm' => $paperH,
    'calibrationRev' => printCalibrationRevision(),
    'initialFillValues' => array_column($fillEditorFields, 'value', 'field_name'),
]) ?>
<?= scriptTag('core/page-config.js') ?>
<?= scriptTag('admin/print-certificate.js') ?>
<?php endif; ?>
<?= lucideInitScript() ?>
<?= adminCoreScripts(['sidebar']) ?>
</body>
</html>
