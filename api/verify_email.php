<?php
/**
 * Gmail verification API — checks if the typed address is an active Google account.
 */
session_start();
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('POST required.', 405);
}

$email = normalizeGmail($_POST['email'] ?? '');
apiJsonResponse(verifyActiveGmailAccount($email));
