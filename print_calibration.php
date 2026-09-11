<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/printing.php';
require_once __DIR__ . '/includes/certification_print.php';
requireAdmin();

@set_time_limit(120);

$activePage = 'print_calibration.php';
$pdo = getDB();

try {
    ensurePrintTables($pdo);
} catch (Throwable $e) {
    // Continue — template data may still load if tables already exist.
}

$certificateType = $_GET['type'] ?? 'birth';
if (!in_array($certificateType, printCertificateTypes(), true)) {
    $certificateType = 'birth';
}
$documentKind = normalizePrintDocumentKind($_GET['kind'] ?? 'certificate');
$isCertification = $documentKind === 'certification';
$pageSide = ($_GET['page'] ?? 'front') === 'back' ? 'back' : 'front';
if ($isCertification) {
    $pageSide = 'front';
    seedCertificationPrintTemplates($pdo);
}

$template = getPrintTemplate($pdo, $certificateType, $pageSide, $documentKind);
if (!$template) {
    redirectWithAuth('print_calibration.php', $isCertification ? ['kind' => 'certification'] : []);
}

$fields = getPrintFields($pdo, (int) $template['id'], false);
$calibration = getPrintCalibration($pdo, (int) $template['id']);
$globalCalibration = printGlobalCalibration();
$meta = $isCertification
    ? ['title' => certificationTitle($certificateType), 'form_number' => certificationFormNumber($certificateType)]
    : printCertificateMeta()[$certificateType];
$paperW = (float) $template['paper_width_mm'];
$paperH = (float) $template['paper_height_mm'];
$refAsset = $isCertification ? null : printFormReferenceAsset($certificateType, $pageSide);
$displayPng = false;
$displayPngSrc = '';
if ($isCertification) {
    $displayPngSrc = certificationFormScanAsset($certificateType) ?? '';
    $displayPng = $displayPngSrc !== '';
} else {
    $pngRelative = 'assets/print/forms/' . $certificateType . '-' . $pageSide . '.png';
    $pngFull = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pngRelative);
    $displayPng = is_file($pngFull);
    $displayPngSrc = $displayPng ? ($pngRelative . '?v=' . filemtime($pngFull)) : '';
}
$calNavItems = printCalibrationNavItems($certificateType, $pageSide, $documentKind);
$calQueryParams = static function (array $extra = []) use ($certificateType, $pageSide, $isCertification): array {
    $params = array_merge(['type' => $certificateType, 'page' => $pageSide], $extra);
    if ($isCertification) {
        $params['kind'] = 'certification';
    }

    return $params;
};
try {
    $sampleValues = $isCertification
        ? certificationCalibrationSampleValues($certificateType)
        : printCalibrationSampleValues($certificateType);
} catch (Throwable $e) {
    $sampleValues = [];
}
$showSampleDefault = $pageSide !== 'back';
$fieldHints = [];
foreach ($fields as $field) {
    $name = (string) $field['field_name'];
    $hint = printFieldHint($name);
    if ($hint !== '') {
        $fieldHints[$name] = $hint;
    }
    if (printIsCustomField($name)) {
        $sampleValues[$name] = (string) ($field['label'] ?: 'Sample');
    }
}
$previewPrintParams = [
    'calibration_preview' => '1',
    'type'                => $certificateType,
    'page'                => $pageSide,
    'background'          => '1',
    'preview'             => '1',
];
if ($isCertification) {
    $previewPrintParams['kind'] = 'certification';
}
$previewPrintUrl = buildAuthUrl('print_render.php', $previewPrintParams);

