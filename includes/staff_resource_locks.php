<?php

/**
 * Shared staff locks for document requests and appointments (real-time coordination).
 */

const STAFF_RESOURCE_LOCK_TTL = 300;
const STAFF_LOCK_TYPE_REQUEST = 'document_request';
const STAFF_LOCK_TYPE_APPOINTMENT = 'appointment';
const STAFF_LOCK_TYPE_CIVIL_RECORD = 'civil_record';

function normalizeStaffLockStaffId(?string $staffId): string
{
    return strtoupper(trim((string) $staffId));
}

function staffResourceLockStaffIdsMatch(?string $left, ?string $right): bool
{
    $left = normalizeStaffLockStaffId($left);
    $right = normalizeStaffLockStaffId($right);

    return $left !== '' && $left === $right;
}

function ensureStaffResourceLocksTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS staff_resource_locks (
            resource_type VARCHAR(24) NOT NULL,
            resource_id INT NOT NULL,
            staff_id VARCHAR(50) NOT NULL,
            staff_name VARCHAR(120) NOT NULL DEFAULT \'\',
            locked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (resource_type, resource_id),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB'
    );
    $done = true;
}

function purgeExpiredStaffResourceLocks(PDO $pdo): void
{
    ensureStaffResourceLocksTable($pdo);
    $pdo->exec('DELETE FROM staff_resource_locks WHERE expires_at < NOW()');
}

function staffResourceLockIsActive(?array $lock): bool
{
    if (!$lock) {
        return false;
    }

    $expiresAt = (string) ($lock['expires_at'] ?? '');
    if ($expiresAt === '') {
        return false;
    }

    $expiresTs = strtotime($expiresAt);

    return $expiresTs !== false && ($expiresTs + 15) > time();
}

function staffResourceLockHeldByStaff(?array $lock, string $staffId): bool
{
    return $lock && staffResourceLockStaffIdsMatch($lock['staff_id'] ?? '', $staffId);
}

function formatStaffResourceLock(?array $lock, string $currentStaffId): ?array
{
    if (!$lock || !staffResourceLockIsActive($lock)) {
        return null;
    }

    $heldByYou = staffResourceLockHeldByStaff($lock, $currentStaffId);

    return [
        'staff_id'    => (string) ($lock['staff_id'] ?? ''),
        'staff_name'  => (string) ($lock['staff_name'] ?? ''),
        'locked_at'   => (string) ($lock['locked_at'] ?? ''),
        'expires_at'  => (string) ($lock['expires_at'] ?? ''),
        'held_by_you' => $heldByYou,
        'active'      => true,
        'label'       => $heldByYou
            ? 'You are handling this'
            : ('Being handled by ' . ((string) ($lock['staff_name'] ?: $lock['staff_id'] ?: 'another staff member'))),
    ];
}

function fetchStaffResourceLock(PDO $pdo, string $resourceType, int $resourceId): ?array
{
    if ($resourceId <= 0 || $resourceType === '') {
        return null;
    }

    ensureStaffResourceLocksTable($pdo);
    purgeExpiredStaffResourceLocks($pdo);

    $stmt = $pdo->prepare(
        'SELECT * FROM staff_resource_locks WHERE resource_type = ? AND resource_id = ? LIMIT 1'
    );
    $stmt->execute([$resourceType, $resourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @param list<int> $resourceIds */
function computeStaffResourceLocksRevision(PDO $pdo, string $resourceType, array $resourceIds): string
{
    ensureStaffResourceLocksTable($pdo);
    purgeExpiredStaffResourceLocks($pdo);

    $resourceIds = array_values(array_unique(array_filter(
        array_map('intval', $resourceIds),
        static fn (int $id): bool => $id > 0
    )));

    if ($resourceIds === []) {
        $stmt = $pdo->prepare(
            'SELECT resource_id, staff_id, staff_name, locked_at, expires_at
             FROM staff_resource_locks
             WHERE resource_type = ? AND expires_at >= NOW()
             ORDER BY resource_id ASC'
        );
        $stmt->execute([$resourceType]);
    } else {
        $placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT resource_id, staff_id, staff_name, locked_at, expires_at
             FROM staff_resource_locks
             WHERE resource_type = ? AND resource_id IN ($placeholders)
             ORDER BY resource_id ASC"
        );
        $stmt->execute(array_merge([$resourceType], $resourceIds));
    }

    $active = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (staffResourceLockIsActive($row)) {
            $active[] = $row;
        }
    }

    return sha1(json_encode($active, JSON_UNESCAPED_UNICODE));
}

/** @param list<int> $resourceIds */
function fetchStaffResourceLocksMap(PDO $pdo, string $resourceType, array $resourceIds): array
{
    $resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds), static fn (int $id): bool => $id > 0)));
    if ($resourceIds === []) {
        return [];
    }

    ensureStaffResourceLocksTable($pdo);
    purgeExpiredStaffResourceLocks($pdo);

    $placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT * FROM staff_resource_locks WHERE resource_type = ? AND resource_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$resourceType], $resourceIds));

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int) $row['resource_id']] = $row;
    }

    return $map;
}

