<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

requireMethod('POST');

clearAuthenticatedUser();

jsonResponse(true, 'Admin logout successful.', []);
