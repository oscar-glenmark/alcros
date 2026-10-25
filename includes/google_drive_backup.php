<?php
/**
 * Upload registry backup zips to Google Drive.
 * Personal @gmail.com: OAuth (uses your Google storage).
 * Google Workspace: service account + folder on a Shared drive.
 */

require_once __DIR__ . '/duplicati_backup.php';

/** Shared backup folders are not created by the app — drive.file cannot see them (404). */
const ALCROS_GDRIVE_SCOPE = 'https://www.googleapis.com/auth/drive';
const ALCROS_GDRIVE_REGISTRY_PREFIX = 'alcros-registry-';

/** Multipart upload (single request) — reliable on shared hosts; resumable above this size. */
const ALCROS_GDRIVE_MULTIPART_MAX_BYTES = 80 * 1024 * 1024;

function alcrosGoogleDriveBackupEnabled(): bool
{
    return getSetting('registry_backup_gdrive_enabled', '0') === '1';
}

/**
 * Accept a raw folder ID or a full drive.google.com …/folders/ID URL.
 */
function alcrosGoogleDriveNormalizeFolderId(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }

    if (preg_match('#drive\.google\.com/(?:drive/)?(?:u/\d+/)?folders/([a-zA-Z0-9_-]+)#', $input, $matches)) {
        return $matches[1];
    }

    if (preg_match('#/folders/([a-zA-Z0-9_-]+)#', $input, $matches)) {
        return $matches[1];
    }

    $id = preg_replace('/\s+/', '', $input) ?? $input;

    return trim($id, ".,;:\t\n\r");
}

function alcrosGoogleDriveFolderId(): string
{
    return alcrosGoogleDriveNormalizeFolderId(getSetting('registry_backup_gdrive_folder_id', ''));
}

function alcrosGoogleDriveAuthMode(): string
{
    $mode = trim(getSetting('registry_backup_gdrive_auth_mode', 'oauth'));

    return $mode === 'service_account' ? 'service_account' : 'oauth';
}

function alcrosGoogleDriveOAuthClientId(): string
{
    return trim(getSetting('registry_backup_gdrive_oauth_client_id', ''));
}

function alcrosGoogleDriveOAuthClientSecret(): string
{
    return trim(getSetting('registry_backup_gdrive_oauth_client_secret', ''));
}

function alcrosGoogleDriveOAuthClientConfigured(): bool
{
    return alcrosGoogleDriveOAuthClientId() !== '' && alcrosGoogleDriveOAuthClientSecret() !== '';
}

function alcrosGoogleDriveOAuthRefreshToken(): string
{
    return trim(getSetting('registry_backup_gdrive_oauth_refresh_token', ''));
}

function alcrosGoogleDriveOAuthConnectedEmail(): string
{
    return trim(getSetting('registry_backup_gdrive_oauth_email', ''));
}

function alcrosGoogleDriveOAuthConnected(): bool
{
    return alcrosGoogleDriveOAuthRefreshToken() !== '';
}

function alcrosGoogleDriveAuthConfigured(): bool
{
    if (alcrosGoogleDriveAuthMode() === 'service_account') {
        return alcrosGoogleDriveServiceAccountConfigured();
    }

    return alcrosGoogleDriveOAuthConnected() && alcrosGoogleDriveOAuthClientConfigured();
}

function alcrosGoogleDriveOAuthRedirectUri(): string
{
    $configured = rtrim(trim(getSetting('registry_backup_public_url', '')), '/');
    if ($configured !== '') {
        return $configured . '/api/gdrive_oauth.php?action=callback';
    }
    if (function_exists('appBaseUrl')) {
        $base = rtrim(trim(appBaseUrl()), '/');
        if ($base !== '') {
            return $base . '/api/gdrive_oauth.php?action=callback';
        }
    }

    return '/api/gdrive_oauth.php?action=callback';
}

function alcrosGoogleDriveClearAccessTokenCache(): void
{
    setSetting('registry_backup_gdrive_token', '');
    setSetting('registry_backup_gdrive_token_exp', '0');
}

function alcrosGoogleDriveDisconnectOAuth(): void
{
    setSetting('registry_backup_gdrive_oauth_refresh_token', '');
    setSetting('registry_backup_gdrive_oauth_email', '');
    alcrosGoogleDriveClearAccessTokenCache();
}

/**
 * @return array{token: string, expires_at: int, refresh_token?: string}
 */
