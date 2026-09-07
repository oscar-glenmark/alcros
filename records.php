<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/printing.php';
requireStaffLogin();
requirePageAccess('records.php');

$activePage = 'records.php';
$pdo = getDB();
ensurePersonNamePartColumns($pdo);

try {
    $pdo->query('SELECT deleted_at FROM civil_records LIMIT 1');
} catch (PDOException $e) {
    $pdo->exec('ALTER TABLE civil_records ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER notes');
}

function ensureCivilRecordExtendedColumns(PDO $pdo): void
{
    ensureCivilRecordTypeTables($pdo);
}

ensureCivilRecordExtendedColumns($pdo);
ensureCivilRecordPrintSchema($pdo);
syncCivilRecordDerivedFields($pdo);

$validTypes = ['birth', 'death', 'marriage'];
$validSorts = [
    'name'    => 'cr.last_name, cr.first_name',
    'type'    => 'cr.record_type',
    'date'    => 'COALESCE(cr.event_date, cr.birth_date)',
    'created' => 'cr.created_at',
];

function buildRecordsUrl(array $overrides = []): string
{
    $params = array_merge([
        'type' => $_GET['type'] ?? 'all',
        'q'    => $_GET['q'] ?? '',
        'sort' => $_GET['sort'] ?? 'name',
        'dir'  => $_GET['dir'] ?? 'asc',
        'page' => (int) ($_GET['page'] ?? 1),
    ], $overrides);
    foreach (['type', 'q', 'sort', 'dir', 'page', 'edit'] as $key) {
        if ($key === 'type' && ($params['type'] ?? '') === 'all') unset($params['type']);
        elseif ($key === 'sort' && ($params['sort'] ?? '') === 'name') unset($params['sort']);
        elseif ($key === 'dir' && ($params['dir'] ?? '') === 'asc') unset($params['dir']);
        elseif ($key === 'page' && (int) ($params['page'] ?? 1) <= 1) unset($params['page']);
        elseif ($key === 'q' && ($params['q'] ?? '') === '') unset($params['q']);
        elseif ($key === 'edit' && empty($params['edit'])) unset($params['edit']);
    }
    return 'records.php' . ($params ? '?' . http_build_query($params) : '');
}

function currentRecordsFilters(): array
{
    return [
        'type' => $_GET['type'] ?? 'all',
        'q'    => $_GET['q'] ?? '',
        'sort' => $_GET['sort'] ?? 'name',
        'dir'  => strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
        'page' => max(1, (int) ($_GET['page'] ?? 1)),
    ];
}

function buildRecordsWhere(array $filters): array
{
    $where  = 'cr.deleted_at IS NULL';
    $params = [];

    if ($filters['type'] !== 'all' && in_array($filters['type'], ['birth', 'death', 'marriage'], true)) {
        $where .= ' AND cr.record_type = ?';
        $params[] = $filters['type'];
    }
    if ($filters['q'] !== '') {
        $term = '%' . $filters['q'] . '%';
        $clauses = [
            'cr.first_name LIKE ?',
            'cr.middle_name LIKE ?',
            'cr.last_name LIKE ?',
            'cr.registry_number LIKE ?',
            'cr.father_name LIKE ?',
            'cr.mother_name LIKE ?',
            'cr.place LIKE ?',
            'cr.notes LIKE ?',
            'CAST(cr.id AS CHAR) LIKE ?',
            "DATE_FORMAT(cr.birth_date, '%Y-%m-%d') LIKE ?",
            "DATE_FORMAT(cr.event_date, '%Y-%m-%d') LIKE ?",
            "EXISTS (
                SELECT 1 FROM death_record_details drd
                WHERE drd.civil_record_id = cr.id
                AND (drd.code_number LIKE ? OR DATE_FORMAT(drd.registration_date, '%Y-%m-%d') LIKE ?)
            )",
            "EXISTS (
                SELECT 1 FROM birth_record_details brd
                WHERE brd.civil_record_id = cr.id
                AND (
                    DATE_FORMAT(brd.registration_date, '%Y-%m-%d') LIKE ?
                    OR DATE_FORMAT(brd.parents_marriage_date, '%Y-%m-%d') LIKE ?
                )
            )",
            "EXISTS (
                SELECT 1 FROM marriage_record_details mrd
                WHERE mrd.civil_record_id = cr.id
                AND (
                    mrd.husband_name LIKE ?
                    OR mrd.wife_name LIKE ?
                    OR mrd.husband_father_name LIKE ?
                    OR mrd.husband_mother_maiden_name LIKE ?
                    OR mrd.wife_father_name LIKE ?
                    OR mrd.wife_mother_maiden_name LIKE ?
                    OR mrd.solemnized_by LIKE ?
                    OR mrd.witnesses LIKE ?
                )
            )",
        ];
        $where .= ' AND (' . implode(' OR ', $clauses) . ')';
        $params = array_merge(
            $params,
            array_fill(0, 9, $term),
            [$term, $term],
            [$term, $term],
            array_fill(0, 8, $term)
        );
    }

    return [$where, $params];
}

function civilRecordSearchBlob(array $r): string
{
    $parts = [
        civilRecordDisplayName($r),
        $r['first_name'] ?? '',
        $r['middle_name'] ?? '',
        $r['last_name'] ?? '',
        civilRecordRegistryNumber($r) ?? '',
        $r['code_number'] ?? '',
        $r['father_name'] ?? '',
        $r['mother_name'] ?? '',
        $r['husband_name'] ?? '',
        $r['wife_name'] ?? '',
        $r['husband_father_name'] ?? '',
        $r['husband_mother_maiden_name'] ?? '',
        $r['wife_father_name'] ?? '',
        $r['wife_mother_maiden_name'] ?? '',
        $r['place'] ?? '',
        $r['marriage_place'] ?? '',
        $r['notes'] ?? '',
        (string) ($r['id'] ?? ''),
    ];

    foreach (['birth_date', 'event_date', 'registration_date', 'parents_marriage_date'] as $dateCol) {
        if (!empty($r[$dateCol])) {
            $parts[] = (string) $r[$dateCol];
            $parts[] = formatRecordDate((string) $r[$dateCol]);
        }
    }

    return implode(' ', array_filter(array_map(static fn ($v) => trim((string) $v), $parts), static fn ($v) => $v !== ''));
}

function recordInitial(string $name): string
{
    return strtoupper(substr(trim($name), 0, 1));
}

function civilRecordExtendedFieldNames(): array
{
    return civilRecordAllTypeFieldNames();
}

function civilRecordCsvDateFields(): array
{
    return [
        'birth_date', 'event_date', 'registration_date', 'parents_marriage_date',
        'death_date', 'marriage_date', 'husband_birth_date', 'wife_birth_date',
    ];
}

function civilRecordNormalizeDate(?string $value): ?string
{
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $serial = (float) $value;
        if ($serial >= 25569 && $serial <= 60000) {
            $timestamp = (int) round(($serial - 25569) * 86400);
            return gmdate('Y-m-d', $timestamp);
        }
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
    }

    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $m)) {
        $year = (int) $m[3];
        $a = (int) $m[1];
        $b = (int) $m[2];
        if ($a > 12 && $b <= 12) {
            $day = $a;
            $month = $b;
        } else {
            $month = $a;
            $day = $b;
        }
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return null;
}

function normalizeCsvHeaderRow(array $headers): array
{
    if ($headers === []) {
        return [];
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/u', '', (string) $headers[0]);
    return array_map(static fn ($header) => trim((string) $header), $headers);
}

function csvRowLooksLikeHeader(array $row): bool
{
    $normalized = array_map('normalizeCsvHeader', $row);
    $headerKeys = [
        'first_name', 'middle_name', 'last_name', 'person_name', 'full_name', 'fullname',
        'child_first_name', 'child_last_name', 'deceased_first_name', 'deceased_last_name',
        'husband_first_name', 'husband_last_name', 'wife_first_name', 'wife_last_name',
        'registry_number', 'birth_date', 'death_date', 'marriage_date',
        'birth_place', 'place_of_death', 'marriage_place',
        'husband_name', 'wife_name', 'record_type', 'province', 'city_municipality',
    ];
    foreach ($headerKeys as $key) {
        if (in_array($key, $normalized, true)) {
            return true;
        }
    }
    return false;
}

function isCsvRowEmpty(array $row): bool
{
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }
    return true;
}

function applyCsvDateNormalization(array $input): array
{
    foreach (civilRecordCsvDateFields() as $field) {
        if (!array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
            continue;
        }
        $normalized = civilRecordNormalizeDate($input[$field]);
        $input[$field] = $normalized ?? '';
    }
    return $input;
}

function applyCsvTypeAliases(array $input, string $importType): array
{
    if ($importType === 'death') {
        if (trim((string) ($input['death_date'] ?? '')) === '' && trim((string) ($input['event_date'] ?? '')) !== '') {
            $input['death_date'] = $input['event_date'];
        }
    }
    if ($importType === 'marriage') {
        if (trim((string) ($input['marriage_date'] ?? '')) === '' && trim((string) ($input['event_date'] ?? '')) !== '') {
            $input['marriage_date'] = $input['event_date'];
        }
        if (trim((string) ($input['marriage_place'] ?? '')) === '' && trim((string) ($input['place'] ?? '')) !== '') {
            $input['marriage_place'] = $input['place'];
        }
    }

    return $input;
}

