<?php
declare(strict_types=1);

/**
 * End-to-end smoke checks: assets, core helpers, database, and optional local HTTP APIs.
 *
 * Usage:
 *   php scripts/verify_integrations.php
 *   set ALCROS_VERIFY_BASE_URL=http://localhost/alcros && php scripts/verify_integrations.php
 */

$root = dirname(__DIR__);
$warnings = [];
$failures = [];
$verifierOutput = [];

require_once $root . '/config/database.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/sms.php';

function runVerifier(string $root, string $script, array &$failures): array
{
    $path = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $script;
    if (!is_file($path)) {
        $failures[] = "Missing verifier: {$script}";
        return [];
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    if ($code !== 0) {
        $failures[] = $script . ' failed: ' . implode("\n", $output);
        return [];
    }

    return $output;
}

foreach (['verify_assets.php', 'verify_core.php', 'test_db_features.php'] as $script) {
    $verifierOutput = array_merge($verifierOutput, runVerifier($root, $script, $failures));
}

if (!isSmsEnabled() || !isSmsConfigured()) {
    $warnings[] = 'SMS is off or missing IPROG token (Settings → SMS). Citizen SMS will not send until configured.';
}

$smtpUser = trim(getSetting('smtp_user', getSetting('notification_email', '')));
if ($smtpUser === '') {
    $warnings[] = 'Gmail SMTP user is not set (Settings → Configuration). Email notices will fail.';
}

$cronSecretFile = $root . '/storage/cron_secret.txt';
if (!is_file($cronSecretFile) || trim((string) file_get_contents($cronSecretFile)) === '') {
    $warnings[] = 'storage/cron_secret.txt is missing; HTTP cron endpoints (appointment reminders, registry backup) need a secret.';
}

$base = getenv('ALCROS_VERIFY_BASE_URL') ?: '';
if ($base !== '') {
    $base = rtrim($base, '/');
    $checks = [
        'index.php',
        'services.php',
        'api/queue_status.php',
        'api/appointment_availability.php?date=' . date('Y-m-d', strtotime('+1 day')) . '&type=appointment',
        'api/request_status.php?code=INVALID',
    ];
    foreach ($checks as $path) {
        $url = $base . '/' . ltrim($path, '/');
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($status !== 200 || $body === false || $body === '') {
            $failures[] = "HTTP {$status} for {$url}";
            continue;
        }
        if (str_contains($path, 'api/') && !str_contains($path, 'INVALID')) {
            $json = json_decode($body, true);
            if (!is_array($json) || empty($json['ok'])) {
                $failures[] = "API JSON not ok for {$url}";
            }
        }
    }
    echo 'OK: HTTP smoke checks against ' . $base . PHP_EOL;
} else {
    $warnings[] = 'Set ALCROS_VERIFY_BASE_URL (e.g. http://localhost/alcros) to run HTTP smoke checks.';
}

if ($warnings !== []) {
    fwrite(STDERR, "Warnings:\n - " . implode("\n - ", $warnings) . PHP_EOL);
}

if ($failures !== []) {
    fwrite(STDERR, "Failures:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

if ($verifierOutput !== []) {
    echo implode(PHP_EOL, $verifierOutput) . PHP_EOL;
}

echo "OK: integration smoke checks passed\n";