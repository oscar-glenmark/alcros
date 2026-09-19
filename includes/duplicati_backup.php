<?php
/**
 * Local backup bundle for Duplicati (or any folder-sync tool).
 * Scope: civil records, staff accounts, print templates/calibrations, print settings, related files.
 */

function alcrosProjectRoot(): string
{
    return dirname(__DIR__);
}

function alcrosDuplicatiBackupDir(): string
{
    return alcrosProjectRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'duplicati';
}

/** @return list<string> */
function alcrosDuplicatiBackupTables(): array
{
    return [
        'civil_records',
        'birth_record_details',
        'death_record_details',
        'marriage_record_details',
        'staff',
        'print_templates',
        'print_fields',
        'print_calibrations',
    ];
}

/** @return list<string> Relative paths (forward slashes) copied into files/ */
function alcrosDuplicatiBackupFileSources(): array
{
    return [
        'uploads/staff',
        'assets/print/forms',
    ];
}

function alcrosDuplicatiEnsureBackupDir(): string
{
    $dir = alcrosDuplicatiBackupDir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create backup directory: ' . $dir);
    }

    $filesDir = $dir . DIRECTORY_SEPARATOR . 'files';
    if (!is_dir($filesDir) && !mkdir($filesDir, 0755, true) && !is_dir($filesDir)) {
        throw new RuntimeException('Could not create backup files directory.');
    }

    $htaccess = dirname($dir) . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    return $dir;
}

function alcrosDuplicatiFindMysqldump(): ?string
{
    $custom = trim((string) getenv('ALCROS_MYSQLDUMP'));
    if ($custom !== '' && is_file($custom)) {
        return $custom;
    }

    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 10.11\\bin\\mysqldump.exe',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    $which = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where mysqldump 2>nul' : 'command -v mysqldump 2>/dev/null';
    $output = [];
    $exitCode = 1;
    @exec($which, $output, $exitCode);
    if ($exitCode === 0 && !empty($output[0]) && is_file(trim($output[0]))) {
        return trim($output[0]);
    }

    return null;
}

/** @return array<string, int> */
function alcrosDuplicatiBackupRowCounts(PDO $pdo): array
{
    $counts = [];
    foreach (alcrosDuplicatiBackupTables() as $table) {
        try {
            $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        } catch (Throwable $e) {
            $counts[$table] = 0;
        }
    }

    try {
        $counts['print_settings'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM system_settings WHERE setting_key LIKE 'print\\_%'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $counts['print_settings'] = 0;
    }

    return $counts;
}

function alcrosDuplicatiBackupPrintSettingsSql(PDO $pdo): string
{
    $stmt = $pdo->query(
        "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'print\\_%' ORDER BY setting_key"
    );
    if ($stmt === false) {
        return '';
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return '';
    }

    $lines = [
        '',
        '-- ALCROS print template settings (system_settings)',
        'DELETE FROM system_settings WHERE setting_key LIKE ' . $pdo->quote('print_%') . ';',
    ];

    foreach ($rows as $row) {
        $key = (string) ($row['setting_key'] ?? '');
        $value = (string) ($row['setting_value'] ?? '');
        if ($key === '') {
            continue;
        }
        $lines[] = 'REPLACE INTO system_settings (setting_key, setting_value) VALUES ('
            . $pdo->quote($key) . ', ' . $pdo->quote($value) . ');';
    }

    return implode("\n", $lines) . "\n";
}

function alcrosDuplicatiRunMysqldump(string $mysqldump, string $outputFile): void
{
    $tables = alcrosDuplicatiBackupTables();
    $args = [
        escapeshellarg($mysqldump),
        '--host=' . escapeshellarg(DB_HOST),
        '--user=' . escapeshellarg(DB_USER),
        '--default-character-set=' . escapeshellarg(DB_CHARSET),
        '--single-transaction',
        '--quick',
        '--skip-add-locks',
        '--no-tablespaces',
        DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '',
        escapeshellarg(DB_NAME),
    ];

    foreach ($tables as $table) {
        $args[] = escapeshellarg($table);
    }

    $command = implode(' ', array_filter($args)) . ' > ' . escapeshellarg($outputFile) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($outputFile) || filesize($outputFile) === 0) {
        $message = trim(implode("\n", $output));
        if ($message === '' && is_file($outputFile)) {
            $message = trim((string) file_get_contents($outputFile));
        }
        throw new RuntimeException('mysqldump failed' . ($message !== '' ? ': ' . $message : '.'));
    }
}

function alcrosDuplicatiPdoTableDump(PDO $pdo, string $table): string
{
    $lines = ["DROP TABLE IF EXISTS `$table`;", ""];

    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    if (!$create || empty($create['Create Table'])) {
        throw new RuntimeException("Could not read schema for table `$table`.");
    }

    $lines[] = $create['Create Table'] . ';';
    $lines[] = '';

    $stmt = $pdo->query("SELECT * FROM `$table`");
    if ($stmt === false) {
        return implode("\n", $lines);
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns = array_keys($row);
        $values = [];
        foreach ($row as $value) {
            $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
        }
        $lines[] = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $values) . ');';
    }

    $lines[] = '';
    return implode("\n", $lines);
}

