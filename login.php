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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $loggedInStaff && $loginSuccessModal === null) {
    $redirectPage = $redirectTarget;
    $redirectQuery = [];
    if (str_contains($redirectTarget, '?')) {
        [$redirectPage, $redirectQs] = explode('?', $redirectTarget, 2);
        parse_str($redirectQs, $redirectQuery);
    }
    redirectWithAuth($redirectPage, $redirectQuery);
}

$error = '';

$submittedStaffId = '';

$rememberMeChecked = false;

$resetSuccess = isset($_GET['reset']) && $_GET['reset'] === '1';

$loginLocked = false;

$loginRetryAfter = 0;

const LOGIN_RATE_MAX = 8;

const LOGIN_RATE_WINDOW = 900;



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedStaffId = strtoupper(trim($_POST['staff_id'] ?? ''));

    $password = $_POST['password'] ?? '';

    $rememberMeChecked = !empty($_POST['remember_me']);

    $csrf = $_POST['csrf_token'] ?? '';

    $loginRateKey = $submittedStaffId !== '' ? rateLimitKey('login', $submittedStaffId) : '';

    $applyLoginLockout = static function () use ($loginRateKey, &$loginLocked, &$loginRetryAfter): void {
        if ($loginRateKey === '') {
            return;
        }
        http_response_code(429);
        $loginLocked = true;
        $loginRetryAfter = rateLimitRetryAfterSeconds($loginRateKey, LOGIN_RATE_MAX, LOGIN_RATE_WINDOW) ?? LOGIN_RATE_WINDOW;
    };

    if ($loginRateKey !== '' && rateLimitIsBlocked($loginRateKey, LOGIN_RATE_MAX, LOGIN_RATE_WINDOW)) {

        $applyLoginLockout();

        try {
            $pdo = getDB();
            notifyStaffLoginLockoutIfNeeded($pdo, $submittedStaffId, $loginRateKey, LOGIN_RATE_MAX, LOGIN_RATE_WINDOW);
        } catch (PDOException $e) {
            // Lockout UI still applies.
        }

    } elseif (!validateCsrf($csrf)) {

        $error = 'Your session expired. Please try again.';

    } elseif ($submittedStaffId === '' || $password === '') {

        $error = 'Please enter both your Staff ID and password.';

    } else {

        try {

            $pdo = getDB();

            $staff = staffRowById($pdo, $submittedStaffId);

            if ($staff && password_verify($password, (string) $staff['password_hash'])) {

                staffSessionLogin($staff, true);

                if ($rememberMeChecked) {
                    staffRememberMeIssue($pdo, $staff);
                } else {
                    staffRememberMeRevokeCurrent($pdo);
                }

                $token = createStaffAuthToken($staff);

                logActivity($staff['staff_id'], 'Staff Login', 'Logged in to staff portal');

                $joiner = str_contains($redirectTarget, '?') ? '&' : '?';

                $_SESSION['post_login_redirect'] = $redirectTarget . $joiner . 'alcros_auth=' . urlencode($token);

                header('Location: login.php?signed_in=1');

                exit;

            }

            if ($staff) {

                $attempt = rateLimitRecordFailedAttempt($loginRateKey, LOGIN_RATE_MAX, LOGIN_RATE_WINDOW);

                if (!$attempt['allowed']) {

                    $applyLoginLockout();

                }

                if ($attempt['limit_reached']) {

                    notifyStaffLoginLockoutIfNeeded($pdo, $submittedStaffId, $loginRateKey, LOGIN_RATE_MAX, LOGIN_RATE_WINDOW);

                }

            }

        } catch (PDOException $e) {

            $error = dbConnectionHelpMessage();

        }

        if ($error === '' && !$loginLocked) {

            $error = 'Invalid Staff ID or password.';

        }

    }

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <?= faviconLinkTag() ?>

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

