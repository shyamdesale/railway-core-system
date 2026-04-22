<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

requireMethod('GET');

requireAdmin();
$pdo = getPDO();

jsonResponse(true, 'Admin dashboard stats fetched successfully.', [
    'stats' => fetchAdminDashboardStats($pdo),
]);
