<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

requireMethod('POST');

$pdo = getPDO();
$input = getRequestData();

$name = sanitizeString($input['name'] ?? $input['full_name'] ?? '');
$email = normalizeEmail((string) ($input['email'] ?? ''));
$phone = sanitizeString($input['phone'] ?? '');
$password = (string) ($input['password'] ?? '');

ensure($name !== '', 'Name is required.');
ensure($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Valid email is required.');
ensure(strlen($password) >= 6, 'Password must be at least 6 characters long.');

$name = ensureMaxStringLength($name, 100, 'Name');
$email = ensureMaxStringLength($email, 150, 'Email');

if ($phone !== '') {
    $phone = ensureMaxStringLength($phone, 20, 'Phone number');
    ensure((bool) preg_match('/^[0-9+\-\s]{7,20}$/', $phone), 'Phone number format is invalid.');
}

$emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$emailCheck->execute(['email' => $email]);
ensure(!$emailCheck->fetch(), 'Email already registered. Please login instead.', 409);

if ($phone !== '') {
    $phoneCheck = $pdo->prepare('SELECT id FROM users WHERE phone = :phone LIMIT 1');
    $phoneCheck->execute(['phone' => $phone]);
    ensure(!$phoneCheck->fetch(), 'Phone number already registered.', 409);
}

$insert = $pdo->prepare(
    'INSERT INTO users (
        name,
        email,
        phone,
        password,
        role,
        status,
        created_at,
        updated_at
    ) VALUES (
        :name,
        :email,
        :phone,
        :password,
        "USER",
        "ACTIVE",
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    )'
);

$insert->execute([
    'name' => $name,
    'email' => $email,
    'phone' => $phone !== '' ? $phone : null,
    'password' => password_hash($password, PASSWORD_BCRYPT),
]);

$user = getUserById($pdo, (int) $pdo->lastInsertId());
ensure($user !== null, 'Unable to load the registered user.', 500);

jsonResponse(true, 'Registration successful.', [
    'user' => setAuthenticatedUser($user),
]);
