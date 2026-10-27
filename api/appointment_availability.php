<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$date = trim($_GET['date'] ?? '');
$time = trim($_GET['time'] ?? '');
$bookingType = normalizeAppointmentBookingType(trim($_GET['type'] ?? 'standalone'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    apiError('A valid date is required (YYYY-MM-DD).');
}

try {
    $pdo = getDB();
    $payload = buildAppointmentAvailability($pdo, $date, $bookingType);

    if ($time !== '') {
        $normalized = normalizeAppointmentTime($time);
        $pastToday = $date === date('Y-m-d')
            && $normalized !== ''
            && substr($normalized, 0, 5) <= date('H:i');
        $payload['available'] = (bool) ($payload['bookable'] ?? false)
            && isValidAppointmentSlot($time, $bookingType)
            && !$pastToday
            && !isAppointmentSlotTaken($pdo, $date, $time);
    }

    apiJsonResponse($payload);
} catch (Throwable $e) {
    apiError('Unable to check appointment availability.', 500);
}
