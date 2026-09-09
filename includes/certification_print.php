<?php
/**
 * LCRO Certification documents — authenticated copies from existing civil records.
 * Uses Civil Registry Forms 1A (birth), 2A (death), 3A (marriage).
 */

require_once __DIR__ . '/printing.php';

const CERTIFICATION_LAYOUT_VERSION = 2;

function certificationFormNumber(string $certificateType): string
{
    return match ($certificateType) {
        'birth'    => '1A',
        'death'    => '2A',
        'marriage' => '3A',
        default    => 'CERT',
    };
}

/** @return array<string, array<string, string>> */
function certificationFieldCatalog(): array
{
    $footer = [
        'purpose'             => 'Issued To (Requester)',
        'remarks'             => 'Remarks',
        'amount_paid'         => 'Amount Paid',
        'or_number'           => 'O.R. Number',
        'certification_date'  => 'Date Issued',
        'registrar_name'      => 'Municipal Civil Registrar',
        'verified_by'         => 'Verified By',
    ];

    return [
        'birth' => [
            'page_number'              => 'Page Number (intro)',
            'book_number'              => 'Book Number (intro)',
            'registry_number'          => 'Registry Number',
            'registration_date'        => 'Date of Registration',
            'subject_full_name'        => 'Name of Child',
            'sex'                      => 'Sex',
            'birth_date'               => 'Date of Birth',
            'birth_place'              => 'Place of Birth',
            'mother_name'              => 'Name of Mother',
            'mother_citizenship'       => 'Citizenship of Mother',
            'father_name'              => 'Name of Father',
            'father_citizenship'       => 'Citizenship of Father',
            'parents_marriage_date'    => 'Date of Marriage of Parents',
            'parents_marriage_place'   => 'Place of Marriage of Parents',
        ] + $footer,
        'death' => [
            'page_number'              => 'Page Number (intro)',
            'book_number'              => 'Book Number (intro)',
            'registry_number'          => 'Registry Number',
            'registration_date'        => 'Date of Registration',
            'subject_full_name'        => 'Name of the Deceased',
            'sex'                      => 'Sex',
            'age_at_death'             => 'Age',
            'civil_status'             => 'Civil Status',
            'citizenship'              => 'Citizenship',
            'death_date'               => 'Date of Death',
            'death_place'              => 'Place of Death',
            'cause_of_death'           => 'Cause of Death',
        ] + $footer,
        'marriage' => [
            'page_number'              => 'Page Number (intro)',
            'book_number'              => 'Book Number (intro)',
            'husband_name'             => 'Husband Name',
            'husband_age'              => 'Husband Age',
            'husband_citizenship'      => 'Husband Citizenship',
            'husband_civil_status'     => 'Husband Civil Status',
            'husband_father_name'      => 'Husband Father',
            'husband_mother_name'      => 'Husband Mother',
            'wife_name'                => 'Wife Name',
            'wife_age'                 => 'Wife Age',
            'wife_citizenship'         => 'Wife Citizenship',
            'wife_civil_status'        => 'Wife Civil Status',
            'wife_father_name'         => 'Wife Father',
            'wife_mother_name'         => 'Wife Mother',
            'registry_number'          => 'Registry Number',
            'registration_date'        => 'Date of Registration',
            'marriage_date'            => 'Date of Marriage',
            'marriage_place'           => 'Place of Marriage',
        ] + $footer,
    ];
}

function certificationLegacyPaperHeightMm(): float
{
    // Original PDF page box height (731 × 1000 pt) before PNG aspect alignment.
    return round(1000 * 25.4 / 72, 2);
}

/** @return array{width_px:int,height_px:int}|null */
function certificationFormImageMetrics(?string $certificateType = null): ?array
{
    $type = $certificateType ?? 'birth';
    if (!in_array($type, printCertificateTypes(), true)) {
        $type = 'birth';
    }

    $relative = 'assets/print/forms/certification/' . $type . '.png';
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($full)) {
        return null;
    }

    $size = getimagesize($full);
    if (!$size || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
        return null;
    }

    return [
        'width_px'  => (int) $size[0],
        'height_px' => (int) $size[1],
    ];
}

