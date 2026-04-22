<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

requireMethod('GET');
requireAdmin();

$pdo = getPDO();
$query = normalizeAdminSearchQuery($_GET['q'] ?? '');

if ($query === '') {
    jsonResponse(true, 'Search results loaded successfully.', [
        'query' => '',
        'results' => [
            'users' => [],
            'bookings' => [],
            'trains' => [],
        ],
    ]);
}

$queryLength = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
if ($queryLength < 2) {
    jsonResponse(true, 'Search results loaded successfully.', [
        'query' => $query,
        'results' => [
            'users' => [],
            'bookings' => [],
            'trains' => [],
        ],
    ]);
}

jsonResponse(true, 'Search results loaded successfully.', [
    'query' => $query,
    'results' => fetchAdminGlobalSearch($pdo, $query),
]);
