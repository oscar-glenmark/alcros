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

$validTypes = ['birth', 'death', 'marriage'];
$validSorts = ['name' => 'person_name', 'type' => 'record_type', 'date' => 'COALESCE(event_date, birth_date)', 'created' => 'created_at'];

function buildRecordsUrl(array $overrides = []): string
{
    $params = array_merge([
        'type' => $_GET['type'] ?? 'all',
        'q'    => $_GET['q'] ?? '',
        'view' => $_GET['view'] ?? 'active',
        'sort' => $_GET['sort'] ?? 'name',
        'dir'  => $_GET['dir'] ?? 'asc',
        'page' => (int) ($_GET['page'] ?? 1),
    ], $overrides);
    foreach (['type', 'view', 'q', 'sort', 'dir', 'page', 'edit'] as $key) {
        if ($key === 'type' && ($params['type'] ?? '') === 'all') unset($params['type']);
        elseif ($key === 'view' && ($params['view'] ?? '') === 'active') unset($params['view']);
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
        'view' => ($_GET['view'] ?? 'active') === 'deleted' ? 'deleted' : 'active',
        'sort' => $_GET['sort'] ?? 'name',
        'dir'  => strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
        'page' => max(1, (int) ($_GET['page'] ?? 1)),
    ];
}

function buildRecordsWhere(array $filters): array
{
    $where  = $filters['view'] === 'deleted' ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
    $params = [];

    if ($filters['type'] !== 'all' && in_array($filters['type'], ['birth', 'death', 'marriage'], true)) {
        $where .= ' AND record_type = ?';
        $params[] = $filters['type'];
    }
    if ($filters['q'] !== '') {
        $where .= ' AND (person_name LIKE ? OR registry_number LIKE ? OR father_name LIKE ? OR mother_name LIKE ? OR place LIKE ? OR notes LIKE ? OR CAST(id AS CHAR) LIKE ?)';
        $term = '%' . $filters['q'] . '%';
        array_push($params, $term, $term, $term, $term, $term, $term, $term);
    }

    return [$where, $params];
}

function recordInitial(string $name): string
{
    return strtoupper(substr(trim($name), 0, 1));
}

function parseCsvRecordRow(array $row, string $importType): ?array
{
    if (count($row) < 2) {
        return null;
    }
    $data = array_pad($row, 8, null);
    $personName = trim((string) ($data[1] ?? ''));
    if ($personName === '') {
        return null;
    }

    return [
        'record_type'      => $importType,
        'registry_number'  => trim((string) ($data[0] ?? '')) ?: null,
        'person_name'      => $personName,
        'birth_date'       => trim((string) ($data[2] ?? '')) ?: null,
        'event_date'       => trim((string) ($data[3] ?? '')) ?: null,
        'place'            => trim((string) ($data[4] ?? '')) ?: null,
        'father_name'      => trim((string) ($data[5] ?? '')) ?: null,
        'mother_name'      => trim((string) ($data[6] ?? '')) ?: null,
        'notes'            => trim((string) ($data[7] ?? '')) ?: null,
    ];
}

function normalizeRecordInput(array $input): array
{
    global $validTypes;
    $type = $input['record_type'] ?? '';
    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException('Invalid record type.');
    }
    $name = trim($input['person_name'] ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('Person name is required.');
    }

    return [
        'record_type'     => $type,
        'registry_number' => trim($input['registry_number'] ?? '') ?: null,
        'person_name'     => $name,
        'birth_date'      => trim($input['birth_date'] ?? '') ?: null,
        'event_date'      => trim($input['event_date'] ?? '') ?: null,
        'place'           => trim($input['place'] ?? '') ?: null,
        'father_name'     => trim($input['father_name'] ?? '') ?: null,
        'mother_name'     => trim($input['mother_name'] ?? '') ?: null,
        'notes'           => trim($input['notes'] ?? '') ?: null,
    ];
}

// CSV template download
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    $tplType = $_GET['type'] ?? '';
    $filename = in_array($tplType, $validTypes, true)
        ? "alcros_{$tplType}_template.csv"
        : 'alcros_records_template.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['registry_number', 'person_name', 'birth_date', 'event_date', 'place', 'father_name', 'mother_name', 'notes']);
    fclose($out);
    exit;
}

