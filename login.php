<?php
/**
 * TTRS 2.6.x - Native PHP Login Interface
 * Tee Time Reservation System — The Orchard Golf & Country Club
 *
 * Authenticates against the `users` table and records every attempt
 * (successful or failed) into `login_history`.
 */

require_once __DIR__ . '/auth_common.php';
require_once __DIR__ . '/db_config.php';

init_secure_session();

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// ---------------------------------------------------------------
// RATE LIMITING HELPERS
// ---------------------------------------------------------------
function is_locked_out(?string $usernameOrEmail = null): bool
{
    // Layer 1: In-session check
    if (isset($_SESSION['login_attempts'], $_SESSION['first_attempt_time'])) {
        $elapsed = time() - $_SESSION['first_attempt_time'];
        if ($_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
            if ($elapsed < LOCKOUT_SECONDS) {
                return true;
            }
            // Lockout window expired — reset session counters
            unset($_SESSION['login_attempts'], $_SESSION['first_attempt_time']);
        } elseif ($elapsed >= LOCKOUT_SECONDS) {
            // Expire stale attempt counter
            unset($_SESSION['login_attempts'], $_SESSION['first_attempt_time']);
        }
    }

    // Layer 2: Database-backed check across IP and username
    try {
        $pdo = get_db_connection();
        $ip  = mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $cutoff = date('Y-m-d H:i:s', time() - LOCKOUT_SECONDS);

        if ($usernameOrEmail !== null && $usernameOrEmail !== '') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM login_history
                 WHERE (ip_address = ? OR username_attempted = ?)
                   AND status = "failed"
                   AND attempted_at >= ?'
            );
            $stmt->execute([$ip, mb_substr($usernameOrEmail, 0, 255), $cutoff]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM login_history
                 WHERE ip_address = ?
                   AND status = "failed"
                   AND attempted_at >= ?'
            );
            $stmt->execute([$ip, $cutoff]);
        }

        if ((int) $stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS) {
            return true;
        }
    } catch (Exception $e) {
        // If DB query fails, session-level rate limiting remains active
    }

    return false;
}

function register_failed_attempt(): void
{
    if (!isset($_SESSION['login_attempts']) || (isset($_SESSION['first_attempt_time']) && time() - $_SESSION['first_attempt_time'] >= LOCKOUT_SECONDS)) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }
    $_SESSION['login_attempts']++;
}

/**
 * AUTHENTICATION LOGIC
 */
function attempt_login(string $usernameOrEmail, string $password): ?array
{
    $pdo = get_db_connection();
    $cleanUsername = trim($usernameOrEmail);

    $stmt = $pdo->prepare(
        'SELECT id, username, email, password_hash, role, status
         FROM users
         WHERE username = ? OR email = ?
         LIMIT 1'
    );
    $stmt->execute([$cleanUsername, $cleanUsername]);
    $user = $stmt->fetch();

    $success = false;
    $userId  = null;
    $dummyHash = '$2y$10$e8w3k7V0gZ9mEwD9y6h4g.sDk3s1B7vO6Yk7p8h4c8a2e1m7g4a5e';

    if ($user) {
        $userId = (int) $user['id'];
        if ($user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
            $success = true;
        } else {
            // Constant-time execution if account is inactive or password mismatch
            password_verify($password, $user['password_hash']);
        }
    } else {
        // Prevent username enumeration via timing attacks
        password_verify($password, $dummyHash);
    }

    log_login_attempt($pdo, $userId, $cleanUsername, $success);

    if (!$success) {
        return null;
    }

    // Update last_login_at on success
    $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $update->execute([$user['id']]);

    return $user;
}

/**
 * Records one row per login attempt into login_history with safe truncation.
 */
