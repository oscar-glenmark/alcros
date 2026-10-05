<?php

require_once __DIR__ . '/civil_record_schema.php';
require_once __DIR__ . '/records_form.php';
require_once __DIR__ . '/helpers.php';

function ensureCivilRecordUpdatesTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS civil_record_updates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            civil_record_id INT NOT NULL,
            event_type ENUM('created','updated') NOT NULL,
            staff_id VARCHAR(32) NOT NULL,
            staff_name VARCHAR(120) NOT NULL,
            staff_role VARCHAR(40) NOT NULL DEFAULT 'Staff',
            summary VARCHAR(255) NOT NULL,
            changes_json JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_record_created (civil_record_id, created_at),
            CONSTRAINT fk_civil_record_updates_record
                FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
}

/** @return list<string> */
function civilRecordAuditFieldKeys(string $type): array
{
    $base = [
        'registry_number',
        'book_number',
        'page_number',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'event_date',
        'place',
        'father_name',
        'mother_name',
        'notes',
    ];

    $typeFields = civilRecordTypeFieldNames($type);
    $merged = array_values(array_unique(array_merge($base, $typeFields)));

    return array_values(array_filter($merged, static fn (string $field): bool => !in_array($field, ['print_fill_data'], true)));
}

function civilRecordAuditNormalizeValue(string $field, mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    if (function_exists('civilRecordNormalizeDate') && in_array($field, civilRecordCsvDateFields(), true)) {
        $normalized = civilRecordNormalizeDate($text);

        return $normalized ?? $text;
    }

    return $text;
}

/** @return array<string, string> */
function civilRecordBuildAuditSnapshot(array $record): array
{
    $type = (string) ($record['record_type'] ?? '');
    if (!in_array($type, ['birth', 'death', 'marriage'], true)) {
        return [];
    }

    $snap = [];
    foreach (civilRecordAuditFieldKeys($type) as $field) {
        $snap[$field] = civilRecordAuditNormalizeValue($field, $record[$field] ?? null);
    }

    return $snap;
}

function civilRecordAuditFormatDisplay(string $field, string $value): string
{
    if ($value === '') {
        return '(empty)';
    }

    if (function_exists('formatDateDisplay') && in_array($field, civilRecordCsvDateFields(), true)) {
        return formatDateDisplay($value);
    }

    return $value;
}

/**
 * @return list<array{field: string, label: string, old: string, new: string}>
 */
function civilRecordDiffAuditSnapshots(array $oldSnap, array $newSnap, string $type): array
{
    $changes = [];
    foreach (civilRecordAuditFieldKeys($type) as $field) {
        $oldVal = $oldSnap[$field] ?? '';
        $newVal = $newSnap[$field] ?? '';
        if ($oldVal === $newVal) {
            continue;
        }
        $changes[] = [
            'field' => $field,
            'label' => civilRecordViewFieldLabel($field),
            'old'   => civilRecordAuditFormatDisplay($field, $oldVal),
            'new'   => civilRecordAuditFormatDisplay($field, $newVal),
        ];
    }

    return $changes;
}

function civilRecordAuditSummary(string $eventType, string $type, array $changes, array $record): string
{
    if ($eventType === 'created') {
        return 'New ' . civilRecordTypeLabel($type) . ' record manually encoded in the registry.';
    }

    if ($changes === []) {
        return 'Record saved with no field changes detected.';
    }

    $labels = array_slice(array_column($changes, 'label'), 0, 3);
    $summary = 'Registry entry was edited';
    if ($labels !== []) {
        $count = count($changes);
        $more = $count > 3 ? ', +' . ($count - 3) . ' more' : '';
        $summary .= ' (' . implode(', ', $labels) . $more . ')';
    }

    return $summary . '.';
}

/**
 * @param array<string, mixed> $before Full record row or empty for create
 * @param array<string, mixed> $after  Normalized save payload
 */
function recordCivilRecordAudit(PDO $pdo, int $recordId, string $eventType, array $before, array $after): void
{
    if ($recordId <= 0 || !in_array($eventType, ['created', 'updated'], true)) {
        return;
    }

    ensureCivilRecordUpdatesTable($pdo);

    $type = (string) ($after['record_type'] ?? $before['record_type'] ?? '');
    if (!in_array($type, ['birth', 'death', 'marriage'], true)) {
        return;
    }

    $staffId = function_exists('staffId') ? staffId() : 'System';
    $staffName = function_exists('staffName') ? staffName() : 'System';
    $staffRole = function_exists('isAdmin') && isAdmin() ? 'Administrator' : (function_exists('staffRole') ? staffRole() : 'Staff');

    if ($eventType === 'created') {
        $changes = [
            [
                'field' => '_created',
                'label' => 'Registered person',
                'old'   => '(empty)',
                'new'   => civilRecordDisplayName($after),
            ],
        ];
        if (trim((string) ($after['registry_number'] ?? '')) !== '') {
            $changes[] = [
                'field' => 'registry_number',
                'label' => civilRecordViewFieldLabel('registry_number'),
                'old'   => '(empty)',
                'new'   => civilRecordAuditFormatDisplay('registry_number', (string) $after['registry_number']),
            ];
        }
        $summary = civilRecordAuditSummary('created', $type, $changes, $after);
    } else {
        $oldSnap = civilRecordBuildAuditSnapshot($before);
        $newSnap = civilRecordBuildAuditSnapshot(array_merge($before, $after));
        $changes = civilRecordDiffAuditSnapshots($oldSnap, $newSnap, $type);
        if ($changes === []) {
            return;
        }
        $summary = civilRecordAuditSummary('updated', $type, $changes, $after);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO civil_record_updates
         (civil_record_id, event_type, staff_id, staff_name, staff_role, summary, changes_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $recordId,
        $eventType,
        $staffId,
        $staffName,
        $staffRole,
        $summary,
        json_encode($changes, JSON_UNESCAPED_UNICODE),
    ]);
}

