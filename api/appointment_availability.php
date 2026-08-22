<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$date = trim($_GET['date'] ?? '');
$time = trim($_GET['time'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    apiError('A valid date is required (YYYY-MM-DD).');
}

try {
    $pdo = getDB();
    ensureCitizenNotifyColumns($pdo);

    $bookedTimes = getBookedAppointmentTimes($pdo, $date);
    $count = countActiveAppointmentsOnDate($pdo, $date);
    $maxDaily = maxDailyAppointmentsLimit();
    $dateFull = $count >= $maxDaily;

    $payload = [
        'date'           => $date,
        'booked_times'   => $bookedTimes,
        'count'          => $count,
        'max_daily'      => $maxDaily,
        'date_full'      => $dateFull,
        'office_weekday' => isOfficeAppointmentDate($date),
    ];

    if ($time !== '') {
        $payload['available'] = isOfficeAppointmentDate($date)
            && isWithinOfficeHours($time)
            && !$dateFull
            && !isAppointmentSlotTaken($pdo, $date, $time);
    }

    apiJsonResponse($payload);
} catch (Throwable $e) {
    apiError('Unable to check appointment availability.', 500);
}
