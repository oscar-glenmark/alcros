<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/staff_resource_locks.php';

requireStaffLogin();
requirePageAccess('appointment.php');

try {
    $pdo = getDB();
    ensureCitizenNotifyColumns($pdo);
    ensureSoftDeleteColumns($pdo);
    ensureAppointmentUpdatedColumn($pdo);

    $filters = appointmentsListFilters($_GET, $pdo);
    $focusId = (int) ($_GET['focus_id'] ?? 0);
    $rows = fetchAppointmentsManageList($pdo, $filters);
    $lockMap = fetchStaffResourceLocksMap(
        $pdo,
        STAFF_LOCK_TYPE_APPOINTMENT,
        array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)
    );
    $staffId = staffId();
    $appointments = [];

    foreach ($rows as $row) {
        $view = appointmentViewData($row, $pdo);
        $aid = (int) $row['id'];
        $lockRow = $lockMap[$aid] ?? null;
        $appointments[] = [
            'id'                => $aid,
            'revision'          => $view['revision'],
            'appointment_code'  => $view['appointment_code'],
            'citizen_name'      => $view['citizen_name'],
            'service_type'      => $view['service_type'],
            'status_key'        => $view['status_key'],
            'status_badge_html' => $view['status_badge_html'],
            'appointment_time'  => (string) ($row['appointment_time'] ?? ''),
            'appointment_date'  => (string) ($row['appointment_date'] ?? ''),
            'lock'              => $lockRow ? formatStaffResourceLock($lockRow, $staffId) : null,
        ];
    }

    $stats = fetchAppointmentDayStats($pdo, $filters['date']);
    if (appointmentManageAwaitingAllDates($filters)) {
        $stats['scheduled'] = countPendingAppointments($pdo);
    }

    $response = [
        'appointments'            => $appointments,
        'stats'                   => $stats,
        'filters'                 => $filters,
        'count'                   => count($appointments),
        'date'                    => $filters['date'],
        'overview_card_highlight' => resolveAppointmentOverviewCardHighlight(
            $filters['status'],
            $filters['q'],
            $rows,
            $filters['status'] === 'follow_ups'
        ),
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
            $focusId = (int) $focusRow['id'];
            $focusLock = touchStaffResourceLockForFocus(
                $pdo,
                STAFF_LOCK_TYPE_APPOINTMENT,
                $focusId,
                staffId(),
                staffName()
            );
            if ($focusLock === null) {
                $lockRow = fetchStaffResourceLock($pdo, STAFF_LOCK_TYPE_APPOINTMENT, $focusId);
                $focusLock = $lockRow ? formatStaffResourceLock($lockRow, staffId()) : null;
            }
            $focus = appointmentViewData($focusRow, $pdo);
            $focus['lock'] = $focusLock;
            if ($focusLock && !($focusLock['held_by_you'] ?? false)) {
                $focus['_lockReadOnly'] = true;
            }
            $response['focus'] = $focus;
        }
    }

    apiJsonResponse($response);
} catch (Throwable $e) {
    apiError('Unable to load appointments.', 500);
}
