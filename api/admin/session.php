<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

requireMethod('GET');

$user = requireAdmin();

jsonResponse(true, 'Admin session fetched successfully.', [
    'user' => $user,
]);