$pageTitle = 'Print Template Calibration';
$pageSubtitle = $meta['title'] . ' · Form ' . $meta['form_number'] . ($isCertification ? '' : (' · ' . ucfirst($pageSide) . ' page'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireStaffPostCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'reset_all_fields') {
        if ($isCertification) {
            syncCertificationFieldCoordinatesFromPresets($pdo, $certificateType);
        } else {
            syncPrintFieldCoordinatesFromPresets($pdo, $certificateType, $pageSide);
        }
        bumpPrintCalibrationRevision();
        logActivity(staffId(), 'Print Fields Reset', 'Reset all print field coordinates to defaults');
    } elseif ($action === 'reset_field' && !empty($_POST['field_id'])) {
        $fieldId = (int) $_POST['field_id'];
        $fieldRow = null;
        foreach ($fields as $f) {
            if ((int) $f['id'] === $fieldId) {
                $fieldRow = $f;
                break;
            }
        }
        if ($fieldRow) {
            $seed = $isCertification
                ? certificationSeedFieldLayout($certificateType)
                : printSeedFieldLayout($certificateType, $pageSide);
            foreach ($seed as $s) {
                if ($s['field_name'] === $fieldRow['field_name']) {
                    updatePrintField($pdo, $fieldId, $s);
                    bumpPrintCalibrationRevision();
                    break;
                }
            }
        }
    }
    redirectWithAuth('print_calibration.php', $calQueryParams());
}

$selectedFieldId = (int) ($_GET['field'] ?? 0);
$formDefaults = null;
foreach ($fields as $f) {
    if ((int) $f['id'] === $selectedFieldId) {
        $formDefaults = $f;
        break;
    }
}
if (!$formDefaults && !empty($fields[0])) {
    $formDefaults = $fields[0];
}
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
    <?= adminLayoutHeadStyles('print-calibration') ?>
    <?= printPrinterSetupStylesheet() ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen<?= $isCertification ? ' print-cal-body--certification' : '' ?>">
