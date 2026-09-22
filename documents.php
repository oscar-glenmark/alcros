<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/printing.php';
require_once __DIR__ . '/includes/print_fill_controls.php';
require_once __DIR__ . '/includes/certification_print.php';
requireStaffLogin();
requirePageAccess('documents.php');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$activePage = 'documents.php';
$pdo = getDB();
@set_time_limit(120);
try {
    ensurePrintTables($pdo);
} catch (Throwable $e) {
    // Continue if tables already exist.
}

$certificateType = (string) ($_GET['type'] ?? 'birth');
if (!in_array($certificateType, printCertificateTypes(), true)) {
    $certificateType = 'birth';
}
$documentKind = normalizePrintDocumentKind($_GET['kind'] ?? 'certificate');
$isCertification = $documentKind === 'certification';

if ($isCertification) {
    seedCertificationPrintTemplates($pdo);
}

$docQueryParams = static function (array $extra = []) use ($certificateType, $isCertification): array {
    $params = ['type' => $certificateType];
    if ($isCertification) {
        $params['kind'] = 'certification';
    }

    return array_merge($params, $extra);
};

$frontTemplate = getPrintTemplate($pdo, $certificateType, 'front', $documentKind);
$printError = null;
if (!$frontTemplate) {
    $printError = 'Print template not found for this document. Ask an administrator to set up print templates.';
} else {
    $fillEditorFields = printDocumentsFillEditorFields($pdo, $certificateType, $documentKind);
    if ($isCertification) {
        $certPaper = certificationPaperSize($certificateType);
        $paperW = (float) $certPaper['paper_width_mm'];
        $paperH = (float) $certPaper['paper_height_mm'];
    } else {
        $paperW = (float) ($frontTemplate['paper_width_mm'] ?? printOfficialPaperWidthMm());
        $paperH = (float) ($frontTemplate['paper_height_mm'] ?? printOfficialPaperHeightMm());
    }
    $paperSizeLabel = printPaperSizeLabel($paperW, $paperH, true);
    $paperSizeTitle = printPaperSizeLabel($paperW, $paperH);
    $meta = $isCertification
        ? ['title' => certificationTitle($certificateType), 'form_number' => certificationFormNumber($certificateType)]
        : printCertificateMeta()[$certificateType];
}

$globalCalibration = printGlobalCalibration();
$printModeSetting = printMode();
$pageTitle = 'Documents';
$pageSubtitle = 'Blank certificate and certification forms for manual entry';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> · ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= interFontTags() ?>
    <?= adminLayoutHeadStyles('print-certificate') ?>
    <?= adminPageStyles('print-calibration') ?>
    <?= printPrinterSetupStylesheet() ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen<?= $isCertification ? ' print-page--certification' : '' ?>">
