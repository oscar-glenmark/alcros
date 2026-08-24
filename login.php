<?php
/**
 * ALCROS - Staff Portal Login (login.php)
 *
 * Functional additions (design untouched):
 *  - Real POST-based authentication against a staff directory
 *  - Session creation on success, with session_regenerate_id() to prevent fixation
 *  - CSRF token on the form
 *  - Redirects already-logged-in staff straight to dashboard.php
 *  - Redirects back to whatever page the user was trying to reach (?redirect=)
 *  - Inline error message on failed login (small addition, styled to match the card)
 *  - Working eye-icon password show/hide toggle
 *  - "Sign in with Google" is left as a visual button wired to a stub (oauth_google.php)
 *    since real Google sign-in requires OAuth client credentials you'll need to supply
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

$redirectTarget = isset($_GET['redirect']) ? $_GET['redirect'] : 'dashboard.php';
// Only allow relative, same-site redirect targets
if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.php(\?.*)?$/', $redirectTarget)) {
    $redirectTarget = 'dashboard.php';
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$submittedStaffId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedStaffId = strtoupper(trim($_POST['staff_id'] ?? ''));
    $password          = $_POST['password'] ?? '';
    $csrf               = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
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
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f8fafc;
        }
        .login-card {
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.04);
        }
        /* Hide Edge/IE native password-reveal so it doesn't stack on the custom eye */
        #passwordInput::-ms-reveal,
        #passwordInput::-ms-clear {
            display: none;
        }
        #togglePassword svg {
            width: 1rem;
            height: 1rem;
            pointer-events: none;
        }
        #togglePassword .eye-show { display: none; }
        #togglePassword.is-revealed .eye-hide { display: none; }
        #togglePassword.is-revealed .eye-show { display: block; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-[420px] bg-white rounded-[2rem] overflow-hidden login-card border border-gray-100">
        
        <div class="p-10 pb-8 text-center">
            <div class="inline-flex items-center justify-center w-14 h-14 bg-blue-600 rounded-2xl mb-6 shadow-lg shadow-blue-200">
                <span class="text-white text-2xl font-black">A</span>
            </div>

            <h1 class="text-2xl font-black text-slate-900 mb-1">Staff Portal</h1>
            <p class="text-gray-400 text-[11px] font-medium tracking-tight mb-8">ALCROS Civil Registry Management</p>

            <div class="mb-8">
                <span class="px-6 py-2 border border-blue-100 rounded-lg text-[10px] font-bold text-blue-600 bg-blue-50/30">
                    Staff Portal Login
                </span>
            </div>

            <?php if ($error): ?>
            <div class="mb-6 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-red-600 text-[11px] font-semibold text-left flex items-center gap-2">
                <i data-lucide="alert-circle" class="w-3.5 h-3.5 flex-shrink-0"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <form class="text-left space-y-5" method="POST" action="login.php?redirect=<?= urlencode($redirectTarget) ?>" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

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
                    <div class="relative">
                        <input 
                            type="password" 
                            name="password"
                            id="passwordInput"
                            placeholder="••••••••"
                            class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 pr-12 text-xs font-semibold text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                            required
                        >
                        <button type="button" id="togglePassword" class="absolute right-3 top-1/2 -translate-y-1/2 z-10 inline-flex items-center justify-center p-1 text-gray-400 hover:text-gray-600" aria-label="Show password" aria-pressed="false">
                            <svg class="eye-show w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg class="eye-hide w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"></path>
                                <path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"></path>
                                <path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"></path>
                                <path d="m2 2 20 20"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3.5 text-xs font-bold flex items-center justify-center gap-2 transition-all shadow-md shadow-blue-100" data-loading-text="Signing in…">
                    <i data-lucide="log-in" class="w-4 h-4"></i> Sign In
                </button>
            </form>

            <div class="relative my-8">
                <div class="absolute inset-0 flex items-center"><span class="w-full border-t border-gray-100"></span></div>
                <div class="relative flex justify-center text-[9px] uppercase font-black tracking-widest text-gray-400">
                    <span class="bg-white px-3">Or Continue With</span>
                </div>
            </div>

            <a href="oauth_google.php?redirect=<?= urlencode($redirectTarget) ?>" class="w-full border border-gray-200 hover:bg-gray-50 text-gray-700 rounded-xl py-3 text-xs font-bold flex items-center justify-center gap-3 transition-all">
                <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" class="w-4 h-4" alt="Google">
                Sign in with Google
            </a>
        </div>

        <div class="bg-gray-50/50 py-6 border-t border-gray-50 text-center">
            <a href="index.php" class="text-[10px] font-bold text-gray-500 hover:text-blue-600 flex items-center justify-center gap-2">
                <i data-lucide="chevron-left" class="w-3 h-3"></i> Back to Citizen Portal
            </a>
        </div>
    </div>

    <script src="includes/loading.js"></script>
    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();

        (function () {
            const toggleBtn = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('passwordInput');
            if (!toggleBtn || !passwordInput) return;

            function setPasswordVisible(visible) {
                passwordInput.setAttribute('type', visible ? 'text' : 'password');
                toggleBtn.classList.toggle('is-revealed', visible);
                toggleBtn.setAttribute('aria-pressed', visible ? 'true' : 'false');
                toggleBtn.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            }

            toggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                const hidden = passwordInput.getAttribute('type') !== 'text';
                setPasswordVisible(hidden);
            });
        })();
    </script>
</body>
</html>