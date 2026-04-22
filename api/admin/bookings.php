<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../lib/notifications.php';

function normalizeAdminBookingIds(mixed $value): array
{
    ensure(is_array($value), 'booking_ids must be an array.');

    $bookingIds = [];
    foreach ($value as $item) {
        $bookingId = (int) $item;
        if ($bookingId > 0) {
            $bookingIds[$bookingId] = $bookingId;
        }
    }

    ensure($bookingIds !== [], 'At least one valid booking_id is required.');

    return array_values($bookingIds);
}

function runAdminBulkBookingAction(PDO $pdo, array $bookingIds, string $action): array
{
    ensure(in_array($action, ['bulk_force_cancel', 'bulk_manual_confirm'], true), 'Invalid bulk booking action.');

    $summary = [
        'requested_count' => count($bookingIds),
        'processed_count' => 0,
        'skipped_count' => 0,
        'processed_ids' => [],
        'skipped' => [],
    ];

    foreach ($bookingIds as $bookingId) {
        try {
            if ($action === 'bulk_force_cancel') {
                $result = cancelBookingById($pdo, $bookingId, null);
                sendLifecycleEmailNotification($pdo, $bookingId, 'booking_cancelled', $result);

                if (!empty($result['promoted_booking']['id'])) {
                    sendLifecycleEmailNotification(
                        $pdo,
                        (int) $result['promoted_booking']['id'],
                        'booking_promoted'
                    );
                }
            } else {
                confirmWaitingBookingById($pdo, $bookingId);
                sendLifecycleEmailNotification($pdo, $bookingId, 'booking_promoted');
            }

            $summary['processed_count'] += 1;
            $summary['processed_ids'][] = $bookingId;
        } catch (Throwable $exception) {
            $summary['skipped_count'] += 1;
            $summary['skipped'][] = [
                'booking_id' => $bookingId,
                'message' => $exception->getMessage(),
            ];
        }
    }

    ensure(
        $summary['processed_count'] > 0,
        $summary['skipped'][0]['message'] ?? 'No bookings could be updated.',
        409,
        ['summary' => $summary]
    );

    return $summary;
}

$requestMethod = requireMethods(['GET', 'POST']);
$admin = requireAdmin();
$pdo = getPDO();

if ($requestMethod === 'GET') {
    $bookingCollection = fetchAdminBookingCollection($pdo, $_GET);

    jsonResponse(true, 'Admin bookings fetched successfully.', [
        'bookings' => $bookingCollection['bookings'],
        'pagination' => $bookingCollection['pagination'],
        'stats' => $bookingCollection['stats'],
        'trains' => fetchAdminTrains($pdo),
    ]);
}

$input = getRequestData();
$action = strtolower(sanitizeString($input['action'] ?? ''));
$bookingId = (int) ($input['booking_id'] ?? 0);

if (in_array($action, ['bulk_force_cancel', 'bulk_manual_confirm'], true)) {
    $bookingIds = normalizeAdminBookingIds($input['booking_ids'] ?? []);
    $summary = runAdminBulkBookingAction($pdo, $bookingIds, $action);
    $processedLabel = $action === 'bulk_force_cancel' ? 'cancelled' : 'confirmed';
    $message = sprintf(
        'Bulk action completed: %d bookings %s.',
        $summary['processed_count'],
        $processedLabel
    );

    if ($summary['skipped_count'] > 0) {
        $message .= sprintf(' %d skipped.', $summary['skipped_count']);
    }

    jsonResponse(true, $message, [
        'summary' => $summary,
        'bookings' => fetchAdminBookings($pdo),
    ]);
}

ensure($bookingId > 0, 'Valid booking_id is required.');

if ($action === 'force_cancel') {
    $result = cancelBookingById($pdo, $bookingId, null);
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

    jsonResponse(true, 'Booking cancelled by admin successfully.', [
        'cancelled_booking' => $result['cancelled_booking'],
        'promoted_booking' => $result['promoted_booking'],
        'notification' => $notification,
        'bookings' => fetchAdminBookings($pdo),
    ]);
}

if ($action === 'manual_confirm') {
    $result = confirmWaitingBookingById($pdo, $bookingId);
    $notification = sendLifecycleEmailNotification($pdo, $bookingId, 'booking_promoted');

    jsonResponse(true, 'Waiting booking confirmed successfully.', [
        'booking' => $result['booking'],
        'notification' => $notification,
        'bookings' => fetchAdminBookings($pdo),
    ]);
}

throw new ApiException('Invalid admin booking action.', 422);
