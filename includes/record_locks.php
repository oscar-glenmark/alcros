<?php

const CIVIL_RECORD_EDIT_LOCK_TTL = 300;

function normalizeStaffId(?string $staffId): string
{
    return strtoupper(trim((string) $staffId));
}

function civilRecordStaffIdsMatch(?string $left, ?string $right): bool
{
    $left = normalizeStaffId($left);
    $right = normalizeStaffId($right);

    return $left !== '' && $left === $right;
}

function ensureCivilRecordEditLocksTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS civil_record_edit_locks (
            civil_record_id INT NOT NULL PRIMARY KEY,
            staff_id VARCHAR(32) NOT NULL,
            staff_name VARCHAR(120) NOT NULL DEFAULT \'\',
            locked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB'
    );
    $done = true;
}

function purgeExpiredCivilRecordEditLocks(PDO $pdo): void
{
    ensureCivilRecordEditLocksTable($pdo);
    $pdo->exec('DELETE FROM civil_record_edit_locks WHERE expires_at < NOW()');
}

function civilRecordEditLockIsActive(?array $lock): bool
{
    if (!$lock) {
        return false;
    }

    $expiresAt = (string) ($lock['expires_at'] ?? '');
    if ($expiresAt === '') {
        return false;
    }

    $expiresTs = strtotime($expiresAt);
    if ($expiresTs === false) {
        return false;
    }

    // Small grace window so PHP/MySQL clock differences do not expire locks early.
    return ($expiresTs + 15) > time();
}

function civilRecordEditLockHeldByStaff(?array $lock, string $staffId): bool
{
    return $lock && civilRecordStaffIdsMatch($lock['staff_id'] ?? '', $staffId);
}

function formatCivilRecordEditLock(array $lock, string $currentStaffId): array
{
    return [
        'staff_id'    => (string) ($lock['staff_id'] ?? ''),
        'staff_name'  => (string) ($lock['staff_name'] ?? ''),
        'locked_at'   => (string) ($lock['locked_at'] ?? ''),
        'expires_at'  => (string) ($lock['expires_at'] ?? ''),
        'held_by_you' => civilRecordEditLockHeldByStaff($lock, $currentStaffId),
        'active'      => civilRecordEditLockIsActive($lock),
    ];
}

function fetchCivilRecordEditLock(PDO $pdo, int $recordId): ?array
{
    if ($recordId <= 0) {
        return null;
    }

    ensureCivilRecordEditLocksTable($pdo);
    purgeExpiredCivilRecordEditLocks($pdo);

    $stmt = $pdo->prepare('SELECT * FROM civil_record_edit_locks WHERE civil_record_id = ? LIMIT 1');
    $stmt->execute([$recordId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function civilRecordEditBlockedByOtherStaff(?array $lock, string $staffId): bool
{
    return $lock
        && civilRecordEditLockIsActive($lock)
        && !civilRecordEditLockHeldByStaff($lock, $staffId);
}

function assertCivilRecordEditableByStaff(PDO $pdo, int $recordId, string $staffId): void
{
    if ($recordId <= 0) {
        throw new InvalidArgumentException('Invalid record.');
    }

    $lock = fetchCivilRecordEditLock($pdo, $recordId);
    if (!civilRecordEditBlockedByOtherStaff($lock, $staffId)) {
        return;
    }

    throw new InvalidArgumentException(
        'Could not update this record because '
        . ((string) ($lock['staff_name'] ?: $lock['staff_id'] ?: 'another staff member'))
        . ' is currently editing it. Close this form and try again.'
    );
}

function acquireCivilRecordEditLock(
    PDO $pdo,
    int $recordId,
    string $staffId,
    string $staffName,
    int $ttlSeconds = CIVIL_RECORD_EDIT_LOCK_TTL
): array {
    if ($recordId <= 0) {
        throw new InvalidArgumentException('Invalid record.');
    }

    $staffId = normalizeStaffId($staffId);
    if ($staffId === '') {
        throw new InvalidArgumentException('Invalid staff session.');
    }

    ensureCivilRecordEditLocksTable($pdo);
    $ttlSeconds = max(60, $ttlSeconds);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

    $pdo->beginTransaction();
    try {
        purgeExpiredCivilRecordEditLocks($pdo);

        $stmt = $pdo->prepare('SELECT * FROM civil_record_edit_locks WHERE civil_record_id = ? FOR UPDATE');
        $stmt->execute([$recordId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (civilRecordEditBlockedByOtherStaff($existing, $staffId)) {
            $pdo->commit();

            return [
                'ok'         => false,
                'locked_by'  => (string) ($existing['staff_name'] ?: $existing['staff_id']),
                'staff_id'   => (string) $existing['staff_id'],
                'expires_at' => (string) $existing['expires_at'],
            ];
        }

        $pdo->prepare(
            'INSERT INTO civil_record_edit_locks (civil_record_id, staff_id, staff_name, locked_at, expires_at)
             VALUES (?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                staff_id = VALUES(staff_id),
                staff_name = VALUES(staff_name),
                locked_at = NOW(),
                expires_at = VALUES(expires_at)'
        )->execute([$recordId, $staffId, $staffName, $expiresAt]);

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

function refreshCivilRecordEditLock(
    PDO $pdo,
    int $recordId,
    string $staffId,
    string $staffName = '',
    int $ttlSeconds = CIVIL_RECORD_EDIT_LOCK_TTL
): array {
    $staffId = normalizeStaffId($staffId);
    if ($staffId === '') {
        return ['ok' => false, 'error' => 'Invalid staff session.'];
    }

    ensureCivilRecordEditLocksTable($pdo);
    $ttlSeconds = max(60, $ttlSeconds);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

    $pdo->beginTransaction();
    try {
        purgeExpiredCivilRecordEditLocks($pdo);

        $stmt = $pdo->prepare('SELECT * FROM civil_record_edit_locks WHERE civil_record_id = ? FOR UPDATE');
        $stmt->execute([$recordId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (civilRecordEditBlockedByOtherStaff($existing, $staffId)) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'Edit lock held by another staff member.'];
        }

        if (!$existing || !civilRecordEditLockHeldByStaff($existing, $staffId)) {
            $pdo->commit();

            return acquireCivilRecordEditLock($pdo, $recordId, $staffId, $staffName, $ttlSeconds);
        }

        $pdo->prepare('UPDATE civil_record_edit_locks SET expires_at = ?, locked_at = NOW(), staff_name = ? WHERE civil_record_id = ?')
            ->execute([$expiresAt, $staffName, $recordId]);
        $pdo->commit();

        return ['ok' => true, 'expires_at' => $expiresAt];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function releaseCivilRecordEditLock(PDO $pdo, int $recordId, string $staffId): bool
{
    ensureCivilRecordEditLocksTable($pdo);
    $lock = fetchCivilRecordEditLock($pdo, $recordId);
    if (!$lock || !civilRecordEditLockHeldByStaff($lock, $staffId)) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM civil_record_edit_locks WHERE civil_record_id = ?');
    $stmt->execute([$recordId]);

    return $stmt->rowCount() > 0;
}
