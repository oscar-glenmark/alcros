<?php
/**
 * Live-site cloud backup (Hostinger / production).
 * Creates the ALCROS backup bundle and uploads a zip to Google Cloud Storage.
 */

require_once __DIR__ . '/duplicati_backup.php';

function alcrosIsLocalOfficeInstall(): bool
{
    if (PHP_OS_FAMILY === 'Windows' && is_dir('C:\\xampp')) {
        return true;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || $host === 'localhost' || str_starts_with($host, '127.0.0.1')) {
        return true;
    }

    return false;
}

function alcrosCloudBackupEnabled(): bool
{
    return getSetting('cloud_backup_enabled', '0') === '1';
}

function alcrosEnsureSecretsDir(): string
{
    $dir = alcrosProjectRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'secrets';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create secrets directory.');
    }

    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    return $dir;
}

function alcrosGcsServiceAccountPath(): string
{
    return alcrosEnsureSecretsDir() . DIRECTORY_SEPARATOR . 'gcs-service-account.json';
}

function alcrosGcsServiceAccountConfigured(): bool
{
    return is_file(alcrosGcsServiceAccountPath());
}

/** @return array<string, mixed>|null */
function alcrosGcsServiceAccount(): ?array
{
    $path = alcrosGcsServiceAccountPath();
    if (!is_file($path)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

function alcrosCloudBackupCronToken(): string
{
    $token = trim(getSetting('cloud_backup_cron_token', ''));
    if ($token !== '') {
        return $token;
    }

    $token = bin2hex(random_bytes(24));
    setSetting('cloud_backup_cron_token', $token);

    return $token;
}

function alcrosRegenerateCloudBackupCronToken(): string
{
    $token = bin2hex(random_bytes(24));
    setSetting('cloud_backup_cron_token', $token);

    return $token;
}

function alcrosCloudBackupPublicBaseUrl(): string
{
    $configured = trim(getSetting('cloud_backup_public_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/system_settings.php'));
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        foreach (['/api', '/cron', '/includes'] as $suffix) {
            if (str_ends_with($dir, $suffix)) {
                $dir = substr($dir, 0, -strlen($suffix));
            }
        }
        if ($dir === '/' || $dir === '.') {
            $dir = '';
        }

        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
    }

    return '';
}

function alcrosCloudBackupCronUrl(): string
{
    $base = alcrosCloudBackupPublicBaseUrl();
    if ($base === '') {
        return '/api/cloud-backup.php?token=YOUR_TOKEN';
    }

    return $base . '/api/cloud-backup.php?token=' . urlencode(alcrosCloudBackupCronToken());
}

function alcrosBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function alcrosGcsAccessToken(array $serviceAccount): string
{
    $email = (string) ($serviceAccount['client_email'] ?? '');
    $privateKey = (string) ($serviceAccount['private_key'] ?? '');
    if ($email === '' || $privateKey === '') {
        throw new RuntimeException('Google service account JSON is missing client_email or private_key.');
    }

    $now = time();
    $header = alcrosBase64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $payload = alcrosBase64UrlEncode(json_encode([
        'iss' => $email,
        'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ], JSON_THROW_ON_ERROR));

    $signed = '';
    $input = $header . '.' . $payload;
    if (!openssl_sign($input, $signed, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign Google Cloud access token.');
    }

    $jwt = $input . '.' . alcrosBase64UrlEncode($signed);
    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $response = file_get_contents('https://oauth2.googleapis.com/token', false, $context);
    if ($response === false) {
        throw new RuntimeException('Could not request Google Cloud access token.');
    }

    $json = json_decode($response, true);
    if (!is_array($json) || empty($json['access_token'])) {
        $error = is_array($json) ? (string) ($json['error_description'] ?? $json['error'] ?? $response) : $response;
        throw new RuntimeException('Google Cloud auth failed: ' . $error);
    }

    return (string) $json['access_token'];
}

/** @return array{status: int, body: string} */
function alcrosHttpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $headerLines = $headers;
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);

    $response = file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    return ['status' => $status, 'body' => $response === false ? '' : $response];
}

function alcrosCloudBackupZipPath(): string
{
    $dir = alcrosProjectRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'archives';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create backup archives directory.');
    }

    return $dir . DIRECTORY_SEPARATOR . 'alcros-backup-' . date('Y-m-d_His') . '.zip';
}

function alcrosCreateBackupZip(): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is not available on this server.');
    }

    $sourceDir = alcrosDuplicatiBackupDir();
    $zipPath = alcrosCloudBackupZipPath();
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create backup zip file.');
    }

    $files = [
        'alcros-backup.sql',
        'manifest.json',
    ];
    foreach ($files as $file) {
        $full = $sourceDir . DIRECTORY_SEPARATOR . $file;
        if (is_file($full)) {
            $zip->addFile($full, $file);
        }
    }

    $filesRoot = $sourceDir . DIRECTORY_SEPARATOR . 'files';
    if (is_dir($filesRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($filesRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            $relative = 'files/' . str_replace('\\', '/', $iterator->getSubPathName());
            $zip->addFile($fileInfo->getPathname(), $relative);
        }
    }

    $zip->close();

    if (!is_file($zipPath) || filesize($zipPath) === 0) {
        throw new RuntimeException('Backup zip file was not created.');
    }

    return $zipPath;
}

