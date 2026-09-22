<?php
/**
 * System-wide error detection, staff alerts, and maintenance fixes.
 */

function ensureSystemErrorsTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_errors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            error_key VARCHAR(80) NOT NULL UNIQUE,
            category VARCHAR(30) NOT NULL DEFAULT 'system',
            title VARCHAR(150) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            fix_action VARCHAR(50) NOT NULL DEFAULT 'acknowledge',
            href VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            resolved_by VARCHAR(30) DEFAULT NULL,
            INDEX idx_active (resolved_at, updated_at)
        ) ENGINE=InnoDB"
    );
}

function systemErrorsMaintenanceUrl(): string
{
    return buildAuthUrl('system_settings.php', ['tab' => 'admin-tools', 'admin_sub' => 'maintenance']);
}

/** Insert or update an error row without reopening one the staff already resolved. */
function upsertSystemError(
    PDO $pdo,
    string $errorKey,
    string $category,
    string $title,
    string $reason,
    string $fixAction,
    ?string $href = null
): void {
    ensureSystemErrorsTable($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO system_errors (error_key, category, title, reason, fix_action, href, resolved_at, resolved_by)
         VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            category = IF(resolved_at IS NULL, VALUES(category), category),
            title = IF(resolved_at IS NULL, VALUES(title), title),
            reason = IF(resolved_at IS NULL, VALUES(reason), reason),
            fix_action = IF(resolved_at IS NULL, VALUES(fix_action), fix_action),
            href = IF(resolved_at IS NULL, VALUES(href), href),
            updated_at = IF(resolved_at IS NULL, CURRENT_TIMESTAMP, updated_at)'
    );
    $stmt->execute([
        $errorKey,
        $category,
        $title,
        mb_substr($reason, 0, 500),
        $fixAction,
        $href ?? systemErrorsMaintenanceUrl(),
    ]);
}

/** Open or reopen an active system error when a condition is currently detected. */
function raiseSystemError(
    PDO $pdo,
    string $errorKey,
    string $category,
    string $title,
    string $reason,
    string $fixAction,
    ?string $href = null
): void {
    ensureSystemErrorsTable($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO system_errors (error_key, category, title, reason, fix_action, href, resolved_at, resolved_by)
         VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            category = VALUES(category),
            title = VALUES(title),
            reason = VALUES(reason),
            fix_action = VALUES(fix_action),
            href = VALUES(href),
            resolved_at = NULL,
            resolved_by = NULL,
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        $errorKey,
        $category,
        $title,
        mb_substr($reason, 0, 500),
        $fixAction,
        $href ?? systemErrorsMaintenanceUrl(),
    ]);
}

function resolveSystemError(PDO $pdo, string $errorKey, ?string $staffId = null): bool
{
    ensureSystemErrorsTable($pdo);
    $stmt = $pdo->prepare(
        'UPDATE system_errors
         SET resolved_at = NOW(), resolved_by = ?
         WHERE error_key = ? AND resolved_at IS NULL'
    );
    $stmt->execute([$staffId, $errorKey]);

    return $stmt->rowCount() > 0;
}

function resolveSystemErrorIfActive(PDO $pdo, string $errorKey, ?string $staffId = null): void
{
    resolveSystemError($pdo, $errorKey, $staffId);
}