function certificationVerticalCoordScale(?string $certificateType = null): float
{
    $paper = certificationPaperSize($certificateType);

    return $paper['paper_height_mm'] / certificationLegacyPaperHeightMm();
}

function certificationPaperSize(?string $certificateType = null): array
{
    // Width follows certifications.pdf page box (731 pt). Height follows the PNG scan aspect
    // so object-fit:fill backgrounds are uniformly scaled and mm coordinates line up.
    $widthMm = round(731 * 25.4 / 72, 2);
    $metrics = certificationFormImageMetrics($certificateType);
    $heightMm = $metrics
        ? round($widthMm * ($metrics['height_px'] / $metrics['width_px']), 2)
        : certificationLegacyPaperHeightMm();

    return [
        'paper_width_mm'  => $widthMm,
        'paper_height_mm' => $heightMm,
        'orientation'     => 'portrait',
        'margin_top_mm'   => 0.00,
        'margin_left_mm'  => 0.00,
    ];
}

/** @param list<array<string, mixed>> $fields */
function certificationApplyVerticalCoordScale(array $fields, ?string $certificateType = null): array
{
    $scale = certificationVerticalCoordScale($certificateType);
    if (abs($scale - 1.0) < 0.0001) {
        return $fields;
    }

    foreach ($fields as &$field) {
        if (isset($field['y_mm'])) {
            $field['y_mm'] = round((float) $field['y_mm'] * $scale, 2);
        }
        if (isset($field['height_mm'])) {
            $field['height_mm'] = round((float) $field['height_mm'] * $scale, 2);
        }
    }
    unset($field);

    return $fields;
}

function ensureCertificationPaperAspect(PDO $pdo): void
{
    if (getSetting('certification_paper_aspect_v1', '') === '1') {
        return;
    }

    $legacyHeight = certificationLegacyPaperHeightMm();

    foreach (printCertificateTypes() as $type) {
        $paper = certificationPaperSize($type);
        $newHeight = (float) $paper['paper_height_mm'];
        if (abs($newHeight - $legacyHeight) < 0.01) {
            continue;
        }

        $scale = $newHeight / $legacyHeight;
        $template = getPrintTemplate($pdo, $type, 'front', 'certification');
        if (!$template) {
            continue;
        }

        $templateId = (int) $template['id'];
        $storedHeight = (float) ($template['paper_height_mm'] ?? $legacyHeight);
        if (abs($storedHeight - $legacyHeight) > 0.01) {
            // Already migrated or manually adjusted — only sync template height.
            $pdo->prepare(
                'UPDATE print_templates SET paper_width_mm = ?, paper_height_mm = ?, updated_at = NOW() WHERE id = ?'
            )->execute([$paper['paper_width_mm'], $newHeight, $templateId]);
            continue;
        }

        $pdo->prepare(
            'UPDATE print_templates SET paper_width_mm = ?, paper_height_mm = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$paper['paper_width_mm'], $newHeight, $templateId]);

        $pdo->prepare(
            'UPDATE print_fields
             SET y_mm = ROUND(y_mm * ?, 2), height_mm = ROUND(height_mm * ?, 2), updated_at = NOW()
             WHERE template_id = ?'
        )->execute([$scale, $scale, $templateId]);

        $pdo->prepare(
            'UPDATE print_calibrations
             SET y_offset_mm = ROUND(y_offset_mm * ?, 2), updated_at = NOW()
             WHERE template_id = ?'
        )->execute([$scale, $templateId]);
    }

    setSetting('certification_paper_aspect_v1', '1');
}

function certificationFormReferenceImage(string $certificateType): string
{
    return 'assets/print/forms/certification/' . $certificateType . '.png';
}

function certificationFormScanAsset(string $certificateType): ?string
{
    $relative = 'assets/print/forms/certification/' . $certificateType . '.png';
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($full)) {
        return null;
    }

    return $relative . '?v=' . filemtime($full);
}

