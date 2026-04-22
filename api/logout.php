<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

requireMethod('POST');

clearAuthenticatedUser();

jsonResponse(true, 'Logged out successfully.', []);
