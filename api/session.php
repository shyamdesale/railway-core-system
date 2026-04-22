<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

requireMethod('GET');

jsonResponse(true, 'Session fetched successfully.', [
    'user' => currentUser(),
]);
