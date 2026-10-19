<?php
/**
 * Sends Gmail reminders for visits and appointments at 5, 3, and 1 hour before start.
 * Requires cron secret — use storage/cron_secret.txt value.
 *
 * SmarterASP.NET Premium Schedule Task (every 15 min, HTTP GET):
 *   https://YOUR-DOMAIN.com/api/appointment_reminders.php?cron_secret=SECRET
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireCronSecret();

try {
    $pdo = getDB();
    $sent = sendDueAppointmentReminders($pdo);
    $payload = ['sent' => $sent];
    if (isset($_GET['diagnostics']) && (string) $_GET['diagnostics'] === '1') {
        $payload['diagnostics'] = alcrosAppointmentReminderDiagnostics($pdo);
    }
    apiJsonResponse($payload);
} catch (Throwable $e) {
    apiError('Unable to send appointment reminders.', 500);
}
