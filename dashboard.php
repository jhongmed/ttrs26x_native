<?php
/**
 * TTRS 2.6.x - Dashboard
 * Tee Time Reservation System — The Orchard Golf & Country Club
 */

require_once __DIR__ . '/auth_common.php';

init_secure_session();

// Require login
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUser = !empty($_SESSION['username']) ? $_SESSION['username'] : 'admin';
$displayName = !empty($_SESSION['display_name'])
    ? $_SESSION['display_name']
    : ($currentUser === 'admin' ? 'Jhong Admin' : ucfirst($currentUser));
$userRole = !empty($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'Administrator';
$sessionUserId = $_SESSION['user_id'] ?? null;

/* ============================================================
 *  CONFIGURATION & PAGE LINKS
 * ============================================================ */

defined('TTRS_API_BASE') or define('TTRS_API_BASE', rtrim(getenv('TTRS_API_BASE') ?: 'http://128.168.64.102/ttrs2_teetime_web_orchard/api', '/'));
defined('TTRS_WEB_BASE') or define('TTRS_WEB_BASE', rtrim(getenv('TTRS_WEB_BASE') ?: 'http://128.168.64.102/ttrs2_teetime_web_orchard', '/'));
defined('TTRS_API_TIMEOUT') or define('TTRS_API_TIMEOUT', (int)(getenv('TTRS_API_TIMEOUT') ?: 4));
defined('TTRS_CONNECT_TIMEOUT') or define('TTRS_CONNECT_TIMEOUT', (int)(getenv('TTRS_CONNECT_TIMEOUT') ?: 2));

/**
 * Resolve a page key to its URL. Local pages resolve directly;
 * portal-level routes resolve to the configured TTRS web system.
 */
function page_url(string $name, array $query = []): string
{
    $localPages = [
        'dashboard'       => 'dashboard.php',
        'forgot_password' => 'forgot_password.php',
        'logout'          => 'logout.php',
    ];

    $remotePages = [
        'members'     => TTRS_WEB_BASE . '/members',
        'golfadmin'   => TTRS_WEB_BASE . '/golfadmin',
        'scheduler'   => TTRS_WEB_BASE . '/scheduler',
        'system_logs' => TTRS_WEB_BASE . '/system_logs',
        'autocancel'  => TTRS_WEB_BASE . '/autocancel',
        'add_member'  => TTRS_WEB_BASE . '/add_member',
    ];

    if (isset($localPages[$name])) {
        $path = $localPages[$name];
    } elseif (isset($remotePages[$name])) {
        $path = $remotePages[$name];
        if (!isset($query['user']) && !empty($_SESSION['user_id'])) {
            $query['user'] = $_SESSION['user_id'];
        }
    } else {
        $path = '#';
    }

    if (!empty($query)) {
        $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
    }
    return $path;
}

/* ============================================================
 *  API DATA LAYER
 * ============================================================ */

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

/**
 * Simple GET request helper (cURL) with short connect timeout to avoid
 * page hangs if the internal API server is unreachable.
 *
 * @param string $endpoint e.g. 'autocancel/json'
 * @param array  $query    query string params
 * @return array{success:bool,status:int,body:mixed,url:string,error:?string}
 */
function ttrsApiGet(string $endpoint, array $query = []): array
{
    $url = rtrim(TTRS_API_BASE, '/') . '/' . ltrim($endpoint, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => TTRS_API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => TTRS_CONNECT_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $raw     = curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $curlErr) {
        return ['success' => false, 'status' => $status, 'body' => null, 'url' => $url, 'error' => $curlErr ?: 'Request failed'];
    }

    $decoded = json_decode($raw, true);
    $success = $status >= 200 && $status < 300;

    return [
        'success' => $success,
        'status'  => $status,
        'body'    => $decoded,
        'url'     => $url,
        'error'   => $success ? null : 'HTTP ' . $status,
    ];
}

/**
 * Slice an array into a simple page window and return paging metadata,
 * mirroring Laravel's LengthAwarePaginator for our purposes.
 */
function ttrsPaginate(array $items, int $page, int $perPage): array
{
    $total    = count($items);
    $lastPage = max(1, (int) ceil($total / $perPage));
    $page     = max(1, min($page, $lastPage));
    $offset   = ($page - 1) * $perPage;

    return [
        'data'         => array_slice($items, $offset, $perPage),
        'current_page' => $page,
        'last_page'    => $lastPage,
        'per_page'     => $perPage,
        'total'        => $total,
    ];
}

/**
 * Build a pagination query string, preserving other GET params.
 */
function ttrsPageUrl(string $pageParam, int $pageNumber): string
{
    $params = $_GET;
    $params[$pageParam] = $pageNumber;
    return '?' . http_build_query($params);
}

$restrictions = [];
$teeTimeLogs  = [];
$apiRequests  = [];
$apiErrors    = [];

// --- AutoCancel API (Previous Day) ---
$cancelResult = ttrsApiGet('autocancel/json', [
    'startDate' => $yesterday,
    'endDate'   => $yesterday,
]);

if ($cancelResult['success']) {
    $restrictions = $cancelResult['body']['restrictions'] ?? [];
    $apiRequests[] = [
        'name'   => 'AutoCancel API',
        'url'    => $cancelResult['url'],
        'status' => $cancelResult['status'],
        'time'   => date('Y-m-d H:i:s'),
    ];
} else {
    $apiErrors[] = 'AutoCancel API error: ' . ($cancelResult['error'] ?? 'unknown error');
    error_log('AutoCancel API error: ' . ($cancelResult['error'] ?? 'unknown error'));
}

// --- TeeTimeLogs API (Today) ---
$logsResult = ttrsApiGet('teetimelogs/json', [
    'startDate' => $today,
    'endDate'   => $today,
]);

if ($logsResult['success']) {
    $teeTimeLogs = $logsResult['body']['messages'] ?? [];
    $apiRequests[] = [
        'name'   => 'TeeTimeLogs API',
        'url'    => $logsResult['url'],
        'status' => $logsResult['status'],
        'time'   => date('Y-m-d H:i:s'),
    ];
} else {
    $apiErrors[] = 'TeeTimeLogs API error: ' . ($logsResult['error'] ?? 'unknown error');
    error_log('TeeTimeLogs API error: ' . ($logsResult['error'] ?? 'unknown error'));
}

// --- Pagination (10 per page, matches the Laravel route) ---
$page      = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$logsPage  = isset($_GET['logs_page']) ? (int) $_GET['logs_page'] : 1;
$perPage   = 10;

$pagedRestrictions = ttrsPaginate($restrictions, $page, $perPage);
$pagedLogs          = ttrsPaginate($teeTimeLogs, $logsPage, $perPage);

/**
 * Small accessor helper: pulls the first matching key that exists in a row,
 * so we tolerate slightly different field names coming back from the API.
 */
function ttrsField(array $row, array $keys, string $default = '—'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return (string) $row[$key];
        }
    }
    return $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> — Dashboard</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        orchard: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            600: '#15803d',
                            700: '#0c6b37',
                            800: '#0a582d',
                            900: '#074222',
                        }
                    }
                }
            }
        };
    </script>
    <style>
        body { font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

        /* Ensure weather widget fits container neatly and expands to full container width */
        #weatherapi-weather-widget-5 {
            width: 100% !important;
            max-width: 100% !important;
            display: block !important;
        }
        #weatherapi-weather-widget-5 aside,
        #weatherapi-weather-widget-5 .widget,
        #weatherapi-weather-widget-5 .weatherapi-weather-wrap,
        #weatherapi-weather-widget-5 .weatherapi-weather-cover {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        #weatherapi-weather-widget-5 .weatherapi-weather-wrap {
            border-radius: 0.75rem !important;
            overflow: hidden !important;
        }
        #weatherapi-weather-widget-5 iframe {
            width: 100% !important;
            max-width: 100% !important;
            border-radius: 0.75rem !important;
        }
        #weatherapi-weather-widget-5 .weatherapi-weather-forecast {
            display: flex !important;
            justify-content: space-around !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
    </style>
