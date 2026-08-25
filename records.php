<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
requireStaffLogin();
requirePageAccess('records.php');

$activePage = 'records.php';
$pdo = getDB();

try {
    $pdo->query('SELECT deleted_at FROM civil_records LIMIT 1');
} catch (PDOException $e) {
    $pdo->exec('ALTER TABLE civil_records ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER notes');
}

function ensureCivilRecordExtendedColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $columns = [
        'sex'                     => 'VARCHAR(10) NULL',
        'birth_time'              => 'VARCHAR(20) NULL',
        'birth_type'              => "VARCHAR(20) NULL DEFAULT 'Single'",
        'birth_order'             => 'VARCHAR(50) NULL',
        'mother_age'              => 'INT NULL',
        'mother_nationality'      => 'VARCHAR(100) NULL',
        'mother_religion'         => 'VARCHAR(100) NULL',
        'father_age'              => 'INT NULL',
        'father_nationality'      => 'VARCHAR(100) NULL',
        'father_religion'         => 'VARCHAR(100) NULL',
        'parents_marriage_date'   => 'DATE NULL',
        'parents_marriage_place'  => 'VARCHAR(255) NULL',
        'registration_date'       => 'DATE NULL',
        'residence_deceased'      => 'VARCHAR(255) NULL',
        'residence_length_place'  => 'VARCHAR(100) NULL',
        'residence_length_ph'     => 'VARCHAR(100) NULL',
        'nationality'             => 'VARCHAR(100) NULL',
        'civil_status'            => 'VARCHAR(50) NULL',
        'age_death_years'         => 'INT NULL',
        'age_death_months'        => 'INT NULL',
        'age_death_days'          => 'INT NULL',
        'age_death_hours'         => 'INT NULL',
        'age_death_minutes'       => 'INT NULL',
        'stillbirth'              => 'TINYINT(1) NOT NULL DEFAULT 0',
        'occupation'              => 'VARCHAR(150) NULL',
        'surviving_spouse_name'   => 'VARCHAR(150) NULL',
        'surviving_spouse_address'=> 'VARCHAR(255) NULL',
        'place_of_burial'         => 'VARCHAR(255) NULL',
        'death_time'              => 'VARCHAR(20) NULL',
        'death_time_period'       => 'VARCHAR(10) NULL',
        'immediate_cause'         => 'VARCHAR(255) NULL',
        'contributory_cause'      => 'VARCHAR(255) NULL',
        'attending_physician'     => 'VARCHAR(150) NULL',
        'autopsy_performed'       => 'VARCHAR(10) NULL',
        'code_number'             => 'VARCHAR(50) NULL',
        'husband_name'              => 'VARCHAR(150) NULL',
        'husband_birth_date'        => 'DATE NULL',
        'husband_age'               => 'INT NULL',
        'husband_birth_place'       => 'VARCHAR(255) NULL',
        'husband_citizenship'       => 'VARCHAR(100) NULL',
        'husband_religion'          => 'VARCHAR(100) NULL',
        'husband_civil_status'      => 'VARCHAR(50) NULL',
        'husband_residence'         => 'VARCHAR(255) NULL',
        'husband_father_name'       => 'VARCHAR(150) NULL',
        'husband_mother_maiden_name'=> 'VARCHAR(150) NULL',
        'wife_name'                 => 'VARCHAR(150) NULL',
        'wife_birth_date'           => 'DATE NULL',
        'wife_age'                  => 'INT NULL',
        'wife_birth_place'          => 'VARCHAR(255) NULL',
        'wife_citizenship'          => 'VARCHAR(100) NULL',
        'wife_religion'             => 'VARCHAR(100) NULL',
        'wife_civil_status'         => 'VARCHAR(50) NULL',
        'wife_residence'            => 'VARCHAR(255) NULL',
        'wife_father_name'          => 'VARCHAR(150) NULL',
        'wife_mother_maiden_name'   => 'VARCHAR(150) NULL',
        'marriage_time'             => 'VARCHAR(20) NULL',
        'solemnized_by'             => 'VARCHAR(150) NULL',
        'witnesses'                 => 'TEXT NULL',
    ];
    foreach ($columns as $column => $definition) {
        try {
            $pdo->query("SELECT `$column` FROM civil_records LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE civil_records ADD COLUMN `$column` $definition");
        }
    }
    $done = true;
}

ensureCivilRecordExtendedColumns($pdo);

// Keep birth key date available as event_date for list/detail views.
$pdo->exec(
    "UPDATE civil_records SET event_date = birth_date
     WHERE record_type = 'birth' AND birth_date IS NOT NULL
     AND (event_date IS NULL OR event_date = '')"
);

// Death imports often land in code_number — mirror into registry_number for display/search.
$pdo->exec(
    "UPDATE civil_records SET registry_number = code_number
     WHERE (registry_number IS NULL OR registry_number = '')
     AND code_number IS NOT NULL AND code_number != ''"
);

$validTypes = ['birth', 'death', 'marriage'];
$validSorts = ['name' => 'person_name', 'type' => 'record_type', 'date' => 'COALESCE(event_date, birth_date)', 'created' => 'created_at'];

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
    $where  = 'deleted_at IS NULL';
    $params = [];

    if ($filters['type'] !== 'all' && in_array($filters['type'], ['birth', 'death', 'marriage'], true)) {
        $where .= ' AND record_type = ?';
        $params[] = $filters['type'];
    }
    if ($filters['q'] !== '') {
        $where .= ' AND (person_name LIKE ? OR registry_number LIKE ? OR father_name LIKE ? OR mother_name LIKE ? OR place LIKE ? OR notes LIKE ? OR husband_name LIKE ? OR wife_name LIKE ? OR CAST(id AS CHAR) LIKE ?)';
        $term = '%' . $filters['q'] . '%';
        array_push($params, $term, $term, $term, $term, $term, $term, $term, $term, $term);
    }

    return [$where, $params];
}

function recordInitial(string $name): string
{
    return strtoupper(substr(trim($name), 0, 1));
}

