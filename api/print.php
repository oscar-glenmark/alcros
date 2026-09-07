<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/printing.php';
requireStaffLogin();

$pdo = getDB();
ensurePrintTables($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireStaffPostCsrf();
}

switch ($action) {
    case 'log_print':
        handleLogPrint($pdo);
        break;
    case 'save_field':
        handleSaveField($pdo);
        break;
    case 'save_calibration':
        handleSaveCalibration($pdo);
        break;
    case 'save_global_calibration':
        handleSaveGlobalCalibration($pdo);
        break;
    case 'preview_data':
        handlePreviewData($pdo);
        break;
    default:
        apiError('Unknown action.', 404);
}

function handleLogPrint(PDO $pdo): void
{
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $recordId = (int) ($_POST['record_id'] ?? 0);
    $pageSide = ($_POST['page'] ?? 'front') === 'back' ? 'back' : 'front';
    $testMode = !empty($_POST['test']);

    $context = printRequestContext($pdo, $requestId ?: null, $recordId ?: null);
    if (!$context['ok']) {
        apiError($context['error'], 404);
    }

    $printOptions = buildPrintOptionsFromRequest();
    $printData = printCertificate(
        $pdo,
        $context['certificate_type'],
        $pageSide,
        $context['record'],
        array_merge($printOptions, ['test_mode' => $testMode])
    );
    if (!$printData) {
        apiError('Template not found.', 404);
    }

    $jobId = logPrintJob($pdo, [
        'request_id'       => $context['request']['id'] ?? null,
        'civil_record_id'  => $context['record']['id'] ?? null,
        'template_id'      => (int) $printData['template']['id'],
        'page_side'        => $pageSide,
        'certificate_type' => $context['certificate_type'],
        'registry_number'  => $context['record']['registry_number'] ?? null,
        'print_mode'       => $testMode ? 'test' : 'production',
        'copies'           => max(1, (int) ($_POST['copies'] ?? 1)),
    ]);

    $fillRaw = $_POST['fill'] ?? '';
    if ($fillRaw !== '') {
        $fillDecoded = json_decode($fillRaw, true);
        savePrintFillData($pdo, $context, is_array($fillDecoded) ? $fillDecoded : []);
    }

    apiJsonResponse(['job_id' => $jobId]);
}

function handleSaveField(PDO $pdo): void
{
    if (!canCalibratePrintTemplates()) {
        apiError('Only administrators can calibrate print templates.', 403);
    }

    $fieldId = (int) ($_POST['field_id'] ?? 0);
    if ($fieldId <= 0) {
        apiError('Invalid field.');
    }

    $data = [];
    foreach (['x_mm', 'y_mm', 'width_mm', 'height_mm', 'font_size', 'font_family', 'font_weight', 'alignment', 'max_length', 'line_height', 'enabled', 'label'] as $key) {
        if (array_key_exists($key, $_POST)) {
            $data[$key] = $_POST[$key];
        }
    }

    if (!updatePrintField($pdo, $fieldId, $data)) {
        apiError('Nothing to update.');
    }

    logActivity(staffId(), 'Print Field Updated', 'Updated print field #' . $fieldId);
    bumpPrintCalibrationRevision();
    apiJsonResponse(['field_id' => $fieldId]);
}

function handleSaveCalibration(PDO $pdo): void
{
    if (!canCalibratePrintTemplates()) {
        apiError('Only administrators can calibrate print templates.', 403);
    }

    $templateId = (int) ($_POST['template_id'] ?? 0);
    if ($templateId <= 0) {
        apiError('Invalid template.');
    }

    updatePrintCalibration($pdo, $templateId, [
        'x_offset_mm' => (float) ($_POST['x_offset_mm'] ?? 0),
        'y_offset_mm' => (float) ($_POST['y_offset_mm'] ?? 0),
        'scale_x'     => (float) ($_POST['scale_x'] ?? 1),
        'scale_y'     => (float) ($_POST['scale_y'] ?? 1),
    ], staffId());

    logActivity(staffId(), 'Print Calibration Saved', 'Template #' . $templateId);
    bumpPrintCalibrationRevision();
    apiJsonResponse(['template_id' => $templateId]);
}

function handleSaveGlobalCalibration(PDO $pdo): void
{
    if (!canCalibratePrintTemplates()) {
        apiError('Only administrators can calibrate print templates.', 403);
    }

    setSetting('print_global_x_offset_mm', (string) ((float) ($_POST['x_offset_mm'] ?? 0)));
    setSetting('print_global_y_offset_mm', (string) ((float) ($_POST['y_offset_mm'] ?? 0)));
    setSetting('print_global_scale_x', (string) ((float) ($_POST['scale_x'] ?? 1)));
    setSetting('print_global_scale_y', (string) ((float) ($_POST['scale_y'] ?? 1)));
    setSetting('print_back_orientation_hint', trim((string) ($_POST['back_orientation_hint'] ?? 'flip_long_edge')));
    setSetting('print_mode', in_array($_POST['print_mode'] ?? '', ['preprinted', 'digital'], true) ? $_POST['print_mode'] : 'preprinted');
    setSetting('print_province', trim((string) ($_POST['print_province'] ?? 'Misamis Occidental')));
    setSetting('print_city_municipality', trim((string) ($_POST['print_city_municipality'] ?? 'Aloran')));

    logActivity(staffId(), 'Print Global Settings Saved', 'Updated global print calibration/settings');
    bumpPrintCalibrationRevision();
    apiJsonResponse(['saved' => true]);
}

function handlePreviewData(PDO $pdo): void
{
    $certificateType = (string) ($_GET['certificate_type'] ?? '');
    $pageSide = ($_GET['page'] ?? 'front') === 'back' ? 'back' : 'front';
    $requestId = (int) ($_GET['request_id'] ?? 0);
    $recordId = (int) ($_GET['record_id'] ?? 0);

    $context = printRequestContext($pdo, $requestId ?: null, $recordId ?: null);
    if (!$context['ok']) {
        apiError($context['error'], 404);
    }

    $printOptions = buildPrintOptionsFromRequest();
    $printData = printCertificate(
        $pdo,
        $context['certificate_type'],
        $pageSide,
        $context['record'],
        $printOptions
    );
    if (!$printData) {
        apiError('Template not found.', 404);
    }

    apiJsonResponse([
        'template' => $printData['template'],
        'fields'   => $printData['fields'],
        'values'   => $printData['values'],
    ]);
}

function buildPrintOptionsFromRequest(): array
{
    return [
        'include_paternity_affidavit'        => !empty($_REQUEST['paternity']),
        'include_delayed_birth_affidavit'    => !empty($_REQUEST['delayed_birth']),
        'include_delayed_marriage_affidavit'   => !empty($_REQUEST['delayed_marriage']),
        'include_delayed_death_affidavit'      => !empty($_REQUEST['delayed_death']),
        'include_infant_section'             => !empty($_REQUEST['infant_section']),
        'include_postmortem'                   => !empty($_REQUEST['postmortem']),
    ];
}
