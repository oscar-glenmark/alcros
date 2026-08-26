<?php
/**
 * ALCROS staff portal login.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/auth.php';

$redirectTarget = isset($_GET['redirect']) ? $_GET['redirect'] : 'dashboard.php';
// Only allow relative, same-site redirect targets
if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.php(\?.*)?$/', $redirectTarget)) {
    $redirectTarget = 'dashboard.php';
}

$error = '';
$submittedStaffId = '';
$resetSuccess = isset($_GET['reset']) && $_GET['reset'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rateLimitOrAbort(rateLimitKey('login', strtoupper(trim($_POST['staff_id'] ?? ''))), 8, 900, 'Too many login attempts. Please wait 15 minutes and try again.');
    $submittedStaffId = strtoupper(trim($_POST['staff_id'] ?? ''));
    $password          = $_POST['password'] ?? '';
    $csrf               = $_POST['csrf_token'] ?? '';

    if (!validateCsrf($csrf)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($submittedStaffId === '' || $password === '') {
        $error = 'Please enter both your Staff ID and password.';
    } else {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare('SELECT staff_id, name, password_hash, role FROM staff WHERE staff_id = ?');
            $stmt->execute([$submittedStaffId]);
            $staff = $stmt->fetch();

            if ($staff && password_verify($password, $staff['password_hash'])) {
                staffSessionLogin($staff, true);
                $token = createStaffAuthToken($staff);
                logActivity($staff['staff_id'], 'Staff Login', 'Logged in to staff portal');
                $joiner = str_contains($redirectTarget, '?') ? '&' : '?';
                header('Location: ' . $redirectTarget . $joiner . 'alcros_auth=' . urlencode($token));
                exit;
            }
        } catch (PDOException $e) {
            $error = dbConnectionHelpMessage();
        }
        if ($error === '') {
            $error = 'Invalid Staff ID or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Portal Login - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="includes/password_toggle.css">
    <link rel="stylesheet" href="includes/back_home.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f8fafc;
        }
        .login-card {
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.04);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-[420px] bg-white rounded-[2rem] overflow-hidden login-card border border-gray-100">
        
        <div class="p-6 sm:p-10 pb-8 text-center">
            <div class="flex justify-center mb-6">
                <?= alcrosFaviconImg(56, 'shadow-lg shadow-blue-200') ?>
            </div>

            <h1 class="text-2xl font-black text-slate-900 mb-1">Staff Portal</h1>
            <p class="text-gray-400 text-[11px] font-medium tracking-tight mb-8">ALCROS Civil Registry Management</p>

            <div class="mb-8">
                <span class="px-6 py-2 border border-blue-100 rounded-lg text-[10px] font-bold text-blue-600 bg-blue-50/30">
                    Staff Portal Login
                </span>
            </div>

            <?php if ($resetSuccess): ?>
            <div class="mb-6 px-4 py-3 rounded-xl bg-green-50 border border-green-100 text-green-700 text-[11px] font-semibold text-left flex items-center gap-2">
                <i data-lucide="check-circle" class="w-3.5 h-3.5 flex-shrink-0"></i>
                <span>Password updated. Sign in with your new password.</span>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="mb-6 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-red-600 text-[11px] font-semibold text-left flex items-center gap-2">
                <i data-lucide="alert-circle" class="w-3.5 h-3.5 flex-shrink-0"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <form class="text-left space-y-5" method="POST" action="login.php?redirect=<?= urlencode($redirectTarget) ?>" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-2 flex items-center gap-2">
                        <i data-lucide="circle-user-round" class="w-3.5 h-3.5 text-gray-400"></i> Staff ID
                    </label>
                    <input 
                        type="text" 
                        name="staff_id"
                        value="<?= htmlspecialchars($submittedStaffId) ?>"
                        class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-xs font-semibold text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                        required
                    >
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-2 flex items-center gap-2">
                        <i data-lucide="lock" class="w-3.5 h-3.5 text-gray-400"></i> Password
                    </label>
                    <div>
                        <input 
                            type="password" 
                            name="password"
                            id="passwordInput"
                            placeholder="••••••••"
                            class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-xs font-semibold text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                            required
                        >
                    </div>
                    <div class="text-right mt-1.5">
                        <a href="forgot_password.php" class="text-[10px] font-bold text-blue-600 hover:underline">Forgot password?</a>
                    </div>
                </div>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3.5 text-xs font-bold flex items-center justify-center gap-2 transition-all shadow-md shadow-blue-100" data-loading-text="Signing in…">
                    <i data-lucide="log-in" class="w-4 h-4"></i> Sign In
                </button>
            </form>
        </div>

        <div class="bg-gray-50/50 py-6 border-t border-gray-50 text-center">
            <a href="index.php" class="back-home back-home--center">
                <i data-lucide="chevron-left" class="back-home__icon w-3 h-3"></i>
                <span>Back to Citizen Portal</span>
            </a>
        </div>
    </div>

    <?= scriptTag('core/loading.js') ?>
    <?= scriptTag('core/password-toggle.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>