function alcrosGoogleDriveOAuthTokenRequest(array $params): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Google Drive backup.');
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Google token request failed: ' . ($curlError ?: 'unknown error'));
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 400 || !is_array($decoded) || empty($decoded['access_token'])) {
        $msg = is_array($decoded) ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? '') : '';
        throw new RuntimeException('Google sign-in failed' . ($msg !== '' ? ': ' . $msg : '') . '.');
    }

    return [
        'token'         => (string) $decoded['access_token'],
        'expires_at'    => time() + max(60, (int) ($decoded['expires_in'] ?? 3600)),
        'refresh_token' => isset($decoded['refresh_token']) ? (string) $decoded['refresh_token'] : '',
    ];
}

function alcrosGoogleDriveExchangeOAuthCode(string $code): void
{
    $code = trim($code);
    if ($code === '') {
        throw new InvalidArgumentException('Google did not return an authorization code.');
    }
    if (!alcrosGoogleDriveOAuthClientConfigured()) {
        throw new RuntimeException('Save OAuth Client ID and Client Secret before connecting Google Drive.');
    }

    $token = alcrosGoogleDriveOAuthTokenRequest([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => alcrosGoogleDriveOAuthRedirectUri(),
        'client_id'     => alcrosGoogleDriveOAuthClientId(),
        'client_secret' => alcrosGoogleDriveOAuthClientSecret(),
    ]);

    if ($token['refresh_token'] !== '') {
        setSetting('registry_backup_gdrive_oauth_refresh_token', $token['refresh_token']);
    } elseif (!alcrosGoogleDriveOAuthConnected()) {
        throw new RuntimeException('Google did not issue a refresh token. Remove ALCROS from your Google Account permissions and connect again.');
    }

    alcrosGoogleDriveClearAccessTokenCache();
    alcrosGoogleDriveOAuthStoreConnectedEmail($token['token']);
}

function alcrosGoogleDriveOAuthStoreConnectedEmail(string $accessToken): void
{
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!is_string($response)) {
        return;
    }
    $decoded = json_decode($response, true);
    if (is_array($decoded) && !empty($decoded['email'])) {
        setSetting('registry_backup_gdrive_oauth_email', (string) $decoded['email']);
    }
}

function alcrosGoogleDriveBeginOAuth(string $state): string
{
    if (!alcrosGoogleDriveOAuthClientConfigured()) {
        throw new RuntimeException('Save OAuth Client ID and Client Secret first.');
    }

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => alcrosGoogleDriveOAuthClientId(),
        'redirect_uri'  => alcrosGoogleDriveOAuthRedirectUri(),
        'response_type' => 'code',
        'scope'         => ALCROS_GDRIVE_SCOPE,
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ]);
}

function alcrosGoogleDriveHumanizeUploadError(string $message): string
{
    if (stripos($message, 'storage quota') === false && stripos($message, 'Service Accounts do not have storage quota') === false) {
        return $message;
    }

    if (alcrosGoogleDriveAuthMode() === 'service_account') {
        return 'Google blocked the upload: service accounts cannot store files in a personal “My Drive” folder. '
            . 'Switch sign-in method to “Personal Gmail (Google sign-in)” below, or use Google Workspace with a folder on a Shared drive.';
    }

    return $message;
}

function alcrosGoogleDriveRetentionCount(): int
{
    $custom = (int) getSetting('registry_backup_gdrive_retention_count', '0');
    if ($custom >= 3) {
        return min(90, $custom);
    }

    if (function_exists('alcrosRegistryBackupRetentionCount')) {
        return alcrosRegistryBackupRetentionCount();
    }

    return max(3, min(90, (int) getSetting('registry_backup_retention_count', '14')));
}

function alcrosGoogleDriveSecretsDir(): string
{
    $dir = alcrosProjectRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'secrets';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create secrets directory.');
    }

    require_once __DIR__ . '/hosting.php';
    alcrosWriteWebAccessDeny($dir);
    alcrosWriteWebAccessDeny(dirname($dir));

    return $dir;
}

function alcrosGoogleDriveServiceAccountPath(): string
{
    return alcrosGoogleDriveSecretsDir() . DIRECTORY_SEPARATOR . 'gdrive-service-account.json';
}

function alcrosGoogleDriveServiceAccountConfigured(): bool
{
    $path = alcrosGoogleDriveServiceAccountPath();

    return is_file($path) && filesize($path) > 32;
}

