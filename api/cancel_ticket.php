<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/notifications.php';

requireMethod('POST');

$user = requireLogin();
$pdo = getPDO();
$input = getRequestData();

$bookingId = (int) ($input['booking_id'] ?? 0);
$pnr = sanitizeString($input['pnr'] ?? '');

ensure($bookingId > 0 || $pnr !== '', 'booking_id or pnr is required.');

if ($bookingId <= 0) {
    $bookingReference = findBookingReference($pdo, 0, $pnr, (int) $user['id']);
    ensure($bookingReference !== null, 'Booking not found.', 404);
    $bookingId = (int) $bookingReference['id'];
}

$result = cancelBookingById($pdo, $bookingId, (int) $user['id']);

$notification = [
    'cancelled' => sendLifecycleEmailNotification($pdo, $bookingId, 'booking_cancelled', $result),
];

if (!empty($result['promoted_booking']['id'])) {
    $notification['promoted'] = sendLifecycleEmailNotification(
        $pdo,
        (int) $result['promoted_booking']['id'],
        'booking_promoted'
    );
}

jsonResponse(true, 'Ticket cancelled successfully.', [
    'cancelled_booking' => $result['cancelled_booking'],
    'promoted_booking' => $result['promoted_booking'],
    'notification' => $notification,
]);