// Export filtered records
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $filters = currentRecordsFilters();
    [$where, $params] = buildRecordsWhere($filters);
    $stmt = $pdo->prepare("SELECT * FROM civil_records WHERE $where ORDER BY person_name ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="alcros_civil_records_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'record_type', 'registry_number', 'person_name', 'birth_date', 'event_date', 'place', 'father_name', 'mother_name', 'notes', 'created_at']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'], $row['record_type'], $row['registry_number'], $row['person_name'],
            $row['birth_date'], $row['event_date'], $row['place'], $row['father_name'],
            $row['mother_name'], $row['notes'], $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $data = normalizeRecordInput($_POST);
            $stmt = $pdo->prepare(
                'INSERT INTO civil_records (record_type, registry_number, person_name, birth_date, event_date, place, father_name, mother_name, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(array_values($data));
            logActivity(staffId(), 'Record Created', 'New ' . $data['record_type'] . ' record: ' . $data['person_name']);
            recordsFlashSet('success', 'Record saved successfully.');
        } elseif ($action === 'update' && !empty($_POST['record_id'])) {
            $data = normalizeRecordInput($_POST);
            $id = (int) $_POST['record_id'];
            $stmt = $pdo->prepare(
                'UPDATE civil_records SET record_type = ?, registry_number = ?, person_name = ?, birth_date = ?, event_date = ?,
                 place = ?, father_name = ?, mother_name = ?, notes = ? WHERE id = ?'
            );
            $stmt->execute([...array_values($data), $id]);
            logActivity(staffId(), 'Record Updated', "Updated record #$id: {$data['person_name']}");
            recordsFlashSet('success', 'Record updated successfully.');
        } elseif ($action === 'delete' && !empty($_POST['record_id'])) {
            $id = (int) $_POST['record_id'];
            $pdo->prepare('UPDATE civil_records SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
            logActivity(staffId(), 'Record Deleted', 'Soft-deleted record #' . $id);
            recordsFlashSet('success', 'Record moved to Recently Deleted.');
        } elseif ($action === 'restore' && !empty($_POST['record_id'])) {
            $id = (int) $_POST['record_id'];
            $pdo->prepare('UPDATE civil_records SET deleted_at = NULL WHERE id = ?')->execute([$id]);
            logActivity(staffId(), 'Record Restored', 'Restored record #' . $id);
            recordsFlashSet('success', 'Record restored successfully.');
        } elseif (in_array($action, ['bulk_delete', 'bulk_restore'], true)) {
            $ids = array_filter(array_map('intval', $_POST['record_ids'] ?? []));
            if (empty($ids)) {
                throw new InvalidArgumentException('No records selected.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'bulk_delete') {
                $pdo->prepare("UPDATE civil_records SET deleted_at = NOW() WHERE id IN ($placeholders)")->execute($ids);
                logActivity(staffId(), 'Bulk Record Delete', count($ids) . ' records soft-deleted');
                recordsFlashSet('success', count($ids) . ' record(s) moved to Recently Deleted.');
            } else {
                $pdo->prepare("UPDATE civil_records SET deleted_at = NULL WHERE id IN ($placeholders)")->execute($ids);
                logActivity(staffId(), 'Bulk Record Restore', count($ids) . ' records restored');
                recordsFlashSet('success', count($ids) . ' record(s) restored.');
            }
        } elseif ($action === 'import_csv' && !empty($_FILES['csv_file']['tmp_name'])) {
            $importType = $_POST['import_type'] ?? '';
            if (!in_array($importType, $validTypes, true)) {
                throw new InvalidArgumentException('Invalid import type.');
            }
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            fgetcsv($handle);
            $imported = 0;
            $skipped = 0;
            $stmt = $pdo->prepare(
                'INSERT INTO civil_records (record_type, registry_number, person_name, birth_date, event_date, place, father_name, mother_name, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            while (($row = fgetcsv($handle)) !== false) {
                $parsed = parseCsvRecordRow($row, $importType);
                if ($parsed === null) {
                    $skipped++;
                    continue;
                }
                $stmt->execute(array_values($parsed));
                $imported++;
            }
            fclose($handle);
            logActivity(staffId(), 'CSV Import', "Imported $imported $importType records");
            $msg = "Successfully imported $imported " . ucfirst($importType) . ' record(s).';
            if ($skipped > 0) {
                $msg .= " Skipped $skipped invalid row(s).";
            }
            recordsFlashSet('success', $msg);
        }
    } catch (InvalidArgumentException $e) {
        recordsFlashSet('error', $e->getMessage());
    } catch (PDOException $e) {
        recordsFlashSet('error', 'Could not complete the action. Please try again.');
    }

    redirectWithAuth('records.php', currentRecordsFilters());
}