function detectCsvDelimiter(string $filePath): string
{
    $sample = file_get_contents($filePath, false, null, 0, 4096);
    if ($sample === false || $sample === '') {
        return ',';
    }
    $firstLine = strtok($sample, "\r\n");
    if ($firstLine === false || $firstLine === '') {
        return ',';
    }
    $comma = substr_count($firstLine, ',');
    $semi = substr_count($firstLine, ';');
    $tab = substr_count($firstLine, "\t");

    if ($tab > $comma && $tab > $semi) {
        return "\t";
    }
    if ($semi > $comma) {
        return ';';
    }

    return ',';
}

function readCsvRow($handle, string $delimiter): array|false
{
    $row = fgetcsv($handle, 0, $delimiter);
    if ($row === false) {
        return false;
    }
    if (isset($row[0])) {
        $row[0] = preg_replace('/^\xEF\xBB\xBF/u', '', (string) $row[0]);
    }
    return trimLeadingEmptyCsvCells(expandCsvRowIfMerged($row, $delimiter));
}

function trimLeadingEmptyCsvCells(array $row): array
{
    while ($row !== [] && trim((string) ($row[0] ?? '')) === '') {
        array_shift($row);
    }

    return $row;
}

function expandCsvRowIfMerged(array $row, string $delimiter): array
{
    if (count($row) !== 1) {
        return $row;
    }

    $cell = (string) ($row[0] ?? '');
    if ($cell === '') {
        return $row;
    }

    foreach (array_unique([$delimiter, ',', ';', "\t"]) as $sep) {
        if (substr_count($cell, $sep) < 1) {
            continue;
        }
        $parsed = str_getcsv($cell, $sep);
        if (count($parsed) > 1) {
            if (isset($parsed[0])) {
                $parsed[0] = preg_replace('/^\xEF\xBB\xBF/u', '', (string) $parsed[0]);
            }
            return $parsed;
        }
    }

    return $row;
}

function csvRowLooksMergedIntoOneCell(array $row): bool
{
    if (count($row) !== 1) {
        return false;
    }
    $cell = (string) ($row[0] ?? '');
    return str_contains($cell, ',') || str_contains($cell, ';') || str_contains($cell, "\t");
}

function buildCsvInputFromRow(array $headers, array $row, string $importType): array
{
    $input = ['record_type' => $importType];
    $normalizedHeaders = array_map('normalizeCsvHeader', normalizeCsvHeaderRow($headers));
    $hasKnownHeader = csvRowLooksLikeHeader($headers);

    if ($hasKnownHeader) {
        foreach ($normalizedHeaders as $i => $key) {
            if ($key === '' || in_array($key, civilRecordCsvSkipColumns(), true)) {
                continue;
            }
            $input[$key] = trim((string) ($row[$i] ?? ''));
        }
        $csvType = strtolower(trim((string) ($input['record_type'] ?? '')));
        $input['record_type'] = in_array($csvType, ['birth', 'death', 'marriage'], true) ? $csvType : $importType;
        return civilRecordExpandPrintFieldInput(
            applyCsvDateNormalization(applyCsvTypeAliases($input, $input['record_type'])),
            $input['record_type']
        );
    }

    $columns = civilRecordCsvColumns($importType);
    foreach ($columns as $i => $column) {
        $input[$column] = trim((string) ($row[$i] ?? ''));
    }

    return civilRecordExpandPrintFieldInput(
        applyCsvDateNormalization(applyCsvTypeAliases($input, $importType)),
        $importType
    );
}

function normalizeCsvHeader(?string $header): string
{
    $key = strtolower(trim((string) ($header ?? '')));
    $key = str_replace([' ', '-'], '_', $key);
    $key = preg_replace('/[^a-z0-9_]/', '', $key);
    $key = preg_replace('/_+/', '_', $key);
    $key = trim($key, '_');

    return match ($key) {
        'date_of_birth', 'dob' => 'birth_date',
        'date_of_death', 'dod' => 'death_date',
        'date_of_marriage', 'dom' => 'marriage_date',
        'date_of_registration' => 'registration_date',
        'firstname', 'first_name' => 'first_name',
        'middlename', 'middle_name' => 'middle_name',
        'lastname', 'last_name' => 'last_name',
        'child_firstname' => 'child_first_name',
        'child_middlename' => 'child_middle_name',
        'child_lastname' => 'child_last_name',
        'deceased_firstname' => 'deceased_first_name',
        'deceased_middlename' => 'deceased_middle_name',
        'deceased_lastname' => 'deceased_last_name',
        'husband_firstname' => 'husband_first_name',
        'husband_middlename' => 'husband_middle_name',
        'husband_lastname' => 'husband_last_name',
        'wife_firstname' => 'wife_first_name',
        'wife_middlename' => 'wife_middle_name',
        'wife_lastname' => 'wife_last_name',
        'mother_firstname' => 'mother_first_name',
        'mother_middlename' => 'mother_middle_name',
        'mother_lastname' => 'mother_last_name',
        'father_firstname' => 'father_first_name',
        'father_middlename' => 'father_middle_name',
        'father_lastname' => 'father_last_name',
        'name_of_deceased', 'deceased_name', 'full_name', 'fullname', 'name' => 'person_name',
        'place_of_birth' => 'birth_place',
        'place_of_marriage' => 'marriage_place',
        'time_of_marriage' => 'marriage_time',
        'solemnized_by', 'solemnizing_officer' => 'solemnizing_officer',
        'citizenship' => 'citizenship',
        'registry_no', 'registry', 'registry_num', 'registry_id' => 'registry_number',
        default => $key,
    };
}

function civilRecordRegistryNumber(array $record): ?string
{
    $registry = trim((string) ($record['registry_number'] ?? ''));
    if ($registry !== '') {
        return $registry;
    }

    $code = trim((string) ($record['code_number'] ?? ''));
    return $code !== '' ? $code : null;
}

function civilRecordCsvSkipColumns(): array
{
    return ['id', 'created_at', 'deleted_at'];
}

function prepareCsvImportFile(string $filePath): array
{
    $head = @file_get_contents($filePath, false, null, 0, 2);
    if ($head === false) {
        throw new InvalidArgumentException('Could not read the uploaded CSV file.');
    }

    $tempPath = null;
    if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")) {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new InvalidArgumentException('Could not read the uploaded CSV file.');
        }
        if (str_starts_with($contents, "\xFF\xFE")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        } else {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }
        $tempPath = tempnam(sys_get_temp_dir(), 'alcros_csv_');
        file_put_contents($tempPath, $contents);
        $filePath = $tempPath;
    }

    return [$filePath, $tempPath];
}

function csvUploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'CSV file is too large. Use a smaller file or increase upload_max_filesize and post_max_size in PHP (currently ' . (ini_get('upload_max_filesize') ?: '?') . ' / ' . (ini_get('post_max_size') ?: '?') . ').',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Please choose a CSV file to import.',
        default => 'File upload failed (error code ' . $code . '). Please try again.',
    };
}

function csvImportSampleRowValues(string $importType): array
{
    static $cache = [];
    if (!isset($cache[$importType])) {
        $cache[$importType] = array_map(
            static fn ($value) => trim((string) ($value ?? '')),
            civilRecordCsvSampleRow($importType)
        );
    }

    return $cache[$importType];
}