/** @return array<string, mixed>|null */
function alcrosGoogleDriveLoadServiceAccount(): ?array
{
    if (!alcrosGoogleDriveServiceAccountConfigured()) {
        return null;
    }

    $raw = file_get_contents(alcrosGoogleDriveServiceAccountPath());
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function alcrosGoogleDriveServiceAccountEmail(): string
{
    $data = alcrosGoogleDriveLoadServiceAccount();

    return is_array($data) ? trim((string) ($data['client_email'] ?? '')) : '';
}

/**
 * @param array<string, mixed> $serviceAccount
 */
function alcrosGoogleDriveValidateServiceAccount(array $serviceAccount): void
{
    foreach (['client_email', 'private_key', 'token_uri'] as $key) {
        if (trim((string) ($serviceAccount[$key] ?? '')) === '') {
            throw new InvalidArgumentException('Service account JSON is missing required field: ' . $key . '.');
        }
    }
}

function alcrosGoogleDriveSaveServiceAccountJson(string $json): void
{
    $json = trim($json);
    if ($json === '') {
        throw new InvalidArgumentException('Service account JSON is empty.');
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Service account JSON is not valid JSON.');
    }

    alcrosGoogleDriveValidateServiceAccount($data);

    $path = alcrosGoogleDriveServiceAccountPath();
    if (file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES)) === false) {
        throw new RuntimeException('Could not save service account JSON on the server.');
    }

    setSetting('registry_backup_gdrive_token', '');
    setSetting('registry_backup_gdrive_token_exp', '0');
}

function alcrosGoogleDriveRemoveServiceAccount(): void
{
    $path = alcrosGoogleDriveServiceAccountPath();
    if (is_file($path)) {
        @unlink($path);
    }
    setSetting('registry_backup_gdrive_token', '');
    setSetting('registry_backup_gdrive_token_exp', '0');
}

function alcrosGoogleDriveBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** @param array<string, mixed> $serviceAccount */
function alcrosGoogleDriveCreateJwt(array $serviceAccount): string
{
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iss'   => (string) $serviceAccount['client_email'],
        'scope' => ALCROS_GDRIVE_SCOPE,
        'aud'   => (string) ($serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $segments = [
        alcrosGoogleDriveBase64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
        alcrosGoogleDriveBase64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $signingInput = implode('.', $segments);

    $privateKey = openssl_pkey_get_private((string) $serviceAccount['private_key']);
    if ($privateKey === false) {
        throw new RuntimeException('Service account private key is invalid.');
    }

    $signature = '';
    if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign Google Drive access token request.');
    }

    $segments[] = alcrosGoogleDriveBase64UrlEncode($signature);

    return implode('.', $segments);
}

/** @return array{token: string, expires_at: int} */
function alcrosGoogleDriveFetchServiceAccountAccessToken(): array
{
    $serviceAccount = alcrosGoogleDriveLoadServiceAccount();
    if ($serviceAccount === null) {
        throw new RuntimeException('Google Drive service account is not configured.');
    }

    alcrosGoogleDriveValidateServiceAccount($serviceAccount);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Google Drive backup.');
    }

    $jwt = alcrosGoogleDriveCreateJwt($serviceAccount);
    $tokenUri = (string) ($serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);

    $ch = curl_init($tokenUri);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Google token request failed: ' . ($curlError ?: 'unknown error'));
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 400 || !is_array($decoded) || empty($decoded['access_token'])) {
        $msg = is_array($decoded) ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? '') : '';
        throw new RuntimeException('Google token request rejected' . ($msg !== '' ? ': ' . $msg : '') . '.');
    }

    $expiresIn = max(60, (int) ($decoded['expires_in'] ?? 3600));

    return [
        'token'      => (string) $decoded['access_token'],
        'expires_at' => time() + $expiresIn,
    ];
}

/** @return array{token: string, expires_at: int} */
function alcrosGoogleDriveFetchOAuthAccessToken(): array
{
    if (!alcrosGoogleDriveOAuthClientConfigured()) {
        throw new RuntimeException('Google OAuth Client ID and Client Secret are not saved.');
    }
    $refresh = alcrosGoogleDriveOAuthRefreshToken();
    if ($refresh === '') {
        throw new RuntimeException('Connect Google Drive (Google sign-in) in System Settings → Backup.');
    }

    $token = alcrosGoogleDriveOAuthTokenRequest([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id'     => alcrosGoogleDriveOAuthClientId(),
        'client_secret' => alcrosGoogleDriveOAuthClientSecret(),
    ]);

    return [
        'token'      => $token['token'],
        'expires_at' => $token['expires_at'],
    ];
}