function civilRecordExtendedFieldNames(): array
{
    return [
        'sex', 'birth_time', 'birth_type', 'birth_order',
        'mother_age', 'mother_nationality', 'mother_religion',
        'father_age', 'father_nationality', 'father_religion',
        'parents_marriage_date', 'parents_marriage_place',
        'registration_date', 'residence_deceased', 'residence_length_place', 'residence_length_ph',
        'nationality', 'civil_status',
        'age_death_years', 'age_death_months', 'age_death_days', 'age_death_hours', 'age_death_minutes',
        'stillbirth', 'occupation', 'surviving_spouse_name', 'surviving_spouse_address', 'place_of_burial',
        'death_time', 'death_time_period', 'immediate_cause', 'contributory_cause',
        'attending_physician', 'autopsy_performed', 'code_number',
        'husband_name', 'husband_birth_date', 'husband_age', 'husband_birth_place',
        'husband_citizenship', 'husband_religion', 'husband_civil_status', 'husband_residence',
        'husband_father_name', 'husband_mother_maiden_name',
        'wife_name', 'wife_birth_date', 'wife_age', 'wife_birth_place',
        'wife_citizenship', 'wife_religion', 'wife_civil_status', 'wife_residence',
        'wife_father_name', 'wife_mother_maiden_name',
        'marriage_time', 'solemnized_by', 'witnesses',
    ];
}

function civilRecordExtendedDefaults(): array
{
    $defaults = [];
    foreach (civilRecordExtendedFieldNames() as $field) {
        $defaults[$field] = $field === 'stillbirth' ? 0 : null;
    }
    return $defaults;
}

function civilRecordOptionalInt($value): ?int
{
    return ($value ?? '') !== '' ? (int) $value : null;
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
        'person_name', 'registry_number', 'birth_date', 'death_date', 'marriage_date',
        'husband_name', 'wife_name', 'record_type',
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
        return applyCsvDateNormalization(applyCsvTypeAliases($input, $input['record_type']));
    }

    $columns = civilRecordCsvColumns($importType);
    foreach ($columns as $i => $column) {
        $input[$column] = trim((string) ($row[$i] ?? ''));
    }

    return applyCsvDateNormalization(applyCsvTypeAliases($input, $importType));
}

function civilRecordCsvColumns(string $type): array
{
    if ($type === 'birth') {
        return [
            'registry_number', 'person_name', 'birth_date', 'sex', 'birth_time', 'place',
            'birth_type', 'birth_order',
            'mother_name', 'mother_age', 'mother_nationality', 'mother_religion',
            'father_name', 'father_age', 'father_nationality', 'father_religion',
            'parents_marriage_date', 'parents_marriage_place',
        ];
    }
    if ($type === 'death') {
        return [
            'registry_number', 'person_name', 'birth_date', 'registration_date', 'sex',
            'residence_deceased', 'residence_length_place', 'residence_length_ph',
            'nationality', 'civil_status',
            'age_death_years', 'age_death_months', 'age_death_days', 'age_death_hours', 'age_death_minutes',
            'stillbirth', 'occupation', 'surviving_spouse_name', 'surviving_spouse_address', 'place_of_burial',
            'death_date', 'death_time', 'death_time_period', 'immediate_cause', 'contributory_cause',
            'attending_physician', 'autopsy_performed', 'code_number',
        ];
    }
    if ($type === 'marriage') {
        return [
            'registry_number', 'person_name', 'birth_date',
            'husband_name', 'husband_birth_date', 'husband_age', 'husband_birth_place',
            'husband_citizenship', 'husband_religion', 'husband_civil_status', 'husband_residence',
            'husband_father_name', 'husband_mother_maiden_name',
            'wife_name', 'wife_birth_date', 'wife_age', 'wife_birth_place',
            'wife_citizenship', 'wife_religion', 'wife_civil_status', 'wife_residence',
            'wife_father_name', 'wife_mother_maiden_name',
            'marriage_date', 'marriage_time', 'marriage_place', 'solemnized_by', 'witnesses',
        ];
    }

    return ['registry_number', 'person_name', 'birth_date'];
}

function civilRecordCsvSampleRow(string $type): array
{
    if ($type === 'birth') {
        return [
            '2024-0001', 'Juan Dela Cruz', '2024-01-15', 'Male', '10:30 AM',
            'Aloran Municipal Hospital, Poblacion', 'Single', 'First',
            'Maria Santos', '28', 'Filipino', 'Catholic',
            'Pedro Dela Cruz', '32', 'Filipino', 'Catholic',
            '2018-06-12', 'Aloran, Misamis Occidental',
        ];
    }
    if ($type === 'death') {
        return [
            '2024-D001', 'Maria Santos', '1950-03-10', '2024-02-05', 'Female',
            '123 Poblacion, Aloran', '5 years', 'Lifetime', 'Filipino', 'Married',
            '74', '0', '0', '0', '0', '0', 'Retired', 'Pedro Santos', '123 Poblacion, Aloran', 'Aloran Cemetery',
            '2024-02-01', '10:30', 'A.M.', 'Cardiac arrest', 'Hypertension',
            'Dr. Juan Reyes', 'No', '2024-D001',
        ];
    }
    if ($type === 'marriage') {
        return [
            '2024-M001', 'Juan Dela Cruz & Maria Santos', '1990-01-01',
            'Juan Dela Cruz', '1990-01-01', '34', 'Aloran', 'Filipino', 'Catholic', 'Single', 'Poblacion, Aloran',
            'Pedro Dela Cruz', 'Ana Reyes',
            'Maria Santos', '1992-05-20', '32', 'Ozamiz City', 'Filipino', 'Catholic', 'Single', 'Poblacion, Aloran',
            'Jose Santos', 'Rosa Garcia',
            '2024-03-15', '09:00 AM', 'Municipal Hall, Aloran', 'Hon. Municipal Mayor', 'Pedro Reyes (Poblacion) / Rosa Lim (Lower)',
        ];
    }

    return ['2024-0001', 'Juan Dela Cruz', '2024-01-15'];
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
        'name_of_deceased', 'deceased_name', 'full_name', 'name' => 'person_name',
        'place_of_marriage' => 'marriage_place',
        'time_of_marriage' => 'marriage_time',
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
    $contents = file_get_contents($filePath);
    if ($contents === false) {
        throw new InvalidArgumentException('Could not read the uploaded CSV file.');
    }

    $tempPath = null;
    if (str_starts_with($contents, "\xFF\xFE")) {
        $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        $tempPath = tempnam(sys_get_temp_dir(), 'alcros_csv_');
        file_put_contents($tempPath, $contents);
        $filePath = $tempPath;
    } elseif (str_starts_with($contents, "\xFE\xFF")) {
        $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        $tempPath = tempnam(sys_get_temp_dir(), 'alcros_csv_');
        file_put_contents($tempPath, $contents);
        $filePath = $tempPath;
    }

    return [$filePath, $tempPath];
}

function csvUploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'CSV file is too large. Use a smaller file or increase upload_max_filesize in php.ini.',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Please choose a CSV file to import.',
        default => 'File upload failed (error code ' . $code . '). Please try again.',
    };
}

function csvRowMatchesSampleRow(array $headers, array $row, string $importType): bool
{
    $columns = civilRecordCsvColumns($importType);
    $sample = civilRecordCsvSampleRow($importType);
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

    if (trim($input['person_name'] ?? '') === '' && $effectiveType === 'marriage') {
        $husband = trim($input['husband_name'] ?? '');
        $wife = trim($input['wife_name'] ?? '');
        if ($husband !== '' && $wife !== '') {
            $input['person_name'] = $husband . ' & ' . $wife;
        }
    }

    if (trim($input['person_name'] ?? '') === '') {
        if (csvRowLooksMergedIntoOneCell($row)) {
            $error = 'This row is in one Excel column. Open the CSV template, paste each value in its own column (A, B, C…), then Save As → CSV UTF-8.';
        } else {
            $error = 'person_name is required (or husband_name and wife_name for marriage).';
        }
        return null;
    }

    try {
        return normalizeRecordInput($input);
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

    try {
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
                        $errors[] = 'Row 1: ' . ($rowError ?: 'invalid data.');
                    } else {
                        try {
                            insertCivilRecord($pdo, $parsed);
                            $imported++;
                        } catch (PDOException) {
                            $skipped++;
                            $errors[] = 'Row 1: could not save record.';
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
                $errors[] = "Row $lineNum: " . ($rowError ?: 'invalid data.');
                continue;
            }

            try {
                insertCivilRecord($pdo, $parsed);
                $imported++;
            } catch (PDOException) {
                $skipped++;
                $errors[] = "Row $lineNum: could not save \"{$parsed['person_name']}\".";
            }
        }
    } finally {
        fclose($handle);
        if ($tempPath !== null) {
            @unlink($tempPath);
        }
    }

    return compact('imported', 'skipped', 'errors') + ['sample_skipped' => $sampleSkipped];
}

function insertCivilRecord(PDO $pdo, array $data): void
{
    $cols = civilRecordInsertColumns();
    $stmt = $pdo->prepare(
        'INSERT INTO civil_records (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
    );
    $stmt->execute(array_map(static fn ($col) => $data[$col], $cols));
}

function civilRecordExportCellValue(array $row, string $type, string $column): ?string
{
    if ($column === 'registry_number') {
        $registry = civilRecordRegistryNumber($row);
        return $registry ?? null;
    }
    if ($type === 'death' && $column === 'death_date') {
        $value = $row['event_date'] ?? null;
        return ($value === null || $value === '') ? null : (string) $value;
    }
    if ($type === 'marriage' && $column === 'marriage_date') {
        $value = $row['event_date'] ?? null;
        return ($value === null || $value === '') ? null : (string) $value;
    }
    if ($type === 'marriage' && $column === 'marriage_place') {
        $value = $row['place'] ?? null;
        return ($value === null || $value === '') ? null : (string) $value;
    }
    if ($column === 'stillbirth') {
        return !empty($row['stillbirth']) ? '1' : '0';
    }

    $value = $row[$column] ?? null;
    if ($value === null || $value === '') {
        return null;
    }

    return (string) $value;
}