/** @return list<array<string, mixed>> */
function fetchActiveSystemErrors(PDO $pdo): array
{
    ensureSystemErrorsTable($pdo);
    $stmt = $pdo->query(
        'SELECT error_key, category, title, reason, fix_action, href, created_at, updated_at
         FROM system_errors
         WHERE resolved_at IS NULL
         ORDER BY updated_at DESC'
    );

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function countActiveSystemErrors(PDO $pdo): int
{
    ensureSystemErrorsTable($pdo);

    return (int) $pdo->query('SELECT COUNT(*) FROM system_errors WHERE resolved_at IS NULL')->fetchColumn();
}

function reminderSchedulerTickPath(): string
{
    return __DIR__ . '/../storage/last_reminder_tick.txt';
}

function reminderSchedulerLockPath(): string
{
    return __DIR__ . '/../storage/appointment_reminders.lock';
}

function reminderSchedulerLastTick(): int
{
    $tickFile = reminderSchedulerTickPath();
    if (!is_file($tickFile)) {
        return 0;
    }

    return (int) trim((string) file_get_contents($tickFile));
}

function reminderSchedulerIsStale(int $maxAgeSeconds = 1800): bool
{
    $last = reminderSchedulerLastTick();
    if ($last <= 0) {
        return false;
    }

    return (time() - $last) > max(300, $maxAgeSeconds);
}

function normalizeReminderSchedulerLockFile(): void
{
    $lockFile = reminderSchedulerLockPath();
    if (!is_file($lockFile)) {
        return;
    }

    $handle = @fopen($lockFile, 'c');
    if (!$handle) {
        return;
    }

    if (flock($handle, LOCK_EX | LOCK_NB)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink($lockFile);
        return;
    }

    fclose($handle);
}

function reminderSchedulerLockIsStale(int $maxAgeSeconds = 600): bool
{
    normalizeReminderSchedulerLockFile();

    $lockFile = reminderSchedulerLockPath();
    if (!is_file($lockFile)) {
        return false;
    }

    $handle = @fopen($lockFile, 'c');
    if (!$handle) {
        return false;
    }

    if (flock($handle, LOCK_EX | LOCK_NB)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink($lockFile);
        return false;
    }

    fclose($handle);

    $mtime = @filemtime($lockFile);

    return $mtime !== false && (time() - $mtime) > max(120, $maxAgeSeconds);
}

function clearReminderSchedulerLock(): bool
{
    normalizeReminderSchedulerLockFile();

    $lockFile = reminderSchedulerLockPath();
    if (!is_file($lockFile)) {
        return true;
    }

    $handle = @fopen($lockFile, 'c');
    if (!$handle) {
        return @unlink($lockFile);
    }

    if (flock($handle, LOCK_EX | LOCK_NB)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return @unlink($lockFile);
    }

    fclose($handle);

    return false;
}

function touchReminderSchedulerTick(): void
{
    $tickFile = reminderSchedulerTickPath();
    $tickDir = dirname($tickFile);
    if (!is_dir($tickDir)) {
        @mkdir($tickDir, 0755, true);
    }
    @file_put_contents($tickFile, (string) time(), LOCK_EX);
}

function deliveryFailureAckSettingKey(string $channel): string
{
    return $channel === 'sms' ? 'sms_delivery_failures_ack_at' : 'email_delivery_failures_ack_at';
}

function getDeliveryFailureAckTime(string $channel): ?string
{
    $value = trim(getSetting(deliveryFailureAckSettingKey($channel), ''));

    return $value !== '' ? $value : null;
}

function acknowledgeDeliveryFailures(string $channel): void
{
    try {
        $now = getDB()->query('SELECT NOW()')->fetchColumn();
        setSetting(deliveryFailureAckSettingKey($channel), (string) ($now ?: date('Y-m-d H:i:s')));
    } catch (Throwable $e) {
        setSetting(deliveryFailureAckSettingKey($channel), date('Y-m-d H:i:s'));
    }
}

/** Mark all failed email_log rows in the window as reviewed (uses MySQL time, same as sent_at). */
function acknowledgeEmailLogFailuresThrough(PDO $pdo, int $hours = 24): void
{
    ensureExtendedSchema($pdo);
    $stmt = $pdo->prepare(
        'SELECT MAX(sent_at) AS max_sent FROM email_logs
         WHERE success = 0 AND sent_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)'
    );
    $stmt->execute([max(1, $hours)]);
    $maxSent = $stmt->fetchColumn();
    if ($maxSent !== false && $maxSent !== null && (string) $maxSent !== '') {
        setSetting(deliveryFailureAckSettingKey('email'), (string) $maxSent);
    } else {
        acknowledgeDeliveryFailures('email');
    }
}