function alcrosCloudBackupObjectName(string $zipFilename): string
{
    $prefix = trim(getSetting('cloud_backup_gcs_prefix', 'alcros-backups'), '/');

    return ($prefix !== '' ? $prefix . '/' : '') . $zipFilename;
}

function alcrosCloudUploadZipToGcs(string $zipPath): array
{
    $bucket = trim(getSetting('cloud_backup_gcs_bucket', ''));
    if ($bucket === '') {
        throw new RuntimeException('Google Cloud Storage bucket name is not configured.');
    }

    $serviceAccount = alcrosGcsServiceAccount();
    if ($serviceAccount === null) {
        throw new RuntimeException('Google service account JSON is not uploaded yet.');
    }

    $token = alcrosGcsAccessToken($serviceAccount);
    $objectName = alcrosCloudBackupObjectName(basename($zipPath));
    $encodedName = rawurlencode($objectName);
    $url = 'https://storage.googleapis.com/upload/storage/v1/b/'
        . rawurlencode($bucket)
        . '/o?uploadType=media&name=' . $encodedName;

    $body = (string) file_get_contents($zipPath);
    $response = alcrosHttpRequest('POST', $url, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/zip',
        'Content-Length: ' . strlen($body),
    ], $body);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = trim($response['body']);
        if ($message !== '' && ($json = json_decode($message, true)) && is_array($json)) {
            $message = (string) ($json['error']['message'] ?? $message);
        }
        throw new RuntimeException('Cloud upload failed (HTTP ' . $response['status'] . '): ' . $message);
    }

    return [
        'bucket' => $bucket,
        'object' => $objectName,
        'bytes' => strlen($body),
    ];
}

function alcrosGcsBucketBrowserUrl(): string
{
    $bucket = trim(getSetting('cloud_backup_gcs_bucket', ''));
    if ($bucket === '') {
        return 'https://console.cloud.google.com/storage/browser';
    }

    $prefix = trim(getSetting('cloud_backup_gcs_prefix', 'alcros-backups'), '/');
    $path = rawurlencode($bucket);
    if ($prefix !== '') {
        $path .= '/' . implode('/', array_map('rawurlencode', explode('/', $prefix)));
    }

    return 'https://console.cloud.google.com/storage/browser/' . $path;
}

/** @return list<array{name: string, filename: string, timeCreated: string, size: int}> */
function alcrosCloudListGcsBackups(): array
{
    $bucket = trim(getSetting('cloud_backup_gcs_bucket', ''));
    if ($bucket === '') {
        return [];
    }

    $serviceAccount = alcrosGcsServiceAccount();
    if ($serviceAccount === null) {
        return [];
    }

    $prefix = trim(getSetting('cloud_backup_gcs_prefix', 'alcros-backups'), '/');
    $token = alcrosGcsAccessToken($serviceAccount);
    $url = 'https://storage.googleapis.com/storage/v1/b/'
        . rawurlencode($bucket)
        . '/o?prefix=' . rawurlencode($prefix === '' ? '' : $prefix . '/')
        . '&fields=items(name,timeCreated,size)';

    $response = alcrosHttpRequest('GET', $url, [
        'Authorization: Bearer ' . $token,
    ]);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = trim($response['body']);
        if ($message !== '' && ($json = json_decode($message, true)) && is_array($json)) {
            $message = (string) ($json['error']['message'] ?? $message);
        }
        throw new RuntimeException('Could not list cloud backups (HTTP ' . $response['status'] . '): ' . ($message !== '' ? $message : 'Unknown error'));
    }

    $json = json_decode($response['body'], true);
    if (!is_array($json) || empty($json['items']) || !is_array($json['items'])) {
        return [];
    }

    $items = [];
    foreach ($json['items'] as $item) {
        if (!is_array($item) || empty($item['name'])) {
            continue;
        }

        $name = (string) $item['name'];
        if (!str_ends_with(strtolower($name), '.zip')) {
            continue;
        }

        $items[] = [
            'name' => $name,
            'filename' => basename($name),
            'timeCreated' => (string) ($item['timeCreated'] ?? ''),
            'size' => (int) ($item['size'] ?? 0),
        ];
    }

    usort($items, static fn (array $a, array $b): int => strcmp($b['timeCreated'], $a['timeCreated']));

    return $items;
}