function civilRecordExportRowValues(array $row, string $type): array
{
    return array_map(
        static fn (string $column) => civilRecordExportCellValue($row, $type, $column),
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

    $stmt = $pdo->prepare("SELECT * FROM civil_records WHERE $where ORDER BY record_type ASC, person_name ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

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

function civilRecordExportColumns(): array
{
    return civilRecordInsertColumns();
}

function normalizeRecordInput(array $input): array
{
    global $validTypes;
    $input = applyCsvDateNormalization($input);
    $type = $input['record_type'] ?? '';
    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException('Invalid record type.');
    }
    $name = trim($input['person_name'] ?? '');
    if ($type === 'marriage') {
        $husband = trim($input['husband_name'] ?? '');
        $wife = trim($input['wife_name'] ?? '');
        if ($name === '' && $husband !== '' && $wife !== '') {
            $name = $husband . ' & ' . $wife;
        }
    }
    if ($name === '') {
        throw new InvalidArgumentException('Person name is required.');
    }

    $data = array_merge([
        'record_type'     => $type,
        'registry_number' => trim($input['registry_number'] ?? '') ?: null,
        'person_name'     => $name,
        'birth_date'      => trim($input['birth_date'] ?? '') ?: null,
        'event_date'      => trim($input['event_date'] ?? '') ?: null,
        'place'           => trim($input['place'] ?? '') ?: null,
        'father_name'     => trim($input['father_name'] ?? '') ?: null,
        'mother_name'     => trim($input['mother_name'] ?? '') ?: null,
        'notes'           => null,
    ], civilRecordExtendedDefaults());

    if ($type === 'birth') {
        $data['sex'] = trim($input['sex'] ?? '') ?: null;
        $data['birth_time'] = trim($input['birth_time'] ?? '') ?: null;
        $data['birth_type'] = trim($input['birth_type'] ?? '') ?: 'Single';
        $data['birth_order'] = trim($input['birth_order'] ?? '') ?: null;
        $data['mother_age'] = civilRecordOptionalInt($input['mother_age'] ?? null);
        $data['mother_nationality'] = trim($input['mother_nationality'] ?? '') ?: null;
        $data['mother_religion'] = trim($input['mother_religion'] ?? '') ?: null;
        $data['father_age'] = civilRecordOptionalInt($input['father_age'] ?? null);
        $data['father_nationality'] = trim($input['father_nationality'] ?? '') ?: null;
        $data['father_religion'] = trim($input['father_religion'] ?? '') ?: null;
        $data['parents_marriage_date'] = trim($input['parents_marriage_date'] ?? '') ?: null;
        $data['parents_marriage_place'] = trim($input['parents_marriage_place'] ?? '') ?: null;
        $data['event_date'] = $data['birth_date'];
    }

    if ($type === 'death') {
        $data['sex'] = trim($input['sex'] ?? '') ?: null;
        $data['registration_date'] = trim($input['registration_date'] ?? '') ?: null;
        $data['event_date'] = trim($input['death_date'] ?? $input['event_date'] ?? '') ?: null;
        $data['residence_deceased'] = trim($input['residence_deceased'] ?? '') ?: null;
        $data['residence_length_place'] = trim($input['residence_length_place'] ?? '') ?: null;
        $data['residence_length_ph'] = trim($input['residence_length_ph'] ?? '') ?: null;
        $data['nationality'] = trim($input['nationality'] ?? '') ?: null;
        $data['civil_status'] = trim($input['civil_status'] ?? '') ?: null;
        $data['age_death_years'] = civilRecordOptionalInt($input['age_death_years'] ?? null);
        $data['age_death_months'] = civilRecordOptionalInt($input['age_death_months'] ?? null);
        $data['age_death_days'] = civilRecordOptionalInt($input['age_death_days'] ?? null);
        $data['age_death_hours'] = civilRecordOptionalInt($input['age_death_hours'] ?? null);
        $data['age_death_minutes'] = civilRecordOptionalInt($input['age_death_minutes'] ?? null);
        $data['stillbirth'] = in_array(strtolower(trim((string) ($input['stillbirth'] ?? ''))), ['1', 'yes', 'true'], true) ? 1 : 0;
        $data['occupation'] = trim($input['occupation'] ?? '') ?: null;
        $data['surviving_spouse_name'] = trim($input['surviving_spouse_name'] ?? '') ?: null;
        $data['surviving_spouse_address'] = trim($input['surviving_spouse_address'] ?? '') ?: null;
        $data['place_of_burial'] = trim($input['place_of_burial'] ?? '') ?: null;
        $data['death_time'] = trim($input['death_time'] ?? '') ?: null;
        $data['death_time_period'] = trim($input['death_time_period'] ?? '') ?: null;
        $data['immediate_cause'] = trim($input['immediate_cause'] ?? '') ?: null;
        $data['contributory_cause'] = trim($input['contributory_cause'] ?? '') ?: null;
        $data['attending_physician'] = trim($input['attending_physician'] ?? '') ?: null;
        $data['autopsy_performed'] = trim($input['autopsy_performed'] ?? '') ?: null;
        $data['code_number'] = trim($input['code_number'] ?? '') ?: ($data['registry_number'] ?: null);
    }

    if ($type === 'marriage') {
        $data['husband_name'] = trim($input['husband_name'] ?? '') ?: null;
        $data['husband_birth_date'] = trim($input['husband_birth_date'] ?? '') ?: null;
        $data['husband_age'] = civilRecordOptionalInt($input['husband_age'] ?? null);
        $data['husband_birth_place'] = trim($input['husband_birth_place'] ?? '') ?: null;
        $data['husband_citizenship'] = trim($input['husband_citizenship'] ?? '') ?: null;
        $data['husband_religion'] = trim($input['husband_religion'] ?? '') ?: null;
        $data['husband_civil_status'] = trim($input['husband_civil_status'] ?? '') ?: null;
        $data['husband_residence'] = trim($input['husband_residence'] ?? '') ?: null;
        $data['husband_father_name'] = trim($input['husband_father_name'] ?? '') ?: null;
        $data['husband_mother_maiden_name'] = trim($input['husband_mother_maiden_name'] ?? '') ?: null;
        $data['wife_name'] = trim($input['wife_name'] ?? '') ?: null;
        $data['wife_birth_date'] = trim($input['wife_birth_date'] ?? '') ?: null;
        $data['wife_age'] = civilRecordOptionalInt($input['wife_age'] ?? null);
        $data['wife_birth_place'] = trim($input['wife_birth_place'] ?? '') ?: null;
        $data['wife_citizenship'] = trim($input['wife_citizenship'] ?? '') ?: null;
        $data['wife_religion'] = trim($input['wife_religion'] ?? '') ?: null;
        $data['wife_civil_status'] = trim($input['wife_civil_status'] ?? '') ?: null;
        $data['wife_residence'] = trim($input['wife_residence'] ?? '') ?: null;
        $data['wife_father_name'] = trim($input['wife_father_name'] ?? '') ?: null;
        $data['wife_mother_maiden_name'] = trim($input['wife_mother_maiden_name'] ?? '') ?: null;
        $data['event_date'] = trim($input['marriage_date'] ?? $input['event_date'] ?? '') ?: null;
        $data['place'] = trim($input['marriage_place'] ?? $input['place'] ?? '') ?: null;
        $data['marriage_time'] = trim($input['marriage_time'] ?? '') ?: null;
        $data['solemnized_by'] = trim($input['solemnized_by'] ?? '') ?: null;
        $data['witnesses'] = trim($input['witnesses'] ?? '') ?: null;
    }

    if (trim((string) ($data['registry_number'] ?? '')) === '') {
        $fallbackRegistry = trim((string) ($data['code_number'] ?? ''));
        if ($fallbackRegistry !== '') {
            $data['registry_number'] = $fallbackRegistry;
        }
    }

    return $data;
}

function civilRecordInsertColumns(): array
{
    return array_merge(
        ['record_type', 'registry_number', 'person_name', 'birth_date'],
        civilRecordExtendedFieldNames(),
        ['event_date', 'place', 'father_name', 'mother_name', 'notes']
    );
}

// CSV template download
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    $tplType = $_GET['type'] ?? 'birth';
    if (!in_array($tplType, $validTypes, true)) {
        $tplType = 'birth';
    }
    $filename = "alcros_{$tplType}_template.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, civilRecordCsvColumns($tplType));
    fputcsv($out, civilRecordCsvSampleRow($tplType));
    fclose($out);
    exit;
}

// Export filtered records
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $filters = currentRecordsFilters();
    exportCivilRecordsCsv($pdo, $filters);
    logActivity(staffId(), 'CSV Export', 'Exported civil records (' . ($filters['type'] ?? 'all') . ')');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $data = normalizeRecordInput($_POST);
            insertCivilRecord($pdo, $data);
            logActivity(staffId(), 'Record Created', 'New ' . $data['record_type'] . ' record: ' . $data['person_name']);
            recordsFlashSet('success', 'Record saved successfully.');
        } elseif ($action === 'update' && !empty($_POST['record_id'])) {
            $data = normalizeRecordInput($_POST);
            $id = (int) $_POST['record_id'];
            $sets = implode(', ', array_map(static fn ($col) => "$col = ?", civilRecordInsertColumns()));
            $stmt = $pdo->prepare("UPDATE civil_records SET $sets WHERE id = ?");
            $stmt->execute([...array_map(static fn ($col) => $data[$col], civilRecordInsertColumns()), $id]);
            logActivity(staffId(), 'Record Updated', "Updated record #$id: {$data['person_name']}");
            recordsFlashSet('success', 'Record updated successfully.');
        } elseif ($action === 'import_csv') {
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
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM civil_records WHERE $where");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));

$orderCol = $validSorts[$sort];
$sql = "SELECT * FROM civil_records WHERE $where ORDER BY $orderCol $dir LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$editRecord = null;
if (isset($_GET['edit'])) {
    $editStmt = $pdo->prepare('SELECT * FROM civil_records WHERE id = ?');
    $editStmt->execute([(int) $_GET['edit']]);
    $editRecord = $editStmt->fetch() ?: null;
}

$typeBadgeClass = [
    'birth'    => 'bg-blue-100 text-blue-600',
    'death'    => 'bg-gray-100 text-gray-600',
    'marriage' => 'bg-pink-100 text-pink-600',
];

$showModal = isset($_GET['new']);
$flash = recordsFlashGet();

function sortUrl(string $column): string
{
    global $sort, $dir;
    $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    return buildRecordsUrl(['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Civil Records - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .active-nav { background-color: #2563eb; color: white !important; }
        .table-head { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; }
        .filter-chip { font-size: 10px; font-weight: 600; padding: 4px 12px; border-radius: 6px; }
        .entry-dropdown { box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
        .entry-menu-item:hover { background-color: #f8fafc; }
        .stat-card { transition: box-shadow 0.2s, border-color 0.2s; }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); border-color: #bfdbfe; }
    </style>
</head>
<body class="flex min-h-screen">

    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="admin-main flex flex-col bg-[#fdfdfd]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-10 space-y-6">
            <div class="flex flex-col lg:flex-row lg:justify-between lg:items-start gap-4">
                <div class="min-w-0">
                    <a href="dashboard.php" class="text-blue-600 text-[11px] font-bold flex items-center mb-2 hover:underline">
                        <i data-lucide="chevron-left" class="w-3 h-3 mr-1"></i> Back to Dashboard
                    </a>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight leading-none">Civil Records</h1>
                    <p class="text-gray-500 text-sm mt-2">Manage birth, death, and marriage registry entries with search, export, and import.</p>
                </div>
                <div class="flex flex-wrap gap-2 sm:gap-3 items-start">
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
                            <button type="button" id="addSingleRecordBtn" class="entry-menu-item w-full px-4 py-2.5 text-left text-sm font-bold text-slate-800 flex items-center gap-2">
                                <span class="text-blue-600 text-base leading-none">+</span> Add Single Record
                            </button>
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
            </div>

            <?php if ($flash): ?>
            <div class="rounded-xl p-3 text-xs font-semibold flex items-center gap-2 <?= $flash[0] === 'success' ? 'bg-green-50 border border-green-100 text-green-700' : 'bg-red-50 border border-red-100 text-red-700' ?>">
                <i data-lucide="<?= $flash[0] === 'success' ? 'check-circle' : 'alert-circle' ?>" class="w-4 h-4"></i>
                <?= htmlspecialchars($flash[1]) ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                <?php foreach (['birth' => ['count' => $birthCount, 'icon' => 'users', 'bg' => 'bg-blue-50 text-blue-600'], 'death' => ['count' => $deathCount, 'icon' => 'activity', 'bg' => 'bg-gray-50 text-gray-400'], 'marriage' => ['count' => $marriageCount, 'icon' => 'heart', 'bg' => 'bg-pink-50 text-pink-500']] as $key => $meta): ?>
                <a href="<?= buildRecordsUrl(['type' => $key, 'page' => 1]) ?>" class="stat-card bg-white p-4 rounded-lg border border-gray-100 shadow-sm block <?= $type === $key ? 'ring-2 ring-blue-500' : '' ?>">
                    <div class="<?= $meta['bg'] ?> p-1.5 rounded-md w-fit mb-2"><i data-lucide="<?= $meta['icon'] ?>" class="w-4 h-4"></i></div>
                    <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest"><?= civilRecordTypeLabel($key) ?></p>
                    <p class="text-2xl font-black text-slate-900 leading-tight mt-0.5"><?= $meta['count'] ?></p>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3">
                    <div class="flex bg-gray-50 p-1 rounded-lg">
                        <?php foreach (['all' => 'All', 'birth' => 'Birth', 'death' => 'Death', 'marriage' => 'Marriage'] as $key => $label): ?>
                        <a href="<?= buildRecordsUrl(['type' => $key, 'page' => 1]) ?>"
                           class="filter-chip <?= $type === $key ? 'bg-white shadow-sm text-blue-600' : 'text-gray-400 hover:text-gray-600' ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                    <form method="GET" class="relative w-full sm:w-72">
                        <?php if ($type !== 'all'): ?><input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>"><?php endif; ?>
                        <?php if ($sort !== 'name'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
                        <?php if ($dir !== 'asc'): ?><input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>"><?php endif; ?>
                        <i data-lucide="search" class="absolute left-3 top-2 w-3.5 h-3.5 text-gray-400"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, registry, parents, place..."
                            class="w-full pl-9 pr-4 py-1.5 text-xs bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </form>
                </div>

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
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($records as $r):
                            $badge = $typeBadgeClass[$r['record_type']] ?? 'bg-gray-100 text-gray-600';
                            $keyDate = $r['record_type'] === 'birth' ? $r['birth_date'] : $r['event_date'];
                            if (!$keyDate) {
                                $keyDate = $r['birth_date'] ?: $r['event_date'];
                            }
                            $parents = array_filter([$r['father_name'] ? 'Father: ' . $r['father_name'] : '', $r['mother_name'] ? 'Mother: ' . $r['mother_name'] : '']);
                        ?>
                        <tr class="hover:bg-gray-50/50 transition-colors">
                            <td class="p-4">
                                <button type="button" class="view-record-btn text-left w-full" data-record="<?= htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-blue-100 rounded flex items-center justify-center text-blue-600 font-bold text-xs"><?= htmlspecialchars(recordInitial($r['person_name'])) ?></div>
                                        <div>
                                            <div class="flex items-center space-x-2">
                                                <span class="text-sm font-bold text-slate-800 hover:text-blue-600"><?= htmlspecialchars($r['person_name']) ?></span>
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
                                    <a href="<?= buildRecordsUrl(['edit' => $r['id']]) ?>" class="text-gray-300 hover:text-slate-600" title="Edit"><i data-lucide="edit-3" class="w-4 h-4"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
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
    $modalTitle = $editRecord ? 'Edit Civil Record' : 'Add New Civil Record';
    $submitAction = $editRecord ? 'update' : 'create';
    $defaultRecordType = $modalRecord['record_type'] ?? ($type !== 'all' ? $type : 'birth');
    ?>
    <div class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4" id="entryModal">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col">
            <div class="flex justify-between items-center px-4 sm:px-6 py-4 border-b border-gray-100 shrink-0">
                <h2 class="text-lg font-black text-slate-900" id="entryModalTitle"><?= htmlspecialchars($modalTitle) ?></h2>
                <button type="button" class="text-gray-400 hover:text-gray-600 close-modal"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form method="POST" class="flex flex-col flex-1 min-h-0" id="entryForm">
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

                    <div id="birthFieldsPanel" class="space-y-5 <?= $defaultRecordType === 'birth' ? '' : 'hidden' ?>">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Full Name *</label>
                            <input type="text" name="person_name" id="birthPersonName" value="<?= htmlspecialchars($modalRecord['person_name'] ?? '') ?>" placeholder="e.g. Juan Dela Cruz" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
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
                    </div>

                    <div id="deathFieldsPanel" class="space-y-5 <?= $defaultRecordType === 'death' ? '' : 'hidden' ?>">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Name of Deceased *</label>
                            <input type="text" name="person_name" id="deathPersonName" value="<?= htmlspecialchars($modalRecord['person_name'] ?? '') ?>" placeholder="Surname, First Name, Middle Name" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
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
                    </div>

                    <div id="marriageFieldsPanel" class="space-y-5 <?= $defaultRecordType === 'marriage' ? '' : 'hidden' ?>">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Full Name *</label>
                            <input type="text" name="person_name" id="marriagePersonName" value="<?= htmlspecialchars($modalRecord['person_name'] ?? '') ?>" placeholder="e.g. Juan Dela Cruz" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
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
                    </div>
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

    <div class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4" id="viewModal">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-lg font-black text-slate-900">Record Details</h2>
                <button type="button" class="text-gray-400 hover:text-gray-600 close-modal"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div id="viewContent" class="space-y-3 text-sm"></div>
            <div class="flex gap-3 pt-6">
                <a href="#" id="viewEditLink" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 text-sm font-bold text-center">Edit Record</a>
                <button type="button" class="flex-1 border border-gray-200 rounded-xl py-3 text-sm font-bold text-gray-600 hover:bg-gray-50 close-modal">Close</button>
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
                <p class="text-xs text-gray-500" id="importColumnsHelp">Download the matching CSV template below. Enter each value in its own column (do not paste an entire row into cell A). Template sample rows are skipped automatically. Use dates as <strong>YYYY-MM-DD</strong> or <strong>MM/DD/YYYY</strong>. In Excel, use <strong>Save As → CSV UTF-8 (Comma delimited)</strong>. Required: <strong>person_name</strong>.</p>
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

    <?php if ($showModal || $editRecord): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('entryModal'));</script>
    <?php endif; ?>

    <script>
        lucide.createIcons();

        const newEntryBtn = document.getElementById('newEntryBtn');
        const newEntryMenu = document.getElementById('newEntryMenu');
        const newEntryWrapper = document.getElementById('newEntryWrapper');

        function openModal(id) {
            const el = document.getElementById(id);
            el.classList.remove('hidden');
            el.classList.add('flex');
            lucide.createIcons();
        }

        function closeAllModals() {
            document.querySelectorAll('#entryModal, #importModal, #viewModal').forEach(el => {
                el.classList.add('hidden');
                el.classList.remove('flex');
            });
        }

        function formatDate(val) {
            if (val === null || val === undefined || val === '') return '—';
            const raw = String(val).substring(0, 10);
            const d = new Date(raw + 'T00:00:00');
            return Number.isNaN(d.getTime()) ? raw : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function displayValue(val) {
            if (val === null || val === undefined || String(val).trim() === '') return '—';
            return String(val);
        }

        function recordRegistryNumber(r) {
            const registry = (r.registry_number ?? '').toString().trim();
            if (registry !== '') return registry;
            const code = (r.code_number ?? '').toString().trim();
            return code !== '' ? code : '';
        }

        function recordEventDate(r) {
            if (r.record_type === 'birth') {
                return r.birth_date || r.event_date;
            }
            return r.event_date || r.birth_date;
        }

        newEntryBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            newEntryMenu.classList.toggle('hidden');
        });

        document.addEventListener('click', (e) => {
            if (!newEntryWrapper.contains(e.target)) newEntryMenu.classList.add('hidden');
        });

        document.getElementById('addSingleRecordBtn').addEventListener('click', () => {
            newEntryMenu.classList.add('hidden');
            setRecordType('birth');
            openModal('entryModal');
        });

        const recordTypeStyles = {
            birth: 'border-blue-500 bg-blue-50 text-blue-700',
            death: 'border-slate-400 bg-slate-50 text-slate-700',
            marriage: 'border-pink-400 bg-pink-50 text-pink-700'
        };
        const recordTypeIdle = 'border-gray-200 text-gray-500 hover:border-gray-300';

        function setRecordType(type) {
            document.getElementById('recordTypeInput').value = type;
            document.querySelectorAll('.record-type-tab').forEach(tab => {
                const active = tab.dataset.recordType === type;
                tab.className = 'record-type-tab rounded-xl border-2 px-3 py-3 text-center transition ' + (active ? recordTypeStyles[type] : recordTypeIdle);
            });

            const panels = {
                birth: document.getElementById('birthFieldsPanel'),
                death: document.getElementById('deathFieldsPanel'),
                marriage: document.getElementById('marriageFieldsPanel'),
            };
            Object.keys(panels).forEach(key => {
                const panel = panels[key];
                if (!panel) return;
                const active = key === type;
                panel.classList.toggle('hidden', !active);
                panel.querySelectorAll('input, select, textarea').forEach(el => { el.disabled = !active; });
            });

            document.getElementById('birthPersonName').required = type === 'birth';
            document.getElementById('deathPersonName').required = type === 'death';
            document.getElementById('marriagePersonName').required = type === 'marriage';
            if (type === 'birth') syncSingleBirthDetails();
            lucide.createIcons();
        }

        function syncPersonNameAcrossPanels(sourceId) {
            const map = {
                birthPersonName: ['deathPersonName', 'marriagePersonName'],
                deathPersonName: ['birthPersonName', 'marriagePersonName'],
                marriagePersonName: ['birthPersonName', 'deathPersonName'],
            };
            const source = document.getElementById(sourceId);
            if (!source) return;
            (map[sourceId] || []).forEach(id => {
                const target = document.getElementById(id);
                if (target && !target.disabled) target.value = source.value;
            });
        }

        function syncSingleBirthDetails() {
            const panel = document.getElementById('singleBirthDetails');
            const select = document.getElementById('birthTypeSelect');
            if (!panel || !select) return;
            const isSingle = select.value === 'Single';
            panel.classList.toggle('hidden', !isSingle);
            panel.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = !isSingle || document.getElementById('birthFieldsPanel').classList.contains('hidden');
            });
        }

        document.querySelectorAll('.record-type-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const nextType = tab.dataset.recordType;
                const names = {
                    birth: document.getElementById('birthPersonName'),
                    death: document.getElementById('deathPersonName'),
                    marriage: document.getElementById('marriagePersonName'),
                };
                const current = Object.entries(names).find(([key, el]) => el && el.value && !el.closest('.hidden'));
                if (current && names[nextType] && !names[nextType].value) {
                    names[nextType].value = current[1].value;
                }
                setRecordType(nextType);
            });
        });

        document.getElementById('birthTypeSelect')?.addEventListener('change', syncSingleBirthDetails);
        document.getElementById('entryForm')?.addEventListener('submit', () => {
            const type = document.getElementById('recordTypeInput').value;
            setRecordType(type);
        });
        setRecordType(document.getElementById('recordTypeInput').value || 'birth');

        const csvTemplateColumns = <?= json_encode([
            'birth' => civilRecordCsvColumns('birth'),
            'death' => civilRecordCsvColumns('death'),
            'marriage' => civilRecordCsvColumns('marriage'),
        ], JSON_UNESCAPED_UNICODE) ?>;

        const recordsAuthUrl = <?= json_encode(buildAuthUrl('records.php')) ?>;

        document.querySelectorAll('[data-import-type]').forEach(btn => {
            btn.addEventListener('click', () => {
                const type = btn.dataset.importType;
                newEntryMenu.classList.add('hidden');
                document.getElementById('importType').value = type;
                document.getElementById('importModalTitle').textContent = 'Import ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Records';
                document.getElementById('importTemplateLink').href = recordsAuthUrl + (recordsAuthUrl.includes('?') ? '&' : '?') + 'action=template&type=' + type;
                document.getElementById('importTemplateLink').download = 'alcros_' + type + '_template.csv';
                const cols = csvTemplateColumns[type] || [];
                document.getElementById('importColumnsHelp').innerHTML =
                    'Upload a CSV with the template headers for <strong>' + type + '</strong> records. Put each value in its own column — do not paste a whole row into cell A. Required: <strong>person_name</strong> (or <strong>husband_name</strong> + <strong>wife_name</strong> for marriage). Dates: YYYY-MM-DD or MM/DD/YYYY. Template sample rows are skipped automatically.<br><span class="text-[10px] text-gray-400 mt-1 inline-block">' + cols.join(', ') + '</span>';
                const fileInput = document.querySelector('#importForm input[name="csv_file"]');
                if (fileInput) fileInput.value = '';
                openModal('importModal');
            });
        });

        document.getElementById('importForm')?.addEventListener('submit', (e) => {
            if (!document.getElementById('importType').value) {
                e.preventDefault();
                alert('Please choose an import type from New Entry → Import.');
                return;
            }
            const submitBtn = e.target.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Importing…';
            }
        });

        document.querySelectorAll('.close-modal').forEach(btn => btn.addEventListener('click', closeAllModals));
        ['entryModal', 'importModal', 'viewModal'].forEach(id => {
            document.getElementById(id)?.addEventListener('click', (e) => {
                if (e.target === e.currentTarget) closeAllModals();
            });
        });

        function formatAgeAtDeath(r) {
            const units = [
                ['age_death_years', 'y'],
                ['age_death_months', 'm'],
                ['age_death_days', 'd'],
                ['age_death_hours', 'h'],
                ['age_death_minutes', 'min'],
            ];
            const parts = units
                .filter(([key]) => r[key] !== null && r[key] !== '' && r[key] !== undefined)
                .map(([key, suffix]) => r[key] + suffix);
            let text = parts.length ? parts.join(' ') : '—';
            if (r.stillbirth == 1) text += (text === '—' ? '' : ' ') + '(Still-birth)';
            return text;
        }

        function marriageSpouseRows(r, prefix, label) {
            return [
                [label + ' — Name', r[prefix + '_name'] || '—'],
                [label + ' — Date of Birth', formatDate(r[prefix + '_birth_date'])],
                [label + ' — Age', r[prefix + '_age'] ?? '—'],
                [label + ' — Place of Birth', r[prefix + '_birth_place'] || '—'],
                [label + ' — Citizenship', r[prefix + '_citizenship'] || '—'],
                [label + ' — Religion', r[prefix + '_religion'] || '—'],
                [label + ' — Civil Status', r[prefix + '_civil_status'] || '—'],
                [label + ' — Residence', r[prefix + '_residence'] || '—'],
                [label + " — Father's Name", r[prefix + '_father_name'] || '—'],
                [label + " — Mother's Maiden Name", r[prefix + '_mother_maiden_name'] || '—'],
            ];
        }

        document.querySelectorAll('.view-record-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const r = JSON.parse(btn.dataset.record);
                let rows = [];

                if (r.record_type === 'birth') {
                    rows = [
                        ['Type', displayValue(r.record_type)],
                        ['Registry Number', displayValue(recordRegistryNumber(r))],
                        ['Full Name', displayValue(r.person_name)],
                        ['Date of Birth', formatDate(r.birth_date)],
                        ['Sex', displayValue(r.sex)],
                        ['Time of Birth', displayValue(r.birth_time)],
                        ['Type of Birth', displayValue(r.birth_type)],
                        ['Birth Order', displayValue(r.birth_order)],
                        ['Place of Birth', displayValue(r.place)],
                    ];
                    if (r.birth_type === 'Single' || !r.birth_type) {
                        rows.push(
                            ['Mother', displayValue(r.mother_name)],
                            ['Mother Age', displayValue(r.mother_age)],
                            ['Mother Nationality', displayValue(r.mother_nationality)],
                            ['Mother Religion', displayValue(r.mother_religion)],
                            ['Father', displayValue(r.father_name)],
                            ['Father Age', displayValue(r.father_age)],
                            ['Father Nationality', displayValue(r.father_nationality)],
                            ['Father Religion', displayValue(r.father_religion)],
                            ['Parents Marriage Date', formatDate(r.parents_marriage_date)],
                            ['Parents Marriage Place', displayValue(r.parents_marriage_place)]
                        );
                    }
                    rows.push(['Created', formatDate((r.created_at || '').substring(0, 10))]);
                } else if (r.record_type === 'death') {
                    rows = [
                        ['Type', displayValue(r.record_type)],
                        ['Registry Number', displayValue(recordRegistryNumber(r))],
                        ['Name of Deceased', displayValue(r.person_name)],
                        ['Date of Birth', formatDate(r.birth_date)],
                        ['Sex', displayValue(r.sex)],
                        ['Date of Registration', formatDate(r.registration_date)],
                        ['Residence', displayValue(r.residence_deceased)],
                        ['Residence (Place of Death)', displayValue(r.residence_length_place)],
                        ['Residence (Philippines)', displayValue(r.residence_length_ph)],
                        ['Nationality', displayValue(r.nationality)],
                        ['Civil Status', displayValue(r.civil_status)],
                        ['Age at Death', formatAgeAtDeath(r)],
                        ['Occupation', displayValue(r.occupation)],
                        ['Surviving Spouse', displayValue(r.surviving_spouse_name)],
                        ['Spouse Address', displayValue(r.surviving_spouse_address)],
                        ['Place of Burial', displayValue(r.place_of_burial)],
                        ['Date of Death', formatDate(recordEventDate(r))],
                        ['Time of Death', (displayValue(r.death_time) === '—' ? '—' : displayValue(r.death_time) + (r.death_time_period ? ' ' + r.death_time_period : ''))],
                        ['Immediate Cause', displayValue(r.immediate_cause)],
                        ['Contributory Cause', displayValue(r.contributory_cause)],
                        ['Attending Physician', displayValue(r.attending_physician)],
                        ['Autopsy Performed', displayValue(r.autopsy_performed)],
                        ['Code Number', displayValue(r.code_number)],
                        ['Created', formatDate((r.created_at || '').substring(0, 10))],
                    ];
                } else if (r.record_type === 'marriage') {
                    rows = [
                        ['Type', displayValue(r.record_type)],
                        ['Registry Number', displayValue(recordRegistryNumber(r))],
                        ['Full Name', displayValue(r.person_name)],
                        ['Date of Birth', formatDate(r.birth_date)],
                        ...marriageSpouseRows(r, 'husband', 'Husband'),
                        ...marriageSpouseRows(r, 'wife', 'Wife'),
                        ['Date of Marriage', formatDate(recordEventDate(r))],
                        ['Time of Marriage', displayValue(r.marriage_time)],
                        ['Place of Marriage', displayValue(r.place)],
                        ['Solemnized By', displayValue(r.solemnized_by)],
                        ['Witnesses', displayValue(r.witnesses)],
                        ['Created', formatDate((r.created_at || '').substring(0, 10))],
                    ];
                } else {
                    rows = [
                        ['Type', displayValue(r.record_type)],
                        ['Registry Number', displayValue(recordRegistryNumber(r))],
                        ['Person Name', displayValue(r.person_name)],
                        ['Birth Date', formatDate(r.birth_date)],
                        ['Event Date', formatDate(recordEventDate(r))],
                        ['Place', displayValue(r.place)],
                        ['Created', formatDate((r.created_at || '').substring(0, 10))],
                    ];
                }

                document.getElementById('viewContent').innerHTML = rows.map(([k, v]) =>
                    '<div class="flex justify-between gap-4 border-b border-gray-50 pb-2"><span class="text-gray-400 text-xs font-bold uppercase">' + k + '</span><span class="text-slate-800 text-xs font-semibold text-right">' + String(v).replace(/</g, '&lt;') + '</span></div>'
                ).join('');
                document.getElementById('viewEditLink').href = 'records.php?edit=' + r.id;
                openModal('viewModal');
            });
        });
    </script>
</body>
</html>
