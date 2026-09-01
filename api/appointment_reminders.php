<?php
/**
 * Sends Gmail reminders for visits and appointments at 5, 3, and 1 hour before start.
 * Requires cron secret — use storage/cron_secret.txt value.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireCronSecret();

try {
    $pdo = getDB();
    $sent = sendDueAppointmentReminders($pdo);
    apiJsonResponse(['sent' => $sent]);
} catch (Throwable $e) {
    apiError('Unable to send appointment reminders.', 500);
}