$filters = currentRecordsFilters();
$view   = $filters['view'];
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
$deletedCount  = (int) $pdo->query('SELECT COUNT(*) FROM civil_records WHERE deleted_at IS NOT NULL')->fetchColumn();

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
        .import-banner { background-color: #eff6ff; border: 1px solid #dbeafe; }
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

        <div class="p-10 space-y-6">
            <div class="flex justify-between items-start">
                <div>
                    <a href="dashboard.php" class="text-blue-600 text-[11px] font-bold flex items-center mb-2 hover:underline">
                        <i data-lucide="chevron-left" class="w-3 h-3 mr-1"></i> Back to Dashboard
                    </a>
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight leading-none">Civil Records</h1>
                    <p class="text-gray-500 text-sm mt-2">Manage birth, death, and marriage registry entries with search, export, and bulk actions.</p>
                </div>
                <div class="flex space-x-3 items-start">
                    <a href="<?= buildRecordsUrl(['view' => $view === 'deleted' ? 'active' : 'deleted', 'page' => 1]) ?>"
                       class="border border-gray-200 text-slate-700 px-4 py-2 rounded-lg text-[11px] font-bold uppercase flex items-center bg-white shadow-sm hover:bg-gray-50 <?= $view === 'deleted' ? 'ring-2 ring-blue-500' : '' ?>">
                        <i data-lucide="history" class="w-4 h-4 mr-2"></i> Recently Deleted
                        <?php if ($deletedCount > 0): ?><span class="ml-2 bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded text-[9px]"><?= $deletedCount ?></span><?php endif; ?>
                    </a>
                    <a href="records.php?action=export&amp;<?= http_build_query(array_filter(['type' => $type !== 'all' ? $type : null, 'q' => $search ?: null, 'view' => $view !== 'active' ? $view : null])) ?>"
                       class="border border-gray-200 text-slate-700 px-4 py-2 rounded-lg text-[11px] font-bold uppercase flex items-center bg-white shadow-sm hover:bg-gray-50">
                        <i data-lucide="download" class="w-4 h-4 mr-2"></i> Export CSV
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

            <?php if ($view === 'deleted'): ?>
            <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-amber-700 text-xs font-semibold flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4"></i>
                Viewing recently deleted records. Restore items or switch back to active records.
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-3 gap-6">
                <?php foreach (['birth' => ['count' => $birthCount, 'icon' => 'users', 'bg' => 'bg-blue-50 text-blue-600'], 'death' => ['count' => $deathCount, 'icon' => 'activity', 'bg' => 'bg-gray-50 text-gray-400'], 'marriage' => ['count' => $marriageCount, 'icon' => 'heart', 'bg' => 'bg-pink-50 text-pink-500']] as $key => $meta): ?>
                <a href="<?= buildRecordsUrl(['type' => $key, 'view' => 'active', 'page' => 1]) ?>" class="stat-card bg-white p-6 rounded-xl border border-gray-100 shadow-sm block <?= $type === $key && $view === 'active' ? 'ring-2 ring-blue-500' : '' ?>">
                    <div class="<?= $meta['bg'] ?> p-2 rounded-lg w-fit mb-4"><i data-lucide="<?= $meta['icon'] ?>" class="w-5 h-5"></i></div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"><?= civilRecordTypeLabel($key) ?></p>
                    <p class="text-3xl font-black text-slate-900"><?= $meta['count'] ?></p>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="import-banner rounded-xl p-4 flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="bg-blue-200 text-blue-600 p-2 rounded-lg"><i data-lucide="upload-cloud" class="w-5 h-5"></i></div>
                    <div>
                        <p class="text-xs font-bold text-blue-900 leading-none">Need to import existing data?</p>
                        <p class="text-[10px] text-blue-600 mt-1">Download our CSV template to format your records correctly.</p>
                    </div>
                </div>
                <a href="records.php?action=template" class="text-blue-700 text-[10px] font-bold flex items-center hover:underline">
                    <i data-lucide="download" class="w-3.5 h-3.5 mr-2"></i> Download Template
                </a>
            </div>

            <div id="bulkBar" class="hidden bg-slate-900 text-white rounded-xl px-4 py-3 flex items-center justify-between">
                <span class="text-xs font-semibold"><span id="bulkCount">0</span> record(s) selected</span>
                <div class="flex gap-2">
                    <?php if ($view === 'deleted'): ?>
                    <form method="POST" id="bulkRestoreForm">
                        <input type="hidden" name="action" value="bulk_restore">
                        <div id="bulkRestoreIds"></div>
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase">Restore Selected</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" id="bulkDeleteForm" onsubmit="return confirm('Move selected records to Recently Deleted?');">
                        <input type="hidden" name="action" value="bulk_delete">
                        <div id="bulkDeleteIds"></div>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase">Delete Selected</button>
                    </form>
                    <?php endif; ?>
                    <button type="button" id="clearSelection" class="text-gray-300 hover:text-white text-[10px] font-bold uppercase">Clear</button>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3">
                    <div class="flex bg-gray-50 p-1 rounded-lg">
                        <?php foreach (['all' => 'All', 'birth' => 'Birth', 'death' => 'Death', 'marriage' => 'Marriage'] as $key => $label): ?>
                        <a href="<?= buildRecordsUrl(['type' => $key, 'page' => 1]) ?>"
                           class="filter-chip <?= $type === $key ? 'bg-white shadow-sm text-blue-600' : 'text-gray-400 hover:text-gray-600' ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                    <form method="GET" class="relative w-72">
                        <?php if ($view === 'deleted'): ?><input type="hidden" name="view" value="deleted"><?php endif; ?>
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
                <table class="w-full">
                    <thead class="bg-gray-50/50 border-b border-gray-100">
                        <tr>
                            <th class="p-4 text-left"><input type="checkbox" class="rounded text-blue-600" id="selectAll"></th>
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
                            <td class="p-4"><input type="checkbox" class="rounded row-check" value="<?= (int) $r['id'] ?>"></td>
                            <td class="p-4">
                                <button type="button" class="view-record-btn text-left w-full" data-record="<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-blue-100 rounded flex items-center justify-center text-blue-600 font-bold text-xs"><?= htmlspecialchars(recordInitial($r['person_name'])) ?></div>
                                        <div>
                                            <div class="flex items-center space-x-2">
                                                <span class="text-sm font-bold text-slate-800 hover:text-blue-600"><?= htmlspecialchars($r['person_name']) ?></span>
                                                <?php if ($r['registry_number']): ?>
                                                <span class="text-[9px] bg-gray-100 px-1.5 py-0.5 rounded text-gray-500 font-bold">#<?= htmlspecialchars($r['registry_number']) ?></span>
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
                                    <button type="button" class="view-record-btn text-gray-300 hover:text-blue-600" title="View" data-record="<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </button>
                                    <?php if ($view === 'deleted'): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="text-gray-300 hover:text-green-600" title="Restore"><i data-lucide="rotate-ccw" class="w-4 h-4"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <a href="<?= buildRecordsUrl(['edit' => $r['id']]) ?>" class="text-gray-300 hover:text-slate-600" title="Edit"><i data-lucide="edit-3" class="w-4 h-4"></i></a>
                                    <form method="POST" class="inline" onsubmit="return confirm('Move this record to Recently Deleted?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="text-gray-300 hover:text-red-500" title="Delete"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
    $modalRecord = $editRecord;
    $modalMode = $editRecord ? 'edit' : 'create';
    $modalTitle = $editRecord ? 'Edit Record' : 'Add Single Record';
    $submitAction = $editRecord ? 'update' : 'create';
    ?>
    <div class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4" id="entryModal">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-lg font-black text-slate-900" id="entryModalTitle"><?= htmlspecialchars($modalTitle) ?></h2>
                <button type="button" class="text-gray-400 hover:text-gray-600 close-modal"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form method="POST" class="space-y-4" id="entryForm">
                <input type="hidden" name="action" id="entryAction" value="<?= $submitAction ?>">
                <?php if ($editRecord): ?><input type="hidden" name="record_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-1">Record Type *</label>
                    <select name="record_type" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                        <?php foreach ($validTypes as $t): ?>
                        <option value="<?= $t ?>" <?= ($modalRecord['record_type'] ?? '') === $t ? 'selected' : '' ?>><?= civilRecordTypeLabel($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-1">Person Name *</label>
                    <input type="text" name="person_name" required value="<?= htmlspecialchars($modalRecord['person_name'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-1">Registry #</label>
                        <input type="text" name="registry_number" value="<?= htmlspecialchars($modalRecord['registry_number'] ?? '') ?>" placeholder="e.g. 2005" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-1">Place</label>
                        <input type="text" name="place" value="<?= htmlspecialchars($modalRecord['place'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-1">Birth Date</label>
                        <input type="date" name="birth_date" value="<?= htmlspecialchars($modalRecord['birth_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-1">Event Date</label>
                        <input type="date" name="event_date" value="<?= htmlspecialchars($modalRecord['event_date'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-1">Father's Name</label>
                        <input type="text" name="father_name" value="<?= htmlspecialchars($modalRecord['father_name'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-1">Mother's Name</label>
                        <input type="text" name="mother_name" value="<?= htmlspecialchars($modalRecord['mother_name'] ?? '') ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm"><?= htmlspecialchars($modalRecord['notes'] ?? '') ?></textarea>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 text-sm font-bold"><?= $editRecord ? 'Update Record' : 'Save Entry' ?></button>
                    <button type="button" class="flex-1 border border-gray-200 rounded-xl py-3 text-sm font-bold text-gray-600 hover:bg-gray-50 close-modal">Cancel</button>
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
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="import_csv">
                <input type="hidden" name="import_type" id="importType" value="">
                <p class="text-xs text-gray-500">Upload a CSV with columns: registry_number, person_name, birth_date, event_date, place, father_name, mother_name, notes</p>
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-1">CSV File *</label>
                    <input type="file" name="csv_file" accept=".csv" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-600 file:font-bold file:text-xs">
                </div>
                <a href="#" id="importTemplateLink" class="text-blue-600 text-[10px] font-bold flex items-center hover:underline">
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
        const bulkBar = document.getElementById('bulkBar');
        const bulkCount = document.getElementById('bulkCount');

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
            if (!val) return '—';
            const d = new Date(val + 'T00:00:00');
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function selectedIds() {
            return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
        }

        function updateBulkBar() {
            const ids = selectedIds();
            bulkCount.textContent = String(ids.length);
            bulkBar.classList.toggle('hidden', ids.length === 0);
            ['bulkDeleteIds', 'bulkRestoreIds'].forEach(containerId => {
                const container = document.getElementById(containerId);
                if (!container) return;
                container.innerHTML = ids.map(id => '<input type="hidden" name="record_ids[]" value="' + id + '">').join('');
            });
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
            openModal('entryModal');
        });

        document.querySelectorAll('[data-import-type]').forEach(btn => {
            btn.addEventListener('click', () => {
                const type = btn.dataset.importType;
                newEntryMenu.classList.add('hidden');
                document.getElementById('importType').value = type;
                document.getElementById('importModalTitle').textContent = 'Import ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Records';
                document.getElementById('importTemplateLink').href = 'records.php?action=template&type=' + type;
                openModal('importModal');
            });
        });

        document.querySelectorAll('.close-modal').forEach(btn => btn.addEventListener('click', closeAllModals));
        ['entryModal', 'importModal', 'viewModal'].forEach(id => {
            document.getElementById(id)?.addEventListener('click', (e) => {
                if (e.target === e.currentTarget) closeAllModals();
            });
        });

        document.getElementById('selectAll')?.addEventListener('change', function () {
            document.querySelectorAll('.row-check').forEach(cb => { cb.checked = this.checked; });
            updateBulkBar();
        });

        document.querySelectorAll('.row-check').forEach(cb => cb.addEventListener('change', updateBulkBar));
        document.getElementById('clearSelection')?.addEventListener('click', () => {
            document.querySelectorAll('.row-check, #selectAll').forEach(cb => { cb.checked = false; });
            updateBulkBar();
        });

        document.querySelectorAll('.view-record-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const r = JSON.parse(btn.dataset.record);
                const rows = [
                    ['Type', r.record_type],
                    ['Registry #', r.registry_number || '—'],
                    ['Person Name', r.person_name],
                    ['Birth Date', formatDate(r.birth_date)],
                    ['Event Date', formatDate(r.event_date)],
                    ['Place', r.place || '—'],
                    ['Father', r.father_name || '—'],
                    ['Mother', r.mother_name || '—'],
                    ['Notes', r.notes || '—'],
                    ['Created', formatDate((r.created_at || '').substring(0, 10))],
                ];
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
