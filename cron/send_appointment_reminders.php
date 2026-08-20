<?php
/**
 * CLI / Task Scheduler entry point:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\alcros\cron\send_appointment_reminders.php
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
