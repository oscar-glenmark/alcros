<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/lucide_icons.php';
require_once __DIR__ . '/includes/printing.php';
require_once __DIR__ . '/includes/record_locks.php';
require_once __DIR__ . '/includes/cascading_location.php';
require_once __DIR__ . '/includes/print_fill_controls.php';
require_once __DIR__ . '/includes/records_form.php';
require_once __DIR__ . '/includes/records_csv_import.php';
requireStaffLogin();
requirePageAccess('records.php');
releaseSessionLock();

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
    return buildAuthUrl('records.php', $params);
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

function recordsExportTypeFromRequest(): string
{
    global $validTypes;

    $exportType = (string) ($_GET['export_type'] ?? 'all');
    if (!in_array($exportType, ['all', ...$validTypes], true)) {
        return 'all';
    }

    return $exportType;
}

function recordsExportFilters(): array
{
    return [
        'type' => recordsExportTypeFromRequest(),
    ];
}

function recordsExportUrl(string $exportType): string
{
    global $validTypes;

    if (!in_array($exportType, ['all', ...$validTypes], true)) {
        $exportType = 'all';
    }

    return buildAuthUrl('records.php', [
        'action'      => 'export',
        'format'      => 'csv',
        'export_type' => $exportType,
    ]);
}

/** Export scope: all non-deleted records, optionally limited to one record type. Search is never applied. */
function buildRecordsExportWhere(string $exportType): array
{
    $where  = 'cr.deleted_at IS NULL';
    $params = [];

    if ($exportType !== 'all' && in_array($exportType, ['birth', 'death', 'marriage'], true)) {
        $where .= ' AND cr.record_type = ?';
        $params[] = $exportType;
    }

    return [$where, $params];
}

function recordsExportCsvChunkSize(): int
{
    return 2500;
}

function recordsExportPrepareStreamingResponse(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    @ini_set('zlib.output_compression', '0');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Accel-Buffering: no');
}

/** @return array<string, int> */
function exportCivilRecordsTypeCounts(PDO $pdo, string $where, array $params, array $types): array
{
    $counts = array_fill_keys($types, 0);
    if ($types === []) {
        return $counts;
    }

    if (preg_match('/\bcr\.record_type\s*=\s*\?/', $where) === 1) {
        $type = $types[0];
        $counts[$type] = exportCivilRecordsTypeCount($pdo, $where, $params, $type);

        return $counts;
    }

    $stmt = $pdo->prepare("SELECT cr.record_type, COUNT(*) AS cnt FROM civil_records cr WHERE $where GROUP BY cr.record_type");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = (string) ($row['record_type'] ?? '');
        if (isset($counts[$type])) {
            $counts[$type] = (int) ($row['cnt'] ?? 0);
        }
    }

    return $counts;
}

function buildRecordsWhere(array $filters): array
{
    ensureAdminSearchIndexes(getDB());

    $where  = 'cr.deleted_at IS NULL';
    $params = [];

    if ($filters['type'] !== 'all' && in_array($filters['type'], ['birth', 'death', 'marriage'], true)) {
        $where .= ' AND cr.record_type = ?';
        $params[] = $filters['type'];
    }

    $search = adminSearchNormalizeWhitespace($filters['q'] ?? '');
    if ($search === '') {
        return [$where, $params];
    }

    [$nameSearch, $dateSearch] = adminSearchSplitNameAndDate($search);
    if ($nameSearch === '' && $dateSearch === '') {
        $nameSearch = $search;
    }

    $term = '%' . $search . '%';
    $clauses = [];
    $searchParams = [];

    if ($nameSearch !== '') {
        [$nameClauses, $nameParams] = adminSearchNameLikeClauses(
            $nameSearch,
            'cr.first_name',
            'cr.middle_name',
            'cr.last_name'
        );
        $clauses = array_merge($clauses, $nameClauses);
        $searchParams = array_merge($searchParams, $nameParams);

        $nameTerm = '%' . $nameSearch . '%';
        $clauses[] = "EXISTS (
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
            )";
        $searchParams = array_merge($searchParams, array_fill(0, 8, $nameTerm));
    }

    foreach ([
        'cr.registry_number LIKE ?',
        'cr.book_number LIKE ?',
        'cr.page_number LIKE ?',
        'cr.father_name LIKE ?',
        'cr.mother_name LIKE ?',
        'cr.place LIKE ?',
        'cr.notes LIKE ?',
        'CAST(cr.id AS CHAR) LIKE ?',
    ] as $clause) {
        $clauses[] = $clause;
        $searchParams[] = $term;
    }

    $clauses[] = "EXISTS (
            SELECT 1 FROM death_record_details drd
            WHERE drd.civil_record_id = cr.id
            AND drd.code_number LIKE ?
        )";
    $searchParams[] = $term;

    $dateQuery = $dateSearch !== '' ? $dateSearch : (adminSearchLooksLikeDate($search) ? $search : '');
    if ($dateQuery !== '') {
        [$dateClauses, $dateParams] = adminSearchDateClausesForColumns($dateQuery, [
            'cr.birth_date',
            'cr.event_date',
        ]);
        $clauses = array_merge($clauses, $dateClauses);
        $searchParams = array_merge($searchParams, $dateParams);

        [$deathRegSql, $deathRegParams] = adminSearchDateMatchExpr('drd.registration_date', $dateQuery);
        $clauses[] = "EXISTS (
                SELECT 1 FROM death_record_details drd
                WHERE drd.civil_record_id = cr.id
                AND $deathRegSql
            )";
        $searchParams = array_merge($searchParams, $deathRegParams);

        [$birthRegSql, $birthRegParams] = adminSearchDateMatchExpr('brd.registration_date', $dateQuery);
        [$parentsDomSql, $parentsDomParams] = adminSearchDateMatchExpr('brd.parents_marriage_date', $dateQuery);
        $clauses[] = "EXISTS (
                SELECT 1 FROM birth_record_details brd
                WHERE brd.civil_record_id = cr.id
                AND ($birthRegSql OR $parentsDomSql)
            )";
        $searchParams = array_merge($searchParams, $birthRegParams, $parentsDomParams);

        [$husbandDobSql, $husbandDobParams] = adminSearchDateMatchExpr('mrd.husband_birth_date', $dateQuery);
        [$wifeDobSql, $wifeDobParams] = adminSearchDateMatchExpr('mrd.wife_birth_date', $dateQuery);
        $clauses[] = "EXISTS (
                SELECT 1 FROM marriage_record_details mrd
                WHERE mrd.civil_record_id = cr.id
                AND ($husbandDobSql OR $wifeDobSql)
            )";
        $searchParams = array_merge($searchParams, $husbandDobParams, $wifeDobParams);
    }

    $where .= ' AND (' . implode(' OR ', $clauses) . ')';
    $params = array_merge($params, $searchParams);

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
        $r['book_number'] ?? '',
        $r['page_number'] ?? '',
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

    foreach ([
        'birth_date', 'event_date', 'registration_date', 'parents_marriage_date',
        'death_date', 'marriage_date', 'husband_birth_date', 'wife_birth_date',
    ] as $dateCol) {
        if (!empty($r[$dateCol])) {
            $parts = array_merge($parts, adminSearchDateBlobVariants((string) $r[$dateCol]));
        }
    }

    return implode(' ', array_filter(array_map(static fn ($v) => trim((string) $v), $parts), static fn ($v) => $v !== ''));
}