function smsSenderPendingAckSettingKey(): string
{
    return 'sms_sender_pending_ack_at';
}

function getSmsSenderPendingAckTime(): ?string
{
    $value = trim(getSetting(smsSenderPendingAckSettingKey(), ''));

    return $value !== '' ? $value : null;
}

function acknowledgeSmsSenderPending(): void
{
    setSetting(smsSenderPendingAckSettingKey(), date('Y-m-d H:i:s'));
}

function clearSmsSenderPendingAck(): void
{
    setSetting(smsSenderPendingAckSettingKey(), '');
}

function recentDeliveryFailureSummary(
    PDO $pdo,
    string $table,
    int $hours = 24,
    ?string $since = null,
    bool $sinceInclusive = false
): ?array
{
    if (!in_array($table, ['email_logs', 'sms_logs'], true)) {
        return null;
    }

    ensureExtendedSchema($pdo);
    $sql =
        "SELECT COUNT(*) AS fail_count,
                MAX(sent_at) AS latest_at,
                SUBSTRING_INDEX(GROUP_CONCAT(error_message ORDER BY sent_at DESC SEPARATOR '||'), '||', 1) AS latest_reason
         FROM {$table}
         WHERE success = 0
           AND sent_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)";
    $params = [max(1, $hours)];

    if ($since !== null && trim($since) !== '') {
        // Ignore failures at or before the ack timestamp (1s slack avoids same-second log rows re-triggering alerts).
        $sql .= ' AND sent_at > DATE_ADD(?, INTERVAL 1 SECOND)';
        $params[] = $since;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['fail_count'] ?? 0) <= 0) {
        return null;
    }

    return [
        'count'  => (int) $row['fail_count'],
        'latest' => (string) ($row['latest_at'] ?? ''),
        'reason' => trim((string) ($row['latest_reason'] ?? '')),
    ];
}

/** Failures logged before Gmail SMTP was saved (not current delivery problems). */
function emailLogErrorIsMissingSmtpConfig(string $reason): bool
{
    $reason = strtolower(trim($reason));
    if ($reason === '') {
        return false;
    }

    return str_contains($reason, 'not configured')
        || str_contains($reason, 'gmail smtp is not');
}

/** True when every failed email_log in the window is from missing Gmail SMTP setup (historical). */
function allRecentEmailFailuresAreMissingSmtpConfig(PDO $pdo, int $hours = 24, ?string $since = null): bool
{
    ensureExtendedSchema($pdo);

    $sql =
        'SELECT error_message FROM email_logs
         WHERE success = 0
           AND sent_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)';
    $params = [max(1, $hours)];

    if ($since !== null && trim($since) !== '') {
        $sql .= ' AND sent_at > DATE_ADD(?, INTERVAL 1 SECOND)';
        $params[] = $since;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($rows === []) {
        return false;
    }

    foreach ($rows as $message) {
        if (!emailLogErrorIsMissingSmtpConfig((string) $message)) {
            return false;
        }
    }

    return true;
}

/**
 * Clear "email delivery failing" when Gmail is configured now but logs only show old missing-SMTP errors.
 */
function clearStaleEmailConfigFailureAlert(PDO $pdo): bool
{
    if (!isEmailConfigured()) {
        return false;
    }

    $since = getDeliveryFailureAckTime('email');
    if (!allRecentEmailFailuresAreMissingSmtpConfig($pdo, 24, $since)) {
        return false;
    }

    acknowledgeEmailLogFailuresThrough($pdo, 24);
    resolveSystemErrorIfActive($pdo, 'email-delivery-failed');

    return true;
}

