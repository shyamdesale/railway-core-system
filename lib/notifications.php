<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/helpers.php';
require_once __DIR__ . '/ticketing.php';

const MAIL_LOG_FILE = __DIR__ . '/../logs/mail.log';
const MAIL_OUTBOX_DIR = __DIR__ . '/../logs/mail_outbox';

function ensureMailStorage(): void
{
    if (!is_dir(dirname(MAIL_LOG_FILE))) {
        mkdir(dirname(MAIL_LOG_FILE), 0775, true);
    }

    if (!file_exists(MAIL_LOG_FILE)) {
        touch(MAIL_LOG_FILE);
    }

    if (!is_dir(MAIL_OUTBOX_DIR)) {
        mkdir(MAIL_OUTBOX_DIR, 0775, true);
    }
}

function mailSlug(string $value): string
{
    $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($value))) ?? 'mail';
    $slug = trim($slug, '_');

    return $slug !== '' ? $slug : 'mail';
}

function buildLifecycleEmailContent(array $booking, string $eventType, array $context = []): array
{
    $queueLabel = isset($booking['queue_no']) && $booking['queue_no'] !== null ? 'WL' . (int) $booking['queue_no'] : null;
    $statusLabel = ($booking['status'] ?? '') === 'WAITING'
        ? ($queueLabel ?? 'WAITING')
        : (($booking['status'] ?? '') === 'CONFIRMED' ? 'CONFIRMED' : 'CANCELLED');

    $headline = 'IndianRail reservation update';
    $subject = 'IndianRail reservation update - PNR ' . ($booking['pnr'] ?? '');
    $summary = 'Your reservation details have been updated.';
    $attachPdf = false;

    if ($eventType === 'booking_confirmed') {
        $headline = 'Booking confirmed';
        $subject = 'IndianRail booking confirmed - PNR ' . ($booking['pnr'] ?? '');
        $summary = 'Your booking is confirmed and your PDF ticket is attached.';
        $attachPdf = true;
    } elseif ($eventType === 'booking_waiting') {
        $headline = 'Waiting list booking created';
        $subject = 'IndianRail waiting list update - PNR ' . ($booking['pnr'] ?? '');
        $summary = 'Your booking has been added to the waiting list and the PDF ticket is attached.';
        $attachPdf = true;
    } elseif ($eventType === 'booking_promoted') {
        $headline = 'Waiting ticket promoted to confirmed';
        $subject = 'IndianRail waiting ticket promoted - PNR ' . ($booking['pnr'] ?? '');
        $summary = 'Good news. Your waiting list ticket has been promoted to confirmed and the updated PDF ticket is attached.';
        $attachPdf = true;
    } elseif ($eventType === 'booking_cancelled') {
        $headline = 'Booking cancelled';
        $subject = 'IndianRail booking cancelled - PNR ' . ($booking['pnr'] ?? '');
        $summary = 'Your booking has been cancelled successfully.';
    }

    $extraNotice = '';
    if (!empty($context['promoted_booking'])) {
        $extraNotice = sprintf(
            '<p>A waiting-list passenger was promoted to confirmed after this cancellation. New PNR: <strong>%s</strong>.</p>',
            ticketSafe((string) ($context['promoted_booking']['pnr'] ?? ''))
        );
    }

    $html = '<html><body style="font-family: Arial, sans-serif; color: #17301d;">'
        . '<h2>' . ticketSafe($headline) . '</h2>'
        . '<p>' . ticketSafe($summary) . '</p>'
        . '<table cellpadding="8" cellspacing="0" style="border-collapse: collapse; border: 1px solid #d6e5d8;">'
        . '<tr><td><strong>Passenger</strong></td><td>' . ticketSafe((string) ($booking['passenger_name'] ?? '')) . '</td></tr>'
        . '<tr><td><strong>PNR</strong></td><td>' . ticketSafe((string) ($booking['pnr'] ?? '')) . '</td></tr>'
        . '<tr><td><strong>Train</strong></td><td>' . ticketSafe((string) ($booking['train_name'] ?? '')) . ' (' . ticketSafe((string) ($booking['train_number'] ?? '')) . ')</td></tr>'
        . '<tr><td><strong>Route</strong></td><td>' . ticketSafe((string) ($booking['source'] ?? '')) . ' to ' . ticketSafe((string) ($booking['destination'] ?? '')) . '</td></tr>'
        . '<tr><td><strong>Travel Date</strong></td><td>' . ticketSafe((string) ($booking['travel_date'] ?? '')) . '</td></tr>'
        . '<tr><td><strong>Ticket Type</strong></td><td>' . ticketSafe((string) ($booking['ticket_type'] ?? '')) . '</td></tr>'
        . '<tr><td><strong>Status</strong></td><td>' . ticketSafe($statusLabel) . '</td></tr>'
        . '<tr><td><strong>Seat / WL</strong></td><td>' . ticketSafe(
            ($booking['status'] ?? '') === 'CONFIRMED'
                ? (string) ($booking['seat_number'] ?? 'N/A')
                : ($queueLabel ?? 'N/A')
        ) . '</td></tr>'
        . '<tr><td><strong>Fare</strong></td><td>' . ticketSafe(formatCurrency((float) ($booking['price'] ?? 0))) . '</td></tr>'
        . '</table>'
        . $extraNotice
        . '<p style="margin-top: 16px;">Thank you for choosing IndianRail.</p>'
        . '</body></html>';

    return [
        'subject' => $subject,
        'html' => $html,
        'attach_pdf' => $attachPdf,
    ];
}

