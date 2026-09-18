<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/helpers.php';

requireStaffLogin();
requirePageAccess('appointment.php');

try {
    $pdo = getDB();
    ensureCitizenNotifyColumns($pdo);
    ensureSoftDeleteColumns($pdo);
    ensureAppointmentUpdatedColumn($pdo);

    $filters = appointmentsListFilters($_GET);
    $focusId = (int) ($_GET['focus_id'] ?? 0);
    $rows = fetchAppointmentsManageList($pdo, $filters);
    $appointments = [];

    foreach ($rows as $row) {
        $view = appointmentViewData($row);
        $appointments[] = [
            'id'                => (int) $row['id'],
            'revision'          => $view['revision'],
            'appointment_code'  => $view['appointment_code'],
            'citizen_name'      => $view['citizen_name'],
            'service_type'      => $view['service_type'],
            'status_key'        => $view['status_key'],
            'status_badge_html' => $view['status_badge_html'],
            'appointment_time'  => (string) ($row['appointment_time'] ?? ''),
            'appointment_date'  => (string) ($row['appointment_date'] ?? ''),
        ];
    }

    $response = [
        'appointments' => $appointments,
        'stats'        => fetchAppointmentDayStats($pdo, $filters['date']),
        'filters'      => $filters,
        'count'        => count($appointments),
        'date'         => $filters['date'],
    ];

    if ($focusId > 0) {
        $includeDeleted = $filters['status'] === 'recently_deleted';
        $standaloneSql = appointmentStandaloneSql('a');
        $sql = "SELECT a.*, dr.date_of_birth, dr.date_of_marriage, dr.sex, dr.document_type AS request_document_type
                FROM appointments a
                LEFT JOIN document_requests dr ON dr.tracking_code = a.tracking_code AND dr.deleted_at IS NULL
                WHERE a.id = ? AND {$standaloneSql}";
        if (!$includeDeleted) {
            $sql .= ' AND a.deleted_at IS NULL';
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
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
