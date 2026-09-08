<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

try {
    requireStaffLogin();
    requirePageAccess('live-queue.php');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiError('Method not allowed.', 405);
    }

    requireStaffPostCsrf();

    $pdo = getDB();
    $action = (string) ($_POST['action'] ?? '');
    $purpose = (string) ($_POST['purpose'] ?? '');
    $tables = queuePurposeConfig();

    if (!isset($tables[$purpose])) {
        apiError('Invalid queue table.', 422);
    }

    $tableNum = queueTableForPurpose($purpose);
    $currentStaff = staffId();

    switch ($action) {
        case 'next':
            $result = queueAdvanceNext($pdo, $purpose, $tableNum, $currentStaff);
            if ($result['called_ticket']) {
                $message = 'Now calling ' . $result['called_ticket'] . ' at Table ' . $tableNum . '.';
            } elseif ($result['had_serving']) {
                $message = 'Current ticket finished. No one else is waiting in this line.';
            } else {
                $message = 'No one is waiting in this line.';
            }
            apiJsonResponse([
                'purpose'       => $purpose,
                'called_ticket' => $result['called_ticket'],
                'message'       => $message,
                'queue'         => fetchQueueSnapshot($pdo, 'full'),
            ]);
            break;

        case 'call_again':
            $ticket = queueCallAgain($pdo, $purpose, $tableNum, $currentStaff);
            if (!$ticket) {
                apiError('No ticket is currently being served at this table.', 409);
            }
            apiJsonResponse([
                'purpose' => $purpose,
                'ticket'  => $ticket,
                'message' => "Calling $ticket again at Table $tableNum.",
                'queue'   => fetchQueueSnapshot($pdo, 'full'),
            ]);
            break;

        case 'skip':
            $ticket = queueSkipServing($pdo, $purpose, $currentStaff);
            if (!$ticket) {
                apiError('No ticket is currently being served at this table.', 409);
            }
            apiJsonResponse([
                'purpose' => $purpose,
                'ticket'  => $ticket,
                'message' => "Skipped $ticket. Tap Call next for the next citizen.",
                'queue'   => fetchQueueSnapshot($pdo, 'full'),
            ]);
            break;

        default:
            apiError('Unknown action.', 404);
    }
} catch (InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
} catch (Throwable $e) {
    apiError('Could not update the queue. Please try again.', 500);
}
