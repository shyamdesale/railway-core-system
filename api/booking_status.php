<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

requireMethod('GET');

$user = requireLogin();
$pdo = getPDO();

$bookingId = (int) ($_GET['booking_id'] ?? 0);
$pnr = sanitizeString($_GET['pnr'] ?? '');

$bookings = fetchBookingsForUser($pdo, (int) $user['id'], null, $bookingId, $pnr);

jsonResponse(true, 'Booking status fetched successfully.', [
    'bookings' => $bookings,
    'updated_at' => date('c'),
]);