function recordInitial(string $name): string
{
    return strtoupper(substr(trim($name), 0, 1));
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

    recordsExportPrepareStreamingResponse();

    $exportType = $filters['type'] ?? 'all';
    [$where, $params] = buildRecordsExportWhere($exportType);
    $types = ($exportType !== 'all' && in_array($exportType, $validTypes, true))
        ? [$exportType]
        : $validTypes;
    $chunkSize = recordsExportCsvChunkSize();
    $typeCounts = exportCivilRecordsTypeCounts($pdo, $where, $params, $types);
    $totalRecords = array_sum($typeCounts);

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

    if (count($types) > 1) {
        fputcsv($out, ['ALCROS Civil Records Export']);
        fputcsv($out, ['Generated on', date('Y-m-d g:i A')]);
        fputcsv($out, ['Total records', (string) $totalRecords]);
        fputcsv($out, []);
    }

    foreach ($types as $type) {
        $typeCount = $typeCounts[$type] ?? 0;

        if (count($types) > 1) {
            fputcsv($out, ['--- ' . strtoupper(civilRecordTypeLabel($type)) . ' RECORDS (' . $typeCount . ') ---']);
        }

        fputcsv($out, civilRecordCsvColumns($type));

        if ($typeCount === 0) {
            if (count($types) > 1) {
                fputcsv($out, []);
            }
            continue;
        }

        [$typeWhere, $typeParams] = exportCivilRecordsTypeWhere($where, $params, $type);
        $afterId = 0;

        while (true) {
            $chunk = exportCivilRecordsFetchChunk($pdo, $typeWhere, $typeParams, $afterId, $chunkSize);
            if ($chunk === []) {
                break;
            }

            $chunk = hydrateCivilRecordRows($pdo, $chunk);
            foreach ($chunk as $record) {
                fputcsv($out, civilRecordExportRowValues($record, $type));
            }

            $afterId = (int) ($chunk[array_key_last($chunk)]['id'] ?? $afterId);
            unset($chunk);
            fflush($out);
        }

        if (count($types) > 1) {
            fputcsv($out, []);
        }
    }

    if (count($types) > 1) {
        fputcsv($out, ['End of export']);
    }

    fflush($out);
    fclose($out);
}

function exportCivilRecordsTypeWhere(string $where, array $params, string $type): array
{
    if (preg_match('/\bcr\.record_type\s*=\s*\?/', $where) !== 1) {
        $where .= ' AND cr.record_type = ?';
        $params[] = $type;
    }

    return [$where, $params];
}

function exportCivilRecordsTypeCount(PDO $pdo, string $where, array $params, string $type): int
{
    [$typeWhere, $typeParams] = exportCivilRecordsTypeWhere($where, $params, $type);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM civil_records cr WHERE $typeWhere");
    $stmt->execute($typeParams);

    return (int) $stmt->fetchColumn();
}

