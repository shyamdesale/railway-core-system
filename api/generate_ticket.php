<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/ticketing.php';

startSessionIfNeeded();
ob_start();

function fail(string $message, int $statusCode = 400, array $data = []): void
{
    jsonResponse(false, $message, $data, $statusCode);
}

try {
    $sessionUser = currentUser();
    ensure($sessionUser !== null, 'Login required.', 401);

    $userId = (int) ($sessionUser['id'] ?? 0);
    $isAdmin = (($sessionUser['role'] ?? 'USER') === 'ADMIN');
    $pnr = ensureMaxStringLength(trim((string) ($_GET['pnr'] ?? '')), 20, 'PNR');
    ensure($pnr !== '', 'PNR is required.');

    $pdo = getPDO();
    $ticketDocument = renderTicketPdfDocument($pdo, 0, $pnr, $userId, $isAdmin, false);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $ticketDocument['filename'] . '"');
    echo $ticketDocument['binary'];
    exit;
} catch (ApiException $exception) {
    fail($exception->getMessage(), $exception->getStatusCode(), $exception->getData());
} catch (Throwable $exception) {
    logSystemEvent('ticket.error', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);
    fail($exception->getMessage() !== '' ? $exception->getMessage() : 'Ticket generation failed.', 500);
}
