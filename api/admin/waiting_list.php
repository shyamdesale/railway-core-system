<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../lib/notifications.php';

$requestMethod = requireMethods(['GET', 'POST']);
requireAdmin();
$pdo = getPDO();

if ($requestMethod === 'GET') {
    jsonResponse(true, 'Waiting list fetched successfully.', [
        'waiting_list' => fetchAdminWaitingList($pdo, $_GET),
        'trains' => fetchAdminTrains($pdo),
    ]);
}

$input = getRequestData();
$action = strtolower(sanitizeString($input['action'] ?? 'promote'));
$bookingId = (int) ($input['booking_id'] ?? 0);

ensure($bookingId > 0, 'Valid booking_id is required.');
ensure(in_array($action, ['promote', 'manual_confirm'], true), 'Invalid waiting-list action.');

$result = confirmWaitingBookingById($pdo, $bookingId);
$notification = sendLifecycleEmailNotification($pdo, $bookingId, 'booking_promoted');

jsonResponse(true, 'Waiting-list user promoted successfully.', [
    'booking' => $result['booking'],
    'notification' => $notification,
    'waiting_list' => fetchAdminWaitingList($pdo),
]);
