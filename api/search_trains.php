<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

requireMethod('POST');

$pdo = getPDO();
$input = getRequestData();

$source = sanitizeString($input['source'] ?? $input['from'] ?? '');
$destination = sanitizeString($input['destination'] ?? $input['to'] ?? '');
$travelDate = validateTravelDate((string) ($input['date'] ?? $input['travel_date'] ?? ''));

ensure($source !== '', 'Source is required.');
ensure($destination !== '', 'Destination is required.');

$source = ensureMaxStringLength($source, 100, 'Source');
$destination = ensureMaxStringLength($destination, 100, 'Destination');

$stmt = $pdo->prepare(
    'SELECT
        t.id,
        t.train_number,
        t.train_name,
        t.source,
        t.destination,
        t.departure_time,
        t.arrival_time,
        t.price,
        t.total_seats,
        t.sleeper_seats,
        t.ac3_seats,
        t.ac2_seats,
        t.ac1_seats
     FROM trains t
     WHERE LOWER(t.source) = LOWER(:source_match)
       AND LOWER(t.destination) = LOWER(:destination_match)
       AND t.is_active = 1
     ORDER BY t.departure_time ASC, t.train_name ASC'
);
$stmt->execute([
    'source_match' => $source,
    'destination_match' => $destination,
]);

$trainRows = $stmt->fetchAll();
$statsByTrain = getJourneyBookingStatsByClass($pdo, array_column($trainRows, 'id'), $travelDate);

$trains = [];
foreach ($trainRows as $row) {
    $trainId = (int) $row['id'];
    $classAvailability = buildTrainClassAvailability($row, $statsByTrain[$trainId] ?? []);
    $durationMinutes = getTravelDurationMinutes((string) $row['departure_time'], (string) $row['arrival_time']);

    $availableSeats = 0;
    $waitingCount = 0;
    foreach ($classAvailability as $classData) {
        $availableSeats += (int) $classData['available_seats'];
        $waitingCount += (int) $classData['waiting_count'];
    }

    $trains[] = [
        'id' => $trainId,
        'train_number' => $row['train_number'],
        'train_name' => $row['train_name'],
        'source' => $row['source'],
        'destination' => $row['destination'],
        'travel_date' => $travelDate,
        'departure_time' => $row['departure_time'],
        'arrival_time' => $row['arrival_time'],
        'departure_label' => formatTimeDisplay((string) $row['departure_time']),
        'arrival_label' => formatTimeDisplay((string) $row['arrival_time']),
        'duration_minutes' => $durationMinutes,
        'duration_label' => formatDurationLabel($durationMinutes),
        'base_price' => (float) $row['price'],
        'price_label' => formatCurrency((float) $row['price']),
        'total_seats' => getTrainTotalSeatLimit($row),
        'available_seats' => $availableSeats,
        'waiting_count' => $waitingCount,
        'booking_status' => $availableSeats > 0 ? 'AVAILABLE' : 'WAITING',
        'class_availability' => $classAvailability,
        'supported_ticket_types' => VALID_TICKET_TYPES,
    ];
}

jsonResponse(true, $trains === [] ? 'No trains found.' : 'Trains fetched successfully.', [
    'trains' => $trains,
    'meta' => [
        'route' => $source . ' to ' . $destination,
        'available_dates' => getRouteAvailableDates($pdo, $source, $destination, $travelDate),
    ],
]);
