<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

requireMethod('POST');

$pdo = getPDO();
$input = getRequestData();

$email = normalizeEmail((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');

ensure($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Valid email is required.');
ensure($password !== '', 'Password is required.');

$email = ensureMaxStringLength($email, 150, 'Email');

$stmt = $pdo->prepare(
    'SELECT id, name, email, phone, password, role, status
     FROM users
     WHERE email = :email
     LIMIT 1'
);
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

ensure($user !== false, 'Invalid email or password.', 401);
ensure(password_verify($password, (string) $user['password']), 'Invalid email or password.', 401);
ensure(($user['status'] ?? 'BLOCKED') === 'ACTIVE', 'Your admin account is not active.', 403);
ensure(($user['role'] ?? 'USER') === 'ADMIN', 'Admin access required.', 403);

jsonResponse(true, 'Admin login successful.', [
    'user' => setAuthenticatedUser($user),
]);
