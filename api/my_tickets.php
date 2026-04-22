<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

requireMethod('GET');

$user = requireLogin();
$pdo = getPDO();
$statusFilter = normalizeStatusFilter((string) ($_GET['status'] ?? 'ALL'));

$bookings = fetchBookingsForUser($pdo, (int) $user['id'], $statusFilter);

jsonResponse(true, 'Tickets fetched successfully.', [
    'bookings' => $bookings,
]);
