<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$requestMethod = requireMethods(['GET', 'POST']);
$actingAdmin = requireAdmin();
$pdo = getPDO();

if ($requestMethod === 'GET') {
    jsonResponse(true, 'Users fetched successfully.', [
        'users' => fetchAdminUsers($pdo, $_GET),
    ]);
}

$input = getRequestData();
$action = strtolower(sanitizeString($input['action'] ?? 'toggle_status'));
$userId = (int) ($input['user_id'] ?? 0);
$status = sanitizeString($input['status'] ?? '');

ensure($action === 'toggle_status', 'Invalid users action.');
ensure($userId > 0, 'Valid user_id is required.');
ensure($status !== '', 'Status is required.');

$updatedUser = updateAdminUserStatus($pdo, $userId, $status, $actingAdmin);

jsonResponse(true, 'User status updated successfully.', [
    'user' => $updatedUser,
    'users' => fetchAdminUsers($pdo, $_GET),
]);
