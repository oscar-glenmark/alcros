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

$portalBadge = $step === 1 ? 'Forgot Password' : 'Set New Password';
$inputClass = 'w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-xs font-semibold text-gray-600 focus:outline-none focus:ring-2 focus:ring-amber-400/30 focus:border-amber-500 transition-all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= faviconLinkTag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Staff Password - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
    <?= interFontTags() ?>
    <?= publicStylesheet('auth-portal') ?>
    <?= publicStylesheet('password-toggle') ?>
    <?= publicStylesheet('back-home') ?>
</head>
<body class="auth-portal">

    <header class="auth-portal-header">
        <div class="auth-portal-header-inner">
            <a href="index.php" class="auth-portal-brand">
                <?= alcrosFaviconImg(48, 'auth-portal-brand-logo') ?>
                <div class="min-w-0">
                    <div class="auth-portal-brand-title">ALCROS</div>
                    <div class="auth-portal-brand-subtitle">Aloran Local Civil Registry Online System</div>
                </div>
            </a>
            <a href="index.php" class="auth-portal-header-link">Citizen Portal</a>
        </div>
    </header>

    <main class="auth-portal-main">
        <div class="auth-portal-card">
            <div class="p-6 sm:p-10 pb-8 text-center">
                <div class="flex justify-center mb-6">
                    <?= alcrosFaviconImg(72, 'auth-portal-card-logo') ?>
                </div>

                <h1 class="text-2xl font-black text-slate-900 mb-1">Staff Portal</h1>
                <p class="text-gray-500 text-[11px] font-medium tracking-tight mb-8">ALCROS Civil Registry Management</p>

                <div class="mb-8">
                    <span class="auth-portal-badge"><?= htmlspecialchars($portalBadge) ?></span>
                </div>

                <?php if ($error): ?>
                <div class="mb-6 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-red-600 text-[11px] font-semibold text-left flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-3.5 h-3.5 flex-shrink-0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                <p class="text-[11px] text-gray-500 font-medium leading-relaxed mb-6 text-left">Enter your Staff ID. We will send a 6-digit verification code to the Gmail registered on your account. That Gmail must have <strong class="text-slate-700">Google 2-Step Verification</strong> already enabled and confirmed in System Settings.</p>
                <form method="POST" class="text-left space-y-5" autocomplete="off" data-no-confirm>
                    <?= publicCsrfField() ?>
                    <input type="hidden" name="action" value="send_otp">
                    <input type="hidden" name="step" value="1">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-2 flex items-center gap-2">
                            <i data-lucide="circle-user-round" class="w-3.5 h-3.5 text-gray-400"></i> Staff ID
                        </label>
                        <input type="text" name="staff_id" required value="<?= htmlspecialchars($staffId) ?>"
                            class="<?= $inputClass ?> uppercase">
                    </div>
                    <button type="submit" class="auth-portal-btn" data-loading-text="Sending code…">
                        <i data-lucide="mail" class="w-4 h-4"></i> Send Verification Code
                    </button>
                    <p class="text-center pt-1">
                        <a href="login.php" class="text-[10px] font-bold text-blue-600 hover:underline inline-flex items-center gap-1 justify-center">
                            <i data-lucide="chevron-left" class="w-3 h-3"></i> Back to login
                        </a>
                    </p>
                </form>
                <?php else: ?>
                <p class="text-[11px] text-gray-500 font-medium leading-relaxed mb-6 text-left">
                    Enter the 6-digit code sent<?= $emailHint !== '' ? ' to <strong class="text-slate-700">' . htmlspecialchars($emailHint) . '</strong>' : '' ?> and choose a new password.
                </p>
                <form method="POST" class="text-left space-y-5" autocomplete="off" data-no-confirm>
                    <?= publicCsrfField() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="step" value="2">
                    <input type="hidden" name="staff_id" value="<?= htmlspecialchars($staffId) ?>">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-2 flex items-center gap-2">
                            <i data-lucide="shield-check" class="w-3.5 h-3.5 text-gray-400"></i> Verification Code
                        </label>
                        <input type="text" name="otp" required maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" placeholder="000000"
                            class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-center text-lg font-black tracking-[0.35em] text-gray-600 focus:outline-none focus:ring-2 focus:ring-amber-400/30 focus:border-amber-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-2 flex items-center gap-2">
                            <i data-lucide="lock" class="w-3.5 h-3.5 text-gray-400"></i> New Password
                        </label>
                        <input type="password" name="new_password" id="passwordInput" required minlength="<?= passwordMinLength() ?>" autocomplete="new-password" placeholder="••••••••"
                            class="<?= $inputClass ?>">
                        <p class="text-[10px] text-gray-400 mt-1.5">At least <?= passwordMinLength() ?> characters with uppercase, lowercase, and a number.</p>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 mb-2 flex items-center gap-2">
                            <i data-lucide="lock-keyhole" class="w-3.5 h-3.5 text-gray-400"></i> Confirm New Password
                        </label>
                        <input type="password" name="confirm_password" required minlength="<?= passwordMinLength() ?>" autocomplete="new-password" placeholder="••••••••"
                            class="<?= $inputClass ?>">
                    </div>
                    <button type="submit" class="auth-portal-btn" data-loading-text="Updating…">
                        <i data-lucide="key-round" class="w-4 h-4"></i> Update Password
                    </button>
                    <p class="text-center space-y-2 pt-1">
                        <a href="forgot_password.php" class="block text-[10px] font-bold text-blue-600 hover:underline">Request a new code</a>
                        <a href="login.php" class="inline-flex items-center gap-1 justify-center text-[10px] font-bold text-blue-600 hover:underline">
                            <i data-lucide="chevron-left" class="w-3 h-3"></i> Back to login
                        </a>
                    </p>
                </form>
                <?php endif; ?>
            </div>

            <div class="auth-portal-card-footer">
                <a href="index.php" class="back-home back-home--center">
                    <i data-lucide="chevron-left" class="back-home__icon w-3 h-3"></i>
                    <span>Back to Citizen Portal</span>
                </a>
            </div>
        </div>
    </main>

    <?= actionCoreScripts() ?>
    <?= scriptTag('core/password-toggle.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
