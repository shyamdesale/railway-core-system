<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_start();
}

$userId = (int) ($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
$role = strtoupper((string) ($_SESSION['role'] ?? ($_SESSION['user']['role'] ?? 'USER')));

if ($userId > 0 && $role !== 'ADMIN') {
    header('Location: frontend/index.html?auth=admin_required');
    exit;
}

header('Location: admin/index.html');
exit;
