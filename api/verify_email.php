<?php
/**
 * Gmail verification API — checks if the typed address is an active Google account.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('POST required.', 405);
}

rateLimitOrAbort(rateLimitKey('verify_email'), 15, 900, 'Too many verification attempts. Please try again later.');

$email = normalizeGmail($_POST['email'] ?? '');
apiJsonResponse(verifyActiveGmailAccount($email));
