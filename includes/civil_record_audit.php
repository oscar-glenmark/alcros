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
            edit_reason VARCHAR(500) NULL DEFAULT NULL,
            changes_json JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_record_created (civil_record_id, created_at),
            CONSTRAINT fk_civil_record_updates_record
                FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    try {
        $pdo->query('SELECT edit_reason FROM civil_record_updates LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE civil_record_updates ADD COLUMN edit_reason VARCHAR(500) NULL DEFAULT NULL AFTER summary');
        } catch (Throwable $ignored) {
        }
    }
}

/** @return array<string, string> preset key => label */
function civilRecordEditReasonPresets(): array
{
    return [
        'typographical_error'  => 'Typographical error',
        'incorrect_date'       => 'Incorrect date',
        'incorrect_name'       => 'Incorrect name or spelling',
        'encoding_error'         => 'Data entered incorrectly during encoding',
        'supporting_document'    => 'Correction from supporting document',
        'other'                  => 'Other (specify below)',
    ];
}

function normalizeCivilRecordEditReasonDetail(string $detail): string
{
    $detail = trim(preg_replace('/\s+/u', ' ', $detail));
    if ($detail === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($detail) > 400) {
        return mb_substr($detail, 0, 400);
    }
    if (strlen($detail) > 400) {
        return substr($detail, 0, 400);
    }

    return $detail;
}

/** Build stored edit reason from POST (update saves only). Returns null if invalid. */
function parseCivilRecordEditReasonFromPost(array $post): ?string
{
    $presets = civilRecordEditReasonPresets();
    $category = trim((string) ($post['edit_reason_category'] ?? ''));
    if ($category === '' || !isset($presets[$category])) {
        return null;
    }

    $detail = normalizeCivilRecordEditReasonDetail((string) ($post['edit_reason_detail'] ?? ''));
    if ($category === 'other') {
        if ($detail === '') {
            return null;
        }

        return 'Other: ' . $detail;
    }

    if ($detail !== '') {
        return $presets[$category] . ' — ' . $detail;
    }

    return $presets[$category];
}

function renderCivilRecordEditReasonNoticeMarkup(bool $compact = false): string
{
    if ($compact) {
        return '<p class="records-edit-reason-modal__hint" role="note">'
            . 'Pick a reason below—it is saved in the audit log with your changes.'
            . '</p>';
    }

    return '<aside class="records-edit-reason-notice" role="note">'
        . '<p class="records-edit-reason-notice__text">Each edit needs a reason (e.g. typo, wrong date, or document correction). '
        . 'It is stored in the audit log with your name and the fields you changed.</p>'
        . '</aside>';
}

function renderCivilRecordEditReasonModalMarkup(): string
{
    $options = '<option value="">Select a reason…</option>';
    foreach (civilRecordEditReasonPresets() as $presetKey => $presetLabel) {
        $options .= '<option value="' . htmlspecialchars($presetKey) . '">' . htmlspecialchars($presetLabel) . '</option>';
    }

    return '<div id="recordEditReasonModal" class="records-edit-reason-modal hidden" aria-hidden="true">'
        . '<div class="records-edit-reason-modal__backdrop" data-edit-reason-close tabindex="-1" aria-hidden="true"></div>'
        . '<div class="records-edit-reason-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="recordEditReasonModalTitle">'
        . '<div class="records-edit-reason-modal__header">'
        . '<div>'
        . '<h2 id="recordEditReasonModalTitle" class="records-edit-reason-modal__title">Reason for update</h2>'
        . '<p class="records-edit-reason-modal__subtitle">Required before saving changes to this registry entry</p>'
        . '</div>'
        . '<button type="button" class="records-edit-reason-modal__close" data-edit-reason-close aria-label="Close reason dialog">'
        . lucideSvg('x', 'w-5 h-5')
        . '</button>'
        . '</div>'
        . '<div class="records-edit-reason-modal__body">'
        . renderCivilRecordEditReasonNoticeMarkup(true)
        . '<div class="grid grid-cols-1 gap-3">'
        . '<div>'
        . '<label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1.5" for="editReasonModalCategory">Reason category</label>'
        . '<select id="editReasonModalCategory" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm bg-white" title="Select a reason for this update">'
        . $options
        . '</select>'
        . '</div>'
        . '<div>'
        . '<label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1.5" for="editReasonModalDetail">Additional details '
        . '<span id="editReasonModalDetailOptional" class="font-normal normal-case text-slate-400">(optional)</span></label>'
        . '<textarea id="editReasonModalDetail" rows="3" maxlength="400" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm leading-relaxed" placeholder="Brief note, e.g. corrected day/month on date of birth"></textarea>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '<div class="records-edit-reason-modal__footer">'
        . '<button type="button" class="records-edit-reason-modal__btn records-edit-reason-modal__btn--cancel" data-edit-reason-close>Cancel</button>'
        . '<button type="button" id="recordEditReasonConfirmBtn" class="records-edit-reason-modal__btn records-edit-reason-modal__btn--ok">Save update</button>'
        . '</div>'
        . '</div>'
        . '</div>';
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
function recordCivilRecordAudit(
    PDO $pdo,
    int $recordId,
    string $eventType,
    array $before,
    array $after,
    ?string $editReason = null
): void {
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

    $storedEditReason = null;
    if ($eventType === 'updated') {
        $storedEditReason = normalizeCivilRecordEditReasonDetail((string) ($editReason ?? ''));
        if ($storedEditReason === '') {
            $storedEditReason = null;
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO civil_record_updates
         (civil_record_id, event_type, staff_id, staff_name, staff_role, summary, edit_reason, changes_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $recordId,
        $eventType,
        $staffId,
        $staffName,
        $staffRole,
        $summary,
        $storedEditReason,
        json_encode($changes, JSON_UNESCAPED_UNICODE),
    ]);
}

/** @return list<array<string, mixed>> */
function fetchCivilRecordUpdateHistory(PDO $pdo, int $recordId, int $limit = 20, bool $editsOnly = true): array
{
    if ($recordId <= 0) {
        return [];
    }

    ensureCivilRecordUpdatesTable($pdo);
    $limit = max(1, min(50, $limit));

    $sql = "SELECT id, event_type, staff_id, staff_name, staff_role, summary, edit_reason, changes_json, created_at
         FROM civil_record_updates
         WHERE civil_record_id = ?";
    if ($editsOnly) {
        $sql .= " AND event_type = 'updated'";
    }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT $limit";

    $stmt = $pdo->prepare($sql);
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
            'edit_reason' => trim((string) ($row['edit_reason'] ?? '')),
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
        return '<p class="records-recent-updates__empty">No edits yet for this record. Field changes will appear here after someone saves an update.</p>';
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
                <?php if (!empty($update['edit_reason'])): ?>
                <p class="records-recent-updates__reason"><strong>Reason:</strong> <?= htmlspecialchars((string) $update['edit_reason']) ?></p>
                <?php endif; ?>
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