function syncSystemErrors(PDO $pdo): void
{
    ensureSystemErrorsTable($pdo);
    normalizeReminderSchedulerLockFile();
    require_once __DIR__ . '/sms.php';

    if (!isEmailConfigured()) {
        raiseSystemError(
            $pdo,
            'email-not-configured',
            'email',
            'Email not configured',
            'Gmail SMTP is not configured. Citizens will not receive confirmation or reminder emails until Gmail address and App Password are saved in Settings → Configuration.',
            'verify_email_config',
            buildAuthUrl('system_settings.php', ['tab' => 'system-configuration'])
        );
    } else {
        resolveSystemErrorIfActive($pdo, 'email-not-configured');
    }

    if (isSmsEnabled() && !isSmsConfigured()) {
        raiseSystemError(
            $pdo,
            'sms-not-configured',
            'sms',
            'SMS not configured',
            'SMS notifications are enabled but Semaphore API key is missing. Add your Semaphore API key in Settings → Configuration or turn SMS off.',
            'verify_sms_config',
            buildAuthUrl('system_settings.php', ['tab' => 'system-configuration'])
        );
    } else {
        resolveSystemErrorIfActive($pdo, 'sms-not-configured');
    }

    resolveSystemErrorIfActive($pdo, 'sms-sender-not-ready');

    if (clearStaleEmailConfigFailureAlert($pdo)) {
        // Stale pre-configuration failures cleared; skip re-opening the alert this run.
    }

    $emailFailures = recentDeliveryFailureSummary($pdo, 'email_logs', 24, getDeliveryFailureAckTime('email'));
    if ($emailFailures !== null && isEmailConfigured()) {
        if (allRecentEmailFailuresAreMissingSmtpConfig($pdo, 24, getDeliveryFailureAckTime('email'))) {
            acknowledgeEmailLogFailuresThrough($pdo, 24);
            resolveSystemErrorIfActive($pdo, 'email-delivery-failed');
        } else {
            $reason = $emailFailures['count'] . ' email(s) failed in the last 24 hours.';
            if ($emailFailures['reason'] !== '') {
                $reason .= ' Latest reason: ' . $emailFailures['reason'];
            }
            raiseSystemError(
                $pdo,
                'email-delivery-failed',
                'email',
                'Email delivery failing',
                $reason,
                'retry_email_delivery',
                buildAuthUrl('system_settings.php', ['tab' => 'system-configuration'])
            );
        }
    } else {
        resolveSystemErrorIfActive($pdo, 'email-delivery-failed');
    }

    if (function_exists('isSmsConfigured')) {
        $smsBlock = isSmsEnabled() && isSmsConfigured() ? smsSemaphoreSendBlockReason() : null;
        if ($smsBlock !== null) {
            if (getSmsSenderPendingAckTime() === null) {
                raiseSystemError(
                    $pdo,
                    'sms-sender-pending',
                    'sms',
                    'SMS waiting on Semaphore',
                    $smsBlock,
                    'acknowledge_sms_sender_pending',
                    buildAuthUrl('system_settings.php', ['tab' => 'system-configuration'])
                );
            } else {
                resolveSystemErrorIfActive($pdo, 'sms-sender-pending');
            }
            resolveSystemErrorIfActive($pdo, 'sms-delivery-failed');
        } else {
            clearSmsSenderPendingAck();
            resolveSystemErrorIfActive($pdo, 'sms-sender-pending');

            $smsFailures = recentDeliveryFailureSummary($pdo, 'sms_logs', 24, getDeliveryFailureAckTime('sms'));
            if ($smsFailures !== null && isSmsConfigured()) {
                $reason = $smsFailures['count'] . ' SMS message(s) failed in the last 24 hours.';
                if ($smsFailures['reason'] !== '') {
                    $reason .= ' Latest reason: ' . $smsFailures['reason'];
                }
                raiseSystemError(
                    $pdo,
                    'sms-delivery-failed',
                    'sms',
                    'SMS delivery failing',
                    $reason,
                    'retry_sms_delivery',
                    buildAuthUrl('system_settings.php', ['tab' => 'system-configuration'])
                );
            } else {
                resolveSystemErrorIfActive($pdo, 'sms-delivery-failed');
            }
        }
    }

    if (reminderSchedulerLockIsStale()) {
        raiseSystemError(
            $pdo,
            'reminder-lock-stale',
            'cron',
            'Reminder scheduler lock stuck',
            'The appointment reminder lock file has been held for over 10 minutes. Scheduled email/SMS reminders may be blocked until the lock is cleared.',
            'clear_reminder_lock',
            systemErrorsMaintenanceUrl()
        );
    } else {
        resolveSystemErrorIfActive($pdo, 'reminder-lock-stale');
    }

    if (reminderSchedulerIsStale()) {
        raiseSystemError(
            $pdo,
            'reminder-scheduler-stale',
            'cron',
            'Appointment reminders delayed',
            'The reminder scheduler has not completed a run in over 30 minutes. Due visit reminders may be late until the scheduler runs again.',
            'run_reminders',
            systemErrorsMaintenanceUrl()
        );
    } else {
        resolveSystemErrorIfActive($pdo, 'reminder-scheduler-stale');
    }
}

