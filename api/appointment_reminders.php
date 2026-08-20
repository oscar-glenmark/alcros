<?php
/**
 * Sends Gmail reminders for appointments that start in the next 5 hours.
 * Safe to call often (from the website or Windows Task Scheduler).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

try {
    $pdo = getDB();
    $sent = sendDueAppointmentReminders($pdo);
    apiJsonResponse(['sent' => $sent]);
} catch (Throwable $e) {
    apiError('Unable to send appointment reminders.', 500);
}
