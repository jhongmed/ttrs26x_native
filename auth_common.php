<?php
/**
 * Shared config + helpers for the TTRS auth pages
 * (login.php, forgot_password.php, reset_password.php).
 */

define('APP_NAME', 'TTRS 2.6.x');
define('APP_TITLE', 'Tee Time Reservation System');
define('CLUB_NAME', 'The Orchard Golf & Country Club');
define('CLUB_LOGO', 'https://theorchardgolf.com/wp-content/uploads/2022/10/cropped-logo.png');
define('DOC_URL', 'http://128.168.64.26:8002/itguide/ttrs-2-6-x-release-notes/');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS', 300); // 5 minutes
define('RESET_TOKEN_TTL_MINUTES', 30);
date_default_timezone_set('Asia/Manila');


function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