/** @return list<array<string, mixed>> */
function systemErrorsAsNotifications(PDO $pdo): array
{
    syncSystemErrors($pdo);
    $items = [];

    foreach (fetchActiveSystemErrors($pdo) as $row) {
        $items[] = [
            'id'         => 'sys-' . $row['error_key'],
            'type'       => 'system',
            'title'      => $row['title'],
            'message'    => $row['reason'],
            'detail'     => strtoupper((string) $row['category']),
            'created_at' => $row['updated_at'] ?: $row['created_at'],
            'href'       => $row['href'] ?: systemErrorsMaintenanceUrl(),
            'error_key'  => $row['error_key'],
            'fix_action' => $row['fix_action'],
        ];
    }

    return $items;
}

function runSystemErrorFix(PDO $pdo, string $errorKey, string $staffId): array
{
    syncSystemErrors($pdo);

    $active = null;
    foreach (fetchActiveSystemErrors($pdo) as $row) {
        if (($row['error_key'] ?? '') === $errorKey) {
            $active = $row;
            break;
        }
    }

    if (!$active) {
        return ['ok' => false, 'message' => 'This issue is already resolved.', 'already_resolved' => true];
    }

    $fixAction = (string) ($active['fix_action'] ?? 'acknowledge');

    try {
        switch ($fixAction) {
            case 'verify_email_config':
                if (!isEmailConfigured()) {
                    return [
                        'ok'      => false,
                        'message' => 'Email is still not configured. Open Configuration, save Gmail SMTP credentials, then try Fix again.',
                    ];
                }
                resolveSystemError($pdo, $errorKey, $staffId);
                break;

            case 'verify_sms_config':
                if (!function_exists('isSmsConfigured') || !isSmsConfigured()) {
                    if (isSmsEnabled()) {
                        return [
                            'ok'      => false,
                            'message' => 'SMS is still enabled without a Semaphore API key. Add the key in Configuration or disable SMS.',
                        ];
                    }
                }
                resolveSystemError($pdo, $errorKey, $staffId);
                break;

            case 'refresh_sms_sender':
                clearSemaphoreSenderNamesCache();
                fetchSemaphoreSenderNames(true);
                resolveSystemError($pdo, $errorKey, $staffId);
                break;

            case 'acknowledge_sms_sender_pending':
                clearSemaphoreSenderNamesCache();
                acknowledgeDeliveryFailures('sms');
                acknowledgeSmsSenderPending();
                resolveSystemError($pdo, 'sms-sender-pending', $staffId);
                resolveSystemError($pdo, 'sms-delivery-failed', $staffId);
                break;

            case 'clear_reminder_lock':
                clearReminderSchedulerLock();
                touchReminderSchedulerTick();
                sendDueAppointmentReminders($pdo);
                syncSystemErrors($pdo);
                if (fetchActiveSystemErrors($pdo) !== [] && reminderSchedulerLockIsStale()) {
                    return [
                        'ok'      => false,
                        'message' => 'Could not clear the reminder lock. Check storage folder permissions.',
                    ];
                }
                resolveSystemError($pdo, $errorKey, $staffId);
                break;

            case 'run_reminders':
                clearReminderSchedulerLock();
                sendDueAppointmentReminders($pdo);
                touchReminderSchedulerTick();
                syncSystemErrors($pdo);
                if (reminderSchedulerIsStale()) {
                    return [
                        'ok'      => false,
                        'message' => 'Reminders were run but the scheduler still looks stale. Confirm cron or site traffic can reach the reminder job.',
                    ];
                }
                resolveSystemError($pdo, $errorKey, $staffId);
                break;

            case 'retry_email_delivery':
                if (!isEmailConfigured()) {
                    return [
                        'ok'      => false,
                        'message' => 'Gmail is not fully configured. In Configuration → Operations, Queue & Email, save both Gmail address and App Password, then try Fix again.',
                    ];
                }
                if (clearStaleEmailConfigFailureAlert($pdo)) {
                    resolveSystemError($pdo, $errorKey, $staffId);
                    break;
                }
                clearReminderSchedulerLock();
                $fixStarted = date('Y-m-d H:i:s');
                sendDueAppointmentReminders($pdo);
                touchReminderSchedulerTick();
                $newFailures = recentDeliveryFailureSummary($pdo, 'email_logs', 1, $fixStarted, true);
                if ($newFailures !== null) {
                    $reason = $newFailures['reason'] !== ''
                        ? ' Reason: ' . $newFailures['reason']
                        : '';
                    return [
                        'ok'      => false,
                        'message' => 'Email is still failing after retry.' . $reason . ' Open Configuration and verify Gmail SMTP credentials.',
                    ];
                }
                acknowledgeEmailLogFailuresThrough($pdo, 24);
                resolveSystemError($pdo, $errorKey, $staffId);
                break;

            case 'retry_sms_delivery':
                if (!function_exists('isSmsConfigured') || !isSmsConfigured()) {
                    return [
                        'ok'      => false,
                        'message' => 'Configure Semaphore SMS first, then run Fix again.',
                    ];
                }
                require_once __DIR__ . '/sms.php';
                clearSemaphoreSenderNamesCache();
                clearReminderSchedulerLock();
                $fixStarted = date('Y-m-d H:i:s');
                sendDueAppointmentReminders($pdo);
                touchReminderSchedulerTick();
                $newFailures = recentDeliveryFailureSummary($pdo, 'sms_logs', 1, $fixStarted, true);
                if ($newFailures !== null) {
                    $reason = $newFailures['reason'] !== ''
                        ? ' Reason: ' . $newFailures['reason']
                        : '';
                    return [
                        'ok'      => false,
                        'message' => 'SMS is still failing after retry.' . $reason . ' Verify Semaphore API key and sender name.',
                    ];
                }
                acknowledgeDeliveryFailures('sms');
                resolveSystemError($pdo, $errorKey, $staffId);
                break;

            default:
                resolveSystemError($pdo, $errorKey, $staffId);
                break;
        }
    } catch (Throwable $e) {
        raiseSystemError(
            $pdo,
            'fix-action-failed-' . $errorKey,
            'system',
            'Fix attempt failed',
            'Could not apply fix for "' . ($active['title'] ?? $errorKey) . '": ' . $e->getMessage(),
            'acknowledge',
            systemErrorsMaintenanceUrl()
        );

        return [
            'ok'      => false,
            'message' => 'Fix attempt failed: ' . $e->getMessage(),
        ];
    }

    syncSystemErrors($pdo);

    logActivity($staffId, 'System Error Fixed', 'Resolved ' . ($active['title'] ?? $errorKey));

    return [
        'ok'      => true,
        'message' => ($active['title'] ?? 'System issue') . ' has been fixed.',
    ];
}

function recordReminderSchedulerFailure(PDO $pdo, string $message): void
{
    raiseSystemError(
        $pdo,
        'reminder-scheduler-failed',
        'cron',
        'Reminder scheduler error',
        $message,
        'run_reminders',
        systemErrorsMaintenanceUrl()
    );
}

function recordReminderSchedulerSuccess(PDO $pdo): void
{
    resolveSystemErrorIfActive($pdo, 'reminder-scheduler-failed');
    touchReminderSchedulerTick();
}