function certificationTitle(string $certificateType): string
{
    return match ($certificateType) {
        'birth'    => 'Certification of Live Birth (Form 1A)',
        'death'    => 'Certification of Death (Form 2A)',
        'marriage' => 'Certification of Marriage (Form 3A)',
        default    => 'Certification',
    };
}

function certificationBookPageReference(array $record): string
{
    $book = trim((string) ($record['book_number'] ?? ''));
    $page = trim((string) ($record['page_number'] ?? ''));
    if ($book === '' && $page === '') {
        return '';
    }
    if ($book !== '' && $page !== '') {
        return 'Book ' . $book . ', Page ' . $page;
    }

    return $book !== '' ? 'Book ' . $book : 'Page ' . $page;
}

function certificationCauseOfDeath(array $record): string
{
    $parts = array_filter([
        trim((string) ($record['immediate_cause'] ?? '')),
        trim((string) ($record['contributory_cause'] ?? '')),
        trim((string) ($record['postmortem_cause'] ?? '')),
    ]);

    return implode('; ', $parts);
}

/** @return array<string, array<string, array<string, float|int|string>>> */
function certificationFieldCoordinatePresets(): array
{
    $line = static fn (float $x, float $y, float $w = 120.0, float $h = 4.8): array => [
        'x_mm' => $x, 'y_mm' => $y, 'width_mm' => $w, 'height_mm' => $h,
    ];

    $introPage = $line(168, 52.0, 18, 5);
    $introBook = $line(218, 52.0, 28, 5);
    $value = static fn (float $y, float $w = 165.0): array => $line(74, $y, $w, 4.8);
    $issuedTo = $line(58, 152.0, 118, 5);
    $remarks = $line(22, 168.0, 210, 28);
    $footer = [
        'amount_paid'        => $line(42, 302.0, 55, 4.8),
        'or_number'          => $line(42, 309.0, 55, 4.8),
        'certification_date' => $line(42, 316.0, 55, 4.8),
        'registrar_name'     => $line(138, 268.0, 95, 5),
        'verified_by'        => $line(22, 268.0, 88, 5),
    ];

    return [
        'birth' => [
            'page_number'            => $introPage,
            'book_number'            => $introBook,
            'registry_number'        => $value(68.0),
            'registration_date'      => $value(74.5),
            'subject_full_name'      => $value(81.0),
            'sex'                    => $value(88.0, 40),
            'birth_date'             => $value(94.5),
            'birth_place'            => $value(101.0),
            'mother_name'            => $value(107.5),
            'mother_citizenship'     => $value(114.0),
            'father_name'            => $value(120.5),
            'father_citizenship'     => $value(127.0),
            'parents_marriage_date'  => $value(133.5),
            'parents_marriage_place' => $value(140.0),
            'purpose'                => $issuedTo,
            'remarks'                => $remarks,
        ] + $footer,
        'death' => [
            'page_number'       => $introPage,
            'book_number'       => $introBook,
            'registry_number'   => $value(68.0),
            'registration_date' => $value(74.5),
            'subject_full_name' => $value(81.0),
            'sex'               => $value(88.0, 40),
            'age_at_death'      => $value(94.5, 50),
            'civil_status'      => $value(101.0, 60),
            'citizenship'       => $value(107.5),
            'death_date'        => $value(114.0),
            'death_place'       => $value(120.5),
            'cause_of_death'    => $value(127.0),
            'purpose'           => $issuedTo,
            'remarks'           => $remarks,
        ] + $footer,
        'marriage' => [
            'page_number'           => $introPage,
            'book_number'           => $introBook,
            'husband_name'          => $line(22, 78.0, 98, 4.8),
            'husband_age'           => $line(22, 84.5, 98, 4.8),
            'husband_citizenship'   => $line(22, 91.0, 98, 4.8),
            'husband_civil_status'  => $line(22, 97.5, 98, 4.8),
            'husband_father_name'   => $line(22, 104.0, 98, 4.8),
            'husband_mother_name'   => $line(22, 110.5, 98, 4.8),
            'wife_name'             => $line(135, 78.0, 98, 4.8),
            'wife_age'              => $line(135, 84.5, 98, 4.8),
            'wife_citizenship'      => $line(135, 91.0, 98, 4.8),
            'wife_civil_status'     => $line(135, 97.5, 98, 4.8),
            'wife_father_name'      => $line(135, 104.0, 98, 4.8),
            'wife_mother_name'      => $line(135, 110.5, 98, 4.8),
            'registry_number'       => $value(120.0),
            'registration_date'     => $value(126.5),
            'marriage_date'         => $value(133.0),
            'marriage_place'        => $value(139.5),
            'purpose'               => $issuedTo,
            'remarks'               => $remarks,
        ] + $footer,
    ];
}

