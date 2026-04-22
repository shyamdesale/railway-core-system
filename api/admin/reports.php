<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

requireMethod('GET');

requireAdmin();
$pdo = getPDO();

jsonResponse(true, 'Reports fetched successfully.', [
    'reports' => fetchAdminReports($pdo, $_GET),
    'trains' => fetchAdminTrains($pdo),
]);
