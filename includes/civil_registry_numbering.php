<?php

/**
 * Per-type civil registry number allocation (Birth, Death, Marriage).
 * Infers format from existing rows; uses MySQL advisory locks for safe concurrent inserts.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/civil_record_schema.php';

function ensureCivilRegistryNumberingSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT registry_number FROM civil_records LIMIT 1');
    } catch (Throwable $e) {
        return;
    }

    $hasIndex = false;
    try {
        $stmt = $pdo->query(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'civil_records'
               AND index_name = 'uniq_civil_registry_per_type'
             LIMIT 1"
        );
        $hasIndex = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $hasIndex = false;
    }

    if ($hasIndex) {
        return;
    }

    try {
        $pdo->exec(
            'CREATE UNIQUE INDEX uniq_civil_registry_per_type
             ON civil_records (record_type, registry_number)'
        );
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            error_log('ALCROS: Could not add uniq_civil_registry_per_type — duplicate registry numbers exist. Resolve duplicates and reload Records.');
        }
    }
}

function civilRegistryNormalizeRegistryNumber(?string $value): ?string
{
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return null;
    }

    return $value;
}

/** @return array{separator: string, seq_width: int, uses_year_prefix: bool} */
function civilRegistryInferNumberFormat(PDO $pdo, string $recordType): array
{
    $defaults = [
        'separator'        => '-',
        'seq_width'        => 6,
        'uses_year_prefix' => true,
    ];

    if (!in_array($recordType, ['birth', 'death', 'marriage'], true)) {
        return $defaults;
    }

    $stmt = $pdo->prepare(
        "SELECT registry_number FROM civil_records
         WHERE record_type = ?
           AND deleted_at IS NULL
           AND registry_number IS NOT NULL
           AND registry_number != ''
         ORDER BY id DESC
         LIMIT 200"
    );
    $stmt->execute([$recordType]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($rows === []) {
        $stored = trim(getSetting('civil_registry_number_format_' . $recordType, ''));
        if ($stored !== '' && preg_match('/^(\d)-(\d+)$/', $stored, $m)) {
            return [
                'separator'        => $m[1] === '1' ? '-' : '.',
                'seq_width'        => max(4, (int) $m[2]),
                'uses_year_prefix' => true,
            ];
        }

        return $defaults;
    }

    $maxWidth = 6;
    $separator = '-';
    $usesYear = false;

    foreach ($rows as $raw) {
        $raw = trim((string) $raw);
        if (preg_match('/^(\d{4})([-\.])(\d+)$/', $raw, $m)) {
            $usesYear = true;
            $separator = $m[2];
            $maxWidth = max($maxWidth, strlen($m[3]));
        } elseif (preg_match('/^(\d+)$/', $raw, $m)) {
            $usesYear = false;
            $maxWidth = max($maxWidth, strlen($m[1]));
        }
    }

    return [
        'separator'        => $separator,
        'seq_width'        => $maxWidth,
        'uses_year_prefix' => $usesYear,
    ];
}

function civilRegistryFormatSequenceNumber(array $format, int $year, int $sequence): string
{
    $seqWidth = max(1, (int) ($format['seq_width'] ?? 6));
    $sep = (string) ($format['separator'] ?? '-');
    $seqPart = str_pad((string) max(1, $sequence), $seqWidth, '0', STR_PAD_LEFT);

    if (!empty($format['uses_year_prefix'])) {
        return $year . $sep . $seqPart;
    }

    return $seqPart;
}

/** @return array<int, int> year => max sequence */
function civilRegistryMaxSequencesByYear(PDO $pdo, string $recordType, array $format): array
{
    $stmt = $pdo->prepare(
        "SELECT registry_number FROM civil_records
         WHERE record_type = ?
           AND deleted_at IS NULL
           AND registry_number IS NOT NULL
           AND registry_number != ''"
    );
    $stmt->execute([$recordType]);
    $byYear = [];
    $sep = preg_quote((string) ($format['separator'] ?? '-'), '/');
    $plainMax = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $raw = trim((string) ($row['registry_number'] ?? ''));
        if ($raw === '') {
            continue;
        }
        if (!empty($format['uses_year_prefix']) && preg_match('/^(\d{4})' . $sep . '(\d+)$/', $raw, $m)) {
            $year = (int) $m[1];
            $seq = (int) $m[2];
            $byYear[$year] = isset($byYear[$year]) ? max($byYear[$year], $seq) : $seq;
        } elseif (preg_match('/^(\d+)$/', $raw, $m)) {
            $plainMax = max($plainMax, (int) $m[1]);
        }
    }

    if ($plainMax > 0 && $byYear === []) {
        $byYear[0] = $plainMax;
    }

    return $byYear;
}

function civilRegistryLockName(string $recordType): string
{
    return 'alcros_civil_registry_' . $recordType;
}

function civilRegistryAcquireLock(PDO $pdo, string $recordType): void
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 20)');
    $stmt->execute([civilRegistryLockName($recordType)]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire registry number lock. Try again in a moment.');
    }
}

function civilRegistryReleaseLock(PDO $pdo, string $recordType): void
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([civilRegistryLockName($recordType)]);
}

