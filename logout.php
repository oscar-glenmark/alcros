<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

$staff = getAuthenticatedStaff();
if ($staff) {
    try {
        logActivity($staff['staff_id'], 'Staff Logout', 'Logged out of staff portal');
    } catch (Throwable $e) {
        // ignore
    }
}
staffSessionLogout();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <title>Logging out...</title>
</head>
<body>
<?= scriptTag('core/logout.js') ?>
</body>
</html>
