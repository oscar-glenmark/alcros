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

$loggedInStaff = getAuthenticatedStaff();

$loginSuccessModal = null;

if (isset($_GET['signed_in']) && (string) $_GET['signed_in'] === '1') {

    $signedInStaff = getAuthenticatedStaff();

    $postLoginRedirect = isset($_SESSION['post_login_redirect']) ? (string) $_SESSION['post_login_redirect'] : '';

    unset($_SESSION['post_login_redirect']);

    if ($signedInStaff && $postLoginRedirect !== '' && preg_match('/^[a-zA-Z0-9_\-\.]+\.php(\?.*)?$/', $postLoginRedirect)) {

        $loginSuccessModal = [

            'name'     => (string) ($signedInStaff['name'] ?? 'Staff'),

            'redirect' => $postLoginRedirect,

        ];

    }

}



$error = '';

$submittedStaffId = '';

$resetSuccess = isset($_GET['reset']) && $_GET['reset'] === '1';

$loginLocked = false;

$loginRetryAfter = 0;

const LOGIN_RATE_MAX = 8;

const LOGIN_RATE_WINDOW = 900;



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedStaffId = strtoupper(trim($_POST['staff_id'] ?? ''));

    $loginRateKey = rateLimitKey('login', $submittedStaffId);

    if (!rateLimitCheck($loginRateKey, LOGIN_RATE_MAX, LOGIN_RATE_WINDOW)) {

        http_response_code(429);

        $loginLocked = true;

        $loginRetryAfter = rateLimitRetryAfterSeconds($loginRateKey, LOGIN_RATE_MAX, LOGIN_RATE_WINDOW) ?? LOGIN_RATE_WINDOW;

    } else {

    $password          = $_POST['password'] ?? '';

    $csrf               = $_POST['csrf_token'] ?? '';



    if (!validateCsrf($csrf)) {

        $error = 'Your session expired. Please try again.';

    } elseif ($submittedStaffId === '' || $password === '') {

        $error = 'Please enter both your Staff ID and password.';

    } else {

        try {

            $pdo = getDB();

            $stmt = $pdo->prepare('SELECT staff_id, first_name, middle_name, last_name, password_hash, role FROM staff WHERE staff_id = ?');

            $stmt->execute([$submittedStaffId]);

            $staff = $stmt->fetch();



            if ($staff && password_verify($password, $staff['password_hash'])) {

                staffSessionLogin($staff, true);

                $token = createStaffAuthToken($staff);

                logActivity($staff['staff_id'], 'Staff Login', 'Logged in to staff portal');

                $joiner = str_contains($redirectTarget, '?') ? '&' : '?';

                $_SESSION['post_login_redirect'] = $redirectTarget . $joiner . 'alcros_auth=' . urlencode($token);

                header('Location: login.php?signed_in=1');

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

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <link rel="icon" type="image/png" href="images/favicon.png?v=2">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Staff Portal Login - ALCROS</title>

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

                    <?= alcrosFaviconImg(56, 'auth-portal-brand-logo shadow-lg') ?>

                </div>



                <h1 class="text-2xl font-black text-slate-900 mb-1">Staff Portal</h1>

                <p class="text-gray-500 text-[11px] font-medium tracking-tight mb-8">ALCROS Civil Registry Management</p>



                <div class="mb-8">

                    <span class="auth-portal-badge"><?= $loginLocked ? 'Sign-in temporarily locked' : 'Staff Portal Login' ?></span>

                </div>



                <?php if ($loggedInStaff && !$loginSuccessModal): ?>

                <div class="mb-6 px-4 py-3 rounded-xl bg-blue-50 border border-blue-100 text-blue-800 text-[11px] font-semibold text-left">

                    <p class="mb-2">You are already signed in as <strong><?= htmlspecialchars($loggedInStaff['name']) ?></strong>.</p>

                    <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="inline-flex items-center gap-1.5 text-blue-700 font-bold hover:underline">

                        <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i> Open Staff Portal

                    </a>

                </div>

                <?php endif; ?>



                <?php if ($resetSuccess): ?>

                <div class="mb-6 px-4 py-3 rounded-xl bg-green-50 border border-green-100 text-green-700 text-[11px] font-semibold text-left flex items-center gap-2">

                    <i data-lucide="check-circle" class="w-3.5 h-3.5 flex-shrink-0"></i>

                    <span>Password updated. Sign in with your new password.</span>

                </div>

                <?php endif; ?>



                <?php if ($loginSuccessModal): ?>

                <p class="text-[11px] text-slate-500 font-medium">Signing you in…</p>

                <?php elseif ($error && !$loginLocked): ?>

                <div class="mb-6 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-red-600 text-[11px] font-semibold text-left flex items-center gap-2">

                    <i data-lucide="alert-circle" class="w-3.5 h-3.5 flex-shrink-0"></i>

                    <span><?= htmlspecialchars($error) ?></span>

                </div>

                <?php endif; ?>



                <?php if ($loginLocked): ?>

                <div class="auth-portal-lockout text-left">

                    <div class="auth-portal-lockout__icon" aria-hidden="true">

                        <i data-lucide="shield-off" class="w-7 h-7"></i>

                    </div>

                    <h2 class="auth-portal-lockout__title">Too many sign-in attempts</h2>

                    <p class="auth-portal-lockout__lead">For your security, sign-in for this Staff ID is paused after several failed tries.</p>

                    <div class="auth-portal-lockout__timer-card">

                        <span class="auth-portal-lockout__timer-label">Try again in</span>

                        <span class="auth-portal-lockout__timer-value" id="loginRetryCountdown" data-seconds="<?= (int) $loginRetryAfter ?>">--:--</span>

                    </div>

                    <p class="auth-portal-lockout__hint" id="loginRetryHint">Leave this page open—the timer updates automatically.</p>

                </div>

                <?php elseif (!$loginSuccessModal): ?>

                <form id="loginForm" class="text-left space-y-5" method="POST" action="login.php?redirect=<?= urlencode($redirectTarget) ?>" autocomplete="off" data-no-confirm>

                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">



                    <div>

                        <label class="block text-[11px] font-bold text-gray-700 mb-2 flex items-center gap-2">

                            <i data-lucide="circle-user-round" class="w-3.5 h-3.5 text-gray-400"></i> Staff ID

                        </label>

                        <input

                            type="text"

                            name="staff_id"

                            value="<?= htmlspecialchars($submittedStaffId) ?>"

                            class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-xs font-semibold text-gray-600 focus:outline-none focus:ring-2 focus:ring-amber-400/30 focus:border-amber-500 transition-all"

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

                                class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-xs font-semibold text-gray-600 focus:outline-none focus:ring-2 focus:ring-amber-400/30 focus:border-amber-500 transition-all"

                                required

                            >

                        </div>

                        <div class="text-right mt-1.5">

                            <a href="forgot_password.php" class="text-[10px] font-bold text-blue-600 hover:underline">Forgot password?</a>

                        </div>

                    </div>



                    <button type="submit" class="auth-portal-btn" data-loading-text="Signing in…">

                        <i data-lucide="log-in" class="w-4 h-4"></i> Sign In

                    </button>

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



    <?php if ($loginSuccessModal): ?>

    <?= pageConfigJson([

        'type'           => 'success',

        'title'          => 'Signed in successfully',

        'badge'          => 'Staff portal',

        'message'        => 'Welcome back, ' . $loginSuccessModal['name'] . '.',

        'buttonLabel'    => 'Continue',

        'redirect'       => $loginSuccessModal['redirect'],

        'autoRedirectMs' => 2400,

    ], 'alcros-action-result') ?>

    <?php endif; ?>

    <?= actionCoreScripts() ?>

    <?= scriptTag('core/password-toggle.js') ?>

    <?= lucideInitScript() ?>

    <?php if ($loginLocked): ?>

    <script>

    (function () {

        var el = document.getElementById('loginRetryCountdown');

        var hint = document.getElementById('loginRetryHint');

        if (!el) return;

        var s = parseInt(el.getAttribute('data-seconds') || '0', 10);

        function format(sec) {

            var m = Math.floor(sec / 60);

            var r = sec % 60;

            return m + ':' + (r < 10 ? '0' : '') + r;

        }

        function tick() {

            if (s <= 0) {

                el.textContent = '0:00';

                if (hint) hint.textContent = 'You can try signing in again. Refreshing…';

                window.setTimeout(function () { window.location.reload(); }, 1200);

                return;

            }

            el.textContent = format(s);

            s -= 1;

            window.setTimeout(tick, 1000);

        }

        el.textContent = format(s);

        window.setTimeout(tick, 1000);

    })();

    </script>

    <?php endif; ?>

</body>

</html>

