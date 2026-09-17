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

/**
 * Lightweight local .env parser (removes external composer dependency risk)
 */
function ttrsLoadEnv(string $filePath): void {
    if (!file_exists($filePath)) {
        return;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'\\");
            if (!array_key_exists($name, $_ENV) && getenv($name) === false) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

ttrsLoadEnv(__DIR__ . '/.env');

$currentUser   = !empty($_SESSION['username']) ? $_SESSION['username'] : 'admin';
$displayName   = !empty($_SESSION['display_name'])
    ? $_SESSION['display_name']
    : ($currentUser === 'admin' ? 'Jhong Admin' : ucfirst($currentUser));
$userRole      = !empty($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'Administrator';
$sessionUserId = $_SESSION['user_id'] ?? null;

/* ============================================================
 *  CONFIGURATION & PAGE LINKS (.env support enabled)
 * ============================================================ */

defined('TTRS_SERVER_IP') or define('TTRS_SERVER_IP', getenv('TTRS_SERVER_IP') ?: '128.168.64.102');
$defaultApiBase = 'http://' . TTRS_SERVER_IP . '/ttrs2_teetime_web_orchard/api';
$defaultWebBase = 'http://' . TTRS_SERVER_IP . '/ttrs2_teetime_web_orchard';

defined('TTRS_API_BASE') or define('TTRS_API_BASE', rtrim(getenv('TTRS_API_BASE') ?: $defaultApiBase, '/'));
defined('TTRS_WEB_BASE') or define('TTRS_WEB_BASE', rtrim(getenv('TTRS_WEB_BASE') ?: $defaultWebBase, '/'));
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
 *  API DATA LAYER & CONNECTION VALIDATION
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
 * Data Connection Validation check against the target API base/host.
 */
function ttrsValidateDataConnection(): array
{
    // Lightweight probe using a quick HEAD/GET against the base API or a known route probe
    $ch = curl_init(TTRS_API_BASE);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => TTRS_API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => TTRS_CONNECT_TIMEOUT,
    ]);
    curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return [
            'connected' => false,
            'server_ip' => TTRS_SERVER_IP,
            'api_base'  => TTRS_API_BASE,
            'message'   => "Cannot reach server (" . TTRS_SERVER_IP . "): {$curlErr}"
        ];
    }

    return [
        'connected' => true,
        'server_ip' => TTRS_SERVER_IP,
        'api_base'  => TTRS_API_BASE,
        'message'   => "Data connection verified on " . TTRS_SERVER_IP . " (HTTP {$status})"
    ];
}

$connectionStatus = ttrsValidateDataConnection();

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

if (!$connectionStatus['connected']) {
    $apiErrors[] = 'Connection validation failed: ' . $connectionStatus['message'];
}

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
    </style>
</head>
<body class="bg-[#f4f6f8] text-[#1b1b18] min-h-screen flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
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

                    <nav class="hidden md:flex items-center space-x-8">
                        <a href="<?= e(page_url('dashboard')) ?>" class="relative text-gray-900 font-semibold text-sm tracking-wide py-2 inline-flex items-center after:content-[''] after:absolute after:bottom-[-22px] after:left-0 after:w-full after:h-[3px] after:bg-blue-600 after:rounded-t-full">
                            Dashboard
                        </a>
                        <a href="<?= e(page_url('members')) ?>" class="text-gray-600 hover:text-gray-900 font-medium text-sm tracking-wide transition-colors py-2">
                            Members Profile
                        </a>
                        <a href="<?= e(page_url('golfadmin')) ?>" class="text-gray-600 hover:text-gray-900 font-medium text-sm tracking-wide transition-colors py-2">
                            Administration
                        </a>
                    </nav>
                </div>

                <div class="flex items-center gap-4">
                    <div class="hidden sm:flex items-center gap-2 text-xs font-medium px-2.5 py-1 rounded-full <?= $connectionStatus['connected'] ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
                        <span class="h-2 w-2 rounded-full <?= $connectionStatus['connected'] ? 'bg-green-500' : 'bg-red-500' ?>"></span>
                        Server: <?= e(TTRS_SERVER_IP) ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Body -->
    <main class="flex-1 max-w-[1400px] w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">

        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-900 tracking-tight">
                <?= e(APP_NAME) ?> Dashboard
            </h1>
        </div>

        <?php if (!empty($apiErrors)): ?>
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold mb-1">Live data warnings/errors:</p>
            <ul class="list-disc list-inside space-y-0.5">
                <?php foreach ($apiErrors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Upper Section: Weather Widget & Auto-Cancellation Cards -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 flex flex-col justify-start">
                <div class="mb-4">
                    <h2 class="text-base font-bold text-gray-900 tracking-tight">Weather @ The Orchard</h2>
                </div>
                <div class="weather-widget-container w-full min-h-[220px]">
                    <div id="weatherapi-weather-widget-5"></div>
                    <script type='text/javascript' src='https://www.weatherapi.com/weather/widget.ashx?loc=1841642&wid=5&tu=1&div=weatherapi-weather-widget-5' async></script>
                </div>
            </div>

            <!-- Auto Cancellation Card -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 flex flex-col justify-start">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-bold text-gray-900 tracking-tight">
                        Auto Cancellation ( <span class="text-gray-700 font-semibold"><?= e($yesterday) ?></span> )
                    </h2>
                </div>
                <div class="overflow-x-auto rounded-md border border-gray-200">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-orchard-700 text-white text-xs font-semibold uppercase tracking-wider">
                                <th class="py-2.5 px-4 text-center">Name</th>
                                <th class="py-2.5 px-4 text-center">Reference No.</th>
                                <th class="py-2.5 px-4 text-center">Date</th>
                                <th class="py-2.5 px-4 text-center">Time</th>
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
            </div>
        </div>

        <!-- Lower Section: Today's Teetime Reservation -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
            <div class="flex items-center justify-between pb-4 border-b border-gray-100 mb-4">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 tracking-tight">
                    Today's (<span class="text-gray-700 font-semibold"><?= e($today) ?></span>) Teetime Reservation
                </h2>
            </div>
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
        </div>

    </main>
</body>
</html>