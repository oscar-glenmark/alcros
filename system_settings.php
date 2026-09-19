<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/system_errors.php';
require_once __DIR__ . '/includes/duplicati_backup.php';
require_once __DIR__ . '/includes/cloud_backup.php';
require_once __DIR__ . '/includes/scripts.php';
requireStaffLogin();
requirePageAccess('system_settings.php');

$activePage = 'system_settings.php';
$pdo = getDB();
ensureStaffProfileColumns($pdo);
$isAdmin = isAdmin();
$currentStaffId = staffId();

$adminSettingKeys = [
    'site_name', 'office_name', 'office_address', 'office_phone', 'office_email',
    'office_hours', 'office_head', 'overview_text', 'portal_title', 'portal_description',
    'queue_window', 'maintenance_mode', 'allow_public_requests', 'notification_email',
    'max_daily_appointments', 'privacy_policy_url', 'kiosk_welcome_message',
    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
    'sms_enabled', 'semaphore_api_key', 'semaphore_sender_name',
];

$defaults = [
    'site_name'               => 'ALCROS',
    'office_name'             => 'Local Civil Registrar Office (LCRO) of Aloran',
    'office_address'          => 'Municipal Hall, Aloran, Misamis Occidental, Philippines',
    'office_phone'            => '+639473212350',
    'office_email'            => 'aloran@gov.ph',
    'office_hours'            => '8:00 AM - 5:00 PM (Monday to Friday)',
    'office_head'             => 'ATTY. LOCAL CIVIL REGISTRAR',
    'overview_text'           => 'This guide covers the requirements, steps, and fees for all core civil registration services handled by the <strong>{office}</strong>.',
    'portal_title'            => 'ALCROS Online Request Portal',
    'portal_description'      => 'Request document submissions or track application statuses online.',
    'queue_window'            => '1',
    'maintenance_mode'        => '0',
    'allow_public_requests'   => '1',
    'notification_email'      => 'aloran@gov.ph',
    'max_daily_appointments'  => '20',
    'privacy_policy_url'      => 'privacy.php',
    'kiosk_welcome_message'   => 'Welcome to ALCROS. Please get your queue number and wait to be served.',
    'smtp_host'               => 'smtp.gmail.com',
    'smtp_port'               => '587',
    'smtp_user'               => '',
    'smtp_pass'               => '',
    'sms_enabled'             => '0',
    'semaphore_api_key'       => '',
    'semaphore_sender_name'   => '',
];