function dispatchEmailMessage(string $to, string $subject, string $htmlBody, ?array $attachment = null): array
{
    ensureMailStorage();

    if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        $payload = [
            'status' => 'skipped',
            'reason' => 'Missing valid recipient email.',
            'recipient' => $to,
        ];
        logSystemEvent('mail', $payload);

        return $payload;
    }

    $boundary = 'minirail_' . bin2hex(random_bytes(12));
    $headers = [
        'From: MiniRail <no-reply@minirail.local>',
        'MIME-Version: 1.0',
    ];

    if ($attachment !== null) {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $message = '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Type: ' . ($attachment['content_type'] ?? 'application/octet-stream') . '; name="' . ($attachment['filename'] ?? 'attachment.pdf') . '"' . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; filename="' . ($attachment['filename'] ?? 'attachment.pdf') . '"' . "\r\n\r\n"
            . chunk_split(base64_encode((string) ($attachment['content'] ?? '')))
            . '--' . $boundary . "--\r\n";
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $message = $htmlBody;
    }

    $mailSent = false;
    if (function_exists('mail')) {
        $mailSent = @mail($to, $subject, $message, implode("\r\n", $headers));
    }

    if ($mailSent) {
        $payload = [
            'status' => 'sent',
            'recipient' => $to,
            'subject' => $subject,
        ];
        logSystemEvent('mail', $payload);

        return $payload;
    }

    $reference = date('Ymd_His') . '_' . mailSlug($to) . '_' . bin2hex(random_bytes(4));
    $basePath = MAIL_OUTBOX_DIR . '/' . $reference;
    file_put_contents($basePath . '.html', $htmlBody);
    if ($attachment !== null && isset($attachment['content'])) {
        file_put_contents($basePath . '.pdf', (string) $attachment['content']);
    }
    file_put_contents($basePath . '.meta.json', json_encode([
        'to' => $to,
        'subject' => $subject,
        'headers' => $headers,
        'attachment' => $attachment !== null ? ($attachment['filename'] ?? 'attachment.pdf') : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $payload = [
        'status' => 'logged',
        'recipient' => $to,
        'subject' => $subject,
        'reference' => $reference,
    ];
    logSystemEvent('mail', $payload);

    return $payload;
}

function sendLifecycleEmailNotification(PDO $pdo, int $bookingId, string $eventType, array $context = []): array
{
    try {
        $booking = fetchTicketBookingRecord($pdo, $bookingId, '', null, true);
        if ($booking === null) {
            return [
                'status' => 'skipped',
                'reason' => 'Booking not found for email notification.',
            ];
        }

        $content = buildLifecycleEmailContent($booking, $eventType, $context);
        $attachment = null;

        if ($content['attach_pdf']) {
            $ticketDocument = renderTicketPdfDocument($pdo, $bookingId, '', null, true, true);
            $attachment = [
                'filename' => $ticketDocument['filename'],
                'content' => $ticketDocument['binary'],
                'content_type' => 'application/pdf',
            ];
        }

        return dispatchEmailMessage(
            (string) ($booking['user_email'] ?? ''),
            (string) $content['subject'],
            (string) $content['html'],
            $attachment
        );
    } catch (Throwable $exception) {
        logSystemEvent('mail_error', [
            'booking_id' => $bookingId,
            'event_type' => $eventType,
            'message' => $exception->getMessage(),
        ]);

        return [
            'status' => 'error',
            'reason' => 'Email delivery failed and was logged.',
        ];
    }
}