function csvRowMatchesSampleRow(array $headers, array $row, string $importType): bool
{
    if (!csvRowLooksLikeHeader($headers)) {
        $sample = csvImportSampleRowValues($importType);
        foreach ($sample as $i => $expected) {
            $actual = trim((string) ($row[$i] ?? ''));
            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    $columns = civilRecordCsvColumns($importType);
    $sample = csvImportSampleRowValues($importType);
    $input = buildCsvInputFromRow($headers, $row, $importType);

    foreach ($columns as $i => $column) {
        $actual = trim((string) ($input[$column] ?? ''));
        $expected = trim((string) ($sample[$i] ?? ''));
        if ($actual !== $expected) {
            return false;
        }
    }

    return true;
}

function parseCsvRecordRow(array $headers, array $row, string $importType, ?string &$error = null): ?array
{
    $error = null;
    if (isCsvRowEmpty($row)) {
        return null;
    }

    $input = buildCsvInputFromRow($headers, $row, $importType);
    $effectiveType = $input['record_type'] ?? $importType;

    if ($effectiveType === 'marriage') {
        $husbandName = trim($input['husband_name'] ?? '');
        $wifeName = trim($input['wife_name'] ?? '');
        if ($husbandName === '' || $wifeName === '') {
            $husbandParts = personNamePartsFromInput($input, 'husband_');
            $wifeParts = personNamePartsFromInput($input, 'wife_');
            if ($husbandName === '' && $husbandParts['first_name'] !== '' && $husbandParts['last_name'] !== '') {
                $input['husband_name'] = formatPersonName($husbandParts['first_name'], $husbandParts['middle_name'], $husbandParts['last_name']);
                $husbandName = $input['husband_name'];
            }
            if ($wifeName === '' && $wifeParts['first_name'] !== '' && $wifeParts['last_name'] !== '') {
                $input['wife_name'] = formatPersonName($wifeParts['first_name'], $wifeParts['middle_name'], $wifeParts['last_name']);
                $wifeName = $input['wife_name'];
            }
        }
        if ($husbandName === '' || $wifeName === '') {
            if (csvRowLooksMergedIntoOneCell($row)) {
                $error = 'This row is in one Excel column. Open the CSV template, paste each value in its own column (A, B, C…), then Save As → CSV UTF-8.';
            } else {
                $error = 'husband_first_name + husband_last_name and wife_first_name + wife_last_name are required (legacy CSV may use husband_name / wife_name).';
            }
            return null;
        }
    } else {
        $parts = personNamePartsFromInput($input);
        if (($parts['first_name'] === '' || $parts['last_name'] === '') && trim($input['person_name'] ?? '') !== '') {
            $parts = parsePersonNameToParts(trim($input['person_name']));
            $input = array_merge($input, $parts);
        }
        if ($parts['first_name'] === '' || $parts['last_name'] === '') {
            $namePrefix = $effectiveType === 'death' ? 'deceased_' : 'child_';
            $altParts = personNamePartsFromInput($input, $namePrefix);
            if ($altParts['first_name'] !== '' && $altParts['last_name'] !== '') {
                $input = array_merge($input, [
                    'first_name'  => $altParts['first_name'],
                    'middle_name' => $altParts['middle_name'],
                    'last_name'   => $altParts['last_name'],
                ]);
                $parts = $altParts;
            }
        }
        if ($parts['first_name'] === '' || $parts['last_name'] === '') {
            if (csvRowLooksMergedIntoOneCell($row)) {
                $error = 'This row is in one Excel column. Open the CSV template, paste each value in its own column (A, B, C…), then Save As → CSV UTF-8.';
            } else {
                $required = $effectiveType === 'death'
                    ? 'deceased_first_name and deceased_last_name are required (legacy CSV may use first_name / last_name or person_name).'
                    : 'child_first_name and child_last_name are required (legacy CSV may use first_name / last_name or person_name).';
                $error = $required;
            }
            return null;
        }
    }

    try {
        return normalizeRecordInput($input, true);
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        return null;
    }
}

function importCsvRecords(PDO $pdo, string $filePath, string $importType): array
{
    if (!in_array($importType, ['birth', 'death', 'marriage'], true)) {
        throw new InvalidArgumentException('Invalid import type.');
    }

    @set_time_limit(600);

    [$csvPath, $tempPath] = prepareCsvImportFile($filePath);
    $delimiter = detectCsvDelimiter($csvPath);
    $handle = fopen($csvPath, 'r');
    if ($handle === false) {
        if ($tempPath !== null) {
            @unlink($tempPath);
        }
        throw new InvalidArgumentException('Could not read the uploaded CSV file.');
    }

    $imported = 0;
    $skipped = 0;
    $sampleSkipped = 0;
    $errors = [];
    $headers = [];
    $lineNum = 0;
    $maxErrors = 50;
    $importOptions = ['insert_details_only' => true];
    $inTransaction = false;

    try {
        $pdo->beginTransaction();
        $inTransaction = true;

        $firstRow = readCsvRow($handle, $delimiter);
        $lineNum++;
        if ($firstRow !== false && !isCsvRowEmpty($firstRow)) {
            if (csvRowLooksLikeHeader($firstRow)) {
                $headers = normalizeCsvHeaderRow($firstRow);
            } else {
                if (csvRowMatchesSampleRow([], $firstRow, $importType)) {
                    $sampleSkipped++;
                } else {
                    $rowError = null;
                    $parsed = parseCsvRecordRow([], $firstRow, $importType, $rowError);
                    if ($parsed === null) {
                        $skipped++;
                        if (count($errors) < $maxErrors) {
                            $errors[] = 'Row 1: ' . ($rowError ?: 'invalid data.');
                        }
                    } else {
                        try {
                            insertCivilRecord($pdo, $parsed, $importOptions);
                            $imported++;
                        } catch (PDOException) {
                            $skipped++;
                            if (count($errors) < $maxErrors) {
                                $errors[] = 'Row 1: could not save record.';
                            }
                        }
                    }
                }
            }
        }

        while (($row = readCsvRow($handle, $delimiter)) !== false) {
            $lineNum++;
            if (isCsvRowEmpty($row)) {
                continue;
            }

            if (csvRowMatchesSampleRow($headers, $row, $importType)) {
                $sampleSkipped++;
                continue;
            }

            $rowError = null;
            $parsed = parseCsvRecordRow($headers, $row, $importType, $rowError);
            if ($parsed === null) {
                $skipped++;
                if (count($errors) < $maxErrors) {
                    $errors[] = "Row $lineNum: " . ($rowError ?: 'invalid data.');
                }
                continue;
            }

            try {
                insertCivilRecord($pdo, $parsed, $importOptions);
                $imported++;
            } catch (PDOException) {
                $skipped++;
                if (count($errors) < $maxErrors) {
                    $errors[] = 'Row ' . $lineNum . ': could not save "' . civilRecordDisplayName($parsed) . '".';
                }
            }
        }

        $pdo->commit();
        $inTransaction = false;
    } catch (Throwable $e) {
        if ($inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        fclose($handle);
        if ($tempPath !== null) {
            @unlink($tempPath);
        }
    }

    return compact('imported', 'skipped', 'errors') + ['sample_skipped' => $sampleSkipped];
}

function insertCivilRecord(PDO $pdo, array $data, array $options = []): void
{
    saveCivilRecord($pdo, $data, null, $options);
}

function civilRecordExportRowValues(array $row, string $type): array
{
    $values = printBuildFieldValues($row, $type, ['keep_empty' => true]);

    return array_map(
        static function (string $column) use ($values) {
            $value = $values[$column] ?? null;
            if ($value === null || trim((string) $value) === '') {
                return null;
            }

            return (string) $value;
        },
        civilRecordCsvColumns($type)
    );
}

function exportCivilRecordsCsv(PDO $pdo, array $filters): void
{
    global $validTypes;

    [$where, $params] = buildRecordsWhere($filters);
    $exportType = $filters['type'] ?? 'all';
    $types = ($exportType !== 'all' && in_array($exportType, $validTypes, true))
        ? [$exportType]
        : $validTypes;

    $stmt = $pdo->prepare("SELECT cr.* FROM civil_records cr WHERE $where ORDER BY cr.record_type ASC, cr.last_name ASC, cr.first_name ASC");
    $stmt->execute($params);
    $rows = hydrateCivilRecordRows($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $grouped = array_fill_keys($validTypes, []);
    foreach ($rows as $row) {
        $recordType = (string) ($row['record_type'] ?? '');
        if (isset($grouped[$recordType])) {
            $grouped[$recordType][] = $row;
        }
    }

    $filename = 'alcros_civil_records_'
        . ($exportType !== 'all' ? $exportType . '_' : 'all_')
        . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('Could not create export file.');
    }

    fprintf($out, "\xEF\xBB\xBF");

    if (count($types) === 1) {
        $type = $types[0];
        fputcsv($out, civilRecordCsvColumns($type));
        foreach ($grouped[$type] as $row) {
            fputcsv($out, civilRecordExportRowValues($row, $type));
        }
    } else {
        fputcsv($out, ['ALCROS Civil Records Export']);
        fputcsv($out, ['Generated on', date('Y-m-d g:i A')]);
        if (($filters['q'] ?? '') !== '') {
            fputcsv($out, ['Search filter', $filters['q']]);
        }
        fputcsv($out, ['Total records', (string) count($rows)]);
        fputcsv($out, []);

        foreach ($types as $type) {
            $sectionRows = $grouped[$type];
            fputcsv($out, ['--- ' . strtoupper(civilRecordTypeLabel($type)) . ' RECORDS (' . count($sectionRows) . ') ---']);
            fputcsv($out, civilRecordCsvColumns($type));
            foreach ($sectionRows as $row) {
                fputcsv($out, civilRecordExportRowValues($row, $type));
            }
            fputcsv($out, []);
        }

        fputcsv($out, ['End of export']);
    }

    fclose($out);
}

function normalizeRecordInput(array $input, bool $fromCsvImport = false): array
{
    global $validTypes;
    $providedFields = array_keys($input);
    $input = applyCsvDateNormalization($input);
    $type = $input['record_type'] ?? '';
    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException('Invalid record type.');
    }

    if ($type === 'marriage') {
        $nameParts = ['first_name' => null, 'middle_name' => null, 'last_name' => null];
        if (trim($input['husband_name'] ?? '') === '' || trim($input['wife_name'] ?? '') === '') {
            throw new InvalidArgumentException('Husband and wife names are required for marriage records.');
        }
    } else {
        $nameParts = personNamePartsFromInput($input);
        if (($nameParts['first_name'] === '' || $nameParts['last_name'] === '') && trim($input['person_name'] ?? '') !== '') {
            $nameParts = parsePersonNameToParts(trim($input['person_name']));
        }
        if ($nameParts['first_name'] === '' || $nameParts['last_name'] === '') {
            throw new InvalidArgumentException('First name and last name are required.');
        }
    }

    $data = array_merge([
        'record_type'     => $type,
        'registry_number' => trim($input['registry_number'] ?? '') ?: null,
        'first_name'      => $nameParts['first_name'],
        'middle_name'     => $nameParts['middle_name'],
        'last_name'       => $nameParts['last_name'],
        'birth_date'      => trim($input['birth_date'] ?? '') ?: null,
        'event_date'      => trim($input['event_date'] ?? '') ?: null,
        'place'           => trim($input['place'] ?? '') ?: null,
        'father_name'     => trim($input['father_name'] ?? '') ?: null,
        'mother_name'     => trim($input['mother_name'] ?? '') ?: null,
        'notes'           => trim($input['notes'] ?? '') ?: null,
    ], civilRecordTypeDefaults($type));

    if (isset($input['print_fill']) && is_array($input['print_fill'])) {
        $submittedFill = $input['print_fill'];
    } elseif (isset($input['print_fill_data'])) {
        $submittedFill = is_array($input['print_fill_data'])
            ? $input['print_fill_data']
            : printParseFillData($input['print_fill_data']);
    } else {
        $submittedFill = [];
    }

    if ($type === 'birth') {
        civilRecordApplyInputDetailFields($data, $input, $type);
        $data['event_date'] = $data['birth_date'];
    }

    if ($type === 'death') {
        civilRecordApplyInputDetailFields($data, $input, $type);
        $data['event_date'] = trim($input['death_date'] ?? $input['event_date'] ?? '') ?: null;
        if (trim((string) ($data['code_number'] ?? '')) === '') {
            $data['code_number'] = $data['registry_number'] ?: null;
        }
    }

    if ($type === 'marriage') {
        civilRecordApplyInputDetailFields($data, $input, $type);
        $data['event_date'] = trim($input['marriage_date'] ?? $input['event_date'] ?? '') ?: null;
        $data['place'] = trim($input['marriage_place'] ?? $input['place'] ?? '') ?: null;
    }

    if (trim((string) ($data['registry_number'] ?? '')) === '') {
        $fallbackRegistry = trim((string) ($data['code_number'] ?? ''));
        if ($fallbackRegistry !== '') {
            $data['registry_number'] = $fallbackRegistry;
        }
    }

    $data['print_fill_data'] = $fromCsvImport
        ? printFillDataForCsvImport($data, $type, $submittedFill)
        : printRebuildRecordFillData($data, $type, $submittedFill);

    $data['_provided_fields'] = $providedFields;

    return $data;
}

// JSON record details for view modal
if (isset($_GET['action']) && $_GET['action'] === 'view_record') {
    header('Content-Type: application/json; charset=UTF-8');
    $recordId = (int) ($_GET['id'] ?? 0);
    $record = fetchFullCivilRecord($pdo, $recordId);
    if (!$record) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Record not found.']);
        exit;
    }
    $certificateType = (string) ($record['record_type'] ?? '');
    $printValues = in_array($certificateType, $validTypes, true)
        ? printBuildFieldValues($record, $certificateType, ['keep_empty' => false])
        : [];
    echo json_encode([
        'ok'           => true,
        'record'       => $record,
        'print_values' => $printValues,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSV template download
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    $tplType = $_GET['type'] ?? 'birth';
    if (!in_array($tplType, $validTypes, true)) {
        $tplType = 'birth';
    }
    $filename = "alcros_{$tplType}_import_template.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, civilRecordCsvColumns($tplType));
    fputcsv($out, civilRecordCsvSampleRow($tplType));
    fclose($out);
    exit;
}

// Export filtered records
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    @set_time_limit(300);
    $filters = currentRecordsFilters();
    exportCivilRecordsCsv($pdo, $filters);
    logActivity(staffId(), 'CSV Export', 'Exported civil records (' . ($filters['type'] ?? 'all') . ')');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $data = normalizeRecordInput(prepareCivilRecordFormInput($_POST));
            insertCivilRecord($pdo, $data);
            logActivity(staffId(), 'Record Created', 'New ' . $data['record_type'] . ' record: ' . civilRecordDisplayName($data));
            recordsFlashSet('success', 'Record saved successfully.');
        } elseif ($action === 'update' && !empty($_POST['record_id'])) {
            $data = normalizeRecordInput(prepareCivilRecordFormInput($_POST));
            $id = (int) $_POST['record_id'];
            saveCivilRecord($pdo, $data, $id);
            logActivity(staffId(), 'Record Updated', "Updated record #$id: " . civilRecordDisplayName($data));
            recordsFlashSet('success', 'Record updated successfully.');
        } elseif ($action === 'import_csv') {
            @set_time_limit(600);
            if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                throw new InvalidArgumentException('Please choose a CSV file to import.');
            }
            if (($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException(csvUploadErrorMessage((int) ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE)));
            }
            $importType = $_POST['import_type'] ?? '';
            if (!in_array($importType, $validTypes, true)) {
                throw new InvalidArgumentException('Please choose Birth, Death, or Marriage before importing.');
            }
            $result = importCsvRecords($pdo, $_FILES['csv_file']['tmp_name'], $importType);
            $imported = $result['imported'];
            $skipped = $result['skipped'];
            $errors = $result['errors'];
            $sampleSkipped = $result['sample_skipped'] ?? 0;

            if ($imported > 0) {
                logActivity(staffId(), 'CSV Import', "Imported $imported $importType records");
            }

            if ($imported === 0) {
                $msg = 'No records were imported.';
                if ($sampleSkipped > 0) {
                    $msg .= " $sampleSkipped template sample row(s) skipped — add your own data rows below the header.";
                }
                if ($skipped > 0) {
                    $msg .= " $skipped row(s) skipped.";
                }
                if (!empty($errors)) {
                    $msg .= ' ' . implode(' ', array_slice($errors, 0, 3));
                }
                recordsFlashSet('error', $msg);
            } else {
                $msg = "Successfully imported $imported " . ucfirst($importType) . ' record(s).';
                if ($sampleSkipped > 0) {
                    $msg .= " Skipped $sampleSkipped template sample row(s).";
                }
                if ($skipped > 0) {
                    $msg .= " Skipped $skipped invalid row(s).";
                    if (!empty($errors)) {
                        $msg .= ' ' . implode(' ', array_slice($errors, 0, 2));
                    }
                }
                recordsFlashSet('success', $msg);
            }
        }
    } catch (InvalidArgumentException $e) {
        recordsFlashSet('error', $e->getMessage());
    } catch (PDOException $e) {
        recordsFlashSet('error', 'Could not complete the action. Please try again.');
    } catch (Throwable $e) {
        error_log('ALCROS CSV import failed: ' . $e->getMessage());
        recordsFlashSet('error', 'Import failed: ' . $e->getMessage());
    }

    redirectWithAuth('records.php', currentRecordsFilters());
}