</head>
<body class="bg-[#f4f6f8] text-[#1b1b18] min-h-screen flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">

                <!-- Left: Club Logo & Branding -->
                <div class="flex items-center gap-8">
                    <a href="<?= e(page_url('dashboard')) ?>" class="flex items-center gap-3 group focus:outline-none">
                        <img
                            src="<?= e(CLUB_LOGO) ?>"
                            alt="<?= e(CLUB_NAME) ?> Logo"
                            class="h-14 w-auto object-contain transition-transform group-hover:scale-105"
                            onerror="this.style.display='none'; document.getElementById('logo-fallback').style.display='block';"
                        >
                        <div id="logo-fallback" style="display:none;" class="font-bold text-orchard-700 tracking-wide text-sm leading-tight">
                            THE ORCHARD<br><span class="text-xs font-medium text-gray-500">GOLF & COUNTRY CLUB</span>
                        </div>
                    </a>

                    <!-- Main Navigation Menus -->
                    <nav class="hidden md:flex items-center space-x-8">
                        <!-- Dashboard (Active) -->
                        <a href="<?= e(page_url('dashboard')) ?>" class="relative text-gray-900 font-semibold text-sm tracking-wide py-2 inline-flex items-center after:content-[''] after:absolute after:bottom-[-22px] after:left-0 after:w-full after:h-[3px] after:bg-blue-600 after:rounded-t-full">
                            Dashboard
                        </a>

                        <!-- Members Profile -->
                        <a href="<?= e(page_url('members')) ?>" class="text-gray-600 hover:text-gray-900 font-medium text-sm tracking-wide transition-colors py-2">
                            Members Profile
                        </a>

                        <!-- Administration -->
                        <a href="<?= e(page_url('golfadmin')) ?>" class="text-gray-600 hover:text-gray-900 font-medium text-sm tracking-wide transition-colors py-2">
                            Administration
                        </a>

                        <!-- System Logs Dropdown -->
                        <div class="relative inline-block text-left" id="system-logs-dropdown-container">
                            <button
                                type="button"
                                id="system-logs-btn"
                                onclick="toggleMenu('system-logs-menu')"
                                class="text-gray-600 hover:text-gray-900 font-medium text-sm tracking-wide transition-colors py-2 inline-flex items-center gap-1.5 focus:outline-none"
                                aria-expanded="false"
                                aria-haspopup="true"
                            >
                                <span>System Logs</span>
                                <svg class="w-3.5 h-3.5 text-gray-500 transition-transform duration-200" id="system-logs-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>

                            <div
                                id="system-logs-menu"
                                class="hidden absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 divide-y divide-gray-100 z-50 focus:outline-none transition-all duration-150"
                                role="menu"
                            >
                                <div class="py-1">
                                    <a href="<?= e(page_url('system_logs')) ?>" class="text-gray-700 hover:bg-gray-50 group flex items-center px-4 py-2 text-sm" role="menuitem">
                                        <svg class="mr-3 h-4 w-4 text-gray-400 group-hover:text-orchard-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        System Activity
                                    </a>
                                    <!-- Filtered via a query param on system_logs.php — adjust the
                                         key/value to whatever that page actually expects. -->
                                    <a href="<?= e(page_url('system_logs', ['type' => 'autocancel'])) ?>" class="text-gray-700 hover:bg-gray-50 group flex items-center px-4 py-2 text-sm" role="menuitem">
                                        <svg class="mr-3 h-4 w-4 text-gray-400 group-hover:text-orchard-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Auto-Cancel Logs
                                    </a>
                                </div>
                                <div class="py-1">
                                    <a href="<?= e(page_url('system_logs', ['type' => 'login'])) ?>" class="text-gray-700 hover:bg-gray-50 group flex items-center px-4 py-2 text-sm" role="menuitem">
                                        <svg class="mr-3 h-4 w-4 text-gray-400 group-hover:text-orchard-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Login History
                                    </a>
                                </div>
                            </div>
                        </div>
                    </nav>
                </div>

                <!-- Right: User Profile Menu & Mobile Hamburger -->
                <div class="flex items-center gap-4">

                    <!-- User Dropdown -->
                    <div class="relative inline-block text-left" id="user-dropdown-container">
                        <button
                            type="button"
                            id="user-menu-btn"
                            onclick="toggleMenu('user-menu')"
                            class="flex items-center gap-2 text-gray-700 hover:text-gray-900 font-medium text-sm py-1.5 px-3 rounded-lg hover:bg-gray-50 transition-colors focus:outline-none"
                            aria-expanded="false"
                            aria-haspopup="true"
                        >
                            <span><?= e($displayName) ?></span>
                            <svg class="w-3.5 h-3.5 text-gray-500 transition-transform duration-200" id="user-menu-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>

                        <div
                            id="user-menu"
                            class="hidden absolute right-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 divide-y divide-gray-100 z-50 focus:outline-none"
                            role="menu"
                        >
                            <div class="px-4 py-3">
                                <p class="text-xs text-gray-500">Signed in as</p>
                                <p class="text-sm font-semibold text-gray-900 truncate"><?= e($currentUser) ?> (<?= $userRole ?>)</p>
                            </div>
                            <div class="py-1">
                                <a href="<?= e(page_url('forgot_password')) ?>" class="text-gray-700 hover:bg-gray-50 group flex items-center px-4 py-2 text-sm" role="menuitem">
                                    <svg class="mr-3 h-4 w-4 text-gray-400 group-hover:text-orchard-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                                    </svg>
                                    Reset Password
                                </a>
                                <a href="<?= e(DOC_URL) ?>" target="_blank" rel="noopener" class="text-gray-700 hover:bg-gray-50 group flex items-center px-4 py-2 text-sm" role="menuitem">
                                    <svg class="mr-3 h-4 w-4 text-gray-400 group-hover:text-orchard-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                    </svg>
                                    Documentation
                                </a>
                            </div>
                            <div class="py-1">
                                <a href="<?= e(page_url('logout')) ?>" class="text-red-700 hover:bg-red-50 group flex items-center px-4 py-2 text-sm font-medium" role="menuitem">
                                    <svg class="mr-3 h-4 w-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                    </svg>
                                    Log out
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile Menu Button -->
                    <button
                        type="button"
                        id="mobile-nav-btn"
                        onclick="toggleMenu('mobile-nav')"
                        class="md:hidden p-2 rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus:outline-none"
                    >
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Mobile Navigation Drawer -->
            <div id="mobile-nav" class="hidden md:hidden border-t border-gray-200 py-3 space-y-1">
                <a href="<?= e(page_url('dashboard')) ?>" class="block px-3 py-2 rounded-md text-base font-semibold text-blue-600 bg-blue-50">Dashboard</a>
                <a href="<?= e(page_url('members')) ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:bg-gray-50">Members Profile</a>
                <a href="<?= e(page_url('golfadmin')) ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:bg-gray-50">Administration</a>
                <div class="pt-2 pl-3 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">System Logs</div>
                <a href="<?= e(page_url('system_logs')) ?>" class="block px-3 py-1.5 pl-6 rounded-md text-sm text-gray-600 hover:bg-gray-50">System Activity</a>
                <a href="<?= e(page_url('system_logs', ['type' => 'autocancel'])) ?>" class="block px-3 py-1.5 pl-6 rounded-md text-sm text-gray-600 hover:bg-gray-50">Auto-Cancel Logs</a>
                <a href="<?= e(page_url('system_logs', ['type' => 'login'])) ?>" class="block px-3 py-1.5 pl-6 rounded-md text-sm text-gray-600 hover:bg-gray-50">Login History</a>
            </div>
        </div>
    </header>

    <!-- Main Content Body -->
    <main class="flex-1 max-w-[1400px] w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">

        <!-- Page Title -->
        <div class="mb-6">
            <h1 class="text-xl font-bold text-gray-900 tracking-tight">
                <?= e(APP_NAME) ?> Dashboard
            </h1>
        </div>

        <?php if (!empty($apiErrors)): ?>
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold mb-1">Some live data could not be loaded:</p>
            <ul class="list-disc list-inside space-y-0.5">
                <?php foreach ($apiErrors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Upper Section: Weather Widget & Auto-Cancellation Cards (50% / 50% split) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

            <!-- Left Card: Weather @ The Orchard (Covers 50% width of contained page) -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 flex flex-col justify-start">
                <div class="mb-4">
                    <h2 class="text-base font-bold text-gray-900 tracking-tight">Weather @ The Orchard</h2>
                </div>

                <!-- WeatherAPI Widget -->
                <div class="weather-widget-container w-full min-h-[220px]">
                    <div id="weatherapi-weather-widget-5"></div>
                    <script type='text/javascript' src='https://www.weatherapi.com/weather/widget.ashx?loc=1841642&wid=5&tu=1&div=weatherapi-weather-widget-5' async></script>
                    <noscript>
                        <a href="https://www.weatherapi.com/weather/q/dasmarinas-1841642" alt="Hour by hour Dasmarinas weather">10 day hour by hour Dasmarinas weather</a>
                    </noscript>
                </div>
            </div>

            <!-- Right Card: Auto Cancellation (Previous Date) -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 flex flex-col justify-start">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-bold text-gray-900 tracking-tight">
                        Auto Cancellation ( <span class="text-gray-700 font-semibold"><?= e($yesterday) ?></span> )
                    </h2>
                </div>

                <!-- Auto Cancellation Table -->
                <div class="overflow-x-auto rounded-md border border-gray-200">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-orchard-700 text-white text-xs font-semibold uppercase tracking-wider">
                                <th scope="col" class="py-2.5 px-4 text-center">Name</th>
                                <th scope="col" class="py-2.5 px-4 text-center">Reference No.</th>
                                <th scope="col" class="py-2.5 px-4 text-center">Date</th>
                                <th scope="col" class="py-2.5 px-4 text-center">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-gray-800 text-[13px]">
                            <?php if (empty($pagedRestrictions['data'])): ?>
                                <tr>
                                    <td colspan="4" class="py-6 px-4 text-center text-gray-400 italic">
                                        No auto-cancellations recorded for <?= e($yesterday) ?>.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pagedRestrictions['data'] as $row): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="py-2 px-4"><?= e(ttrsField($row, ['members_last_name'])) ?>, <?= e(ttrsField($row, ['members_first_name'], '')) ?></td>
                                        <td class="py-2 px-4 text-center"><?= e(ttrsField($row, ['teetime_reference_no'])) ?></td>
                                        <td class="py-2 px-4 text-center"><?= e(ttrsField($row, ['teetime_date'])) ?></td>
                                        <td class="py-2 px-4 text-center text-gray-600"><?= e(ttrsField($row, ['teetime_time'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pagedRestrictions['last_page'] > 1): ?>
                <div class="flex items-center justify-between mt-3 text-xs text-gray-500">
                    <span>Page <?= (int) $pagedRestrictions['current_page'] ?> of <?= (int) $pagedRestrictions['last_page'] ?> (<?= (int) $pagedRestrictions['total'] ?> total)</span>
                    <div class="flex gap-2">
                        <?php if ($pagedRestrictions['current_page'] > 1): ?>
                            <a class="inline-flex items-center justify-center h-7 w-7 rounded border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-gray-700" href="<?= e(ttrsPageUrl('page', $pagedRestrictions['current_page'] - 1)) ?>" aria-label="Previous page" title="Previous page">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                </svg>
                            </a>
                        <?php else: ?>
                            <span class="inline-flex items-center justify-center h-7 w-7 rounded border border-gray-100 text-gray-300 cursor-not-allowed" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                </svg>
                            </span>
                        <?php endif; ?>
                        <?php if ($pagedRestrictions['current_page'] < $pagedRestrictions['last_page']): ?>
                            <a class="inline-flex items-center justify-center h-7 w-7 rounded border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-gray-700" href="<?= e(ttrsPageUrl('page', $pagedRestrictions['current_page'] + 1)) ?>" aria-label="Next page" title="Next page">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                        <?php else: ?>
                            <span class="inline-flex items-center justify-center h-7 w-7 rounded border border-gray-100 text-gray-300 cursor-not-allowed" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Lower Section: Today's Teetime Reservation with Action Menus -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">

            <!-- Section Header & Action Buttons -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-4 border-b border-gray-100 mb-4">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 tracking-tight">
                    Today's (<span class="text-gray-700 font-semibold"><?= e($today) ?></span>) Teetime Reservation
                </h2>

                <!-- Action Button Menus -->
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        href="<?= e(TTRS_WEB_BASE) ?>/?user=<?= e($sessionUserId) ?>"
                        class="inline-flex items-center px-4 py-2 bg-orchard-700 hover:bg-orchard-800 text-white text-xs font-bold tracking-wider uppercase rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-orchard-600"
                    >
                        ADD MEMBERS
                    </a>

                    <!-- Legacy booking system link -->
                    <a
                        href="<?= e(TTRS_WEB_BASE) ?>/?user=<?= e($sessionUserId) ?>"
                        class="inline-flex items-center px-4 py-2 bg-orchard-700 hover:bg-orchard-800 text-white text-xs font-bold tracking-wider uppercase rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-orchard-600"
                    >
                        RESERVATIONS
                    </a>

                    <a
                        href="<?= e(TTRS_WEB_BASE) ?>/scheduler?user=<?= e($sessionUserId) ?>"
                        class="inline-flex items-center px-4 py-2 bg-orchard-700 hover:bg-orchard-800 text-white text-xs font-bold tracking-wider uppercase rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-orchard-600"
                    >
                        SCHEDULER
                    </a>

                    <a
                        href="<?= e(TTRS_WEB_BASE) ?>/autocancel?user=<?= e($sessionUserId) ?>"
                        class="inline-flex items-center px-4 py-2 bg-orchard-700 hover:bg-orchard-800 text-white text-xs font-bold tracking-wider uppercase rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-orchard-600"
                    >
                        AUTO-CANCEL
                    </a>
                </div>
            </div>

            <!-- SMS Inbox / Outbox Log Table -->
            <div class="overflow-x-auto rounded-md border border-gray-200">
                <table class="w-full table-fixed text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-orchard-700 text-white uppercase tracking-wider font-semibold">
                            <th class="py-2.5 px-3 border-r border-orchard-600 text-center w-32">Received Time</th>
                            <th class="py-2.5 px-3 border-r border-orchard-600 text-center w-36">Name</th>
                            <th class="py-2.5 px-3 border-r border-orchard-600 text-center w-20">Source</th>
                            <th class="py-2.5 px-3 border-r border-orchard-600 text-center w-28">Mobile No.</th>
                            <th class="py-2.5 px-3 border-r border-orchard-600 text-center w-28">Request Type</th>
                            <th class="py-2.5 px-3 border-r border-orchard-600 text-center w-20">Status</th>
                            <th class="py-2.5 px-3 border-r border-orchard-600">Message</th>
                            <th class="py-2.5 px-3">Reply</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-700">
                        <?php if (empty($pagedLogs['data'])): ?>
                            <tr>
                                <td colspan="8" class="py-6 px-3 text-center text-gray-400 italic">
                                    No logs found for <?= e($today) ?>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pagedLogs['data'] as $row):
                                $isDone = (int) ttrsField($row, ['outbox_status'], '0') === 1;
                                $statusLabel   = $isDone ? 'Done' : 'Pending';
                                $statusClasses = $isDone ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800';
                                $lastName  = ttrsField($row, ['members_last_name'], '');
                                $firstName = ttrsField($row, ['members_first_name'], '');
                                $fullName  = ($lastName !== '' && $firstName !== '')
                                    ? "{$lastName}, {$firstName}"
                                    : ($lastName !== '' ? $lastName : ($firstName !== '' ? $firstName : '—'));
                            ?>
                                <tr class="align-top hover:bg-gray-50 transition-colors">
                                    <td class="py-2 px-3 border-r border-gray-200"><?= e(ttrsField($row, ['inbox_timestamp'], '-')) ?></td>
                                    <td class="py-2 px-3 border-r border-gray-200"><?= e($fullName) ?></td>
                                    <td class="py-2 px-3 text-center border-r border-gray-200"><?= e(ttrsField($row, ['inbox_source'], '-')) ?></td>
                                    <td class="py-2 px-3 text-center border-r border-gray-200"><?= e(ttrsField($row, ['members_mobile_no_1'], '-')) ?></td>
                                    <td class="py-2 px-3 text-center border-r border-gray-200"><?= e(ttrsField($row, ['inbox_message_type'], '-')) ?></td>
                                    <td class="py-2 px-3 text-center border-r border-gray-200">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium <?= $statusClasses ?>">
                                            <?= e($statusLabel) ?>
                                        </span>
                                    </td>
                                    <td class="py-2 px-3 border-r border-gray-200 whitespace-normal break-words"><?= e(ttrsField($row, ['inbox_message'], '-')) ?></td>
                                    <td class="py-2 px-3 whitespace-normal break-words"><?= e(ttrsField($row, ['outbox_message'], '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pagedLogs['last_page'] > 1): ?>
            <div class="flex items-center justify-between mt-3 text-xs text-gray-500">
                <span>Page <?= (int) $pagedLogs['current_page'] ?> of <?= (int) $pagedLogs['last_page'] ?> (<?= (int) $pagedLogs['total'] ?> total)</span>
                <div class="flex gap-2">
                    <?php if ($pagedLogs['current_page'] > 1): ?>
                        <a class="inline-flex items-center justify-center h-7 w-7 rounded border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-gray-700" href="<?= e(ttrsPageUrl('logs_page', $pagedLogs['current_page'] - 1)) ?>" aria-label="Previous page" title="Previous page">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                        </a>
                    <?php else: ?>
                        <span class="inline-flex items-center justify-center h-7 w-7 rounded border border-gray-100 text-gray-300 cursor-not-allowed" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                        </span>
                    <?php endif; ?>
                    <?php if ($pagedLogs['current_page'] < $pagedLogs['last_page']): ?>
                        <a class="inline-flex items-center justify-center h-7 w-7 rounded border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-gray-700" href="<?= e(ttrsPageUrl('logs_page', $pagedLogs['current_page'] + 1)) ?>" aria-label="Next page" title="Next page">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    <?php else: ?>
                        <span class="inline-flex items-center justify-center h-7 w-7 rounded border border-gray-100 text-gray-300 cursor-not-allowed" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- JavaScript for Interactive Menus & Dropdowns -->
    <script>
        function toggleMenu(menuId) {
            const menu = document.getElementById(menuId);
            if (!menu) return;
            const isHidden = menu.classList.contains('hidden');

            // Close other open menus
            closeAllMenus();

            if (isHidden) {
                menu.classList.remove('hidden');

                // Update aria & arrow rotation
                if (menuId === 'system-logs-menu') {
                    document.getElementById('system-logs-btn')?.setAttribute('aria-expanded', 'true');
                    document.getElementById('system-logs-arrow')?.classList.add('rotate-180');
                } else if (menuId === 'user-menu') {
                    document.getElementById('user-menu-btn')?.setAttribute('aria-expanded', 'true');
                    document.getElementById('user-menu-arrow')?.classList.add('rotate-180');
                } else if (menuId === 'mobile-nav') {
                    document.getElementById('mobile-nav-btn')?.setAttribute('aria-expanded', 'true');
                }
            }
        }

        function closeAllMenus() {
            const menus = ['system-logs-menu', 'user-menu', 'mobile-nav'];
            menus.forEach(id => {
                const el = document.getElementById(id);
                if (el && !el.classList.contains('hidden')) {
                    el.classList.add('hidden');
                }
            });

            document.getElementById('system-logs-btn')?.setAttribute('aria-expanded', 'false');
            document.getElementById('system-logs-arrow')?.classList.remove('rotate-180');
            document.getElementById('user-menu-btn')?.setAttribute('aria-expanded', 'false');
            document.getElementById('user-menu-arrow')?.classList.remove('rotate-180');
            document.getElementById('mobile-nav-btn')?.setAttribute('aria-expanded', 'false');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const sysContainer = document.getElementById('system-logs-dropdown-container');
            const userContainer = document.getElementById('user-dropdown-container');
            const mobileBtn = document.getElementById('mobile-nav-btn');
            const mobileNav = document.getElementById('mobile-nav');

            if (!sysContainer?.contains(event.target) &&
                !userContainer?.contains(event.target) &&
                !mobileBtn?.contains(event.target) &&
                !mobileNav?.contains(event.target)) {
                closeAllMenus();
            }
        });
    </script>

</body>
</html>