function log_login_attempt(PDO $pdo, ?int $userId, string $usernameAttempted, bool $success): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO login_history (user_id, username_attempted, ip_address, user_agent, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            mb_substr($usernameAttempted, 0, 255),
            mb_substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $success ? 'success' : 'failed',
        ]);
    } catch (Exception $e) {
        error_log('TTRS login_history log error: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------
// FLASH MESSAGE (e.g. redirected here after a successful reset)
// ---------------------------------------------------------------
$flash_success = null;
if (($_GET['reset'] ?? '') === 'success') {
    $flash_success = 'Your password has been reset. You can now log in.';
}

// ---------------------------------------------------------------
// HANDLE POST
// ---------------------------------------------------------------
$errors = [];
$old_username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_username = trim($_POST['username'] ?? '');
    $password     = $_POST['password'] ?? '';
    $token        = $_POST['csrf_token'] ?? '';

    if (!csrf_check($token)) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } elseif (is_locked_out($old_username)) {
        $errors[] = 'Too many failed attempts. Please wait a few minutes before trying again.';
    } elseif ($old_username === '' || $password === '') {
        $errors[] = 'Please enter both username and password.';
    } else {
        $db_error = false;

        try {
            $user = attempt_login($old_username, $password);
        } catch (PDOException $e) {
            error_log('TTRS DB error: ' . $e->getMessage());
            $user = null;
            $db_error = true;
            $errors[] = 'We could not reach the database. Please try again shortly.';
        }

        if ($user) {
            // Success — reset attempt counters, regenerate session ID & CSRF
            unset($_SESSION['login_attempts'], $_SESSION['first_attempt_time']);
            session_regenerate_id(true);
            csrf_regenerate();

            $_SESSION['user_id']      = (int) $user['id'];
            $_SESSION['username']     = $user['username'];
            $_SESSION['display_name'] = $user['username'];
            $_SESSION['role']         = $user['role'];

            header('Location: dashboard.php');
            exit;
        } elseif (!$db_error) {
            register_failed_attempt();
            $errors[] = 'Invalid username or password.';
        }
    }
}