/** @return list<array<string, mixed>> */
function fetchCivilRecordUpdateHistory(PDO $pdo, int $recordId, int $limit = 20): array
{
    if ($recordId <= 0) {
        return [];
    }

    ensureCivilRecordUpdatesTable($pdo);
    $limit = max(1, min(50, $limit));

    $stmt = $pdo->prepare(
        "SELECT id, event_type, staff_id, staff_name, staff_role, summary, changes_json, created_at
         FROM civil_record_updates
         WHERE civil_record_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT $limit"
    );
    $stmt->execute([$recordId]);
    $rows = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $changes = json_decode((string) ($row['changes_json'] ?? '[]'), true);
        if (!is_array($changes)) {
            $changes = [];
        }
        $rows[] = [
            'id'          => (int) $row['id'],
            'event_type'  => (string) $row['event_type'],
            'staff_id'    => (string) $row['staff_id'],
            'staff_name'  => (string) $row['staff_name'],
            'staff_role'  => (string) $row['staff_role'],
            'summary'     => (string) $row['summary'],
            'changes'     => $changes,
            'created_at'  => (string) $row['created_at'],
        ];
    }

    return $rows;
}

function formatCivilRecordAuditTimestamp(string $createdAt): string
{
    $ts = strtotime($createdAt);
    if ($ts === false) {
        return $createdAt;
    }

    $datePart = function_exists('formatDateDisplay') ? formatDateDisplay(date('Y-m-d', $ts)) : date('M j, Y', $ts);
    $timePart = date('g:i A', $ts);
    $today = function_exists('alcrosTodayDate') ? alcrosTodayDate() : date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $dayKey = date('Y-m-d', $ts);

    if ($dayKey === $today) {
        return 'Today · ' . $timePart;
    }
    if ($dayKey === $yesterday) {
        return 'Yesterday · ' . $timePart;
    }

    return $datePart . ' · ' . $timePart;
}

/** HTML for recent-updates modal body (edit form and view modal info button). */
function renderCivilRecordUpdateHistoryMarkup(array $recordUpdateHistory): string
{
    if ($recordUpdateHistory === []) {
        return '<p class="records-recent-updates__empty">No edit history yet for this record. Changes will appear here after the next save.</p>';
    }

    ob_start();
    ?>
    <div class="records-recent-updates__list">
        <?php foreach ($recordUpdateHistory as $update): ?>
        <article class="records-recent-updates__item">
            <header class="records-recent-updates__item-head">
                <time class="records-recent-updates__when"><?= htmlspecialchars(formatCivilRecordAuditTimestamp($update['created_at'])) ?></time>
                <p class="records-recent-updates__who">
                    <strong><?= htmlspecialchars($update['event_type'] === 'created' ? 'Created by' : 'Updated by') ?>:</strong>
                    <?= htmlspecialchars($update['staff_name']) ?>
                    (<span class="font-mono text-[10px]"><?= htmlspecialchars($update['staff_id']) ?></span>)
                    · <?= htmlspecialchars($update['staff_role']) ?>
                </p>
                <p class="records-recent-updates__summary"><?= htmlspecialchars($update['summary']) ?></p>
            </header>
            <?php if (!empty($update['changes'])): ?>
            <div class="records-recent-updates__changes">
                <p class="records-recent-updates__changes-label">Changes</p>
                <ul class="records-recent-updates__changes-list">
                    <?php foreach ($update['changes'] as $change): ?>
                    <li>
                        <span class="records-recent-updates__field"><?= htmlspecialchars((string) ($change['label'] ?? 'Field')) ?>:</span>
                        <span class="records-recent-updates__from"><?= htmlspecialchars((string) ($change['old'] ?? '')) ?></span>
                        <span class="records-recent-updates__arrow" aria-hidden="true">→</span>
                        <span class="records-recent-updates__to"><?= htmlspecialchars((string) ($change['new'] ?? '')) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}