function alcrosDuplicatiWriteSqlDump(PDO $pdo, string $outputFile): string
{
    $mysqldump = alcrosDuplicatiFindMysqldump();
    if ($mysqldump !== null) {
        alcrosDuplicatiRunMysqldump($mysqldump, $outputFile);
        file_put_contents($outputFile, alcrosDuplicatiBackupPrintSettingsSql($pdo), FILE_APPEND);

        return 'mysqldump';
    }

    $parts = [
        '-- ALCROS Duplicati backup (PDO fallback)',
        '-- Generated ' . date('Y-m-d H:i:s'),
        'SET FOREIGN_KEY_CHECKS=0;',
        'SET NAMES ' . DB_CHARSET . ';',
        '',
    ];

    foreach (alcrosDuplicatiBackupTables() as $table) {
        $parts[] = alcrosDuplicatiPdoTableDump($pdo, $table);
    }

    $parts[] = alcrosDuplicatiBackupPrintSettingsSql($pdo);
    $parts[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $parts[] = '';

    if (file_put_contents($outputFile, implode("\n", $parts)) === false) {
        throw new RuntimeException('Could not write SQL backup file.');
    }

    return 'pdo';
}

function alcrosDuplicatiRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            alcrosDuplicatiRemoveTree($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function alcrosDuplicatiCopyTree(string $source, string $dest): int
{
    if (!is_dir($source)) {
        return 0;
    }

    if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
        throw new RuntimeException('Could not create directory: ' . $dest);
    }

    $copied = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new RuntimeException('Could not create directory: ' . $target);
            }
            continue;
        }

        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('Could not copy file: ' . $item->getPathname());
        }
        $copied++;
    }

    return $copied;
}

/** @return array{copied_files: int, sources: list<string>} */
function alcrosDuplicatiCopyBackupFiles(string $filesRoot): array
{
    $projectRoot = alcrosProjectRoot();
    $totalCopied = 0;
    $sources = [];

    foreach (alcrosDuplicatiBackupFileSources() as $relative) {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $source = $projectRoot . DIRECTORY_SEPARATOR . $relative;
        if (!is_dir($source)) {
            continue;
        }

        $dest = $filesRoot . DIRECTORY_SEPARATOR . $relative;
        alcrosDuplicatiRemoveTree($dest);
        $copied = alcrosDuplicatiCopyTree($source, $dest);
        $totalCopied += $copied;
        $sources[] = str_replace('\\', '/', $relative);
    }

    return ['copied_files' => $totalCopied, 'sources' => $sources];
}

/** @return array<string, mixed> */
function alcrosDuplicatiReadManifest(): ?array
{
    $path = alcrosDuplicatiBackupDir() . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($path)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

/** @return array{ok: bool, message: string, manifest?: array<string, mixed>} */
function runAlcrosDuplicatiBackup(PDO $pdo): array
{
    try {
        require_once __DIR__ . '/civil_record_schema.php';
        require_once __DIR__ . '/printing.php';

        ensureCivilRecordTypeTables($pdo);
        ensurePrintTables($pdo);
        ensurePrintDocumentKindColumn($pdo);

        $backupDir = alcrosDuplicatiEnsureBackupDir();
        $sqlFile = $backupDir . DIRECTORY_SEPARATOR . 'alcros-backup.sql';
        $manifestFile = $backupDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $filesRoot = $backupDir . DIRECTORY_SEPARATOR . 'files';

        $dumpMethod = alcrosDuplicatiWriteSqlDump($pdo, $sqlFile);
        $fileCopy = alcrosDuplicatiCopyBackupFiles($filesRoot);
        $counts = alcrosDuplicatiBackupRowCounts($pdo);

        $manifest = [
            'version' => 1,
            'product' => 'ALCROS',
            'generated_at' => date('c'),
            'database' => DB_NAME,
            'dump_method' => $dumpMethod,
            'tables' => alcrosDuplicatiBackupTables(),
            'print_settings_included' => true,
            'file_sources' => $fileCopy['sources'],
            'counts' => $counts,
            'files_copied' => $fileCopy['copied_files'],
            'sql_bytes' => is_file($sqlFile) ? filesize($sqlFile) : 0,
            'sql_sha256' => is_file($sqlFile) ? hash_file('sha256', $sqlFile) : '',
            'backup_dir' => str_replace('\\', '/', $backupDir),
        ];

        if (file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new RuntimeException('Could not write manifest.json.');
        }

        setSetting('backup_last_run_at', date('Y-m-d H:i:s'));
        setSetting('backup_last_status', 'success');
        setSetting('backup_last_message', 'Backup completed successfully.');
        setSetting('backup_last_sql_bytes', (string) ($manifest['sql_bytes'] ?? 0));
        setSetting('backup_last_files_copied', (string) ($manifest['files_copied'] ?? 0));

        return [
            'ok' => true,
            'message' => 'Backup completed successfully.',
            'manifest' => $manifest,
        ];
    } catch (Throwable $e) {
        setSetting('backup_last_run_at', date('Y-m-d H:i:s'));
        setSetting('backup_last_status', 'error');
        setSetting('backup_last_message', $e->getMessage());

        return [
            'ok' => false,
            'message' => $e->getMessage(),
        ];
    }
}

function alcrosDuplicatiBackupDirSize(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }

    return $size;
}

function alcrosFormatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    }

    return round($bytes / 1073741824, 2) . ' GB';
}
