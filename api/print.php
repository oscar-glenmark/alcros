<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/printing.php';

@set_time_limit(120);

try {
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
        case 'save_fields':
            handleSaveFields($pdo);
            break;
        case 'add_field':
            handleAddField($pdo);
            break;
        case 'delete_field':
            handleDeleteField($pdo);
            break;
        case 'save_calibration':
            handleSaveCalibration($pdo);
            break;
        case 'save_global_calibration':
            handleSaveGlobalCalibration($pdo);
            break;
        case 'save_paper_preferences':
            handleSavePaperPreferences();
            break;
        case 'preview_data':
            handlePreviewData($pdo);
            break;
        default:
            apiError('Unknown action.', 404);
    }
} catch (Throwable $e) {
    apiError('Print request failed. Refresh the page and try again.', 500);
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

function handleAddField(PDO $pdo): void
{
    if (!canCalibratePrintTemplates()) {
        apiError('Only administrators can calibrate print templates.', 403);
    }

    $templateId = (int) ($_POST['template_id'] ?? 0);
    if ($templateId <= 0) {
        apiError('Invalid template.');
    }

    $overrides = [];
    foreach (['x_mm', 'y_mm', 'width_mm', 'height_mm', 'label'] as $key) {
        if (array_key_exists($key, $_POST)) {
            $overrides[$key] = $_POST[$key];
        }
    }

    $field = createPrintField($pdo, $templateId, $overrides);
    if (!$field) {
        apiError('Could not create textbox.');
    }

    logActivity(staffId(), 'Print Field Added', 'Added custom textbox ' . $field['field_name']);
    bumpPrintCalibrationRevision();
    apiJsonResponse(['field' => printFieldConfigForClient($field)]);
}

function handleDeleteField(PDO $pdo): void
{
    if (!canCalibratePrintTemplates()) {
        apiError('Only administrators can calibrate print templates.', 403);
    }

    $fieldId = (int) ($_POST['field_id'] ?? 0);
    if ($fieldId <= 0) {
        apiError('Invalid field.');
    }

    $field = getPrintFieldById($pdo, $fieldId);
    if (!$field) {
        apiError('Field not found.');
    }

    if (!deletePrintField($pdo, $fieldId)) {
        apiError('Only custom textboxes can be deleted.');
    }

    logActivity(staffId(), 'Print Field Deleted', 'Deleted custom textbox ' . $field['field_name']);
    bumpPrintCalibrationRevision();
    apiJsonResponse(['field_id' => $fieldId]);
}

function printFieldUpdateDataFromInput(PDO $pdo, int $fieldId, array $input): array
{
    $existing = getPrintFieldById($pdo, $fieldId);
    if (!$existing) {
        return ['error' => 'Field #' . $fieldId . ' not found.'];
    }

    $data = [];
    foreach (['x_mm', 'y_mm', 'width_mm', 'height_mm', 'font_size', 'font_family', 'font_weight', 'alignment', 'max_length', 'line_height', 'enabled'] as $key) {
        if (array_key_exists($key, $input)) {
            $data[$key] = $input[$key];
        }
    }

    if (array_key_exists('label', $input)) {
        $label = trim((string) $input['label']);
        if ($label === '') {
            return ['error' => 'Field name cannot be empty.'];
        }
        $data['label'] = mb_substr($label, 0, 120);
    }

    if ($data === []) {
        return ['error' => 'Nothing to update for field #' . $fieldId . '.'];
    }

    if (!updatePrintField($pdo, $fieldId, $data)) {
        return ['error' => 'Could not update field #' . $fieldId . '.'];
    }

    $updated = getPrintFieldById($pdo, $fieldId);
    if (!$updated) {
        return ['error' => 'Field #' . $fieldId . ' saved but could not be reloaded.'];
    }

    return ['field' => printFieldConfigForClient($updated)];
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

    $result = printFieldUpdateDataFromInput($pdo, $fieldId, $_POST);
    if (isset($result['error'])) {
        apiError($result['error']);
    }

    logActivity(staffId(), 'Print Field Updated', 'Updated print field #' . $fieldId);
    bumpPrintCalibrationRevision();
    apiJsonResponse([
        'field_id' => $fieldId,
        'field'    => $result['field'],
    ]);
}

function handleSaveFields(PDO $pdo): void
{
    if (!canCalibratePrintTemplates()) {
        apiError('Only administrators can calibrate print templates.', 403);
    }

    $raw = $_POST['fields_json'] ?? '';
    $entries = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($entries) || $entries === []) {
        apiError('No fields to save.');
    }

    $saved = [];
    $lastError = null;

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            apiError('Invalid field data.');
        }

        $fieldId = (int) ($entry['field_id'] ?? 0);
        if ($fieldId <= 0) {
            apiError('Invalid field.');
        }

        try {
            $result = printFieldUpdateDataFromInput($pdo, $fieldId, $entry);
        } catch (Throwable $e) {
            $lastError = 'Could not save field #' . $fieldId . '.';
            continue;
        }

        if (isset($result['error'])) {
            $lastError = $result['error'];
            continue;
        }

        $saved[] = $result['field'];
    }

    if ($saved === []) {
        apiError($lastError ?: 'No fields were saved.');
    }

    $count = count($saved);
    logActivity(
        staffId(),
        'Print Fields Updated',
        'Updated ' . $count . ' print field' . ($count === 1 ? '' : 's')
    );
    bumpPrintCalibrationRevision();
    apiJsonResponse([
        'fields' => $saved,
        'count'  => $count,
        'partial' => $count < count($entries),
        'warning' => ($count < count($entries) && $lastError) ? $lastError : null,
    ]);
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

function handleSavePaperPreferences(): void
{
    $preset = trim((string) ($_POST['preset'] ?? 'legal'));
    $widthMm = (float) ($_POST['width_mm'] ?? 0);
    $heightMm = (float) ($_POST['height_mm'] ?? 0);

    try {
        savePrintPaperPreferences($preset, $widthMm, $heightMm);
    } catch (InvalidArgumentException $e) {
        apiError($e->getMessage(), 422);
    }

    apiJsonResponse(['saved' => true]);
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