/** @return list<array<string, mixed>> */
function certificationSeedFieldLayout(string $certificateType): array
{
    $catalog = certificationFieldCatalog()[$certificateType] ?? [];
    $presets = certificationFieldCoordinatePresets()[$certificateType] ?? [];
    $fields = [];

    foreach ($catalog as $fieldName => $label) {
        $preset = $presets[$fieldName] ?? null;
        $fontSize = $fieldName === 'remarks' ? 9.0 : 10.0;
        $height = (float) ($preset['height_mm'] ?? 4.8);

        $fields[] = array_merge([
            'field_name'  => $fieldName,
            'label'       => $label,
            'font_family' => 'Arial',
            'font_size'   => $fontSize,
            'font_weight' => 'bold',
            'alignment'   => 'left',
            'max_length'  => $fieldName === 'remarks' ? 400 : 120,
            'line_height' => $fieldName === 'remarks' ? 1.25 : 1.20,
            'enabled'     => 1,
        ], $preset ?? [
            'x_mm'      => 22.0,
            'y_mm'      => 48.0,
            'width_mm'  => 120.0,
            'height_mm' => 4.8,
        ]);
    }

    return certificationApplyVerticalCoordScale($fields, $certificateType);
}

function certificationTemplateNeedsFieldReseed(PDO $pdo, int $templateId): bool
{
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM print_fields WHERE template_id = ?');
    $countStmt->execute([$templateId]);
    if ((int) $countStmt->fetchColumn() === 0) {
        return true;
    }

    $fieldStmt = $pdo->prepare(
        'SELECT field_name, x_mm, y_mm FROM print_fields WHERE template_id = ? ORDER BY id ASC LIMIT 1'
    );
    $fieldStmt->execute([$templateId]);
    $first = $fieldStmt->fetch(PDO::FETCH_ASSOC);
    if (!$first) {
        return true;
    }

    // Replace the old generic placeholder stack (province at x=22, y≈48).
    if (($first['field_name'] ?? '') === 'province') {
        return true;
    }
    if (abs((float) ($first['x_mm'] ?? 0) - 22.0) < 0.01
        && abs((float) ($first['y_mm'] ?? 0) - 48.0) < 0.01
        && ($first['field_name'] ?? '') !== 'page_number') {
        return true;
    }

    $storedVersion = (int) getSetting('certification_layout_version', '0');

    return $storedVersion < CERTIFICATION_LAYOUT_VERSION;
}

function reseedCertificationPrintFields(PDO $pdo, int $templateId, string $certificateType): void
{
    $pdo->prepare('DELETE FROM print_fields WHERE template_id = ?')->execute([$templateId]);
    seedPrintFieldsForTemplate($pdo, $templateId, $certificateType, 'front', certificationSeedFieldLayout($certificateType));
}