function staffResourceLockBlockedByOther(?array $lock, string $staffId): bool
{
    return $lock
        && staffResourceLockIsActive($lock)
        && !staffResourceLockHeldByStaff($lock, $staffId);
}

function documentRequestLockEligibleStatus(string $status): bool
{
    return normalizeRequestStatus($status) === 'pending';
}

function appointmentLockEligibleStatus(string $status): bool
{
    return strtolower(trim($status)) === 'scheduled';
}

/** Lock badge / coordination only when the row is still in the actionable queue state. */
function staffResourceLockDisplay(
    PDO $pdo,
    string $resourceType,
    int $resourceId,
    ?array $lockRow,
    string $staffId
): ?array {
    if ($resourceId <= 0 || !$lockRow || !staffResourceLockEligible($pdo, $resourceType, $resourceId)) {
        return null;
    }

    return formatStaffResourceLock($lockRow, $staffId);
}

function documentRequestLockEligible(PDO $pdo, int $id): bool
{
    if ($id <= 0) {
        return false;
    }

    ensureSoftDeleteColumns($pdo);
    $stmt = $pdo->prepare('SELECT status FROM document_requests WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $status = $stmt->fetchColumn();

    return $status !== false && documentRequestLockEligibleStatus((string) $status);
}

function appointmentLockEligible(PDO $pdo, int $id): bool
{
    if ($id <= 0) {
        return false;
    }

    ensureSoftDeleteColumns($pdo);
    $stmt = $pdo->prepare('SELECT status FROM appointments WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $status = $stmt->fetchColumn();

    return $status !== false && appointmentLockEligibleStatus((string) $status);
}

function assertStaffResourceLockForWrite(
    PDO $pdo,
    string $resourceType,
    int $resourceId,
    string $staffId,
    string $staffName = ''
): void {
    if ($resourceId <= 0) {
        throw new InvalidArgumentException('Invalid record.');
    }

    $eligible = match ($resourceType) {
        STAFF_LOCK_TYPE_REQUEST => documentRequestLockEligible($pdo, $resourceId),
        STAFF_LOCK_TYPE_APPOINTMENT => appointmentLockEligible($pdo, $resourceId),
        default => false,
    };

    if (!$eligible) {
        return;
    }

    $staffId = normalizeStaffLockStaffId($staffId);
    if ($staffId === '') {
        throw new InvalidArgumentException('Invalid staff session.');
    }

    $staffName = trim($staffName);
    if ($staffName === '') {
        $staffName = $staffId;
    }

    $lock = fetchStaffResourceLock($pdo, $resourceType, $resourceId);

    if (staffResourceLockBlockedByOther($lock, $staffId)) {
        $who = (string) ($lock['staff_name'] ?: $lock['staff_id'] ?: 'another staff member');
        throw new InvalidArgumentException(
            'This item is being handled by ' . $who . '. You can view it but cannot change it until they finish or release it.'
        );
    }

    if (!$lock || !staffResourceLockIsActive($lock)) {
        throw new InvalidArgumentException(
            'Open this item with Verify or View first so it is assigned to you before saving changes.'
        );
    }

    if (!staffResourceLockHeldByStaff($lock, $staffId)) {
        $who = (string) ($lock['staff_name'] ?: $lock['staff_id'] ?: 'another staff member');
        throw new InvalidArgumentException(
            'This item is being handled by ' . $who . '. You can view it but cannot change it until they finish or release it.'
        );
    }

    refreshStaffResourceLock($pdo, $resourceType, $resourceId, $staffId, $staffName);
}

/** Claim or refresh lock when staff focuses an item (list poll / detail). */
function touchStaffResourceLockForFocus(PDO $pdo, string $resourceType, int $resourceId, string $staffId, string $staffName): ?array
{
    if ($resourceId <= 0) {
        return null;
    }

    $eligible = match ($resourceType) {
        STAFF_LOCK_TYPE_REQUEST => documentRequestLockEligible($pdo, $resourceId),
        STAFF_LOCK_TYPE_APPOINTMENT => appointmentLockEligible($pdo, $resourceId),
        default => false,
    };

    if (!$eligible) {
        return null;
    }

    $existing = fetchStaffResourceLock($pdo, $resourceType, $resourceId);
    if (staffResourceLockBlockedByOther($existing, $staffId)) {
        return formatStaffResourceLock($existing, $staffId);
    }

    if ($existing && staffResourceLockHeldByStaff($existing, $staffId)) {
        refreshStaffResourceLock($pdo, $resourceType, $resourceId, $staffId, $staffName);
    } else {
        acquireStaffResourceLock($pdo, $resourceType, $resourceId, $staffId, $staffName);
    }

    $lock = fetchStaffResourceLock($pdo, $resourceType, $resourceId);

    return $lock ? formatStaffResourceLock($lock, $staffId) : null;
}

function staffResourceLockEligible(PDO $pdo, string $resourceType, int $resourceId): bool
{
    return match ($resourceType) {
        STAFF_LOCK_TYPE_REQUEST => documentRequestLockEligible($pdo, $resourceId),
        STAFF_LOCK_TYPE_APPOINTMENT => appointmentLockEligible($pdo, $resourceId),
        default => true,
    };
}

function clearStaffResourceLock(PDO $pdo, string $resourceType, int $resourceId): void
{
    if ($resourceId <= 0 || $resourceType === '') {
        return;
    }

    ensureStaffResourceLocksTable($pdo);
    $pdo->prepare('DELETE FROM staff_resource_locks WHERE resource_type = ? AND resource_id = ?')
        ->execute([$resourceType, $resourceId]);
}

function acquireStaffResourceLock(
    PDO $pdo,
    string $resourceType,
    int $resourceId,
    string $staffId,
    string $staffName,
    int $ttlSeconds = STAFF_RESOURCE_LOCK_TTL
): array {
    if ($resourceId <= 0) {
        throw new InvalidArgumentException('Invalid record.');
    }

    if (!staffResourceLockEligible($pdo, $resourceType, $resourceId)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'not_lockable'];
    }

    $staffId = normalizeStaffLockStaffId($staffId);
    if ($staffId === '') {
        throw new InvalidArgumentException('Invalid staff session.');
    }

    ensureStaffResourceLocksTable($pdo);
    $ttlSeconds = max(60, $ttlSeconds);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

    $pdo->beginTransaction();
    try {
        purgeExpiredStaffResourceLocks($pdo);

        $stmt = $pdo->prepare(
            'SELECT * FROM staff_resource_locks WHERE resource_type = ? AND resource_id = ? FOR UPDATE'
        );
        $stmt->execute([$resourceType, $resourceId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (staffResourceLockBlockedByOther($existing, $staffId)) {
            $pdo->commit();

            return [
                'ok'         => false,
                'locked_by'  => (string) ($existing['staff_name'] ?: $existing['staff_id']),
                'staff_id'   => (string) $existing['staff_id'],
                'expires_at' => (string) $existing['expires_at'],
            ];
        }

        $pdo->prepare(
            'INSERT INTO staff_resource_locks (resource_type, resource_id, staff_id, staff_name, locked_at, expires_at)
             VALUES (?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                staff_id = VALUES(staff_id),
                staff_name = VALUES(staff_name),
                locked_at = NOW(),
                expires_at = VALUES(expires_at)'
        )->execute([$resourceType, $resourceId, $staffId, $staffName, $expiresAt]);

        $pdo->commit();

        return [
            'ok'         => true,
            'expires_at' => $expiresAt,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function refreshStaffResourceLock(
    PDO $pdo,
    string $resourceType,
    int $resourceId,
    string $staffId,
    string $staffName = '',
    int $ttlSeconds = STAFF_RESOURCE_LOCK_TTL
): array {
    $staffId = normalizeStaffLockStaffId($staffId);
    if ($staffId === '') {
        return ['ok' => false, 'error' => 'Invalid staff session.'];
    }

    if (!staffResourceLockEligible($pdo, $resourceType, $resourceId)) {
        clearStaffResourceLock($pdo, $resourceType, $resourceId);

        return ['ok' => true, 'skipped' => true, 'reason' => 'not_lockable'];
    }

    ensureStaffResourceLocksTable($pdo);
    $ttlSeconds = max(60, $ttlSeconds);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

    $pdo->beginTransaction();
    try {
        purgeExpiredStaffResourceLocks($pdo);

        $stmt = $pdo->prepare(
            'SELECT * FROM staff_resource_locks WHERE resource_type = ? AND resource_id = ? FOR UPDATE'
        );
        $stmt->execute([$resourceType, $resourceId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (staffResourceLockBlockedByOther($existing, $staffId)) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'Lock held by another staff member.'];
        }

        if (!$existing || !staffResourceLockHeldByStaff($existing, $staffId)) {
            $pdo->commit();

            return acquireStaffResourceLock($pdo, $resourceType, $resourceId, $staffId, $staffName, $ttlSeconds);
        }

        $pdo->prepare(
            'UPDATE staff_resource_locks SET expires_at = ?, locked_at = NOW(), staff_name = ? WHERE resource_type = ? AND resource_id = ?'
        )->execute([$expiresAt, $staffName, $resourceType, $resourceId]);
        $pdo->commit();

        return ['ok' => true, 'expires_at' => $expiresAt];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function releaseStaffResourceLock(PDO $pdo, string $resourceType, int $resourceId, string $staffId): bool
{
    ensureStaffResourceLocksTable($pdo);
    $lock = fetchStaffResourceLock($pdo, $resourceType, $resourceId);
    if (!$lock || !staffResourceLockHeldByStaff($lock, $staffId)) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM staff_resource_locks WHERE resource_type = ? AND resource_id = ?');
    $stmt->execute([$resourceType, $resourceId]);

    return $stmt->rowCount() > 0;
}

function forceReleaseStaffResourceLock(
    PDO $pdo,
    string $resourceType,
    int $resourceId,
    string $adminStaffId,
    string $adminStaffName
): bool {
    ensureStaffResourceLocksTable($pdo);
    $lock = fetchStaffResourceLock($pdo, $resourceType, $resourceId);
    if (!$lock || !staffResourceLockIsActive($lock)) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM staff_resource_locks WHERE resource_type = ? AND resource_id = ?');
    $stmt->execute([$resourceType, $resourceId]);
    if ($stmt->rowCount() === 0) {
        return false;
    }

    $holder = (string) ($lock['staff_name'] ?: $lock['staff_id']);
    logActivity(
        $adminStaffId,
        'Lock Released',
        $adminStaffName . ' cleared a stale ' . str_replace('_', ' ', $resourceType) . ' lock on #' . $resourceId
            . ($holder !== '' ? ' (was held by ' . $holder . ')' : '')
    );

    return true;
}

function releaseStaffResourceLockIfTerminal(PDO $pdo, string $resourceType, int $resourceId): void
{
    if ($resourceId <= 0) {
        return;
    }

    $eligible = match ($resourceType) {
        STAFF_LOCK_TYPE_REQUEST => !documentRequestLockEligible($pdo, $resourceId),
        STAFF_LOCK_TYPE_APPOINTMENT => !appointmentLockEligible($pdo, $resourceId),
        default => false,
    };

    if ($eligible) {
        $pdo->prepare('DELETE FROM staff_resource_locks WHERE resource_type = ? AND resource_id = ?')
            ->execute([$resourceType, $resourceId]);
    }
}