/** @return list<array<string, mixed>> */
function exportCivilRecordsFetchChunk(PDO $pdo, string $where, array $params, int $afterId, int $limit): array
{
    $sql = "SELECT cr.* FROM civil_records cr WHERE $where AND cr.id > ?"
        . ' ORDER BY cr.id ASC LIMIT ' . max(1, $limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$afterId]));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function exportCivilRecordTemplateXlsx(PDO $pdo, string $type): void
{
    global $validTypes;

    if (!in_array($type, $validTypes, true)) {
        $type = 'birth';
    }

    require_once __DIR__ . '/includes/excel_export.php';

    $spreadsheet = alcrosExcelNewSpreadsheet('ALCROS ' . ucfirst($type) . ' Import Template');
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Import data');

    $headers = printFillCsvTemplateHeadersForType($pdo, $type);
    $sample = civilRecordCsvSampleRowFromPrintFields($type, $pdo);

    $row = 1;
    alcrosExcelWriteMetaBlock($sheet, [
        'ALCROS bulk import template — ' . civilRecordTypeLabel($type),
        ['Instructions', 'Enter one record per row using the column headers below. The sample row is skipped on import.'],
        ['Dates', 'Use YYYY-MM-DD or MM/DD/YYYY, or separate day / month / year columns where provided.'],
        ['Required', $type === 'marriage'
            ? 'Husband and wife name columns (see template headers).'
            : ($type === 'death'
                ? 'deceased_first_name and deceased_last_name'
                : 'child_first_name and child_last_name')],
    ], $row);

    alcrosExcelWriteTable($sheet, $headers, [$sample], $row);

    alcrosExcelSendDownload($spreadsheet, 'alcros_' . $type . '_import_template.xlsx');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) {
    return;
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
    $lock = fetchCivilRecordEditLock($pdo, $recordId);
    echo json_encode([
        'ok'           => true,
        'record'       => $record,
        'print_values' => $printValues,
        'edit_lock'    => $lock ? formatCivilRecordEditLock($lock, staffId()) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Import template download
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    $tplType = $_GET['type'] ?? 'birth';
    if (!in_array($tplType, $validTypes, true)) {
        $tplType = 'birth';
    }
    $format = strtolower((string) ($_GET['format'] ?? 'xlsx'));
    if ($format === 'xlsx') {
        exportCivilRecordTemplateXlsx($pdo, $tplType);
    }

    $filename = "alcros_{$tplType}_import_template.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, printFillCsvTemplateHeadersForType($pdo, $tplType));
    fputcsv($out, civilRecordCsvSampleRowFromPrintFields($tplType, $pdo));
    fclose($out);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'export') {
    @ini_set('memory_limit', '1024M');
    @set_time_limit(0);
    ignore_user_abort(true);

    $format = strtolower((string) ($_GET['format'] ?? 'csv'));
    if ($format !== 'csv') {
        http_response_code(400);
        exit('Only CSV export is available.');
    }

    $filters = recordsExportFilters();
    logActivity(staffId(), 'CSV Export', 'Exported civil records (' . ($filters['type'] ?? 'all') . ')');
    exportCivilRecordsCsv($pdo, $filters);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action !== '') {
    try {
        if ($action === 'create') {
            $data = normalizeRecordInput(prepareCivilRecordFormInput($_POST));
            insertCivilRecord($pdo, $data);
            logActivity(staffId(), 'Record Created', 'New ' . $data['record_type'] . ' record: ' . civilRecordDisplayName($data));
            recordsFlashSet('success', 'Record saved successfully.');
        } elseif ($action === 'update' && !empty($_POST['record_id'])) {
            $data = normalizeRecordInput(prepareCivilRecordFormInput($_POST));
            $id = (int) $_POST['record_id'];
            assertCivilRecordEditableByStaff($pdo, $id, staffId());
            saveCivilRecord($pdo, $data, $id);
            releaseCivilRecordEditLock($pdo, $id, staffId());
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

$typeCounts = $pdo->query(
    "SELECT record_type, COUNT(*) AS cnt
     FROM civil_records
     WHERE deleted_at IS NULL AND record_type IN ('birth', 'death', 'marriage')
     GROUP BY record_type"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$birthCount    = (int) ($typeCounts['birth'] ?? 0);
$deathCount    = (int) ($typeCounts['death'] ?? 0);
$marriageCount = (int) ($typeCounts['marriage'] ?? 0);

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
$records = hydrateCivilRecordRows($pdo, $records);

$editRecord = null;
$editLockBlocked = null;
$editLockHeld = false;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $editRecord = fetchFullCivilRecord($pdo, $editId);
    if ($editRecord) {
        $lockResult = acquireCivilRecordEditLock($pdo, $editId, staffId(), staffName());
        if ($lockResult['ok']) {
            $editLockHeld = true;
        } else {
            $editLockBlocked = $lockResult;
        }
    }
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

function renderRecordEntryPrintFillSection(PDO $pdo, string $type, array $modalRecord, bool $active): void
{
    $fields = printFillEditorFields($type, recordEntryPrintFillSource($type, $modalRecord), [
        'exclude_record_registry_fields' => true,
    ], $pdo);
    $panelId = $type . 'PrintFillPanel';
    ?>
    <div id="<?= htmlspecialchars($panelId) ?>" class="records-entry-print-fill <?= $active ? '' : 'hidden' ?>">
        <div class="records-entry-print-fill__head">
            <div>
                <p class="records-entry-print-fill__title">Print Certificate Fields</p>
                <p class="records-entry-print-fill__hint">Additional values for the municipal form (attendant, informant, registrar, LCRO, affidavits, etc.). Use <strong>Back page</strong> for affidavit and optional sections. Custom textboxes from Print Calibration appear here automatically.</p>
            </div>
        </div>
        <div class="records-entry-print-fill__tabs" role="tablist" aria-label="<?= htmlspecialchars(ucfirst($type)) ?> fill-in page">
            <button type="button" class="records-entry-print-fill__tab is-active" data-entry-fill-tab="front" role="tab" aria-selected="true">Front page</button>
            <button type="button" class="records-entry-print-fill__tab" data-entry-fill-tab="back" role="tab" aria-selected="false">Back page</button>
        </div>
        <?php foreach (['front', 'back'] as $fillSide): ?>
        <?php
        $sideFields = array_values(array_filter(
            $fields,
            static fn (array $fillField): bool => ($fillField['page_side'] ?? '') === $fillSide
        ));
        ?>
        <div class="records-entry-print-fill__grid" data-entry-fill-panel="<?= $fillSide ?>" role="tabpanel"<?= $fillSide === 'back' ? ' hidden' : '' ?>>
            <?php if ($sideFields === []): ?>
            <p class="records-entry-print-fill__empty">No <?= $fillSide === 'back' ? 'back page' : 'front page' ?> fields configured yet.</p>
            <?php else: ?>
            <?php foreach ($sideFields as $fillField):
                $fillGroup = printFillFieldGroup($fillField['field_name']);
            ?>
            <label class="records-entry-print-fill__field"<?= $fillGroup !== '' ? ' data-fill-group="' . htmlspecialchars($fillGroup) . '"' : '' ?>>
                <span><?= htmlspecialchars($fillField['label']) ?></span>
                <?php renderPrintFillFieldInput($fillField, [
                    'name'       => 'print_fill[' . $fillField['field_name'] . ']',
                    'lcro_class' => 'records-entry-print-fill__field--lcro',
                ]); ?>
            </label>
            <?php endforeach; ?>
            <?php endif; ?>
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
    <?= faviconLinkTag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Civil Records - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= interFontTags() ?>
    <?= adminLayoutHeadStyles('records') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen">

    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="admin-main flex flex-col bg-[#fdfdfd]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto w-full admin-page-wrap space-y-6">
            <div class="flex flex-wrap gap-2 sm:gap-3 items-center justify-end">
                    <div class="relative" id="recordsExportMenu">
                        <button type="button" id="recordsExportBtn"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-[11px] font-bold uppercase flex items-center shadow-sm">
                            <i data-lucide="download" class="w-4 h-4 mr-2"></i> Export CSV
                            <i data-lucide="chevron-down" class="w-3.5 h-3.5 ml-1.5 opacity-80"></i>
                        </button>
                        <div id="recordsExportPanel" class="hidden absolute right-0 mt-2 w-64 bg-white border border-gray-100 rounded-xl shadow-lg z-50 py-1 text-xs">
                            <?php
                            $exportOptions = [
                                'all'      => 'All records',
                                'birth'    => 'Birth records',
                                'death'    => 'Death records',
                                'marriage' => 'Marriage records',
                            ];
                            ?>
                            <p class="px-3 py-1.5 text-[9px] font-bold uppercase tracking-wider text-gray-400">CSV (.csv)</p>
                            <?php foreach ($exportOptions as $exportKey => $exportLabel): ?>
                            <a href="<?= htmlspecialchars(recordsExportUrl($exportKey)) ?>"
                               class="block px-3 py-2.5 font-semibold text-slate-700 hover:bg-gray-50">
                                <?= htmlspecialchars($exportLabel) ?>
                            </a>
                            <?php endforeach; ?>
                            <p class="px-3 py-2 border-t border-gray-100 text-[10px] text-gray-400 leading-snug">Exports every record for the option you choose. Search does not affect downloads.</p>
                        </div>
                    </div>
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
                                <?= lucideSvg('file-text', 'w-4 h-4 text-gray-400 shrink-0') ?> Import <?= civilRecordTypeLabel($t) ?> Records
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                <?php foreach (['birth' => ['count' => $birthCount, 'icon' => 'users', 'bg' => 'bg-blue-50 text-blue-600'], 'death' => ['count' => $deathCount, 'icon' => 'activity', 'bg' => 'bg-gray-50 text-gray-400'], 'marriage' => ['count' => $marriageCount, 'icon' => 'heart', 'bg' => 'bg-pink-50 text-pink-500']] as $key => $meta): ?>
                <a href="<?= buildRecordsUrl(['type' => $key, 'page' => 1]) ?>" class="stat-card bg-white p-4 rounded-lg border border-gray-100 shadow-sm block <?= $type === $key ? 'ring-2 ring-blue-500' : '' ?>">
                    <div class="<?= $meta['bg'] ?> p-1.5 rounded-md w-fit mb-2"><?= lucideSvg($meta['icon'], 'w-4 h-4') ?></div>
                    <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest"><?= civilRecordTypeLabel($key) ?></p>
                    <p class="text-2xl font-black text-slate-900 leading-tight mt-0.5"><?= $meta['count'] ?></p>
                </a>
                <?php endforeach; ?>
            </div>

            <form method="GET" action="<?= htmlspecialchars(buildAuthUrl('records.php')) ?>" class="admin-toolbar">
                <?= authFormField() ?>
                <?php if ($type !== 'all'): ?><input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>"><?php endif; ?>
                <?php if ($sort !== 'name'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
                <?php if ($dir !== 'asc'): ?><input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>"><?php endif; ?>
                <div class="relative flex-1 admin-toolbar-search">
                    <span class="absolute left-3 top-2.5 pointer-events-none text-gray-400"><?= lucideSvg('search', 'w-4 h-4') ?></span>
                    <input type="text" name="q" id="recordsSearchInput" value="<?= htmlspecialchars($search) ?>" placeholder="First, middle, last, full name, DOB, DOM, registry…"
                        class="records-search-input w-full pl-10 pr-4 py-2 text-sm bg-gray-50 border-none rounded-lg focus:ring-0 text-slate-600 placeholder-gray-400" autocomplete="off">
                </div>
                <div class="admin-toolbar-filters">
                    <?php
                    $filterTabs = [
                        'all'      => ['label' => 'All', 'icon' => 'layers'],
                        'birth'    => ['label' => 'Birth', 'icon' => 'users'],
                        'death'    => ['label' => 'Death', 'icon' => 'activity'],
                        'marriage' => ['label' => 'Marriage', 'icon' => 'heart'],
                    ];
                    foreach ($filterTabs as $key => $filterMeta):
                    ?>
                    <a href="<?= buildRecordsUrl(['type' => $key, 'page' => 1, 'q' => $search ?: null]) ?>"
                       class="filter-chip filter-chip--with-icon whitespace-nowrap shrink-0 <?= $type === $key ? 'bg-white shadow-sm text-blue-600' : 'text-gray-400 hover:text-gray-600' ?>">
                        <?= lucideSvg($filterMeta['icon'], 'records-filter-icon shrink-0') ?>
                        <span><?= htmlspecialchars($filterMeta['label']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="w-full lg:w-auto bg-blue-600 text-white px-4 py-2 rounded-lg text-xs font-bold shrink-0" data-loading-text="Searching…">Search</button>
            </form>

            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <?php if (empty($records)): ?>
                <div class="p-16 text-center">
                    <div class="bg-gray-50 p-4 rounded-xl w-fit mx-auto mb-4 text-gray-200"><?= lucideSvg('book-open', 'w-10 h-10') ?></div>
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
                        ?>
                        <tr class="hover:bg-gray-50/50 transition-colors records-table-row">
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
                                                <?php if (!empty($r['book_number']) || !empty($r['page_number'])): ?>
                                                <span class="text-[9px] bg-amber-50 px-1.5 py-0.5 rounded text-amber-700 font-bold">Bk <?= htmlspecialchars((string) ($r['book_number'] ?? '—')) ?> · Pg <?= htmlspecialchars((string) ($r['page_number'] ?? '—')) ?></span>
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
                                <div class="manage-row-actions" onclick="event.stopPropagation()">
                                    <div class="manage-print-menu">
                                        <button type="button"
                                                class="manage-row-action manage-row-action--labeled manage-row-action--print manage-print-trigger"
                                                title="Print options"
                                                aria-label="Print options for <?= htmlspecialchars(civilRecordDisplayName($r)) ?>"
                                                aria-haspopup="true"
                                                aria-expanded="false">
                                            <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                                            <span class="manage-row-action__label">PRINT</span>
                                            <i data-lucide="chevron-down" class="w-3 h-3 manage-print-trigger__chevron"></i>
                                        </button>
                                        <div class="manage-print-dropdown hidden" role="menu">
                                            <a href="<?= htmlspecialchars(buildAuthUrl('print_certificate.php', ['record_id' => (int) $r['id']])) ?>"
                                               role="menuitem">Local Certificate</a>
                                            <a href="<?= htmlspecialchars(buildAuthUrl('print_certificate.php', ['record_id' => (int) $r['id'], 'kind' => 'certification'])) ?>"
                                               role="menuitem">Certification</a>
                                        </div>
                                    </div>
                                    <button type="button" class="view-record-btn manage-row-action" title="View" aria-label="View record"
                                            data-record="<?= htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </button>
                                    <a href="<?= buildRecordsUrl(['edit' => $r['id']]) ?>" class="manage-row-action" title="Edit" aria-label="Edit record"><i data-lucide="edit-3" class="w-4 h-4"></i></a>
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
    $entryFormReadOnly = $editRecord && $editLockBlocked;
    $modalTitle = $editRecord
        ? 'Edit Civil Record'
        : 'Add ' . civilRecordTypeLabel($modalRecord['record_type'] ?? ($type !== 'all' ? $type : 'birth')) . ' Record';
    $submitAction = $editRecord ? 'update' : 'create';
    $defaultRecordType = $modalRecord['record_type'] ?? ($type !== 'all' ? $type : 'birth');
    $entryFormEditMode = (bool) $editRecord;
    ?>
    <div class="records-entry-modal hidden" id="entryModal">
        <div class="records-entry-dialog">
            <div class="records-entry-header">
                <h2 class="text-lg font-black text-slate-900" id="entryModalTitle"><?= htmlspecialchars($modalTitle) ?></h2>
                <button type="button" class="text-gray-400 hover:text-gray-600 close-modal"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form method="POST" class="records-entry-form" id="entryForm">
                <?= authFormField() ?>
                <input type="hidden" name="action" id="entryAction" value="<?= $submitAction ?>">
                <?php if ($editRecord): ?><input type="hidden" name="record_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
                <input type="hidden" name="record_type" id="recordTypeInput" value="<?= htmlspecialchars($defaultRecordType) ?>">

                <?php if ($entryFormReadOnly): ?>
                <div class="records-edit-lock-banner" role="alert">
                    <strong>Record locked.</strong>
                    <?= htmlspecialchars($editLockBlocked['locked_by'] ?? 'Another staff member') ?> is editing this record right now. Close this form and try again in a few minutes.
                </div>
                <?php endif; ?>

                <div class="records-entry-scroll space-y-5">
                <fieldset class="records-entry-fieldset" <?= $entryFormReadOnly ? 'disabled' : '' ?>>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">Record Type</label>
                        <?php if ($entryFormEditMode): ?>
                        <p class="text-[11px] text-slate-500 mb-2">This is a <strong><?= htmlspecialchars(civilRecordTypeLabel($defaultRecordType)) ?></strong> record. Type cannot be changed while editing.</p>
                        <?php endif; ?>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 <?= $entryFormEditMode ? 'record-type-tabs--locked' : '' ?>" id="recordTypeTabs" <?= $entryFormEditMode ? 'data-record-type-locked="1"' : '' ?>>
                            <?php foreach (['birth' => ['label' => 'Birth', 'icon' => 'baby', 'active' => 'border-blue-500 bg-blue-50 text-blue-700'], 'death' => ['label' => 'Death', 'icon' => 'activity', 'active' => 'border-slate-400 bg-slate-50 text-slate-700'], 'marriage' => ['label' => 'Marriage', 'icon' => 'heart', 'active' => 'border-pink-400 bg-pink-50 text-pink-700']] as $t => $meta): ?>
                            <button type="button" data-record-type="<?= $t ?>"
                                class="record-type-tab rounded-xl border-2 px-3 py-3 text-center transition <?= $defaultRecordType === $t ? $meta['active'] : 'border-gray-200 text-gray-500 hover:border-gray-300' ?>"
                                <?= $entryFormEditMode ? 'disabled aria-disabled="true"' : '' ?>>
                                <?= lucideSvg($meta['icon'], 'records-type-tab-icon mx-auto mb-1') ?>
                                <span class="text-xs font-bold"><?= $meta['label'] ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php $birthPanelRecord = ($entryFormEditMode && $defaultRecordType !== 'birth') ? [] : $modalRecord; ?>
                    <div id="birthFieldsPanel" class="entry-detail-panel space-y-5 <?= $defaultRecordType === 'birth' ? '' : 'hidden' ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">First Name *</label>
                                <input type="text" name="first_name" id="birthFirstName" value="<?= htmlspecialchars($birthPanelRecord['first_name'] ?? '') ?>" placeholder="Juan" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Middle Name</label>
                                <input type="text" name="middle_name" id="birthMiddleName" value="<?= htmlspecialchars($birthPanelRecord['middle_name'] ?? '') ?>" placeholder="Dela" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Last Name *</label>
                                <input type="text" name="last_name" id="birthLastName" value="<?= htmlspecialchars($birthPanelRecord['last_name'] ?? '') ?>" placeholder="Cruz" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Registry Number</label>
                                <input type="text" name="registry_number" value="<?= htmlspecialchars($birthPanelRecord['registry_number'] ?? '') ?>" placeholder="e.g. 2024-0001" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Book Number</label>
                                <input type="text" name="book_number" value="<?= htmlspecialchars($birthPanelRecord['book_number'] ?? '') ?>" placeholder="e.g. 12" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Page Number</label>
                                <input type="text" name="page_number" value="<?= htmlspecialchars($birthPanelRecord['page_number'] ?? '') ?>" placeholder="e.g. 45" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Birth</label>
                                <input type="date" name="birth_date" value="<?= htmlspecialchars($birthPanelRecord['birth_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Time of Birth</label>
                                <input type="text" name="birth_time" value="<?= htmlspecialchars($birthPanelRecord['birth_time'] ?? '') ?>" placeholder="e.g. 10:30 AM" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Sex</label>
                                <select name="sex" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    <?php foreach (['Male', 'Female'] as $sex): ?>
                                    <option value="<?= $sex ?>" <?= ($birthPanelRecord['sex'] ?? 'Male') === $sex ? 'selected' : '' ?>><?= $sex ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Birth</label>
                            <input type="text" name="place" value="<?= htmlspecialchars($birthPanelRecord['place'] ?? '') ?>" placeholder="Barangay, City/Municipality, Province" class="<?= htmlspecialchars(cascadingLocationInputClass('w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm')) ?>" data-location-mode="ph_birth_place">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Type of Birth</label>
                                <select name="birth_type" id="birthTypeSelect" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    <?php foreach (['Single', 'Twin', 'Triplet', 'Other'] as $birthType): ?>
                                    <option value="<?= $birthType ?>" <?= ($birthPanelRecord['birth_type'] ?? 'Single') === $birthType ? 'selected' : '' ?>><?= $birthType ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Birth Order</label>
                                <input type="text" name="birth_order" value="<?= htmlspecialchars($birthPanelRecord['birth_order'] ?? '') ?>" placeholder="First" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Weight at Birth</label>
                                <input type="text" name="birth_weight" value="<?= htmlspecialchars($birthPanelRecord['birth_weight'] ?? '') ?>" placeholder="e.g. 3.2 kg" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Registration</label>
                                <input type="date" name="registration_date" value="<?= htmlspecialchars($birthPanelRecord['registration_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>

                        <div id="singleBirthDetails" class="space-y-4">
                            <div class="rounded-xl border border-blue-100 bg-blue-50/40 p-4 space-y-4">
                                <p class="text-[10px] font-black text-blue-700 uppercase tracking-wider flex items-center gap-2">
                                    <i data-lucide="user" class="w-4 h-4"></i> Mother's Information (Her)
                                </p>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Maiden Name (Full)</label>
                                    <input type="text" name="mother_name" value="<?= htmlspecialchars($birthPanelRecord['mother_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Age</label>
                                        <input type="number" name="mother_age" min="0" value="<?= htmlspecialchars((string) ($birthPanelRecord['mother_age'] ?? '')) ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Nationality</label>
                                        <input type="text" name="mother_nationality" value="<?= htmlspecialchars($birthPanelRecord['mother_nationality'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Religion</label>
                                        <input type="text" name="mother_religion" value="<?= htmlspecialchars($birthPanelRecord['mother_religion'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Occupation</label>
                                        <input type="text" name="mother_occupation" value="<?= htmlspecialchars($birthPanelRecord['mother_occupation'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Residence</label>
                                        <input type="text" name="mother_residence" value="<?= htmlspecialchars($birthPanelRecord['mother_residence'] ?? '') ?>" placeholder="Barangay, City/Municipality, Province, Country" class="<?= htmlspecialchars(cascadingLocationInputClass('w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm')) ?>"<?= cascadingLocationDataAttributes('mother_residence') ?>>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Children Born Alive</label>
                                        <input type="text" name="mother_children_born_alive" value="<?= htmlspecialchars($birthPanelRecord['mother_children_born_alive'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Still Living</label>
                                        <input type="text" name="mother_children_still_living" value="<?= htmlspecialchars($birthPanelRecord['mother_children_still_living'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Born Alive but Now Dead</label>
                                        <input type="text" name="mother_children_born_alive_now_dead" value="<?= htmlspecialchars($birthPanelRecord['mother_children_born_alive_now_dead'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 space-y-4">
                                <p class="text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <i data-lucide="user" class="w-4 h-4"></i> Father's Information
                                </p>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Full Name</label>
                                    <input type="text" name="father_name" value="<?= htmlspecialchars($birthPanelRecord['father_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Age</label>
                                        <input type="number" name="father_age" min="0" value="<?= htmlspecialchars((string) ($birthPanelRecord['father_age'] ?? '')) ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Nationality</label>
                                        <input type="text" name="father_nationality" value="<?= htmlspecialchars($birthPanelRecord['father_nationality'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Religion</label>
                                        <input type="text" name="father_religion" value="<?= htmlspecialchars($birthPanelRecord['father_religion'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Occupation</label>
                                        <input type="text" name="father_occupation" value="<?= htmlspecialchars($birthPanelRecord['father_occupation'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Residence</label>
                                        <input type="text" name="father_residence" value="<?= htmlspecialchars($birthPanelRecord['father_residence'] ?? '') ?>" placeholder="Barangay, City/Municipality, Province, Country" class="<?= htmlspecialchars(cascadingLocationInputClass('w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm')) ?>"<?= cascadingLocationDataAttributes('father_residence') ?>>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-xl border border-emerald-100 bg-emerald-50/40 p-4 space-y-4">
                                <p class="text-[10px] font-black text-emerald-700 uppercase tracking-wider">Marriage of Parents</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date</label>
                                        <input type="date" name="parents_marriage_date" value="<?= htmlspecialchars($birthPanelRecord['parents_marriage_date'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place (City/Municipality, Province, Country)</label>
                                        <input type="text" name="parents_marriage_place" value="<?= htmlspecialchars($birthPanelRecord['parents_marriage_place'] ?? '') ?>" placeholder="City/Municipality, Province, Country" class="<?= htmlspecialchars(cascadingLocationInputClass('w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm')) ?>"<?= cascadingLocationDataAttributes('parents_marriage_place') ?>>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Remarks / Notes</label>
                            <textarea name="notes" rows="2" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm resize-y"><?= htmlspecialchars($birthPanelRecord['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <?php $deathPanelRecord = ($entryFormEditMode && $defaultRecordType !== 'death') ? [] : $modalRecord; ?>

                    <div id="deathFieldsPanel" class="entry-detail-panel space-y-5 <?= $defaultRecordType === 'death' ? '' : 'hidden' ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">First Name *</label>
                                <input type="text" name="first_name" id="deathFirstName" value="<?= htmlspecialchars($deathPanelRecord['first_name'] ?? '') ?>" placeholder="Maria" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Middle Name</label>
                                <input type="text" name="middle_name" id="deathMiddleName" value="<?= htmlspecialchars($deathPanelRecord['middle_name'] ?? '') ?>" placeholder="Optional" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Last Name *</label>
                                <input type="text" name="last_name" id="deathLastName" value="<?= htmlspecialchars($deathPanelRecord['last_name'] ?? '') ?>" placeholder="Santos" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Registry Number</label>
                                <input type="text" name="registry_number" value="<?= htmlspecialchars($deathPanelRecord['registry_number'] ?? '') ?>" placeholder="e.g. 2024-0001" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Book Number</label>
                                <input type="text" name="book_number" value="<?= htmlspecialchars($deathPanelRecord['book_number'] ?? '') ?>" placeholder="e.g. 12" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Page Number</label>
                                <input type="text" name="page_number" value="<?= htmlspecialchars($deathPanelRecord['page_number'] ?? '') ?>" placeholder="e.g. 45" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Birth</label>
                                <input type="date" name="birth_date" value="<?= htmlspecialchars($deathPanelRecord['birth_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Registration</label>
                                <input type="date" name="registration_date" value="<?= htmlspecialchars($deathPanelRecord['registration_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Sex</label>
                                <select name="sex" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    <?php foreach (['Male', 'Female'] as $sex): ?>
                                    <option value="<?= $sex ?>" <?= ($deathPanelRecord['sex'] ?? 'Male') === $sex ? 'selected' : '' ?>><?= $sex ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Residence of Deceased</label>
                            <input type="text" name="residence_deceased" value="<?= htmlspecialchars($deathPanelRecord['residence_deceased'] ?? '') ?>" placeholder="Barangay, City/Municipality, Province, Country" class="<?= htmlspecialchars(cascadingLocationInputClass('w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm')) ?>"<?= cascadingLocationDataAttributes('residence_deceased') ?>>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Length of Residence (Place of Death)</label>
                                <input type="text" name="residence_length_place" value="<?= htmlspecialchars($deathPanelRecord['residence_length_place'] ?? '') ?>" placeholder="e.g. 5 years" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Length of Residence (Philippines)</label>
                                <input type="text" name="residence_length_ph" value="<?= htmlspecialchars($deathPanelRecord['residence_length_ph'] ?? '') ?>" placeholder="e.g. Lifetime" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Nationality</label>
                                <input type="text" name="nationality" value="<?= htmlspecialchars($deathPanelRecord['nationality'] ?? 'Filipino') ?>" placeholder="e.g. Filipino" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Civil Status</label>
                                <input type="text" name="civil_status" value="<?= htmlspecialchars($deathPanelRecord['civil_status'] ?? '') ?>" placeholder="e.g. Married, Single" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Religion</label>
                                <input type="text" name="religion" value="<?= htmlspecialchars($deathPanelRecord['religion'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Death</label>
                            <input type="text" name="place" value="<?= htmlspecialchars($deathPanelRecord['place'] ?? '') ?>" placeholder="Hospital / Institution / Address" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 space-y-4">
                            <p class="text-[10px] font-black text-slate-700 uppercase tracking-wider">Age at Death</p>
                            <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 items-end">
                                <?php foreach (['years' => 'Years', 'months' => 'Months', 'days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Min'] as $unit => $label): ?>
                                <div>
                                    <label class="block text-[9px] font-bold text-gray-500 uppercase mb-1"><?= $label ?></label>
                                    <input type="number" min="0" name="age_death_<?= $unit ?>" value="<?= htmlspecialchars((string) ($deathPanelRecord['age_death_' . $unit] ?? '')) ?>" class="w-full bg-white border border-gray-200 rounded-xl px-3 py-2 text-sm">
                                </div>
                                <?php endforeach; ?>
                                <div class="flex items-center pb-2">
                                    <label class="inline-flex items-center gap-2 text-[10px] font-bold text-gray-700 uppercase cursor-pointer">
                                        <input type="checkbox" name="stillbirth" value="1" class="rounded border-gray-300 text-blue-600" <?= !empty($deathPanelRecord['stillbirth']) ? 'checked' : '' ?>>
                                        Still-birth
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Occupation</label>
                                <input type="text" name="occupation" value="<?= htmlspecialchars($deathPanelRecord['occupation'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Burial</label>
                                <input type="text" name="place_of_burial" value="<?= htmlspecialchars($deathPanelRecord['place_of_burial'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Name of Surviving Spouse</label>
                                <input type="text" name="surviving_spouse_name" value="<?= htmlspecialchars($deathPanelRecord['surviving_spouse_name'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Address of Surviving Spouse</label>
                                <input type="text" name="surviving_spouse_address" value="<?= htmlspecialchars($deathPanelRecord['surviving_spouse_address'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 space-y-4">
                            <p class="text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="activity" class="w-4 h-4"></i> Death Details
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Death</label>
                                    <input type="date" name="death_date" value="<?= htmlspecialchars($deathPanelRecord['event_date'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Time of Death</label>
                                    <input type="text" name="death_time" value="<?= htmlspecialchars($deathPanelRecord['death_time'] ?? '') ?>" placeholder="e.g. 10:30" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Period</label>
                                    <select name="death_time_period" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                        <?php foreach (['A.M.', 'P.M.'] as $period): ?>
                                        <option value="<?= $period ?>" <?= ($deathPanelRecord['death_time_period'] ?? 'A.M.') === $period ? 'selected' : '' ?>><?= $period ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Immediate Cause of Death</label>
                                <input type="text" name="immediate_cause" value="<?= htmlspecialchars($deathPanelRecord['immediate_cause'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Contributory Cause</label>
                                <input type="text" name="contributory_cause" value="<?= htmlspecialchars($deathPanelRecord['contributory_cause'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <?php foreach (['a', 'b', 'c', 'd', 'e'] as $letter): ?>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Infant Cause <?= strtoupper($letter) ?></label>
                                <input type="text" name="infant_cause_<?= $letter ?>" value="<?= htmlspecialchars($deathPanelRecord['infant_cause_' . $letter] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <?php endforeach; ?>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Postmortem Cause</label>
                                <input type="text" name="postmortem_cause" value="<?= htmlspecialchars($deathPanelRecord['postmortem_cause'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Attending Physician</label>
                                <input type="text" name="attending_physician" value="<?= htmlspecialchars($deathPanelRecord['attending_physician'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Autopsy Performed?</label>
                                <select name="autopsy_performed" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                    <?php foreach (['No', 'Yes'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= ($deathPanelRecord['autopsy_performed'] ?? 'No') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Code Number (System Only)</label>
                                <input type="text" name="code_number" value="<?= htmlspecialchars($deathPanelRecord['code_number'] ?? $deathPanelRecord['registry_number'] ?? '') ?>" readonly class="w-full bg-gray-100 border border-gray-200 rounded-xl px-4 py-2.5 text-sm text-gray-500">
                            </div>
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 space-y-4">
                            <p class="text-[10px] font-black text-slate-700 uppercase tracking-wider">Parents of Deceased</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Father's Name</label>
                                    <input type="text" name="father_name" value="<?= htmlspecialchars($deathPanelRecord['father_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Mother's Name</label>
                                    <input type="text" name="mother_name" value="<?= htmlspecialchars($deathPanelRecord['mother_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-violet-100 bg-violet-50/40 p-4 space-y-4">
                            <p class="text-[10px] font-black text-violet-800 uppercase tracking-wider">Infant Details (0–7 Days)</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Age of Mother</label>
                                    <input type="text" name="child_age_mother" value="<?= htmlspecialchars($deathPanelRecord['child_age_mother'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Method of Delivery</label>
                                    <input type="text" name="child_delivery_method" value="<?= htmlspecialchars($deathPanelRecord['child_delivery_method'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Length of Pregnancy</label>
                                    <input type="text" name="child_pregnancy_length" value="<?= htmlspecialchars($deathPanelRecord['child_pregnancy_length'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Type of Birth</label>
                                    <input type="text" name="child_birth_type" value="<?= htmlspecialchars($deathPanelRecord['child_birth_type'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Birth Order (Infant)</label>
                                    <input type="text" name="child_birth_order_infant" value="<?= htmlspecialchars($deathPanelRecord['child_birth_order_infant'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Remarks / Notes</label>
                            <textarea name="notes" rows="2" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm resize-y"><?= htmlspecialchars($deathPanelRecord['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <?php $marriagePanelRecord = ($entryFormEditMode && $defaultRecordType !== 'marriage') ? [] : $modalRecord; ?>

                    <div id="marriageFieldsPanel" class="entry-detail-panel space-y-5 <?= $defaultRecordType === 'marriage' ? '' : 'hidden' ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Registry Number</label>
                                <input type="text" name="registry_number" value="<?= htmlspecialchars($marriagePanelRecord['registry_number'] ?? '') ?>" placeholder="e.g. 2024-0001" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Book Number</label>
                                <input type="text" name="book_number" value="<?= htmlspecialchars($marriagePanelRecord['book_number'] ?? '') ?>" placeholder="e.g. 12" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Page Number</label>
                                <input type="text" name="page_number" value="<?= htmlspecialchars($marriagePanelRecord['page_number'] ?? '') ?>" placeholder="e.g. 45" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
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
                                <input type="text" name="<?= $prefix ?>_name" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Date of Birth</label>
                                    <input type="date" name="<?= $prefix ?>_birth_date" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_birth_date'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Age</label>
                                    <input type="number" min="0" name="<?= $prefix ?>_age" value="<?= htmlspecialchars((string) ($marriagePanelRecord[$prefix . '_age'] ?? '')) ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Birth</label>
                                    <input type="text" name="<?= $prefix ?>_birth_place" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_birth_place'] ?? '') ?>" placeholder="Barangay, City/Municipality, Province" class="<?= htmlspecialchars(cascadingLocationInputClass('w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm')) ?>" data-location-mode="ph_birth_place">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Citizenship</label>
                                    <input type="text" name="<?= $prefix ?>_citizenship" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_citizenship'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Religion</label>
                                    <input type="text" name="<?= $prefix ?>_religion" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_religion'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Civil Status</label>
                                    <input type="text" name="<?= $prefix ?>_civil_status" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_civil_status'] ?? 'Single') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Residence</label>
                                    <input type="text" name="<?= $prefix ?>_residence" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_residence'] ?? '') ?>" placeholder="Barangay, City/Municipality, Province, Country" class="<?= htmlspecialchars(cascadingLocationInputClass('w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm')) ?>"<?= cascadingLocationDataAttributes($prefix . '_residence') ?>>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Father's Full Name</label>
                                    <input type="text" name="<?= $prefix ?>_father_name" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_father_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Mother's Maiden Name</label>
                                    <input type="text" name="<?= $prefix ?>_mother_maiden_name" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_mother_maiden_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Father Citizenship</label>
                                    <input type="text" name="<?= $prefix ?>_father_citizenship" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_father_citizenship'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Mother Citizenship</label>
                                    <input type="text" name="<?= $prefix ?>_mother_citizenship" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_mother_citizenship'] ?? 'Filipino') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Consent Person Name</label>
                                    <input type="text" name="<?= $prefix ?>_consent_person_name" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_consent_person_name'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Consent Relationship</label>
                                    <input type="text" name="<?= $prefix ?>_consent_relationship" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_consent_relationship'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Consent Residence</label>
                                    <input type="text" name="<?= $prefix ?>_consent_residence" value="<?= htmlspecialchars($marriagePanelRecord[$prefix . '_consent_residence'] ?? '') ?>" placeholder="Barangay, City/Municipality, Province, Country" class="<?= htmlspecialchars(cascadingLocationInputClass('w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm')) ?>"<?= cascadingLocationDataAttributes($prefix . '_consent_residence') ?>>
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
                                    <input type="date" name="marriage_date" value="<?= htmlspecialchars($marriagePanelRecord['event_date'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Time of Marriage</label>
                                    <input type="text" name="marriage_time" value="<?= htmlspecialchars($marriagePanelRecord['marriage_time'] ?? '') ?>" placeholder="e.g. 09:00 AM" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Place of Marriage</label>
                                    <input type="text" name="marriage_place" value="<?= htmlspecialchars($marriagePanelRecord['place'] ?? '') ?>" placeholder="Church / Office / Barangay" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Solemnized By (Name)</label>
                                    <input type="text" name="solemnized_by" value="<?= htmlspecialchars($marriagePanelRecord['solemnized_by'] ?? '') ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Witnesses</label>
                            <textarea name="witnesses" rows="3" placeholder="List of witnesses (Name, Residence)" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm resize-y"><?= htmlspecialchars($marriagePanelRecord['witnesses'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1">Remarks / Notes</label>
                            <textarea name="notes" rows="2" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm resize-y"><?= htmlspecialchars($marriagePanelRecord['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <?php foreach ($validTypes as $fillType): ?>
                        <?php renderRecordEntryPrintFillSection($pdo, $fillType, $modalRecord, $defaultRecordType === $fillType); ?>
                    <?php endforeach; ?>
                </fieldset>
                </div>

                <div class="records-entry-footer">
                    <button type="button" class="border border-gray-200 rounded-xl py-3 px-4 text-sm font-bold text-gray-600 hover:bg-gray-50 close-modal sm:flex-1"><?= $entryFormReadOnly ? 'Close' : 'Cancel' ?></button>
                    <?php if (!$entryFormReadOnly): ?>
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 text-sm font-bold inline-flex items-center justify-center gap-2">
                        <i data-lucide="save" class="w-4 h-4"></i> <?= $editRecord ? 'Update Record' : 'Add Record' ?>
                    </button>
                    <?php endif; ?>
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
                <div class="records-view-print-group">
                    <a href="#" id="viewPrintCertificateLink" class="records-view-action records-view-action--print">Print Certificate</a>
                    <a href="#" id="viewPrintCertificationLink" class="records-view-action records-view-action--print-secondary">Print Certification</a>
                </div>
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
            <form method="POST" enctype="multipart/form-data" class="space-y-4" id="importForm" data-no-confirm data-no-loading action="<?= htmlspecialchars(buildAuthUrl('records.php')) ?>">
                <?= authFormField() ?>
                <input type="hidden" name="action" value="import_csv">
                <input type="hidden" name="import_type" id="importType" value="">
                <p class="text-xs text-gray-500" id="importColumnsHelp">Download the Excel template below, enter one record per row, then upload the CSV file. Large files (100k+ rows) are imported in batches in your browser so server upload limits do not apply. The sample row is skipped automatically.</p>
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-1">CSV File *</label>
                    <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-600 file:font-bold file:text-xs">
                </div>
                <a href="#" id="importTemplateLink" download class="text-blue-600 text-[10px] font-bold flex items-center hover:underline">
                    <i data-lucide="download" class="w-3.5 h-3.5 mr-2"></i> Download Excel Template
                </a>
                <div id="importProgressWrap" class="hidden space-y-2 pt-1" aria-live="polite">
                    <p id="importProgressText" class="text-xs font-semibold text-slate-600">Preparing import…</p>
                    <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                        <div id="importProgressBar" class="h-full bg-blue-600 transition-all duration-200" style="width:0%"></div>
                    </div>
                </div>
                <div class="flex gap-3 pt-2" id="importFormActions">
                    <button type="submit" id="importSubmitBtn" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 text-sm font-bold">Import Records</button>
                    <button type="button" class="flex-1 border border-gray-200 rounded-xl py-3 text-sm font-bold text-gray-600 hover:bg-gray-50 close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <?= actionResultScript($flash) ?>
    <?= pageConfigJson([
        'recordsAuthUrl' => buildAuthUrl('records.php'),
        'printCertificateUrl' => buildAuthUrl('print_certificate.php'),
        'printCertificationUrl' => buildAuthUrl('print_certificate.php', ['kind' => 'certification']),
        'recordViewSections' => [
            'birth' => civilRecordViewSections('birth'),
            'death' => civilRecordViewSections('death'),
            'marriage' => civilRecordViewSections('marriage'),
        ],
        'printFillFieldLabels' => [
            'birth' => printFillFieldLabelsForType($pdo, 'birth'),
            'death' => printFillFieldLabelsForType($pdo, 'death'),
            'marriage' => printFillFieldLabelsForType($pdo, 'marriage'),
        ],
        'openEntryModal' => (bool) ($showModal || $editRecord),
        'defaultEntryType' => $defaultRecordType,
        'editRecordId' => $editRecord ? (int) $editRecord['id'] : null,
        'lockRecordType' => $entryFormEditMode ?? false,
        'editLockHeld' => $editLockHeld,
        'editLockBlocked' => $editLockBlocked,
        'recordsLockApiUrl' => buildAuthUrl('api/records.php'),
        'recordsImportApiUrl' => buildAuthUrl('api/records_import.php'),
        'locationsApiUrl' => buildAuthUrl('api/locations.php'),
    ], 'records-config') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('core/admin-search.js') ?>
    <?= scriptTag('core/cascading-location.js') ?>
    <?= scriptTag('admin/records.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
