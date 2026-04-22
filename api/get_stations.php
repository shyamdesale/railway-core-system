<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

requireMethod('GET');

$pdo = getPDO();
$query = sanitizeString($_GET['q'] ?? '');
$query = ensureMaxStringLength($query, 100, 'Station search');

if ($query === '') {
    $stmt = $pdo->prepare(
        'SELECT station_name
         FROM (
            SELECT source AS station_name FROM trains WHERE is_active = 1
            UNION
            SELECT destination AS station_name FROM trains WHERE is_active = 1
         ) stations
         ORDER BY station_name ASC
         LIMIT 8'
    );
    $stmt->execute();

    jsonResponse(true, 'Stations fetched successfully.', [
        'stations' => array_column($stmt->fetchAll(), 'station_name'),
    ]);
}

$stmt = $pdo->prepare(
    'SELECT station_name
     FROM (
        SELECT source AS station_name FROM trains WHERE is_active = 1
        UNION
        SELECT destination AS station_name FROM trains WHERE is_active = 1
     ) stations
     WHERE station_name LIKE :contains_query
     ORDER BY
        CASE WHEN station_name LIKE :starts_with_query THEN 0 ELSE 1 END,
        station_name ASC
     LIMIT 8'
);
$stmt->execute([
    'contains_query' => '%' . $query . '%',
    'starts_with_query' => $query . '%',
]);

jsonResponse(true, 'Stations fetched successfully.', [
    'stations' => array_column($stmt->fetchAll(), 'station_name'),
]);