$filters = currentRecordsFilters();
$type   = $filters['type'];
$search = trim($filters['q']);
$page   = $filters['page'];
$sort   = $filters['sort'];
$dir    = $filters['dir'];
$perPage = 10;
$offset  = ($page - 1) * $perPage;

if (!in_array($type, ['all', ...$validTypes], true)) {
    $type = 'all';
}
if (!isset($validSorts[$sort])) {
    $sort = 'name';
}

$birthCount    = (int) $pdo->query("SELECT COUNT(*) FROM civil_records WHERE record_type = 'birth' AND deleted_at IS NULL")->fetchColumn();
$deathCount    = (int) $pdo->query("SELECT COUNT(*) FROM civil_records WHERE record_type = 'death' AND deleted_at IS NULL")->fetchColumn();
$marriageCount = (int) $pdo->query("SELECT COUNT(*) FROM civil_records WHERE record_type = 'marriage' AND deleted_at IS NULL")->fetchColumn();

[$where, $params] = buildRecordsWhere($filters);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM civil_records cr WHERE $where");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));

$orderCol = $validSorts[$sort];
$sql = "SELECT cr.* FROM civil_records cr WHERE $where ORDER BY $orderCol $dir LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();
$records = array_map(static fn (array $row) => hydrateCivilRecordRow($pdo, $row), $records);

$editRecord = null;
if (isset($_GET['edit'])) {
    $editRecord = fetchFullCivilRecord($pdo, (int) $_GET['edit']);
}

$typeBadgeClass = [
    'birth'    => 'bg-blue-100 text-blue-600',
    'death'    => 'bg-gray-100 text-gray-600',
    'marriage' => 'bg-pink-100 text-pink-600',
];

$showModal = isset($_GET['new']);
$flash = recordsFlashGet();

function recordEntryPrintFillSource(string $type, array $modalRecord): array
{
    if (($modalRecord['record_type'] ?? '') === $type && $modalRecord !== []) {
        return $modalRecord;
    }

    return ['record_type' => $type];
}

