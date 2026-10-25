<?php

require_once __DIR__ . '/staff_resource_locks.php';
require_once __DIR__ . '/schema_reorganization.php';

const CIVIL_RECORD_EDIT_LOCK_TTL = STAFF_RESOURCE_LOCK_TTL;

function normalizeStaffId(?string $staffId): string
{
    return normalizeStaffLockStaffId($staffId);
}

function civilRecordStaffIdsMatch(?string $left, ?string $right): bool
{
    return staffResourceLockStaffIdsMatch($left, $right);
}

function ensureCivilRecordEditLocksTable(PDO $pdo): void
{
    ensureAlcrosSchemaReorganization($pdo);
    ensureStaffResourceLocksTable($pdo);
}

/** @return array<string, mixed>|null Row shaped like legacy civil_record_edit_locks for callers. */
function mapStaffLockRowToCivilRecordEditLock(?array $lock, int $recordId): ?array
{
    if (!$lock) {
        return null;
    }

    return [
        'civil_record_id' => $recordId,
        'staff_id'        => (string) ($lock['staff_id'] ?? ''),
        'staff_name'      => (string) ($lock['staff_name'] ?? ''),
        'locked_at'       => (string) ($lock['locked_at'] ?? ''),
        'expires_at'      => (string) ($lock['expires_at'] ?? ''),
    ];
}

function purgeExpiredCivilRecordEditLocks(PDO $pdo): void
{
    ensureCivilRecordEditLocksTable($pdo);
    purgeExpiredStaffResourceLocks($pdo);
}

function civilRecordEditLockIsActive(?array $lock): bool
{
    return staffResourceLockIsActive($lock);
}

function civilRecordEditLockHeldByStaff(?array $lock, string $staffId): bool
{
    return staffResourceLockHeldByStaff($lock, $staffId);
}

function formatCivilRecordEditLock(array $lock, string $currentStaffId): array
{
    $formatted = formatStaffResourceLock($lock, $currentStaffId);
    if ($formatted === null) {
        return [
            'staff_id'    => (string) ($lock['staff_id'] ?? ''),
            'staff_name'  => (string) ($lock['staff_name'] ?? ''),
            'locked_at'   => (string) ($lock['locked_at'] ?? ''),
            'expires_at'  => (string) ($lock['expires_at'] ?? ''),
            'held_by_you' => civilRecordEditLockHeldByStaff($lock, $currentStaffId),
            'active'      => false,
        ];
    }

    return [
        'staff_id'    => $formatted['staff_id'],
        'staff_name'  => $formatted['staff_name'],
        'locked_at'   => $formatted['locked_at'],
        'expires_at'  => $formatted['expires_at'],
        'held_by_you' => $formatted['held_by_you'],
        'active'      => $formatted['active'],
    ];
}

function fetchCivilRecordEditLock(PDO $pdo, int $recordId): ?array
{
    if ($recordId <= 0) {
        return null;
    }

    ensureCivilRecordEditLocksTable($pdo);
    purgeExpiredCivilRecordEditLocks($pdo);

    $lock = fetchStaffResourceLock($pdo, STAFF_LOCK_TYPE_CIVIL_RECORD, $recordId);

    return mapStaffLockRowToCivilRecordEditLock($lock, $recordId);
}

function civilRecordEditBlockedByOtherStaff(?array $lock, string $staffId): bool
{
    return staffResourceLockBlockedByOther($lock, $staffId);
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
    $result = acquireStaffResourceLock(
        $pdo,
        STAFF_LOCK_TYPE_CIVIL_RECORD,
        $recordId,
        $staffId,
        $staffName,
        $ttlSeconds
    );

    if (!($result['ok'] ?? false)) {
        return [
            'ok'         => false,
            'locked_by'  => $result['locked_by'] ?? null,
            'staff_id'   => $result['staff_id'] ?? null,
            'expires_at' => $result['expires_at'] ?? null,
        ];
    }

    return [
        'ok'         => true,
        'expires_at' => $result['expires_at'] ?? null,
    ];
}

function refreshCivilRecordEditLock(
    PDO $pdo,
    int $recordId,
    string $staffId,
    string $staffName = '',
    int $ttlSeconds = CIVIL_RECORD_EDIT_LOCK_TTL
): array {
    return refreshStaffResourceLock(
        $pdo,
        STAFF_LOCK_TYPE_CIVIL_RECORD,
        $recordId,
        $staffId,
        $staffName,
        $ttlSeconds
    );
}

function releaseCivilRecordEditLock(PDO $pdo, int $recordId, string $staffId): bool
{
    return releaseStaffResourceLock($pdo, STAFF_LOCK_TYPE_CIVIL_RECORD, $recordId, $staffId);
}
