<?php
/**
 * Hosted registry backup (SmarterASP.NET Schedule Tasks and other PHP hosts).
 * Scope: same as Duplicati bundle — civil records, staff, print calibration, print settings, related files.
 */

require_once __DIR__ . '/duplicati_backup.php';

function alcrosRegistryBackupEnabled(): bool
{
    return getSetting('registry_backup_enabled', '0') === '1';
}

function alcrosRegistryBackupCronToken(): string
{
    $token = trim(getSetting('registry_backup_cron_token', ''));
    if ($token !== '') {
        return $token;
    }

    $token = bin2hex(random_bytes(32));
    setSetting('registry_backup_cron_token', $token);

    return $token;
}

function alcrosRegistryBackupRegenerateCronToken(): string
{
    $token = bin2hex(random_bytes(32));
    setSetting('registry_backup_cron_token', $token);

    return $token;
}

function alcrosRegistryBackupPublicBaseUrl(): string
{
    $configured = rtrim(trim(getSetting('registry_backup_public_url', '')), '/');
    if ($configured !== '') {
        return $configured;
    }

    if (function_exists('appBaseUrl')) {
        $base = trim(appBaseUrl());
        if ($base !== '') {
            return rtrim($base, '/');
        }
    }

    return '';
}

function alcrosRegistryBackupCronUrl(): string
{
    $base = alcrosRegistryBackupPublicBaseUrl();
    if ($base === '') {
        return '/api/registry_backup.php?token=YOUR_TOKEN';
    }

    return $base . '/api/registry_backup.php?token=' . urlencode(alcrosRegistryBackupCronToken());
}

function alcrosRegistryBackupArchiveDir(): string
{
    $dir = alcrosProjectRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'registry';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create registry backup directory.');
    }

    require_once __DIR__ . '/hosting.php';
    alcrosWriteWebAccessDeny($dir);
    alcrosWriteWebAccessDeny(dirname($dir));

    return $dir;
}

function alcrosRegistryBackupLockPath(): string
{
    return alcrosProjectRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'registry_backup.lock';
}

function alcrosRegistryBackupAcquireLock(): bool
{
    $path = alcrosRegistryBackupLockPath();
    if (is_file($path)) {
        $age = time() - (int) @filemtime($path);
        if ($age < 900) {
            return false;
        }
        @unlink($path);
    }

    return file_put_contents($path, (string) getmypid() . "\n" . date('c'), LOCK_EX) !== false;
}

function alcrosRegistryBackupReleaseLock(): void
{
    $path = alcrosRegistryBackupLockPath();
    if (is_file($path)) {
        @unlink($path);
    }
}

function alcrosRegistryBackupRetentionCount(): int
{
    return max(3, min(90, (int) getSetting('registry_backup_retention_count', '14')));
}

/** @param list<string> $paths */
function alcrosZipAddPaths(ZipArchive $zip, array $paths, string $archiveRoot): void
{
    $archiveRoot = rtrim(str_replace('\\', '/', $archiveRoot), '/');

    foreach ($paths as $path) {
        if (!is_file($path) && !is_dir($path)) {
            continue;
        }

        $real = realpath($path);
        if ($real === false) {
            continue;
        }

        $real = str_replace('\\', '/', $real);
        if (!str_starts_with($real, $archiveRoot)) {
            continue;
        }

        $relative = ltrim(substr($real, strlen($archiveRoot)), '/');
        if ($relative === '') {
            continue;
        }

        if (is_file($real)) {
            $zip->addFile($real, $relative);

            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $itemPath = str_replace('\\', '/', $item->getPathname());
            $entry = ltrim(substr($itemPath, strlen($archiveRoot)), '/');
            if ($entry === '') {
                continue;
            }
            if ($item->isDir()) {
                $zip->addEmptyDir(rtrim($entry, '/'));
            } else {
                $zip->addFile($itemPath, $entry);
            }
        }
    }
}

