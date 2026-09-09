<?php
/**
 * ALCROS Target Printing / Form Overlay Printing Engine
 * Municipal Forms 102, 97, 103 (Revised August 2016)
 */

require_once __DIR__ . '/civil_record_schema.php';
require_once __DIR__ . '/print_field_definitions.php';
require_once __DIR__ . '/print_form_svgs.php';
require_once __DIR__ . '/print_printer_setup.php';

function ensurePrintTables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS print_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            certificate_type ENUM('birth','death','marriage') NOT NULL,
            page_side ENUM('front','back') NOT NULL,
            form_number VARCHAR(10) NOT NULL,
            paper_width_mm DECIMAL(8,2) NOT NULL DEFAULT 215.90,
            paper_height_mm DECIMAL(8,2) NOT NULL DEFAULT 358.90,
            orientation ENUM('portrait','landscape') NOT NULL DEFAULT 'portrait',
            margin_top_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            margin_left_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            reference_image VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cert_page (certificate_type, page_side)
        ) ENGINE=InnoDB"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS print_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            field_name VARCHAR(80) NOT NULL,
            label VARCHAR(120) DEFAULT NULL,
            x_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            y_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            width_mm DECIMAL(8,2) NOT NULL DEFAULT 50.00,
            height_mm DECIMAL(8,2) NOT NULL DEFAULT 5.00,
            font_family VARCHAR(60) NOT NULL DEFAULT 'Arial',
            font_size DECIMAL(4,1) NOT NULL DEFAULT 10.0,
            font_weight VARCHAR(20) NOT NULL DEFAULT 'normal',
            alignment ENUM('left','center','right') NOT NULL DEFAULT 'left',
            max_length INT NOT NULL DEFAULT 120,
            line_height DECIMAL(4,2) NOT NULL DEFAULT 1.20,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_template_field (template_id, field_name),
            CONSTRAINT fk_print_fields_template
                FOREIGN KEY (template_id) REFERENCES print_templates(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS print_calibrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            x_offset_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            y_offset_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            scale_x DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
            scale_y DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
            updated_by VARCHAR(50) DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_template_calibration (template_id),
            CONSTRAINT fk_print_calibrations_template
                FOREIGN KEY (template_id) REFERENCES print_templates(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS print_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT DEFAULT NULL,
            civil_record_id INT DEFAULT NULL,
            template_id INT NOT NULL,
            page_side ENUM('front','back') NOT NULL,
            certificate_type ENUM('birth','death','marriage') NOT NULL,
            registry_number VARCHAR(50) DEFAULT NULL,
            printer_name VARCHAR(120) DEFAULT NULL,
            printed_by VARCHAR(50) DEFAULT NULL,
            print_mode ENUM('preview','test','production') NOT NULL DEFAULT 'production',
            copies INT NOT NULL DEFAULT 1,
            status ENUM('queued','completed','failed','cancelled') NOT NULL DEFAULT 'completed',
            notes TEXT DEFAULT NULL,
            printed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_request (request_id),
            INDEX idx_record (civil_record_id),
            INDEX idx_printed (printed_at),
            CONSTRAINT fk_print_jobs_template
                FOREIGN KEY (template_id) REFERENCES print_templates(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB"
        );
    } catch (Throwable $e) {
        // Tables may already exist; continue with best-effort seed/sync below.
    }

    foreach ([
        static fn (PDO $db) => ensurePrintRequestColumns($db),
        static fn (PDO $db) => ensurePrintRequestStatuses($db),
        static fn (PDO $db) => seedPrintTemplates($db),
        static fn (PDO $db) => syncPrintPaperDimensions($db),
        static fn (PDO $db) => ensurePrintFormReferenceFiles($db),
        static fn (PDO $db) => ensurePrintFieldLeftAlignment($db),
        static fn (PDO $db) => syncPrintFieldsFromCatalog($db),
        static fn (PDO $db) => restorePrintFieldUserPositionsAfterBulkSync($db),
        static fn (PDO $db) => syncPrintFieldFontSizes($db),
        static fn (PDO $db) => seedCertificationPrintTemplatesIfAvailable($db),
    ] as $step) {
        try {
            $step($pdo);
        } catch (Throwable $e) {
            // Non-fatal — keep print pages usable if one migration step fails.
        }
    }
}

function ensureCivilRecordPrintSchema(PDO $pdo): void
{
    ensureCivilRecordTypeTables($pdo);

    try {
        $pdo->query('SELECT print_fill_data FROM document_requests LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE document_requests ADD COLUMN print_fill_data JSON NULL AFTER civil_record_id');
        } catch (Throwable $ignored) {
        }
    }
}

/** @return array<string, string> */
function printParseFillData(mixed $raw): array
{
    if (is_array($raw)) {
        return array_map(static fn ($v) => trim((string) $v), $raw);
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded)
        ? array_map(static fn ($v) => trim((string) $v), $decoded)
        : [];
}

function printFormatDateField(?string $date): string
{
    $parts = printDateParts($date);
    if ($parts['day'] === '' && $parts['month'] === '' && $parts['year'] === '') {
        return '';
    }

    return trim(($parts['month'] . ' ' . $parts['day'] . ', ' . $parts['year']));
}

function syncPrintFieldFontSizes(PDO $pdo): void
{
    if (getSetting('print_field_font_size_version', '') === '2') {
        return;
    }

    try {
        $pdo->exec('UPDATE print_fields SET font_size = 10.0, updated_at = NOW()');
    } catch (Throwable $e) {
        // ignore if migration cannot run
    }

    setSetting('print_field_font_size_version', '2');
}

function printNormalizeAlignment(?string $alignment): string
{
    $align = (string) $alignment;

    return in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
}

function printAlignmentJustifyContent(?string $alignment): string
{
    return match (printNormalizeAlignment($alignment)) {
        'right'  => 'flex-end',
        'center' => 'center',
        default  => 'flex-start',
    };
}

function ensurePrintFieldLeftAlignment(PDO $pdo): void
{
    if (getSetting('print_field_alignment_left', '') === '1') {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE print_fields MODIFY COLUMN alignment ENUM('left','center','right') NOT NULL DEFAULT 'left'");
        $pdo->exec("UPDATE print_fields SET alignment = 'left' WHERE alignment = 'center'");
    } catch (Throwable $e) {
        // ignore if migration cannot run
    }

    setSetting('print_field_alignment_left', '1');
}

function syncPrintPaperDimensions(PDO $pdo): void
{
    $paper = printDefaultPaperSize();
    $width = $paper['paper_width_mm'];
    $height = $paper['paper_height_mm'];

    if (getSetting('print_paper_size_version', '') !== 'official_municipal_v1') {
        try {
            $pdo->exec("ALTER TABLE print_templates MODIFY paper_width_mm DECIMAL(8,2) NOT NULL DEFAULT {$width}");
            $pdo->exec("ALTER TABLE print_templates MODIFY paper_height_mm DECIMAL(8,2) NOT NULL DEFAULT {$height}");
        } catch (Throwable $e) {
            // ignore if migration cannot run
        }
        setSetting('print_paper_size_version', 'official_municipal_v1');
    }

    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM print_templates WHERE paper_width_mm <> ? OR paper_height_mm <> ?'
    );
    $check->execute([$width, $height]);
    if ((int) $check->fetchColumn() === 0) {
        return;
    }

    $pdo->prepare(
        'UPDATE print_templates SET paper_width_mm = ?, paper_height_mm = ?, updated_at = NOW()
         WHERE paper_width_mm <> ? OR paper_height_mm <> ?'
    )->execute([$width, $height, $width, $height]);
}

function ensurePrintRequestColumns(PDO $pdo): void
{
    try {
        $pdo->query('SELECT civil_record_id FROM document_requests LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE document_requests ADD COLUMN civil_record_id INT DEFAULT NULL AFTER status');
        } catch (Throwable $ignored) {
        }
    }
}

function ensurePrintRequestStatuses(PDO $pdo): void
{
    try {
        $row = $pdo->query("SHOW COLUMNS FROM document_requests LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string) ($row['Type'] ?? '');
        $needed = ['processing', 'printing', 'printed', 'quality_check'];
        $missing = array_filter($needed, static fn ($s) => stripos($type, "'{$s}'") === false);
        if ($missing !== []) {
            $pdo->exec(
                "ALTER TABLE document_requests MODIFY status ENUM(
                    'pending','processing','printing','printed','quality_check',
                    'verified','ready','completed','rejected'
                ) NOT NULL DEFAULT 'pending'"
            );
        }
    } catch (Throwable $e) {
        // ignore if migration cannot run
    }
}

