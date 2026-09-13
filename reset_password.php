<?php
/**
 * TTRS 2.6.x - Reset Password
 *
 * Verifies the (email, token) pair from the emailed link against
 * the password_resets table, checks expiry, and if valid lets the
 * user set a new password. The token row is deleted after use so
 * it can't be replayed.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/auth_common.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

/**
 * Checks the token against the stored hash and expiry for this email.
 */
function verify_reset_token(PDO $pdo, string $email, string $token): bool
{
    $stmt = $pdo->prepare('SELECT token_hash, expires_at FROM password_resets WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    if (strtotime($row['expires_at']) < time()) {
        return false;
    }

    return hash_equals($row['token_hash'], hash('sha256', $token));
}

function consume_reset_token(PDO $pdo, string $email): void
{
    $stmt = $pdo->prepare('DELETE FROM password_resets WHERE email = ?');
    $stmt->execute([$email]);
}

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$errors     = [];
$done       = false;
$token_ok   = false;
$fatal_link = null;

if ($email === '' || $token === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fatal_link = 'This reset link is invalid.';
} else {
    try {
        $pdo = get_db_connection();
        $token_ok = verify_reset_token($pdo, $email, $token);
        if (!$token_ok) {
            $fatal_link = 'This reset link is invalid or has expired. Please request a new one.';
        }
    } catch (PDOException $e) {
        error_log('TTRS DB error (reset_password verify): ' . $e->getMessage());
        $fatal_link = 'We could not verify this link right now. Please try again shortly.';
    }
}

if ($token_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $csrf     = $_POST['csrf_token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirmation'] ?? '';

    if (!csrf_check($csrf)) {
        $errors[] = 'Your session has expired. Please try again.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        try {
            $pdo  = get_db_connection();
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
            $stmt->execute([$hash, $email]);

            consume_reset_token($pdo, $email);

            $done = true;
        } catch (PDOException $e) {
            error_log('TTRS DB error (reset_password update): ' . $e->getMessage());
            $errors[] = 'We could not update your password right now. Please try again shortly.';
        }
    }
}

if ($done) {
    header('Location: login.php?reset=success');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> — Reset Password</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'media' };
    </script>

    <style>
        body { font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif; }
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

    <div class="flex items-center justify-center w-full">
        <main class="max-w-[335px] w-full lg:max-w-md rounded-lg overflow-hidden border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615]">
            <div class="text-[13px] leading-[20px] p-6 lg:p-10">

                <h1 class="mb-1 font-medium">Reset your password</h1>

                <?php if ($fatal_link): ?>
                    <p class="mb-4 text-[#706f6c] dark:text-[#A1A09A]">We ran into a problem with this link.</p>
                    <div class="mb-4 px-4 py-3 rounded-sm bg-[#fff2f1] dark:bg-[#2a1210] border border-[#f5300340] text-[#f53003] dark:text-[#FF4433] text-sm" role="alert">
                        <?= e($fatal_link) ?>
                    </div>
                    <a href="forgot_password.php" class="inline-block font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433]">
                        Request a new reset link
                    </a>
                <?php else: ?>
                    <p class="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                        Choose a new password for <?= e($email) ?>.
                    </p>

                    <?php if (!empty($errors)): ?>
                        <div class="mb-4 px-4 py-3 rounded-sm bg-[#fff2f1] dark:bg-[#2a1210] border border-[#f5300340] text-[#f53003] dark:text-[#FF4433] text-sm" role="alert">
                            <?php foreach ($errors as $error): ?>
                                <div><?= e($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="email" value="<?= e($email) ?>">
                        <input type="hidden" name="token" value="<?= e($token) ?>">

                        <div class="mb-4">
                            <label for="password" class="block mb-1.5 font-medium text-[#1b1b18] dark:text-[#EDEDEC]">New password</label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                autocomplete="new-password"
                                minlength="8"
                                required autofocus
                                class="w-full px-3.5 py-2.5 border border-[#e3e3e0] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] rounded-sm text-[13px] focus:outline-none focus:ring-2 focus:ring-green-700 focus:border-transparent"
                                placeholder="At least 8 characters"
                            >
                        </div>

                        <div class="mb-6">
                            <label for="password_confirmation" class="block mb-1.5 font-medium text-[#1b1b18] dark:text-[#EDEDEC]">Confirm new password</label>
                            <input
                                type="password"
                                id="password_confirmation"
                                name="password_confirmation"
                                autocomplete="new-password"
                                minlength="8"
                                required
                                class="w-full px-3.5 py-2.5 border border-[#e3e3e0] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] rounded-sm text-[13px] focus:outline-none focus:ring-2 focus:ring-green-700 focus:border-transparent"
                                placeholder="Re-enter new password"
                            >
                        </div>

                        <button
                            type="submit"
                            class="w-full inline-flex items-center justify-center px-5 py-2.5 bg-green-700 text-white border border-transparent hover:bg-green-800 rounded-sm text-sm leading-normal font-medium transition-colors"
                        >
                            Reset password
                        </button>
                    </form>
                <?php endif; ?>

            </div>
        </main>
    </div>

</body>
</html>