function alcrosRegistryCreateZipFromBundle(string $bundleDir): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('PHP ZipArchive extension is required for registry backups.');
    }

    $bundleDir = realpath($bundleDir);
    if ($bundleDir === false) {
        throw new RuntimeException('Backup bundle directory is missing.');
    }

    $archiveDir = alcrosRegistryBackupArchiveDir();
    $zipName = 'alcros-registry-' . date('Y-m-d-His') . '.zip';
    $zipPath = $archiveDir . DIRECTORY_SEPARATOR . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create backup zip file.');
    }

    $root = str_replace('\\', '/', $bundleDir);
    alcrosZipAddPaths($zip, [
        $root . '/alcros-backup.sql',
        $root . '/manifest.json',
        $root . '/files',
    ], $root);

    $zip->close();

    if (!is_file($zipPath) || filesize($zipPath) === 0) {
        @unlink($zipPath);
        throw new RuntimeException('Backup zip was empty or could not be written.');
    }

    return $zipPath;
}

function alcrosRegistryPruneOldArchives(): int
{
    $dir = alcrosRegistryBackupArchiveDir();
    $files = glob($dir . DIRECTORY_SEPARATOR . 'alcros-registry-*.zip') ?: [];
    usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $keep = alcrosRegistryBackupRetentionCount();
    $removed = 0;
    foreach (array_slice($files, $keep) as $path) {
        if (@unlink($path)) {
            $removed++;
        }
    }

    return $removed;
}

/** @return list<array{filename: string, path: string, size: int, mtime: int}> */
function alcrosRegistryBackupListArchives(): array
{
    $dir = alcrosRegistryBackupArchiveDir();
    $files = glob($dir . DIRECTORY_SEPARATOR . 'alcros-registry-*.zip') ?: [];
    usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $items = [];
    foreach ($files as $path) {
        $items[] = [
            'filename' => basename($path),
            'path'     => $path,
            'size'     => (int) filesize($path),
            'mtime'    => (int) filemtime($path),
        ];
    }

    return $items;
}

function requireRegistryBackupCronToken(): void
{
    $expected = alcrosRegistryBackupCronToken();
    $provided = trim((string) ($_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_REGISTRY_BACKUP_TOKEN'] ?? ''));
    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

/**
 * @return array{ok: bool, message: string, zip?: string, filename?: string, bytes?: int, manifest?: array<string, mixed>, pruned?: int}
 */
function runAlcrosRegistryHostedBackup(PDO $pdo, bool $requireEnabled = true): array
{
    if ($requireEnabled && !alcrosRegistryBackupEnabled()) {
        return ['ok' => false, 'message' => 'Registry backup is disabled. Enable it in System Settings → Admin Tools → Backup.'];
    }

    if (!alcrosRegistryBackupAcquireLock()) {
        return ['ok' => false, 'message' => 'A registry backup is already running. Try again in a few minutes.'];
    }

    try {
        @set_time_limit(300);

        $bundle = runAlcrosDuplicatiBackup($pdo);
        if (!$bundle['ok']) {
            throw new RuntimeException($bundle['message']);
        }

        $bundleDir = alcrosDuplicatiBackupDir();
        $zipPath = alcrosRegistryCreateZipFromBundle($bundleDir);
        $pruned = alcrosRegistryPruneOldArchives();
        $filename = basename($zipPath);
        $bytes = (int) filesize($zipPath);

        setSetting('registry_backup_last_run_at', date('Y-m-d H:i:s'));
        setSetting('registry_backup_last_status', 'success');
        setSetting('registry_backup_last_message', 'Created ' . $filename);
        setSetting('registry_backup_last_zip_bytes', (string) $bytes);
        setSetting('registry_backup_last_filename', $filename);

        return [
            'ok'       => true,
            'message'  => 'Registry backup zip created.',
            'zip'      => str_replace('\\', '/', $zipPath),
            'filename' => $filename,
            'bytes'    => $bytes,
            'manifest' => $bundle['manifest'] ?? null,
            'pruned'   => $pruned,
        ];
    } catch (Throwable $e) {
        setSetting('registry_backup_last_run_at', date('Y-m-d H:i:s'));
        setSetting('registry_backup_last_status', 'error');
        setSetting('registry_backup_last_message', $e->getMessage());

        return [
            'ok'      => false,
            'message' => $e->getMessage(),
        ];
    } finally {
        alcrosRegistryBackupReleaseLock();
    }
}