/** @return array<string, string> */
function certificationBuildFieldValues(array $record, string $certificateType, array $options = []): array
{
    $values = [];
    $today = printDateParts(date('Y-m-d'));

    $values['page_number'] = trim((string) ($record['page_number'] ?? ''));
    $values['book_number'] = trim((string) ($record['book_number'] ?? ''));
    $values['registry_number'] = trim((string) ($record['registry_number'] ?? ''));
    $values['registration_date'] = printFormatDateField($record['registration_date'] ?? null);
    $values['certification_date'] = printFormatDateField(date('Y-m-d'));
    $values['purpose'] = trim((string) ($options['purpose'] ?? ''));
    $values['remarks'] = trim((string) ($record['notes'] ?? ''));
    $values['amount_paid'] = trim((string) ($options['amount_paid'] ?? getSetting('certification_amount_paid', '')));
    $values['or_number'] = trim((string) ($options['or_number'] ?? ''));
    $values['registrar_name'] = getSetting('registrar_name', getSetting('print_registrar_name', ''));
    $values['verified_by'] = getSetting('certification_verified_by', getSetting('registration_officer_name', ''));

    if ($certificateType === 'birth') {
        $values['subject_full_name'] = civilRecordDisplayName($record);
        $values['sex'] = ucfirst(strtolower((string) ($record['sex'] ?? '')));
        $values['birth_date'] = printFormatDateField($record['birth_date'] ?? $record['event_date'] ?? null);
        $values['birth_place'] = trim((string) ($record['place'] ?? ''));
        $values['mother_name'] = trim((string) ($record['mother_name'] ?? ''));
        $values['mother_citizenship'] = trim((string) ($record['mother_nationality'] ?? ''));
        $values['father_name'] = trim((string) ($record['father_name'] ?? ''));
        $values['father_citizenship'] = trim((string) ($record['father_nationality'] ?? ''));
        $values['parents_marriage_date'] = printFormatDateField($record['parents_marriage_date'] ?? null);
        $values['parents_marriage_place'] = trim((string) ($record['parents_marriage_place'] ?? ''));
    } elseif ($certificateType === 'death') {
        $values['subject_full_name'] = civilRecordDisplayName($record);
        $values['sex'] = ucfirst(strtolower((string) ($record['sex'] ?? '')));
        $values['age_at_death'] = printAgeAtDeathText($record);
        $values['civil_status'] = trim((string) ($record['civil_status'] ?? ''));
        $values['citizenship'] = trim((string) ($record['nationality'] ?? ''));
        $values['death_date'] = printFormatDateField($record['event_date'] ?? null);
        $values['death_place'] = trim((string) ($record['place'] ?? ''));
        $values['cause_of_death'] = certificationCauseOfDeath($record);
    } elseif ($certificateType === 'marriage') {
        $values['husband_name'] = trim((string) ($record['husband_name'] ?? ''));
        $values['husband_age'] = isset($record['husband_age']) ? (string) $record['husband_age'] : '';
        $values['husband_citizenship'] = trim((string) ($record['husband_citizenship'] ?? ''));
        $values['husband_civil_status'] = trim((string) ($record['husband_civil_status'] ?? ''));
        $values['husband_father_name'] = trim((string) ($record['husband_father_name'] ?? ''));
        $values['husband_mother_name'] = trim((string) ($record['husband_mother_maiden_name'] ?? ''));
        $values['wife_name'] = trim((string) ($record['wife_name'] ?? ''));
        $values['wife_age'] = isset($record['wife_age']) ? (string) $record['wife_age'] : '';
        $values['wife_citizenship'] = trim((string) ($record['wife_citizenship'] ?? ''));
        $values['wife_civil_status'] = trim((string) ($record['wife_civil_status'] ?? ''));
        $values['wife_father_name'] = trim((string) ($record['wife_father_name'] ?? ''));
        $values['wife_mother_name'] = trim((string) ($record['wife_mother_maiden_name'] ?? ''));
        $values['marriage_date'] = printFormatDateField($record['event_date'] ?? null);
        $values['marriage_place'] = trim((string) ($record['place'] ?? ''));
    }

    $fill = printParseFillData($record['print_fill_data'] ?? null);
    foreach ($fill as $key => $value) {
        if (array_key_exists($key, $values) && trim((string) $values[$key]) === '' && trim((string) $value) !== '') {
            $values[$key] = trim((string) $value);
        }
    }

    if (empty($options['keep_empty'])) {
        foreach ($values as $key => $value) {
            if (trim((string) $value) === '') {
                unset($values[$key]);
            }
        }
    }

    return $values;
}

