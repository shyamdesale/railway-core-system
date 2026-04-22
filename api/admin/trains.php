<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$requestMethod = requireMethods(['GET', 'POST']);
requireAdmin();
$pdo = getPDO();

if ($requestMethod === 'GET') {
    jsonResponse(true, 'Train list fetched successfully.', [
        'trains' => fetchAdminTrains($pdo),
    ]);
}

$input = getRequestData();
$action = strtolower(sanitizeString($input['action'] ?? 'save'));

if ($action === 'delete') {
    $trainId = (int) ($input['train_id'] ?? $input['id'] ?? 0);
    deleteAdminTrain($pdo, $trainId);

    jsonResponse(true, 'Train deleted successfully.', [
        'trains' => fetchAdminTrains($pdo),
    ]);
}

$train = saveAdminTrain($pdo, $input);

jsonResponse(true, 'Train saved successfully.', [
    'train' => $train,
    'trains' => fetchAdminTrains($pdo),
]);