/** @return array{token: string, expires_at: int} */
function alcrosGoogleDriveFetchAccessToken(): array
{
    if (alcrosGoogleDriveAuthMode() === 'service_account') {
        return alcrosGoogleDriveFetchServiceAccountAccessToken();
    }

    return alcrosGoogleDriveFetchOAuthAccessToken();
}

function alcrosGoogleDriveAccessToken(): string
{
    $cached = trim(getSetting('registry_backup_gdrive_token', ''));
    $expiresAt = (int) getSetting('registry_backup_gdrive_token_exp', '0');
    if ($cached !== '' && time() < $expiresAt - 120) {
        return $cached;
    }

    $fresh = alcrosGoogleDriveFetchAccessToken();
    setSetting('registry_backup_gdrive_token', $fresh['token']);
    setSetting('registry_backup_gdrive_token_exp', (string) $fresh['expires_at']);

    return $fresh['token'];
}

/**
 * @return array{ok: bool, status: int, body: string}
 */
function alcrosGoogleDriveApiRequest(string $method, string $url, string $accessToken, ?string $body = null, array $extraHeaders = []): array
{
    $headers = array_merge(['Authorization: Bearer ' . $accessToken], $extraHeaders);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 300,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Google Drive API request failed: ' . ($curlError ?: 'unknown error'));
    }

    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'body'   => $response,
    ];
}

/**
 * @return array{ok: bool, message: string, file_id?: string}
 */
function alcrosGoogleDriveUploadRegistryZip(string $zipPath, string $filename): array
{
    if (!alcrosGoogleDriveBackupEnabled()) {
        return ['ok' => false, 'message' => 'Google Drive backup is disabled.'];
    }

    $folderId = alcrosGoogleDriveFolderId();
    if ($folderId === '') {
        return ['ok' => false, 'message' => 'Google Drive folder ID is not set.'];
    }

    if (!alcrosGoogleDriveAuthConfigured()) {
        return ['ok' => false, 'message' => 'Google Drive is not connected. Complete setup under System Settings → Backup.'];
    }

    if (!is_file($zipPath) || filesize($zipPath) === 0) {
        return ['ok' => false, 'message' => 'Backup zip file is missing or empty.'];
    }

    $accessToken = alcrosGoogleDriveAccessToken();
    $bytes = (int) filesize($zipPath);
    $mime = 'application/zip';

    if ($bytes <= ALCROS_GDRIVE_MULTIPART_MAX_BYTES) {
        $multipart = alcrosGoogleDriveUploadMultipart($accessToken, $zipPath, $filename, $folderId, $mime);
        if ($multipart['ok']) {
            alcrosGoogleDriveMarkUploadSuccess($filename, $multipart['file_id'] ?? '');

            return $multipart;
        }
        $multipart['message'] = alcrosGoogleDriveHumanizeUploadError($multipart['message']);

        return $multipart;
    }

    $metadata = json_encode([
        'name'    => $filename,
        'parents' => [$folderId],
    ], JSON_UNESCAPED_SLASHES);

    $session = alcrosGoogleDriveBeginResumableUpload($accessToken, $metadata, $mime, $bytes);
    if (($session['url'] ?? '') === '') {
        $detail = trim((string) ($session['error'] ?? ''));
        $msg = 'Google Drive upload could not start.';
        if ($detail !== '') {
            $msg .= ' ' . $detail;
        } else {
            $msg .= ' Check folder ID and that the folder is shared with the service account as Editor.';
        }

        return ['ok' => false, 'message' => alcrosGoogleDriveHumanizeUploadError($msg)];
    }

    $fileHandle = fopen($zipPath, 'rb');
    if ($fileHandle === false) {
        return ['ok' => false, 'message' => 'Could not read backup zip for upload.'];
    }

    $ch = curl_init($session['url']);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $fileHandle,
        CURLOPT_INFILESIZE     => $bytes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: ' . $mime,
            'Content-Length: ' . (string) $bytes,
        ],
        CURLOPT_TIMEOUT        => 600,
    ]);
    $uploadBody = curl_exec($ch);
    $uploadStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fileHandle);

    if ($uploadBody === false) {
        return ['ok' => false, 'message' => 'Drive upload failed: ' . ($curlError ?: 'unknown error')];
    }

    if ($uploadStatus < 200 || $uploadStatus >= 300) {
        $err = alcrosGoogleDriveApiErrorMessage($uploadBody);

        $msg = 'Drive upload rejected' . ($err !== '' ? ': ' . $err : '.');

        return ['ok' => false, 'message' => alcrosGoogleDriveHumanizeUploadError($msg)];
    }

    $decoded = json_decode($uploadBody, true);
    $fileId = is_array($decoded) ? trim((string) ($decoded['id'] ?? '')) : '';

    alcrosGoogleDriveMarkUploadSuccess($filename, $fileId);

    return [
        'ok'      => true,
        'message' => 'Uploaded to Google Drive.',
        'file_id' => $fileId,
    ];
}

