<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/helpers.php';

requireStaffLogin();
requirePageAccess('appointment.php');

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

try {
    $pdo = getDB();
    $response = [
        'appointments' => fetchAppointments($pdo, $date),
        'date'         => $date,
    ];

    $focusId = (int) ($_GET['focus_id'] ?? 0);
    if ($focusId > 0) {
        $standaloneSql = appointmentStandaloneSql('a');
        $stmt = $pdo->prepare(
            "SELECT a.*, dr.date_of_birth, dr.date_of_marriage, dr.sex, dr.document_type AS request_document_type
             FROM appointments a
             LEFT JOIN document_requests dr ON dr.tracking_code = a.tracking_code AND dr.deleted_at IS NULL
             WHERE a.id = ? AND {$standaloneSql}
             LIMIT 1"
        );
        $stmt->execute([$focusId]);
        $focusRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($focusRow) {
            $response['focus'] = appointmentViewData($focusRow);
        }
    }

    apiJsonResponse($response);
} catch (Throwable $e) {
    apiError('Unable to load appointments.', 500);
}
