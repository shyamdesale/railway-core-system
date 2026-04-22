<?php

declare(strict_types=1);

/**
 * Production-grade JSON API template for this project.
 *
 * Usage:
 * 1. Copy this file to a new endpoint, for example api/example_endpoint.php
 * 2. Update the request method, validation rules, query, and response payload
 * 3. Keep all output flowing through jsonResponse() so clients always receive JSON
 */

require_once __DIR__ . '/helpers.php';

requireMethod('POST');

$input = getRequestData();
ensure($input !== [], 'Request body is required.');

$recordId = filter_var(
    $input['record_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$search = normalizeWhitespace(sanitizeString($input['search'] ?? ''));

ensure($recordId !== false, 'Valid record_id is required.');
ensure(stringLength($search) <= 100, 'search must be 100 characters or fewer.');

$pdo = getPDO();

$stmt = $pdo->prepare(
    'SELECT
        id,
        train_number,
        train_name,
        source,
        destination
     FROM trains
     WHERE id = :id
     LIMIT 1'
);
$stmt->bindValue(':id', (int) $recordId, PDO::PARAM_INT);
$stmt->execute();

$record = $stmt->fetch();
ensure($record !== false, 'Record not found.', 404);

jsonResponse(true, 'Request processed successfully.', [
    'record' => $record,
    'filters' => [
        'search' => $search,
    ],
]);
