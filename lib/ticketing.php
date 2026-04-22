<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;

function ticketSafe(mixed $value, string $default = 'N/A'): string
{
    $stringValue = trim((string) ($value ?? ''));
    if ($stringValue === '') {
        $stringValue = $default;
    }

    return htmlspecialchars($stringValue, ENT_QUOTES, 'UTF-8');
}

function ticketFormatTimeDisplay(string $time): string
{
    $date = DateTimeImmutable::createFromFormat('H:i:s', $time);

    return $date ? $date->format('h:i A') : $time;
}

function ticketGetTravelDurationMinutes(string $departureTime, string $arrivalTime): int
{
    $departure = DateTimeImmutable::createFromFormat('H:i:s', $departureTime);
    $arrival = DateTimeImmutable::createFromFormat('H:i:s', $arrivalTime);

    if (!$departure || !$arrival) {
        return 0;
    }

    if ($arrival < $departure) {
        $arrival = $arrival->modify('+1 day');
    }

    return (int) (($arrival->getTimestamp() - $departure->getTimestamp()) / 60);
}

function ticketFormatDurationLabel(int $minutes): string
{
    $hours = intdiv(max(0, $minutes), 60);
    $remainingMinutes = max(0, $minutes % 60);

    return sprintf('%dh %02dm', $hours, $remainingMinutes);
}

function fetchTicketBookingRecord(
    PDO $pdo,
    int $bookingId = 0,
    string $pnr = '',
    ?int $userId = null,
    bool $allowAdminScope = false
): ?array {
    if ($bookingId <= 0 && $pnr === '') {
        return null;
    }

    $sql = 'SELECT
                b.id,
                b.user_id,
                b.travel_date,
                b.passenger_name,
                b.age,
                b.gender,
                b.ticket_type,
                b.price,
                b.seat_number,
                b.pnr,
                b.status,
                b.created_at,
                b.updated_at,
                b.cancelled_at,
                u.name AS user_name,
                u.email AS user_email,
                u.phone AS user_phone,
                t.train_number,
                t.train_name,
                t.source,
                t.destination,
                t.departure_time,
                t.arrival_time,
                wl.queue_no
            FROM bookings b
            INNER JOIN users u ON u.id = b.user_id
            INNER JOIN trains t ON t.id = b.train_id
            LEFT JOIN waiting_list wl ON wl.booking_id = b.id
            WHERE ';
    $params = [];

    if ($bookingId > 0) {
        $sql .= 'b.id = :booking_id';
        $params['booking_id'] = $bookingId;
    } else {
        $sql .= 'b.pnr = :pnr';
        $params['pnr'] = $pnr;
    }

    if ($userId !== null && !$allowAdminScope) {
        $sql .= ' AND b.user_id = :user_id';
        $params['user_id'] = $userId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    return $booking ?: null;
}

function buildTicketPdfData(array $booking): array
{
    $queueLabel = null;
    if (($booking['status'] ?? '') === 'WAITING' && isset($booking['queue_no']) && $booking['queue_no'] !== null) {
        $queueLabel = 'WL' . (int) $booking['queue_no'];
    }

    $journeyDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($booking['travel_date'] ?? ''));
    $departureTime = DateTimeImmutable::createFromFormat('H:i:s', (string) ($booking['departure_time'] ?? ''));
    $arrivalTime = DateTimeImmutable::createFromFormat('H:i:s', (string) ($booking['arrival_time'] ?? ''));

    $departureLabel = $journeyDate && $departureTime
        ? $journeyDate->setTime((int) $departureTime->format('H'), (int) $departureTime->format('i'))->format('d M Y h:i A')
        : 'N/A';

    $arrivalLabel = 'N/A';
    if ($journeyDate && $arrivalTime) {
        $arrivalDate = $journeyDate->setTime((int) $arrivalTime->format('H'), (int) $arrivalTime->format('i'));
        if ($departureTime && $arrivalTime < $departureTime) {
            $arrivalDate = $arrivalDate->modify('+1 day');
        }
        $arrivalLabel = $arrivalDate->format('d M Y h:i A');
    }

    $statusText = (string) ($booking['status'] ?? 'CONFIRMED');
    $seatValue = 'N/A';
    if ($statusText === 'CONFIRMED') {
        $statusText = 'CNF';
        $seatValue = (string) ($booking['seat_number'] ?? 'N/A');
    } elseif ($statusText === 'WAITING') {
        $statusText = $queueLabel ?? 'WL';
        $seatValue = $statusText;
    } elseif ($statusText === 'CANCELLED') {
        $seatValue = 'Cancelled';
    }

    $baseFare = (float) ($booking['price'] ?? 0);
    $convenienceFee = 11.80;
    $insurance = 0.45;
    $totalFare = $baseFare + $convenienceFee + $insurance;
    $coachLabel = ($booking['status'] ?? '') === 'CONFIRMED' ? (string) ($booking['ticket_type'] ?? '-') : '-';

    return [
        'pnr' => ticketSafe($booking['pnr'] ?? ''),
        'status' => ticketSafe($statusText),
        'source' => ticketSafe($booking['source'] ?? ''),
        'destination' => ticketSafe($booking['destination'] ?? ''),
        'departure' => ticketSafe($departureLabel),
        'arrival' => ticketSafe($arrivalLabel),
        'train' => ticketSafe($booking['train_name'] ?? ''),
        'train_no' => ticketSafe($booking['train_number'] ?? ''),
        'class' => ticketSafe($booking['ticket_type'] ?? ''),
        'date' => ticketSafe($journeyDate ? $journeyDate->format('d M Y') : (string) ($booking['travel_date'] ?? '')),
        'name' => ticketSafe($booking['passenger_name'] ?? ''),
        'age' => ticketSafe(isset($booking['age']) && $booking['age'] !== null ? (string) $booking['age'] : ''),
        'gender' => ticketSafe($booking['gender'] ?? ''),
        'seat' => ticketSafe($seatValue),
        'fare' => $baseFare,
        'price' => number_format($totalFare, 2, '.', ''),
        'booking_date' => ticketSafe((new DateTimeImmutable((string) ($booking['created_at'] ?? 'now')))->format('d-M-Y H:i')),
        'passengers' => [[
            'name' => ticketSafe($booking['passenger_name'] ?? ''),
            'age' => ticketSafe(isset($booking['age']) && $booking['age'] !== null ? (string) $booking['age'] : ''),
            'gender' => ticketSafe($booking['gender'] ?? ''),
            'booking_status' => ticketSafe($statusText),
            'current_status' => ticketSafe($statusText),
            'coach' => ticketSafe($coachLabel),
            'berth' => ticketSafe($seatValue),
            'berth_type' => ($booking['status'] ?? '') === 'CONFIRMED' ? 'Seat' : '-',
            'food' => 'No',
        ]],
        'email' => ticketSafe($booking['user_email'] ?? ''),
        'mobile' => ticketSafe($booking['user_phone'] ?? ''),
        'convenience_fee' => $convenienceFee,
        'insurance' => $insurance,
        'total_fare' => $totalFare,
        'generated_at' => date('d-M-Y H:i:s'),
        'duration_label' => ticketFormatDurationLabel(
            ticketGetTravelDurationMinutes((string) ($booking['departure_time'] ?? ''), (string) ($booking['arrival_time'] ?? ''))
        ),
        'departure_label' => ticketFormatTimeDisplay((string) ($booking['departure_time'] ?? '')),
        'arrival_label' => ticketFormatTimeDisplay((string) ($booking['arrival_time'] ?? '')),
        'waiting_label' => $queueLabel,
    ];
}