<?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
<main class="admin-main">
    <?php require __DIR__ . '/includes/admin_header.php'; ?>

    <div class="admin-content print-cal-page">
        <div class="print-cal-toolbar no-print">
            <div class="print-cal-toolbar-top">
                <div class="print-cal-cert-tabs" role="tablist" aria-label="Document kind">
                    <a href="<?= htmlspecialchars(buildAuthUrl('print_calibration.php', ['type' => $certificateType, 'page' => $pageSide])) ?>"
                       class="print-cal-cert-tab<?= !$isCertification ? ' is-active' : '' ?>">Certificate</a>
                    <a href="<?= htmlspecialchars(buildAuthUrl('print_calibration.php', ['type' => $certificateType, 'page' => 'front', 'kind' => 'certification'])) ?>"
                       class="print-cal-cert-tab<?= $isCertification ? ' is-active' : '' ?>">Certification</a>
                </div>
                <div class="print-cal-cert-tabs" role="tablist" aria-label="Certificate type">
                    <?php foreach (printCertificateTypes() as $type): ?>
                        <a href="<?= htmlspecialchars(buildAuthUrl('print_calibration.php', $calQueryParams(['type' => $type]))) ?>"
                           class="print-cal-cert-tab<?= $certificateType === $type ? ' is-active' : '' ?>">
                            <?= htmlspecialchars(ucfirst($type)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if (!$isCertification): ?>
                <div class="print-cal-page-tabs" role="tablist" aria-label="Page side">
                    <a href="<?= htmlspecialchars(buildAuthUrl('print_calibration.php', $calQueryParams(['page' => 'front']))) ?>"
                       class="print-cal-page-tab<?= $pageSide === 'front' ? ' is-active' : '' ?>">Front page</a>
                    <a href="<?= htmlspecialchars(buildAuthUrl('print_calibration.php', $calQueryParams(['page' => 'back']))) ?>"
                       class="print-cal-page-tab<?= $pageSide === 'back' ? ' is-active' : '' ?>">Back page</a>
                </div>
                <?php endif; ?>
            </div>

            <div class="print-cal-progress" aria-label="Calibration pages">
                <?php foreach ($calNavItems as $item): ?>
                    <a href="<?= htmlspecialchars($item['url']) ?>"
                       class="print-cal-progress-item<?= !empty($item['active']) ? ' is-active' : '' ?>"
                       title="<?= htmlspecialchars($item['label']) ?>">
                        <?= htmlspecialchars($item['short']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <p class="print-cal-note"><?= htmlspecialchars($meta['title']) ?> · Form <?= htmlspecialchars($meta['form_number']) ?> · <?= $isCertification ? 'LCRO certification form layout' : 'Official LGU bond-paper layout' ?></p>

            <form method="post" class="print-cal-reset-all" data-confirm="Reset all fields on this page to their default positions?">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reset_all_fields">
                <button type="submit" class="print-cal-reset print-cal-reset--inline">Reset all fields on this page</button>
            </form>
        </div>

        <div class="print-cal-layout print-cal-layout--wide">
            <aside class="print-cal-sidebar no-print">
                <div class="print-cal-help">
                    <p class="print-cal-help-title">Quick guide</p>
                    <ol class="print-cal-help-steps">
                        <li>Move as many fields as you need, then click <strong>Save all changes</strong> once.</li>
                        <li><strong>Shift form</strong> — move every box together if your printer is slightly off.</li>
                        <li><strong>Printer</strong> — set your LGU name and print mode once.</li>
                    </ol>
                </div>

                <div class="print-cal-tabs" role="tablist" aria-label="Calibration steps">
                    <button type="button" class="print-cal-tab is-active" data-tab="fields" role="tab" aria-selected="true">1. Fields</button>
                    <button type="button" class="print-cal-tab" data-tab="shift" role="tab" aria-selected="false">2. Shift form</button>
                    <button type="button" class="print-cal-tab" data-tab="printer" role="tab" aria-selected="false">3. Printer</button>
                </div>

                <div class="print-cal-panel is-active" data-panel="fields" role="tabpanel">
                <h2>Form fields</h2>
                <input type="search" id="calFieldSearch" class="print-cal-search" placeholder="Search fields…" autocomplete="off">

                <ul class="print-cal-field-list" id="calFieldList">
                    <?php foreach ($fields as $field): ?>
                        <li>
                            <button type="button"
                                class="print-cal-field-btn<?= (int) $field['id'] === $selectedFieldId ? ' is-active' : '' ?><?= empty($field['enabled']) ? ' is-hidden-field' : '' ?>"
                                data-field-id="<?= (int) $field['id'] ?>"
                                data-field-name="<?= htmlspecialchars($field['field_name']) ?>"
                                data-field-label="<?= htmlspecialchars($field['label'] ?: $field['field_name']) ?>">
                                <span class="print-cal-field-label"><?= htmlspecialchars($field['label'] ?: $field['field_name']) ?></span>
                                <?php if (empty($field['enabled'])): ?>
                                    <span class="print-cal-field-badge">Hidden</span>
                                <?php endif; ?>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="print-cal-editor" id="calFieldEditor">
                    <?php if ($formDefaults): ?>
                    <div class="print-cal-editor-empty" id="calFieldEmpty"<?= $selectedFieldId ? ' hidden' : '' ?>>
                        <p class="print-cal-editor-empty-title">No field selected</p>
                        <p class="print-cal-hint">Click a field in the list to fine-tune it. When finished, click <strong>Save all changes</strong> above the form to save every field you moved.</p>
                    </div>
                    <div id="calFieldControls"<?= $selectedFieldId ? '' : ' hidden' ?>>
                    <div class="print-cal-field-nav">
                        <button type="button" class="print-cal-nav-btn" id="calPrevField" aria-label="Previous field">← Prev</button>
                        <button type="button" class="print-cal-nav-btn" id="calNextField" aria-label="Next field">Next →</button>
                    </div>
                    <div class="print-cal-editor-head">
                        <p class="print-cal-editor-kicker">Editing</p>
                        <h3 id="calFieldTitle"><?= htmlspecialchars($formDefaults['label'] ?: $formDefaults['field_name']) ?></h3>
                        <p id="calFieldKey" class="print-cal-editor-key"><?= htmlspecialchars($formDefaults['field_name']) ?></p>
                    </div>

                    <form id="calFieldForm" data-no-confirm>
                        <input type="hidden" name="field_id" value="<?= (int) $formDefaults['id'] ?>">

                        <fieldset class="print-cal-fieldset print-cal-field-label-wrap" id="calFieldLabelWrap">
                            <legend>Field name</legend>
                            <p class="print-cal-hint">Rename how this textbox appears in the field list and print fill-in forms. The internal key above stays the same so saved positions and data mapping are not affected.</p>
                            <label>Display name
                                <input type="text" id="calFieldLabel" maxlength="120" placeholder="Field display name" autocomplete="off">
                            </label>
                            <p class="print-cal-label-save-status" id="calLabelSaveStatus" aria-live="polite"></p>
                        </fieldset>

                        <fieldset class="print-cal-fieldset">
                            <legend>Position on paper</legend>
                            <p class="print-cal-hint">How far the box sits from the top-left corner of the form.</p>
                            <div class="print-cal-grid-2">
                                <label>From left (mm)
                                    <input type="number" step="0.1" name="x_mm" value="<?= htmlspecialchars($formDefaults['x_mm']) ?>">
                                </label>
                                <label>From top (mm)
                                    <input type="number" step="0.1" name="y_mm" value="<?= htmlspecialchars($formDefaults['y_mm']) ?>">
                                </label>
                            </div>
                            <div class="print-cal-nudge">
                                <p class="print-cal-hint">Fine-tune with arrow buttons (1&nbsp;mm each click)</p>
                                <div class="print-cal-nudge-grid" role="group" aria-label="Nudge field position">
                                    <span aria-hidden="true"></span>
                                    <button type="button" class="print-cal-nudge-btn" data-nudge="0,-1" aria-label="Move up">↑</button>
                                    <span aria-hidden="true"></span>
                                    <button type="button" class="print-cal-nudge-btn" data-nudge="-1,0" aria-label="Move left">←</button>
                                    <span class="print-cal-nudge-dot" aria-hidden="true">·</span>
                                    <button type="button" class="print-cal-nudge-btn" data-nudge="1,0" aria-label="Move right">→</button>
                                    <span aria-hidden="true"></span>
                                    <button type="button" class="print-cal-nudge-btn" data-nudge="0,1" aria-label="Move down">↓</button>
                                    <span aria-hidden="true"></span>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="print-cal-fieldset">
                            <legend>Box size</legend>
                            <p class="print-cal-hint">The area where printed text will appear.</p>
                            <div class="print-cal-grid-2">
                                <label>Width (mm)
                                    <input type="number" step="0.1" name="width_mm" value="<?= htmlspecialchars($formDefaults['width_mm']) ?>">
                                </label>
                                <label>Height (mm)
                                    <input type="number" step="0.1" name="height_mm" value="<?= htmlspecialchars($formDefaults['height_mm']) ?>">
                                </label>
                            </div>
                            <div class="print-cal-size-nudge" role="group" aria-label="Adjust box size">
                                <button type="button" class="print-cal-size-btn" data-size-nudge="-1,0">Narrower</button>
                                <button type="button" class="print-cal-size-btn" data-size-nudge="1,0">Wider</button>
                                <button type="button" class="print-cal-size-btn" data-size-nudge="0,-1">Shorter</button>
                                <button type="button" class="print-cal-size-btn" data-size-nudge="0,1">Taller</button>
                            </div>
                        </fieldset>

                        <fieldset class="print-cal-fieldset">
                            <legend>Text appearance</legend>
                            <label>Preview text
                                <textarea id="calPreviewText" rows="2" readonly tabindex="-1" aria-readonly="true" placeholder="Select a field to see its preview text…"></textarea>
                            </label>
                            <p class="print-cal-hint">Read-only preview of sample text for this field. Turn on <strong>Show sample text</strong> on the form to see it in the box.</p>
                            <label>Font size (pt)
                                <input type="number" step="0.5" min="6" max="24" name="font_size" value="<?= htmlspecialchars($formDefaults['font_size']) ?>">
                            </label>
                            <div class="print-cal-align-wrap">
                                <span class="print-cal-align-label">Text alignment</span>
                                <div class="print-cal-align-group" role="group" aria-label="Text alignment">
                                    <?php foreach (['left' => 'Left', 'center' => 'Center', 'right' => 'Right'] as $align => $alignLabel): ?>
                                        <button type="button"
                                            class="print-cal-align-btn<?= ($formDefaults['alignment'] ?? 'left') === $align ? ' is-active' : '' ?>"
                                            data-align="<?= $align ?>"><?= $alignLabel ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="alignment" value="<?= htmlspecialchars($formDefaults['alignment'] ?? 'left') ?>">
                            </div>
                            <label>Font
                                <select name="font_family">
                                    <?php
                                    $fonts = ['Arial', 'Times New Roman', 'Courier New', 'Helvetica', 'Georgia'];
                                    $currentFont = $formDefaults['font_family'] ?? 'Arial';
                                    if (!in_array($currentFont, $fonts, true)) {
                                        $fonts[] = $currentFont;
                                    }
                                    foreach ($fonts as $font):
                                    ?>
                                        <option value="<?= htmlspecialchars($font) ?>" <?= $currentFont === $font ? 'selected' : '' ?>><?= htmlspecialchars($font) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </fieldset>

                        <label class="print-cal-toggle">
                            <input type="checkbox" name="enabled" value="1" <?= !empty($formDefaults['enabled']) ? 'checked' : '' ?>>
                            <span class="print-cal-toggle-ui" aria-hidden="true"></span>
                            <span class="print-cal-toggle-text">Show this field when printing</span>
                        </label>
                    </form>
                    <form method="post" class="print-cal-reset-form" id="calResetFieldForm" data-no-confirm>
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reset_field">
                        <input type="hidden" name="field_id" value="<?= (int) $formDefaults['id'] ?>">
                        <button type="button" class="print-cal-reset" id="calResetFieldBtn">Reset this field to default</button>
                    </form>
                    <button type="button" class="print-cal-delete" id="calDeleteFieldBtn" hidden>Delete textbox</button>
                    </div>
                    <?php endif; ?>
                </div>
                </div>

                <div class="print-cal-panel" data-panel="shift" role="tabpanel" hidden>
                    <h2>Shift whole form</h2>
                    <p class="print-cal-hint">Use this when every field is slightly too high, low, left, or right on your printer. All boxes on this page move together.</p>
                    <form id="calTemplateForm" data-no-confirm>
                        <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
                        <div class="print-cal-grid-2">
                            <label>Move right (+) / left (−)
                                <input type="number" step="0.1" name="x_offset_mm" value="<?= htmlspecialchars($calibration['x_offset_mm']) ?>">
                            </label>
                            <label>Move down (+) / up (−)
                                <input type="number" step="0.1" name="y_offset_mm" value="<?= htmlspecialchars($calibration['y_offset_mm']) ?>">
                            </label>
                        </div>
                        <div class="print-cal-nudge">
                            <p class="print-cal-hint">Nudge entire form (1&nbsp;mm)</p>
                            <div class="print-cal-nudge-grid" role="group" aria-label="Nudge whole form">
                                <span aria-hidden="true"></span>
                                <button type="button" class="print-cal-nudge-btn print-cal-shift-nudge" data-nudge="0,-1" aria-label="Shift up">↑</button>
                                <span aria-hidden="true"></span>
                                <button type="button" class="print-cal-nudge-btn print-cal-shift-nudge" data-nudge="-1,0" aria-label="Shift left">←</button>
                                <span class="print-cal-nudge-dot" aria-hidden="true">·</span>
                                <button type="button" class="print-cal-nudge-btn print-cal-shift-nudge" data-nudge="1,0" aria-label="Shift right">→</button>
                                <span aria-hidden="true"></span>
                                <button type="button" class="print-cal-nudge-btn print-cal-shift-nudge" data-nudge="0,1" aria-label="Shift down">↓</button>
                                <span aria-hidden="true"></span>
                            </div>
                        </div>
                        <div class="print-cal-grid-2">
                            <label>Horizontal scale
                                <input type="number" step="0.001" min="0.9" max="1.1" name="scale_x" value="<?= htmlspecialchars($calibration['scale_x']) ?>">
                            </label>
                            <label>Vertical scale
                                <input type="number" step="0.001" min="0.9" max="1.1" name="scale_y" value="<?= htmlspecialchars($calibration['scale_y']) ?>">
                            </label>
                        </div>
                        <div class="print-cal-size-nudge" role="group" aria-label="Adjust form scale">
                            <button type="button" class="print-cal-size-btn" data-scale-nudge="-0.002">Shrink all</button>
                            <button type="button" class="print-cal-size-btn" data-scale-nudge="0.002">Enlarge all</button>
                            <button type="button" class="print-cal-size-btn" data-scale-reset="1">Reset scale to 1</button>
                        </div>
                        <button type="submit" class="print-cal-save">Save form shift</button>
                    </form>
                </div>

                <div class="print-cal-panel" data-panel="printer" role="tabpanel" hidden>
                    <p class="print-cal-hint">These settings apply to every certificate. Paper size is fixed to the built-in Legal preset below.</p>
                    <?php renderPrintBuiltInPrinterSetup([
                        'variant' => 'panel',
                        'show_back_hint' => true,
                        'back_orientation_hint' => $globalCalibration['back_orientation_hint'] ?? '',
                    ]); ?>
                    <form id="calGlobalForm" data-no-confirm class="print-cal-global-form">
                        <label>Print mode
                            <select name="print_mode">
                                <option value="preprinted" <?= printMode() === 'preprinted' ? 'selected' : '' ?>>Pre-printed forms — print data only on blank forms</option>
                                <option value="digital" <?= printMode() === 'digital' ? 'selected' : '' ?>>Digital forms — print full form + data</option>
                            </select>
                        </label>
                        <label>Province printed on forms
                            <input type="text" name="print_province" value="<?= htmlspecialchars(getSetting('print_province', 'Misamis Occidental')) ?>">
                        </label>
                        <label>City / Municipality printed on forms
                            <input type="text" name="print_city_municipality" value="<?= htmlspecialchars(getSetting('print_city_municipality', 'Aloran')) ?>">
                        </label>
                        <details class="print-cal-advanced print-cal-advanced--nested">
                            <summary>Fine-tune all pages globally</summary>
                            <div class="print-cal-advanced-body">
                                <p class="print-cal-hint">Only use if every page on every form needs the same small adjustment.</p>
                                <div class="print-cal-grid-2">
                                    <label>Global left/right shift<input type="number" step="0.1" name="x_offset_mm" value="<?= htmlspecialchars($globalCalibration['x_offset_mm']) ?>"></label>
                                    <label>Global up/down shift<input type="number" step="0.1" name="y_offset_mm" value="<?= htmlspecialchars($globalCalibration['y_offset_mm']) ?>"></label>
                                    <label>Global horizontal scale<input type="number" step="0.001" name="scale_x" value="<?= htmlspecialchars($globalCalibration['scale_x']) ?>"></label>
                                    <label>Global vertical scale<input type="number" step="0.001" name="scale_y" value="<?= htmlspecialchars($globalCalibration['scale_y']) ?>"></label>
                                </div>
                                <label>Back page when printing double-sided
                                    <select name="back_orientation_hint">
                                        <?php foreach (['flip_long_edge' => 'Flip on long edge (most printers)', 'flip_short_edge' => 'Flip on short edge', 'manual' => 'Manual — I will orient myself'] as $hint => $hintLabel): ?>
                                            <option value="<?= $hint ?>" <?= ($globalCalibration['back_orientation_hint'] ?? '') === $hint ? 'selected' : '' ?>><?= htmlspecialchars($hintLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                        </details>
                        <button type="submit" class="print-cal-save">Save printer setup</button>
                    </form>
                </div>
            </aside>

            <div class="print-cal-main">
            <div class="print-cal-canvas-toolbar no-print">
                <button type="button" class="print-cal-tool-btn print-cal-save-all" id="calSaveAllBtn" disabled>Save all changes</button>
                <span class="print-cal-save-status is-saved" id="calSaveStatus" role="status" aria-live="polite">All changes saved</span>
                <button type="button" class="print-cal-tool-btn print-cal-retry-save" id="calRetrySaveBtn" hidden>Retry save</button>
                <button type="button" class="print-cal-tool-btn print-cal-add-btn" id="calAddTextbox">+ Add textbox</button>
                <label class="print-cal-toggle print-cal-toggle--compact">
                    <input type="checkbox" id="calShowBoxes" checked>
                    <span class="print-cal-toggle-ui" aria-hidden="true"></span>
                    <span class="print-cal-toggle-text">Show all field boxes</span>
                </label>
                <label class="print-cal-toggle print-cal-toggle--compact">
                    <input type="checkbox" id="calShowSample"<?= $showSampleDefault ? ' checked' : '' ?>>
                    <span class="print-cal-toggle-ui" aria-hidden="true"></span>
                    <span class="print-cal-toggle-text">Show sample text</span>
                </label>
                <div class="print-cal-zoom-controls">
                    <button type="button" class="print-cal-tool-btn" id="calZoomOut" aria-label="Zoom out">−</button>
                    <input type="range" id="calZoom" min="0.75" max="1.5" step="0.05" value="1" aria-label="Zoom">
                    <button type="button" class="print-cal-tool-btn" id="calZoomIn" aria-label="Zoom in">+</button>
                    <span id="calZoomLabel">100%</span>
                </div>
                <button type="button" id="calPreviewPrintLink" class="print-cal-tool-link">Test print preview ↗</button>
            </div>
            <div class="print-cal-canvas-wrap" id="calCanvasWrap">
                <div class="print-cal-canvas-scaler" id="calCanvasScaler">
                <div class="print-cal-canvas" id="calCanvas"
                     style="width:<?= $paperW ?>mm;height:<?= $paperH ?>mm;"
                     data-template-id="<?= (int) $template['id'] ?>"
                     data-paper-w="<?= $paperW ?>"
                     data-paper-h="<?= $paperH ?>">
                    <?php if ($displayPng): ?>
                    <img src="<?= htmlspecialchars($displayPngSrc) ?>" alt="" class="print-cal-bg">
                    <?php elseif ($isCertification && !empty($template['reference_image'])): ?>
                    <img src="<?= htmlspecialchars((string) $template['reference_image']) ?>" alt="" class="print-cal-bg">
                    <?php elseif (!empty($refAsset['is_svg']) && !empty($refAsset['inline_svg'])): ?>
                    <div class="print-cal-form-bg"><?= $refAsset['inline_svg'] ?></div>
                    <?php elseif ($refAsset['exists']): ?>
                    <img src="<?= htmlspecialchars($refAsset['src']) ?>" alt="" class="print-cal-bg">
                    <?php else: ?>
                    <img src="" alt="" class="print-cal-bg is-missing">
                    <?php endif; ?>
                    <?php foreach ($fields as $field):
                        $pos = printEffectivePosition($field, $calibration, $globalCalibration);
                        $isSelected = $selectedFieldId > 0 && (int) $field['id'] === $selectedFieldId;
                        $fieldHint = printFieldHint((string) $field['field_name']);
                        $markerSampleText = $showSampleDefault
                            ? ($sampleValues[$field['field_name']] ?? ($fieldHint !== '' ? $fieldHint : ($field['label'] ?: $field['field_name'])))
                            : ($fieldHint !== '' ? $fieldHint : ($field['label'] ?: $field['field_name']));
                        $fieldAlign = printNormalizeAlignment($field['alignment'] ?? null);
                        $fieldJustify = printAlignmentJustifyContent($fieldAlign);
                    ?>
                    <div class="print-cal-marker<?= $isSelected ? ' is-selected' : '' ?><?= empty($field['enabled']) ? ' is-disabled' : '' ?><?= $showSampleDefault ? ' is-sample-mode' : '' ?>"
                         data-field-id="<?= (int) $field['id'] ?>"
                         data-field-name="<?= htmlspecialchars($field['field_name']) ?>"
                         data-base-x="<?= (float) $field['x_mm'] ?>"
                         data-base-y="<?= (float) $field['y_mm'] ?>"
                         data-base-w="<?= (float) $field['width_mm'] ?>"
                         data-base-h="<?= (float) $field['height_mm'] ?>"
                         style="left:<?= $pos['x'] ?>mm;top:<?= $pos['y'] ?>mm;width:<?= $pos['width'] ?>mm;height:<?= $pos['height'] ?>mm;font-size:<?= (float) $field['font_size'] ?>pt;text-align:<?= htmlspecialchars($fieldAlign) ?>;justify-content:<?= htmlspecialchars($fieldJustify) ?>;">
                        <span class="print-cal-marker-text"><?= htmlspecialchars($markerSampleText) ?></span>
                        <span class="print-cal-resize-handle" aria-hidden="true"></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                </div>
            </div>
            </div>
        </div>
    </div>
</main>

<?= csrfField() ?>
<?= pageConfigJson([
    'apiPrintUrl' => buildAuthUrl('api/print.php'),
    'csrfToken' => csrfToken(),
    'fields' => array_map(static function ($f) {
        return [
            'id' => (int) $f['id'],
            'field_name' => $f['field_name'],
            'label' => $f['label'],
            'x_mm' => (float) $f['x_mm'],
            'y_mm' => (float) $f['y_mm'],
            'width_mm' => (float) $f['width_mm'],
            'height_mm' => (float) $f['height_mm'],
            'font_size' => (float) $f['font_size'],
            'font_family' => $f['font_family'],
            'alignment' => $f['alignment'],
            'enabled' => (int) $f['enabled'],
        ];
    }, $fields),
    'selectedFieldId' => $selectedFieldId,
    'templateId' => (int) $template['id'],
    'templateCalibration' => [
        'x_offset_mm' => (float) $calibration['x_offset_mm'],
        'y_offset_mm' => (float) $calibration['y_offset_mm'],
        'scale_x'     => (float) $calibration['scale_x'],
        'scale_y'     => (float) $calibration['scale_y'],
    ],
    'globalCalibration' => [
        'x_offset_mm' => (float) $globalCalibration['x_offset_mm'],
        'y_offset_mm' => (float) $globalCalibration['y_offset_mm'],
        'scale_x'     => (float) $globalCalibration['scale_x'],
        'scale_y'     => (float) $globalCalibration['scale_y'],
    ],
    'sampleValues' => $sampleValues,
    'fieldHints' => $fieldHints,
    'previewPrintUrl' => $previewPrintUrl,
]) ?>
<?= scriptTag('core/page-config.js') ?>
<?= scriptTag('admin/print-calibration.js') ?>
<?= lucideInitScript() ?>
<?= adminCoreScripts() ?>
</body>
</html>
