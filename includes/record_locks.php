<?php

const CIVIL_RECORD_EDIT_LOCK_TTL = 300;

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

    return strtotime((string) ($lock['expires_at'] ?? '')) > time();
}

function formatCivilRecordEditLock(array $lock, string $currentStaffId): array
{
    return [
        'staff_id'    => (string) ($lock['staff_id'] ?? ''),
        'staff_name'  => (string) ($lock['staff_name'] ?? ''),
        'locked_at'   => (string) ($lock['locked_at'] ?? ''),
        'expires_at'  => (string) ($lock['expires_at'] ?? ''),
        'held_by_you' => (string) ($lock['staff_id'] ?? '') === $currentStaffId,
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

    ensureCivilRecordEditLocksTable($pdo);
    $ttlSeconds = max(60, $ttlSeconds);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

    $pdo->beginTransaction();
    try {
        purgeExpiredCivilRecordEditLocks($pdo);

        $stmt = $pdo->prepare('SELECT * FROM civil_record_edit_locks WHERE civil_record_id = ? FOR UPDATE');
        $stmt->execute([$recordId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing
            && (string) $existing['staff_id'] !== $staffId
            && civilRecordEditLockIsActive($existing)
        ) {
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
    int $ttlSeconds = CIVIL_RECORD_EDIT_LOCK_TTL
): array {
    ensureCivilRecordEditLocksTable($pdo);
    $ttlSeconds = max(60, $ttlSeconds);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM civil_record_edit_locks WHERE civil_record_id = ? FOR UPDATE');
        $stmt->execute([$recordId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing || (string) $existing['staff_id'] !== $staffId) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'Edit lock not held by you.'];
        }

        $pdo->prepare('UPDATE civil_record_edit_locks SET expires_at = ?, locked_at = NOW() WHERE civil_record_id = ?')
            ->execute([$expiresAt, $recordId]);
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
    $stmt = $pdo->prepare('DELETE FROM civil_record_edit_locks WHERE civil_record_id = ? AND staff_id = ?');
    $stmt->execute([$recordId, $staffId]);

    return $stmt->rowCount() > 0;
}

function requireCivilRecordEditLock(PDO $pdo, int $recordId, string $staffId): void
{
    $lock = fetchCivilRecordEditLock($pdo, $recordId);
    if (!$lock || (string) $lock['staff_id'] !== $staffId || !civilRecordEditLockIsActive($lock)) {
        throw new InvalidArgumentException(
            'This record is being edited by another staff member or your edit session expired. Close this form and try again.'
        );
    }
}