function seedCertificationPrintTemplates(PDO $pdo): void
{
    ensurePrintDocumentKindColumn($pdo);
    ensureCertificationPaperAspect($pdo);

    foreach (printCertificateTypes() as $type) {
        $paper = certificationPaperSize($type);
        $stmt = $pdo->prepare(
            'SELECT id FROM print_templates
             WHERE certificate_type = ? AND page_side = ? AND document_kind = ? LIMIT 1'
        );
        $stmt->execute([$type, 'front', 'certification']);
        $existingId = $stmt->fetchColumn();

        if (!$existingId) {
            $insert = $pdo->prepare(
                'INSERT INTO print_templates
                 (certificate_type, document_kind, page_side, form_number, paper_width_mm, paper_height_mm,
                  orientation, margin_top_mm, margin_left_mm, reference_image)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $type,
                'certification',
                'front',
                certificationFormNumber($type),
                $paper['paper_width_mm'],
                $paper['paper_height_mm'],
                $paper['orientation'],
                $paper['margin_top_mm'],
                $paper['margin_left_mm'],
                certificationFormReferenceImage($type),
            ]);
            $existingId = (int) $pdo->lastInsertId();
        } else {
            $existingId = (int) $existingId;
            $pdo->prepare(
                'UPDATE print_templates
                 SET form_number = ?, paper_width_mm = ?, paper_height_mm = ?, orientation = ?,
                     margin_top_mm = ?, margin_left_mm = ?, reference_image = ?
                 WHERE id = ?'
            )->execute([
                certificationFormNumber($type),
                $paper['paper_width_mm'],
                $paper['paper_height_mm'],
                $paper['orientation'],
                $paper['margin_top_mm'],
                $paper['margin_left_mm'],
                certificationFormReferenceImage($type),
                $existingId,
            ]);
        }

        if (certificationTemplateNeedsFieldReseed($pdo, $existingId)) {
            reseedCertificationPrintFields($pdo, $existingId, $type);
        }

        $calStmt = $pdo->prepare('SELECT id FROM print_calibrations WHERE template_id = ? LIMIT 1');
        $calStmt->execute([$existingId]);
        if (!$calStmt->fetchColumn()) {
            $pdo->prepare(
                'INSERT INTO print_calibrations (template_id, x_offset_mm, y_offset_mm, scale_x, scale_y)
                 VALUES (?, 0, 0, 1, 1)'
            )->execute([$existingId]);
        }
    }

    setSetting('certification_layout_version', (string) CERTIFICATION_LAYOUT_VERSION);
}

function printCertificationFillEditorFields(string $certificateType, array $record, array $overrides = []): array
{
    $values = certificationBuildFieldValues($record, $certificateType, ['keep_empty' => true]);
    $values = printApplyFillOverrides($values, $overrides);
    $catalog = certificationFieldCatalog()[$certificateType] ?? [];
    $fields = [];

    foreach ($catalog as $fieldName => $label) {
        $fields[] = [
            'field_name' => $fieldName,
            'label'      => $label,
            'value'      => (string) ($values[$fieldName] ?? ''),
            'page_side'  => 'front',
        ];
    }

    return $fields;
}

