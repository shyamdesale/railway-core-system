<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/notifications.php';

requireMethod('POST');

$user = requireLogin();
$pdo = getPDO();
$input = getRequestData();

$trainId = (int) ($input['train_id'] ?? 0);
$travelDate = validateTravelDate((string) ($input['travel_date'] ?? $input['date'] ?? ''));
$passengerName = sanitizeString($input['passenger_name'] ?? $input['name'] ?? $user['name'] ?? '');
$age = normalizeAge($input['age'] ?? null);
$gender = normalizeGender($input['gender'] ?? 'O');
$ticketType = normalizeTicketType($input['ticket_type'] ?? 'Sleeper');
$requestToken = sanitizeString($input['request_token'] ?? '');

ensure($trainId > 0, 'Valid train_id is required.');
ensure($passengerName !== '', 'Passenger name is required.');
ensure($requestToken !== '', 'request_token is required.');

$passengerName = ensureMaxStringLength($passengerName, 120, 'Passenger name');
$requestToken = ensureMaxStringLength($requestToken, 36, 'request_token');

$lockName = acquireJourneyLock($pdo, $trainId, $travelDate);
$booking = null;
$message = 'Ticket booked successfully.';
$notification = null;

try {
    $pdo->beginTransaction();

    $train = getTrainById($pdo, $trainId, true);
    ensure($train !== null, 'Train not found.', 404);
    ensure((int) $train['is_active'] === 1, 'This train is not available for booking.', 409);

    $existingByToken = findBookingByRequestToken($pdo, $requestToken);
    ensure(
        $existingByToken === null,
        'Duplicate booking request detected. Please generate a new request_token for a new booking.',
        409,
        ['existing_booking_id' => $existingByToken['id'] ?? null, 'existing_pnr' => $existingByToken['pnr'] ?? null]
    );

    $classSeatLimit = getTrainClassSeatLimit($train, $ticketType);
    ensure($classSeatLimit > 0, 'The selected class is not available on this train.', 409);

    $confirmedCount = countJourneyBookingsByStatus($pdo, $trainId, $travelDate, 'CONFIRMED', $ticketType);
    $price = calculateTicketPrice((float) $train['price'], $ticketType);
    $pnr = createUniquePnr($pdo, $trainId, $travelDate);

    $insertBooking = $pdo->prepare(
        'INSERT INTO bookings (
            user_id,
            train_id,
            travel_date,
            passenger_name,
            age,
            gender,
            ticket_type,
            price,
            seat_number,
            pnr,
            request_token,
            status,
            created_at,
            updated_at
        ) VALUES (
            :user_id,
            :train_id,
            :travel_date,
            :passenger_name,
            :age,
            :gender,
            :ticket_type,
            :price,
            :seat_number,
            :pnr,
            :request_token,
            :status,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )'
    );

    if ($confirmedCount < $classSeatLimit) {
        $seatNumber = getNextAvailableSeatNumber($pdo, $trainId, $travelDate, $ticketType, $classSeatLimit);
        ensure($seatNumber !== null, 'No confirmed seat is available right now. Please retry.', 409);

        $insertBooking->execute([
            'user_id' => (int) $user['id'],
            'train_id' => $trainId,
            'travel_date' => $travelDate,
            'passenger_name' => $passengerName,
            'age' => $age,
            'gender' => $gender,
            'ticket_type' => $ticketType,
            'price' => $price,
            'seat_number' => $seatNumber,
            'pnr' => $pnr,
            'request_token' => $requestToken,
            'status' => 'CONFIRMED',
        ]);

        $booking = loadBookingById($pdo, (int) $pdo->lastInsertId());
        $message = 'Ticket booked successfully (CONFIRMED).';
    } else {
        $insertBooking->execute([
            'user_id' => (int) $user['id'],
            'train_id' => $trainId,
            'travel_date' => $travelDate,
            'passenger_name' => $passengerName,
            'age' => $age,
            'gender' => $gender,
            'ticket_type' => $ticketType,
            'price' => $price,
            'seat_number' => null,
            'pnr' => $pnr,
            'request_token' => $requestToken,
            'status' => 'WAITING',
        ]);

        $bookingId = (int) $pdo->lastInsertId();
        $queueNo = getNextWaitingQueueNo($pdo, $trainId, $travelDate, $ticketType);

        createWaitingListEntry($pdo, $bookingId, $queueNo);

        $booking = loadBookingById($pdo, $bookingId);
        $message = 'Train is full. Booking added to the waiting list.';
    }

    ensure($booking !== null, 'Booking could not be loaded after creation.', 500);
    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($exception->getCode() === '23000') {
        $existingByToken = findBookingByRequestToken($pdo, $requestToken);
        if ($existingByToken !== null) {
            throw new ApiException(
                'Duplicate booking request detected. Please generate a new request_token for a new booking.',
                409,
                ['existing_booking_id' => $existingByToken['id'] ?? null, 'existing_pnr' => $existingByToken['pnr'] ?? null],
                $exception
            );
        } else {
            throw new ApiException('Booking conflict detected. Please retry.', 409, [], $exception);
        }
    } else {
        throw $exception;
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $exception;
} finally {
    releaseJourneyLock($pdo, $lockName);
}

ensure($booking !== null, 'Unable to process booking.', 500);

if ($booking !== null) {
    $notificationEvent = $booking['status'] === 'CONFIRMED' ? 'booking_confirmed' : 'booking_waiting';
    $notification = sendLifecycleEmailNotification($pdo, (int) $booking['id'], $notificationEvent);
}

jsonResponse(true, $message, [
    'booking' => $booking,
    'notification' => $notification,
]);