function renderRecordEntryPrintFillSection(string $type, array $modalRecord, bool $active, bool $createMode = false): void
{
    $fields = printFillEditorFields($type, recordEntryPrintFillSource($type, $modalRecord));
    $panelId = $type . 'PrintFillPanel';
    ?>
    <div id="<?= htmlspecialchars($panelId) ?>" class="records-entry-print-fill <?= $active ? '' : 'hidden' ?>">
        <div class="records-entry-print-fill__head">
            <div>
                <p class="records-entry-print-fill__title"><?= $createMode ? 'Complete Certificate Fields' : 'Print Certificate Fields' ?></p>
                <p class="records-entry-print-fill__hint"><?= $createMode
                    ? 'Enter every value for this ' . htmlspecialchars(civilRecordTypeLabel($type)) . ' certificate — same fields as the municipal form and CSV import. Required: ' . ($type === 'marriage' ? 'husband and wife names' : 'first and last name') . '.'
                    : 'Additional values for the municipal form (attendant, informant, registrar, LCRO, affidavits, etc.).' ?></p>
            </div>
        </div>
        <div class="records-entry-print-fill__tabs" role="tablist" aria-label="<?= htmlspecialchars(ucfirst($type)) ?> fill-in page">
            <button type="button" class="records-entry-print-fill__tab is-active" data-entry-fill-tab="front" role="tab" aria-selected="true">Front page</button>
            <button type="button" class="records-entry-print-fill__tab" data-entry-fill-tab="back" role="tab" aria-selected="false">Back page</button>
        </div>
        <?php foreach (['front', 'back'] as $fillSide): ?>
        <div class="records-entry-print-fill__grid" data-entry-fill-panel="<?= $fillSide ?>" role="tabpanel"<?= $fillSide === 'back' ? ' hidden' : '' ?>>
            <?php foreach ($fields as $fillField):
                if ($fillField['page_side'] !== $fillSide) {
                    continue;
                }
                $fillGroup = printFillFieldGroup($fillField['field_name']);
            ?>
            <label class="records-entry-print-fill__field"<?= $fillGroup !== '' ? ' data-fill-group="' . htmlspecialchars($fillGroup) . '"' : '' ?>>
                <span><?= htmlspecialchars($fillField['label']) ?></span>
                <input type="text"
                       name="print_fill[<?= htmlspecialchars($fillField['field_name']) ?>]"
                       value="<?= htmlspecialchars($fillField['value']) ?>"
                       autocomplete="off"
                       spellcheck="false">
            </label>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function sortUrl(string $column): string
{
    global $sort, $dir;
    $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    return buildRecordsUrl(['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
}

$pageTitle = 'Civil Records';
$pageSubtitle = 'Manage birth, death, and marriage registry entries with search, export, and import.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Civil Records - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= vendorStylesheetTag('inter/inter.css') ?>
    <?= adminLayoutHeadStyles('records') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen">

    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="admin-main flex flex-col bg-[#fdfdfd]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto w-full admin-page-wrap space-y-6">
            <div class="flex flex-wrap gap-2 sm:gap-3 items-center justify-end">
                    <a href="<?= htmlspecialchars(buildAuthUrl('records.php', array_filter(['action' => 'export', 'type' => $type !== 'all' ? $type : null, 'q' => $search ?: null]))) ?>"
                       title="<?= $type === 'all' ? 'Download all records grouped by Birth, Death, and Marriage' : 'Download ' . civilRecordTypeLabel($type) . ' records (re-importable CSV)' ?>"
                       class="border border-gray-200 text-slate-700 px-4 py-2 rounded-lg text-[11px] font-bold uppercase flex items-center bg-white shadow-sm hover:bg-gray-50">
                        <i data-lucide="download" class="w-4 h-4 mr-2"></i> Export CSV<?= $type !== 'all' ? ' (' . civilRecordTypeLabel($type) . ')' : '' ?>
                    </a>
                    <div class="relative" id="newEntryWrapper">
                        <button type="button" id="newEntryBtn"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-[11px] font-bold uppercase flex items-center shadow-md transition">
                            <span class="mr-2 text-lg leading-none">+</span> New Entry
                        </button>
                        <div id="newEntryMenu" class="hidden absolute right-0 top-full mt-2 w-64 bg-white rounded-xl border border-gray-100 entry-dropdown z-50 py-2">
                            <p class="px-4 py-2 text-[9px] font-bold text-gray-400 uppercase tracking-widest">Manual Entry</p>
                            <?php foreach (['birth' => ['label' => 'Birth', 'icon' => 'baby'], 'death' => ['label' => 'Death', 'icon' => 'activity'], 'marriage' => ['label' => 'Marriage', 'icon' => 'heart']] as $entryType => $entryMeta): ?>
                            <button type="button" data-single-entry-type="<?= $entryType ?>" class="entry-menu-item w-full px-4 py-2.5 text-left text-sm font-bold text-slate-800 flex items-center gap-3">
                                <span class="text-blue-600 text-base leading-none">+</span>
                                <i data-lucide="<?= $entryMeta['icon'] ?>" class="w-4 h-4 text-gray-400"></i>
                                Add <?= $entryMeta['label'] ?> Record
                            </button>
                            <?php endforeach; ?>
                            <div class="my-2 border-t border-gray-100"></div>
                            <p class="px-4 py-2 text-[9px] font-bold text-gray-400 uppercase tracking-widest">Bulk Import (CSV)</p>
                            <?php foreach ($validTypes as $t): ?>
                            <button type="button" data-import-type="<?= $t ?>" class="entry-menu-item w-full px-4 py-2.5 text-left text-sm font-bold text-slate-800 flex items-center gap-3">
                                <i data-lucide="file-text" class="w-4 h-4 text-gray-400"></i> Import <?= civilRecordTypeLabel($t) ?> Records
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                <?php foreach (['birth' => ['count' => $birthCount, 'icon' => 'users', 'bg' => 'bg-blue-50 text-blue-600'], 'death' => ['count' => $deathCount, 'icon' => 'activity', 'bg' => 'bg-gray-50 text-gray-400'], 'marriage' => ['count' => $marriageCount, 'icon' => 'heart', 'bg' => 'bg-pink-50 text-pink-500']] as $key => $meta): ?>
                <a href="<?= buildRecordsUrl(['type' => $key, 'page' => 1]) ?>" class="stat-card bg-white p-4 rounded-lg border border-gray-100 shadow-sm block <?= $type === $key ? 'ring-2 ring-blue-500' : '' ?>">
                    <div class="<?= $meta['bg'] ?> p-1.5 rounded-md w-fit mb-2"><i data-lucide="<?= $meta['icon'] ?>" class="w-4 h-4"></i></div>
                    <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest"><?= civilRecordTypeLabel($key) ?></p>
                    <p class="text-2xl font-black text-slate-900 leading-tight mt-0.5"><?= $meta['count'] ?></p>
                </a>
                <?php endforeach; ?>
            </div>

            <form method="GET" class="admin-toolbar">
                <?php if ($type !== 'all'): ?><input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>"><?php endif; ?>
                <?php if ($sort !== 'name'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
                <?php if ($dir !== 'asc'): ?><input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>"><?php endif; ?>
                <div class="relative flex-1 admin-toolbar-search">
                    <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-gray-400"></i>
                    <input type="text" name="q" id="recordsSearchInput" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, registry, DOM, DOB, parents, place..."
                        class="records-search-input w-full pl-10 pr-4 py-2 text-sm bg-gray-50 border-none rounded-lg focus:ring-0 text-slate-600 placeholder-gray-400">
                </div>
                <div class="admin-toolbar-filters">
                    <?php foreach (['all' => 'All', 'birth' => 'Birth', 'death' => 'Death', 'marriage' => 'Marriage'] as $key => $label): ?>
                    <a href="<?= buildRecordsUrl(['type' => $key, 'page' => 1, 'q' => $search ?: null]) ?>"
                       class="filter-chip whitespace-nowrap shrink-0 <?= $type === $key ? 'bg-white shadow-sm text-blue-600' : 'text-gray-400 hover:text-gray-600' ?>"><?= $label ?></a>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="w-full lg:w-auto bg-blue-600 text-white px-4 py-2 rounded-lg text-xs font-bold shrink-0">Search</button>
            </form>

            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <?php if (empty($records)): ?>
                <div class="p-16 text-center">
                    <div class="bg-gray-50 p-4 rounded-xl w-fit mx-auto mb-4"><i data-lucide="book-open" class="w-10 h-10 text-gray-200"></i></div>
                    <p class="text-sm font-bold text-slate-800 mb-1">No records found</p>
                    <p class="text-gray-400 text-xs">Try adjusting your filters or add a new entry.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                <table class="w-full min-w-[720px]">
                    <thead class="bg-gray-50/50 border-b border-gray-100">
                        <tr>
                            <th class="p-4 text-left table-head"><a href="<?= sortUrl('name') ?>" class="hover:text-blue-600">Record Name <?= $sort === 'name' ? ($dir === 'asc' ? '↑' : '↓') : '' ?></a></th>
                            <th class="p-4 text-left table-head"><a href="<?= sortUrl('type') ?>" class="hover:text-blue-600">Type <?= $sort === 'type' ? ($dir === 'asc' ? '↑' : '↓') : '' ?></a></th>
                            <th class="p-4 text-left table-head"><a href="<?= sortUrl('date') ?>" class="hover:text-blue-600">Key Date <?= $sort === 'date' ? ($dir === 'asc' ? '↑' : '↓') : '' ?></a></th>
                            <th class="p-4 text-left table-head">Details</th>
                            <th class="p-4 text-right table-head">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100" id="recordsTableBody">
                        <?php foreach ($records as $r):
                            $badge = $typeBadgeClass[$r['record_type']] ?? 'bg-gray-100 text-gray-600';
                            $keyDate = $r['record_type'] === 'birth' ? $r['birth_date'] : $r['event_date'];
                            if (!$keyDate) {
                                $keyDate = $r['birth_date'] ?: $r['event_date'];
                            }
                            $parents = array_filter([$r['father_name'] ? 'Father: ' . $r['father_name'] : '', $r['mother_name'] ? 'Mother: ' . $r['mother_name'] : '']);
                            $searchBlob = civilRecordSearchBlob($r);
                        ?>
                        <tr class="hover:bg-gray-50/50 transition-colors records-table-row" data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
                            <td class="p-4">
                                <button type="button" class="view-record-btn text-left w-full" data-record="<?= htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-blue-100 rounded flex items-center justify-center text-blue-600 font-bold text-xs"><?= htmlspecialchars(recordInitial(civilRecordDisplayName($r))) ?></div>
                                        <div>
                                            <div class="flex items-center space-x-2">
                                                <span class="text-sm font-bold text-slate-800 hover:text-blue-600"><?= htmlspecialchars(civilRecordDisplayName($r)) ?></span>
                                                <?php $displayRegistry = civilRecordRegistryNumber($r); ?>
                                                <?php if ($displayRegistry): ?>
                                                <span class="text-[9px] bg-gray-100 px-1.5 py-0.5 rounded text-gray-500 font-bold">#<?= htmlspecialchars($displayRegistry) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-[10px] text-gray-400 font-medium">ID: <?= (int) $r['id'] ?> • Added <?= formatRecordDate(substr($r['created_at'], 0, 10)) ?></p>
                                        </div>
                                    </div>
                                </button>
                            </td>
                            <td class="p-4"><span class="text-[9px] font-black <?= $badge ?> px-2 py-0.5 rounded uppercase"><?= htmlspecialchars($r['record_type']) ?></span></td>
                            <td class="p-4 text-[10px] text-gray-500 font-medium"><?= formatRecordDate($keyDate) ?></td>
                            <td class="p-4 text-[10px] text-gray-400 font-medium max-w-[180px] truncate" title="<?= htmlspecialchars(implode(' • ', $parents) ?: ($r['place'] ?? '')) ?>">
                                <?= htmlspecialchars(implode(' • ', $parents) ?: ($r['place'] ?? '—')) ?>
                            </td>
                            <td class="p-4 text-right">
                                <div class="inline-flex items-center space-x-2">
                                    <button type="button" class="view-record-btn text-gray-300 hover:text-blue-600" title="View" data-record="<?= htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </button>
                                    <a href="<?= htmlspecialchars(buildAuthUrl('print_certificate.php', ['record_id' => (int) $r['id']])) ?>"
                                       class="text-gray-300 hover:text-emerald-600 records-row-print"
                                       title="Print certificate"
                                       aria-label="Print certificate for <?= htmlspecialchars(civilRecordDisplayName($r)) ?>">
                                        <i data-lucide="printer" class="w-4 h-4"></i>
                                    </a>
                                    <a href="<?= buildRecordsUrl(['edit' => $r['id']]) ?>" class="text-gray-300 hover:text-slate-600" title="Edit"><i data-lucide="edit-3" class="w-4 h-4"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="recordsSearchEmpty" class="hidden">
                            <td colspan="5" class="p-10 text-center text-sm text-gray-400 font-medium">No records on this page match your search.</td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>

                <div class="p-4 border-t border-gray-100 flex items-center justify-between">
                    <p class="text-[10px] font-bold text-gray-400 uppercase">Page <?= $page ?> of <?= $totalPages ?> • Total: <?= $totalRecords ?></p>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                        <a href="<?= buildRecordsUrl(['page' => $page - 1]) ?>" class="p-1 border border-gray-200 rounded text-gray-400 hover:bg-gray-50"><i data-lucide="chevron-left" class="w-4 h-4"></i></a>
                        <?php else: ?>
                        <span class="p-1 border border-gray-100 rounded text-gray-200"><i data-lucide="chevron-left" class="w-4 h-4"></i></span>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                        <a href="<?= buildRecordsUrl(['page' => $page + 1]) ?>" class="p-1 border border-gray-200 rounded text-gray-400 hover:bg-gray-50"><i data-lucide="chevron-right" class="w-4 h-4"></i></a>
                        <?php else: ?>
                        <span class="p-1 border border-gray-100 rounded text-gray-200"><i data-lucide="chevron-right" class="w-4 h-4"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php
    $modalRecord = $editRecord ?: [];
    $modalMode = $editRecord ? 'edit' : 'create';
    $entryUseCompletePrintForm = $modalMode === 'create';
    $modalTitle = $editRecord
        ? 'Edit Civil Record'
        : 'Add ' . civilRecordTypeLabel($modalRecord['record_type'] ?? ($type !== 'all' ? $type : 'birth')) . ' Record';
    $submitAction = $editRecord ? 'update' : 'create';
    $defaultRecordType = $modalRecord['record_type'] ?? ($type !== 'all' ? $type : 'birth');
    ?>
    <div class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4" id="entryModal">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full max-w-4xl max-h-[92vh] overflow-hidden flex flex-col">
            <div class="flex justify-between items-center px-4 sm:px-6 py-4 border-b border-gray-100 shrink-0">
                <h2 class="text-lg font-black text-slate-900" id="entryModalTitle"><?= htmlspecialchars($modalTitle) ?></h2>
                <button type="button" class="text-gray-400 hover:text-gray-600 close-modal"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form method="POST" class="flex flex-col flex-1 min-h-0" id="entryForm">
                <?= authFormField() ?>
                <input type="hidden" name="action" id="entryAction" value="<?= $submitAction ?>">
                <?php if ($editRecord): ?><input type="hidden" name="record_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
                <input type="hidden" name="record_type" id="recordTypeInput" value="<?= htmlspecialchars($defaultRecordType) ?>">

                <div class="px-4 sm:px-6 py-4 overflow-y-auto flex-1 space-y-5">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">Record Type</label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="recordTypeTabs">
                            <?php foreach (['birth' => ['label' => 'Birth', 'icon' => 'baby', 'active' => 'border-blue-500 bg-blue-50 text-blue-700'], 'death' => ['label' => 'Death', 'icon' => 'activity', 'active' => 'border-slate-400 bg-slate-50 text-slate-700'], 'marriage' => ['label' => 'Marriage', 'icon' => 'heart', 'active' => 'border-pink-400 bg-pink-50 text-pink-700']] as $t => $meta): ?>
                            <button type="button" data-record-type="<?= $t ?>"
                                class="record-type-tab rounded-xl border-2 px-3 py-3 text-center transition <?= $defaultRecordType === $t ? $meta['active'] : 'border-gray-200 text-gray-500 hover:border-gray-300' ?>">
                                <i data-lucide="<?= $meta['icon'] ?>" class="w-5 h-5 mx-auto mb-1"></i>
                                <span class="text-xs font-bold"><?= $meta['label'] ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="birthFieldsPanel" class="entry-detail-panel space-y-5 <?= $entryUseCompletePrintForm ? 'hidden' : ($defaultRecordType === 'birth' ? '' : 'hidden') ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">First Name *</label>
                                <input type="text" name="first_name" id="birthFirstName" value="<?= htmlspecialchars($modalRecord['first_name'] ?? '') ?>" placeholder="Juan" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Middle Name</label>
                                <input type="text" name="middle_name" id="birthMiddleName" value="<?= htmlspecialchars($modalRecord['middle_name'] ?? '') ?>" placeholder="Dela" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Last Name *</label>
                                <input type="text" name="last_name" id="birthLastName" value="<?= htmlspecialchars($modalRecord['last_name'] ?? '') ?>" placeholder="Cruz" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Registry Number</label>
                                <input type="text" name="registry_number" value="<?= htmlspecialchars($modalRecord['registry_number'] ?? '') ?>" placeholder="e.g. 2024-0001" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Birth</label>
                                <input type="date" name="birth_date" value="<?= htmlspecialchars($modalRecord['birth_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Time of Birth</label>
                                <input type="text" name="birth_time" value="<?= htmlspecialchars($modalRecord['birth_time'] ?? '') ?>" placeholder="e.g. 10:30 AM" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Sex</label>
                                <select name="sex" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    <?php foreach (['Male', 'Female'] as $sex): ?>
                                    <option value="<?= $sex ?>" <?= ($modalRecord['sex'] ?? 'Male') === $sex ? 'selected' : '' ?>><?= $sex ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Birth</label>
                            <input type="text" name="place" value="<?= htmlspecialchars($modalRecord['place'] ?? '') ?>" placeholder="Name of Hospital / Institution; Street / Barangay" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Type of Birth</label>
                                <select name="birth_type" id="birthTypeSelect" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    <?php foreach (['Single', 'Twin', 'Triplet', 'Other'] as $birthType): ?>
                                    <option value="<?= $birthType ?>" <?= ($modalRecord['birth_type'] ?? 'Single') === $birthType ? 'selected' : '' ?>><?= $birthType ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Birth Order</label>
                                <input type="text" name="birth_order" value="<?= htmlspecialchars($modalRecord['birth_order'] ?? '') ?>" placeholder="First" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Weight at Birth</label>
                                <input type="text" name="birth_weight" value="<?= htmlspecialchars($modalRecord['birth_weight'] ?? '') ?>" placeholder="e.g. 3.2 kg" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Registration</label>
                                <input type="date" name="registration_date" value="<?= htmlspecialchars($modalRecord['registration_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>

                        <div id="singleBirthDetails" class="space-y-4">
                            <div class="rounded-xl border border-blue-100 bg-blue-50/40 p-4 space-y-4">
                                <p class="text-[10px] font-black text-blue-700 uppercase tracking-wider flex items-center gap-2">
                                    <i data-lucide="user" class="w-4 h-4"></i> Mother's Information (Her)
                                </p>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Maiden Name (Full)</label>
                                    <input type="text" name="mother_name" value="<?= htmlspecialchars($modalRecord['mother_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Age</label>
                                        <input type="number" name="mother_age" min="0" value="<?= htmlspecialchars((string) ($modalRecord['mother_age'] ?? '')) ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Nationality</label>
                                        <input type="text" name="mother_nationality" value="<?= htmlspecialchars($modalRecord['mother_nationality'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Religion</label>
                                        <input type="text" name="mother_religion" value="<?= htmlspecialchars($modalRecord['mother_religion'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Occupation</label>
                                        <input type="text" name="mother_occupation" value="<?= htmlspecialchars($modalRecord['mother_occupation'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Residence</label>
                                        <input type="text" name="mother_residence" value="<?= htmlspecialchars($modalRecord['mother_residence'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Children Born Alive</label>
                                        <input type="text" name="mother_children_born_alive" value="<?= htmlspecialchars($modalRecord['mother_children_born_alive'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Still Living</label>
                                        <input type="text" name="mother_children_still_living" value="<?= htmlspecialchars($modalRecord['mother_children_still_living'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Born Alive but Now Dead</label>
                                        <input type="text" name="mother_children_born_alive_now_dead" value="<?= htmlspecialchars($modalRecord['mother_children_born_alive_now_dead'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 space-y-4">
                                <p class="text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <i data-lucide="user" class="w-4 h-4"></i> Father's Information
                                </p>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Full Name</label>
                                    <input type="text" name="father_name" value="<?= htmlspecialchars($modalRecord['father_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Age</label>
                                        <input type="number" name="father_age" min="0" value="<?= htmlspecialchars((string) ($modalRecord['father_age'] ?? '')) ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Nationality</label>
                                        <input type="text" name="father_nationality" value="<?= htmlspecialchars($modalRecord['father_nationality'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Religion</label>
                                        <input type="text" name="father_religion" value="<?= htmlspecialchars($modalRecord['father_religion'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Occupation</label>
                                        <input type="text" name="father_occupation" value="<?= htmlspecialchars($modalRecord['father_occupation'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Residence</label>
                                        <input type="text" name="father_residence" value="<?= htmlspecialchars($modalRecord['father_residence'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-xl border border-emerald-100 bg-emerald-50/40 p-4 space-y-4">
                                <p class="text-[10px] font-black text-emerald-700 uppercase tracking-wider">Marriage of Parents</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date</label>
                                        <input type="date" name="parents_marriage_date" value="<?= htmlspecialchars($modalRecord['parents_marriage_date'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place (Municipality, Province)</label>
                                        <input type="text" name="parents_marriage_place" value="<?= htmlspecialchars($modalRecord['parents_marriage_place'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Remarks / Notes</label>
                            <textarea name="notes" rows="2" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm resize-y"><?= htmlspecialchars($modalRecord['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div id="deathFieldsPanel" class="entry-detail-panel space-y-5 <?= $entryUseCompletePrintForm ? 'hidden' : ($defaultRecordType === 'death' ? '' : 'hidden') ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">First Name *</label>
                                <input type="text" name="first_name" id="deathFirstName" value="<?= htmlspecialchars($modalRecord['first_name'] ?? '') ?>" placeholder="Maria" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Middle Name</label>
                                <input type="text" name="middle_name" id="deathMiddleName" value="<?= htmlspecialchars($modalRecord['middle_name'] ?? '') ?>" placeholder="Optional" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Last Name *</label>
                                <input type="text" name="last_name" id="deathLastName" value="<?= htmlspecialchars($modalRecord['last_name'] ?? '') ?>" placeholder="Santos" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Registry Number</label>
                                <input type="text" name="registry_number" value="<?= htmlspecialchars($modalRecord['registry_number'] ?? '') ?>" placeholder="e.g. 2024-0001" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Birth</label>
                                <input type="date" name="birth_date" value="<?= htmlspecialchars($modalRecord['birth_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Registration</label>
                                <input type="date" name="registration_date" value="<?= htmlspecialchars($modalRecord['registration_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Sex</label>
                                <select name="sex" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    <?php foreach (['Male', 'Female'] as $sex): ?>
                                    <option value="<?= $sex ?>" <?= ($modalRecord['sex'] ?? 'Male') === $sex ? 'selected' : '' ?>><?= $sex ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Residence of Deceased</label>
                            <input type="text" name="residence_deceased" value="<?= htmlspecialchars($modalRecord['residence_deceased'] ?? '') ?>" placeholder="Complete Address" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Length of Residence (Place of Death)</label>
                                <input type="text" name="residence_length_place" value="<?= htmlspecialchars($modalRecord['residence_length_place'] ?? '') ?>" placeholder="e.g. 5 years" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Length of Residence (Philippines)</label>
                                <input type="text" name="residence_length_ph" value="<?= htmlspecialchars($modalRecord['residence_length_ph'] ?? '') ?>" placeholder="e.g. Lifetime" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Nationality</label>
                                <input type="text" name="nationality" value="<?= htmlspecialchars($modalRecord['nationality'] ?? 'Filipino') ?>" placeholder="e.g. Filipino" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Civil Status</label>
                                <input type="text" name="civil_status" value="<?= htmlspecialchars($modalRecord['civil_status'] ?? '') ?>" placeholder="e.g. Married, Single" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Religion</label>
                                <input type="text" name="religion" value="<?= htmlspecialchars($modalRecord['religion'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Death</label>
                            <input type="text" name="place" value="<?= htmlspecialchars($modalRecord['place'] ?? '') ?>" placeholder="Hospital / Institution / Address" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 space-y-4">
                            <p class="text-[10px] font-black text-slate-700 uppercase tracking-wider">Age at Death</p>
                            <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 items-end">
                                <?php foreach (['years' => 'Years', 'months' => 'Months', 'days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Min'] as $unit => $label): ?>
                                <div>
                                    <label class="block text-[9px] font-bold text-gray-500 uppercase mb-1"><?= $label ?></label>
                                    <input type="number" min="0" name="age_death_<?= $unit ?>" value="<?= htmlspecialchars((string) ($modalRecord['age_death_' . $unit] ?? '')) ?>" class="w-full bg-white border border-gray-200 rounded-xl px-3 py-2 text-sm">
                                </div>
                                <?php endforeach; ?>
                                <div class="flex items-center pb-2">
                                    <label class="inline-flex items-center gap-2 text-[10px] font-bold text-gray-700 uppercase cursor-pointer">
                                        <input type="checkbox" name="stillbirth" value="1" class="rounded border-gray-300 text-blue-600" <?= !empty($modalRecord['stillbirth']) ? 'checked' : '' ?>>
                                        Still-birth
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Occupation</label>
                                <input type="text" name="occupation" value="<?= htmlspecialchars($modalRecord['occupation'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Burial</label>
                                <input type="text" name="place_of_burial" value="<?= htmlspecialchars($modalRecord['place_of_burial'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Name of Surviving Spouse</label>
                                <input type="text" name="surviving_spouse_name" value="<?= htmlspecialchars($modalRecord['surviving_spouse_name'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Address of Surviving Spouse</label>
                                <input type="text" name="surviving_spouse_address" value="<?= htmlspecialchars($modalRecord['surviving_spouse_address'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 space-y-4">
                            <p class="text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="activity" class="w-4 h-4"></i> Death Details
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Death</label>
                                    <input type="date" name="death_date" value="<?= htmlspecialchars($modalRecord['event_date'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Time of Death</label>
                                    <input type="text" name="death_time" value="<?= htmlspecialchars($modalRecord['death_time'] ?? '') ?>" placeholder="e.g. 10:30" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Period</label>
                                    <select name="death_time_period" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                        <?php foreach (['A.M.', 'P.M.'] as $period): ?>
                                        <option value="<?= $period ?>" <?= ($modalRecord['death_time_period'] ?? 'A.M.') === $period ? 'selected' : '' ?>><?= $period ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Immediate Cause of Death</label>
                                <input type="text" name="immediate_cause" value="<?= htmlspecialchars($modalRecord['immediate_cause'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Contributory Cause</label>
                                <input type="text" name="contributory_cause" value="<?= htmlspecialchars($modalRecord['contributory_cause'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <?php foreach (['a', 'b', 'c', 'd', 'e'] as $letter): ?>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Infant Cause <?= strtoupper($letter) ?></label>
                                <input type="text" name="infant_cause_<?= $letter ?>" value="<?= htmlspecialchars($modalRecord['infant_cause_' . $letter] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <?php endforeach; ?>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Postmortem Cause</label>
                                <input type="text" name="postmortem_cause" value="<?= htmlspecialchars($modalRecord['postmortem_cause'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Attending Physician</label>
                                <input type="text" name="attending_physician" value="<?= htmlspecialchars($modalRecord['attending_physician'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Autopsy Performed?</label>
                                <select name="autopsy_performed" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    <?php foreach (['No', 'Yes'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= ($modalRecord['autopsy_performed'] ?? 'No') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Code Number (System Only)</label>
                                <input type="text" name="code_number" value="<?= htmlspecialchars($modalRecord['code_number'] ?? $modalRecord['registry_number'] ?? '') ?>" readonly class="w-full bg-gray-100 border border-gray-200 rounded-xl px-4 py-2.5 text-sm text-gray-500">
                            </div>
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 space-y-4">
                            <p class="text-[10px] font-black text-slate-700 uppercase tracking-wider">Parents of Deceased</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Father's Name</label>
                                    <input type="text" name="father_name" value="<?= htmlspecialchars($modalRecord['father_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Mother's Name</label>
                                    <input type="text" name="mother_name" value="<?= htmlspecialchars($modalRecord['mother_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-violet-100 bg-violet-50/40 p-4 space-y-4">
                            <p class="text-[10px] font-black text-violet-800 uppercase tracking-wider">Infant Details (0–7 Days)</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Age of Mother</label>
                                    <input type="text" name="child_age_mother" value="<?= htmlspecialchars($modalRecord['child_age_mother'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Method of Delivery</label>
                                    <input type="text" name="child_delivery_method" value="<?= htmlspecialchars($modalRecord['child_delivery_method'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Length of Pregnancy</label>
                                    <input type="text" name="child_pregnancy_length" value="<?= htmlspecialchars($modalRecord['child_pregnancy_length'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Type of Birth</label>
                                    <input type="text" name="child_birth_type" value="<?= htmlspecialchars($modalRecord['child_birth_type'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Birth Order (Infant)</label>
                                    <input type="text" name="child_birth_order_infant" value="<?= htmlspecialchars($modalRecord['child_birth_order_infant'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Remarks / Notes</label>
                            <textarea name="notes" rows="2" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm resize-y"><?= htmlspecialchars($modalRecord['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div id="marriageFieldsPanel" class="entry-detail-panel space-y-5 <?= $entryUseCompletePrintForm ? 'hidden' : ($defaultRecordType === 'marriage' ? '' : 'hidden') ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Registry Number</label>
                                <input type="text" name="registry_number" value="<?= htmlspecialchars($modalRecord['registry_number'] ?? '') ?>" placeholder="e.g. 2024-0001" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Birth</label>
                                <input type="date" name="birth_date" value="<?= htmlspecialchars($modalRecord['birth_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>

                        <?php
                        $spouseSections = [
                            'husband' => ['label' => "Husband's Information", 'icon' => 'user', 'theme' => 'blue'],
                            'wife'    => ['label' => "Wife's Information", 'icon' => 'user', 'theme' => 'pink'],
                        ];
                        foreach ($spouseSections as $prefix => $section):
                            $themeClasses = $prefix === 'husband'
                                ? 'border-blue-200 bg-blue-50/50 text-blue-800'
                                : 'border-pink-200 bg-pink-50/50 text-pink-800';
                        ?>
                        <div class="rounded-xl border p-4 space-y-4 <?= $themeClasses ?>">
                            <p class="text-[10px] font-black uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="<?= $section['icon'] ?>" class="w-4 h-4"></i> <?= $section['label'] ?>
                            </p>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Full Name</label>
                                <input type="text" name="<?= $prefix ?>_name" value="<?= htmlspecialchars($modalRecord[$prefix . '_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Birth</label>
                                    <input type="date" name="<?= $prefix ?>_birth_date" value="<?= htmlspecialchars($modalRecord[$prefix . '_birth_date'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Age</label>
                                    <input type="number" min="0" name="<?= $prefix ?>_age" value="<?= htmlspecialchars((string) ($modalRecord[$prefix . '_age'] ?? '')) ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Birth</label>
                                    <input type="text" name="<?= $prefix ?>_birth_place" value="<?= htmlspecialchars($modalRecord[$prefix . '_birth_place'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Citizenship</label>
                                    <input type="text" name="<?= $prefix ?>_citizenship" value="<?= htmlspecialchars($modalRecord[$prefix . '_citizenship'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Religion</label>
                                    <input type="text" name="<?= $prefix ?>_religion" value="<?= htmlspecialchars($modalRecord[$prefix . '_religion'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Civil Status</label>
                                    <input type="text" name="<?= $prefix ?>_civil_status" value="<?= htmlspecialchars($modalRecord[$prefix . '_civil_status'] ?? 'Single') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Residence</label>
                                    <input type="text" name="<?= $prefix ?>_residence" value="<?= htmlspecialchars($modalRecord[$prefix . '_residence'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Father's Full Name</label>
                                    <input type="text" name="<?= $prefix ?>_father_name" value="<?= htmlspecialchars($modalRecord[$prefix . '_father_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Mother's Maiden Name</label>
                                    <input type="text" name="<?= $prefix ?>_mother_maiden_name" value="<?= htmlspecialchars($modalRecord[$prefix . '_mother_maiden_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Father Citizenship</label>
                                    <input type="text" name="<?= $prefix ?>_father_citizenship" value="<?= htmlspecialchars($modalRecord[$prefix . '_father_citizenship'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Mother Citizenship</label>
                                    <input type="text" name="<?= $prefix ?>_mother_citizenship" value="<?= htmlspecialchars($modalRecord[$prefix . '_mother_citizenship'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Consent Person Name</label>
                                    <input type="text" name="<?= $prefix ?>_consent_person_name" value="<?= htmlspecialchars($modalRecord[$prefix . '_consent_person_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Consent Relationship</label>
                                    <input type="text" name="<?= $prefix ?>_consent_relationship" value="<?= htmlspecialchars($modalRecord[$prefix . '_consent_relationship'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Consent Residence</label>
                                    <input type="text" name="<?= $prefix ?>_consent_residence" value="<?= htmlspecialchars($modalRecord[$prefix . '_consent_residence'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div class="rounded-xl border border-rose-200 bg-rose-50/40 p-4 space-y-4">
                            <p class="text-[10px] font-black text-rose-800 uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="heart" class="w-4 h-4"></i> Marriage Ceremony Details
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Marriage</label>
                                    <input type="date" name="marriage_date" value="<?= htmlspecialchars($modalRecord['event_date'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Time of Marriage</label>
                                    <input type="text" name="marriage_time" value="<?= htmlspecialchars($modalRecord['marriage_time'] ?? '') ?>" placeholder="e.g. 09:00 AM" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Marriage</label>
                                    <input type="text" name="marriage_place" value="<?= htmlspecialchars($modalRecord['place'] ?? '') ?>" placeholder="Church / Office / Barangay" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Solemnized By (Name)</label>
                                    <input type="text" name="solemnized_by" value="<?= htmlspecialchars($modalRecord['solemnized_by'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Witnesses</label>
                            <textarea name="witnesses" rows="3" placeholder="List of witnesses (Name, Residence)" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm resize-y"><?= htmlspecialchars($modalRecord['witnesses'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Remarks / Notes</label>
                            <textarea name="notes" rows="2" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm resize-y"><?= htmlspecialchars($modalRecord['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <?php foreach ($validTypes as $fillType): ?>
                        <?php renderRecordEntryPrintFillSection($fillType, $modalRecord, $defaultRecordType === $fillType, $entryUseCompletePrintForm); ?>
                    <?php endforeach; ?>
                </div>

                <div class="px-4 sm:px-6 py-4 border-t border-gray-100 flex flex-col-reverse sm:flex-row gap-3 shrink-0 bg-white">
                    <button type="button" class="border border-gray-200 rounded-xl py-3 px-4 text-sm font-bold text-gray-600 hover:bg-gray-50 close-modal sm:flex-1">Cancel</button>
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 text-sm font-bold inline-flex items-center justify-center gap-2">
                        <i data-lucide="save" class="w-4 h-4"></i> <?= $editRecord ? 'Update Record' : 'Add Record' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="viewModal" class="records-view-modal hidden" aria-hidden="true">
        <div class="records-view-modal__backdrop close-modal" aria-hidden="true"></div>
        <div class="records-view-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
            <div class="records-view-modal__header">
                <div class="records-view-modal__heading">
                    <div class="records-view-modal__title-row">
                        <h2 id="viewModalTitle" class="records-detail-title">Record Details</h2>
                        <span id="viewModalBadge" class="records-type-badge"></span>
                    </div>
                    <p id="viewModalSubtitle" class="records-detail-meta"></p>
                </div>
                <button type="button" class="records-view-modal__close close-modal" aria-label="Close record details">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div id="viewContent" class="records-view-modal__body"></div>
            <div class="records-view-modal__actions">
                <a href="#" id="viewEditLink" class="records-view-action records-view-action--primary">Edit Record</a>
                <a href="#" id="viewPrintLink" class="records-view-action records-view-action--print">Print Certificate</a>
                <button type="button" class="records-view-action close-modal">Close</button>
            </div>
        </div>
    </div>

    <div class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4" id="importModal">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-lg font-black text-slate-900" id="importModalTitle">Import Records</h2>
                <button type="button" class="text-gray-400 hover:text-gray-600 close-modal"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="space-y-4" id="importForm" action="<?= htmlspecialchars(buildAuthUrl('records.php')) ?>">
                <?= authFormField() ?>
                <input type="hidden" name="action" value="import_csv">
                <input type="hidden" name="import_type" id="importType" value="">
                <p class="text-xs text-gray-500" id="importColumnsHelp">Download the matching CSV template below. Enter each value in its own column (do not paste an entire row into cell A). Template sample rows are skipped automatically. Use dates as <strong>YYYY-MM-DD</strong> or <strong>MM/DD/YYYY</strong>. In Excel, use <strong>Save As → CSV UTF-8 (Comma delimited)</strong>. Required: <strong>first_name</strong> and <strong>last_name</strong> (birth/death), or <strong>husband_name</strong> and <strong>wife_name</strong> (marriage).</p>
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-1">CSV File *</label>
                    <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-600 file:font-bold file:text-xs">
                </div>
                <a href="#" id="importTemplateLink" download class="text-blue-600 text-[10px] font-bold flex items-center hover:underline">
                    <i data-lucide="download" class="w-3.5 h-3.5 mr-2"></i> Download CSV Template
                </a>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 text-sm font-bold">Import Records</button>
                    <button type="button" class="flex-1 border border-gray-200 rounded-xl py-3 text-sm font-bold text-gray-600 hover:bg-gray-50 close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <?= actionResultScript($flash) ?>
    <?= pageConfigJson([
        'csvTemplateColumns' => [
            'birth' => civilRecordCsvColumns('birth'),
            'death' => civilRecordCsvColumns('death'),
            'marriage' => civilRecordCsvColumns('marriage'),
        ],
        'recordsAuthUrl' => buildAuthUrl('records.php'),
        'printCertificateUrl' => buildAuthUrl('print_certificate.php'),
        'recordViewSections' => [
            'birth' => civilRecordViewSections('birth'),
            'death' => civilRecordViewSections('death'),
            'marriage' => civilRecordViewSections('marriage'),
        ],
        'printFillFieldLabels' => [
            'birth' => civilRecordPrintFillFieldLabels('birth'),
            'death' => civilRecordPrintFillFieldLabels('death'),
            'marriage' => civilRecordPrintFillFieldLabels('marriage'),
        ],
        'openEntryModal' => (bool) ($showModal || $editRecord),
        'defaultEntryType' => $defaultRecordType,
        'entryUseCompletePrintForm' => $entryUseCompletePrintForm,
    ], 'records-config') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('admin/records.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