$locked = is_locked_out();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> — Login</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'media' };
    </script>

    <style>
        body { font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-in-up { animation: fadeInUp 0.6s ease-out both; }

        /* Keep autofilled inputs on-brand instead of the browser's default blue highlight */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-text-fill-color: #1b1b18;
            box-shadow: 0 0 0px 1000px #ffffff inset;
            transition: background-color 9999s ease-in-out 0s;
        }
        @media (prefers-color-scheme: dark) {
            input:-webkit-autofill,
            input:-webkit-autofill:hover,
            input:-webkit-autofill:focus {
                -webkit-text-fill-color: #EDEDEC;
                box-shadow: 0 0 0px 1000px #161615 inset;
            }
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-[#EDEDEC] flex p-6 lg:p-8 items-center lg:justify-center min-h-screen flex-col">

    <div class="flex items-center justify-center w-full fade-in-up">
        <main class="flex max-w-[335px] w-full flex-col-reverse lg:max-w-4xl lg:flex-row rounded-lg overflow-hidden border border-[#e3e3e0] dark:border-[#3E3E3A]">

            <!-- Login form panel -->
            <div class="text-[13px] leading-[20px] flex-1 p-6 pb-12 lg:p-20 bg-white dark:bg-[#161615] dark:text-[#EDEDEC]">

                <h1 class="mb-1 font-medium"><?= e(APP_TITLE) ?></h1>
                <p class="mb-4 text-[#706f6c] dark:text-[#A1A09A]">Sign in to <?= e(APP_NAME) ?> to manage tee time reservations.</p>

                <?php if ($flash_success): ?>
                    <div class="mb-4 px-4 py-3 rounded-sm bg-[#effaf1] dark:bg-[#0f2a17] border border-[#1a7f3740] text-green-700 dark:text-green-400 text-sm" role="status">
                        <?= e($flash_success) ?>
                    </div>
                <?php endif; ?>

                <?php if ($locked): ?>
                    <div class="mb-4 px-4 py-3 rounded-sm bg-[#fff2f1] dark:bg-[#2a1210] border border-[#f5300340] text-[#f53003] dark:text-[#FF4433] text-sm" role="alert">
                        Too many failed attempts. Please wait a few minutes before trying again.
                    </div>
                <?php elseif (!empty($errors)): ?>
                    <div class="mb-4 px-4 py-3 rounded-sm bg-[#fff2f1] dark:bg-[#2a1210] border border-[#f5300340] text-[#f53003] dark:text-[#FF4433] text-sm" role="alert">
                        <?php foreach ($errors as $error): ?>
                            <div><?= e($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" novalidate autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="mb-4">
                        <label for="username" class="block mb-1.5 font-medium text-[#1b1b18] dark:text-[#EDEDEC]">Username or email</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            value=""
                            autocomplete="off"
                            <?= $locked ? 'disabled' : 'required autofocus' ?>
                            class="w-full px-3.5 py-2.5 border border-[#e3e3e0] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] rounded-sm text-[13px] focus:outline-none focus:ring-2 focus:ring-green-700 focus:border-transparent disabled:bg-[#f5f5f4] dark:disabled:bg-[#1D1D1B] disabled:text-[#a1a09a]"
                            placeholder="Enter your username or email"
                        >
                    </div>

                    <div class="mb-2">
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="password" class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">Password</label>
                            <a href="forgot_password.php" class="text-[#f53003] dark:text-[#FF4433] underline underline-offset-4 font-medium">Forgot password?</a>
                        </div>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            value=""
                            autocomplete="off"
                            <?= $locked ? 'disabled' : 'required' ?>
                            class="w-full px-3.5 py-2.5 border border-[#e3e3e0] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] rounded-sm text-[13px] focus:outline-none focus:ring-2 focus:ring-green-700 focus:border-transparent disabled:bg-[#f5f5f4] dark:disabled:bg-[#1D1D1B] disabled:text-[#a1a09a]"
                            placeholder="Enter your password"
                        >
                    </div>

                    <button
                        type="submit"
                        <?= $locked ? 'disabled' : '' ?>
                        class="w-full mt-4 inline-flex items-center justify-center px-5 py-2.5 bg-green-700 text-white border border-transparent hover:bg-green-800 rounded-sm text-sm leading-normal font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Log in
                    </button>
                </form>

                <p class="mt-6 text-[#706f6c] dark:text-[#A1A09A]">
                    Need help? Read the
                    <a href="<?= e(DOC_URL) ?>" target="_blank" rel="noopener" class="font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433]">
                        <?= e(APP_NAME) ?> Documentation
                    </a>
                </p>
            </div>

            <!-- Branding panel -->
            <div class="bg-[#ffffff] dark:bg-[#1D0002] border-b lg:border-b-0 lg:border-l border-[#e3e3e0] dark:border-[#3E3E3A] aspect-[335/376] lg:aspect-auto w-full lg:w-[438px] shrink-0 overflow-hidden flex flex-col items-center justify-center space-y-2 pt-4">

                <img src="<?= e(CLUB_LOGO) ?>" alt="<?= e(CLUB_NAME) ?> Logo" class="w-[300px] h-auto -mb-2">

                <svg width="200" height="100" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <filter id="textShadow" x="-50%" y="-50%" width="200%" height="200%">
                            <feDropShadow dx="2" dy="2" stdDeviation="1" flood-color="#000000" flood-opacity="0.4"/>
                        </filter>
                    </defs>
                    <rect width="200" height="100" rx="10" ry="10" fill="#ffffff"/>
                    <text x="100" y="55" text-anchor="middle" fill="#014421" font-family="Arial, serif" font-weight="bold" font-size="24" filter="url(#textShadow)">
                        <?= e(APP_NAME) ?>
                    </text>
                </svg>
            </div>

        </main>
    </div>

    <script>
        // Belt-and-braces: some browsers ignore autocomplete="off" for login
        // forms and will still autofill saved credentials, and the
        // back/forward cache can restore previously typed values when the
        // user navigates back to this page. Force both fields blank on
        // every load/restore so the form always starts empty.
        function clearLoginFields() {
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            if (username) username.value = '';
            if (password) password.value = '';
        }
        document.addEventListener('DOMContentLoaded', clearLoginFields);
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                clearLoginFields();
            }
        });
    </script>

</body>
</html>
