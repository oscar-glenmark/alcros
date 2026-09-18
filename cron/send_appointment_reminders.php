<?php
/**
 * CLI / Task Scheduler entry point (recommended every 5 minutes):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\alcros\cron\send_appointment_reminders.php
 * Or double-click / schedule: cron\run-reminders.bat
 *
 * Sends Gmail visit/appointment reminders at 5h, 3h, and 1h before schedule.
 * ALCROS also runs a throttled background check during site traffic, but Task Scheduler
 * is required for overnight/off-hours delivery.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script is for scheduled tasks only.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$sent = sendDueAppointmentReminders($pdo);
echo 'Appointment reminders sent: ' . $sent . PHP_EOL;
