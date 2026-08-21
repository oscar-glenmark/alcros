<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
requireStaffLogin();
requirePageAccess('system_settings.php');

$activePage = 'system_settings.php';
$pdo = getDB();
$isAdmin = isAdmin();
$currentStaffId = staffId();

$adminSettingKeys = [
    'site_name', 'office_name', 'office_address', 'office_phone', 'office_email',
    'office_hours', 'office_head', 'overview_text', 'portal_title', 'portal_description',
    'queue_window', 'maintenance_mode', 'allow_public_requests', 'notification_email',
    'max_daily_appointments', 'privacy_policy_url', 'kiosk_welcome_message',
    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
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
];

function currentStaffRow(PDO $pdo, string $staffId): ?array
{
    $stmt = $pdo->prepare('SELECT staff_id, name, role, created_at FROM staff WHERE staff_id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function adminCount(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM staff WHERE role IN ('Administrator', 'Registrar')")->fetchColumn();
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

    try {
        if ($action === 'update_profile') {
            $name = trim($_POST['profile_name'] ?? '');
            if ($name === '') {
                throw new InvalidArgumentException('Display name cannot be empty.');
            }
            $pdo->prepare('UPDATE staff SET name = ? WHERE staff_id = ?')->execute([$name, $currentStaffId]);
            logActivity($currentStaffId, 'Profile Updated', 'Updated display name');
            settingsFlashSet('success', 'Your profile has been updated.');
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
            if (strlen($newPass) < 6) {
                throw new InvalidArgumentException('New password must be at least 6 characters.');
            }
            if ($newPass !== $confirm) {
                throw new InvalidArgumentException('New passwords do not match.');
            }
            $pdo->prepare('UPDATE staff SET password_hash = ? WHERE staff_id = ?')->execute([password_hash($newPass, PASSWORD_DEFAULT), $currentStaffId]);
            logActivity($currentStaffId, 'Password Changed', 'Updated account password');
            settingsFlashSet('success', 'Password changed successfully.');
        } elseif ($action === 'save_settings' && $isAdmin) {
            foreach ($adminSettingKeys as $key) {
                if (in_array($key, ['maintenance_mode', 'allow_public_requests'], true)) {
                    setSetting($key, isset($_POST[$key]) ? '1' : '0');
                } elseif ($key === 'smtp_pass') {
                    $smtpPass = (string) ($_POST['smtp_pass'] ?? '');
                    if ($smtpPass !== '') {
                        setSetting($key, $smtpPass);
                    }
                } elseif (isset($_POST[$key])) {
                    setSetting($key, trim($_POST[$key]));
                }
            }
            logActivity($currentStaffId, 'Settings Updated', 'System configuration saved');
            settingsFlashSet('success', 'System settings saved successfully.');
        } elseif ($action === 'add_staff' && $isAdmin) {
            $name = trim($_POST['staff_name'] ?? '');
            $newStaffId = strtoupper(trim($_POST['staff_id_new'] ?? ''));
            $password = $_POST['staff_password'] ?? '';
            $role = $_POST['staff_role'] ?? 'Staff';
            $validRoles = ['Staff', 'Registrar', 'Administrator'];
            if (!in_array($role, $validRoles, true)) {
                $role = 'Staff';
            }
            if ($name === '' || $newStaffId === '' || $password === '') {
                throw new InvalidArgumentException('Please fill in name, staff ID, and password.');
            }
            if (strlen($password) < 6) {
                throw new InvalidArgumentException('Password must be at least 6 characters.');
            }
            if (!preg_match('/^[A-Z0-9\-]+$/', $newStaffId)) {
                throw new InvalidArgumentException('Staff ID may only contain letters, numbers, and hyphens.');
            }
            $exists = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE staff_id = ?');
            $exists->execute([$newStaffId]);
            if ((int) $exists->fetchColumn() > 0) {
                throw new InvalidArgumentException('That Staff ID is already in use.');
            }
            $pdo->prepare('INSERT INTO staff (staff_id, name, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$newStaffId, $name, password_hash($password, PASSWORD_DEFAULT), $role]);
            logActivity($currentStaffId, 'Staff Added', "Created staff account $newStaffId ($name)");
            settingsFlashSet('success', "Staff member $name ($newStaffId) added successfully.");
        } elseif ($action === 'update_staff' && $isAdmin) {
            $targetId = strtoupper(trim($_POST['target_staff_id'] ?? ''));
            $name = trim($_POST['edit_staff_name'] ?? '');
            $role = $_POST['edit_staff_role'] ?? 'Staff';
            $validRoles = ['Staff', 'Registrar', 'Administrator'];
            if (!in_array($role, $validRoles, true)) {
                $role = 'Staff';
            }
            if ($targetId === '' || $name === '') {
                throw new InvalidArgumentException('Staff ID and name are required.');
            }
            if ($targetId === $currentStaffId && !in_array($role, ['Administrator', 'Registrar'], true) && adminCount($pdo) <= 1) {
                throw new InvalidArgumentException('Cannot demote the last administrator account.');
            }
            $pdo->prepare('UPDATE staff SET name = ?, role = ? WHERE staff_id = ?')->execute([$name, $role, $targetId]);
            logActivity($currentStaffId, 'Staff Updated', "Updated account $targetId");
            settingsFlashSet('success', "Staff account $targetId updated.");
        } elseif ($action === 'reset_staff_password' && $isAdmin) {
            $targetId = strtoupper(trim($_POST['target_staff_id'] ?? ''));
            $newPass = $_POST['reset_password'] ?? '';
            if ($targetId === '' || strlen($newPass) < 6) {
                throw new InvalidArgumentException('Staff ID and a password of at least 6 characters are required.');
            }
            $pdo->prepare('UPDATE staff SET password_hash = ? WHERE staff_id = ?')->execute([password_hash($newPass, PASSWORD_DEFAULT), $targetId]);
            logActivity($currentStaffId, 'Password Reset', "Reset password for $targetId");
            settingsFlashSet('success', "Password reset for $targetId.");
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
            if (in_array($targetRole, ['Administrator', 'Registrar'], true) && adminCount($pdo) <= 1) {
                throw new InvalidArgumentException('Cannot remove the last administrator account.');
            }
            $pdo->prepare('DELETE FROM staff WHERE staff_id = ?')->execute([$targetId]);
            logActivity($currentStaffId, 'Staff Removed', "Removed staff account $targetId");
            settingsFlashSet('success', "Staff account $targetId removed.");
        } elseif ($action === 'clear_old_logs' && $isAdmin) {
            $days = max(7, (int) ($_POST['log_retention_days'] ?? 30));
            $stmt = $pdo->prepare('DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
            $stmt->execute([$days]);
            $deleted = $stmt->rowCount();
            logActivity($currentStaffId, 'Logs Cleared', "Removed $deleted activity log entries older than $days days");
            settingsFlashSet('success', "Cleared $deleted old activity log entries.");
        }
    } catch (InvalidArgumentException $e) {
        settingsFlashSet('error', $e->getMessage());
    } catch (PDOException $e) {
        settingsFlashSet('error', 'Could not complete the action. Please try again.');
    }

    $tab = $_POST['active_tab'] ?? 'my-account';
    redirectWithAuth('system_settings.php', array_filter(['tab' => $tab !== 'my-account' ? $tab : null]));
}

$settings = [];
foreach ($adminSettingKeys as $key) {
    $settings[$key] = getSetting($key, $defaults[$key] ?? '');
}

$currentStaff = currentStaffRow($pdo, $currentStaffId);
$staffMembers = $pdo->query('SELECT staff_id, name, role, created_at FROM staff ORDER BY created_at ASC')->fetchAll();
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

$flash = settingsFlashGet();
$staffInitial = strtoupper(substr($currentStaff['name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isAdmin ? 'System Settings' : 'My Settings' ?> - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .tab-btn.active { background-color: #2563eb; color: #ffffff; }
        .tab-btn.active i { color: #ffffff; }
        .toggle-dot { transition: transform 0.2s; }
    </style>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="admin-main flex-1 flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-8 max-w-7xl w-full mx-auto">
            <div class="mb-6">
                <a href="dashboard.php" class="text-xs text-gray-500 hover:text-blue-600 inline-flex items-center gap-1 mb-2 font-medium">
                    <i data-lucide="chevron-left" class="w-4 h-4"></i> Back to Dashboard
                </a>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight"><?= $isAdmin ? 'System Settings' : 'My Settings' ?></h1>
                <p class="text-gray-500 text-sm mt-1">Manage your account, security<?= $isAdmin ? ', staff accounts, and system-wide configurations' : ', and credentials' ?>.</p>
            </div>

            <?php if ($flash): ?>
            <div id="alert-banner" class="mb-6 p-4 <?= $flash[0] === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' ?> border text-sm rounded-xl flex items-center gap-2">
                <i data-lucide="<?= $flash[0] === 'success' ? 'check-circle' : 'alert-circle' ?>" class="w-5 h-5 shrink-0"></i>
                <span><?= htmlspecialchars($flash[1]) ?></span>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                <aside class="lg:col-span-3 bg-white border border-slate-100 rounded-2xl p-3 shadow-sm space-y-1 sticky top-6 self-start z-10">
                    <?php
                    $tabs = [
                        'my-account'           => ['label' => 'My Account', 'icon' => 'user'],
                        'security'             => ['label' => 'Security', 'icon' => 'shield'],
                    ];
                    if ($isAdmin) {
                        $tabs['account-management']   = ['label' => 'Account Management', 'icon' => 'users'];
                        $tabs['system-configuration'] = ['label' => 'System Configuration', 'icon' => 'settings'];
                        $tabs['admin-tools']          = ['label' => 'Admin Tools', 'icon' => 'wrench'];
                    }
                    foreach ($tabs as $id => $tab):
                    ?>
                    <button type="button" onclick="switchTab('<?= $id ?>')" id="btn-<?= $id ?>"
                        class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors <?= $activeTab === $id ? 'active' : '' ?>">
                        <i data-lucide="<?= $tab['icon'] ?>" class="w-4 h-4 text-slate-500 shrink-0"></i> <?= $tab['label'] ?>
                    </button>
                    <?php endforeach; ?>
                </aside>

                <section class="lg:col-span-9 space-y-6">
                    <!-- MY ACCOUNT -->
                    <div id="tab-my-account" class="tab-content bg-white border border-slate-100 rounded-2xl p-8 shadow-sm <?= $activeTab !== 'my-account' ? 'hidden' : '' ?>">
                        <div class="flex items-center gap-5 mb-8">
                            <div class="w-20 h-20 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center text-3xl font-black shrink-0"><?= htmlspecialchars($staffInitial) ?></div>
                            <div>
                                <h2 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($currentStaff['name'] ?? staffName()) ?></h2>
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
                                <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Office Email</p>
                                <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($settings['office_email']) ?></p>
                            </div>
                        </div>

                        <form method="POST" class="space-y-4 border-t border-slate-100 pt-6">
                            <input type="hidden" name="settings_action" value="update_profile">
                            <input type="hidden" name="active_tab" value="my-account">
                            <h3 class="text-sm font-bold text-slate-900">Update Display Name</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Full Name</label>
                                    <input type="text" name="profile_name" required value="<?= htmlspecialchars($currentStaff['name'] ?? '') ?>"
                                        class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Staff ID</label>
                                    <input type="text" readonly value="<?= htmlspecialchars($currentStaffId) ?>"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-500 font-mono">
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
                    <div id="tab-security" class="tab-content bg-white border border-slate-100 rounded-2xl p-8 shadow-sm <?= $activeTab !== 'security' ? 'hidden' : '' ?>">
                        <h2 class="text-lg font-bold text-slate-900 mb-1">Security & Credentials</h2>
                        <p class="text-xs text-slate-500 mb-6">Change your password and review account security policies.</p>

                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-sm text-slate-600 mb-8 space-y-1">
                            <p><strong>Password policy:</strong> Minimum 6 characters. Use a unique password not shared with other systems.</p>
                            <p><strong>Staff IDs:</strong> Alphanumeric characters and hyphens only (e.g. ALORAN-001).</p>
                            <p><strong>Session:</strong> Login tokens expire after 7 days of inactivity.</p>
                        </div>

                        <form method="POST" class="space-y-4 max-w-lg">
                            <input type="hidden" name="settings_action" value="change_password">
                            <input type="hidden" name="active_tab" value="security">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Current Password</label>
                                <input type="password" name="current_password" required autocomplete="current-password"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">New Password</label>
                                <input type="password" name="new_password" required minlength="6" autocomplete="new-password"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Confirm New Password</label>
                                <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white px-6 py-2.5 rounded-xl text-sm font-semibold inline-flex items-center gap-2">
                                <i data-lucide="key" class="w-4 h-4"></i> Update Password
                            </button>
                        </form>
                    </div>

                    <?php if ($isAdmin): ?>
                    <!-- ACCOUNT MANAGEMENT -->
                    <div id="tab-account-management" class="tab-content bg-white border border-slate-100 rounded-2xl p-8 shadow-sm <?= $activeTab !== 'account-management' ? 'hidden' : '' ?>">
                        <div class="flex justify-between items-start mb-6">
                            <div>
                                <h2 class="text-lg font-bold text-slate-900 mb-1">Staff Members</h2>
                                <p class="text-xs text-slate-500">Manage who can access the staff portal. Staff role sees Dashboard, Requests, Appointments, Records, Queue, and My Settings.</p>
                            </div>
                            <span class="text-[10px] font-bold uppercase bg-slate-100 text-slate-600 px-3 py-1 rounded-full"><?= count($staffMembers) ?> accounts</span>
                        </div>

                        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8 pb-8 border-b border-slate-100">
                            <input type="hidden" name="settings_action" value="add_staff">
                            <input type="hidden" name="active_tab" value="account-management">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Full Name</label>
                                <input type="text" name="staff_name" required placeholder="Juan Dela Cruz" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Staff ID</label>
                                <input type="text" name="staff_id_new" required placeholder="ALORAN-002" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm uppercase focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Role</label>
                                <select name="staff_role" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                                    <option value="Staff">Staff</option>
                                    <option value="Registrar">Registrar</option>
                                    <option value="Administrator">Administrator</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Password</label>
                                <input type="password" name="staff_password" required minlength="6" placeholder="Minimum 6 characters" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <div class="sm:col-span-2">
                                <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white px-6 py-2.5 rounded-xl text-sm font-semibold">Add Staff Member</button>
                            </div>
                        </form>

                        <div class="overflow-x-auto rounded-xl border border-slate-200">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-400 border-b border-slate-200">
                                    <tr>
                                        <th class="px-4 py-3">Staff ID</th>
                                        <th class="px-4 py-3">Name</th>
                                        <th class="px-4 py-3">Role</th>
                                        <th class="px-4 py-3">Added</th>
                                        <th class="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($staffMembers as $member): ?>
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="px-4 py-3 font-mono text-xs font-bold text-blue-600"><?= htmlspecialchars($member['staff_id']) ?></td>
                                        <td class="px-4 py-3 font-semibold text-slate-800">
                                    <?= htmlspecialchars($member['name']) ?>
                                    <?php if ($member['staff_id'] === $currentStaffId): ?><span class="text-[9px] text-blue-500">(You)</span><?php endif; ?>
                                </td>
                                        <td class="px-4 py-3">
                                            <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded <?= $member['role'] === 'Staff' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' ?>"><?= htmlspecialchars($member['role']) ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-400 text-xs"><?= formatDateDisplay($member['created_at']) ?></td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="inline-flex gap-2">
                                                <button type="button" class="edit-staff-btn text-[10px] font-bold text-blue-600 hover:underline"
                                                    data-staff-id="<?= htmlspecialchars($member['staff_id']) ?>"
                                                    data-staff-name="<?= htmlspecialchars($member['name']) ?>"
                                                    data-staff-role="<?= htmlspecialchars($member['role']) ?>">Edit</button>
                                                <button type="button" class="reset-staff-btn text-[10px] font-bold text-amber-600 hover:underline"
                                                    data-staff-id="<?= htmlspecialchars($member['staff_id']) ?>">Reset PW</button>
                                                <?php if ($member['staff_id'] !== $currentStaffId): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Remove this staff account permanently?');">
                                                    <input type="hidden" name="settings_action" value="remove_staff">
                                                    <input type="hidden" name="active_tab" value="account-management">
                                                    <input type="hidden" name="target_staff_id" value="<?= htmlspecialchars($member['staff_id']) ?>">
                                                    <button type="submit" class="text-[10px] font-bold text-red-500 hover:underline">Remove</button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- SYSTEM CONFIGURATION -->
                    <div id="tab-system-configuration" class="tab-content bg-white border border-slate-100 rounded-2xl p-8 shadow-sm <?= $activeTab !== 'system-configuration' ? 'hidden' : '' ?>">
                        <form method="POST" class="space-y-8">
                            <input type="hidden" name="settings_action" value="save_settings">
                            <input type="hidden" name="active_tab" value="system-configuration">

                            <div>
                                <h2 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Office Information</h2>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Site Name</label><input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Office Name</label><input type="text" name="office_name" value="<?= htmlspecialchars($settings['office_name']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div class="sm:col-span-2"><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Address</label><input type="text" name="office_address" value="<?= htmlspecialchars($settings['office_address']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Phone</label><input type="text" name="office_phone" value="<?= htmlspecialchars($settings['office_phone']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Email</label><input type="email" name="office_email" value="<?= htmlspecialchars($settings['office_email']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Office Hours</label><input type="text" name="office_hours" value="<?= htmlspecialchars($settings['office_hours']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Office Head / Signatory</label><input type="text" name="office_head" value="<?= htmlspecialchars($settings['office_head']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                </div>
                            </div>

                            <div>
                                <h2 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Public Portal Content</h2>
                                <div class="space-y-4">
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
                            </div>

                            <div>
                                <h2 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Operations & Queue</h2>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Queue Window Number</label><input type="number" name="queue_window" value="<?= htmlspecialchars($settings['queue_window']) ?>" min="1" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Max Daily Appointments</label><input type="number" name="max_daily_appointments" value="<?= htmlspecialchars($settings['max_daily_appointments']) ?>" min="1" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div class="sm:col-span-2"><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Notification Email</label><input type="email" name="notification_email" value="<?= htmlspecialchars($settings['notification_email']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><p class="text-[10px] text-slate-400 mt-1">Fallback From address if Gmail SMTP is not used.</p></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Gmail SMTP Host</label><input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?: 'smtp.gmail.com') ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Gmail SMTP Port</label><input type="number" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?: '587') ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><p class="text-[10px] text-slate-400 mt-1">587 for STARTTLS, or 465 for SSL.</p></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Gmail Address (SMTP user)</label><input type="email" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user']) ?>" placeholder="youroffice@gmail.com" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"></div>
                                    <div><label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Gmail App Password</label><input type="password" name="smtp_pass" value="" autocomplete="new-password" placeholder="<?= $settings['smtp_pass'] !== '' ? 'Leave blank to keep the saved password' : 'App password from Google Account' ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm"><p class="text-[10px] text-slate-400 mt-1">Create an App Password in Google Account → Security. Required so citizens receive request and status emails.</p></div>
                                </div>
                            </div>

                            <div>
                                <h2 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Portal Access Controls</h2>
                                <div class="space-y-4">
                                    <label class="flex items-center justify-between p-4 bg-slate-50 rounded-xl border border-slate-100 cursor-pointer">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-800">Maintenance Mode</p>
                                            <p class="text-xs text-slate-500">Show maintenance notice on the citizen portal when enabled.</p>
                                        </div>
                                        <input type="checkbox" name="maintenance_mode" value="1" <?= $settings['maintenance_mode'] === '1' ? 'checked' : '' ?> class="rounded text-blue-600 w-5 h-5">
                                    </label>
                                    <label class="flex items-center justify-between p-4 bg-slate-50 rounded-xl border border-slate-100 cursor-pointer">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-800">Allow Public Requests</p>
                                            <p class="text-xs text-slate-500">Citizens can submit new document requests online.</p>
                                        </div>
                                        <input type="checkbox" name="allow_public_requests" value="1" <?= $settings['allow_public_requests'] === '1' ? 'checked' : '' ?> class="rounded text-blue-600 w-5 h-5">
                                    </label>
                                </div>
                            </div>

                            <div class="pt-4 flex justify-end">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl text-sm font-semibold inline-flex items-center gap-2">
                                    <i data-lucide="save" class="w-4 h-4"></i> Save Settings
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ADMIN TOOLS -->
                    <div id="tab-admin-tools" class="tab-content space-y-6 <?= $activeTab !== 'admin-tools' ? 'hidden' : '' ?>">
                        <div class="bg-white border border-slate-100 rounded-2xl p-8 shadow-sm">
                            <h2 class="text-lg font-bold text-slate-900 mb-1">System Overview</h2>
                            <p class="text-xs text-slate-500 mb-6">Database statistics and environment information.</p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
                                <?php foreach ($systemStats as $stat): ?>
                                <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase"><?= htmlspecialchars($stat['label']) ?></p>
                                    <p class="text-2xl font-black text-slate-900"><?= number_format($stat['count']) ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs text-slate-600 bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <div><span class="text-slate-400 font-bold uppercase text-[10px]">Database</span><p class="font-semibold mt-1"><?= htmlspecialchars(DB_NAME) ?></p></div>
                                <div><span class="text-slate-400 font-bold uppercase text-[10px]">Host</span><p class="font-semibold mt-1"><?= htmlspecialchars(DB_HOST) ?></p></div>
                                <div><span class="text-slate-400 font-bold uppercase text-[10px]">PHP Version</span><p class="font-semibold mt-1"><?= htmlspecialchars(PHP_VERSION) ?></p></div>
                            </div>
                        </div>

                        <div class="bg-white border border-slate-100 rounded-2xl p-8 shadow-sm">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-base font-bold text-slate-900">Recent Activity</h3>
                                <a href="Activity-log.php" class="text-[10px] font-bold text-blue-600 hover:underline uppercase">View Full Log</a>
                            </div>
                            <?php if (empty($recentLogs)): ?>
                            <p class="text-sm text-slate-400">No activity logged yet.</p>
                            <?php else: ?>
                            <div class="divide-y divide-slate-100">
                                <?php foreach ($recentLogs as $log): ?>
                                <div class="py-3 flex justify-between gap-4 text-sm">
                                    <div>
                                        <p class="font-semibold text-slate-800"><?= htmlspecialchars($log['action']) ?></p>
                                        <p class="text-xs text-slate-400"><?= htmlspecialchars($log['staff_id'] ?? 'System') ?> — <?= htmlspecialchars($log['details']) ?></p>
                                    </div>
                                    <span class="text-[10px] text-slate-400 whitespace-nowrap"><?= formatDateDisplay($log['created_at']) ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="bg-white border border-slate-100 rounded-2xl p-8 shadow-sm">
                            <h3 class="text-base font-bold text-slate-900 mb-1">Maintenance Actions</h3>
                            <p class="text-xs text-slate-500 mb-6">Export logs or clean up old data. Use with caution.</p>
                            <div class="flex flex-wrap gap-3">
                                <a href="<?= htmlspecialchars(buildAuthUrl('system_settings.php', ['action' => 'export_logs'])) ?>" class="border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-4 py-2.5 rounded-xl text-xs font-bold inline-flex items-center gap-2">
                                    <i data-lucide="download" class="w-4 h-4"></i> Export Activity Logs
                                </a>
                                <a href="install.php" target="_blank" class="border border-amber-200 bg-amber-50 hover:bg-amber-100 text-amber-800 px-4 py-2.5 rounded-xl text-xs font-bold inline-flex items-center gap-2">
                                    <i data-lucide="database" class="w-4 h-4"></i> Database Installer
                                </a>
                            </div>
                            <form method="POST" class="mt-6 pt-6 border-t border-slate-100 flex flex-wrap items-end gap-4" onsubmit="return confirm('Delete old activity logs permanently?');">
                                <input type="hidden" name="settings_action" value="clear_old_logs">
                                <input type="hidden" name="active_tab" value="admin-tools">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Clear Logs Older Than</label>
                                    <select name="log_retention_days" class="border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                                        <option value="30">30 days</option>
                                        <option value="60">60 days</option>
                                        <option value="90">90 days</option>
                                        <option value="180">180 days</option>
                                    </select>
                                </div>
                                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold">Clear Old Logs</button>
                            </form>
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
                <input type="hidden" name="settings_action" value="update_staff">
                <input type="hidden" name="active_tab" value="account-management">
                <input type="hidden" name="target_staff_id" id="editStaffId">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Full Name</label>
                    <input type="text" name="edit_staff_name" id="editStaffName" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Role</label>
                    <select name="edit_staff_role" id="editStaffRole" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                        <option value="Staff">Staff</option>
                        <option value="Registrar">Registrar</option>
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
                <input type="hidden" name="settings_action" value="reset_staff_password">
                <input type="hidden" name="active_tab" value="account-management">
                <input type="hidden" name="target_staff_id" id="resetStaffId">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">New Password</label>
                    <input type="password" name="reset_password" required minlength="6" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm">
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-amber-600 text-white rounded-xl py-3 text-sm font-bold">Reset Password</button>
                    <button type="button" class="flex-1 border border-slate-200 rounded-xl py-3 text-sm font-bold close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            const target = document.getElementById('tab-' + tabId);
            const btn = document.getElementById('btn-' + tabId);
            if (target) target.classList.remove('hidden');
            if (btn) btn.classList.add('active');
            const url = new URL(window.location);
            if (tabId === 'my-account') url.searchParams.delete('tab');
            else url.searchParams.set('tab', tabId);
            window.history.replaceState({}, '', url);
        }

        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
            document.getElementById(id).classList.add('flex');
        }

        function closeModals() {
            document.querySelectorAll('#editStaffModal, #resetStaffModal').forEach(el => {
                el.classList.add('hidden');
                el.classList.remove('flex');
            });
        }

        document.querySelectorAll('.edit-staff-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('editStaffId').value = btn.dataset.staffId;
                document.getElementById('editStaffName').value = btn.dataset.staffName;
                document.getElementById('editStaffRole').value = btn.dataset.staffRole;
                openModal('editStaffModal');
            });
        });

        document.querySelectorAll('.reset-staff-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('resetStaffId').value = btn.dataset.staffId;
                document.getElementById('resetStaffLabel').textContent = btn.dataset.staffId;
                openModal('resetStaffModal');
            });
        });

        document.querySelectorAll('.close-modal').forEach(btn => btn.addEventListener('click', closeModals));

        setTimeout(() => {
            const alert = document.getElementById('alert-banner');
            if (alert) {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }
        }, 5000);
    </script>
</body>
</html>