function printCertification(PDO $pdo, string $certificateType, array $record, array $options = []): ?array
{
    if (!in_array($certificateType, printCertificateTypes(), true)) {
        return null;
    }

    $template = getPrintTemplate($pdo, $certificateType, 'front', 'certification');
    if (!$template) {
        return null;
    }

    $fields = getPrintFields($pdo, (int) $template['id'], empty($options['include_disabled_fields']));
    $values = certificationBuildFieldValues($record, $certificateType, $options);
    $values = printApplyFillOverrides($values, $options['fill_overrides'] ?? []);

    return [
        'template'             => $template,
        'fields'               => $fields,
        'values'               => $values,
        'template_calibration' => getPrintCalibration($pdo, (int) $template['id']),
        'global_calibration'   => printGlobalCalibration(),
        'mode'                 => $options['mode'] ?? printMode(),
        'test_mode'            => !empty($options['test_mode']),
        'document_kind'        => 'certification',
    ];
}

function certificationCalibrationSampleRecord(string $certificateType): array
{
    $record = printCalibrationSampleRecord($certificateType);
    $record['book_number'] = '12';
    $record['page_number'] = '45';
    $record['registry_number'] = '2024-001234';
    $record['registration_date'] = $record['registration_date'] ?? '2024-03-20';

    if ($certificateType === 'death') {
        $record['nationality'] = 'Filipino';
        $record['immediate_cause'] = 'Cardiac arrest';
        $record['contributory_cause'] = 'Hypertension';
    } elseif ($certificateType === 'marriage') {
        $record['husband_civil_status'] = 'Single';
        $record['wife_civil_status'] = 'Single';
        $record['husband_father_name'] = 'Pedro Dela Cruz';
        $record['husband_mother_maiden_name'] = 'Rosa Santos';
        $record['wife_father_name'] = 'Jose Santos';
        $record['wife_mother_maiden_name'] = 'Carmen Reyes';
        $record['event_date'] = $record['event_date'] ?? '2024-02-14';
    }

    return $record;
}

function certificationCalibrationSampleValues(string $certificateType): array
{
    return certificationBuildFieldValues(
        certificationCalibrationSampleRecord($certificateType),
        $certificateType,
        ['keep_empty' => true]
    );
}

function syncCertificationFieldCoordinatesFromPresets(PDO $pdo, string $certificateType): void
{
    $template = getPrintTemplate($pdo, $certificateType, 'front', 'certification');
    if (!$template) {
        return;
    }

    $layout = certificationSeedFieldLayout($certificateType);
    foreach ($layout as $field) {
        $pdo->prepare(
            'UPDATE print_fields SET x_mm = ?, y_mm = ?, width_mm = ?, height_mm = ?, updated_at = NOW()
             WHERE template_id = ? AND field_name = ?'
        )->execute([
            $field['x_mm'],
            $field['y_mm'],
            $field['width_mm'],
            $field['height_mm'],
            (int) $template['id'],
            $field['field_name'],
        ]);
    }
}

function printCertificationContext(PDO $pdo, int $recordId = 0, int $requestId = 0): array
{
    if ($requestId > 0) {
        $context = printRequestContext($pdo, $requestId, null);
        if (!$context['ok']) {
            return $context;
        }
        if (($context['record_source'] ?? '') !== 'civil_record') {
            return [
                'ok'    => false,
                'error' => 'Certification requires an existing civil registry record linked to this request.',
            ];
        }

        $context['document_kind'] = 'certification';

        return $context;
    }

    if ($recordId <= 0) {
        return ['ok' => false, 'error' => 'Select an existing civil registry record to print a certification.'];
    }

    $record = fetchFullCivilRecord($pdo, $recordId);
    if (!$record) {
        return ['ok' => false, 'error' => 'Civil registry record not found.'];
    }

    $certificateType = (string) ($record['record_type'] ?? '');
    if (!in_array($certificateType, printCertificateTypes(), true)) {
        return ['ok' => false, 'error' => 'Unsupported record type for certification.'];
    }

    return [
        'ok'               => true,
        'request'          => null,
        'record'           => $record,
        'certificate_type' => $certificateType,
        'record_source'    => 'civil_record',
        'document_kind'    => 'certification',
        'error'            => null,
    ];
}
