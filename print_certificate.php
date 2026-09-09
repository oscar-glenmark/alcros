<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/printing.php';
require_once __DIR__ . '/includes/certification_print.php';
requireStaffLogin();
requirePageAccess('print_certificate.php');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$activePage = 'print_certificate.php';
$pdo = getDB();
@set_time_limit(120);
try {
    ensurePrintTables($pdo);
} catch (Throwable $e) {
    // Continue if tables already exist.
}

$requestId = (int) ($_GET['request_id'] ?? 0);
$recordId = (int) ($_GET['record_id'] ?? 0);
$documentKind = normalizePrintDocumentKind($_GET['kind'] ?? 'certificate');
$isCertification = $documentKind === 'certification';

if ($isCertification) {
    $context = printCertificationContext($pdo, $recordId, $requestId);
} else {
    $context = printRequestContext($pdo, $requestId ?: null, $recordId ?: null);
}

if (!$context['ok']) {
    $pageTitle = $isCertification ? 'Print Certification' : 'Print Certificate';
    $pageSubtitle = 'Unable to prepare print preview.';
    $printError = $context['error'];
} else {
    $request = $context['request'];
    $record = $context['record'];
    $certificateType = $context['certificate_type'];
    $recordSource = $context['record_source'] ?? 'civil_record';
    $printError = null;

    if ($isCertification && $recordSource !== 'civil_record') {
        $printError = 'Certification requires an existing civil registry record. It does not create a new registration entry.';
    } else {
        $pageTitle = $isCertification ? 'Print Certification' : 'Print Certificate';
        $pageSubtitle = $isCertification
            ? certificationTitle($certificateType) . ' · Existing LCRO Record'
            : printCertificateTitle($certificateType) . ' · Municipal Form No. ' . printCertificateFormNumber($certificateType);
        $fillEditorFields = $isCertification
            ? printCertificationFillEditorFields($certificateType, $record)
            : printFillEditorFields($certificateType, $record, [], $pdo);
        $frontTemplate = getPrintTemplate($pdo, $certificateType, 'front', $documentKind);
        $paperW = (float) ($frontTemplate['paper_width_mm'] ?? ($isCertification ? certificationPaperSize()['paper_width_mm'] : printOfficialPaperWidthMm()));
        $paperH = (float) ($frontTemplate['paper_height_mm'] ?? ($isCertification ? certificationPaperSize()['paper_height_mm'] : printOfficialPaperHeightMm()));
        $paperSizeLabel = printPaperSizeLabel($paperW, $paperH, true);
        $paperSizeTitle = printPaperSizeLabel($paperW, $paperH);
    }
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
    <?= printPrinterSetupStylesheet() ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen<?= !empty($isCertification) ? ' print-page--certification' : '' ?>">
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
                    <a href="<?= htmlspecialchars(buildAuthUrl(!empty($isCertification) ? 'records.php' : 'manage_request.php')) ?>" class="print-cert-link">Back</a>
                </div>
            </div>
        <?php else: ?>
            <?php if (!$isCertification && ($recordSource ?? '') === 'request'): ?>
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
                        <span class="print-cert-label"><?= $isCertification ? 'Document' : 'Certificate' ?></span>
                        <strong><?= htmlspecialchars($isCertification ? certificationTitle($certificateType) : printCertificateTitle($certificateType)) ?></strong>
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
                        <span class="print-cert-label">Book / Page</span>
                        <strong><?= htmlspecialchars(certificationBookPageReference($record) ?: '—') ?></strong>
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

            <?php if (!$isCertification): ?>
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
                    </div>
                </div>
                <p class="print-cert-hint print-cert-hint--compact">Affidavit sections fill only when checked. Signatures are never auto-generated.</p>
            </section>
            <?php else: ?>
            <section class="print-cert-alert print-cert-alert--warn no-print">
                <i data-lucide="info"></i>
                <div>
                    <strong>Certification from existing record</strong>
                    <p>This prints an authenticated copy from the civil registry. It does not create a new registration entry. Review the auto-filled fields below, then print directly from the preview.</p>
                </div>
            </section>
            <?php endif; ?>
            <p class="print-cert-hint print-cert-hint--compact no-print">
                The preview and print setup screen show the form for alignment. The printer receives <strong>data only</strong> — load pre-printed bond paper before printing.
            </p>

            <section class="print-cert-fill no-print">
                <div class="print-cert-fill-head">
                    <div>
                        <h2 class="print-cert-section-title">Edit Fill-in Data</h2>
                        <p class="print-cert-hint">Change values before printing. You can also click text directly in the preview below.</p>
                    </div>
                    <button type="button" class="print-cert-btn print-cert-btn--ghost" id="resetFillData">Reset to record data</button>
                </div>
                <?php if (!$isCertification): ?>
                <div class="print-cert-fill-tabs" role="tablist" aria-label="Fill-in page">
                    <button type="button" class="print-cert-fill-tab is-active" data-fill-tab="front" role="tab" aria-selected="true">Front page fields</button>
                    <button type="button" class="print-cert-fill-tab" data-fill-tab="back" role="tab" aria-selected="false">Back page fields</button>
                </div>
                <?php endif; ?>
                <?php foreach ($isCertification ? ['front'] : ['front', 'back'] as $fillSide): ?>
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
                <?php if (!$isCertification): ?>
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
                <?php endif; ?>
            </section>

            <section class="print-cert-actions no-print">
                <?php if (!$isCertification): ?>
                <button type="button" class="print-cert-btn print-cert-btn--ghost" id="printTestBoth">Test Print Both Sides</button>
                <button type="button" class="print-cert-btn print-cert-btn--primary" id="printFrontBack">Print Front + Back</button>
                <?php else: ?>
                <button type="button" class="print-cert-btn print-cert-btn--ghost" data-print-side="front" data-test="1">Test Print</button>
                <button type="button" class="print-cert-btn print-cert-btn--primary" data-print-side="front">Print Certification</button>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(buildAuthUrl($request ? 'manage_request.php' : 'records.php')) ?>" class="print-cert-btn print-cert-btn--ghost">Cancel</a>
            </section>

            <?php if (!$isCertification): ?>
            <?php renderPrintBuiltInPrinterSetup([
                'variant'               => 'compact',
                'show_back_hint'        => true,
                'back_orientation_hint' => $globalCalibration['back_orientation_hint'] ?? '',
                'extra_class'           => 'no-print',
                'default_width_mm'      => $paperW,
                'default_height_mm'     => $paperH,
            ]); ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php if (!$printError): ?>
<?= csrfField() ?>
<?= pageConfigJson([
    'requestId' => (int) ($request['id'] ?? $requestId),
    'recordId' => (int) (($record['id'] ?? 0) ?: $recordId),
    'documentKind' => $documentKind,
    'printAuthUrl' => buildAuthUrl('print_render.php'),
    'apiPrintUrl' => buildAuthUrl('api/print.php'),
    'paperWidthMm' => $paperW,
    'paperHeightMm' => $paperH,
    'calibrationRev' => printCalibrationRevision(),
    'initialFillValues' => array_column($fillEditorFields, 'value', 'field_name'),
]) ?>
<?= scriptTag('admin/print-fit-text.js') ?>
<?= scriptTag('core/page-config.js') ?>
<?= scriptTag('admin/print-certificate.js') ?>
<?php endif; ?>
<?= lucideInitScript() ?>
<?= adminCoreScripts() ?>
</body>
</html>