function alcrosGoogleDriveMarkUploadSuccess(string $filename, string $fileId): void
{
    setSetting('registry_backup_gdrive_last_run_at', date('Y-m-d H:i:s'));
    setSetting('registry_backup_gdrive_last_status', 'success');
    setSetting('registry_backup_gdrive_last_message', 'Uploaded ' . $filename);
    setSetting('registry_backup_gdrive_last_file_id', $fileId);
}

/**
 * @return array{ok: bool, message: string, file_id?: string}
 */
function alcrosGoogleDriveUploadMultipart(
    string $accessToken,
    string $zipPath,
    string $filename,
    string $folderId,
    string $mime
): array {
    $fileData = file_get_contents($zipPath);
    if ($fileData === false) {
        return ['ok' => false, 'message' => 'Could not read backup zip for upload.'];
    }

    $metadata = json_encode([
        'name'    => $filename,
        'parents' => [$folderId],
    ], JSON_UNESCAPED_SLASHES);

    $boundary = 'alcros_gdrive_' . bin2hex(random_bytes(8));
    $delimiter = "\r\n";
    $body = '--' . $boundary . $delimiter
        . 'Content-Type: application/json; charset=UTF-8' . $delimiter . $delimiter
        . $metadata . $delimiter
        . '--' . $boundary . $delimiter
        . 'Content-Type: ' . $mime . $delimiter . $delimiter
        . $fileData . $delimiter
        . '--' . $boundary . '--';

    $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name&supportsAllDrives=true';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . (string) strlen($body),
        ],
        CURLOPT_TIMEOUT        => 600,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'message' => 'Drive upload failed: ' . ($curlError ?: 'unknown error')];
    }

    if ($status < 200 || $status >= 300) {
        $err = alcrosGoogleDriveApiErrorMessage($response);

        $msg = 'Drive upload rejected' . ($err !== '' ? ': ' . $err : ' (HTTP ' . $status . ').');

        return ['ok' => false, 'message' => alcrosGoogleDriveHumanizeUploadError($msg)];
    }

    $decoded = json_decode($response, true);
    $fileId = is_array($decoded) ? trim((string) ($decoded['id'] ?? '')) : '';

    return [
        'ok'      => true,
        'message' => 'Uploaded to Google Drive.',
        'file_id' => $fileId,
    ];
}

/**
 * @return array{url: string, error: string}
 */
function alcrosGoogleDriveBeginResumableUpload(string $accessToken, string $metadataJson, string $mime, int $bytes): array
{
    $location = '';
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name&supportsAllDrives=true');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $metadataJson,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$location): int {
            if (stripos($headerLine, 'Location:') === 0) {
                $location = trim(substr($headerLine, 9));
            }

            return strlen($headerLine);
        },
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $mime,
            'X-Upload-Content-Length: ' . (string) $bytes,
        ],
        CURLOPT_TIMEOUT        => 120,
    ]);
    $responseBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $location = trim($location, " \t\n\r");
    if ($location !== '' && $status >= 200 && $status < 300) {
        return ['url' => $location, 'error' => ''];
    }

    $err = is_string($responseBody) ? alcrosGoogleDriveApiErrorMessage($responseBody) : '';
    if ($err === '' && $responseBody === false) {
        $err = $curlError ?: 'Unknown cURL error';
    }
    if ($err === '' && $status > 0) {
        $err = 'HTTP ' . $status;
    }

    return ['url' => '', 'error' => $err];
}

