<?php

require_once __DIR__ . '/printing.php';
require_once __DIR__ . '/civil_record_schema.php';

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

function normalizeRecordInput(array $input, bool $fromCsvImport = false): array
{
    $validTypes = ['birth', 'death', 'marriage'];
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
        'book_number'     => trim($input['book_number'] ?? '') ?: null,
        'page_number'     => trim($input['page_number'] ?? '') ?: null,
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

function createCivilRecordFromPrintFill(PDO $pdo, string $recordType, array $printFill): array
{
    $recordType = strtolower(trim($recordType));
    if (!in_array($recordType, ['birth', 'death', 'marriage'], true)) {
        throw new InvalidArgumentException('Invalid record type.');
    }

    $cleanFill = [];
    foreach ($printFill as $field => $value) {
        if (!is_string($field)) {
            continue;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            continue;
        }
        $cleanFill[$field] = $trimmed;
    }

    if ($cleanFill === []) {
        throw new InvalidArgumentException('Fill in at least the required name fields before saving a record.');
    }

    $data = normalizeRecordInput(prepareCivilRecordFormInput([
        'record_type' => $recordType,
        'print_fill'  => $cleanFill,
    ]));

    $recordId = saveCivilRecord($pdo, $data, null);

    return [
        'id'           => $recordId,
        'record_type'  => $data['record_type'],
        'display_name' => civilRecordDisplayName($data),
    ];
}