function syncPrintFieldsFromCatalog(PDO $pdo): void
{
    $targetVersion = '3';
    if (getSetting('print_field_catalog_version', '') === $targetVersion) {
        return;
    }

    try {
        foreach (['birth', 'marriage', 'death'] as $type) {
            foreach (['front', 'back'] as $side) {
                $template = getPrintTemplate($pdo, $type, $side);
                if (!$template) {
                    continue;
                }

                $templateId = (int) $template['id'];
                $existingStmt = $pdo->prepare('SELECT field_name FROM print_fields WHERE template_id = ?');
                $existingStmt->execute([$templateId]);
                $existingNames = array_flip($existingStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

                $layout = printSeedFieldLayout($type, $side);
                $insert = $pdo->prepare(
                    'INSERT INTO print_fields
                     (template_id, field_name, label, x_mm, y_mm, width_mm, height_mm,
                      font_family, font_size, font_weight, alignment, max_length, line_height, enabled)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $insertedNames = [];
                foreach ($layout as $field) {
                    if (isset($existingNames[$field['field_name']])) {
                        continue;
                    }
                    $insert->execute([
                        $templateId,
                        $field['field_name'],
                        $field['label'],
                        $field['x_mm'],
                        $field['y_mm'],
                        $field['width_mm'],
                        $field['height_mm'],
                        $field['font_family'],
                        $field['font_size'],
                        $field['font_weight'],
                        $field['alignment'],
                        $field['max_length'],
                        $field['line_height'],
                        $field['enabled'],
                    ]);
                    $insertedNames[] = $field['field_name'];
                }

                if ($insertedNames !== []) {
                    syncPrintFieldCoordinatesFromPresets($pdo, $type, $side, $insertedNames);
                }
            }
        }
    } catch (Throwable $e) {
        // ignore if migration cannot run
    }

    setSetting('print_field_catalog_version', $targetVersion);
}

/**
 * Restore staff-calibrated positions overwritten by the bulk preset sync (v3).
 * Only runs once; never overwrites fields the user saved afterward.
 */
function restorePrintFieldUserPositionsAfterBulkSync(PDO $pdo): void
{
    if (getSetting('print_field_position_restore_v1', '') === '1') {
        return;
    }

    $snapshots = [
        'birth' => [
            'front' => [
                'province'                 => ['x' => 37.00, 'y' => 33.50, 'w' => 86.00, 'h' => 4.50],
                'registry_number'          => ['x' => 157.32, 'y' => 36.40, 'w' => 27.80, 'h' => 5.93],
                'city_municipality'        => ['x' => 50.00, 'y' => 39.50, 'w' => 76.00, 'h' => 4.50],
                'child_first_name'         => ['x' => 47.00, 'y' => 50.50, 'w' => 54.00, 'h' => 4.50],
                'child_middle_name'        => ['x' => 97.00, 'y' => 50.50, 'w' => 45.00, 'h' => 4.50],
                'child_last_name'          => ['x' => 147.00, 'y' => 50.50, 'w' => 58.00, 'h' => 4.50],
                'sex'                      => ['x' => 42.00, 'y' => 59.50, 'w' => 29.00, 'h' => 4.50],
                'birth_day'                => ['x' => 115.00, 'y' => 59.50, 'w' => 17.00, 'h' => 4.50],
                'birth_month'              => ['x' => 141.00, 'y' => 59.50, 'w' => 22.00, 'h' => 4.50],
                'birth_year'               => ['x' => 176.00, 'y' => 59.50, 'w' => 25.00, 'h' => 4.50],
                'birth_place'              => ['x' => 58.90, 'y' => 70.50, 'w' => 147.00, 'h' => 4.50],
                'birth_type'               => ['x' => 29.00, 'y' => 84.50, 'w' => 41.00, 'h' => 4.50],
                'multiple_birth_child_was' => ['x' => 78.00, 'y' => 84.50, 'w' => 44.00, 'h' => 4.50],
                'birth_order'              => ['x' => 131.00, 'y' => 84.50, 'w' => 35.00, 'h' => 4.50],
                'birth_weight'             => ['x' => 176.00, 'y' => 84.50, 'w' => 21.00, 'h' => 4.50],
                'mother_last_name'         => ['x' => 155.00, 'y' => 94.50, 'w' => 50.00, 'h' => 5.50],
                'mother_middle_name'       => ['x' => 101.00, 'y' => 95.00, 'w' => 34.04, 'h' => 5.73],
                'mother_first_name'        => ['x' => 46.00, 'y' => 95.50, 'w' => 45.00, 'h' => 4.50],
                'mother_citizenship'       => ['x' => 28.00, 'y' => 104.50, 'w' => 84.00, 'h' => 4.50],
                'mother_religion'          => ['x' => 119.00, 'y' => 104.50, 'w' => 87.00, 'h' => 4.50],
                'mother_age'               => ['x' => 177.50, 'y' => 117.17, 'w' => 27.47, 'h' => 4.50],
                'mother_children_born_alive' => ['x' => 30.00, 'y' => 117.50, 'w' => 21.00, 'h' => 4.50],
                'mother_children_still_living' => ['x' => 56.00, 'y' => 117.50, 'w' => 25.00, 'h' => 4.50],
                'mother_children_born_alive_now_dead' => ['x' => 88.00, 'y' => 117.50, 'w' => 29.00, 'h' => 4.50],
                'mother_occupation'        => ['x' => 121.00, 'y' => 117.50, 'w' => 52.00, 'h' => 4.50],
                'mother_residence'         => ['x' => 43.84, 'y' => 128.18, 'w' => 162.00, 'h' => 4.50],
                'father_first_name'        => ['x' => 43.00, 'y' => 139.50, 'w' => 43.00, 'h' => 4.50],
                'father_middle_name'       => ['x' => 94.00, 'y' => 139.50, 'w' => 44.00, 'h' => 4.50],
                'father_last_name'         => ['x' => 152.00, 'y' => 139.50, 'w' => 49.00, 'h' => 4.50],
                'father_occupation'        => ['x' => 127.71, 'y' => 149.69, 'w' => 44.34, 'h' => 4.50],
                'father_citizenship'       => ['x' => 27.00, 'y' => 150.50, 'w' => 42.92, 'h' => 4.50],
                'father_religion'          => ['x' => 73.76, 'y' => 150.62, 'w' => 48.97, 'h' => 4.50],
                'father_age'               => ['x' => 176.54, 'y' => 151.21, 'w' => 29.71, 'h' => 4.50],
                'father_residence'         => ['x' => 47.29, 'y' => 162.81, 'w' => 158.00, 'h' => 4.50],
                'parents_marriage_place'   => ['x' => 106.59, 'y' => 178.03, 'w' => 99.45, 'h' => 4.50],
                'parents_marriage_month'   => ['x' => 39.00, 'y' => 178.45, 'w' => 16.00, 'h' => 4.50],
                'parents_marriage_day'     => ['x' => 55.98, 'y' => 178.45, 'w' => 18.00, 'h' => 4.50],
                'parents_marriage_year'    => ['x' => 74.00, 'y' => 178.45, 'w' => 17.00, 'h' => 4.50],
                'attendant_type'           => ['x' => 21.33, 'y' => 191.68, 'w' => 8.00, 'h' => 4.00],
                'birth_time'               => ['x' => 129.18, 'y' => 199.96, 'w' => 18.30, 'h' => 4.50],
                'attendant_cert_address'   => ['x' => 129.77, 'y' => 208.77, 'w' => 72.51, 'h' => 4.50],
                'attendant_cert_name'      => ['x' => 41.98, 'y' => 214.61, 'w' => 67.00, 'h' => 4.50],
                'attendant_cert_date'      => ['x' => 122.83, 'y' => 219.46, 'w' => 59.00, 'h' => 4.00],
                'attendant_cert_title'     => ['x' => 43.00, 'y' => 221.00, 'w' => 67.00, 'h' => 4.00],
                'informant_name'           => ['x' => 41.00, 'y' => 245.00, 'w' => 69.00, 'h' => 4.00],
                'prepared_by_name'         => ['x' => 137.00, 'y' => 246.00, 'w' => 61.00, 'h' => 4.00],
                'informant_relationship'   => ['x' => 53.00, 'y' => 251.00, 'w' => 50.00, 'h' => 4.00],
                'prepared_by_title'        => ['x' => 139.00, 'y' => 252.00, 'w' => 58.00, 'h' => 4.00],
                'informant_address'        => ['x' => 34.00, 'y' => 257.00, 'w' => 78.00, 'h' => 4.00],
                'prepared_by_date'         => ['x' => 132.00, 'y' => 257.00, 'w' => 40.00, 'h' => 4.00],
                'informant_date'           => ['x' => 37.00, 'y' => 263.00, 'w' => 40.00, 'h' => 4.00],
                'received_by_name'         => ['x' => 43.00, 'y' => 279.00, 'w' => 60.00, 'h' => 4.00],
                'registrar_name'           => ['x' => 138.00, 'y' => 279.00, 'w' => 65.00, 'h' => 4.00],
                'received_by_title'        => ['x' => 44.00, 'y' => 285.00, 'w' => 62.00, 'h' => 4.00],
                'registrar_title'          => ['x' => 140.00, 'y' => 285.00, 'w' => 64.00, 'h' => 4.00],
                'registrar_date'           => ['x' => 134.00, 'y' => 291.00, 'w' => 40.00, 'h' => 4.00],
                'received_by_date'         => ['x' => 37.91, 'y' => 291.77, 'w' => 40.00, 'h' => 4.00],
                'remarks_annotations'      => ['x' => 21.80, 'y' => 301.97, 'w' => 182.00, 'h' => 20.00],
                'lcro_box_19'              => ['x' => 156.76, 'y' => 334.35, 'w' => 27.00, 'h' => 5.00],
                'lcro_box_17'              => ['x' => 136.66, 'y' => 334.53, 'w' => 20.00, 'h' => 5.00],
                'lcro_box_9'               => ['x' => 34.57, 'y' => 334.90, 'w' => 13.00, 'h' => 5.00],
                'lcro_box_13'              => ['x' => 66.73, 'y' => 334.90, 'w' => 45.00, 'h' => 5.00],
                'lcro_box_15'              => ['x' => 112.00, 'y' => 334.90, 'w' => 11.00, 'h' => 5.00],
                'lcro_box_16'              => ['x' => 123.87, 'y' => 334.90, 'w' => 12.00, 'h' => 5.00],
                'lcro_box_8'               => ['x' => 22.78, 'y' => 335.08, 'w' => 11.00, 'h' => 5.00],
                'lcro_box_11'              => ['x' => 47.81, 'y' => 335.08, 'w' => 19.00, 'h' => 5.00],
            ],
        ],
        'death' => [
            'front' => [
                'religion' => ['x' => 19.43, 'y' => 89.02, 'w' => 48.15, 'h' => 5.68],
            ],
        ],
    ];

    try {
        $update = $pdo->prepare(
            'UPDATE print_fields SET x_mm = ?, y_mm = ?, width_mm = ?, height_mm = ?, updated_at = NOW()
             WHERE template_id = ? AND field_name = ?'
        );

        foreach ($snapshots as $type => $sides) {
            foreach ($sides as $side => $fields) {
                $template = getPrintTemplate($pdo, $type, $side);
                if (!$template) {
                    continue;
                }
                $templateId = (int) $template['id'];
                foreach ($fields as $fieldName => $pos) {
                    $update->execute([
                        $pos['x'],
                        $pos['y'],
                        $pos['w'],
                        $pos['h'],
                        $templateId,
                        $fieldName,
                    ]);
                }
            }
        }
    } catch (Throwable $e) {
        // ignore if migration cannot run
    }

    setSetting('print_field_position_restore_v1', '1');
}

function syncPrintFieldCoordinatesFromPresets(PDO $pdo, ?string $onlyType = null, ?string $onlySide = null, array $onlyFieldNames = []): void
{
    if ($onlyType === null || $onlySide === null) {
        return;
    }

    $template = getPrintTemplate($pdo, $onlyType, $onlySide);
    if (!$template) {
        return;
    }

    $layout = printSeedFieldLayout($onlyType, $onlySide);
    foreach ($layout as $field) {
        if ($onlyFieldNames !== [] && !in_array($field['field_name'], $onlyFieldNames, true)) {
            continue;
        }
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

function seedPrintTemplates(PDO $pdo): void
{
    $meta = printCertificateMeta();
    $paper = printDefaultPaperSize();

    foreach (['birth', 'marriage', 'death'] as $type) {
        foreach (['front', 'back'] as $side) {
            ensurePrintDocumentKindColumn($pdo);
            $stmt = $pdo->prepare(
                'SELECT id FROM print_templates
                 WHERE certificate_type = ? AND page_side = ? AND document_kind = ? LIMIT 1'
            );
            $stmt->execute([$type, $side, 'certificate']);
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
                    'certificate',
                    $side,
                    $meta[$type]['form_number'],
                    $paper['paper_width_mm'],
                    $paper['paper_height_mm'],
                    $paper['orientation'],
                    $paper['margin_top_mm'],
                    $paper['margin_left_mm'],
                    printFormReferenceImage($type, $side),
                ]);
                $existingId = (int) $pdo->lastInsertId();
            }

            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM print_fields WHERE template_id = ?');
            $countStmt->execute([(int) $existingId]);
            if ((int) $countStmt->fetchColumn() === 0) {
                seedPrintFieldsForTemplate($pdo, (int) $existingId, $type, $side);
            }

            $calStmt = $pdo->prepare('SELECT id FROM print_calibrations WHERE template_id = ? LIMIT 1');
            $calStmt->execute([(int) $existingId]);
            if (!$calStmt->fetchColumn()) {
                $pdo->prepare(
                    'INSERT INTO print_calibrations (template_id, x_offset_mm, y_offset_mm, scale_x, scale_y)
                     VALUES (?, 0, 0, 1, 1)'
                )->execute([(int) $existingId]);
            }
        }
    }
}

function seedCertificationPrintTemplatesIfAvailable(PDO $pdo): void
{
    $path = __DIR__ . '/certification_print.php';
    if (!is_file($path)) {
        return;
    }
    require_once $path;
    if (function_exists('seedCertificationPrintTemplates')) {
        seedCertificationPrintTemplates($pdo);
    }
}

function ensurePrintDocumentKindColumn(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT document_kind FROM print_templates LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                "ALTER TABLE print_templates
                 ADD COLUMN document_kind VARCHAR(20) NOT NULL DEFAULT 'certificate' AFTER certificate_type"
            );
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->exec('ALTER TABLE print_templates DROP INDEX uniq_cert_page');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec(
            'ALTER TABLE print_templates
             ADD UNIQUE KEY uniq_cert_page_kind (certificate_type, page_side, document_kind)'
        );
    } catch (Throwable $e) {
    }
}

function normalizePrintDocumentKind(?string $kind): string
{
    return strtolower(trim((string) $kind)) === 'certification' ? 'certification' : 'certificate';
}

