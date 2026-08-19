<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireStaffLogin();
requirePageAccess('appointment.php');

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

try {
    $pdo = getDB();
    apiJsonResponse(['appointments' => fetchAppointments($pdo, $date), 'date' => $date]);
} catch (Throwable $e) {
    apiError('Unable to load appointments.', 500);
}