function alcrosGoogleDriveApiErrorMessage(string $body): string
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return '';
    }

    $error = $decoded['error'] ?? null;
    if (is_string($error)) {
        return $error;
    }
    if (is_array($error)) {
        $message = trim((string) ($error['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }
    }

    return trim((string) ($decoded['error_description'] ?? ''));
}

function alcrosGoogleDrivePruneOldUploads(): int
{
    $folderId = alcrosGoogleDriveFolderId();
    if ($folderId === '' || !alcrosGoogleDriveAuthConfigured()) {
        return 0;
    }

    $accessToken = alcrosGoogleDriveAccessToken();
    $query = rawurlencode(
        "'" . str_replace("'", "\\'", $folderId) . "' in parents and trashed = false and name contains '" . ALCROS_GDRIVE_REGISTRY_PREFIX . "'"
    );
    $url = 'https://www.googleapis.com/drive/v3/files?q=' . $query
        . '&fields=files(id,name,createdTime)&orderBy=createdTime desc&pageSize=100&supportsAllDrives=true&includeItemsFromAllDrives=true';

    $list = alcrosGoogleDriveApiRequest('GET', $url, $accessToken);
    if (!$list['ok']) {
        return 0;
    }

    $decoded = json_decode($list['body'], true);
    $files = is_array($decoded) ? ($decoded['files'] ?? []) : [];
    if (!is_array($files)) {
        return 0;
    }

    $keep = alcrosGoogleDriveRetentionCount();
    $toDelete = array_slice($files, $keep);
    $removed = 0;

    foreach ($toDelete as $file) {
        if (!is_array($file)) {
            continue;
        }
        $id = trim((string) ($file['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $delete = alcrosGoogleDriveApiRequest(
            'DELETE',
            'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id),
            $accessToken
        );
        if ($delete['ok'] || $delete['status'] === 404) {
            $removed++;
        }
    }

    return $removed;
}

/**
 * @return array{ok: bool, message: string}
 */
function alcrosGoogleDriveTestConnection(): array
{
    if (!alcrosGoogleDriveAuthConfigured()) {
        return ['ok' => false, 'message' => 'Complete Google Drive setup (sign-in or service account) first.'];
    }

    $folderId = alcrosGoogleDriveFolderId();
    if ($folderId === '') {
        return ['ok' => false, 'message' => 'Enter the Google Drive folder ID.'];
    }

    try {
        $accessToken = alcrosGoogleDriveAccessToken();
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($folderId)
        . '?fields=id,name,mimeType&supportsAllDrives=true';
    $check = alcrosGoogleDriveApiRequest('GET', $url, $accessToken);
    if (!$check['ok']) {
        $err = alcrosGoogleDriveApiErrorMessage($check['body']);
        if (alcrosGoogleDriveAuthMode() === 'oauth') {
            $hint = ' Use a folder in the same Google account you connected'
                . (alcrosGoogleDriveOAuthConnectedEmail() !== '' ? ' (' . alcrosGoogleDriveOAuthConnectedEmail() . ')' : '')
                . ', and check the folder ID.';
        } else {
            $email = alcrosGoogleDriveServiceAccountEmail();
            $hint = $email !== ''
                ? ' Share the folder (on a Workspace Shared drive) with ' . $email . ' as Editor.'
                : ' Share the folder with the service account as Editor (Shared drive only).';
        }

        return [
            'ok'      => false,
            'message' => 'Could not access folder.' . $hint
                . ($err !== '' ? ' Google says: ' . $err : ''),
        ];
    }

    $decoded = json_decode($check['body'], true);
    $name = is_array($decoded) ? (string) ($decoded['name'] ?? 'Folder') : 'Folder';

    return ['ok' => true, 'message' => 'Connected. Folder: ' . $name];
}

/**
 * After a local registry zip is created, upload to Drive when enabled.
 *
 * @return array{ok: bool, message: string, file_id?: string, pruned?: int}|null null when skipped
 */
function alcrosGoogleDriveMaybeUploadRegistryBackup(string $zipPath, string $filename): ?array
{
    if (!alcrosGoogleDriveBackupEnabled()) {
        return null;
    }

    try {
        $upload = alcrosGoogleDriveUploadRegistryZip($zipPath, $filename);
        if (!$upload['ok']) {
            setSetting('registry_backup_gdrive_last_run_at', date('Y-m-d H:i:s'));
            setSetting('registry_backup_gdrive_last_status', 'error');
            setSetting('registry_backup_gdrive_last_message', $upload['message']);

            return $upload;
        }

        $pruned = alcrosGoogleDrivePruneOldUploads();
        $upload['pruned'] = $pruned;

        return $upload;
    } catch (Throwable $e) {
        setSetting('registry_backup_gdrive_last_run_at', date('Y-m-d H:i:s'));
        setSetting('registry_backup_gdrive_last_status', 'error');
        setSetting('registry_backup_gdrive_last_message', $e->getMessage());

        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
