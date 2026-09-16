<?php
/**
 * Shared config + helpers for the TTRS auth pages
 * (login.php, forgot_password.php, reset_password.php, dashboard.php).
 */

/**
 * Minimal .env loader that populates putenv() and $_ENV without clobbering
 * existing environment variables.
 */
function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and lines without assignments
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip matching surrounding quotes
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[-1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

// Automatically load .env if present
load_env_file(__DIR__ . '/.env');

define('APP_NAME', getenv('APP_NAME') ?: 'TTRS 2.6.x');
define('APP_TITLE', getenv('APP_TITLE') ?: 'Tee Time Reservation System');
define('CLUB_NAME', getenv('CLUB_NAME') ?: 'The Orchard Golf & Country Club');
define('CLUB_LOGO', getenv('CLUB_LOGO') ?: 'https://theorchardgolf.com/wp-content/uploads/2022/10/cropped-logo.png');
define('DOC_URL', getenv('DOC_URL') ?: 'http://128.168.64.26:8002/itguide/ttrs-2-6-x-release-notes/');
define('MAX_LOGIN_ATTEMPTS', (int)(getenv('MAX_LOGIN_ATTEMPTS') ?: 5));
define('LOCKOUT_SECONDS', (int)(getenv('LOCKOUT_SECONDS') ?: 300)); // 5 minutes
define('RESET_TOKEN_TTL_MINUTES', (int)(getenv('RESET_TOKEN_TTL_MINUTES') ?: 30));
date_default_timezone_set('Asia/Manila');

/**
 * Initializes a session with hardened security cookies (HttpOnly, SameSite, Secure).
 */
function init_secure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}

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

function csrf_regenerate(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_check(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Returns the base application URL, preferring the configured APP_URL to prevent
 * Host Header Injection.
 */
function app_base_url(): string
{
    $configured = getenv('APP_URL');
    if ($configured && filter_var($configured, FILTER_VALIDATE_URL)) {
        return rtrim($configured, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ? 'https' : 'http';

    $host = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return sprintf('%s://%s%s', $scheme, $host, $scriptDir);
}

