<?php
/**
 * Google Drive OAuth for registry backup (personal Gmail / user storage quota).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/google_drive_backup.php';

requireStaffLogin();
requireAdmin();

$action = (string) ($_GET['action'] ?? '');

if ($action === 'start') {
    try {
        if (!alcrosGoogleDriveOAuthClientConfigured()) {
            throw new InvalidArgumentException('Save OAuth Client ID and Client Secret on the Backup page, then try again.');
        }

        $state = bin2hex(random_bytes(16));
        setSetting('registry_backup_gdrive_oauth_state', $state);
        setSetting('registry_backup_gdrive_oauth_state_at', (string) time());

        header('Location: ' . alcrosGoogleDriveBeginOAuth($state));
        exit;
    } catch (Throwable $e) {
        settingsFlashSet('error', $e->getMessage());
        redirectWithAuth('system_settings.php', ['tab' => 'admin-tools', 'admin_sub' => 'backup']);
    }
}

if ($action === 'callback') {
    try {
        $state = (string) ($_GET['state'] ?? '');
        $expected = getSetting('registry_backup_gdrive_oauth_state', '');
        $stateAt = (int) getSetting('registry_backup_gdrive_oauth_state_at', '0');
        setSetting('registry_backup_gdrive_oauth_state', '');
        setSetting('registry_backup_gdrive_oauth_state_at', '0');

        if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
            throw new InvalidArgumentException('Google sign-in expired or was invalid. Please connect again.');
        }
        if ($stateAt > 0 && time() - $stateAt > 900) {
            throw new InvalidArgumentException('Google sign-in took too long. Please connect again.');
        }

        if (!empty($_GET['error'])) {
            throw new InvalidArgumentException('Google sign-in was cancelled or denied.');
        }

        alcrosGoogleDriveExchangeOAuthCode((string) ($_GET['code'] ?? ''));
        setSetting('registry_backup_gdrive_auth_mode', 'oauth');
        settingsFlashSet('success', 'Google Drive connected'
            . (alcrosGoogleDriveOAuthConnectedEmail() !== '' ? ' as ' . alcrosGoogleDriveOAuthConnectedEmail() : '')
            . '. Run a registry backup to upload a zip.');
    } catch (Throwable $e) {
        settingsFlashSet('error', $e->getMessage());
    }

    redirectWithAuth('system_settings.php', ['tab' => 'admin-tools', 'admin_sub' => 'backup']);
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Not found';