function civilRegistryNumberExists(PDO $pdo, string $recordType, string $registryNumber, ?int $excludeRecordId = null): bool
{
    $registryNumber = civilRegistryNormalizeRegistryNumber($registryNumber);
    if ($registryNumber === null) {
        return false;
    }

    $sql = 'SELECT id FROM civil_records
            WHERE record_type = ?
              AND registry_number = ?
              AND deleted_at IS NULL';
    $params = [$recordType, $registryNumber];
    if ($excludeRecordId !== null && $excludeRecordId > 0) {
        $sql .= ' AND id != ?';
        $params[] = $excludeRecordId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

/**
 * Next registry number for a new record (manual create only — not imports).
 *
 * @param array{event_year?: int|null} $options
 */
function civilRegistryAllocateNextNumber(PDO $pdo, string $recordType, array $options = []): string
{
    if (!in_array($recordType, ['birth', 'death', 'marriage'], true)) {
        throw new InvalidArgumentException('Invalid record type for registry allocation.');
    }

    ensureCivilRegistryNumberingSchema($pdo);

    $format = civilRegistryInferNumberFormat($pdo, $recordType);
    $targetYear = (int) ($options['event_year'] ?? 0);
    if ($targetYear < 1900 || $targetYear > 2100) {
        $targetYear = (int) date('Y');
    }

    civilRegistryAcquireLock($pdo, $recordType);

    try {
        $byYear = civilRegistryMaxSequencesByYear($pdo, $recordType, $format);

        if (!empty($format['uses_year_prefix'])) {
            $nextSeq = ($byYear[$targetYear] ?? 0) + 1;
        } else {
            $nextSeq = ($byYear[0] ?? 0) + 1;
            $targetYear = 0;
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = !empty($format['uses_year_prefix'])
                ? civilRegistryFormatSequenceNumber($format, $targetYear, $nextSeq)
                : civilRegistryFormatSequenceNumber($format, $targetYear, $nextSeq);

            if (!civilRegistryNumberExists($pdo, $recordType, $candidate)) {
                return $candidate;
            }
            $nextSeq++;
        }

        throw new RuntimeException('Could not allocate a unique registry number.');
    } finally {
        civilRegistryReleaseLock($pdo, $recordType);
    }
}

function civilRegistryEventYearFromRecordData(array $data): ?int
{
    foreach (['event_date', 'birth_date', 'death_date', 'marriage_date'] as $key) {
        $raw = trim((string) ($data[$key] ?? ''));
        if ($raw !== '' && preg_match('/^(\d{4})/', $raw, $m)) {
            return (int) $m[1];
        }
    }

    return null;
}

/** Recently used book numbers for suggestions (datalist), not an exhaustive closed list. */
function civilRegistryBookSuggestionLimit(): int
{
    return 80;
}

/** @return list<string> */
function civilRegistryDistinctBookNumbers(PDO $pdo, string $recordType, ?int $limit = null): array
{
    if (!in_array($recordType, ['birth', 'death', 'marriage'], true)) {
        return [];
    }

    $limit = $limit ?? civilRegistryBookSuggestionLimit();
    $limit = max(1, min(200, $limit));

    $stmt = $pdo->prepare(
        "SELECT book_number FROM civil_records
         WHERE record_type = ?
           AND deleted_at IS NULL
           AND book_number IS NOT NULL
           AND book_number != ''
         GROUP BY book_number
         ORDER BY MAX(id) DESC, book_number + 0, book_number
         LIMIT " . (int) $limit
    );
    $stmt->execute([$recordType]);

    $books = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $book) {
        $book = trim((string) $book);
        if ($book !== '') {
            $books[] = $book;
        }
    }

    return $books;
}

function civilRegistryImportDuplicateMessage(string $recordType, string $registryNumber, int $existingId): string
{
    return 'Duplicate registry number "' . $registryNumber . '" for '
        . civilRecordTypeLabel($recordType)
        . ' (already used by record #' . $existingId . '). Row skipped — source data unchanged.';
}

/** @param array<string, true> $seenInFile */
function civilRegistryCsvImportRowError(PDO $pdo, array $parsed, string $importType, array &$seenInFile): ?string
{
    $registry = civilRegistryNormalizeRegistryNumber($parsed['registry_number'] ?? null);
    if ($registry === null) {
        return 'registry number is missing — add the original register number from the source file (imports are not auto-numbered).';
    }

    $key = $importType . "\0" . $registry;
    if (isset($seenInFile[$key])) {
        return 'duplicate registry number "' . $registry . '" in this file (same ' . civilRecordTypeLabel($importType) . ' type). Row skipped.';
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM civil_records
         WHERE record_type = ?
           AND registry_number = ?
           AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$importType, $registry]);
    $existingId = (int) $stmt->fetchColumn();
    if ($existingId > 0) {
        return civilRegistryImportDuplicateMessage($importType, $registry, $existingId);
    }

    $seenInFile[$key] = true;

    return null;
}

function civilRegistryCsvImportSaveError(PDOException $e, array $parsed, string $importType): string
{
    if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'uniq_civil_registry_per_type')) {
        $registry = civilRegistryNormalizeRegistryNumber($parsed['registry_number'] ?? null) ?? '?';

        return 'conflicting registry number "' . $registry . '" for ' . civilRecordTypeLabel($importType) . '. Row skipped.';
    }

    $name = civilRecordDisplayName($parsed);

    return 'could not save "' . $name . '".';
}