<?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
<main class="admin-main">
    <?php require __DIR__ . '/includes/admin_header.php'; ?>

    <div class="admin-content print-certificate-page">
        <?php if ($printError): ?>
            <div class="print-cert-alert print-cert-alert--error">
                <i data-lucide="alert-circle"></i>
                <div>
                    <strong>Cannot open document form</strong>
                    <p><?= htmlspecialchars($printError) ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="print-cal-toolbar no-print">
                <div class="print-cal-toolbar-top">
                    <div class="print-cal-cert-tabs" role="tablist" aria-label="Document kind">
                        <a href="<?= htmlspecialchars(buildAuthUrl('documents.php', ['type' => $certificateType])) ?>"
                           class="print-cal-cert-tab<?= !$isCertification ? ' is-active' : '' ?>">Certificate</a>
                        <a href="<?= htmlspecialchars(buildAuthUrl('documents.php', ['type' => $certificateType, 'kind' => 'certification'])) ?>"
                           class="print-cal-cert-tab<?= $isCertification ? ' is-active' : '' ?>">Certification</a>
                    </div>
                    <div class="print-cal-cert-tabs" role="tablist" aria-label="Document type">
                        <?php foreach (printCertificateTypes() as $type): ?>
                            <a href="<?= htmlspecialchars(buildAuthUrl('documents.php', $docQueryParams(['type' => $type]))) ?>"
                               class="print-cal-cert-tab<?= $certificateType === $type ? ' is-active' : '' ?>">
                                <?= htmlspecialchars(ucfirst($type)) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="print-cal-toolbar-meta">
                    <p class="print-cal-note">
                        <?= htmlspecialchars($meta['title']) ?> · Form <?= htmlspecialchars($meta['form_number']) ?>
                        · Manual entry — fields start empty. Auto-fill from requests and records is unchanged.
                    </p>
                    <?php if (isAdmin()): ?>
                        <a href="<?= htmlspecialchars(buildAuthUrl('print_calibration.php', $docQueryParams(['page' => 'front']))) ?>"
                           class="print-cert-btn print-cert-btn--ghost"
                           id="openPrintCalibration">
                            Open print calibration
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <section class="print-cert-summary">
                <div class="print-cert-summary__grid">
                    <div class="print-cert-summary__item">
                        <span class="print-cert-label"><?= $isCertification ? 'Document' : 'Certificate' ?></span>
                        <strong><?= htmlspecialchars($isCertification ? certificationTitle($certificateType) : printCertificateTitle($certificateType)) ?></strong>
                    </div>
                    <div class="print-cert-summary__item">
                        <span class="print-cert-label">Entry mode</span>
                        <strong>Manual (blank form)</strong>
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
            <section class="print-cert-options no-print" id="backPageOptions" hidden>
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
            <section class="print-cert-options no-print">
                <div class="print-cert-options__inner">
                    <h2 class="print-cert-section-title">Print Options</h2>
                    <div class="print-cert-checks">
                        <label><input type="checkbox" id="optShowBackground" checked> Show background</label>
                    </div>
                </div>
                <p class="print-cert-hint print-cert-hint--compact">When checked, the form background is included in the preview and print. When unchecked, only the filled-in data is printed — load blank A4 certification paper.</p>
            </section>
            <?php endif; ?>
            <?php if (!$isCertification): ?>
            <p class="print-cert-hint print-cert-hint--compact no-print">
                The preview and print setup screen show the form for alignment. The printer receives <strong>data only</strong> — load pre-printed bond paper before printing.
            </p>
            <?php endif; ?>

            <section class="print-cert-fill no-print">
                <div class="print-cert-fill-head">
                    <div>
                        <h2 class="print-cert-section-title">Fill-in Data</h2>
                        <p class="print-cert-hint">Type values manually. You can also click text directly in the preview below.</p>
                    </div>
                    <div class="print-cert-fill-actions">
                        <?php if (!$isCertification): ?>
                        <button type="button" class="print-cert-btn print-cert-btn--primary" id="addRecordFromDocument">Add to records</button>
                        <?php endif; ?>
                        <button type="button" class="print-cert-btn print-cert-btn--ghost" id="resetFillData">Clear all fields</button>
                    </div>
                </div>
                <?php if (!$isCertification): ?>
                <div class="print-cert-fill-tabs" role="tablist" aria-label="Fill-in page">
                    <button type="button" class="print-cert-fill-tab is-active" data-fill-tab="front" role="tab" aria-selected="true">Front page fields</button>
                    <button type="button" class="print-cert-fill-tab" data-fill-tab="back" role="tab" aria-selected="false">Back page fields</button>
                    <button type="button" class="print-cert-fill-tab print-cert-fill-tab--both" id="previewViewBoth" data-preview-view="both">Both pages</button>
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
                        <?php renderPrintFillFieldInput($fillField); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </section>

            <section class="print-cert-previews<?= !$isCertification ? ' print-cert-previews--local' : '' ?>"
                     data-preview-view="front"
                     data-paper-w="<?= htmlspecialchars((string) $paperW) ?>"
                     data-paper-h="<?= htmlspecialchars((string) $paperH) ?>">
                <div class="print-cert-preview-block" data-preview-side="front">
                    <div class="print-cert-preview-head">
                        <h2>Front Preview</h2>
                        <div class="print-cert-preview-actions no-print">
                            <button type="button" class="print-cert-btn print-cert-btn--ghost" data-print-side="front" data-test="1">Test Print Front</button>
                            <button type="button" class="print-cert-btn print-cert-btn--primary" data-print-side="front">Print Front</button>
                        </div>
                    </div>
                    <?php if (!$isCertification): ?>
                    <div class="print-cert-preview-viewport" data-preview-viewport="front">
                        <div class="print-cert-preview-scaler" data-preview-scaler="front">
                            <iframe id="previewFront" class="print-cert-frame print-cert-frame--local" title="Front preview"></iframe>
                        </div>
                    </div>
                    <?php else: ?>
                    <iframe id="previewFront" class="print-cert-frame" title="Front preview"></iframe>
                    <?php endif; ?>
                </div>
                <?php if (!$isCertification): ?>
                <div class="print-cert-preview-block" data-preview-side="back">
                    <div class="print-cert-preview-head">
                        <h2>Back Preview</h2>
                        <div class="print-cert-preview-actions no-print">
                            <button type="button" class="print-cert-btn print-cert-btn--ghost" data-print-side="back" data-test="1">Test Print Back</button>
                            <button type="button" class="print-cert-btn print-cert-btn--primary" data-print-side="back">Print Back</button>
                        </div>
                    </div>
                    <div class="print-cert-preview-viewport" data-preview-viewport="back">
                        <div class="print-cert-preview-scaler" data-preview-scaler="back">
                            <iframe id="previewBack" class="print-cert-frame print-cert-frame--local" title="Back preview"></iframe>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </section>

            <?php if (!$isCertification): ?>
            <div class="print-cert-footer no-print">
                <section class="print-cert-actions">
                    <button type="button" class="print-cert-btn print-cert-btn--ghost" id="printTestBoth">Test Print Both Sides</button>
                    <button type="button" class="print-cert-btn print-cert-btn--primary" id="printFrontBack">Print Front + Back</button>
                </section>
                <?php renderPrintBuiltInPrinterSetup([
                    'variant'               => 'compact',
                    'show_back_hint'        => true,
                    'back_orientation_hint' => $globalCalibration['back_orientation_hint'] ?? '',
                    'default_width_mm'      => $paperW,
                    'default_height_mm'     => $paperH,
                ]); ?>
            </div>
            <?php else: ?>
            <section class="print-cert-actions no-print">
                <button type="button" class="print-cert-btn print-cert-btn--ghost" data-print-side="front" data-test="1">Test Print</button>
                <button type="button" class="print-cert-btn print-cert-btn--primary" data-print-side="front">Print Certification</button>
            </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php if (!$printError): ?>
<?= csrfField() ?>
<?= pageConfigJson([
    'manualMode' => true,
    'certificateType' => $certificateType,
    'documentKind' => $documentKind,
    'createRecordApiUrl' => !$isCertification ? buildAuthUrl('api/documents.php') : '',
    'locationsApiUrl' => buildAuthUrl('api/locations.php'),
    'printAuthUrl' => buildAuthUrl('print_render.php'),
    'apiPrintUrl' => buildAuthUrl('api/print.php'),
    'paperWidthMm' => $paperW,
    'paperHeightMm' => $paperH,
    'calibrationRev' => printCalibrationRevision(),
    'initialFillValues' => array_column($fillEditorFields, 'value', 'field_name'),
    'calibrationUrl' => buildAuthUrl('print_calibration.php', $docQueryParams(['page' => 'front'])),
]) ?>
<?= scriptTag('admin/print-fit-text.js') ?>
<?= scriptTag('core/page-config.js') ?>
<?= scriptTag('core/cascading-location.js') ?>
<?= scriptTag('admin/print-certificate.js') ?>
<?php endif; ?>
<?= lucideInitScript() ?>
</body>
</html>