function currentStaffRow(PDO $pdo, string $staffId): ?array
{
    $stmt = $pdo->prepare('SELECT staff_id, first_name, middle_name, last_name, email, recovery_gmail_2sv_confirmed, role, created_at, profile_photo_path FROM staff WHERE staff_id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function adminCount(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM staff WHERE role = 'Administrator'")->fetchColumn();
}

// Export activity logs (admin)
if ($isAdmin && isset($_GET['action']) && $_GET['action'] === 'export_logs') {
    $logs = $pdo->query('SELECT staff_id, action, details, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 500')->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="alcros_activity_logs_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['staff_id', 'action', 'details', 'created_at']);
    foreach ($logs as $log) {
        fputcsv($out, [$log['staff_id'], $log['action'], $log['details'], $log['created_at']]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['settings_action'] ?? '';
    $handled = false;

    try {
        if ($action === 'update_profile') {
            $nameParts = personNamePartsFromInput($_POST, 'profile_');
            $email = normalizeStaffEmail(trim($_POST['profile_email'] ?? ''));
            if (($nameError = validatePersonNameParts($nameParts)) !== null) {
                throw new InvalidArgumentException($nameError);
            }
            if ($emailError = validateStaffEmail($email)) {
                throw new InvalidArgumentException($emailError);
            }
            if (staffEmailInUse($pdo, $email, $currentStaffId)) {
                throw new InvalidArgumentException('That Gmail address is already used by another staff account.');
            }
            $existing = currentStaffRow($pdo, $currentStaffId);
            $needs2sv = staffRecoveryGmailNeeds2svConfirmation($existing, $email);
            $confirmedCheckbox = !empty($_POST['recovery_gmail_2sv_confirmed']);
            if ($needs2sv && ($error2sv = validateStaffRecoveryGmail2sv($confirmedCheckbox))) {
                throw new InvalidArgumentException($error2sv);
            }
            $confirmedValue = staffRecoveryGmail2svConfirmedValue($existing, $email, $confirmedCheckbox);
            $pdo->prepare('UPDATE staff SET first_name = ?, middle_name = ?, last_name = ?, email = ?, recovery_gmail_2sv_confirmed = ? WHERE staff_id = ?')
                ->execute([$nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name'], $email, $confirmedValue, $currentStaffId]);
            $_SESSION['staff_name'] = formatPersonName($nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name']);
            logActivity($currentStaffId, 'Profile Updated', 'Updated display name and recovery Gmail');
            settingsFlashSet('success', 'Your profile has been updated.');
            $handled = true;
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $stmt = $pdo->prepare('SELECT password_hash FROM staff WHERE staff_id = ?');
            $stmt->execute([$currentStaffId]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) {
                throw new InvalidArgumentException('Current password is incorrect.');
            }
            if ($passwordError = validatePasswordStrength($newPass)) {
                throw new InvalidArgumentException($passwordError);
            }
            if ($newPass !== $confirm) {
                throw new InvalidArgumentException('New passwords do not match.');
            }
            $pdo->prepare('UPDATE staff SET password_hash = ? WHERE staff_id = ?')->execute([password_hash($newPass, PASSWORD_DEFAULT), $currentStaffId]);
            logActivity($currentStaffId, 'Password Changed', 'Updated account password');
            settingsFlashSet('success', 'Password changed successfully.');
            $handled = true;
        } elseif ($action === 'save_settings' && $isAdmin) {
            foreach ($adminSettingKeys as $key) {
                if (in_array($key, ['maintenance_mode', 'allow_public_requests', 'sms_enabled'], true)) {
                    setSetting($key, isset($_POST[$key]) ? '1' : '0');
                } elseif ($key === 'smtp_pass') {
                    $smtpPass = (string) ($_POST['smtp_pass'] ?? '');
                    $smtpPassMask = '••••••••••••••••';
                    if ($smtpPass !== '' && $smtpPass !== $smtpPassMask) {
                        setSetting($key, $smtpPass);
                    }
                } elseif ($key === 'semaphore_api_key') {
                    $apiKey = (string) ($_POST['semaphore_api_key'] ?? '');
                    if ($apiKey !== '') {
                        setSetting($key, $apiKey);
                    }
                } elseif ($key === 'privacy_policy_url') {
                    setSetting($key, sanitizeExternalUrl(trim((string) ($_POST[$key] ?? '')), 'privacy.php'));
                } elseif (isset($_POST[$key])) {
                    setSetting($key, trim($_POST[$key]));
                }
            }
            if (getSetting('maintenance_mode', '0') === '1') {
                setSetting('allow_public_requests', '0');
            } elseif (getSetting('allow_public_requests', '0') === '1') {
                setSetting('maintenance_mode', '0');
            }
            logActivity($currentStaffId, 'Settings Updated', 'System configuration saved');
            settingsFlashSet('success', 'System settings saved successfully.');
            $handled = true;
        } elseif ($action === 'add_staff' && $isAdmin) {
            $nameParts = personNamePartsFromInput($_POST, 'staff_');
            $newStaffId = strtoupper(trim($_POST['staff_id_new'] ?? ''));
            $email = normalizeStaffEmail(trim($_POST['staff_email'] ?? ''));
            $password = $_POST['staff_password'] ?? '';
            $role = $_POST['staff_role'] ?? 'Staff';
            $validRoles = ['Staff', 'Administrator'];
            if (!in_array($role, $validRoles, true)) {
                $role = 'Staff';
            }
            if (($nameError = validatePersonNameParts($nameParts)) !== null || $newStaffId === '' || $password === '') {
                throw new InvalidArgumentException($nameError ?? 'Please fill in name, staff ID, and password.');
            }
            if ($emailError = validateStaffEmail($email)) {
                throw new InvalidArgumentException($emailError);
            }
            if (staffEmailInUse($pdo, $email)) {
                throw new InvalidArgumentException('That Gmail address is already used by another staff account.');
            }
            if ($error2sv = validateStaffRecoveryGmail2sv(!empty($_POST['recovery_gmail_2sv_confirmed']))) {
                throw new InvalidArgumentException($error2sv);
            }
            if ($passwordError = validatePasswordStrength($password)) {
                throw new InvalidArgumentException($passwordError);
            }
            if (!preg_match('/^[A-Z0-9\-]+$/', $newStaffId)) {
                throw new InvalidArgumentException('Staff ID may only contain letters, numbers, and hyphens.');
            }
            $exists = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE staff_id = ?');
            $exists->execute([$newStaffId]);
            if ((int) $exists->fetchColumn() > 0) {
                throw new InvalidArgumentException('That Staff ID is already in use.');
            }
            $pdo->prepare('INSERT INTO staff (staff_id, first_name, middle_name, last_name, email, recovery_gmail_2sv_confirmed, password_hash, role) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
                ->execute([$newStaffId, $nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name'], $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            $displayName = formatPersonName($nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name']);
            logActivity($currentStaffId, 'Staff Added', "Created staff account $newStaffId ($displayName)");
            settingsFlashSet('success', "Staff member $displayName ($newStaffId) added successfully.");
            $handled = true;
        } elseif ($action === 'update_staff' && $isAdmin) {
            $targetId = strtoupper(trim($_POST['target_staff_id'] ?? ''));
            $nameParts = personNamePartsFromInput($_POST, 'edit_staff_');
            $email = normalizeStaffEmail(trim($_POST['edit_staff_email'] ?? ''));
            $role = $_POST['edit_staff_role'] ?? 'Staff';
            $validRoles = ['Staff', 'Administrator'];
            if (!in_array($role, $validRoles, true)) {
                $role = 'Staff';
            }
            if ($targetId === '' || ($nameError = validatePersonNameParts($nameParts)) !== null) {
                throw new InvalidArgumentException($nameError ?? 'Staff ID and name are required.');
            }
            if ($emailError = validateStaffEmail($email)) {
                throw new InvalidArgumentException($emailError);
            }
            if (staffEmailInUse($pdo, $email, $targetId)) {
                throw new InvalidArgumentException('That Gmail address is already used by another staff account.');
            }
            $existing = currentStaffRow($pdo, $targetId);
            $needs2sv = staffRecoveryGmailNeeds2svConfirmation($existing, $email);
            $confirmedCheckbox = !empty($_POST['recovery_gmail_2sv_confirmed']);
            if ($needs2sv && ($error2sv = validateStaffRecoveryGmail2sv($confirmedCheckbox))) {
                throw new InvalidArgumentException($error2sv);
            }
            $confirmedValue = staffRecoveryGmail2svConfirmedValue($existing, $email, $confirmedCheckbox);
            if ($targetId === $currentStaffId && $role !== 'Administrator' && adminCount($pdo) <= 1) {
                throw new InvalidArgumentException('Cannot demote the last administrator account.');
            }
            $pdo->prepare('UPDATE staff SET first_name = ?, middle_name = ?, last_name = ?, email = ?, recovery_gmail_2sv_confirmed = ?, role = ? WHERE staff_id = ?')
                ->execute([$nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name'], $email, $confirmedValue, $role, $targetId]);
            if ($targetId === $currentStaffId) {
                $_SESSION['staff_name'] = formatPersonName($nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name']);
            }
            logActivity($currentStaffId, 'Staff Updated', "Updated account $targetId");
            settingsFlashSet('success', "Staff account $targetId updated.");
            $handled = true;
        } elseif ($action === 'reset_staff_password' && $isAdmin) {
            $targetId = strtoupper(trim($_POST['target_staff_id'] ?? ''));
            $newPass = $_POST['reset_password'] ?? '';
            if ($targetId === '' || strlen($newPass) < passwordMinLength()) {
                throw new InvalidArgumentException('Staff ID and a password of at least ' . passwordMinLength() . ' characters are required.');
            }
            if ($passwordError = validatePasswordStrength($newPass)) {
                throw new InvalidArgumentException($passwordError);
            }
            $pdo->prepare('UPDATE staff SET password_hash = ? WHERE staff_id = ?')->execute([password_hash($newPass, PASSWORD_DEFAULT), $targetId]);
            logActivity($currentStaffId, 'Password Reset', "Reset password for $targetId");
            settingsFlashSet('success', "Password reset for $targetId.");
            $handled = true;
        } elseif ($action === 'upload_staff_photo' && $isAdmin) {
            $targetId = strtoupper(trim($_POST['target_staff_id'] ?? ''));
            if ($targetId === '') {
                throw new InvalidArgumentException('Staff ID is required.');
            }
            $exists = $pdo->prepare('SELECT profile_photo_path FROM staff WHERE staff_id = ?');
            $exists->execute([$targetId]);
            $existing = $exists->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Staff account not found.');
            }
            $newPath = saveStaffPhotoUpload($_FILES['staff_photo'] ?? [], $targetId);
            if (!$newPath) {
                throw new InvalidArgumentException('Invalid photo. Use JPG, PNG, or WEBP (max upload size allowed by server).');
            }
            deleteStaffPhotoFile($existing['profile_photo_path'] ?? null);
            $pdo->prepare('UPDATE staff SET profile_photo_path = ? WHERE staff_id = ?')->execute([$newPath, $targetId]);
            logActivity($currentStaffId, 'Staff Photo Updated', "Updated profile photo for $targetId");
            settingsFlashSet('success', "Profile photo updated for $targetId.");
            $handled = true;
        } elseif ($action === 'remove_staff_photo' && $isAdmin) {
            $targetId = strtoupper(trim($_POST['target_staff_id'] ?? ''));
            if ($targetId === '') {
                throw new InvalidArgumentException('Staff ID is required.');
            }
            $photoStmt = $pdo->prepare('SELECT profile_photo_path FROM staff WHERE staff_id = ?');
            $photoStmt->execute([$targetId]);
            $photo = $photoStmt->fetchColumn();
            if (!$photo) {
                throw new InvalidArgumentException('Staff account not found.');
            }
            deleteStaffPhotoFile($photo ?: null);
            $pdo->prepare('UPDATE staff SET profile_photo_path = NULL WHERE staff_id = ?')->execute([$targetId]);
            logActivity($currentStaffId, 'Staff Photo Removed', "Removed profile photo for $targetId");
            settingsFlashSet('success', "Profile photo removed for $targetId.");
            $handled = true;
        } elseif ($action === 'remove_staff' && $isAdmin) {
            $targetId = strtoupper(trim($_POST['target_staff_id'] ?? ''));
            if ($targetId === '') {
                throw new InvalidArgumentException('Staff ID is required.');
            }
            if ($targetId === $currentStaffId) {
                throw new InvalidArgumentException('You cannot remove your own account.');
            }
            $targetStmt = $pdo->prepare('SELECT role FROM staff WHERE staff_id = ?');
            $targetStmt->execute([$targetId]);
            $targetRole = $targetStmt->fetchColumn();
            if (!$targetRole) {
                throw new InvalidArgumentException('Staff account not found.');
            }
            if ($targetRole === 'Administrator' && adminCount($pdo) <= 1) {
                throw new InvalidArgumentException('Cannot remove the last administrator account.');
            }
            $photoStmt = $pdo->prepare('SELECT profile_photo_path FROM staff WHERE staff_id = ?');
            $photoStmt->execute([$targetId]);
            deleteStaffPhotoFile($photoStmt->fetchColumn() ?: null);
            $pdo->prepare('DELETE FROM staff WHERE staff_id = ?')->execute([$targetId]);
            logActivity($currentStaffId, 'Staff Removed', "Removed staff account $targetId");
            settingsFlashSet('success', "Staff account $targetId removed.");
            $handled = true;
        } elseif ($action === 'clear_old_logs' && $isAdmin) {
            $days = max(7, (int) ($_POST['log_retention_days'] ?? 30));
            $stmt = $pdo->prepare('DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
            $stmt->execute([$days]);
            $deleted = $stmt->rowCount();
            logActivity($currentStaffId, 'Logs Cleared', "Removed $deleted activity log entries older than $days days");
            settingsFlashSet('success', "Cleared $deleted old activity log entries.");
            $handled = true;
        } elseif ($action === 'fix_system_error' && $isAdmin) {
            $errorKey = trim((string) ($_POST['error_key'] ?? ''));
            if ($errorKey === '' || !preg_match('/^[a-z0-9\-]+$/', $errorKey)) {
                throw new InvalidArgumentException('Invalid system error.');
            }
            $result = runSystemErrorFix($pdo, $errorKey, $currentStaffId);
            if (empty($result['already_resolved'])) {
                settingsFlashSet($result['ok'] ? 'success' : 'error', $result['message']);
            }
            $handled = true;
        } elseif ($action === 'clear_data' && $isAdmin) {
            $types = is_array($_POST['clear_data_types'] ?? null) ? $_POST['clear_data_types'] : [];
            $clearAllQueue = in_array('queue_tickets', $types, true);
            $results = clearOperationalData($pdo, $types, $currentStaffId);

            $parts = [];
            if ($results['document_requests'] > 0) {
                $parts[] = $results['document_requests'] . ' request(s)';
            }
            if ($results['appointments'] > 0) {
                $parts[] = $results['appointments'] . ' appointment(s)';
            }
            if ($results['civil_records'] > 0) {
                $parts[] = $results['civil_records'] . ' civil record(s)';
            }
            if ($results['queue_tickets'] > 0) {
                $parts[] = $results['queue_tickets'] . ($clearAllQueue ? ' queue ticket(s)' : ' related queue ticket(s)');
            }
            if ($results['queue_announcements'] > 0) {
                $parts[] = $results['queue_announcements'] . ' queue announcement(s)';
            }

            settingsFlashSet(
                'success',
                $parts !== []
                    ? 'Cleared ' . implode(', ', $parts) . '.'
                    : 'No records found for the selected data types.'
            );
            $handled = true;
        } elseif ($action === 'run_duplicati_backup' && $isAdmin) {
            $result = runAlcrosDuplicatiBackup($pdo);
            if (!$result['ok']) {
                throw new InvalidArgumentException($result['message']);
            }
            $manifest = $result['manifest'] ?? [];
            $sqlSize = alcrosFormatBytes((int) ($manifest['sql_bytes'] ?? 0));
            logActivity(
                $currentStaffId,
                'Backup Created',
                'Duplicati backup bundle updated (' . $sqlSize . ', ' . (int) ($manifest['files_copied'] ?? 0) . ' files)'
            );
            settingsFlashSet('success', 'Local backup bundle created. Duplicati can now upload the folder to the cloud.');
            $handled = true;
        } elseif ($action === 'save_cloud_backup_settings' && $isAdmin) {
            setSetting('cloud_backup_enabled', !empty($_POST['cloud_backup_enabled']) ? '1' : '0');
            setSetting('cloud_backup_gcs_bucket', trim((string) ($_POST['cloud_backup_gcs_bucket'] ?? '')));
            setSetting('cloud_backup_gcs_prefix', trim((string) ($_POST['cloud_backup_gcs_prefix'] ?? 'alcros-backups')) ?: 'alcros-backups');
            setSetting('cloud_backup_retention_count', (string) max(5, (int) ($_POST['cloud_backup_retention_count'] ?? 30)));
            setSetting('cloud_backup_public_url', rtrim(trim((string) ($_POST['cloud_backup_public_url'] ?? '')), '/'));
            if (trim(getSetting('cloud_backup_cron_token', '')) === '') {
                alcrosCloudBackupCronToken();
            }
            logActivity($currentStaffId, 'Backup Settings Updated', 'Cloud backup configuration saved');
            settingsFlashSet('success', 'Cloud backup settings saved.');
            $handled = true;
        } elseif ($action === 'upload_gcs_service_account' && $isAdmin) {
            alcrosSaveGcsServiceAccountUpload($_FILES['gcs_service_account_json'] ?? []);
            logActivity($currentStaffId, 'Backup Credentials Updated', 'Google Cloud service account JSON uploaded');
            settingsFlashSet('success', 'Google service account file saved securely on the server.');
            $handled = true;
        } elseif ($action === 'run_live_cloud_backup' && $isAdmin) {
            $result = runAlcrosLiveCloudBackup($pdo);
            if (!$result['ok']) {
                throw new InvalidArgumentException($result['message']);
            }
            logActivity($currentStaffId, 'Cloud Backup Uploaded', $result['message']);
            settingsFlashSet('success', $result['message']);
            $handled = true;
        } elseif ($action === 'test_cloud_backup' && $isAdmin) {
            $result = alcrosTestCloudBackupConnection($pdo);
            settingsFlashSet($result['ok'] ? 'success' : 'error', $result['message']);
            $handled = true;
        } elseif ($action === 'regenerate_backup_cron_token' && $isAdmin) {
            alcrosRegenerateCloudBackupCronToken();
            logActivity($currentStaffId, 'Backup Token Regenerated', 'Cloud backup cron URL token was regenerated');
            settingsFlashSet('success', 'Cron URL token regenerated. Update your Hostinger cron job with the new URL.');
            $handled = true;
        }
    } catch (InvalidArgumentException $e) {
        settingsFlashSet('error', $e->getMessage());
        $handled = true;
    } catch (PDOException $e) {
        settingsFlashSet('error', 'Could not complete the action. Please try again.');
        $handled = true;
    }

    if ($handled) {
        $tab = $_POST['active_tab'] ?? 'my-account';
        $redirectParams = array_filter(['tab' => $tab !== 'my-account' ? $tab : null]);
        if ($tab === 'admin-tools' && !empty($_POST['admin_sub'])) {
            $redirectParams['admin_sub'] = $_POST['admin_sub'];
        }
        redirectWithAuth('system_settings.php', $redirectParams);
    }
}

$settings = [];
foreach ($adminSettingKeys as $key) {
    $settings[$key] = getSetting($key, $defaults[$key] ?? '');
}
$smtpPassMask = '••••••••••••••••';
$smtpPassSaved = trim($settings['smtp_pass']) !== '';

$currentStaff = currentStaffRow($pdo, $currentStaffId);
$profileNeeds2svConfirmation = staffRecoveryGmailNeeds2svConfirmation($currentStaff, (string) ($currentStaff['email'] ?? ''));
$staffMembers = $pdo->query('SELECT staff_id, first_name, middle_name, last_name, email, recovery_gmail_2sv_confirmed, role, created_at, profile_photo_path FROM staff ORDER BY created_at ASC')->fetchAll();
$systemStats = $isAdmin ? getSystemStats($pdo) : [];
$recentLogs = $isAdmin
    ? $pdo->query('SELECT staff_id, action, details, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 8')->fetchAll()
    : [];

$lastLoginStmt = $pdo->prepare(
    "SELECT created_at FROM activity_logs WHERE staff_id = ? AND action = 'Staff Login' ORDER BY created_at DESC LIMIT 1 OFFSET 1"
);
$lastLoginStmt->execute([$currentStaffId]);
$lastLogin = $lastLoginStmt->fetchColumn();

$activeTab = $_GET['tab'] ?? 'my-account';
$validTabs = $isAdmin
    ? ['my-account', 'security', 'account-management', 'system-configuration', 'admin-tools']
    : ['my-account', 'security'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'my-account';
}

$adminToolsSection = 'overview';
if ($isAdmin && $activeTab === 'admin-tools') {
    $adminToolsSection = $_GET['admin_sub'] ?? 'overview';
    if (!in_array($adminToolsSection, ['overview', 'activity', 'backup', 'maintenance'], true)) {
        $adminToolsSection = 'overview';
    }
}

$backupManifest = $isAdmin ? alcrosDuplicatiReadManifest() : null;
$backupDir = $isAdmin ? alcrosDuplicatiBackupDir() : '';
$backupDirSize = ($isAdmin && is_dir($backupDir)) ? alcrosDuplicatiBackupDirSize($backupDir) : 0;
$backupLastRun = $isAdmin ? getSetting('backup_last_run_at', '') : '';
$backupLastStatus = $isAdmin ? getSetting('backup_last_status', '') : '';
$backupLastMessage = $isAdmin ? getSetting('backup_last_message', '') : '';
$backupScriptBat = $isAdmin ? realpath(__DIR__ . '/scripts/duplicati-pre-backup.bat') : false;
$backupRunBat = $isAdmin ? realpath(__DIR__ . '/scripts/backup-run.bat') : false;
$backupRecordCount = $isAdmin
    ? (int) ($backupManifest['counts']['civil_records'] ?? ($systemStats['civil_records']['count'] ?? 0))
    : 0;
$backupStaffCount = $isAdmin
    ? (int) ($backupManifest['counts']['staff'] ?? count($staffMembers))
    : 0;
$backupIsLocalOffice = $isAdmin && alcrosIsLocalOfficeInstall();
$cloudBackupEnabled = $isAdmin && alcrosCloudBackupEnabled();
$cloudBackupBucket = $isAdmin ? getSetting('cloud_backup_gcs_bucket', '') : '';
$cloudBackupPrefix = $isAdmin ? getSetting('cloud_backup_gcs_prefix', 'alcros-backups') : '';
$cloudBackupRetention = $isAdmin ? (int) getSetting('cloud_backup_retention_count', '30') : 30;
$cloudBackupPublicUrl = $isAdmin ? getSetting('cloud_backup_public_url', '') : '';
$cloudBackupGcsConfigured = $isAdmin && alcrosGcsServiceAccountConfigured();
$cloudBackupLastUpload = $isAdmin ? getSetting('cloud_backup_last_upload_at', '') : '';
$cloudBackupLastUploadStatus = $isAdmin ? getSetting('cloud_backup_last_upload_status', '') : '';
$cloudBackupLastUploadMessage = $isAdmin ? getSetting('cloud_backup_last_upload_message', '') : '';
$cloudBackupCronUrl = $isAdmin ? alcrosCloudBackupCronUrl() : '';
$cloudBackupCronToken = $isAdmin ? alcrosCloudBackupCronToken() : '';
$cloudBackupFileList = ['ok' => true, 'items' => [], 'message' => ''];
if ($isAdmin) {
    $cloudBackupFileList = alcrosCloudFetchBackupFileList();
}
$cloudBackupFiles = $cloudBackupFileList['items'];
$cloudBackupFilesError = $cloudBackupFileList['ok'] ? '' : $cloudBackupFileList['message'];
$cloudBackupGcsBrowserUrl = $isAdmin ? alcrosGcsBucketBrowserUrl() : '';

function settingsPageUrl(string $tab, ?string $adminSub = null): string
{
    return buildAuthUrl('system_settings.php', array_filter([
        'tab' => $tab !== 'my-account' ? $tab : null,
        'admin_sub' => ($tab === 'admin-tools' && $adminSub && $adminSub !== 'overview') ? $adminSub : null,
    ]));
}

$flash = settingsFlashGet();
$activeSystemErrors = [];
if ($isAdmin) {
    syncSystemErrors($pdo);
    $activeSystemErrors = fetchActiveSystemErrors($pdo);
}
$currentStaffPhoto = $currentStaff['profile_photo_path'] ?? null;

$pageTitle = $isAdmin ? 'System Settings' : 'My Settings';
$pageSubtitle = 'Manage your account, security' . ($isAdmin ? ', staff accounts, and system-wide configurations' : ', and credentials') . '.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isAdmin ? 'System Settings' : 'My Settings' ?> - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= interFontTags() ?>
    <?= publicStylesheet('password-toggle') ?>
    <?= adminLayoutHeadStyles('settings') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="admin-main flex-1 flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto admin-page-wrap">
            <div class="settings-layout grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-start min-w-0">
                <aside class="settings-tab-nav lg:col-span-3 bg-white border border-slate-100 rounded-2xl p-3 shadow-sm space-y-1 min-w-0">
                    <?php
                    $tabs = [
                        'my-account' => ['label' => 'My Account', 'icon' => 'user', 'desc' => ''],
                        'security'   => ['label' => 'Security', 'icon' => 'shield', 'desc' => ''],
                    ];
                    if ($isAdmin) {
                        $tabs['account-management']   = ['label' => 'Staff Accounts', 'icon' => 'users', 'desc' => 'Portal users & roles'];
                        $tabs['system-configuration'] = ['label' => 'Configuration', 'icon' => 'settings', 'desc' => 'Office, portal & email'];
                        $tabs['admin-tools']          = ['label' => 'Admin Tools', 'icon' => 'wrench', 'desc' => 'Stats, logs & upkeep'];
                    }
                    foreach ($tabs as $id => $tab):
                    ?>
                    <button type="button" data-tab="<?= htmlspecialchars($id) ?>" id="btn-<?= $id ?>"
                        class="tab-btn w-full flex items-start gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-slate-600 text-left <?= $activeTab === $id ? 'active' : '' ?>">
                        <i data-lucide="<?= $tab['icon'] ?>" class="w-4 h-4 text-slate-500 shrink-0 mt-0.5"></i>
                        <span class="min-w-0">
                            <span class="tab-label block"><?= htmlspecialchars($tab['label']) ?></span>
                            <?php if (!empty($tab['desc'])): ?>
                            <span class="tab-desc"><?= htmlspecialchars($tab['desc']) ?></span>
                            <?php endif; ?>
                        </span>
                    </button>
                    <?php endforeach; ?>
                </aside>

                <section class="settings-tab-panel lg:col-span-9 space-y-6 min-w-0">
                    <!-- MY ACCOUNT -->
                    <div id="tab-my-account" class="tab-content bg-white border border-slate-100 rounded-2xl p-4 sm:p-6 lg:p-8 shadow-sm <?= $activeTab !== 'my-account' ? 'hidden' : '' ?>">
                        <div class="flex items-center gap-5 mb-8">
                            <?= renderStaffAvatar($currentStaffPhoto, personNameFromRow($currentStaff ?? []), 'w-20 h-20 text-3xl', 'rounded-2xl') ?>
                            <div>
                                <h2 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars(personNameFromRow($currentStaff ?? [])) ?></h2>
                                <p class="text-sm text-slate-400 font-mono"><?= htmlspecialchars($currentStaffId) ?></p>
                                <span class="inline-block mt-2 text-[10px] font-bold uppercase px-2 py-0.5 rounded <?= staffRole() === 'Staff' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' ?>"><?= htmlspecialchars(staffRole()) ?></span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Member Since</p>
                                <p class="text-sm font-semibold text-slate-800"><?= formatRecordDate(substr($currentStaff['created_at'] ?? '', 0, 10)) ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Last Login</p>
                                <p class="text-sm font-semibold text-slate-800"><?= $lastLogin ? formatRecordDate(substr($lastLogin, 0, 10)) : 'First session' ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Recovery Gmail</p>
                                <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($currentStaff['email'] ?? 'Not set') ?></p>
                                <?php if (!empty($currentStaff['email'])): ?>
                                <p class="text-[10px] mt-1 <?= !empty($currentStaff['recovery_gmail_2sv_confirmed']) ? 'text-emerald-600' : 'text-amber-600' ?> font-semibold">
                                    <?= !empty($currentStaff['recovery_gmail_2sv_confirmed']) ? '2-Step Verification confirmed' : '2-Step Verification not confirmed — password reset disabled' ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Office Email</p>
                                <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($settings['office_email']) ?></p>
                            </div>
                        </div>

                        <form method="POST" class="space-y-4 border-t border-slate-100 pt-6" id="profileForm">
                            <?= authFormField() ?>
                            <input type="hidden" name="settings_action" value="update_profile">
                            <input type="hidden" name="active_tab" value="my-account">
                            <h3 class="text-sm font-bold text-slate-900">Update Name</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:col-span-2">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">First Name</label>
                                    <input type="text" name="profile_first_name" required value="<?= htmlspecialchars($currentStaff['first_name'] ?? '') ?>"
                                        class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Middle Name</label>
                                    <input type="text" name="profile_middle_name" value="<?= htmlspecialchars($currentStaff['middle_name'] ?? '') ?>"
                                        class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Last Name</label>
                                    <input type="text" name="profile_last_name" required value="<?= htmlspecialchars($currentStaff['last_name'] ?? '') ?>"
                                        class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-blue-500">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Staff ID</label>
                                    <input type="text" readonly value="<?= htmlspecialchars($currentStaffId) ?>"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-500 font-mono">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Recovery Gmail *</label>
                                    <input type="email" name="profile_email" id="profileEmail" required value="<?= htmlspecialchars($currentStaff['email'] ?? '') ?>" placeholder="you@gmail.com"
                                        data-original-email="<?= htmlspecialchars($currentStaff['email'] ?? '') ?>"
                                        data-2sv-confirmed="<?= !empty($currentStaff['recovery_gmail_2sv_confirmed']) ? '1' : '0' ?>"
                                        class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-blue-500">
                                    <p class="text-[10px] text-slate-400 mt-1">Must be a Gmail account with <strong>Google 2-Step Verification</strong> already enabled. Used for password reset codes.</p>
                                </div>
                                <div class="sm:col-span-2 recovery-2sv-field <?= $profileNeeds2svConfirmation ? '' : 'hidden' ?>" id="profile2svField">
                                    <label class="flex items-start gap-3 cursor-pointer">
                                        <input type="checkbox" name="recovery_gmail_2sv_confirmed" value="1" class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500 recovery-2sv-checkbox" <?= $profileNeeds2svConfirmation ? 'required' : '' ?>>
                                        <span class="text-xs text-slate-600 leading-relaxed">I confirm this Gmail account already has Google 2-Step Verification turned on. <a href="https://myaccount.google.com/signinoptions/two-step-verification" target="_blank" rel="noopener noreferrer" class="text-blue-600 font-semibold hover:underline">Enable 2-Step Verification</a></span>
                                    </label>
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl text-sm font-semibold inline-flex items-center gap-2">
                                    <i data-lucide="save" class="w-4 h-4"></i> Save Profile
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- SECURITY -->
                    <div id="tab-security" class="tab-content bg-white border border-slate-100 rounded-2xl p-4 sm:p-6 lg:p-8 shadow-sm <?= $activeTab !== 'security' ? 'hidden' : '' ?>">
                        <h2 class="text-lg font-bold text-slate-900 mb-1">Security & Credentials</h2>
                        <p class="text-xs text-slate-500 mb-6">Change your password and review account security policies.</p>

                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-sm text-slate-600 mb-8 space-y-1">
                            <p><strong>Password policy:</strong> Minimum <?= passwordMinLength() ?> characters with uppercase, lowercase, and a number.</p>
                            <p><strong>Forgot password:</strong> Use the link on the login page. A 6-digit code is sent to your registered Gmail with 2-Step Verification confirmed.</p>
                            <p><strong>Recovery Gmail:</strong> Must be @gmail.com with Google 2-Step Verification already enabled before password reset works.</p>
                            <p><strong>Staff IDs:</strong> Alphanumeric characters and hyphens only (e.g. ALORAN-001).</p>
                            <p><strong>Session:</strong> Login tokens expire after 7 days of inactivity.</p>
                        </div>

                        <form method="POST" class="space-y-4 max-w-lg">
                            <?= authFormField() ?>
                            <input type="hidden" name="settings_action" value="change_password">
                            <input type="hidden" name="active_tab" value="security">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Current Password</label>
                                <input type="password" name="current_password" required autocomplete="current-password"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">New Password</label>
                                <input type="password" name="new_password" required minlength="<?= passwordMinLength() ?>" autocomplete="new-password"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Confirm New Password</label>
                                <input type="password" name="confirm_password" required minlength="<?= passwordMinLength() ?>" autocomplete="new-password"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white px-6 py-2.5 rounded-xl text-sm font-semibold inline-flex items-center gap-2">
                                <i data-lucide="key" class="w-4 h-4"></i> Update Password
                            </button>
                        </form>
                    </div>

                    <?php if ($isAdmin): ?>
                    <!-- ACCOUNT MANAGEMENT -->
                    <div id="tab-account-management" class="tab-content bg-white border border-slate-100 rounded-2xl p-4 sm:p-6 lg:p-8 shadow-sm <?= $activeTab !== 'account-management' ? 'hidden' : '' ?>">
                        <div class="flex justify-between items-start mb-6">
                            <div>
                                <h2 class="text-lg font-bold text-slate-900 mb-1">Staff Members</h2>
                                <p class="text-xs text-slate-500">Manage staff portal access and profile photos.</p>
                            </div>
                            <span class="text-[10px] font-bold uppercase bg-slate-100 text-slate-600 px-3 py-1 rounded-full"><?= count($staffMembers) ?> accounts</span>
                        </div>

                        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8 pb-8 border-b border-slate-100">
                            <?= authFormField() ?>
                            <input type="hidden" name="settings_action" value="add_staff">
                            <input type="hidden" name="active_tab" value="account-management">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:col-span-2">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">First Name</label>
                                    <input type="text" name="staff_first_name" required placeholder="Juan" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Middle Name</label>
                                    <input type="text" name="staff_middle_name" placeholder="Dela" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Last Name</label>
                                    <input type="text" name="staff_last_name" required placeholder="Cruz" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Staff ID</label>
                                <input type="text" name="staff_id_new" required placeholder="ALORAN-002" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm uppercase focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Recovery Gmail *</label>
                                <input type="email" name="staff_email" required placeholder="staff@gmail.com" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                                <p class="text-[10px] text-slate-400 mt-1">Gmail with Google 2-Step Verification required.</p>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Role</label>
                                <select name="staff_role" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                                    <option value="Staff">Staff</option>
                                    <option value="Administrator">Administrator</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Password</label>
                                <input type="password" name="staff_password" required minlength="<?= passwordMinLength() ?>" placeholder="Minimum <?= passwordMinLength() ?> characters" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" name="recovery_gmail_2sv_confirmed" value="1" required class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                    <span class="text-xs text-slate-600 leading-relaxed">I confirm this Gmail account already has Google 2-Step Verification turned on. <a href="https://myaccount.google.com/signinoptions/two-step-verification" target="_blank" rel="noopener noreferrer" class="text-blue-600 font-semibold hover:underline">Enable 2-Step Verification</a></span>
                                </label>
                            </div>
                            <div class="sm:col-span-2">
                                <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white px-6 py-2.5 rounded-xl text-sm font-semibold">Add Staff Member</button>
                            </div>
                        </form>

                        <div class="overflow-x-auto rounded-xl border border-slate-200">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-400 border-b border-slate-200">
                                    <tr>
                                        <th class="px-4 py-3">Photo</th>
                                        <th class="px-4 py-3">Staff ID</th>
                                        <th class="px-4 py-3">Name</th>
                                        <th class="px-4 py-3">Gmail</th>
                                        <th class="px-4 py-3">2SV</th>
                                        <th class="px-4 py-3">Role</th>
                                        <th class="px-4 py-3">Added</th>
                                        <th class="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($staffMembers as $member): ?>
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="px-4 py-3">
                                            <div class="flex flex-col items-center gap-1">
                                                <?= renderStaffAvatar($member['profile_photo_path'] ?? null, personNameFromRow($member), 'w-10 h-10 text-sm') ?>
                                                <form method="POST" enctype="multipart/form-data" class="staff-photo-form">
                                                    <?= authFormField() ?>
                                                    <input type="hidden" name="settings_action" value="upload_staff_photo">
                                                    <input type="hidden" name="active_tab" value="account-management">
                                                    <input type="hidden" name="target_staff_id" value="<?= htmlspecialchars($member['staff_id']) ?>">
                                                    <input type="file" name="staff_photo" accept="image/jpeg,image/png,image/webp" class="hidden staff-photo-input">
                                                    <button type="button" class="staff-photo-trigger text-[10px] font-semibold text-blue-600 hover:underline">Change</button>
                                                </form>
                                                <?php if (!empty($member['profile_photo_path'])): ?>
                                                <form method="POST">
                                                    <?= authFormField() ?>
                                                    <input type="hidden" name="settings_action" value="remove_staff_photo">
                                                    <input type="hidden" name="active_tab" value="account-management">
                                                    <input type="hidden" name="target_staff_id" value="<?= htmlspecialchars($member['staff_id']) ?>">
                                                    <button type="submit" class="text-[10px] text-slate-400 hover:text-red-500">Remove</button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs font-bold text-blue-600 whitespace-nowrap"><?= htmlspecialchars($member['staff_id']) ?></td>
                                        <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap">
                                            <?= htmlspecialchars(personNameFromRow($member)) ?>
                                            <?php if ($member['staff_id'] === $currentStaffId): ?><span class="text-[9px] text-blue-500 font-normal"> (You)</span><?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap"><?= htmlspecialchars($member['email'] ?? '—') ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php if (empty($member['email'])): ?>
                                            <span class="text-[10px] text-slate-400">—</span>
                                            <?php elseif (!empty($member['recovery_gmail_2sv_confirmed'])): ?>
                                            <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-emerald-100 text-emerald-700">Confirmed</span>
                                            <?php else: ?>
                                            <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-amber-100 text-amber-700">Required</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded whitespace-nowrap <?= $member['role'] === 'Staff' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' ?>"><?= htmlspecialchars($member['role']) ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-400 text-xs whitespace-nowrap"><?= formatDateDisplay($member['created_at']) ?></td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <button type="button" class="edit-staff-btn text-[10px] font-semibold text-blue-600 hover:underline"
                                                data-staff-id="<?= htmlspecialchars($member['staff_id']) ?>"
                                                data-staff-first-name="<?= htmlspecialchars($member['first_name'] ?? '') ?>"
                                                data-staff-middle-name="<?= htmlspecialchars($member['middle_name'] ?? '') ?>"
                                                data-staff-last-name="<?= htmlspecialchars($member['last_name'] ?? '') ?>"
                                                data-staff-email="<?= htmlspecialchars($member['email'] ?? '') ?>"
                                                data-staff-role="<?= htmlspecialchars($member['role']) ?>"
                                                data-staff-2sv-confirmed="<?= !empty($member['recovery_gmail_2sv_confirmed']) ? '1' : '0' ?>">Edit</button>
                                            <span class="text-slate-200 mx-1">·</span>
                                            <button type="button" class="reset-staff-btn text-[10px] font-semibold text-amber-600 hover:underline"
                                                data-staff-id="<?= htmlspecialchars($member['staff_id']) ?>">Reset</button>
                                            <?php if ($member['staff_id'] !== $currentStaffId): ?>
                                            <span class="text-slate-200 mx-1">·</span>
                                            <form method="POST" class="inline">
                                                <?= authFormField() ?>
                                                <input type="hidden" name="settings_action" value="remove_staff">
                                                <input type="hidden" name="active_tab" value="account-management">
                                                <input type="hidden" name="target_staff_id" value="<?= htmlspecialchars($member['staff_id']) ?>">
                                                <button type="submit" class="text-[10px] font-semibold text-red-500 hover:underline">Delete</button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- SYSTEM CONFIGURATION -->
                    <div id="tab-system-configuration" class="tab-content bg-white border border-slate-100 rounded-2xl p-4 sm:p-6 lg:p-8 shadow-sm <?= $activeTab !== 'system-configuration' ? 'hidden' : '' ?>">
                        <div class="mb-6">
                            <h2 class="text-lg font-bold text-slate-900">System Configuration</h2>
                            <p class="text-xs text-slate-500 mt-1">Expand each section to edit. Save once at the bottom — all changes apply together.</p>
                        </div>
                        <form method="POST" class="space-y-4">
                            <?= authFormField() ?>
                            <input type="hidden" name="settings_action" value="save_settings">
                            <input type="hidden" name="active_tab" value="system-configuration">

                            <details class="config-section group rounded-xl border border-slate-200 overflow-hidden" open>
                                <summary class="flex items-center justify-between gap-3 px-4 py-3.5 bg-slate-50 hover:bg-slate-100/80 font-semibold text-sm text-slate-800">
                                    <span class="flex items-center gap-2"><i data-lucide="building-2" class="w-4 h-4 text-slate-500"></i> Office Information</span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 config-chevron"></i>
                                </summary>
                                <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-slate-100">
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Site Name</label><input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Office Name</label><input type="text" name="office_name" value="<?= htmlspecialchars($settings['office_name']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div class="sm:col-span-2"><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Address</label><input type="text" name="office_address" value="<?= htmlspecialchars($settings['office_address']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Phone</label><input type="text" name="office_phone" value="<?= htmlspecialchars($settings['office_phone']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Email</label><input type="email" name="office_email" value="<?= htmlspecialchars($settings['office_email']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Office Hours</label><input type="text" name="office_hours" value="<?= htmlspecialchars($settings['office_hours']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Office Head / Signatory</label><input type="text" name="office_head" value="<?= htmlspecialchars($settings['office_head']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                </div>
                            </details>

                            <details class="config-section group rounded-xl border border-slate-200 overflow-hidden">
                                <summary class="flex items-center justify-between gap-3 px-4 py-3.5 bg-slate-50 hover:bg-slate-100/80 font-semibold text-sm text-slate-800">
                                    <span class="flex items-center gap-2"><i data-lucide="globe" class="w-4 h-4 text-slate-500"></i> Public Portal Content</span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 config-chevron"></i>
                                </summary>
                                <div class="p-4 space-y-4 border-t border-slate-100">
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Overview Description</label>
                                        <textarea name="overview_text" rows="3" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><?= htmlspecialchars($settings['overview_text']) ?></textarea>
                                        <p class="text-[10px] text-slate-400 mt-1">Use <code class="bg-slate-100 px-1 rounded">{office}</code> to insert the office name.</p>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Portal Banner Title</label><input type="text" name="portal_title" value="<?= htmlspecialchars($settings['portal_title']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                        <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Privacy Policy URL</label><input type="text" name="privacy_policy_url" value="<?= htmlspecialchars($settings['privacy_policy_url']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    </div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Portal Banner Description</label><textarea name="portal_description" rows="2" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><?= htmlspecialchars($settings['portal_description']) ?></textarea></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Kiosk Welcome Message</label><input type="text" name="kiosk_welcome_message" value="<?= htmlspecialchars($settings['kiosk_welcome_message']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                </div>
                            </details>

                            <details class="config-section group rounded-xl border border-slate-200 overflow-hidden">
                                <summary class="flex items-center justify-between gap-3 px-4 py-3.5 bg-slate-50 hover:bg-slate-100/80 font-semibold text-sm text-slate-800">
                                    <span class="flex items-center gap-2"><i data-lucide="mail" class="w-4 h-4 text-slate-500"></i> Operations, Queue & Email</span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 config-chevron"></i>
                                </summary>
                                <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-slate-100">
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Queue Window Number</label><input type="number" name="queue_window" value="<?= htmlspecialchars($settings['queue_window']) ?>" min="1" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Max Daily Appointments</label><input type="number" name="max_daily_appointments" value="<?= htmlspecialchars($settings['max_daily_appointments']) ?>" min="1" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div class="sm:col-span-2"><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Notification Email</label><input type="email" name="notification_email" value="<?= htmlspecialchars($settings['notification_email']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><p class="text-[10px] text-slate-400 mt-1">Fallback From address if Gmail SMTP is not used.</p></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Gmail SMTP Host</label><input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?: 'smtp.gmail.com') ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Gmail SMTP Port</label><input type="number" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?: '587') ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><p class="text-[10px] text-slate-400 mt-1">587 for STARTTLS, or 465 for SSL.</p></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Gmail Address (SMTP user)</label><input type="email" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user']) ?>" placeholder="youroffice@gmail.com" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Gmail App Password</label><input type="password" name="smtp_pass" id="smtpPassInput" value="<?= $smtpPassSaved ? htmlspecialchars($smtpPassMask) : '' ?>" autocomplete="new-password" data-saved-mask="<?= $smtpPassSaved ? htmlspecialchars($smtpPassMask) : '' ?>" data-saved-value="<?= $smtpPassSaved ? htmlspecialchars($settings['smtp_pass']) : '' ?>" placeholder="<?= $smtpPassSaved ? '' : 'App password from Google Account' ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><p class="text-[10px] text-slate-400 mt-1">Create an App Password in Google Account → Security.<?= $smtpPassSaved ? ' Click the field, then the eye icon, to view the saved password.' : '' ?></p></div>
                                </div>
                            </details>

                            <details class="config-section group rounded-xl border border-slate-200 overflow-hidden">
                                <summary class="flex items-center justify-between gap-3 px-4 py-3.5 bg-slate-50 hover:bg-slate-100/80 font-semibold text-sm text-slate-800">
                                    <span class="flex items-center gap-2"><i data-lucide="message-square" class="w-4 h-4 text-slate-500"></i> SMS (Semaphore)</span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 config-chevron"></i>
                                </summary>
                                <div class="p-4 space-y-4 border-t border-slate-100">
                                    <?php
                                    require_once __DIR__ . '/includes/sms.php';
                                    $smsSummary = smsConfigurationSummary();
                                    ?>
                                    <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                                        <?php if ($smsSummary['configured']): ?>
                                        <p class="font-semibold text-green-700">Semaphore is configured and ready to send SMS.</p>
                                        <?php elseif ($smsSummary['enabled'] && !$smsSummary['has_api_key']): ?>
                                        <p class="font-semibold text-amber-700">SMS is enabled but no API key is saved yet.</p>
                                        <?php else: ?>
                                        <p class="font-semibold text-slate-700">SMS is off until you subscribe at <a href="https://semaphore.co" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">semaphore.co</a> and enter your API key below.</p>
                                        <?php endif; ?>
                                        <p class="mt-1.5">Citizens who opt in receive text messages when a request is accepted, when it is ready for pickup, and 3 hours and 1 hour before a confirmed visit.</p>
                                    </div>
                                    <label class="flex items-center justify-between p-4 bg-white rounded-xl border border-slate-100 cursor-pointer">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-800">Enable SMS notifications</p>
                                            <p class="text-xs text-slate-500">Turn on after your Semaphore account is active and funded.</p>
                                        </div>
                                        <input type="checkbox" name="sms_enabled" value="1" <?= $settings['sms_enabled'] === '1' ? 'checked' : '' ?> class="rounded text-blue-600 w-5 h-5">
                                    </label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Semaphore API Key</label><input type="password" name="semaphore_api_key" value="" autocomplete="new-password" placeholder="<?= $settings['semaphore_api_key'] !== '' ? 'Leave blank to keep the saved key' : 'API key from Semaphore dashboard' ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><p class="text-[10px] text-slate-400 mt-1">Found under Account → API in your Semaphore dashboard.</p></div>
                                        <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Sender Name (optional)</label><input type="text" name="semaphore_sender_name" value="<?= htmlspecialchars($settings['semaphore_sender_name']) ?>" maxlength="11" placeholder="ALCROS" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><p class="text-[10px] text-slate-400 mt-1">Must be registered with Semaphore. Leave blank for the default sender.</p></div>
                                    </div>
                                </div>
                            </details>

                            <details class="config-section group rounded-xl border border-slate-200 overflow-hidden">
                                <summary class="flex items-center justify-between gap-3 px-4 py-3.5 bg-slate-50 hover:bg-slate-100/80 font-semibold text-sm text-slate-800">
                                    <span class="flex items-center gap-2"><i data-lucide="shield-check" class="w-4 h-4 text-slate-500"></i> Portal Access Controls</span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 config-chevron"></i>
                                </summary>
                                <div class="p-4 space-y-3 border-t border-slate-100">
                                    <label class="flex items-center justify-between p-4 bg-slate-50 rounded-xl border border-slate-100 cursor-pointer">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-800">Maintenance Mode</p>
                                            <p class="text-xs text-slate-500">Show the maintenance announcement and temporarily block new online requests.</p>
                                        </div>
                                        <input type="checkbox" name="maintenance_mode" id="maintenanceModeToggle" value="1" <?= $settings['maintenance_mode'] === '1' ? 'checked' : '' ?> class="rounded text-blue-600 w-5 h-5">
                                    </label>
                                    <label class="flex items-center justify-between p-4 bg-slate-50 rounded-xl border border-slate-100 cursor-pointer">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-800">Allow Public Requests</p>
                                            <p class="text-xs text-slate-500">Citizens can submit new document requests online.</p>
                                        </div>
                                        <input type="checkbox" name="allow_public_requests" id="allowPublicRequestsToggle" value="1" <?= $settings['allow_public_requests'] === '1' ? 'checked' : '' ?> class="rounded text-blue-600 w-5 h-5">
                                    </label>
                                </div>
                            </details>

                            <div class="pt-2 flex justify-end sticky bottom-0 bg-white/95 backdrop-blur border-t border-slate-100 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-4 mt-4">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl text-sm font-semibold inline-flex items-center gap-2">
                                    <i data-lucide="save" class="w-4 h-4"></i> Save Settings
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ADMIN TOOLS -->
                    <div id="tab-admin-tools" class="tab-content <?= $activeTab !== 'admin-tools' ? 'hidden' : '' ?>">
                        <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden mb-5">
                            <div class="px-5 py-4 border-b border-slate-100">
                                <h2 class="text-base font-black text-slate-900">Admin Tools</h2>
                                <p class="text-xs text-slate-500 mt-0.5">System health, audit trail, and maintenance — one section at a time.</p>
                            </div>
                            <nav class="flex gap-1 p-2 overflow-x-auto bg-slate-50/80 border-b border-slate-100" aria-label="Admin tools sections">
                                <?php
                                $adminToolTabs = [
                                    'overview'    => ['label' => 'Overview', 'icon' => 'layout-dashboard'],
                                    'activity'    => ['label' => 'Activity', 'icon' => 'activity', 'count' => count($recentLogs)],
                                    'backup'      => ['label' => 'Cloud Backup', 'icon' => 'cloud-upload'],
                                    'maintenance' => ['label' => 'Maintenance', 'icon' => 'wrench', 'count' => count($activeSystemErrors)],
                                ];
                                foreach ($adminToolTabs as $subKey => $subTab):
                                ?>
                                <a href="<?= htmlspecialchars(settingsPageUrl('admin-tools', $subKey)) ?>"
                                   class="admin-sub-tab flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold border border-transparent whitespace-nowrap text-slate-600 hover:bg-white <?= $adminToolsSection === $subKey ? 'is-active' : '' ?>">
                                    <i data-lucide="<?= $subTab['icon'] ?>" class="w-3.5 h-3.5"></i>
                                    <?= htmlspecialchars($subTab['label']) ?>
                                    <?php if (isset($subTab['count'])): ?>
                                    <span class="min-w-[1.1rem] h-5 px-1 rounded-full text-[10px] font-black flex items-center justify-center <?= $adminToolsSection === $subKey ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-600' ?>"><?= (int) $subTab['count'] ?></span>
                                    <?php endif; ?>
                                </a>
                                <?php endforeach; ?>
                            </nav>

                            <!-- Overview -->
                            <div class="p-5 sm:p-6 <?= $adminToolsSection !== 'overview' ? 'hidden' : '' ?>">
                                <h3 class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-3">Database totals</h3>
                                <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
                                    <?php foreach ($systemStats as $stat): ?>
                                    <div class="rounded-xl p-4 bg-slate-50 border border-slate-100">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase"><?= htmlspecialchars($stat['label']) ?></p>
                                        <p class="text-xl font-black text-slate-900 mt-1"><?= number_format($stat['count']) ?></p>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs text-slate-600 rounded-xl p-4 bg-slate-50 border border-slate-100 mb-6">
                                    <div><span class="text-slate-400 font-bold uppercase text-[10px]">Database</span><p class="font-semibold mt-1"><?= htmlspecialchars(DB_NAME) ?></p></div>
                                    <div><span class="text-slate-400 font-bold uppercase text-[10px]">Host</span><p class="font-semibold mt-1"><?= htmlspecialchars(DB_HOST) ?></p></div>
                                    <div><span class="text-slate-400 font-bold uppercase text-[10px]">PHP</span><p class="font-semibold mt-1"><?= htmlspecialchars(PHP_VERSION) ?></p></div>
                                </div>
                                <h3 class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-3">Quick links</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <?php
                                    $adminQuickLinks = [
                                        ['url' => buildAuthUrl('report.php', ['section' => 'analytics']), 'label' => 'Reports', 'desc' => 'Charts, statistics & exports', 'icon' => 'bar-chart-2'],
                                        ['url' => buildAuthUrl('activity-log.php'), 'label' => 'Full Activity Log', 'desc' => 'Search all staff actions', 'icon' => 'scroll-text'],
                                        ['url' => settingsPageUrl('system-configuration'), 'label' => 'Configuration', 'desc' => 'Office & portal settings', 'icon' => 'settings'],
                                    ];
                                    foreach ($adminQuickLinks as $link):
                                    ?>
                                    <a href="<?= htmlspecialchars($link['url']) ?>"
                                       class="flex items-start gap-3 p-4 rounded-xl border border-slate-100 hover:border-blue-200 hover:bg-blue-50/40 transition-colors">
                                        <div class="p-2 bg-white rounded-lg border border-slate-100 shrink-0">
                                            <i data-lucide="<?= $link['icon'] ?>" class="w-4 h-4 text-slate-600"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold text-slate-800"><?= htmlspecialchars($link['label']) ?></p>
                                            <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($link['desc']) ?></p>
                                        </div>
                                        <i data-lucide="chevron-right" class="w-4 h-4 text-slate-300 shrink-0 mt-1"></i>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Activity preview -->
                            <div class="p-5 sm:p-6 <?= $adminToolsSection !== 'activity' ? 'hidden' : '' ?>">
                                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                                    <div>
                                        <h3 class="text-sm font-bold text-slate-900">Recent staff actions</h3>
                                        <p class="text-xs text-slate-500">Latest 8 entries — open the full log to search and filter.</p>
                                    </div>
                                    <a href="<?= htmlspecialchars(buildAuthUrl('activity-log.php')) ?>" class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:underline">
                                        Open full log <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                                    </a>
                                </div>
                                <?php if (empty($recentLogs)): ?>
                                <p class="text-sm text-slate-400 py-8 text-center rounded-xl bg-slate-50 border border-slate-100">No activity logged yet.</p>
                                <?php else: ?>
                                <div class="divide-y divide-slate-100 rounded-xl border border-slate-100 overflow-hidden">
                                    <?php foreach ($recentLogs as $log): ?>
                                    <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 hover:bg-slate-50/60">
                                        <div class="flex items-center gap-3 min-w-0 flex-1">
                                            <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                                                <i data-lucide="activity" class="w-4 h-4"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($log['action']) ?></p>
                                                <p class="text-xs text-slate-400 truncate"><?= htmlspecialchars($log['staff_id'] ?? 'System') ?> · <?= htmlspecialchars($log['details'] ?? '') ?></p>
                                            </div>
                                        </div>
                                        <span class="text-[11px] text-slate-400 shrink-0"><?= htmlspecialchars(formatDateDisplay($log['created_at'])) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Cloud backup (Duplicati) -->
                            <div class="p-5 sm:p-6 <?= $adminToolsSection !== 'backup' ? 'hidden' : '' ?>">
                                <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-5 mb-6">
                                    <h3 class="text-sm font-bold text-emerald-950 flex items-center gap-2">
                                        <i data-lucide="shield-check" class="w-4 h-4"></i> What gets backed up
                                    </h3>
                                    <ul class="mt-3 space-y-1.5 text-xs text-emerald-900/90 list-disc pl-5">
                                        <li>Civil records (birth, death, marriage) and detail tables</li>
                                        <li>Staff accounts (login IDs, roles, password hashes, emails)</li>
                                        <li>Print templates, field positions, and calibrations</li>
                                        <li>Print settings (global offsets, mode, province/city labels)</li>
                                        <li>Staff profile photos and print form reference files</li>
                                    </ul>
                                    <p class="text-[11px] text-emerald-800/70 mt-3">Requests, appointments, queue tickets, and activity logs are <strong>not</strong> included.</p>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                                    <div class="rounded-xl p-4 bg-slate-50 border border-slate-100">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Last export</p>
                                        <p class="text-sm font-black text-slate-900 mt-1">
                                            <?= $backupLastRun !== '' ? htmlspecialchars(formatReportDateTime($backupLastRun)) : 'Not run yet' ?>
                                        </p>
                                    </div>
                                    <div class="rounded-xl p-4 bg-slate-50 border border-slate-100">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Last cloud upload</p>
                                        <p class="text-sm font-black text-slate-900 mt-1">
                                            <?= $cloudBackupLastUpload !== '' ? htmlspecialchars(formatReportDateTime($cloudBackupLastUpload)) : 'Not uploaded yet' ?>
                                        </p>
                                        <?php if ($cloudBackupLastUploadStatus !== ''): ?>
                                        <p class="text-[11px] mt-1 font-semibold <?= $cloudBackupLastUploadStatus === 'success' ? 'text-emerald-600' : 'text-red-600' ?>">
                                            <?= htmlspecialchars(ucfirst($cloudBackupLastUploadStatus)) ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rounded-xl p-4 bg-slate-50 border border-slate-100">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Bundle size</p>
                                        <p class="text-sm font-black text-slate-900 mt-1"><?= htmlspecialchars(alcrosFormatBytes($backupDirSize)) ?></p>
                                    </div>
                                    <div class="rounded-xl p-4 bg-slate-50 border border-slate-100">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Civil records</p>
                                        <p class="text-sm font-black text-slate-900 mt-1"><?= number_format($backupRecordCount) ?></p>
                                    </div>
                                    <div class="rounded-xl p-4 bg-slate-50 border border-slate-100">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Staff accounts</p>
                                        <p class="text-sm font-black text-slate-900 mt-1"><?= number_format($backupStaffCount) ?></p>
                                    </div>
                                </div>

                                <?php if ($backupLastMessage !== '' && $backupLastStatus === 'error'): ?>
                                <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-800 mb-4">
                                    Export error: <?= htmlspecialchars($backupLastMessage) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($cloudBackupLastUploadMessage !== '' && $cloudBackupLastUploadStatus === 'error'): ?>
                                <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-800 mb-6">
                                    Cloud upload error: <?= htmlspecialchars($cloudBackupLastUploadMessage) ?>
                                </div>
                                <?php endif; ?>

                                <div class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-5 mb-8">
                                    <h3 class="text-base font-black text-slate-900 mb-1 flex items-center gap-2">
                                        <i data-lucide="cloud" class="w-4 h-4 text-indigo-600"></i> Live site backup (Hostinger / production)
                                    </h3>
                                    <p class="text-xs text-slate-600 mb-4 leading-relaxed">Use this when ALCROS is online. Every run exports <strong>all current</strong> civil records, staff, and print settings from the live database, then uploads a zip to <strong>Google Cloud Storage</strong>. New records added on the live site are included the next time backup runs.</p>

                                    <details class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5 mb-5 text-xs text-slate-600 leading-relaxed" open>
                                        <summary class="font-bold text-slate-900 cursor-pointer text-sm">Full setup guide — Google Cloud + ALCROS + Hostinger (click to collapse)</summary>

                                        <p class="mt-4 mb-2 text-slate-500">Do these steps <strong>once</strong> after ALCROS is live on Hostinger. Total time: about 20–30 minutes. You need a Google account and a credit/debit card for Google Cloud billing (small backups usually cost pennies per month).</p>

                                        <div class="rounded-lg border border-blue-100 bg-blue-50/70 p-4 mb-4 text-slate-700">
                                            <p class="font-bold text-slate-900 mb-1">What is the Google service account JSON?</p>
                                            <p class="mb-2">It is a small key file from Google (not created by ALCROS). ALCROS uses it to upload backups to your private cloud bucket <strong>without</strong> your personal Google password — so hourly cron backups work automatically on Hostinger.</p>
                                            <p class="font-semibold text-slate-800 mb-1">You get it from:</p>
                                            <p>Google Cloud Console → IAM &amp; Admin → Service Accounts → Keys → Add key → JSON → file downloads to your computer → you upload that file below on this page.</p>
                                        </div>

                                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4">
                                            <p class="font-bold text-slate-800 mb-2">Before you download the JSON, complete:</p>
                                            <ul class="list-disc pl-4 space-y-1 text-slate-600">
                                                <li>Step 1 — Google Cloud project created</li>
                                                <li>Step 2 — Billing enabled</li>
                                                <li>Step 3 — Cloud Storage API enabled</li>
                                                <li>Step 4 — Storage bucket created (save the bucket name)</li>
                                                <li>Step 5 — Service account created with Storage Object Admin role</li>
                                            </ul>
                                        </div>

                                        <div class="space-y-5">
                                            <section>
                                                <h4 class="text-sm font-black text-indigo-900 mb-2">Part 1 — Google Cloud (create bucket + JSON key file)</h4>

                                                <div class="space-y-4">
                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 1 — Sign in and create a project</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>Open <a href="https://console.cloud.google.com" target="_blank" rel="noopener noreferrer" class="text-blue-600 font-semibold hover:underline">console.cloud.google.com</a></li>
                                                            <li>Sign in with a Google account (office account recommended)</li>
                                                            <li>Top bar → <strong>Select a project</strong> → <strong>New Project</strong></li>
                                                            <li>Name it e.g. <code class="bg-white px-1 rounded border border-slate-200">alcros-backup</code> → <strong>Create</strong></li>
                                                            <li>Make sure that project is selected in the top bar</li>
                                                        </ol>
                                                    </div>

                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 2 — Turn on billing (required for cloud storage)</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>Menu ☰ → <strong>Billing</strong> → link a billing account</li>
                                                            <li>New accounts often get <strong>$300 free credit</strong> for 90 days</li>
                                                            <li>ALCROS backups are small — often under <strong>$1/month</strong> after free credit</li>
                                                        </ol>
                                                    </div>

                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 3 — Enable Cloud Storage API</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>Menu ☰ → <strong>APIs &amp; Services</strong> → <strong>Library</strong></li>
                                                            <li>Search <strong>Cloud Storage</strong></li>
                                                            <li>Open <strong>Cloud Storage API</strong> → click <strong>Enable</strong></li>
                                                        </ol>
                                                    </div>

                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 4 — Create a storage bucket (where backups are stored)</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>Menu ☰ → <strong>Cloud Storage</strong> → <strong>Buckets</strong> → <strong>Create</strong></li>
                                                            <li><strong>Name:</strong> e.g. <code class="bg-white px-1 rounded border border-slate-200">alcros-backup-youroffice</code> (must be globally unique — copy this name exactly for ALCROS settings)</li>
                                                            <li><strong>Location:</strong> Region near you (e.g. <em>asia-southeast1</em> Singapore)</li>
                                                            <li><strong>Storage class:</strong> Standard</li>
                                                            <li><strong>Access control:</strong> Uniform</li>
                                                            <li>Turn ON <strong>Enforce public access prevention</strong> (bucket stays private)</li>
                                                            <li>Click <strong>Create</strong></li>
                                                        </ol>
                                                    </div>

                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 5 — Create a service account (ALCROS login to Google)</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>Open <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener noreferrer" class="text-blue-600 font-semibold hover:underline">Service Accounts</a> (or Menu ☰ → <strong>IAM &amp; Admin</strong> → <strong>Service Accounts</strong>)</li>
                                                            <li>Click <strong>+ Create service account</strong></li>
                                                            <li><strong>Name:</strong> <code class="bg-white px-1 rounded border border-slate-200">alcros-backup-uploader</code></li>
                                                            <li>Click <strong>Create and continue</strong></li>
                                                            <li><strong>Role:</strong> choose <strong>Storage Object Admin</strong> → <strong>Continue</strong> → <strong>Done</strong></li>
                                                        </ol>
                                                    </div>

                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 6 — Download the JSON key file (this is the file you upload in ALCROS)</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>On the <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener noreferrer" class="text-blue-600 font-semibold hover:underline">Service Accounts</a> page, click the account you created (e.g. <code class="bg-white px-1 rounded border border-slate-200">alcros-backup-uploader@....iam.gserviceaccount.com</code>)</li>
                                                            <li>Open the <strong>Keys</strong> tab</li>
                                                            <li>Click <strong>Add key</strong> → <strong>Create new key</strong></li>
                                                            <li>Select <strong>JSON</strong> → click <strong>Create</strong></li>
                                                            <li>Your browser downloads a file (often to <strong>Downloads</strong>) — name looks like <code class="bg-white px-1 rounded border border-slate-200">your-project-abc123.json</code></li>
                                                            <li>That downloaded file is what you upload in <strong>Step 7</strong> below</li>
                                                        </ol>
                                                        <p class="mt-3 font-semibold text-slate-800">Security:</p>
                                                        <ul class="list-disc pl-4 space-y-1 mt-1">
                                                            <li>Treat the JSON like a password — do not email it or post it online</li>
                                                            <li>Do not put it inside your public website folder (<code class="bg-white px-1 rounded border border-slate-200">public_html</code>)</li>
                                                            <li>After upload, ALCROS stores it securely in <code class="bg-white px-1 rounded border border-slate-200">storage/secrets/</code> (not web-accessible)</li>
                                                        </ul>
                                                        <p class="mt-3 font-semibold text-slate-800">Lost the JSON file?</p>
                                                        <p class="mt-1">Google does not let you download the same key again. Create a new one: Service Accounts → your account → <strong>Keys</strong> → <strong>Add key</strong> → JSON → upload the new file in ALCROS. You may delete the old key in Google Cloud afterward.</p>
                                                    </div>
                                                </div>
                                            </section>

                                            <section>
                                                <h4 class="text-sm font-black text-indigo-900 mb-2">Part 2 — ALCROS (connect Google to this page)</h4>
                                                <div class="space-y-4">
                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 7 — Upload the JSON file below on this page</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>Scroll to <strong>Google service account JSON</strong> (below this guide)</li>
                                                            <li>Click <strong>Choose file</strong> → select the <code class="bg-white px-1 rounded border border-slate-200">.json</code> file from Step 6 (usually in your <strong>Downloads</strong> folder)</li>
                                                            <li>Click <strong>Upload JSON</strong></li>
                                                            <li>You should see: <strong>JSON saved on server</strong> (green check)</li>
                                                        </ol>
                                                    </div>

                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 8 — Fill in cloud settings (form below)</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>Check <strong>Enable automatic cloud backup on this server</strong></li>
                                                            <li><strong>Google Cloud Storage bucket:</strong> paste your bucket name from Step 4 (e.g. <code class="bg-white px-1 rounded border border-slate-200">alcros-backup-youroffice</code>)</li>
                                                            <li><strong>Folder inside bucket:</strong> leave as <code class="bg-white px-1 rounded border border-slate-200">alcros-backups</code> (unless you want another name)</li>
                                                            <li><strong>Keep how many cloud copies:</strong> <code class="bg-white px-1 rounded border border-slate-200">30</code> is recommended</li>
                                                            <li><strong>Live site URL:</strong> your real website, e.g. <code class="bg-white px-1 rounded border border-slate-200">https://yourdomain.com</code> (no trailing slash)</li>
                                                            <li>Click <strong>Save cloud settings</strong></li>
                                                        </ol>
                                                    </div>

                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 9 — Test that it works</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>Click <strong>Test cloud connection</strong> — should say connected successfully</li>
                                                            <li>Click <strong>Backup to cloud now</strong> — should show success</li>
                                                            <li>Check the <strong>Last cloud upload</strong> box at the top of this page — it should show today’s date and <strong>Success</strong></li>
                                                        </ol>
                                                    </div>

                                                    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                        <p class="font-bold text-slate-800 mb-1">Step 10 — How to see your backed-up files</p>
                                                        <p class="mb-2 text-slate-600">Backup zip files are stored in Google Cloud, not inside your website folder. You can check them in two places:</p>
                                                        <p class="font-semibold text-slate-800 mb-1">Option A — In ALCROS (easiest)</p>
                                                        <ol class="list-decimal pl-4 space-y-1 mb-3">
                                                            <li>Stay on this page: <strong>System Settings</strong> → <strong>Admin Tools</strong> → <strong>Cloud Backup</strong></li>
                                                            <li>Scroll to the <strong>Cloud backup files</strong> section (below the backup buttons)</li>
                                                            <li>You should see a table with file name, upload date, and size — e.g. <code class="bg-white px-1 rounded border border-slate-200">alcros-backup-2026-....zip</code></li>
                                                            <li>Newest backups appear at the top. Refresh this page after <strong>Backup to cloud now</strong> or after cron runs</li>
                                                            <li>Click <strong>Open in Google Cloud</strong> in that section if you need to download a zip file</li>
                                                        </ol>
                                                        <p class="font-semibold text-slate-800 mb-1">Option B — In Google Cloud Console</p>
                                                        <ol class="list-decimal pl-4 space-y-1">
                                                            <li>Go to <a href="https://console.cloud.google.com/storage/browser" target="_blank" rel="noopener noreferrer" class="text-blue-600 font-semibold hover:underline">Cloud Storage → Buckets</a></li>
                                                            <li>Click your bucket (e.g. <code class="bg-white px-1 rounded border border-slate-200">alcros-backup-youroffice</code>)</li>
                                                            <li>Open the folder <code class="bg-white px-1 rounded border border-slate-200">alcros-backups</code></li>
                                                            <li>You should see zip files like <code class="bg-white px-1 rounded border border-slate-200">alcros-backup-2026-....zip</code></li>
                                                            <li>Click a file → <strong>Download</strong> if you need a copy on your computer</li>
                                                        </ol>
                                                        <p class="mt-3 text-slate-500">If <strong>Cloud backup files</strong> says to upload JSON or save bucket name, complete Steps 7–8 first. If the table is empty, run <strong>Backup to cloud now</strong> once to create the first file.</p>
                                                    </div>
                                                </div>
                                            </section>

                                            <section>
                                                <h4 class="text-sm font-black text-indigo-900 mb-2">Part 3 — Hostinger (automatic hourly backups)</h4>
                                                <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                                                    <p class="font-bold text-slate-800 mb-1">Step 11 — Add a cron job so new live records are backed up automatically</p>
                                                    <ol class="list-decimal pl-4 space-y-1.5">
                                                        <li>Log in to <strong>Hostinger hPanel</strong></li>
                                                        <li>Go to <strong>Advanced</strong> → <strong>Cron Jobs</strong></li>
                                                        <li>Click <strong>Create cron job</strong></li>
                                                        <li><strong>Schedule:</strong> every hour (<code class="bg-white px-1 rounded border border-slate-200">0 * * * *</code>) — or every 15 minutes if your plan allows</li>
                                                        <li><strong>Command:</strong> copy the full <code class="bg-white px-1 rounded border border-slate-200">curl</code> line from the <strong>Hostinger cron job</strong> box below this guide</li>
                                                        <li>Save the cron job</li>
                                                        <li>After 1 hour, check <strong>Cloud backup files</strong> on this page (Step 10) — a new zip should appear</li>
                                                    </ol>
                                                    <p class="mt-3 text-slate-500">Each cron run exports <strong>all current</strong> civil records and staff from the live database, then uploads to Google Cloud. New records added today will be in the next backup.</p>
                                                </div>
                                            </section>

                                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
                                                <p class="font-bold mb-1">You are done when:</p>
                                                <ul class="list-disc pl-4 space-y-1">
                                                    <li>Test connection succeeds</li>
                                                    <li>Backup to cloud now succeeds</li>
                                                    <li><strong>Cloud backup files</strong> shows at least one zip (or you see it in Google Cloud bucket)</li>
                                                    <li>Hostinger cron is saved (for automatic backups)</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </details>

                                    <form method="POST" class="space-y-4 mb-5">
                                        <?= authFormField() ?>
                                        <input type="hidden" name="settings_action" value="save_cloud_backup_settings">
                                        <input type="hidden" name="active_tab" value="admin-tools">
                                        <input type="hidden" name="admin_sub" value="backup">
                                        <label class="flex items-center gap-3 cursor-pointer text-sm font-semibold text-slate-800">
                                            <input type="checkbox" name="cloud_backup_enabled" value="1" class="rounded text-indigo-600" <?= $cloudBackupEnabled ? 'checked' : '' ?>>
                                            Enable automatic cloud backup on this server
                                        </label>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Google Cloud Storage bucket</label>
                                                <input type="text" name="cloud_backup_gcs_bucket" value="<?= htmlspecialchars($cloudBackupBucket) ?>" placeholder="alcros-backup-youroffice" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Folder inside bucket</label>
                                                <input type="text" name="cloud_backup_gcs_prefix" value="<?= htmlspecialchars($cloudBackupPrefix) ?>" placeholder="alcros-backups" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Keep how many cloud copies</label>
                                                <input type="number" min="5" max="365" name="cloud_backup_retention_count" value="<?= (int) $cloudBackupRetention ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Live site URL (for cron)</label>
                                                <input type="url" name="cloud_backup_public_url" value="<?= htmlspecialchars($cloudBackupPublicUrl) ?>" placeholder="https://yourdomain.com" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                            </div>
                                        </div>
                                        <button type="submit" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold">
                                            <i data-lucide="save" class="w-4 h-4"></i> Save cloud settings
                                        </button>
                                    </form>

                                    <form method="POST" enctype="multipart/form-data" class="rounded-xl border border-slate-200 bg-white p-4 mb-5">
                                        <?= authFormField() ?>
                                        <input type="hidden" name="settings_action" value="upload_gcs_service_account">
                                        <input type="hidden" name="active_tab" value="admin-tools">
                                        <input type="hidden" name="admin_sub" value="backup">
                                        <p class="text-sm font-bold text-slate-900 mb-1">Google service account JSON</p>
                                        <p class="text-xs text-slate-500 mb-3">Upload the key file from Google Cloud (stored securely in <code class="bg-slate-100 px-1 rounded">storage/secrets/</code>, never in the public web folder).</p>
                                        <div class="flex flex-wrap items-end gap-3">
                                            <input type="file" name="gcs_service_account_json" accept=".json,application/json" class="text-xs">
                                            <button type="submit" class="inline-flex items-center gap-2 border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-4 py-2 rounded-xl text-xs font-bold">
                                                <i data-lucide="upload" class="w-4 h-4"></i> Upload JSON
                                            </button>
                                            <?php if ($cloudBackupGcsConfigured): ?>
                                            <span class="text-xs font-semibold text-emerald-600 flex items-center gap-1"><i data-lucide="circle-check" class="w-3.5 h-3.5"></i> JSON saved on server</span>
                                            <?php endif; ?>
                                        </div>
                                    </form>

                                    <div class="flex flex-wrap gap-2 mb-5">
                                        <form method="POST">
                                            <?= authFormField() ?>
                                            <input type="hidden" name="settings_action" value="run_live_cloud_backup">
                                            <input type="hidden" name="active_tab" value="admin-tools">
                                            <input type="hidden" name="admin_sub" value="backup">
                                            <button type="submit" class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold">
                                                <i data-lucide="cloud-upload" class="w-4 h-4"></i> Backup to cloud now
                                            </button>
                                        </form>
                                        <form method="POST">
                                            <?= authFormField() ?>
                                            <input type="hidden" name="settings_action" value="test_cloud_backup">
                                            <input type="hidden" name="active_tab" value="admin-tools">
                                            <input type="hidden" name="admin_sub" value="backup">
                                            <button type="submit" class="inline-flex items-center gap-2 border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-4 py-2.5 rounded-xl text-xs font-bold">
                                                <i data-lucide="plug" class="w-4 h-4"></i> Test cloud connection
                                            </button>
                                        </form>
                                    </div>

                                    <div class="rounded-xl border border-slate-200 bg-white p-4 mb-5">
                                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-3">
                                            <div>
                                                <p class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                                    <i data-lucide="archive" class="w-4 h-4 text-indigo-600"></i> Cloud backup files
                                                </p>
                                                <p class="text-xs text-slate-500 mt-1">
                                                    <?php if ($cloudBackupBucket !== ''): ?>
                                                    Stored in Google Cloud bucket <code class="bg-slate-100 px-1 rounded"><?= htmlspecialchars($cloudBackupBucket) ?></code><?= $cloudBackupPrefix !== '' ? ' / <code class="bg-slate-100 px-1 rounded">' . htmlspecialchars($cloudBackupPrefix) . '</code>' : '' ?>
                                                    <?php else: ?>
                                                    Set your bucket name and upload the JSON key to see cloud backups here.
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <?php if ($cloudBackupGcsConfigured && $cloudBackupBucket !== ''): ?>
                                            <a href="<?= htmlspecialchars($cloudBackupGcsBrowserUrl) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-xs font-bold text-indigo-600 hover:text-indigo-800 shrink-0">
                                                <i data-lucide="external-link" class="w-3.5 h-3.5"></i> Open in Google Cloud
                                            </a>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($cloudBackupFilesError !== ''): ?>
                                        <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 mb-3">
                                            Could not load cloud backup list: <?= htmlspecialchars($cloudBackupFilesError) ?>
                                        </div>
                                        <?php elseif (!$cloudBackupGcsConfigured || $cloudBackupBucket === ''): ?>
                                        <p class="text-xs text-slate-500">Upload the service account JSON and save your bucket name to list backups from Google Cloud.</p>
                                        <?php elseif ($cloudBackupFiles === []): ?>
                                        <p class="text-xs text-slate-500">No cloud backups yet. Click <strong>Backup to cloud now</strong> to create the first zip file.</p>
                                        <?php else: ?>
                                        <?php
                                        $cloudBackupFilesShown = array_slice($cloudBackupFiles, 0, 30);
                                        $cloudBackupFilesTotal = count($cloudBackupFiles);
                                        ?>
                                        <div class="overflow-x-auto rounded-lg border border-slate-100">
                                            <table class="min-w-full text-xs">
                                                <thead class="bg-slate-50 text-slate-500">
                                                    <tr>
                                                        <th class="text-left font-bold uppercase tracking-wide px-3 py-2">File</th>
                                                        <th class="text-left font-bold uppercase tracking-wide px-3 py-2">Uploaded</th>
                                                        <th class="text-right font-bold uppercase tracking-wide px-3 py-2">Size</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100">
                                                    <?php foreach ($cloudBackupFilesShown as $cloudFile): ?>
                                                    <tr class="hover:bg-slate-50/80">
                                                        <td class="px-3 py-2 font-mono text-[11px] text-slate-800"><?= htmlspecialchars($cloudFile['filename']) ?></td>
                                                        <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars(formatReportDateTime($cloudFile['timeCreated'])) ?></td>
                                                        <td class="px-3 py-2 text-right text-slate-600"><?= htmlspecialchars(alcrosFormatBytes((int) $cloudFile['size'])) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <p class="text-[11px] text-slate-400 mt-2">
                                            Showing <?= count($cloudBackupFilesShown) ?> of <?= $cloudBackupFilesTotal ?> backup file(s). Newest first. Download files from Google Cloud Console.
                                            <?php if ($cloudBackupLastUploadStatus === 'success' && $cloudBackupLastUploadMessage !== ''): ?>
                                            Last upload: <?= htmlspecialchars($cloudBackupLastUploadMessage) ?>.
                                            <?php endif; ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                                        <p class="text-sm font-bold text-slate-900 mb-2">Hostinger cron job (automatic backups)</p>
                                        <ol class="text-xs text-slate-600 space-y-2 list-decimal pl-4 mb-3">
                                            <li>Hostinger hPanel → <strong>Advanced</strong> → <strong>Cron Jobs</strong></li>
                                            <li>Create a job: <strong>every hour</strong> (or every 15 minutes if allowed)</li>
                                            <li>Paste this command (replace nothing if URL is already correct):</li>
                                        </ol>
                                        <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 font-mono text-[11px] text-slate-800 break-all">
                                            curl -s "<?= htmlspecialchars($cloudBackupCronUrl) ?>"
                                        </div>
                                        <p class="text-[11px] text-slate-400 mt-3">This URL is secret — anyone with the link can trigger a backup. Do not share it publicly.</p>
                                        <form method="POST" class="mt-3">
                                            <?= authFormField() ?>
                                            <input type="hidden" name="settings_action" value="regenerate_backup_cron_token">
                                            <input type="hidden" name="active_tab" value="admin-tools">
                                            <input type="hidden" name="admin_sub" value="backup">
                                            <button type="submit" class="text-xs font-bold text-slate-500 hover:text-red-600">Regenerate cron URL token</button>
                                        </form>
                                    </div>
                                </div>

                                <?php if ($backupIsLocalOffice): ?>
                                <div class="rounded-xl border border-amber-100 bg-amber-50/60 p-4 mb-6 text-xs text-amber-950">
                                    <p class="font-bold flex items-center gap-2"><i data-lucide="monitor" class="w-4 h-4"></i> Optional — local XAMPP only (Duplicati)</p>
                                    <p class="mt-1 text-amber-900/85 leading-relaxed">The section below is only for backing up ALCROS on this Windows PC. When the site is live on Hostinger, use the <strong>Live site backup</strong> section above instead.</p>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-white p-5 mb-8">
                                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-900">Create local backup folder only</h3>
                                            <p class="text-xs text-slate-500 mt-1">Does not upload to cloud. Use with Duplicati on this PC.</p>
                                        </div>
                                        <form method="POST" class="shrink-0">
                                            <?= authFormField() ?>
                                            <input type="hidden" name="settings_action" value="run_duplicati_backup">
                                            <input type="hidden" name="active_tab" value="admin-tools">
                                            <input type="hidden" name="admin_sub" value="backup">
                                            <button type="submit" class="inline-flex items-center gap-2 bg-slate-700 hover:bg-slate-800 text-white px-4 py-2.5 rounded-xl text-xs font-bold w-full sm:w-auto justify-center">
                                                <i data-lucide="refresh-cw" class="w-4 h-4"></i> Create backup bundle now
                                            </button>
                                        </form>
                                    </div>
                                    <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-xs text-slate-700 break-all">
                                        <?= htmlspecialchars(str_replace('\\', '/', $backupDir)) ?>
                                    </div>
                                </div>

                                <h3 class="text-base font-black text-slate-900 mb-1">Duplicati setup (local PC only)</h3>
                                <p class="text-xs text-slate-500 mb-5">Follow these in order. Setup takes about 15–20 minutes.</p>

                                <div class="space-y-4 mb-8">
                                    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                        <div class="flex gap-4 p-5">
                                            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-black flex items-center justify-center shrink-0">1</div>
                                            <div class="min-w-0 flex-1 text-sm text-slate-700">
                                                <p class="font-bold text-slate-900 mb-1">Download and install Duplicati</p>
                                                <p class="text-xs text-slate-500 mb-2">Free backup app for Windows.</p>
                                                <ol class="text-xs space-y-1.5 list-decimal pl-4 text-slate-600">
                                                    <li>Go to <a href="https://www.duplicati.com/download" target="_blank" rel="noopener noreferrer" class="text-blue-600 font-semibold hover:underline">duplicati.com/download</a></li>
                                                    <li>Download the Windows installer and run it</li>
                                                    <li>Open Duplicati — it may open in your browser (that is normal)</li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                        <div class="flex gap-4 p-5">
                                            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-black flex items-center justify-center shrink-0">2</div>
                                            <div class="min-w-0 flex-1 text-sm text-slate-700">
                                                <p class="font-bold text-slate-900 mb-1">Create a new backup job</p>
                                                <ol class="text-xs space-y-1.5 list-decimal pl-4 text-slate-600">
                                                    <li>Click <strong>Add backup</strong></li>
                                                    <li><strong>General</strong> — name it e.g. <em>ALCROS Cloud Backup</em></li>
                                                    <li><strong>Destination</strong> — pick <strong>Google Drive</strong> or <strong>Google Cloud Storage</strong></li>
                                                    <li>Sign in with your Google account when asked</li>
                                                    <li>Choose a remote folder name, e.g. <code class="bg-slate-100 px-1 rounded">ALCROS Backups</code></li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                        <div class="flex gap-4 p-5">
                                            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-black flex items-center justify-center shrink-0">3</div>
                                            <div class="min-w-0 flex-1 text-sm text-slate-700">
                                                <p class="font-bold text-slate-900 mb-1">Choose what to back up (source folder)</p>
                                                <p class="text-xs text-slate-500 mb-2">Tell Duplicati to upload the ALCROS backup folder — not the whole XAMPP folder.</p>
                                                <ol class="text-xs space-y-1.5 list-decimal pl-4 text-slate-600 mb-3">
                                                    <li>On the <strong>Source data</strong> step, click <strong>Configure source</strong></li>
                                                    <li>Choose <strong>Computer</strong> (local files)</li>
                                                    <li>Browse to this folder and select it:</li>
                                                </ol>
                                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-[11px] text-slate-700 break-all">
                                                    <?= htmlspecialchars(str_replace('\\', '/', $backupDir)) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                        <div class="flex gap-4 p-5">
                                            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-black flex items-center justify-center shrink-0">4</div>
                                            <div class="min-w-0 flex-1 text-sm text-slate-700">
                                                <p class="font-bold text-slate-900 mb-1">Set the schedule</p>
                                                <ol class="text-xs space-y-1.5 list-decimal pl-4 text-slate-600">
                                                    <li>On the <strong>Schedule</strong> step, choose how often to upload</li>
                                                    <li><strong>Recommended:</strong> every <strong>15 minutes</strong> or <strong>1 hour</strong> during office hours</li>
                                                    <li>The office PC must be <strong>on</strong> and connected to the internet when a backup runs</li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                        <div class="flex gap-4 p-5">
                                            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-black flex items-center justify-center shrink-0">5</div>
                                            <div class="min-w-0 flex-1 text-sm text-slate-700">
                                                <p class="font-bold text-slate-900 mb-1">Run ALCROS export before each upload (important)</p>
                                                <p class="text-xs text-slate-500 mb-2">This refreshes civil records and staff data in the backup folder before Duplicati sends files to the cloud.</p>
                                                <ol class="text-xs space-y-1.5 list-decimal pl-4 text-slate-600 mb-3">
                                                    <li>Go to <strong>Options</strong> (or job settings) → find <strong>Run script before</strong></li>
                                                    <li>Paste this path exactly:</li>
                                                </ol>
                                                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 font-mono text-[11px] text-slate-800 break-all">
                                                    <?php if ($backupScriptBat): ?>
                                                    <?= htmlspecialchars(str_replace('\\', '/', $backupScriptBat)) ?>
                                                    <?php else: ?>
                                                    <?= htmlspecialchars(str_replace('\\', '/', __DIR__ . '/scripts/duplicati-pre-backup.bat')) ?>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="text-[11px] text-slate-400 mt-2">Duplicati will run this script automatically — you do not need to click “Create backup bundle now” every time after this is set.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                        <div class="flex gap-4 p-5">
                                            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-black flex items-center justify-center shrink-0">6</div>
                                            <div class="min-w-0 flex-1 text-sm text-slate-700">
                                                <p class="font-bold text-slate-900 mb-1">Turn on encryption</p>
                                                <ol class="text-xs space-y-1.5 list-decimal pl-4 text-slate-600">
                                                    <li>In job options, enable <strong>Encryption</strong></li>
                                                    <li>Create a strong passphrase (20+ characters) and <strong>write it down</strong> in a safe place</li>
                                                    <li>Without this passphrase, cloud backups cannot be restored</li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                        <div class="flex gap-4 p-5">
                                            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-black flex items-center justify-center shrink-0">7</div>
                                            <div class="min-w-0 flex-1 text-sm text-slate-700">
                                                <p class="font-bold text-slate-900 mb-1">Set how long to keep old backups</p>
                                                <ol class="text-xs space-y-1.5 list-decimal pl-4 text-slate-600">
                                                    <li>Under <strong>Retention</strong>, choose e.g. <strong>30 days</strong> or <strong>30 versions</strong></li>
                                                    <li>Save the backup job</li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                        <div class="flex gap-4 p-5">
                                            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-black flex items-center justify-center shrink-0">8</div>
                                            <div class="min-w-0 flex-1 text-sm text-slate-700">
                                                <p class="font-bold text-slate-900 mb-1">Test that it works</p>
                                                <ol class="text-xs space-y-1.5 list-decimal pl-4 text-slate-600">
                                                    <li>In Duplicati, click <strong>Run now</strong> on your ALCROS job</li>
                                                    <li>Wait until status shows <strong>Success</strong></li>
                                                    <li>Open Google Drive (or your cloud bucket) — you should see the backup folder and files</li>
                                                    <li>Check email alerts in Duplicati settings if you want failure notifications</li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-5 mb-4">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Optional — test without Duplicati</h4>
                                    <p class="text-xs text-slate-600 mb-2">Double-click this file in File Explorer (or run in Command Prompt) to create the backup folder manually:</p>
                                    <?php if ($backupRunBat): ?>
                                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-[11px] text-slate-700 break-all">
                                        <?= htmlspecialchars(str_replace('\\', '/', $backupRunBat)) ?>
                                    </div>
                                    <?php else: ?>
                                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-[11px] text-slate-700 break-all">
                                        <?= htmlspecialchars(str_replace('\\', '/', __DIR__ . '/scripts/backup-run.bat')) ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (is_array($backupManifest)): ?>
                                    <p class="text-[11px] text-slate-400 mt-3">Latest bundle: <?= htmlspecialchars((string) ($backupManifest['generated_at'] ?? '')) ?> · <?= htmlspecialchars(alcrosFormatBytes((int) ($backupManifest['sql_bytes'] ?? 0))) ?> SQL · <?= (int) ($backupManifest['files_copied'] ?? 0) ?> files copied</p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Maintenance -->
                            <div class="p-5 sm:p-6 <?= $adminToolsSection !== 'maintenance' ? 'hidden' : '' ?>">
                                <div id="system-errors" class="rounded-xl border border-red-200 bg-red-50/60 p-5 mb-8">
                                    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                                        <div>
                                            <h3 class="text-sm font-bold text-red-900 flex items-center gap-2">
                                                <i data-lucide="alert-triangle" class="w-4 h-4"></i> System errors
                                            </h3>
                                            <p class="text-xs text-red-800/80 mt-1">Issues detected across email, SMS, and scheduled reminders. These also appear in the notification bell.</p>
                                        </div>
                                        <?php if (!empty($activeSystemErrors)): ?>
                                        <span class="inline-flex items-center min-w-[1.5rem] h-6 px-2 rounded-full bg-red-600 text-white text-[10px] font-black"><?= count($activeSystemErrors) ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (empty($activeSystemErrors)): ?>
                                    <div class="rounded-lg border border-emerald-200 bg-emerald-50/80 px-4 py-3 flex items-center gap-3">
                                        <i data-lucide="circle-check" class="w-5 h-5 text-emerald-600 shrink-0"></i>
                                        <p class="text-sm font-semibold text-emerald-900">No active system errors detected.</p>
                                    </div>
                                    <?php else: ?>
                                    <div class="space-y-3">
                                        <?php foreach ($activeSystemErrors as $sysError): ?>
                                        <div class="rounded-xl border border-red-100 bg-white/90 p-4 flex flex-col sm:flex-row sm:items-start gap-4">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                                    <p class="text-sm font-bold text-red-950"><?= htmlspecialchars($sysError['title']) ?></p>
                                                    <span class="text-[10px] font-bold uppercase tracking-wide text-red-700/70 bg-red-100 px-2 py-0.5 rounded"><?= htmlspecialchars($sysError['category']) ?></span>
                                                </div>
                                                <p class="text-xs text-red-900/85 leading-relaxed"><?= htmlspecialchars($sysError['reason']) ?></p>
                                                <p class="text-[10px] text-red-800/60 mt-2">Detected <?= htmlspecialchars(formatDateDisplay($sysError['updated_at'] ?: $sysError['created_at'])) ?></p>
                                            </div>
                                            <form method="POST" class="shrink-0">
                                                <?= authFormField() ?>
                                                <input type="hidden" name="settings_action" value="fix_system_error">
                                                <input type="hidden" name="error_key" value="<?= htmlspecialchars($sysError['error_key']) ?>">
                                                <input type="hidden" name="active_tab" value="admin-tools">
                                                <input type="hidden" name="admin_sub" value="maintenance">
                                                <button type="submit" class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold whitespace-nowrap">
                                                    <i data-lucide="wrench" class="w-3.5 h-3.5"></i> Fix error
                                                </button>
                                            </form>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <h3 class="text-sm font-bold text-slate-900 mb-1">Exports & utilities</h3>
                                <p class="text-xs text-slate-500 mb-4">Download data or open setup tools. These do not delete live records.</p>
                                <div class="flex flex-wrap gap-3 mb-8">
                                    <a href="<?= htmlspecialchars(buildAuthUrl('system_settings.php', ['action' => 'export_logs'])) ?>" class="inline-flex items-center gap-2 border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-4 py-2.5 rounded-xl text-xs font-bold">
                                        <i data-lucide="download" class="w-4 h-4"></i> Export activity logs (CSV)
                                    </a>
                                    <a href="install.php" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 border border-amber-200 bg-amber-50 hover:bg-amber-100 text-amber-800 px-4 py-2.5 rounded-xl text-xs font-bold">
                                        <i data-lucide="database" class="w-4 h-4"></i> Database installer
                                    </a>
                                </div>
                                <div class="rounded-xl border border-red-100 bg-red-50/50 p-5">
                                    <h4 class="text-sm font-bold text-red-900 flex items-center gap-2">
                                        <i data-lucide="alert-triangle" class="w-4 h-4"></i> Danger zone
                                    </h4>
                                    <p class="text-xs text-red-800/80 mt-1">Destructive maintenance actions. None of these can be undone.</p>

                                    <div class="mt-4 pt-4 border-t border-red-100">
                                        <p class="text-xs font-bold text-red-900 mb-1">Clear old activity logs</p>
                                        <p class="text-xs text-red-800/80 mb-3">Permanently removes old activity log rows only.</p>
                                        <form method="POST" class="flex flex-wrap items-end gap-3">
                                            <?= authFormField() ?>
                                            <input type="hidden" name="settings_action" value="clear_old_logs">
                                            <input type="hidden" name="active_tab" value="admin-tools">
                                            <input type="hidden" name="admin_sub" value="maintenance">
                                            <div>
                                                <label class="block text-[10px] font-bold text-red-900/70 uppercase mb-1">Remove logs older than</label>
                                                <select name="log_retention_days" class="border border-red-200 rounded-lg px-3 py-2 text-sm bg-white">
                                                    <option value="30">30 days</option>
                                                    <option value="60">60 days</option>
                                                    <option value="90">90 days</option>
                                                    <option value="180">180 days</option>
                                                </select>
                                            </div>
                                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-xs font-bold">Clear old logs</button>
                                        </form>
                                    </div>

                                    <div class="mt-4 pt-4 border-t border-red-100">
                                        <p class="text-xs font-bold text-red-900 mb-1">Clear operational data</p>
                                        <p class="text-xs text-red-800/80 mb-3">Permanently deletes selected live records. Staff accounts, office settings, and activity logs are kept.</p>
                                        <form method="POST" id="clearOperationalDataForm" class="space-y-4">
                                            <?= authFormField() ?>
                                            <input type="hidden" name="settings_action" value="clear_data">
                                            <input type="hidden" name="active_tab" value="admin-tools">
                                            <input type="hidden" name="admin_sub" value="maintenance">
                                            <fieldset class="space-y-2">
                                                <legend class="block text-[10px] font-bold text-red-900/70 uppercase mb-1">Data to clear</legend>
                                                <?php
                                                $clearDataOptions = [
                                                    'appointments'      => ['label' => 'Appointment data', 'stat' => 'appointments'],
                                                    'document_requests' => ['label' => 'Request document data', 'stat' => 'document_requests'],
                                                    'civil_records'     => ['label' => 'Civil records', 'stat' => 'civil_records'],
                                                    'queue_tickets'     => ['label' => 'Queue tickets', 'stat' => 'queue_tickets', 'hint' => 'Includes walk-in, appointment, and document claim tickets plus speaker announcements.'],
                                                ];
                                                foreach ($clearDataOptions as $typeKey => $option):
                                                    $count = (int) ($systemStats[$option['stat']]['count'] ?? 0);
                                                ?>
                                                <label class="flex items-start gap-3 rounded-lg border border-red-100 bg-white/80 px-3 py-2.5 cursor-pointer hover:bg-white">
                                                    <input type="checkbox" name="clear_data_types[]" value="<?= htmlspecialchars($typeKey) ?>" class="mt-0.5 rounded text-red-600">
                                                    <span class="min-w-0">
                                                        <span class="block text-sm font-semibold text-red-950"><?= htmlspecialchars($option['label']) ?></span>
                                                        <span class="block text-[11px] text-red-800/70"><?= number_format($count) ?> record<?= $count === 1 ? '' : 's' ?> in database</span>
                                                        <?php if (!empty($option['hint'])): ?>
                                                        <span class="block text-[11px] text-red-800/60 mt-0.5"><?= htmlspecialchars($option['hint']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </label>
                                                <?php endforeach; ?>
                                            </fieldset>
                                            <p id="clearOperationalDataError" class="hidden text-xs font-semibold text-red-700">Select at least one data type to clear.</p>
                                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-xs font-bold">Clear selected data</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>

    <!-- Edit Staff Modal -->
    <div id="editStaffModal" class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-black text-slate-900 mb-4">Edit Staff Account</h3>
            <form method="POST" class="space-y-4">
                <?= authFormField() ?>
                <input type="hidden" name="settings_action" value="update_staff">
                <input type="hidden" name="active_tab" value="account-management">
                <input type="hidden" name="target_staff_id" id="editStaffId">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">First Name</label>
                        <input type="text" name="edit_staff_first_name" id="editStaffFirstName" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Middle Name</label>
                        <input type="text" name="edit_staff_middle_name" id="editStaffMiddleName" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Last Name</label>
                        <input type="text" name="edit_staff_last_name" id="editStaffLastName" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Recovery Gmail</label>
                    <input type="email" name="edit_staff_email" id="editStaffEmail" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                    <p class="text-[10px] text-slate-400 mt-1">Gmail with Google 2-Step Verification required.</p>
                </div>
                <div id="editStaff2svField" class="recovery-2sv-field hidden">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="recovery_gmail_2sv_confirmed" value="1" id="editStaff2svCheckbox" class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500 recovery-2sv-checkbox">
                        <span class="text-xs text-slate-600 leading-relaxed">I confirm this Gmail account already has Google 2-Step Verification turned on. <a href="https://myaccount.google.com/signinoptions/two-step-verification" target="_blank" rel="noopener noreferrer" class="text-blue-600 font-semibold hover:underline">Enable 2-Step Verification</a></span>
                    </label>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Role</label>
                    <select name="edit_staff_role" id="editStaffRole" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                        <option value="Staff">Staff</option>
                        <option value="Administrator">Administrator</option>
                    </select>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white rounded-xl py-3 text-sm font-bold">Save Changes</button>
                    <button type="button" class="flex-1 border border-slate-200 rounded-xl py-3 text-sm font-bold close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetStaffModal" class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-black text-slate-900 mb-1">Reset Staff Password</h3>
            <p class="text-xs text-slate-500 mb-4">Set a new password for <strong id="resetStaffLabel"></strong>.</p>
            <form method="POST" class="space-y-4">
                <?= authFormField() ?>
                <input type="hidden" name="settings_action" value="reset_staff_password">
                <input type="hidden" name="active_tab" value="account-management">
                <input type="hidden" name="target_staff_id" id="resetStaffId">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">New Password</label>
                    <input type="password" name="reset_password" required minlength="<?= passwordMinLength() ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-amber-600 text-white rounded-xl py-3 text-sm font-bold">Reset Password</button>
                    <button type="button" class="flex-1 border border-slate-200 rounded-xl py-3 text-sm font-bold close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <?= actionResultScript($flash) ?>
    <?= scriptTag('core/password-toggle.js') ?>
    <?= scriptTag('admin/system-settings.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