function renderTicketHtml(array $pdfData): string
{
    ob_start();
    $data = $pdfData;
    include __DIR__ . '/../views/ticket_template.php';
    $html = ob_get_clean();

    if ($html === false || trim($html) === '') {
        throw new RuntimeException('Ticket template rendering failed.');
    }

    return $html;
}

function renderTicketPdfBinary(array $pdfData): string
{
    $dompdf = new Dompdf(['isRemoteEnabled' => true]);
    $dompdf->loadHtml(renderTicketHtml($pdfData), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

function renderTicketPdfDocument(
    PDO $pdo,
    int $bookingId = 0,
    string $pnr = '',
    ?int $userId = null,
    bool $allowAdminScope = false,
    bool $allowCancelled = false
): array {
    $booking = fetchTicketBookingRecord($pdo, $bookingId, $pnr, $userId, $allowAdminScope);
    if ($booking === null) {
        throw new RuntimeException('Ticket not found.');
    }

    if (($booking['status'] ?? '') === 'CANCELLED' && !$allowCancelled) {
        throw new RuntimeException('Ticket is cancelled.');
    }

    $pdfData = buildTicketPdfData($booking);

    return [
        'booking' => $booking,
        'pdf_data' => $pdfData,
        'filename' => 'ERS_' . rawurlencode((string) $booking['pnr']) . '.pdf',
        'binary' => renderTicketPdfBinary($pdfData),
    ];
}