/** @return array{ok: bool, items: list<array{name: string, filename: string, timeCreated: string, size: int}>, message: string} */
function alcrosCloudFetchBackupFileList(): array
{
    $bucket = trim(getSetting('cloud_backup_gcs_bucket', ''));
    if ($bucket === '' || !alcrosGcsServiceAccountConfigured()) {
        return ['ok' => true, 'items' => [], 'message' => ''];
    }

    try {
        return [
            'ok' => true,
            'items' => alcrosCloudListGcsBackups(),
            'message' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'items' => [],
            'message' => $e->getMessage(),
        ];
    }
}

function alcrosCloudDeleteGcsObject(string $objectName): void
{
    $bucket = trim(getSetting('cloud_backup_gcs_bucket', ''));
    $serviceAccount = alcrosGcsServiceAccount();
    if ($bucket === '' || $serviceAccount === null) {
        return;
    }

    $token = alcrosGcsAccessToken($serviceAccount);
    $url = 'https://storage.googleapis.com/storage/v1/b/'
        . rawurlencode($bucket)
        . '/o/' . rawurlencode($objectName);

    alcrosHttpRequest('DELETE', $url, [
        'Authorization: Bearer ' . $token,
    ]);
}

function alcrosCloudPruneOldBackups(): int
{
    $keep = max(5, (int) getSetting('cloud_backup_retention_count', '30'));
    $items = alcrosCloudListGcsBackups();
    $deleted = 0;

    foreach (array_slice($items, $keep) as $item) {
        alcrosCloudDeleteGcsObject($item['name']);
        $deleted++;
    }

    return $deleted;
}

function alcrosSaveGcsServiceAccountUpload(array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Please choose a valid JSON service account file.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Upload failed. Try again.');
    }

    $json = json_decode((string) file_get_contents($tmp), true);
    if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
        throw new InvalidArgumentException('Invalid Google service account JSON file.');
    }

    $dest = alcrosGcsServiceAccountPath();
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save service account file.');
    }

    @chmod($dest, 0600);
}

function alcrosValidateCloudBackupCronToken(?string $token): bool
{
    $expected = trim(getSetting('cloud_backup_cron_token', ''));
    if ($expected === '' || $token === null || $token === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

/** @return array{ok: bool, message: string, manifest?: array<string, mixed>, upload?: array<string, mixed>} */
function runAlcrosLiveCloudBackup(PDO $pdo): array
{
    if (!alcrosCloudBackupEnabled()) {
        return ['ok' => false, 'message' => 'Cloud backup is disabled. Enable it in Cloud Backup settings first.'];
    }

    $bundle = runAlcrosDuplicatiBackup($pdo);
    if (!$bundle['ok']) {
        return $bundle;
    }

    $zipPath = null;
    try {
        $zipPath = alcrosCreateBackupZip();
        $upload = alcrosCloudUploadZipToGcs($zipPath);
        $pruned = 0;
        try {
            $pruned = alcrosCloudPruneOldBackups();
        } catch (Throwable $pruneError) {
            error_log('ALCROS cloud backup prune failed: ' . $pruneError->getMessage());
        }

        setSetting('cloud_backup_last_upload_at', date('Y-m-d H:i:s'));
        setSetting('cloud_backup_last_upload_status', 'success');
        setSetting('cloud_backup_last_upload_message', 'Uploaded ' . $upload['object']);
        setSetting('cloud_backup_last_upload_bytes', (string) ($upload['bytes'] ?? 0));

        return [
            'ok' => true,
            'message' => 'Live backup uploaded to Google Cloud Storage (' . alcrosFormatBytes((int) ($upload['bytes'] ?? 0)) . ').',
            'manifest' => $bundle['manifest'] ?? null,
            'upload' => $upload + ['pruned' => $pruned],
        ];
    } catch (Throwable $e) {
        setSetting('cloud_backup_last_upload_at', date('Y-m-d H:i:s'));
        setSetting('cloud_backup_last_upload_status', 'error');
        setSetting('cloud_backup_last_upload_message', $e->getMessage());

        return [
            'ok' => false,
            'message' => $e->getMessage(),
            'manifest' => $bundle['manifest'] ?? null,
        ];
    } finally {
        if ($zipPath !== null && is_file($zipPath)) {
            @unlink($zipPath);
        }
    }
}

/** @return array{ok: bool, message: string} */
function alcrosTestCloudBackupConnection(PDO $pdo): array
{
    try {
        if (!alcrosGcsServiceAccountConfigured()) {
            throw new RuntimeException('Upload the Google service account JSON file first.');
        }
        if (trim(getSetting('cloud_backup_gcs_bucket', '')) === '') {
            throw new RuntimeException('Enter your Google Cloud Storage bucket name.');
        }

        $serviceAccount = alcrosGcsServiceAccount();
        if ($serviceAccount === null) {
            throw new RuntimeException('Service account file could not be read.');
        }

        alcrosGcsAccessToken($serviceAccount);
        $items = alcrosCloudListGcsBackups();

        return [
            'ok' => true,
            'message' => 'Connected successfully. Found ' . count($items) . ' existing backup file(s) in the bucket.',
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
