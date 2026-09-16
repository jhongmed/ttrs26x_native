<?php
/**
 * TTRS 2.6.x - Forgot Password Request
 *
 * Takes an email address, and if it matches an active user, creates
 * a one-time reset token (hashed in the DB, TTL from RESET_TOKEN_TTL_MINUTES)
 * and emails a reset link.
 */

require_once __DIR__ . '/auth_common.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/mail_config.php';

init_secure_session();

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

/**
 * Creates (or replaces) a reset token for the given email and
 * returns the plaintext token to send in the email link. Returns
 * null if no active user has that email.
 */
function create_reset_token(PDO $pdo, string $email): ?string
{
    // Clean up expired tokens
    try {
        $pdo->exec('DELETE FROM password_resets WHERE expires_at < NOW()');
    } catch (Exception $e) {
        // Non-fatal if cleanup fails
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND status = "active" LIMIT 1');
    $stmt->execute([$email]);
    if (!$stmt->fetch()) {
        return null;
    }

    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + (RESET_TOKEN_TTL_MINUTES * 60));

    $stmt = $pdo->prepare(
        'INSERT INTO password_resets (email, token_hash, expires_at)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), created_at = NOW()'
    );
    $stmt->execute([$email, $tokenHash, $expiresAt]);

    return $token;
}

/**
 * Sends the reset link by email via SMTP (PHPMailer).
 */
function send_reset_email(string $email, string $resetUrl): bool
{
    $subject = APP_NAME . ' — Password Reset Request';

    $safeClubName = e(CLUB_NAME);
    $safeAppName  = e(APP_NAME);
    $safeAppTitle = e(APP_TITLE);
    $safeLogo     = e(CLUB_LOGO);
    $safeUrl      = e($resetUrl);
    $ttlMinutes   = (int)RESET_TOKEN_TTL_MINUTES;

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Password Reset Request</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f5; margin: 0; padding: 24px; color: #18181b;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 560px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; border: 1px solid #e4e4e7; overflow: hidden;">
        <tr>
            <td style="padding: 24px 32px; background-color: #f4f4f5; text-align: center; border-bottom: 1px solid #e4e4e7;">
                <img src="{$safeLogo}" alt="{$safeClubName}" style="max-height: 48px; margin-bottom: 8px; display: inline-block;">
                <h2 style="color: #18181b; margin: 0; font-size: 18px; font-weight: 600;">{$safeClubName}</h2>
                <div style="color: #52525b; font-size: 13px; margin-top: 4px;">{$safeAppTitle} ({$safeAppName})</div>
            </td>
        </tr>
        <tr>
            <td style="padding: 32px;">
                <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; color: #09090b;">Password Reset Request</h3>
                <p style="margin-bottom: 16px; font-size: 14px; line-height: 1.5; color: #3f3f46;">
                    You requested a password reset for your <strong>{$safeAppName}</strong> account. Click the button below to set a new password:
                </p>
                <div style="text-align: center; margin: 28px 0;">
                    <a href="{$safeUrl}" style="background-color: #15803d; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 14px; display: inline-block;">Reset Password</a>
                </div>
                <p style="margin-bottom: 16px; font-size: 13px; line-height: 1.5; color: #71717a;">
                    This link is valid for <strong>{$ttlMinutes} minutes</strong>. If you did not request a password reset, you can safely ignore this email.
                </p>
                <hr style="border: none; border-top: 1px solid #f4f4f5; margin: 24px 0;">
                <p style="font-size: 12px; color: #a1a1aa; line-height: 1.4; margin: 0; word-break: break-all;">
                    If the button above does not work, copy and paste this URL into your browser:<br>
                    <a href="{$safeUrl}" style="color: #15803d; text-decoration: underline;">{$safeUrl}</a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    $textBody = "You requested a password reset for your " . APP_NAME . " account.\n\n"
              . "Reset your password using the link below (expires in " . RESET_TOKEN_TTL_MINUTES . " minutes):\n"
              . $resetUrl . "\n\n"
              . "If you didn't request this, you can safely ignore this email.";

    return send_app_mail($email, $subject, $htmlBody, $textBody);
}

$errors  = [];
$sent    = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $token = $_POST['csrf_token'] ?? '';

    if (!csrf_check($token)) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        try {
            $pdo         = get_db_connection();
            $resetToken  = create_reset_token($pdo, $email);

            if ($resetToken !== null) {
                $resetUrl = sprintf(
                    '%s/reset_password.php?email=%s&token=%s',
                    app_base_url(),
                    urlencode($email),
                    $resetToken
                );

                $mailSent = send_reset_email($email, $resetUrl);
                if (!$mailSent) {
                    error_log('[TTRS Mail] Failed to send password reset email to: ' . $email);
                    $errors[] = 'We could not send the password reset email at this moment. Please try again later or contact the administrator.';
                } else {
                    $sent = true;
                }
            } else {
                // Report generic success when email does not exist to prevent enumeration
                $sent = true;
            }
        } catch (PDOException $e) {
            error_log('TTRS DB error (forgot_password): ' . $e->getMessage());
            $errors[] = 'We could not process your request right now. Please try again shortly.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> — Forgot Password</title>

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

                <h1 class="mb-1 font-medium">Forgot your password?</h1>
                <p class="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                    Enter the email address on your <?= e(APP_NAME) ?> account and we'll send you a link to reset it.
                </p>

                <?php if ($sent): ?>
                    <div class="mb-4 px-4 py-3 rounded-sm bg-[#effaf1] dark:bg-[#0f2a17] border border-[#1a7f3740] text-green-700 dark:text-green-400 text-sm" role="status">
                        If that email is registered, a reset link is on its way. Check your inbox.
                    </div>

                    <a href="login.php" class="inline-block mt-2 font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433]">
                        Back to login
                    </a>
                <?php else: ?>

                    <?php if (!empty($errors)): ?>
                        <div class="mb-4 px-4 py-3 rounded-sm bg-[#fff2f1] dark:bg-[#2a1210] border border-[#f5300340] text-[#f53003] dark:text-[#FF4433] text-sm" role="alert">
                            <?php foreach ($errors as $error): ?>
                                <div><?= e($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                        <div class="mb-6">
                            <label for="email" class="block mb-1.5 font-medium text-[#1b1b18] dark:text-[#EDEDEC]">Email address</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= e($_POST['email'] ?? '') ?>"
                                autocomplete="email"
                                required autofocus
                                class="w-full px-3.5 py-2.5 border border-[#e3e3e0] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] rounded-sm text-[13px] focus:outline-none focus:ring-2 focus:ring-green-700 focus:border-transparent"
                                placeholder="you@example.com"
                            >
                        </div>

                        <button
                            type="submit"
                            class="w-full inline-flex items-center justify-center px-5 py-2.5 bg-green-700 text-white border border-transparent hover:bg-green-800 rounded-sm text-sm leading-normal font-medium transition-colors"
                        >
                            Send reset link
                        </button>
                    </form>

                    <a href="login.php" class="inline-block mt-6 font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433]">
                        Back to login
                    </a>
                <?php endif; ?>

            </div>
        </main>
    </div>

</body>
</html>