function seedPrintFieldsForTemplate(PDO $pdo, int $templateId, string $certificateType, string $pageSide, ?array $layoutOverride = null): void
{
    $layout = $layoutOverride ?? printSeedFieldLayout($certificateType, $pageSide);
    $insert = $pdo->prepare(
        'INSERT INTO print_fields
         (template_id, field_name, label, x_mm, y_mm, width_mm, height_mm,
          font_family, font_size, font_weight, alignment, max_length, line_height, enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($layout as $field) {
        $insert->execute([
            $templateId,
            $field['field_name'],
            $field['label'],
            $field['x_mm'],
            $field['y_mm'],
            $field['width_mm'],
            $field['height_mm'],
            $field['font_family'],
            $field['font_size'],
            $field['font_weight'],
            $field['alignment'],
            $field['max_length'],
            $field['line_height'],
            $field['enabled'],
        ]);
    }
}

function printMode(): string
{
    $mode = getSetting('print_mode', 'preprinted');
    return in_array($mode, ['preprinted', 'digital'], true) ? $mode : 'preprinted';
}

function printGlobalCalibration(): array
{
    return [
        'x_offset_mm' => (float) getSetting('print_global_x_offset_mm', '0'),
        'y_offset_mm' => (float) getSetting('print_global_y_offset_mm', '0'),
        'scale_x'     => (float) getSetting('print_global_scale_x', '1'),
        'scale_y'     => (float) getSetting('print_global_scale_y', '1'),
        'back_orientation_hint' => getSetting('print_back_orientation_hint', 'flip_long_edge'),
    ];
}

/** Shared paper size for all workstations (stored in settings, not browser localStorage). */
function printPaperPreferences(): array
{
    $presets = printPaperPresets();
    $preset = getSetting('print_paper_preset', 'legal');
    if (!isset($presets[$preset])) {
        $preset = 'legal';
    }

    $widthMm = (float) getSetting('print_paper_width_mm', '0');
    $heightMm = (float) getSetting('print_paper_height_mm', '0');
    if ($widthMm <= 0 || $heightMm <= 0) {
        $fallback = $presets[$preset];
        if ($preset !== 'custom' && !empty($fallback['width_mm']) && !empty($fallback['height_mm'])) {
            $widthMm = (float) $fallback['width_mm'];
            $heightMm = (float) $fallback['height_mm'];
        } else {
            $builtIn = printBuiltInPaperSpec();
            $widthMm = (float) $builtIn['width_mm'];
            $heightMm = (float) $builtIn['height_mm'];
            $preset = printPaperPresetKeyForSize($widthMm, $heightMm);
        }
    }

    return [
        'preset'    => $preset,
        'width_mm'  => $widthMm,
        'height_mm' => $heightMm,
    ];
}

function savePrintPaperPreferences(string $preset, float $widthMm, float $heightMm): void
{
    if ($widthMm <= 0 || $heightMm <= 0) {
        throw new InvalidArgumentException('Paper width and height must be greater than zero.');
    }

    $presets = printPaperPresets();
    if (!isset($presets[$preset])) {
        $preset = printPaperPresetKeyForSize($widthMm, $heightMm);
    }

    setSetting('print_paper_preset', $preset);
    setSetting('print_paper_width_mm', (string) round($widthMm, 2));
    setSetting('print_paper_height_mm', (string) round($heightMm, 2));
}

function canCalibratePrintTemplates(): bool
{
    return isAdmin();
}

function bumpPrintCalibrationRevision(): void
{
    setSetting('print_calibration_rev', (string) time());
}

function printCalibrationRevision(): int
{
    return (int) getSetting('print_calibration_rev', '0');
}

function printCertificateTypes(): array
{
    return ['birth', 'death', 'marriage'];
}

function printRecordFromRequest(array $request): array
{
    $type = (string) ($request['document_type'] ?? 'birth');
    if (!in_array($type, printCertificateTypes(), true)) {
        return [];
    }

    $record = [
        'id'              => null,
        'record_type'     => $type,
        'first_name'      => trim((string) ($request['first_name'] ?? '')),
        'middle_name'     => trim((string) ($request['middle_name'] ?? '')),
        'last_name'       => trim((string) ($request['last_name'] ?? '')),
        'birth_date'      => (string) ($request['date_of_birth'] ?? ''),
        'sex'             => (string) ($request['sex'] ?? ''),
        'registry_number' => null,
        'print_fill_data' => $request['print_fill_data'] ?? null,
        '_print_source'   => 'request',
    ];

    $fullName = personNameFromRow($request);

    if ($type === 'marriage') {
        $record['event_date'] = (string) ($request['date_of_marriage'] ?? '');
        $sex = strtolower((string) ($request['sex'] ?? ''));
        if ($sex === 'female') {
            $record['wife_name'] = $fullName;
            $record['wife_birth_date'] = (string) ($request['date_of_birth'] ?? '');
        } else {
            $record['husband_name'] = $fullName;
            $record['husband_birth_date'] = (string) ($request['date_of_birth'] ?? '');
        }
    } elseif ($type === 'death') {
        $record['event_date'] = null;
    }

    return $record;
}

function resolveCivilRecordForRequest(PDO $pdo, array $request): ?array
{
    $recordId = (int) ($request['civil_record_id'] ?? 0);
    if ($recordId > 0) {
        $record = fetchFullCivilRecord($pdo, $recordId);
        if ($record) {
            return $record;
        }
    }

    $name = personNameFromRow($request);
    $dob = (string) ($request['date_of_birth'] ?? '');
    $type = (string) ($request['document_type'] ?? '');
    $dom = $request['date_of_marriage'] ?? null;

    if ($type === 'cenomar') {
        return null;
    }

    $match = findCivilRecordMatch($pdo, $name, $dob, $type, $dom);
    if ($match) {
        return fetchFullCivilRecord($pdo, (int) $match['id']);
    }

    $fallback = printRecordFromRequest($request);

    return $fallback !== [] ? $fallback : null;
}

function linkRequestToCivilRecord(PDO $pdo, int $requestId, int $recordId): void
{
    if ($requestId <= 0 || $recordId <= 0) {
        return;
    }
    $pdo->prepare('UPDATE document_requests SET civil_record_id = ? WHERE id = ? AND civil_record_id IS NULL')
        ->execute([$recordId, $requestId]);
}

function printFormReferenceAsset(string $certificateType, string $pageSide): array
{
    $relative = printFormReferenceImage($certificateType, $pageSide);
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $isSvg = str_ends_with(strtolower($relative), '.svg');
    $src = $relative;
    if (is_file($full)) {
        $src .= '?v=' . filemtime($full);
    }

    return [
        'relative'   => $relative,
        'src'        => $src,
        'full'       => $full,
        'exists'     => is_file($full),
        'is_svg'     => $isSvg,
        'inline_svg' => ($isSvg && is_file($full)) ? file_get_contents($full) : null,
        'label'      => basename($relative),
        'updated'    => is_file($full) ? date('M j, Y g:i A', filemtime($full)) : null,
    ];
}

function splitFullName(?string $fullName): array
{
    $fullName = trim((string) $fullName);
    if ($fullName === '') {
        return ['first' => '', 'middle' => '', 'last' => ''];
    }

    $parts = preg_split('/\s+/', $fullName) ?: [];
    if (count($parts) === 1) {
        return ['first' => $parts[0], 'middle' => '', 'last' => ''];
    }
    if (count($parts) === 2) {
        return ['first' => $parts[0], 'middle' => '', 'last' => $parts[1]];
    }

    return [
        'first'  => $parts[0],
        'middle' => implode(' ', array_slice($parts, 1, -1)),
        'last'   => $parts[count($parts) - 1],
    ];
}

function printDateParts(?string $date): array
{
    if ($date === null || $date === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
        return ['day' => '', 'month' => '', 'year' => ''];
    }

    $timestamp = strtotime($m[1] . '-' . $m[2] . '-' . $m[3] . ' 00:00:00');
    return [
        'day'   => ltrim($m[3], '0') ?: $m[3],
        'month' => $timestamp ? date('F', $timestamp) : $m[2],
        'year'  => $m[1],
    ];
}

function printOfficeLocationFields(): array
{
    return [
        'province'          => getSetting('print_province', 'Misamis Occidental'),
        'city_municipality' => getSetting('print_city_municipality', 'Aloran'),
    ];
}

function printCalibrationNavItems(string $activeType, string $activeSide, string $documentKind = 'certificate'): array
{
    $documentKind = normalizePrintDocumentKind($documentKind);
    $items = [];
    $sides = $documentKind === 'certification' ? ['front'] : ['front', 'back'];

    foreach (printCertificateTypes() as $type) {
        foreach ($sides as $side) {
            $params = ['type' => $type, 'page' => $side];
            if ($documentKind === 'certification') {
                $params['kind'] = 'certification';
            }
            $items[] = [
                'type'   => $type,
                'page'   => $side,
                'label'  => ucfirst($type) . ' · ' . ucfirst($side),
                'short'  => strtoupper(substr($type, 0, 1)) . ($side === 'front' ? ' F' : ' B'),
                'url'    => buildAuthUrl('print_calibration.php', $params),
                'active' => $type === $activeType && $side === $activeSide,
            ];
        }
    }

    return $items;
}

function printCalibrationSampleRecord(string $certificateType): array
{
    $base = [
        'registry_number' => '2024-001234',
        'place'           => 'Barangay Sample, Aloran',
    ];

    return match ($certificateType) {
        'birth' => array_merge($base, [
            'first_name'              => 'Maria',
            'middle_name'             => 'Santos',
            'last_name'               => 'Reyes',
            'sex'                     => 'female',
            'birth_date'              => '2024-03-15',
            'birth_type'              => 'Live Birth',
            'birth_order'             => '1',
            'mother_name'             => 'Ana Santos Garcia',
            'mother_nationality'      => 'Filipino',
            'mother_religion'         => 'Roman Catholic',
            'mother_age'              => 28,
            'father_name'             => 'Juan Dela Reyes',
            'father_nationality'      => 'Filipino',
            'father_religion'         => 'Roman Catholic',
            'father_age'              => 30,
            'parents_marriage_date'   => '2018-06-12',
            'parents_marriage_place'  => 'Aloran, Misamis Occidental',
            'birth_time'              => '3:45 AM',
            'registration_date'       => '2024-03-20',
            'notes'                   => 'Sample remarks for calibration preview.',
        ]),
        'marriage' => array_merge($base, [
            'husband_name'         => 'Juan Dela Cruz',
            'wife_name'            => 'Maria Santos Reyes',
            'husband_birth_date'   => '1995-01-20',
            'wife_birth_date'      => '1997-05-08',
            'husband_age'          => 29,
            'wife_age'             => 27,
            'husband_birth_place'  => 'Aloran, Misamis Occidental',
            'wife_birth_place'     => 'Oroquieta City',
            'husband_citizenship'  => 'Filipino',
            'wife_citizenship'     => 'Filipino',
            'husband_residence'    => 'Poblacion, Aloran',
            'wife_residence'       => 'Lower Langaran, Aloran',
            'husband_religion'     => 'Roman Catholic',
            'wife_religion'        => 'Roman Catholic',
            'event_date'           => '2024-02-14',
            'marriage_place'       => 'Aloran Municipal Hall',
        ]),
        'death' => array_merge($base, [
            'first_name'      => 'Pedro',
            'middle_name'     => 'M.',
            'last_name'       => 'Garcia',
            'sex'             => 'male',
            'event_date'      => '2024-01-10',
            'death_place'     => 'Aloran, Misamis Occidental',
            'death_cause'     => 'Natural causes',
            'age_death_years' => 78,
            'civil_status'    => 'Married',
            'religion'        => 'Roman Catholic',
            'occupation'      => 'Farmer',
            'residence'       => 'Barangay Sample, Aloran',
        ]),
        default => $base,
    };
}

function printFormatFieldDisplayText(string $text): string
{
    if ($text === '') {
        return '';
    }

    return mb_strtoupper($text, 'UTF-8');
}

function printDefaultSampleValue(string $fieldName, string $label): string
{
    static $specific = [
        'registry_number'                       => '2024-001234',
        'sex'                                   => 'Female',
        'birth_type'                            => 'Single',
        'birth_order'                           => '1',
        'multiple_birth_child_was'              => '1',
        'birth_weight'                          => '3.2 kg',
        'attendant_type'                        => '3',
        'birth_time'                            => '3:45 AM',
        'attendant_cert_name'                   => 'Dr. Rosa Mendoza',
        'attendant_cert_title'                  => 'Municipal Health Officer',
        'attendant_cert_address'                => 'Aloran RHU, Poblacion, Aloran',
        'attendant_cert_date'                   => 'March 15, 2024',
        'informant_name'                        => 'Ana Santos Garcia',
        'informant_relationship'                => 'Mother',
        'informant_address'                     => 'Poblacion, Aloran, Misamis Occidental',
        'informant_date'                        => 'March 15, 2024',
        'prepared_by_name'                      => 'Juan Clerk',
        'prepared_by_title'                     => 'Registration Officer',
        'prepared_by_date'                      => 'March 15, 2024',
        'received_by_name'                      => 'Maria Receiver',
        'received_by_title'                     => 'Clerk II',
        'received_by_date'                      => 'March 15, 2024',
        'registrar_name'                        => 'Hon. Sample Registrar',
        'registrar_title'                       => 'Municipal Civil Registrar',
        'registrar_date'                        => 'March 20, 2024',
        'remarks_annotations'                   => 'Sample remarks for calibration preview.',
        'mother_children_born_alive'            => '2',
        'mother_children_still_living'          => '2',
        'mother_children_born_alive_now_dead'   => '0',
        'mother_occupation'                     => 'Teacher',
        'mother_residence'                      => 'Poblacion, Aloran, Misamis Occidental',
        'father_occupation'                     => 'Driver',
        'father_residence'                      => 'Lower Langaran, Aloran',
        'husband_sex'                           => 'Male',
        'wife_sex'                              => 'Female',
        'husband_civil_status'                  => 'Single',
        'wife_civil_status'                     => 'Single',
        'marriage_time'                         => '2:30 PM',
        'solemnizing_officer'                   => 'Rev. Sample Officiant',
        'witnesses'                             => 'Pedro Santos, Rosa Cruz, Miguel Reyes',
        'witness_1'                             => 'Pedro Santos',
        'witness_2'                             => 'Rosa Cruz',
        'witness_3'                             => 'Miguel Reyes',
        'autopsy_performed'                     => 'No',
        'death_time'                            => '6:15 AM',
        'registration_date'                     => 'January 12, 2024',
        'child_delivery_method'                 => 'Normal',
        'child_pregnancy_length'                => '39 weeks',
        'child_birth_type'                      => 'Single',
        'child_birth_order'                     => '1',
        'child_age_mother'                        => 'N/A',
        'paternity_father_name'                 => 'Juan Dela Reyes',
        'paternity_mother_name'                 => 'Ana Santos Garcia',
        'paternity_child_name'                  => 'Maria Santos Reyes',
        'paternity_birth_date'                  => 'March 15, 2024',
        'paternity_birth_place'                 => 'Barangay Sample, Aloran',
        'delayed_birth_affiant_name'            => 'Ana Santos Garcia',
        'delayed_birth_affiant_address'         => 'Poblacion, Aloran',
        'delayed_birth_place'                     => 'Barangay Sample, Aloran',
        'delayed_birth_date'                      => 'March 15, 2024',
        'delayed_birth_child_name'                => 'Maria Santos Reyes',
        'delayed_birth_attendant'                 => 'Midwife Sample',
        'delayed_birth_citizenship'               => 'Filipino',
        'delayed_birth_parents_marriage_date'     => 'June 12, 2018',
        'delayed_birth_parents_marriage_place'    => 'Aloran, Misamis Occidental',
        'delayed_birth_reason'                    => 'Delayed registration due to distance from LCRO.',
        'affidavit_officer_name'                  => 'Rev. Sample Officiant',
        'affidavit_officer_address'               => 'Poblacion, Aloran',
        'affidavit_husband_name'                  => 'Juan Dela Cruz',
        'affidavit_wife_name'                     => 'Maria Santos Reyes',
        'affidavit_marriage_date'                 => 'February 14, 2024',
        'delayed_marriage_affiant_name'           => 'Juan Dela Cruz',
        'delayed_marriage_affiant_address'          => 'Poblacion, Aloran',
        'delayed_marriage_spouse_name'            => 'Maria Santos Reyes',
        'delayed_marriage_place'                  => 'Aloran Municipal Hall',
        'delayed_marriage_date'                   => 'February 14, 2024',
        'delayed_marriage_reason'                 => 'Delayed registration due to travel constraints.',
        'infant_cause_a'                          => 'Sample infant cause A',
        'infant_cause_b'                          => 'Sample infant cause B',
        'postmortem_cause'                        => 'Sample postmortem finding',
        'embalmer_deceased_name'                  => 'Pedro M. Garcia',
        'delayed_death_affiant_name'              => 'Sample Affiant',
        'delayed_death_affiant_address'           => 'Poblacion, Aloran',
        'delayed_death_deceased_name'             => 'Pedro M. Garcia',
        'delayed_death_date'                      => 'January 10, 2024',
        'delayed_death_place'                     => 'Aloran, Misamis Occidental',
        'delayed_death_burial_place'              => 'Aloran Public Cemetery',
        'delayed_death_attendant'                 => 'Dr. Sample Physician',
        'delayed_death_cause'                     => 'Natural causes',
        'delayed_death_reason'                    => 'Delayed registration due to family circumstances.',
    ];

    if (isset($specific[$fieldName])) {
        return $specific[$fieldName];
    }

    if (preg_match('/^lcro_box_(\d+)$/', $fieldName, $match)) {
        return $match[1];
    }

    if (preg_match('/_(day)$/', $fieldName)) {
        return '15';
    }

    if (preg_match('/_(month)$/', $fieldName)) {
        return 'March';
    }

    if (preg_match('/_(year)$/', $fieldName)) {
        return '2024';
    }

    if (preg_match('/_(date)$/', $fieldName) || $fieldName === 'registration_date') {
        return 'March 20, 2024';
    }

    if (preg_match('/(_place|_residence|_address|place_of_|^residence$|burial)/', $fieldName)) {
        return 'Poblacion, Aloran, Misamis Occidental';
    }

    if (preg_match('/(_first_name|_middle_name|_last_name)$/', $fieldName)) {
        return match ($fieldName) {
            'child_first_name', 'deceased_first_name', 'husband_first_name' => 'Maria',
            'child_middle_name', 'deceased_middle_name', 'husband_middle_name' => 'Santos',
            'child_last_name', 'deceased_last_name', 'husband_last_name' => 'Reyes',
            'mother_first_name', 'wife_first_name' => 'Ana',
            'mother_middle_name', 'wife_middle_name' => 'Santos',
            'mother_last_name', 'wife_last_name' => 'Garcia',
            'father_first_name' => 'Juan',
            'father_middle_name' => 'Dela',
            'father_last_name' => 'Reyes',
            default => 'Sample',
        };
    }

    if (preg_match('/(_name|witnesses|witness_\d|solemnizing_officer|attending_physician|embalmer_|affiant_name|officer_name|surviving_spouse_name|delayed_birth_attendant|delayed_death_attendant)$/', $fieldName)
        || preg_match('/_(father_name|mother_maiden_name|consent_person_name)$/', $fieldName)) {
        return 'Sample Name';
    }

    if (preg_match('/(_title|occupation|religion|citizenship|nationality|civil_status|cause|consent_relationship)/', $fieldName)) {
        return match (true) {
            str_contains($fieldName, 'title') => 'Registration Officer',
            str_contains($fieldName, 'occupation') => 'Teacher',
            str_contains($fieldName, 'religion') => 'Roman Catholic',
            str_contains($fieldName, 'citizenship'), str_contains($fieldName, 'nationality') => 'Filipino',
            str_contains($fieldName, 'civil_status') => 'Married',
            str_contains($fieldName, 'cause') => 'Sample cause text',
            str_contains($fieldName, 'relationship') => 'Mother',
            default => 'Sample',
        };
    }

    if (preg_match('/(_age|age_at_death|child_age)/', $fieldName)) {
        return '28';
    }

    $trimmedLabel = preg_replace('/^\d+[a-z]?\s*/', '', $label) ?: $label;

    return $trimmedLabel !== '' ? $trimmedLabel : 'Sample';
}

/** @return array<string, string> */
function printEnsureSampleValues(array $values, string $certificateType): array
{
    foreach (printFieldCatalog()[$certificateType] ?? [] as $side => $fields) {
        foreach ($fields as $fieldName => $label) {
            if (!isset($values[$fieldName]) || trim((string) $values[$fieldName]) === '') {
                $values[$fieldName] = printDefaultSampleValue($fieldName, $label);
            }
        }
    }

    return $values;
}

function printCalibrationSampleValues(string $certificateType): array
{
    $options = [
        'include_paternity_affidavit'     => $certificateType === 'birth',
        'include_delayed_birth_affidavit' => $certificateType === 'birth',
        'include_delayed_marriage_affidavit' => $certificateType === 'marriage',
        'include_delayed_death_affidavit' => $certificateType === 'death',
        'include_infant_section'          => $certificateType === 'death',
        'include_postmortem'              => $certificateType === 'death',
    ];

    $values = printBuildFieldValues(printCalibrationSampleRecord($certificateType), $certificateType, $options);

    return printEnsureSampleValues($values, $certificateType);
}

function printAgeAtDeathText(array $record): string
{
    $units = [
        ['age_death_years', 'y'],
        ['age_death_months', 'm'],
        ['age_death_days', 'd'],
        ['age_death_hours', 'h'],
        ['age_death_minutes', 'min'],
    ];
    $parts = [];
    foreach ($units as [$key, $suffix]) {
        if (!empty($record[$key])) {
            $parts[] = $record[$key] . $suffix;
        }
    }
    $text = implode(' ', $parts);
    if (!empty($record['stillbirth'])) {
        $text = trim($text . ' (Still-birth)');
    }

    return $text;
}

/** Field names derived from civil record columns — not stored as manual print_fill overrides. */
function printRecordSyncedFieldNames(string $certificateType): array
{
    static $cache = [];

    if (!isset($cache[$certificateType])) {
        $cache[$certificateType] = array_keys(
            printBuildFieldValues(
                printCalibrationSampleRecord($certificateType),
                $certificateType,
                ['keep_empty' => true, 'skip_fill_overrides' => true]
            )
        );
    }

    return $cache[$certificateType];
}

/** Manual-only extras saved in print_fill_data (attendant, LCRO, etc.). */
function printManualFillOverrides(array $record, string $certificateType): array
{
    $stored = printParseFillData($record['print_fill_data'] ?? null);
    if ($stored === []) {
        return [];
    }

    $synced = array_flip(printRecordSyncedFieldNames($certificateType));

    return array_filter(
        $stored,
        static fn ($value, $key) => !isset($synced[$key]) && trim((string) $value) !== '',
        ARRAY_FILTER_USE_BOTH
    );
}

/** Rebuild print_fill_data from record columns plus manual-only form extras. */
function printRebuildRecordFillData(array $record, string $certificateType, array $submittedFill = []): ?string
{
    $computed = printBuildFieldValues(
        $record,
        $certificateType,
        ['keep_empty' => true, 'skip_fill_overrides' => true]
    );
    $synced = array_flip(printRecordSyncedFieldNames($certificateType));
    $manual = [];

    foreach ($submittedFill as $key => $value) {
        if (!is_string($key) || isset($synced[$key])) {
            continue;
        }
        $trimmed = trim((string) $value);
        if ($trimmed !== '') {
            $manual[$key] = $trimmed;
        }
    }

    $merged = array_merge($computed, $manual);
    $merged = array_filter(
        $merged,
        static fn ($value) => trim((string) $value) !== ''
    );

    if ($merged === []) {
        return null;
    }

    $json = json_encode($merged, JSON_UNESCAPED_UNICODE);

    return $json !== false ? $json : null;
}

/** Faster CSV import when rows already include print-form columns (export / full template). */
function printFillDataForCsvImport(array $record, string $certificateType, array $submittedFill): ?string
{
    if ($submittedFill === []) {
        return printRebuildRecordFillData($record, $certificateType, []);
    }

    $expectedMin = max(8, (int) (count(printFillFieldNames($certificateType)) * 0.2));
    if (count($submittedFill) >= $expectedMin) {
        $merged = array_filter(
            $submittedFill,
            static fn ($value) => trim((string) $value) !== ''
        );
        if ($merged !== []) {
            $json = json_encode($merged, JSON_UNESCAPED_UNICODE);

            return $json !== false ? $json : null;
        }
    }

    return printRebuildRecordFillData($record, $certificateType, $submittedFill);
}

function printBuildFieldValues(array $record, string $certificateType, array $options = []): array
{
    $values = printOfficeLocationFields();
    $values['registry_number'] = trim((string) ($record['registry_number'] ?? ''));
    $values['book_number'] = trim((string) ($record['book_number'] ?? ''));
    $values['page_number'] = trim((string) ($record['page_number'] ?? ''));

    if ($certificateType === 'birth') {
        $values['child_first_name'] = trim((string) ($record['first_name'] ?? ''));
        $values['child_middle_name'] = trim((string) ($record['middle_name'] ?? ''));
        $values['child_last_name'] = trim((string) ($record['last_name'] ?? ''));
        $values['sex'] = ucfirst(strtolower((string) ($record['sex'] ?? '')));
        $birth = printDateParts($record['birth_date'] ?? $record['event_date'] ?? null);
        $values['birth_day'] = $birth['day'];
        $values['birth_month'] = $birth['month'];
        $values['birth_year'] = $birth['year'];
        $values['birth_place'] = trim((string) ($record['place'] ?? ''));
        $values['birth_type'] = trim((string) ($record['birth_type'] ?? ''));
        $values['multiple_birth_child_was'] = trim((string) ($record['birth_order'] ?? ''));
        $values['birth_order'] = trim((string) ($record['birth_order'] ?? ''));
        $values['birth_weight'] = trim((string) ($record['birth_weight'] ?? ''));

        $mother = splitFullName($record['mother_name'] ?? '');
        $values['mother_first_name'] = $mother['first'];
        $values['mother_middle_name'] = $mother['middle'];
        $values['mother_last_name'] = $mother['last'];
        $values['mother_citizenship'] = trim((string) ($record['mother_nationality'] ?? ''));
        $values['mother_religion'] = trim((string) ($record['mother_religion'] ?? ''));
        $values['mother_age'] = isset($record['mother_age']) ? (string) $record['mother_age'] : '';
        $values['mother_occupation'] = trim((string) ($record['mother_occupation'] ?? ''));
        $values['mother_residence'] = trim((string) ($record['mother_residence'] ?? ''));
        $values['mother_children_born_alive'] = trim((string) ($record['mother_children_born_alive'] ?? ''));
        $values['mother_children_still_living'] = trim((string) ($record['mother_children_still_living'] ?? ''));
        $values['mother_children_born_alive_now_dead'] = trim((string) ($record['mother_children_born_alive_now_dead'] ?? ''));

        $father = splitFullName($record['father_name'] ?? '');
        $values['father_first_name'] = $father['first'];
        $values['father_middle_name'] = $father['middle'];
        $values['father_last_name'] = $father['last'];
        $values['father_citizenship'] = trim((string) ($record['father_nationality'] ?? ''));
        $values['father_religion'] = trim((string) ($record['father_religion'] ?? ''));
        $values['father_age'] = isset($record['father_age']) ? (string) $record['father_age'] : '';
        $values['father_occupation'] = trim((string) ($record['father_occupation'] ?? ''));
        $values['father_residence'] = trim((string) ($record['father_residence'] ?? ''));

        $pm = printDateParts($record['parents_marriage_date'] ?? null);
        $values['parents_marriage_day'] = $pm['day'];
        $values['parents_marriage_month'] = $pm['month'];
        $values['parents_marriage_year'] = $pm['year'];
        $values['parents_marriage_place'] = trim((string) ($record['parents_marriage_place'] ?? ''));

        $values['birth_time'] = trim((string) ($record['birth_time'] ?? ''));
        $values['registrar_date'] = printFormatDateField($record['registration_date'] ?? null);
        $values['remarks_annotations'] = trim((string) ($record['notes'] ?? ''));

        $childName = trim(($values['child_first_name'] . ' ' . $values['child_middle_name'] . ' ' . $values['child_last_name']));
        $childName = preg_replace('/\s+/', ' ', $childName);
        $values['paternity_father_name'] = trim((string) ($record['father_name'] ?? ''));
        $values['paternity_mother_name'] = trim((string) ($record['mother_name'] ?? ''));
        $values['paternity_child_name'] = $childName;
        $values['paternity_birth_date'] = trim(($values['birth_month'] . ' ' . $values['birth_day'] . ', ' . $values['birth_year']));
        $values['paternity_birth_place'] = $values['birth_place'];
        $values['delayed_birth_child_name'] = $childName;
        $values['delayed_birth_place'] = $values['birth_place'];
        $values['delayed_birth_date'] = trim(($values['birth_month'] . ' ' . $values['birth_day'] . ', ' . $values['birth_year']));
        $values['delayed_birth_parents_marriage_date'] = trim(($values['parents_marriage_month'] . ' ' . $values['parents_marriage_day'] . ', ' . $values['parents_marriage_year']));
        $values['delayed_birth_parents_marriage_place'] = $values['parents_marriage_place'];
    } elseif ($certificateType === 'marriage') {
        $husband = splitFullName($record['husband_name'] ?? '');
        $wife = splitFullName($record['wife_name'] ?? '');
        $values['husband_first_name'] = $husband['first'];
        $values['husband_middle_name'] = $husband['middle'];
        $values['husband_last_name'] = $husband['last'];
        $values['wife_first_name'] = $wife['first'];
        $values['wife_middle_name'] = $wife['middle'];
        $values['wife_last_name'] = $wife['last'];

        $hb = printDateParts($record['husband_birth_date'] ?? null);
        $values['husband_birth_day'] = $hb['day'];
        $values['husband_birth_month'] = $hb['month'];
        $values['husband_birth_year'] = $hb['year'];
        $values['husband_age'] = isset($record['husband_age']) ? (string) $record['husband_age'] : '';
        $values['husband_birth_place'] = trim((string) ($record['husband_birth_place'] ?? ''));
        $values['husband_sex'] = 'Male';
        $values['husband_citizenship'] = trim((string) ($record['husband_citizenship'] ?? ''));
        $values['husband_residence'] = trim((string) ($record['husband_residence'] ?? ''));
        $values['husband_religion'] = trim((string) ($record['husband_religion'] ?? ''));
        $values['husband_civil_status'] = trim((string) ($record['husband_civil_status'] ?? ''));
        $values['husband_father_name'] = trim((string) ($record['husband_father_name'] ?? ''));
        $values['husband_father_citizenship'] = trim((string) ($record['husband_father_citizenship'] ?? ''));
        $values['husband_mother_maiden_name'] = trim((string) ($record['husband_mother_maiden_name'] ?? ''));
        $values['husband_mother_citizenship'] = trim((string) ($record['husband_mother_citizenship'] ?? ''));
        $values['husband_consent_person_name'] = trim((string) ($record['husband_consent_person_name'] ?? ''));
        $values['husband_consent_relationship'] = trim((string) ($record['husband_consent_relationship'] ?? ''));
        $values['husband_consent_residence'] = trim((string) ($record['husband_consent_residence'] ?? ''));

        $wb = printDateParts($record['wife_birth_date'] ?? null);
        $values['wife_birth_day'] = $wb['day'];
        $values['wife_birth_month'] = $wb['month'];
        $values['wife_birth_year'] = $wb['year'];
        $values['wife_age'] = isset($record['wife_age']) ? (string) $record['wife_age'] : '';
        $values['wife_birth_place'] = trim((string) ($record['wife_birth_place'] ?? ''));
        $values['wife_sex'] = 'Female';
        $values['wife_citizenship'] = trim((string) ($record['wife_citizenship'] ?? ''));
        $values['wife_residence'] = trim((string) ($record['wife_residence'] ?? ''));
        $values['wife_religion'] = trim((string) ($record['wife_religion'] ?? ''));
        $values['wife_civil_status'] = trim((string) ($record['wife_civil_status'] ?? ''));
        $values['wife_father_name'] = trim((string) ($record['wife_father_name'] ?? ''));
        $values['wife_father_citizenship'] = trim((string) ($record['wife_father_citizenship'] ?? ''));
        $values['wife_mother_maiden_name'] = trim((string) ($record['wife_mother_maiden_name'] ?? ''));
        $values['wife_mother_citizenship'] = trim((string) ($record['wife_mother_citizenship'] ?? ''));
        $values['wife_consent_person_name'] = trim((string) ($record['wife_consent_person_name'] ?? ''));
        $values['wife_consent_relationship'] = trim((string) ($record['wife_consent_relationship'] ?? ''));
        $values['wife_consent_residence'] = trim((string) ($record['wife_consent_residence'] ?? ''));

        $md = printDateParts($record['event_date'] ?? null);
        $values['marriage_day'] = $md['day'];
        $values['marriage_month'] = $md['month'];
        $values['marriage_year'] = $md['year'];
        $values['marriage_place'] = trim((string) ($record['place'] ?? ''));
        $values['marriage_time'] = trim((string) ($record['marriage_time'] ?? ''));
        $values['solemnizing_officer'] = trim((string) ($record['solemnized_by'] ?? ''));
        $values['witnesses'] = trim((string) ($record['witnesses'] ?? ''));
        $values['remarks_annotations'] = trim((string) ($record['notes'] ?? ''));

        $values['delayed_marriage_place'] = $values['marriage_place'];
        $values['delayed_marriage_date'] = trim(($values['marriage_month'] . ' ' . $values['marriage_day'] . ', ' . $values['marriage_year']));

        $witnessLines = preg_split('/\r\n|\r|\n|,/', (string) ($record['witnesses'] ?? '')) ?: [];
        $witnessLines = array_values(array_filter(array_map('trim', $witnessLines)));
        $values['witness_1'] = $witnessLines[0] ?? '';
        $values['witness_2'] = $witnessLines[1] ?? '';
        $values['witness_3'] = $witnessLines[2] ?? '';
        $values['affidavit_husband_name'] = trim((string) ($record['husband_name'] ?? ''));
        $values['affidavit_wife_name'] = trim((string) ($record['wife_name'] ?? ''));
        $values['affidavit_marriage_date'] = trim(($values['marriage_month'] . ' ' . $values['marriage_day'] . ', ' . $values['marriage_year']));
        $values['affidavit_officer_name'] = $values['solemnizing_officer'];
    } elseif ($certificateType === 'death') {
        $values['deceased_first_name'] = trim((string) ($record['first_name'] ?? ''));
        $values['deceased_middle_name'] = trim((string) ($record['middle_name'] ?? ''));
        $values['deceased_last_name'] = trim((string) ($record['last_name'] ?? ''));
        $values['sex'] = ucfirst(strtolower((string) ($record['sex'] ?? '')));

        $death = printDateParts($record['event_date'] ?? null);
        $values['death_day'] = $death['day'];
        $values['death_month'] = $death['month'];
        $values['death_year'] = $death['year'];

        $birth = printDateParts($record['birth_date'] ?? null);
        $values['birth_day'] = $birth['day'];
        $values['birth_month'] = $birth['month'];
        $values['birth_year'] = $birth['year'];

        $values['age_at_death'] = printAgeAtDeathText($record);
        $values['place_of_death'] = trim((string) ($record['place'] ?? ''));
        $values['civil_status'] = trim((string) ($record['civil_status'] ?? ''));
        $values['religion'] = trim((string) ($record['religion'] ?? ''));
        $values['citizenship'] = trim((string) ($record['nationality'] ?? ''));
        $values['residence'] = trim((string) ($record['residence_deceased'] ?? ''));
        $values['occupation'] = trim((string) ($record['occupation'] ?? ''));
        $values['immediate_cause'] = trim((string) ($record['immediate_cause'] ?? ''));
        $values['contributory_cause'] = trim((string) ($record['contributory_cause'] ?? ''));
        $values['autopsy_performed'] = trim((string) ($record['autopsy_performed'] ?? ''));
        $values['attending_physician'] = trim((string) ($record['attending_physician'] ?? ''));
        $values['surviving_spouse_name'] = trim((string) ($record['surviving_spouse_name'] ?? ''));
        $values['surviving_spouse_address'] = trim((string) ($record['surviving_spouse_address'] ?? ''));
        $values['place_of_burial'] = trim((string) ($record['place_of_burial'] ?? ''));
        $deathTime = trim((string) ($record['death_time'] ?? ''));
        $period = trim((string) ($record['death_time_period'] ?? ''));
        $values['death_time'] = trim($deathTime . ($period !== '' ? ' ' . $period : ''));
        $values['registration_date'] = printFormatDateField($record['registration_date'] ?? null);
        $values['registrar_date'] = $values['registration_date'];
        $values['remarks_annotations'] = trim((string) ($record['notes'] ?? ''));

        $father = splitFullName($record['father_name'] ?? '');
        $values['father_first_name'] = $father['first'];
        $values['father_middle_name'] = $father['middle'];
        $values['father_last_name'] = $father['last'];

        $mother = splitFullName($record['mother_name'] ?? '');
        $values['mother_first_name'] = $mother['first'];
        $values['mother_middle_name'] = $mother['middle'];
        $values['mother_last_name'] = $mother['last'];

        $values['child_age_mother'] = trim((string) ($record['child_age_mother'] ?? ''));
        $values['child_delivery_method'] = trim((string) ($record['child_delivery_method'] ?? ''));
        $values['child_pregnancy_length'] = trim((string) ($record['child_pregnancy_length'] ?? ''));
        $values['child_birth_type'] = trim((string) ($record['child_birth_type'] ?? ''));
        $values['child_birth_order'] = trim((string) ($record['child_birth_order_infant'] ?? ''));
        $values['infant_cause_a'] = trim((string) ($record['infant_cause_a'] ?? ''));
        $values['infant_cause_b'] = trim((string) ($record['infant_cause_b'] ?? ''));
        $values['infant_cause_c'] = trim((string) ($record['infant_cause_c'] ?? ''));
        $values['infant_cause_d'] = trim((string) ($record['infant_cause_d'] ?? ''));
        $values['infant_cause_e'] = trim((string) ($record['infant_cause_e'] ?? ''));
        $values['postmortem_cause'] = trim((string) ($record['postmortem_cause'] ?? ''));

        if ($values['infant_cause_a'] === '' && $values['immediate_cause'] !== '') {
            $values['infant_cause_a'] = $values['immediate_cause'];
        }
        if ($values['infant_cause_b'] === '' && $values['contributory_cause'] !== '') {
            $values['infant_cause_b'] = $values['contributory_cause'];
        }
        if ($values['postmortem_cause'] === '' && strtolower($values['autopsy_performed']) === 'yes' && $values['immediate_cause'] !== '') {
            $values['postmortem_cause'] = $values['immediate_cause'];
        }

        $deceased = trim(($values['deceased_first_name'] . ' ' . $values['deceased_middle_name'] . ' ' . $values['deceased_last_name']));
        $deceased = preg_replace('/\s+/', ' ', $deceased);
        $values['embalmer_deceased_name'] = $deceased;
        $values['delayed_death_deceased_name'] = $deceased;
        $values['delayed_death_date'] = trim(($values['death_month'] . ' ' . $values['death_day'] . ', ' . $values['death_year']));
        $values['delayed_death_place'] = $values['place_of_death'];
        $values['delayed_death_burial_place'] = $values['place_of_burial'];
        $values['delayed_death_cause'] = $values['immediate_cause'];
        $values['delayed_death_attendant'] = $values['attending_physician'];
    }

    if (empty($options['skip_fill_overrides'])) {
        $values = printApplyFillOverrides($values, printManualFillOverrides($record, $certificateType));
    }

    if (empty($options['keep_empty'])) {
        $values = array_filter(
            $values,
            static fn ($v) => $v !== null && trim((string) $v) !== ''
        );
    }

    return $values;
}

function printDecodeFillOverrides(?string $encoded): array
{
    if ($encoded === null || $encoded === '') {
        return [];
    }

    $encoded = strtr($encoded, '-_', '+/');
    $pad = strlen($encoded) % 4;
    if ($pad) {
        $encoded .= str_repeat('=', 4 - $pad);
    }

    $json = base64_decode($encoded, true);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function savePrintFillData(PDO $pdo, array $context, mixed $fillData): void
{
    $record = $context['record'] ?? [];
    $certificateType = (string) ($context['certificate_type'] ?? ($record['record_type'] ?? ''));
    if (!in_array($certificateType, printCertificateTypes(), true)) {
        return;
    }

    $submitted = is_array($fillData)
        ? array_map(static fn ($v) => trim((string) $v), $fillData)
        : printParseFillData($fillData);
    $json = printRebuildRecordFillData($record, $certificateType, $submitted);
    if ($json === null) {
        return;
    }

    $recordId = (int) ($record['id'] ?? 0);
    if ($recordId > 0) {
        $pdo->prepare('UPDATE civil_records SET print_fill_data = ? WHERE id = ?')
            ->execute([$json, $recordId]);
    }

    $requestId = (int) ($context['request']['id'] ?? 0);
    if ($requestId > 0) {
        $pdo->prepare('UPDATE document_requests SET print_fill_data = ? WHERE id = ?')
            ->execute([$json, $requestId]);
    }
}

function printApplyFillOverrides(array $values, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            continue;
        }
        $values[$key] = $trimmed;
    }

    return $values;
}

function printFillFieldGroup(string $fieldName): string
{
    if (str_starts_with($fieldName, 'paternity_')) {
        return 'paternity';
    }
    if (str_starts_with($fieldName, 'delayed_birth_')) {
        return 'delayed_birth';
    }
    if (str_starts_with($fieldName, 'delayed_marriage_') || str_starts_with($fieldName, 'affidavit_')) {
        return 'delayed_marriage';
    }
    if (str_starts_with($fieldName, 'delayed_death_')) {
        return 'delayed_death';
    }
    if (str_starts_with($fieldName, 'infant_')
        || str_starts_with($fieldName, 'child_age_')
        || str_starts_with($fieldName, 'child_delivery_')
        || str_starts_with($fieldName, 'child_pregnancy_')
        || str_starts_with($fieldName, 'child_birth_')) {
        return 'infant_section';
    }
    if (str_starts_with($fieldName, 'postmortem_') || $fieldName === 'embalmer_deceased_name') {
        return 'postmortem';
    }

    return '';
}

/** @return list<array{field_name: string, label: string, page_side: string, value: string}> */
function printFillEditorFields(string $certificateType, array $record, array $options = [], ?PDO $pdo = null): array
{
    $values = printBuildFieldValues($record, $certificateType, array_merge([
        'keep_empty' => true,
    ], $options));
    $catalog = printFieldCatalog()[$certificateType] ?? [];
    $fields = [];
    $seen = [];

    foreach (['front', 'back'] as $side) {
        foreach ($catalog[$side] ?? [] as $name => $label) {
            $fields[] = [
                'field_name' => $name,
                'label'      => $label,
                'page_side'  => $side,
                'value'      => $values[$name] ?? '',
            ];
            $seen[$name] = true;
        }
    }

    if ($pdo !== null) {
        foreach (['front', 'back'] as $side) {
            $template = getPrintTemplate($pdo, $certificateType, $side);
            if (!$template) {
                continue;
            }
            foreach (getPrintFields($pdo, (int) $template['id'], true) as $dbField) {
                $name = (string) $dbField['field_name'];
                if (!printIsCustomField($name) || isset($seen[$name])) {
                    continue;
                }
                $fields[] = [
                    'field_name' => $name,
                    'label'      => (string) ($dbField['label'] ?: $name),
                    'page_side'  => $side,
                    'value'      => $values[$name] ?? '',
                ];
                $seen[$name] = true;
            }
        }
    }

    return $fields;
}

function getPrintTemplate(PDO $pdo, string $certificateType, string $pageSide, string $documentKind = 'certificate'): ?array
{
    ensurePrintDocumentKindColumn($pdo);
    $documentKind = normalizePrintDocumentKind($documentKind);

    $stmt = $pdo->prepare(
        'SELECT * FROM print_templates
         WHERE certificate_type = ? AND page_side = ? AND document_kind = ? LIMIT 1'
    );
    $stmt->execute([$certificateType, $pageSide, $documentKind]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    if ($documentKind !== 'certificate') {
        return null;
    }

    $fallback = $pdo->prepare(
        'SELECT * FROM print_templates WHERE certificate_type = ? AND page_side = ? LIMIT 1'
    );
    $fallback->execute([$certificateType, $pageSide]);

    return $fallback->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getPrintFields(PDO $pdo, int $templateId, bool $enabledOnly = true): array
{
    $sql = 'SELECT * FROM print_fields WHERE template_id = ?';
    if ($enabledOnly) {
        $sql .= ' AND enabled = 1';
    }
    $sql .= ' ORDER BY y_mm ASC, x_mm ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$templateId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getPrintCalibration(PDO $pdo, int $templateId): array
{
    $stmt = $pdo->prepare('SELECT * FROM print_calibrations WHERE template_id = ? LIMIT 1');
    $stmt->execute([$templateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [
        'x_offset_mm' => 0,
        'y_offset_mm' => 0,
        'scale_x'     => 1,
        'scale_y'     => 1,
    ];
}

function printParseCalibrationLivePayload(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return ['fields' => [], 'template' => [], 'apply_effective' => false];
    }

    $trimmed = trim($raw);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $decoded = json_decode($trimmed, true);
    } else {
        $json = base64_decode(strtr($trimmed, '-_', '+/'), true);
        $decoded = is_string($json) ? json_decode($json, true) : null;
    }

    if (!is_array($decoded)) {
        return ['fields' => [], 'template' => [], 'apply_effective' => false];
    }

    return [
        'fields'           => is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [],
        'template'         => is_array($decoded['template'] ?? null) ? $decoded['template'] : [],
        'apply_effective'  => !empty($decoded['apply_effective']),
    ];
}

function printZeroCalibrationOffsets(): array
{
    return [
        'x_offset_mm' => 0.0,
        'y_offset_mm' => 0.0,
        'scale_x'     => 1.0,
        'scale_y'     => 1.0,
    ];
}

function printApplyCalibrationLiveOverrides(array $printData, array $live): array
{
    $fieldOverrides = [];
    foreach ($live['fields'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = (int) ($entry['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $fieldOverrides[$id] = $entry;
    }

    $applyEffective = !empty($live['apply_effective']);

    if ($fieldOverrides !== []) {
        foreach ($printData['fields'] as &$field) {
            $id = (int) ($field['id'] ?? 0);
            if (!isset($fieldOverrides[$id])) {
                continue;
            }
            $override = $fieldOverrides[$id];
            foreach (['x_mm', 'y_mm', 'width_mm', 'height_mm', 'font_size', 'alignment'] as $key) {
                if (array_key_exists($key, $override)) {
                    $field[$key] = $override[$key];
                }
            }
        }
        unset($field);
    }

    if ($applyEffective) {
        $printData['template_calibration'] = printZeroCalibrationOffsets();
        $printData['global_calibration'] = printZeroCalibrationOffsets();
        $printData['use_effective_positions'] = true;
    } else {
        $template = $live['template'] ?? [];
        if ($template !== []) {
            $cal = $printData['template_calibration'] ?? printZeroCalibrationOffsets();
            foreach (['x_offset_mm', 'y_offset_mm', 'scale_x', 'scale_y'] as $key) {
                if (array_key_exists($key, $template)) {
                    $cal[$key] = $template[$key];
                }
            }
            $printData['template_calibration'] = $cal;
        }
    }

    return $printData;
}

function printEffectivePosition(array $field, array $templateCalibration, array $globalCalibration): array
{
    $x = ((float) $field['x_mm'] + (float) $templateCalibration['x_offset_mm'] + (float) $globalCalibration['x_offset_mm'])
        * (float) $globalCalibration['scale_x'] * (float) $templateCalibration['scale_x'];
    $y = ((float) $field['y_mm'] + (float) $templateCalibration['y_offset_mm'] + (float) $globalCalibration['y_offset_mm'])
        * (float) $globalCalibration['scale_y'] * (float) $templateCalibration['scale_y'];
    $width = (float) $field['width_mm'] * (float) $globalCalibration['scale_x'] * (float) $templateCalibration['scale_x'];
    $height = (float) $field['height_mm'] * (float) $globalCalibration['scale_y'] * (float) $templateCalibration['scale_y'];

    return [
        'x'      => round($x, 2),
        'y'      => round($y, 2),
        'width'  => round(max(1, $width), 2),
        'height' => round(max(1, $height), 2),
    ];
}

function printCertificate(
    PDO $pdo,
    string $certificateType,
    string $pageSide,
    array $record,
    array $options = []
): ?array
{
    if (!in_array($certificateType, printCertificateTypes(), true)) {
        return null;
    }
    if (!in_array($pageSide, ['front', 'back'], true)) {
        return null;
    }

    $documentKind = normalizePrintDocumentKind($options['document_kind'] ?? 'certificate');
    if ($documentKind === 'certification') {
        require_once __DIR__ . '/certification_print.php';

        return printCertification($pdo, $certificateType, $record, $options);
    }

    $template = getPrintTemplate($pdo, $certificateType, $pageSide, 'certificate');
    if (!$template) {
        return null;
    }

    $fields = getPrintFields($pdo, (int) $template['id'], empty($options['include_disabled_fields']));
    $values = printBuildFieldValues($record, $certificateType, $options);
    $templateCalibration = getPrintCalibration($pdo, (int) $template['id']);
    $globalCalibration = printGlobalCalibration();

    return [
        'template'            => $template,
        'fields'              => $fields,
        'values'              => $values,
        'template_calibration'=> $templateCalibration,
        'global_calibration'  => $globalCalibration,
        'mode'                => $options['mode'] ?? printMode(),
        'test_mode'           => !empty($options['test_mode']),
    ];
}

function renderPrintOverlayHtml(array $printData, array $options = []): string
{
    $template = $printData['template'];
    $fields = $printData['fields'];
    $values = $printData['values'];
    $templateCalibration = $printData['template_calibration'];
    $globalCalibration = $printData['global_calibration'];
    $mode = $options['mode'] ?? ($printData['mode'] ?? printMode());
    $testMode = !empty($options['test_mode']) || !empty($printData['test_mode']);
    if (array_key_exists('show_background', $options)) {
        $showBackground = !empty($options['show_background']);
    } else {
        $showBackground = $mode === 'digital';
    }
    $useEffectivePositions = !empty($options['use_effective_positions']) || !empty($printData['use_effective_positions']);
    $documentKind = normalizePrintDocumentKind($template['document_kind'] ?? 'certificate');
    $isCertification = $documentKind === 'certification';
    $calibrationPreview = !empty($options['calibration_preview']);

    $paperW = (float) $template['paper_width_mm'];
    $paperH = (float) $template['paper_height_mm'];
    $marginTop = (float) $template['margin_top_mm'];
    $marginLeft = (float) $template['margin_left_mm'];

    $html = '<div class="print-sheet" style="width:' . $paperW . 'mm;height:' . $paperH . 'mm;position:relative;margin:0 auto;background:#fff;">';

    if ($showBackground) {
        $referencePath = (string) ($template['reference_image'] ?? '');
        $certType = (string) ($template['certificate_type'] ?? '');
        $pageSide = (string) ($template['page_side'] ?? 'front');
        $documentKind = normalizePrintDocumentKind($template['document_kind'] ?? 'certificate');
        $preferScan = !empty($options['prefer_scan_background']);
        $scan = $documentKind === 'certification' && function_exists('certificationFormScanAsset')
            ? certificationFormScanAsset($certType)
            : printFormScanAsset($certType, $pageSide);
        if ($scan === null && $documentKind === 'certification') {
            require_once __DIR__ . '/certification_print.php';
            $scan = certificationFormScanAsset($certType);
        }

        if ($referencePath !== '') {
            $referenceFull = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $referencePath);
            if ($preferScan && $scan) {
                $escaped = htmlspecialchars($scan, ENT_QUOTES, 'UTF-8');
                $html .= '<img src="' . $escaped . '" alt="" class="print-sheet__background" style="position:absolute;inset:0;width:100%;height:100%;object-fit:fill;">';
            } elseif (is_file($referenceFull) && str_ends_with(strtolower($referencePath), '.svg')) {
                $svg = file_get_contents($referenceFull);
                $html .= '<div class="print-sheet__background print-sheet__background--svg" style="position:absolute;inset:0;pointer-events:none;overflow:hidden;">'
                    . $svg . '</div>';
            } elseif ($scan) {
                $escaped = htmlspecialchars($scan, ENT_QUOTES, 'UTF-8');
                $html .= '<img src="' . $escaped . '" alt="" class="print-sheet__background" style="position:absolute;inset:0;width:100%;height:100%;object-fit:fill;">';
            } elseif (is_file($referenceFull)) {
                $reference = htmlspecialchars($referencePath, ENT_QUOTES, 'UTF-8');
                $html .= '<img src="' . $reference . '" alt="" class="print-sheet__background" style="position:absolute;inset:0;width:100%;height:100%;object-fit:fill;opacity:' . ($mode === 'digital' ? '1' : '0.4') . ';">';
            }
        }
    }

    if ($testMode) {
        $html .= '<div class="print-test-marker" style="position:absolute;left:5mm;top:5mm;font:7pt monospace;color:#999;">TEST PRINT · ALCROS</div>';
        $html .= '<div class="print-test-marker" style="position:absolute;right:5mm;bottom:5mm;font:7pt monospace;color:#999;">X/Y CALIBRATION</div>';
    }

    foreach ($fields as $field) {
        $name = (string) $field['field_name'];
        $text = $testMode
            ? strtoupper(str_replace('_', ' ', $name))
            : (string) ($values[$name] ?? '');

        $editable = !empty($options['editable']) && !$testMode;

        if (!$testMode && trim($text) === '' && !$editable) {
            continue;
        }

        if (!$testMode && !empty($field['max_length'])) {
            $text = mb_substr($text, 0, (int) $field['max_length']);
        }

        if (!$testMode && trim($text) !== '' && !$isCertification) {
            $text = printFormatFieldDisplayText($text);
        }

        if ($useEffectivePositions) {
            $pos = [
                'x'      => round((float) $field['x_mm'], 2),
                'y'      => round((float) $field['y_mm'], 2),
                'width'  => round(max(1, (float) $field['width_mm']), 2),
                'height' => round(max(1, (float) $field['height_mm']), 2),
            ];
        } else {
            $pos = printEffectivePosition($field, $templateCalibration, $globalCalibration);
        }
        $x = $marginLeft + $pos['x'];
        $y = $marginTop + $pos['y'];
        $textAlign = printNormalizeAlignment($field['alignment'] ?? null);
        $justify = printAlignmentJustifyContent($textAlign);
        $weight = '700';
        $fontSize = max(1, (float) $field['font_size']);
        $color = $testMode ? '#666' : '#000';
        $editableClass = $editable ? ' print-field--editable' : '';
        $editableAttr = $editable ? ' contenteditable="true" spellcheck="false" tabindex="0"' : '';
        $multiline = $editable || ((float) $field['height_mm'] >= 12.0 && $name === 'remarks');
        $textTransform = ($isCertification || $multiline) ? 'none' : 'uppercase';
        $alignItems = $multiline ? 'flex-start' : 'center';
        $fieldPadding = $isCertification ? 'padding:0 0.2rem;' : '';

        $html .= '<div class="print-field' . $editableClass . '"'
            . ' data-field="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-field-id="' . (int) ($field['id'] ?? 0) . '" style="'
            . 'position:absolute;left:' . $x . 'mm;top:' . $y . 'mm;width:' . $pos['width'] . 'mm;height:' . $pos['height'] . 'mm;'
            . 'display:flex;align-items:' . $alignItems . ';justify-content:' . $justify . ';'
            . 'font-family:' . htmlspecialchars((string) $field['font_family'], ENT_QUOTES, 'UTF-8') . ',sans-serif;'
            . 'font-size:' . $fontSize . 'pt;font-weight:' . $weight . ';text-transform:' . $textTransform . ';'
            . 'line-height:' . ($isCertification ? '1.2' : (float) $field['line_height']) . ';text-align:' . $textAlign . ';color:' . $color . ';'
            . $fieldPadding
            . 'overflow:hidden;' . ($multiline ? 'white-space:pre-wrap;word-break:break-word;' : 'white-space:nowrap;') . '"'
            . $editableAttr . '>'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }

    $html .= '</div>';

    return $html;
}

function logPrintJob(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO print_jobs
         (request_id, civil_record_id, template_id, page_side, certificate_type, registry_number,
          printer_name, printed_by, print_mode, copies, status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['request_id'] ?? null,
        $data['civil_record_id'] ?? null,
        $data['template_id'],
        $data['page_side'],
        $data['certificate_type'],
        $data['registry_number'] ?? null,
        $data['printer_name'] ?? null,
        $data['printed_by'] ?? staffId(),
        $data['print_mode'] ?? 'production',
        (int) ($data['copies'] ?? 1),
        $data['status'] ?? 'completed',
        $data['notes'] ?? null,
    ]);

    $jobId = (int) $pdo->lastInsertId();
    $details = sprintf(
        'Certificate %s %s · Registry %s · Job #%d · Mode %s',
        strtoupper((string) $data['certificate_type']),
        strtoupper((string) $data['page_side']),
        (string) ($data['registry_number'] ?? '—'),
        $jobId,
        (string) ($data['print_mode'] ?? 'production')
    );
    if (!empty($data['request_id'])) {
        $details .= ' · Request #' . (int) $data['request_id'];
    }
    logActivity(staffId(), 'Certificate Printed', $details);

    return $jobId;
}

function canPrintRequestStatus(string $status): bool
{
    return normalizeRequestStatus($status) === 'ready';
}

function printRequestContext(PDO $pdo, ?int $requestId, ?int $recordId): array
{
    $request = null;
    $record = null;
    $error = null;

    if ($requestId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM document_requests WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$request) {
            return ['ok' => false, 'error' => 'Request not found.'];
        }
        if (!canPrintRequestStatus((string) ($request['status'] ?? ''))) {
            return ['ok' => false, 'error' => 'This request is not eligible for certificate printing.'];
        }
        if (($request['document_type'] ?? '') === 'cenomar') {
            return ['ok' => false, 'error' => 'CENOMAR printing is not configured in this module.'];
        }
        $record = resolveCivilRecordForRequest($pdo, $request);
        if ($record) {
            linkRequestToCivilRecord($pdo, (int) $request['id'], (int) $record['id']);
        }
    } elseif ($recordId > 0) {
        $record = fetchFullCivilRecord($pdo, $recordId);
    }

    if (!$record) {
        if ($requestId <= 0 && $recordId <= 0) {
            return ['ok' => false, 'error' => 'Open Print Certificate from Manage Requests or Civil Records.'];
        }
        return ['ok' => false, 'error' => 'No civil registry record could be matched for printing.'];
    }

    if ($request && !empty($request['print_fill_data'])) {
        $merged = array_merge(
            printParseFillData($record['print_fill_data'] ?? null),
            printParseFillData($request['print_fill_data'])
        );
        if ($merged !== []) {
            $record['print_fill_data'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
        }
    }

    $recordSource = (($record['_print_source'] ?? '') === 'request') ? 'request' : 'civil_record';

    $certificateType = (string) ($record['record_type'] ?? ($request['document_type'] ?? ''));
    if (!in_array($certificateType, printCertificateTypes(), true)) {
        return ['ok' => false, 'error' => 'Unsupported certificate type.'];
    }

    return [
        'ok'               => true,
        'request'          => $request,
        'record'           => $record,
        'certificate_type' => $certificateType,
        'record_source'    => $recordSource,
        'error'            => $error,
    ];
}

function printCertificateTitle(string $certificateType): string
{
    return printCertificateMeta()[$certificateType]['title'] ?? ucfirst($certificateType) . ' Certificate';
}

function printCertificateFormNumber(string $certificateType): string
{
    return printCertificateMeta()[$certificateType]['form_number'] ?? '';
}

function printIsCustomField(string $fieldName): bool
{
    return str_starts_with($fieldName, 'custom_textbox_');
}

function getPrintTemplateById(PDO $pdo, int $templateId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM print_templates WHERE id = ? LIMIT 1');
    $stmt->execute([$templateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function nextCustomTextboxFieldName(PDO $pdo, int $templateId): string
{
    $stmt = $pdo->prepare(
        'SELECT field_name FROM print_fields WHERE template_id = ? AND field_name LIKE ?'
    );
    $stmt->execute([$templateId, 'custom_textbox_%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        if (preg_match('/^custom_textbox_(\d+)$/', (string) $name, $matches)) {
            $max = max($max, (int) $matches[1]);
        }
    }

    return 'custom_textbox_' . ($max + 1);
}

/** @return array<string, mixed>|null */
function createPrintField(PDO $pdo, int $templateId, array $overrides = []): ?array
{
    $template = getPrintTemplateById($pdo, $templateId);
    if (!$template) {
        return null;
    }

    $fieldName = nextCustomTextboxFieldName($pdo, $templateId);
    preg_match('/(\d+)$/', $fieldName, $matches);
    $num = (int) ($matches[1] ?? 1);

    $width = 50.0;
    $height = 5.0;
    $paperW = (float) $template['paper_width_mm'];
    $paperH = (float) $template['paper_height_mm'];

    $defaults = [
        'field_name'  => $fieldName,
        'label'       => 'Custom textbox ' . $num,
        'x_mm'        => max(0, ($paperW - $width) / 2),
        'y_mm'        => max(0, ($paperH - $height) / 2),
        'width_mm'    => $width,
        'height_mm'   => $height,
        'font_family' => 'Arial',
        'font_size'   => 10.0,
        'font_weight' => 'normal',
        'alignment'   => 'left',
        'max_length'  => 120,
        'line_height' => 1.2,
        'enabled'     => 1,
    ];

    $data = array_merge($defaults, $overrides);

    $insert = $pdo->prepare(
        'INSERT INTO print_fields
         (template_id, field_name, label, x_mm, y_mm, width_mm, height_mm,
          font_family, font_size, font_weight, alignment, max_length, line_height, enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $templateId,
        $data['field_name'],
        $data['label'],
        (float) $data['x_mm'],
        (float) $data['y_mm'],
        (float) $data['width_mm'],
        (float) $data['height_mm'],
        $data['font_family'],
        (float) $data['font_size'],
        $data['font_weight'],
        $data['alignment'],
        (int) $data['max_length'],
        (float) $data['line_height'],
        (int) $data['enabled'],
    ]);

    $fieldId = (int) $pdo->lastInsertId();
    if ($fieldId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM print_fields WHERE id = ? LIMIT 1');
    $stmt->execute([$fieldId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function printFieldConfigForClient(array $field): array
{
    return [
        'id'          => (int) $field['id'],
        'field_name'  => (string) $field['field_name'],
        'label'       => (string) ($field['label'] ?? ''),
        'x_mm'        => (float) $field['x_mm'],
        'y_mm'        => (float) $field['y_mm'],
        'width_mm'    => (float) $field['width_mm'],
        'height_mm'   => (float) $field['height_mm'],
        'font_size'   => (float) $field['font_size'],
        'font_family' => (string) ($field['font_family'] ?? 'Arial'),
        'alignment'   => (string) ($field['alignment'] ?? 'left'),
        'enabled'     => (int) ($field['enabled'] ?? 1),
    ];
}

function getPrintFieldById(PDO $pdo, int $fieldId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM print_fields WHERE id = ? LIMIT 1');
    $stmt->execute([$fieldId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function deletePrintField(PDO $pdo, int $fieldId): bool
{
    $field = getPrintFieldById($pdo, $fieldId);
    if (!$field || !printIsCustomField((string) $field['field_name'])) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM print_fields WHERE id = ? LIMIT 1');

    return $stmt->execute([$fieldId]);
}

function updatePrintField(PDO $pdo, int $fieldId, array $data): bool
{
    $allowed = ['x_mm', 'y_mm', 'width_mm', 'height_mm', 'font_family', 'font_size', 'font_weight', 'alignment', 'max_length', 'line_height', 'enabled', 'label'];
    $sets = [];
    $params = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $sets[] = "`{$key}` = ?";
            $params[] = $data[$key];
        }
    }
    if ($sets === []) {
        return false;
    }
    $params[] = $fieldId;
    $sql = 'UPDATE print_fields SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?';

    return $pdo->prepare($sql)->execute($params);
}

function updatePrintCalibration(PDO $pdo, int $templateId, array $data, ?string $updatedBy = null): bool
{
    $stmt = $pdo->prepare(
        'INSERT INTO print_calibrations (template_id, x_offset_mm, y_offset_mm, scale_x, scale_y, updated_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            x_offset_mm = VALUES(x_offset_mm),
            y_offset_mm = VALUES(y_offset_mm),
            scale_x = VALUES(scale_x),
            scale_y = VALUES(scale_y),
            updated_by = VALUES(updated_by),
            updated_at = NOW()'
    );

    return $stmt->execute([
        $templateId,
        (float) ($data['x_offset_mm'] ?? 0),
        (float) ($data['y_offset_mm'] ?? 0),
        (float) ($data['scale_x'] ?? 1),
        (float) ($data['scale_y'] ?? 1),
        $updatedBy ?? staffId(),
    ]);
}

function advanceRequestAfterPrint(PDO $pdo, int $requestId, bool $testMode): void
{
    if ($testMode || $requestId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT status, tracking_code FROM document_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $status = normalizeRequestStatus((string) ($row['status'] ?? ''));
    if (!in_array($status, ['processing', 'printing', 'pending'], true)) {
        return;
    }

    $pdo->prepare("UPDATE document_requests SET status = 'printed', updated_at = NOW() WHERE id = ?")
        ->execute([$requestId]);
    logRequestStatusChange(
        $pdo,
        $requestId,
        (string) ($row['tracking_code'] ?? ''),
        $status,
        'printed',
        staffId(),
        'Status updated after certificate printing.'
    );
}

function csvPersonNameFromParts(array $input, string $prefix = ''): string
{
    return formatPersonName(
        trim((string) ($input[$prefix . 'first_name'] ?? '')),
        trim((string) ($input[$prefix . 'middle_name'] ?? '')) ?: null,
        trim((string) ($input[$prefix . 'last_name'] ?? ''))
    );
}

function csvDateFromPrintParts(array $input, string $prefix): ?string
{
    $dateKey = match ($prefix) {
        'birth'            => 'birth_date',
        'death'            => 'death_date',
        'marriage'         => 'marriage_date',
        'parents_marriage' => 'parents_marriage_date',
        'husband_birth'    => 'husband_birth_date',
        'wife_birth'       => 'wife_birth_date',
        default            => $prefix . '_date',
    };

    if (trim((string) ($input[$dateKey] ?? '')) !== '') {
        return function_exists('civilRecordNormalizeDate')
            ? civilRecordNormalizeDate($input[$dateKey])
            : null;
    }

    $day = trim((string) ($input[$prefix . '_day'] ?? ''));
    $month = trim((string) ($input[$prefix . '_month'] ?? ''));
    $year = trim((string) ($input[$prefix . '_year'] ?? ''));
    if ($day === '' || $month === '' || $year === '') {
        return null;
    }

    if (!function_exists('civilRecordNormalizeDate')) {
        return null;
    }

    return civilRecordNormalizeDate(trim("$month $day, $year"))
        ?? civilRecordNormalizeDate(trim("$year-$month-$day"));
}

function csvCollectPrintFillData(array $input, string $type): array
{
    $fill = [];
    foreach (printFillFieldNames($type) as $field) {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value !== '') {
            $fill[$field] = $value;
        }
    }

    return $fill;
}

function csvAssignIfEmpty(array &$input, string $key, mixed $value): void
{
    if (trim((string) ($input[$key] ?? '')) !== '') {
        return;
    }
    $text = trim((string) $value);
    if ($text !== '') {
        $input[$key] = $text;
    }
}

function csvAssignDateIfEmpty(array &$input, string $key, ?string $value): void
{
    if (trim((string) ($input[$key] ?? '')) !== '' || $value === null || $value === '') {
        return;
    }
    $input[$key] = $value;
}

/** Map manual entry print_fill[...] POST values onto civil record input before save. */
function prepareCivilRecordFormInput(array $input): array
{
    $type = strtolower(trim((string) ($input['record_type'] ?? '')));
    if (!in_array($type, ['birth', 'death', 'marriage'], true)) {
        return $input;
    }

    if (isset($input['print_fill']) && is_array($input['print_fill'])) {
        foreach ($input['print_fill'] as $field => $value) {
            if (!is_string($field)) {
                continue;
            }
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }
            if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
                $input[$field] = $trimmed;
            }
        }
    }

    return civilRecordExpandPrintFieldInput($input, $type);
}

/** Map print fill-in field CSV columns onto civil record storage fields. */
function civilRecordExpandPrintFieldInput(array $input, string $type): array
{
    $printFill = csvCollectPrintFillData($input, $type);

    if ($type === 'birth') {
        csvAssignIfEmpty($input, 'first_name', $printFill['child_first_name'] ?? '');
        csvAssignIfEmpty($input, 'middle_name', $printFill['child_middle_name'] ?? '');
        csvAssignIfEmpty($input, 'last_name', $printFill['child_last_name'] ?? '');
        csvAssignIfEmpty($input, 'place', $printFill['birth_place'] ?? '');
        csvAssignDateIfEmpty($input, 'birth_date', csvDateFromPrintParts($input, 'birth'));
        csvAssignIfEmpty($input, 'sex', $printFill['sex'] ?? '');
        csvAssignIfEmpty($input, 'birth_time', $printFill['birth_time'] ?? '');
        csvAssignIfEmpty($input, 'birth_type', $printFill['birth_type'] ?? '');
        csvAssignIfEmpty($input, 'birth_order', $printFill['birth_order'] ?? $printFill['multiple_birth_child_was'] ?? '');
        csvAssignIfEmpty($input, 'birth_weight', $printFill['birth_weight'] ?? '');
        csvAssignIfEmpty($input, 'mother_name', csvPersonNameFromParts($input, 'mother_'));
        csvAssignIfEmpty($input, 'mother_age', $printFill['mother_age'] ?? '');
        csvAssignIfEmpty($input, 'mother_nationality', $printFill['mother_citizenship'] ?? '');
        csvAssignIfEmpty($input, 'mother_religion', $printFill['mother_religion'] ?? '');
        csvAssignIfEmpty($input, 'mother_occupation', $printFill['mother_occupation'] ?? '');
        csvAssignIfEmpty($input, 'mother_residence', $printFill['mother_residence'] ?? '');
        csvAssignIfEmpty($input, 'mother_children_born_alive', $printFill['mother_children_born_alive'] ?? '');
        csvAssignIfEmpty($input, 'mother_children_still_living', $printFill['mother_children_still_living'] ?? '');
        csvAssignIfEmpty($input, 'mother_children_born_alive_now_dead', $printFill['mother_children_born_alive_now_dead'] ?? '');
        csvAssignIfEmpty($input, 'father_name', csvPersonNameFromParts($input, 'father_'));
        csvAssignIfEmpty($input, 'father_age', $printFill['father_age'] ?? '');
        csvAssignIfEmpty($input, 'father_nationality', $printFill['father_citizenship'] ?? '');
        csvAssignIfEmpty($input, 'father_religion', $printFill['father_religion'] ?? '');
        csvAssignIfEmpty($input, 'father_occupation', $printFill['father_occupation'] ?? '');
        csvAssignIfEmpty($input, 'father_residence', $printFill['father_residence'] ?? '');
        csvAssignDateIfEmpty($input, 'parents_marriage_date', csvDateFromPrintParts($input, 'parents_marriage'));
        csvAssignIfEmpty($input, 'parents_marriage_place', $printFill['parents_marriage_place'] ?? '');
        csvAssignDateIfEmpty(
            $input,
            'registration_date',
            function_exists('civilRecordNormalizeDate')
                ? (civilRecordNormalizeDate($printFill['registrar_date'] ?? '') ?? civilRecordNormalizeDate($printFill['registration_date'] ?? ''))
                : null
        );
        csvAssignIfEmpty($input, 'notes', $printFill['remarks_annotations'] ?? '');
    } elseif ($type === 'death') {
        csvAssignIfEmpty($input, 'first_name', $printFill['deceased_first_name'] ?? '');
        csvAssignIfEmpty($input, 'middle_name', $printFill['deceased_middle_name'] ?? '');
        csvAssignIfEmpty($input, 'last_name', $printFill['deceased_last_name'] ?? '');
        csvAssignDateIfEmpty($input, 'birth_date', csvDateFromPrintParts($input, 'birth'));
        csvAssignDateIfEmpty($input, 'death_date', csvDateFromPrintParts($input, 'death'));
        csvAssignIfEmpty($input, 'place', $printFill['place_of_death'] ?? '');
        csvAssignIfEmpty($input, 'sex', $printFill['sex'] ?? '');
        csvAssignIfEmpty($input, 'civil_status', $printFill['civil_status'] ?? '');
        csvAssignIfEmpty($input, 'religion', $printFill['religion'] ?? '');
        csvAssignIfEmpty($input, 'nationality', $printFill['citizenship'] ?? '');
        csvAssignIfEmpty($input, 'residence_deceased', $printFill['residence'] ?? '');
        csvAssignIfEmpty($input, 'occupation', $printFill['occupation'] ?? '');
        csvAssignIfEmpty($input, 'immediate_cause', $printFill['immediate_cause'] ?? '');
        csvAssignIfEmpty($input, 'contributory_cause', $printFill['contributory_cause'] ?? '');
        csvAssignIfEmpty($input, 'autopsy_performed', $printFill['autopsy_performed'] ?? '');
        csvAssignIfEmpty($input, 'attending_physician', $printFill['attending_physician'] ?? '');
        csvAssignIfEmpty($input, 'surviving_spouse_name', $printFill['surviving_spouse_name'] ?? '');
        csvAssignIfEmpty($input, 'surviving_spouse_address', $printFill['surviving_spouse_address'] ?? '');
        csvAssignIfEmpty($input, 'place_of_burial', $printFill['place_of_burial'] ?? '');
        csvAssignIfEmpty($input, 'death_time', $printFill['death_time'] ?? '');
        csvAssignIfEmpty($input, 'father_name', csvPersonNameFromParts($input, 'father_'));
        csvAssignIfEmpty($input, 'mother_name', csvPersonNameFromParts($input, 'mother_'));
        csvAssignIfEmpty($input, 'child_age_mother', $printFill['child_age_mother'] ?? '');
        csvAssignIfEmpty($input, 'child_delivery_method', $printFill['child_delivery_method'] ?? '');
        csvAssignIfEmpty($input, 'child_pregnancy_length', $printFill['child_pregnancy_length'] ?? '');
        csvAssignIfEmpty($input, 'child_birth_type', $printFill['child_birth_type'] ?? '');
        csvAssignIfEmpty($input, 'child_birth_order_infant', $printFill['child_birth_order'] ?? '');
        csvAssignIfEmpty($input, 'infant_cause_a', $printFill['infant_cause_a'] ?? '');
        csvAssignIfEmpty($input, 'infant_cause_b', $printFill['infant_cause_b'] ?? '');
        csvAssignIfEmpty($input, 'infant_cause_c', $printFill['infant_cause_c'] ?? '');
        csvAssignIfEmpty($input, 'infant_cause_d', $printFill['infant_cause_d'] ?? '');
        csvAssignIfEmpty($input, 'infant_cause_e', $printFill['infant_cause_e'] ?? '');
        csvAssignIfEmpty($input, 'postmortem_cause', $printFill['postmortem_cause'] ?? '');
        csvAssignDateIfEmpty(
            $input,
            'registration_date',
            function_exists('civilRecordNormalizeDate')
                ? (civilRecordNormalizeDate($printFill['registrar_date'] ?? '') ?? civilRecordNormalizeDate($printFill['registration_date'] ?? ''))
                : null
        );
        csvAssignIfEmpty($input, 'notes', $printFill['remarks_annotations'] ?? '');

        if (trim((string) ($input['age_death_years'] ?? '')) === '' && !empty($printFill['age_at_death'])) {
            if (preg_match('/(\d+)\s*y/i', (string) $printFill['age_at_death'], $m)) {
                $input['age_death_years'] = $m[1];
            } elseif (preg_match('/^\d+$/', trim((string) $printFill['age_at_death']))) {
                $input['age_death_years'] = trim((string) $printFill['age_at_death']);
            }
        }
    } elseif ($type === 'marriage') {
        csvAssignIfEmpty($input, 'husband_name', csvPersonNameFromParts($input, 'husband_'));
        csvAssignIfEmpty($input, 'wife_name', csvPersonNameFromParts($input, 'wife_'));
        csvAssignDateIfEmpty($input, 'husband_birth_date', csvDateFromPrintParts($input, 'husband_birth'));
        csvAssignDateIfEmpty($input, 'wife_birth_date', csvDateFromPrintParts($input, 'wife_birth'));
        csvAssignIfEmpty($input, 'husband_age', $printFill['husband_age'] ?? '');
        csvAssignIfEmpty($input, 'wife_age', $printFill['wife_age'] ?? '');
        csvAssignIfEmpty($input, 'husband_birth_place', $printFill['husband_birth_place'] ?? '');
        csvAssignIfEmpty($input, 'wife_birth_place', $printFill['wife_birth_place'] ?? '');
        csvAssignIfEmpty($input, 'husband_citizenship', $printFill['husband_citizenship'] ?? '');
        csvAssignIfEmpty($input, 'wife_citizenship', $printFill['wife_citizenship'] ?? '');
        csvAssignIfEmpty($input, 'husband_residence', $printFill['husband_residence'] ?? '');
        csvAssignIfEmpty($input, 'wife_residence', $printFill['wife_residence'] ?? '');
        csvAssignIfEmpty($input, 'husband_religion', $printFill['husband_religion'] ?? '');
        csvAssignIfEmpty($input, 'wife_religion', $printFill['wife_religion'] ?? '');
        csvAssignIfEmpty($input, 'husband_civil_status', $printFill['husband_civil_status'] ?? '');
        csvAssignIfEmpty($input, 'wife_civil_status', $printFill['wife_civil_status'] ?? '');
        csvAssignIfEmpty($input, 'husband_father_name', $printFill['husband_father_name'] ?? '');
        csvAssignIfEmpty($input, 'husband_father_citizenship', $printFill['husband_father_citizenship'] ?? '');
        csvAssignIfEmpty($input, 'husband_mother_maiden_name', $printFill['husband_mother_maiden_name'] ?? '');
        csvAssignIfEmpty($input, 'husband_mother_citizenship', $printFill['husband_mother_citizenship'] ?? '');
        csvAssignIfEmpty($input, 'husband_consent_person_name', $printFill['husband_consent_person_name'] ?? '');
        csvAssignIfEmpty($input, 'husband_consent_relationship', $printFill['husband_consent_relationship'] ?? '');
        csvAssignIfEmpty($input, 'husband_consent_residence', $printFill['husband_consent_residence'] ?? '');
        csvAssignIfEmpty($input, 'wife_father_name', $printFill['wife_father_name'] ?? '');
        csvAssignIfEmpty($input, 'wife_father_citizenship', $printFill['wife_father_citizenship'] ?? '');
        csvAssignIfEmpty($input, 'wife_mother_maiden_name', $printFill['wife_mother_maiden_name'] ?? '');
        csvAssignIfEmpty($input, 'wife_mother_citizenship', $printFill['wife_mother_citizenship'] ?? '');
        csvAssignIfEmpty($input, 'wife_consent_person_name', $printFill['wife_consent_person_name'] ?? '');
        csvAssignIfEmpty($input, 'wife_consent_relationship', $printFill['wife_consent_relationship'] ?? '');
        csvAssignIfEmpty($input, 'wife_consent_residence', $printFill['wife_consent_residence'] ?? '');
        csvAssignDateIfEmpty($input, 'marriage_date', csvDateFromPrintParts($input, 'marriage'));
        csvAssignIfEmpty($input, 'marriage_place', $printFill['marriage_place'] ?? '');
        csvAssignIfEmpty($input, 'marriage_time', $printFill['marriage_time'] ?? '');
        csvAssignIfEmpty($input, 'solemnized_by', $printFill['solemnizing_officer'] ?? '');
        csvAssignIfEmpty($input, 'witnesses', $printFill['witnesses'] ?? '');
        csvAssignIfEmpty($input, 'notes', $printFill['remarks_annotations'] ?? '');
    }

    csvAssignIfEmpty($input, 'registry_number', $printFill['registry_number'] ?? '');
    csvAssignIfEmpty($input, 'book_number', $printFill['book_number'] ?? '');
    csvAssignIfEmpty($input, 'page_number', $printFill['page_number'] ?? '');

    if ($printFill !== []) {
        $input['print_fill_data'] = $printFill;
    }

    return $input;
}

/** @return list<string|null> */
function civilRecordCsvSampleRowFromPrintFields(string $type): array
{
    $record = printCalibrationSampleRecord($type);
    $values = printBuildFieldValues($record, $type, ['keep_empty' => false]);
    $catalog = printFieldCatalog()[$type] ?? [];

    foreach (array_merge($catalog['front'] ?? [], $catalog['back'] ?? []) as $field => $label) {
        if (!isset($values[$field]) || trim((string) $values[$field]) === '') {
            $values[$field] = printDefaultSampleValue($field, $label);
        }
    }

    return array_map(
        static fn (string $column) => (($values[$column] ?? '') !== '' ? (string) $values[$column] : null),
        printFillCsvColumns($type)
    );
}
