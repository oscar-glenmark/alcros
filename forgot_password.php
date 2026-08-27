<?php
/**
 * Staff / administrator password reset via Gmail OTP.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/auth.php';

if (getAuthenticatedStaff()) {
    header('Location: dashboard.php');
    exit;
}

$step = max(1, min(2, (int) ($_GET['step'] ?? $_POST['step'] ?? 1)));
$error = '';
$success = '';
$staffId = strtoupper(trim((string) ($_SESSION['forgot_staff_id'] ?? $_POST['staff_id'] ?? '')));
$emailHint = (string) ($_SESSION['forgot_email_hint'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePublicPostCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'send_otp') {
        rateLimitOrAbort(rateLimitKey('staff_forgot_otp', $staffId), 5, 900, 'Too many reset attempts. Please wait 15 minutes.');
        $staffId = strtoupper(trim((string) ($_POST['staff_id'] ?? '')));
        if ($staffId === '') {
            $error = 'Please enter your Staff ID.';
            $step = 1;
        } else {
            try {
                $pdo = getDB();
                $result = sendStaffPasswordOtp($pdo, $staffId);
                if ($result['ok']) {
                    $_SESSION['forgot_staff_id'] = $result['staff_id'] ?? $staffId;
                    $_SESSION['forgot_email_hint'] = $result['email_hint'] ?? '';
                    header('Location: forgot_password.php?step=2');
                    exit;
                }
                $error = $result['message'];
                $step = 1;
            } catch (Throwable $e) {
                $error = 'Unable to process your request right now. Please try again.';
                $step = 1;
            }
        }
    } elseif ($action === 'reset_password') {
        rateLimitOrAbort(rateLimitKey('staff_forgot_reset', $staffId), 8, 900, 'Too many reset attempts. Please wait 15 minutes.');
        $staffId = strtoupper(trim((string) ($_POST['staff_id'] ?? $_SESSION['forgot_staff_id'] ?? '')));
        $otp = trim((string) ($_POST['otp'] ?? ''));
        $newPass = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($newPass !== $confirm) {
            $error = 'New passwords do not match.';
            $step = 2;
        } else {
            try {
                $pdo = getDB();
                $result = resetStaffPasswordWithOtp($pdo, $staffId, $otp, $newPass);
                if ($result['ok']) {
                    unset($_SESSION['forgot_staff_id'], $_SESSION['forgot_email_hint']);
                    header('Location: login.php?reset=1');
                    exit;
                }
                $error = $result['message'];
                $step = 2;
            } catch (Throwable $e) {
                $error = 'Unable to reset your password right now. Please try again.';
                $step = 2;
            }
        }
    }
}

if ($step === 2 && $staffId === '') {
    $step = 1;
}
if ($step === 2) {
    $emailHint = $emailHint !== '' ? $emailHint : (string) ($_SESSION['forgot_email_hint'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Staff Password - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <?= publicStylesheet('password-toggle') ?>
    <?= publicStylesheet('back-home') ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .login-card { box-shadow: 0 10px 40px rgba(0, 0, 0, 0.04); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-[420px] bg-white rounded-[2rem] overflow-hidden login-card border border-gray-100">
        <div class="p-6 sm:p-10 pb-8">
            <div class="text-center mb-6">
                <div class="flex justify-center mb-4">
                    <?= alcrosFaviconImg(56, 'shadow-lg shadow-blue-200') ?>
                </div>
                <h1 class="text-2xl font-black text-slate-900 mb-1">Reset Password</h1>
                <p class="text-gray-400 text-[11px] font-medium">Staff &amp; administrator accounts</p>
            </div>

            <?php if ($error): ?>
            <div class="mb-4 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-red-600 text-[11px] font-semibold flex items-start gap-2">
                <i data-lucide="alert-circle" class="w-3.5 h-3.5 flex-shrink-0 mt-0.5"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
            <p class="text-xs text-gray-500 mb-5 leading-relaxed">Enter your Staff ID. We will send a 6-digit verification code to the Gmail registered on your account. That Gmail must have <strong>Google 2-Step Verification</strong> already enabled and confirmed in System Settings.</p>
            <form method="POST" class="space-y-4" autocomplete="off" data-no-confirm>
                <?= publicCsrfField() ?>
                <input type="hidden" name="action" value="send_otp">
                <input type="hidden" name="step" value="1">
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-2">Staff ID</label>
                    <input type="text" name="staff_id" required value="<?= htmlspecialchars($staffId) ?>"
                        class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-xs font-semibold uppercase focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3.5 text-xs font-bold" data-loading-text="Sending code…">
                    Send verification code
                </button>
            </form>
            <?php else: ?>
            <p class="text-xs text-gray-500 mb-5 leading-relaxed">
                Enter the 6-digit code sent<?= $emailHint !== '' ? ' to <strong class="text-slate-700">' . htmlspecialchars($emailHint) . '</strong>' : '' ?> and choose a new password.
            </p>
            <form method="POST" class="space-y-4" autocomplete="off" data-no-confirm>
                <?= publicCsrfField() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="staff_id" value="<?= htmlspecialchars($staffId) ?>">
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-2">Verification code</label>
                    <input type="text" name="otp" required maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" placeholder="000000"
                        class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-center text-lg font-black tracking-[0.35em] focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-2">New password</label>
                    <input type="password" name="new_password" id="passwordInput" required minlength="10" autocomplete="new-password"
                        class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    <p class="text-[10px] text-gray-400 mt-1">At least 10 characters with uppercase, lowercase, and a number.</p>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-2">Confirm new password</label>
                    <input type="password" name="confirm_password" required minlength="10" autocomplete="new-password"
                        class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3.5 text-xs font-bold" data-loading-text="Updating…">
                    Update password
                </button>
            </form>
            <p class="text-center mt-4">
                <a href="forgot_password.php" class="text-[11px] font-bold text-blue-600 hover:underline">Request a new code</a>
            </p>
            <?php endif; ?>
        </div>

        <div class="bg-gray-50/50 py-6 border-t border-gray-50 text-center space-y-2">
            <a href="login.php" class="text-[11px] font-bold text-blue-600 hover:underline inline-flex items-center gap-1 justify-center">
                <i data-lucide="chevron-left" class="w-3 h-3"></i> Back to login
            </a>
        </div>
    </div>

    <?= actionCoreScripts() ?>
    <?= scriptTag('core/password-toggle.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
