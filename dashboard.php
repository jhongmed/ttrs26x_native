<?php
/**
 * TTRS 2.6.x - Dashboard (placeholder)
 * Replace this with your real dashboard content.
 */

session_start();
require_once __DIR__ . '/auth_common.php';

// Require login
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> — Dashboard</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'media' };
    </script>
    <style>
        body { font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif; }
    </style>
</head>
<body class="bg-gray-100 dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-[#EDEDEC] min-h-screen p-6 lg:p-8">

    <header class="flex items-center justify-between max-w-4xl mx-auto mb-8">
        <h1 class="font-medium text-lg"><?= e(APP_TITLE) ?></h1>
        <a href="logout.php" class="inline-block px-5 py-1.5 border border-[#19140035] dark:border-[#3E3E3A] hover:border-[#19140055] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal">
            Log out
        </a>
    </header>

    <main class="max-w-4xl mx-auto">
        <div class="rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] p-8 text-[13px] leading-[20px]">
            <h2 class="text-base font-medium mb-2">Welcome, <?= e($_SESSION['username']) ?> 👋</h2>
            <p class="text-[#706f6c] dark:text-[#A1A09A]">
                You're signed in as <strong><?= e($_SESSION['role']) ?></strong>. This is a placeholder dashboard —
                replace this page with your actual tee-time reservation UI.
            </p>
        </div>
    </main>

</body>
</html>
