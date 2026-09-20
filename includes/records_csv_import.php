<?php

require_once __DIR__ . '/records_form.php';

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

/** @return array<string, string> Normalized header => custom_textbox field name */
function printFillCustomCsvHeaderAliases(PDO $pdo, string $type): array
{
    $aliases = [];

    foreach (['front', 'back'] as $side) {
        $template = getPrintTemplate($pdo, $type, $side);
        if (!$template) {
            continue;
        }
        foreach (getPrintFields($pdo, (int) $template['id'], true) as $dbField) {
            $name = (string) $dbField['field_name'];
            if (!printIsCustomField($name)) {
                continue;
            }
            $aliases[normalizeCsvHeader($name)] = $name;
            $label = trim((string) ($dbField['label'] ?: ''));
            if ($label !== '') {
                $aliases[normalizeCsvHeader($label)] = $name;
            }
        }
    }

    return $aliases;
}

function buildCsvInputFromRow(array $headers, array $row, string $importType, ?PDO $pdo = null): array
{
    $input = ['record_type' => $importType];
    $normalizedHeaders = array_map('normalizeCsvHeader', normalizeCsvHeaderRow($headers));
    $hasKnownHeader = csvRowLooksLikeHeader($headers);
    $customHeaderAliases = $pdo !== null ? printFillCustomCsvHeaderAliases($pdo, $importType) : [];

    if ($hasKnownHeader) {
        foreach ($normalizedHeaders as $i => $key) {
            if ($key === '' || in_array($key, civilRecordCsvSkipColumns(), true)) {
                continue;
            }
            if (isset($customHeaderAliases[$key])) {
                $key = $customHeaderAliases[$key];
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
        'book_no', 'book', 'book_num' => 'book_number',
        'page_no', 'page', 'page_num' => 'page_number',
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

function csvRowMatchesSampleRow(array $headers, array $row, string $importType, ?PDO $pdo = null): bool
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
    $input = buildCsvInputFromRow($headers, $row, $importType, $pdo);

    foreach ($columns as $i => $column) {
        $actual = trim((string) ($input[$column] ?? ''));
        $expected = trim((string) ($sample[$i] ?? ''));
        if ($actual !== $expected) {
            return false;
        }
    }

    return true;
}

function parseCsvRecordRow(array $headers, array $row, string $importType, ?string &$error = null, ?PDO $pdo = null): ?array
{
    $error = null;
    if (isCsvRowEmpty($row)) {
        return null;
    }

    $input = buildCsvInputFromRow($headers, $row, $importType, $pdo);
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
                if (csvRowMatchesSampleRow([], $firstRow, $importType, $pdo)) {
                    $sampleSkipped++;
                } else {
                    $rowError = null;
                    $parsed = parseCsvRecordRow([], $firstRow, $importType, $rowError, $pdo);
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

            if (csvRowMatchesSampleRow($headers, $row, $importType, $pdo)) {
                $sampleSkipped++;
                continue;
            }

            $rowError = null;
            $parsed = parseCsvRecordRow($headers, $row, $importType, $rowError, $pdo);
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


function importCsvParsedRows(PDO $pdo, string $importType, array $headers, array $rows, int $startLineNum = 1): array
{
    if (!in_array($importType, ['birth', 'death', 'marriage'], true)) {
        throw new InvalidArgumentException('Invalid import type.');
    }

    $imported = 0;
    $skipped = 0;
    $sampleSkipped = 0;
    $errors = [];
    $maxErrors = 50;
    $importOptions = ['insert_details_only' => true];
    $lineNum = max(1, $startLineNum);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            $lineNum++;
            continue;
        }
        if (isCsvRowEmpty($row)) {
            $lineNum++;
            continue;
        }
        if (csvRowMatchesSampleRow($headers, $row, $importType, $pdo)) {
            $sampleSkipped++;
            $lineNum++;
            continue;
        }

        $rowError = null;
        $parsed = parseCsvRecordRow($headers, $row, $importType, $rowError, $pdo);
        if ($parsed === null) {
            $skipped++;
            if (count($errors) < $maxErrors) {
                $errors[] = "Row $lineNum: " . ($rowError ?: 'invalid data.');
            }
            $lineNum++;
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
        $lineNum++;
    }

    return compact('imported', 'skipped', 'errors') + ['sample_skipped' => $sampleSkipped];
}
