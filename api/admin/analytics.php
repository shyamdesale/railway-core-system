<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../admin/analytics_functions.php';

requireMethod('GET');
requireAdmin();

$state = analytics_load_dashboard_state();
$analytics = $state['analytics'] ?? analytics_build_empty_payload();
$errorMessage = $state['error'] ?? null;

if (is_string($errorMessage) && $errorMessage !== '') {
    $responseData = [
        'analytics' => $analytics,
    ];

    if (analytics_is_debug_mode()) {
        $responseData['error'] = $errorMessage;
    }

    jsonResponse(false, analytics_public_error_message($errorMessage), $responseData, 503);
}

jsonResponse(true, 'Analytics data fetched successfully.', [
    'analytics' => $analytics,
]);