<?php if (!$loggedInStaff && $loginSuccessModal === null): ?>
<script>try{sessionStorage.removeItem('alcros_auth');}catch(e){}</script>
<?php endif; ?>

    <div class="auth-portal-shell">
        <aside class="auth-portal-hero" aria-label="Aloran Local Civil Registry">
            <?= alcrosFaviconImg(320, 'auth-portal-hero__seal') ?>
            <div class="auth-portal-hero__inner">
                <h1 class="auth-portal-hero__title">Aloran Local Civil Registry</h1>
                <p class="auth-portal-hero__brand">ALCROS</p>
                <p class="auth-portal-hero__lead">Civil registry management for authorized staff</p>
                <p class="auth-portal-hero__copy">
                    Review citizen requests, manage appointments, serve the live queue, and maintain official records securely from one workspace.
                </p>
                <div class="auth-portal-hero__pills">
                    <span class="auth-portal-hero__pill"><i data-lucide="shield-check"></i> Secure access</span>
                    <span class="auth-portal-hero__pill"><i data-lucide="file-text"></i> Document requests</span>
                    <span class="auth-portal-hero__pill"><i data-lucide="calendar"></i> Appointments</span>
                </div>
            </div>
        </aside>

        <main class="auth-portal-form-wrap">
            <div class="auth-portal-form-panel">
                <header class="auth-portal-form-head">
                    <?= alcrosFaviconImg(88, 'auth-portal-card-logo') ?>
                    <p class="auth-portal-form-eyebrow">Welcome back</p>
                    <h2 class="auth-portal-form-title">Sign in to ALCROS</h2>
                    <p class="auth-portal-form-subtitle">Use your staff ID and password to continue</p>
                </header>

                <div class="auth-portal-form-body">
                    <?php if (!$loginSuccessModal): ?>
                    <div class="auth-portal-badge-wrap">
                        <span class="auth-portal-badge"><?= $loginLocked ? 'Sign-in temporarily locked' : 'Authorized staff only' ?></span>
                    </div>
                    <?php endif; ?>



                <?php if ($resetSuccess): ?>

                <div class="mb-6 px-4 py-3 rounded-xl bg-green-50 border border-green-100 text-green-700 text-[11px] font-semibold text-left flex items-center gap-2">

                    <i data-lucide="check-circle" class="w-3.5 h-3.5 flex-shrink-0"></i>

                    <span>Password updated. Sign in with your new password.</span>

                </div>

                <?php endif; ?>



                <?php if ($loginSuccessModal): ?>

                <div class="auth-portal-signing-in" role="status" aria-live="polite">
                    <p class="auth-portal-signing-in__text">Signing you in…</p>
                </div>

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

                    <p class="auth-portal-lockout__hint" id="loginRetryHint">Leave this page open the timer updates automatically.</p>

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

                        <div class="auth-portal-login-row mt-4">

                            <label class="auth-portal-remember">

                                <input
                                    type="checkbox"
                                    name="remember_me"
                                    value="1"
                                    class="auth-portal-remember__input"
                                    <?= $rememberMeChecked ? 'checked' : '' ?>
                                >

                                <span class="auth-portal-remember__label">Remember me</span>

                            </label>

                            <a href="forgot_password.php" class="auth-portal-forgot-link">Forgot password?</a>

                        </div>

                    </div>



                    <button type="submit" class="auth-portal-btn" data-loading-text="Signing in…">

                        <i data-lucide="log-in" class="w-4 h-4"></i> Sign In

                    </button>

                </form>

                <?php endif; ?>

                </div>

                <footer class="auth-portal-form-footer">
                    <a href="index.php" class="back-home back-home--center">
                        <i data-lucide="chevron-left" class="back-home__icon w-3 h-3"></i>
                        <span>Back to Citizen Portal</span>
                    </a>
                    <p class="auth-portal-form-footer-mobile">
                        <a href="index.php">Return to citizen portal</a>
                    </p>
                </footer>
            </div>
        </main>
    </div>



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

