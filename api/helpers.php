<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

const API_ERROR_LOG = __DIR__ . '/../logs/error.log';
const VALID_TICKET_TYPES = ['Sleeper', '3AC', '2AC', '1AC'];
const VALID_GENDERS = ['M', 'F', 'O'];
const VALID_USER_ROLES = ['USER', 'ADMIN'];
const VALID_USER_STATUSES = ['ACTIVE', 'BLOCKED'];
const TICKET_TYPE_CONFIG = [
    'Sleeper' => [
        'seat_column' => 'sleeper_seats',
        'multiplier' => 1.00,
    ],
    '3AC' => [
        'seat_column' => 'ac3_seats',
        'multiplier' => 1.50,
    ],
    '2AC' => [
        'seat_column' => 'ac2_seats',
        'multiplier' => 2.00,
    ],
    '1AC' => [
        'seat_column' => 'ac1_seats',
        'multiplier' => 3.00,
    ],
];

final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly array $data = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getData(): array
    {
        return $this->data;
    }
}

function getApiRequestId(): string
{
    static $requestId = null;

    if (is_string($requestId) && $requestId !== '') {
        return $requestId;
    }

    try {
        $requestId = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $requestId = uniqid('api_', true);
    }

    return $requestId;
}

function getApiRawBody(): string
{
    static $rawBody = null;

    if ($rawBody !== null) {
        return $rawBody;
    }

    $rawBody = file_get_contents('php://input') ?: '';

    return $rawBody;
}

function truncateDebugString(string $value, int $maxLength = 2000): string
{
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength) . '…';
    }

    if (strlen($value) <= $maxLength) {
        return $value;
    }

    return substr($value, 0, $maxLength) . '...';
}

function redactSensitiveData(mixed $value, string $key = ''): mixed
{
    $sensitiveKeys = [
        'password',
        'pass',
        'token',
        'authorization',
        'cookie',
        'secret',
    ];
    $normalizedKey = strtolower($key);

    foreach ($sensitiveKeys as $sensitiveKey) {
        if ($normalizedKey !== '' && str_contains($normalizedKey, $sensitiveKey)) {
            return '[REDACTED]';
        }
    }

    if (is_array($value)) {
        $sanitized = [];
        foreach ($value as $childKey => $childValue) {
            $sanitized[$childKey] = redactSensitiveData($childValue, (string) $childKey);
        }

        return $sanitized;
    }

    if (is_string($value)) {
        return truncateDebugString($value);
    }

    return $value;
}

function buildApiRequestPayloadSnapshot(): array
{
    $rawBody = getApiRawBody();
    if ($rawBody === '') {
        return [];
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            return [
                'body' => redactSensitiveData($decoded),
            ];
        }

        return [
            'raw_body' => truncateDebugString($rawBody),
        ];
    }

    $parsed = [];
    parse_str($rawBody, $parsed);
    if ($parsed !== []) {
        return [
            'body' => redactSensitiveData($parsed),
        ];
    }

    return [
        'raw_body' => truncateDebugString($rawBody),
    ];
}

function buildApiRequestContext(): array
{
    return [
        'request_id' => getApiRequestId(),
        'sapi' => PHP_SAPI,
        'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'content_type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        'query' => redactSensitiveData($_GET),
        'payload' => buildApiRequestPayloadSnapshot(),
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
}

function logApiDebug(string $channel, array $payload): void
{
    logSystemEvent($channel, redactSensitiveData(array_merge([
        'request_id' => getApiRequestId(),
    ], $payload)));
}

function logApiRequest(): void
{
    static $logged = false;

    if ($logged) {
        return;
    }

    $logged = true;
    logApiDebug('api.request', buildApiRequestContext());
}

function logSqlQuery(string $sql, array $params = [], array $context = []): void
{
    $normalizedSql = preg_replace('/\s+/u', ' ', trim($sql)) ?: trim($sql);

    logApiDebug('api.sql', [
        'sql' => $normalizedSql,
        'params' => redactSensitiveData($params),
        'context' => redactSensitiveData($context),
    ]);
}

function formatThrowableForClient(Throwable $exception): string
{
    $message = trim($exception->getMessage());

    if ($exception instanceof ApiException) {
        return $message !== '' ? $message : 'Request failed.';
    }

    if ($exception instanceof PDOException) {
        if (
            str_contains($message, 'SQLSTATE[HY000] [2002]')
            || str_contains(strtolower($message), 'connection refused')
            || str_contains(strtolower($message), 'operation not permitted')
        ) {
            return 'Database connection failed. Make sure MySQL is running and config/db.php credentials are correct.';
        }

        if (str_contains($message, 'SQLSTATE[')) {
            return 'Database query failed. ' . $message;
        }

        return 'Database operation failed. ' . ($message !== '' ? $message : 'Unknown database error.');
    }

    if ($exception instanceof ErrorException || $exception instanceof Error || $exception instanceof TypeError) {
        return 'Server error: ' . ($message !== '' ? $message : get_class($exception));
    }

    return $message !== '' ? $message : 'Unexpected server error.';
}

function formatFatalApiMessage(array $error): string
{
    $message = trim((string) ($error['message'] ?? ''));

    if ($message !== '' && preg_match('/Next RuntimeException:\s*([^\n]+)/', $message, $matches) === 1) {
        $message = trim($matches[1]);
        $message = preg_replace('/\s+in\s+\/.+$/', '', $message) ?: $message;

        return $message;
    }

    if ($message !== '' && preg_match('/Uncaught [^:]+:\s*([^\n]+)/', $message, $matches) === 1) {
        $message = trim($matches[1]);
    }

    if (str_contains($message, 'Stack trace:')) {
        $message = trim((string) strtok($message, "\n"));
    }

    return 'Fatal server error: ' . ($message !== '' ? $message : 'Unexpected shutdown failure.');
}

function setApiHeaders(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('X-Request-Id: ' . getApiRequestId());
}

function bootApi(): void
{
    if (!is_dir(dirname(API_ERROR_LOG))) {
        mkdir(dirname(API_ERROR_LOG), 0775, true);
    }

    if (!file_exists(API_ERROR_LOG)) {
        touch(API_ERROR_LOG);
    }

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', API_ERROR_LOG);
    error_reporting(E_ALL);

    if (ob_get_level() === 0) {
        ob_start();
    }

    setApiHeaders();
    logApiRequest();

    set_error_handler(
        static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
    );

    set_exception_handler(
        static function (Throwable $exception): void {
            logApiDebug('api.exception', [
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'request' => buildApiRequestContext(),
            ]);

            if ($exception instanceof ApiException) {
                jsonResponse(false, $exception->getMessage(), $exception->getData(), $exception->getStatusCode());
            }

            error_log(
                sprintf(
                    'Unhandled API exception: %s in %s:%d %s',
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine(),
                    $exception->getTraceAsString()
                )
            );

            jsonResponse(false, formatThrowableForClient($exception), [], 500);
        }
    );

    register_shutdown_function(
        static function (): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($error['type'], $fatalTypes, true)) {
                return;
            }

            error_log(
                sprintf(
                    'Fatal API error: %s in %s:%d',
                    $error['message'],
                    $error['file'],
                    $error['line']
                )
            );
            logApiDebug('api.fatal', [
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'request' => buildApiRequestContext(),
            ]);

            if (!headers_sent()) {
                http_response_code(500);
                setApiHeaders();
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            echo json_encode(
                [
                    'status' => 'error',
                    'success' => false,
                    'message' => formatFatalApiMessage($error),
                    'data' => [
                        'request_id' => getApiRequestId(),
                    ],
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
    );
}

function startSessionIfNeeded(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => (int) ($cookieParams['lifetime'] ?? 0),
        'path' => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function jsonResponse(bool $success, string $message, array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    setApiHeaders();

    if (!$success && !array_key_exists('request_id', $data)) {
        $data['request_id'] = getApiRequestId();
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $payload = [
        'status' => $success ? 'success' : 'error',
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = sprintf(
            '{"status":"error","success":false,"message":"Response encoding failed.","data":{"request_id":"%s"}}',
            getApiRequestId()
        );
    }

    echo $json;
    exit;
}

function requireMethod(string $method): void
{
    requireMethods([$method]);
}

function requireMethods(array $methods): string
{
    $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $allowedMethods = array_values(array_unique(array_map(static fn (string $item): string => strtoupper($item), $methods)));

    if ($requestMethod === 'OPTIONS') {
        jsonResponse(true, 'Preflight request accepted.', [], 200);
    }

    if (!in_array($requestMethod, $allowedMethods, true)) {
        throw new ApiException('Method not allowed.', 405);
    }

    return $requestMethod;
}

function getRequestData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $rawBody = getApiRawBody();

    if (stripos($contentType, 'application/json') !== false) {
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new ApiException('Invalid JSON payload.', 422);
        }

        return $decoded;
    }

    if ($_POST !== []) {
        return $_POST;
    }

    if ($rawBody !== '') {
        parse_str($rawBody, $parsed);
        if (is_array($parsed)) {
            return $parsed;
        }
    }

    return [];
}

function sanitizeString(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function ensureMaxStringLength(string $value, int $maxLength, string $label): string
{
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    ensure($length <= $maxLength, sprintf('%s must be %d characters or fewer.', $label, $maxLength));

    return $value;
}

function normalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function normalizeUserRole(mixed $value, string $default = 'USER'): string
{
    $normalized = strtoupper(sanitizeString($value));
    if ($normalized === '') {
        $normalized = $default;
    }

    ensure(in_array($normalized, VALID_USER_ROLES, true), 'Invalid user role.');

    return $normalized;
}

function normalizeUserStatus(mixed $value, string $default = 'ACTIVE'): string
{
    $normalized = strtoupper(sanitizeString($value));
    if ($normalized === '') {
        $normalized = $default;
    }

    ensure(in_array($normalized, VALID_USER_STATUSES, true), 'Invalid user status.');

    return $normalized;
}

function logSystemEvent(string $channel, array $payload): void
{
    $logDirectory = __DIR__ . '/../logs';
    if (!is_dir($logDirectory)) {
        mkdir($logDirectory, 0775, true);
    }

    $line = json_encode([
        'timestamp' => date('c'),
        'channel' => $channel,
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($line === false) {
        $line = date('c') . ' ' . $channel . ' ' . print_r($payload, true);
    }

    file_put_contents($logDirectory . '/system.log', $line . PHP_EOL, FILE_APPEND);
}

function ensure(bool $condition, string $message, int $statusCode = 422, array $data = []): void
{
    if (!$condition) {
        throw new ApiException($message, $statusCode, $data);
    }
}

function formatTimeDisplay(string $time): string
{
    $date = DateTimeImmutable::createFromFormat('H:i:s', $time);

    return $date ? $date->format('h:i A') : $time;
}

function getTravelDurationMinutes(string $departureTime, string $arrivalTime): int
{
    $departure = DateTimeImmutable::createFromFormat('H:i:s', $departureTime);
    $arrival = DateTimeImmutable::createFromFormat('H:i:s', $arrivalTime);

    if (!$departure || !$arrival) {
        return 0;
    }

    if ($arrival < $departure) {
        $arrival = $arrival->modify('+1 day');
    }

    return (int) (($arrival->getTimestamp() - $departure->getTimestamp()) / 60);
}

function formatDurationLabel(int $minutes): string
{
    $hours = intdiv(max(0, $minutes), 60);
    $remainingMinutes = max(0, $minutes % 60);

    return sprintf('%dh %02dm', $hours, $remainingMinutes);
}

function formatCurrency(float $amount): string
{
    return '₹' . number_format($amount, 2);
}

function validateTravelDate(string $travelDate, bool $allowPast = false): string
{
    $normalized = sanitizeString($travelDate);
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $normalized);

    ensure($date !== false && $date->format('Y-m-d') === $normalized, 'Invalid travel date.');

    if (!$allowPast) {
        $today = new DateTimeImmutable('today');
        ensure($date >= $today, 'Travel date cannot be in the past.');
    }

    return $normalized;
}

function normalizeTicketType(mixed $value): string
{
    $raw = strtoupper(str_replace([' ', '-'], '', sanitizeString($value)));
    if ($raw === '') {
        return 'Sleeper';
    }

    $map = [
        'SLEEPER' => 'Sleeper',
        'SL' => 'Sleeper',
        '3AC' => '3AC',
        '2AC' => '2AC',
        '1AC' => '1AC',
    ];

    ensure(isset($map[$raw]), 'Invalid ticket type.');

    return $map[$raw];
}

function normalizeGender(mixed $value): string
{
    $raw = strtoupper(sanitizeString($value));
    if ($raw === '') {
        return 'O';
    }

    $map = [
        'M' => 'M',
        'MALE' => 'M',
        'F' => 'F',
        'FEMALE' => 'F',
        'O' => 'O',
        'OTHER' => 'O',
    ];

    ensure(isset($map[$raw]), 'Invalid gender.');

    return $map[$raw];
}

function normalizeAge(mixed $value): ?int
{
    $raw = sanitizeString($value);
    if ($raw === '') {
        return null;
    }

    $age = filter_var(
        $raw,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 120]]
    );

    ensure($age !== false, 'Age must be between 1 and 120.');

    return (int) $age;
}

function getTicketTypeConfig(string $ticketType): array
{
    $normalizedTicketType = normalizeTicketType($ticketType);

    return TICKET_TYPE_CONFIG[$normalizedTicketType];
}

function getTrainClassSeatLimit(array $train, string $ticketType): int
{
    $config = getTicketTypeConfig($ticketType);
    $seatColumn = $config['seat_column'];

    return max(0, (int) ($train[$seatColumn] ?? 0));
}

function getTrainTotalSeatLimit(array $train): int
{
    $totalSeats = 0;
    foreach (VALID_TICKET_TYPES as $ticketType) {
        $totalSeats += getTrainClassSeatLimit($train, $ticketType);
    }

    if ($totalSeats > 0) {
        return $totalSeats;
    }

    return max(0, (int) ($train['total_seats'] ?? 0));
}

function calculateTicketPrice(float $basePrice, string $ticketType): float
{
    $config = getTicketTypeConfig($ticketType);

    return round($basePrice * (float) $config['multiplier'], 2);
}

function generateRequestToken(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function createUniquePnr(PDO $pdo, int $trainId, string $travelDate): string
{
    $check = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE pnr = :pnr');
    $datePart = (new DateTimeImmutable($travelDate))->format('ymd');
    $trainPart = strtoupper(str_pad(base_convert((string) $trainId, 10, 36), 2, '0', STR_PAD_LEFT));

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $pnr = 'MR' . $datePart . $trainPart . strtoupper(bin2hex(random_bytes(3)));
        $check->execute(['pnr' => $pnr]);

        if ((int) $check->fetchColumn() === 0) {
            return $pnr;
        }
    }

    throw new ApiException('Unable to generate a unique PNR. Please retry.', 500);
}

function getUserById(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, email, phone, role, status
         FROM users
         WHERE id = :id AND status = "ACTIVE"
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function buildUserPayload(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => $user['phone'] ?? null,
        'role' => normalizeUserRole($user['role'] ?? 'USER'),
        'status' => normalizeUserStatus($user['status'] ?? 'ACTIVE'),
    ];
}

function persistAuthenticatedUserSession(array $user): array
{
    startSessionIfNeeded();
    $payload = buildUserPayload($user);

    $_SESSION['user'] = $payload;
    $_SESSION['user_id'] = $payload['id'];
    $_SESSION['role'] = $payload['role'];
    $_SESSION['is_authenticated'] = true;

    return $payload;
}

function setAuthenticatedUser(array $user): array
{
    startSessionIfNeeded();
    session_regenerate_id(true);

    return persistAuthenticatedUserSession($user);
}

function clearAuthenticatedUser(): void
{
    startSessionIfNeeded();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function currentUser(): ?array
{
    startSessionIfNeeded();

    $sessionUserId = (int) ($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
    if ($sessionUserId <= 0) {
        return null;
    }

    $pdo = getPDO();
    $user = getUserById($pdo, $sessionUserId);

    if ($user === null) {
        clearAuthenticatedUser();
        return null;
    }

    return persistAuthenticatedUserSession($user);
}

function requireLogin(): array
{
    $user = currentUser();
    ensure($user !== null, 'Please login to continue.', 401);

    return $user;
}

function requireAdmin(): array
{
    $user = requireLogin();
    ensure(($user['role'] ?? 'USER') === 'ADMIN', 'Admin access required.', 403);

    return $user;
}

function normalizeStatusFilter(string $status): ?string
{
    $normalized = strtoupper(sanitizeString($status));
    if ($normalized === '' || $normalized === 'ALL') {
        return null;
    }

    ensure(in_array($normalized, ['CONFIRMED', 'WAITING', 'CANCELLED'], true), 'Invalid status filter.');

    return $normalized;
}

function acquireJourneyLock(PDO $pdo, int $trainId, string $travelDate, int $timeoutSeconds = 10): string
{
    $lockName = sprintf('minirail:%d:%s', $trainId, $travelDate);
    $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds) AS lock_status');
    $stmt->bindValue('lock_name', $lockName, PDO::PARAM_STR);
    $stmt->bindValue('timeout_seconds', $timeoutSeconds, PDO::PARAM_INT);
    $stmt->execute();

    ensure((int) $stmt->fetchColumn() === 1, 'The booking system is busy. Please try again.', 409);

    return $lockName;
}

function releaseJourneyLock(PDO $pdo, ?string $lockName): void
{
    if ($lockName === null || $lockName === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $stmt->execute(['lock_name' => $lockName]);
}

function getTrainById(PDO $pdo, int $trainId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, train_number, train_name, source, destination, departure_time, arrival_time, price, total_seats, sleeper_seats, ac3_seats, ac2_seats, ac1_seats, is_active, created_at, updated_at
            FROM trains
            WHERE id = :id
            LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $trainId]);
    $train = $stmt->fetch();

    return $train ?: null;
}

function countJourneyBookingsByStatus(
    PDO $pdo,
    int $trainId,
    string $travelDate,
    string $status,
    ?string $ticketType = null
): int
{
    $sql = 'SELECT COUNT(*)
            FROM bookings
            WHERE train_id = :train_id
              AND travel_date = :travel_date
              AND status = :status';

    $params = [
        'train_id' => $trainId,
        'travel_date' => $travelDate,
        'status' => $status,
    ];

    if ($ticketType !== null) {
        $sql .= ' AND ticket_type = :ticket_type';
        $params['ticket_type'] = normalizeTicketType($ticketType);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function getJourneyBookingStatsByClass(PDO $pdo, array $trainIds, string $travelDate): array
{
    if ($trainIds === []) {
        return [];
    }

    $placeholders = [];
    $params = [
        'travel_date' => $travelDate,
    ];

    foreach (array_values(array_unique(array_map('intval', $trainIds))) as $index => $trainId) {
        $placeholder = 'train_id_' . $index;
        $placeholders[] = ':' . $placeholder;
        $params[$placeholder] = $trainId;
    }

    $stmt = $pdo->prepare(
        'SELECT
            train_id,
            ticket_type,
            SUM(CASE WHEN status = "CONFIRMED" THEN 1 ELSE 0 END) AS confirmed_count,
            SUM(CASE WHEN status = "WAITING" THEN 1 ELSE 0 END) AS waiting_count
         FROM bookings
         WHERE travel_date = :travel_date
           AND train_id IN (' . implode(', ', $placeholders) . ')
         GROUP BY train_id, ticket_type'
    );
    $stmt->execute($params);

    $stats = [];
    foreach ($stmt->fetchAll() as $row) {
        $stats[(int) $row['train_id']][(string) $row['ticket_type']] = [
            'confirmed_count' => (int) $row['confirmed_count'],
            'waiting_count' => (int) $row['waiting_count'],
        ];
    }

    return $stats;
}

function buildTrainClassAvailability(array $train, array $classStats = []): array
{
    $classAvailability = [];

    foreach (VALID_TICKET_TYPES as $ticketType) {
        $confirmedCount = (int) ($classStats[$ticketType]['confirmed_count'] ?? 0);
        $waitingCount = (int) ($classStats[$ticketType]['waiting_count'] ?? 0);
        $totalSeats = getTrainClassSeatLimit($train, $ticketType);
        $availableSeats = max(0, $totalSeats - $confirmedCount);
        $price = calculateTicketPrice((float) $train['price'], $ticketType);

        $classAvailability[$ticketType] = [
            'ticket_type' => $ticketType,
            'available_seats' => $availableSeats,
            'confirmed_count' => $confirmedCount,
            'waiting_count' => $waitingCount,
            'total_seats' => $totalSeats,
            'price' => $price,
            'price_label' => formatCurrency($price),
            'booking_status' => $availableSeats > 0 ? 'AVAILABLE' : ($waitingCount > 0 ? 'WAITING' : 'FULL'),
        ];
    }

    return $classAvailability;
}

function getNextAvailableSeatNumber(
    PDO $pdo,
    int $trainId,
    string $travelDate,
    string $ticketType,
    int $classSeatLimit
): ?int
{
    $stmt = $pdo->prepare(
        'SELECT seat_number
         FROM bookings
         WHERE train_id = :train_id
           AND travel_date = :travel_date
           AND ticket_type = :ticket_type
           AND status = "CONFIRMED"
           AND seat_number IS NOT NULL
         ORDER BY seat_number ASC'
    );
    $stmt->execute([
        'train_id' => $trainId,
        'travel_date' => $travelDate,
        'ticket_type' => $ticketType,
    ]);

    $occupied = array_map('intval', array_column($stmt->fetchAll(), 'seat_number'));
    $lookup = array_fill_keys($occupied, true);

    for ($seatNumber = 1; $seatNumber <= $classSeatLimit; $seatNumber++) {
        if (!isset($lookup[$seatNumber])) {
            return $seatNumber;
        }
    }

    return null;
}

function getNextWaitingQueueNo(PDO $pdo, int $trainId, string $travelDate, string $ticketType): int
{
    $stmt = $pdo->prepare(
        'SELECT MAX(wl.queue_no)
         FROM waiting_list wl
         INNER JOIN bookings b ON b.id = wl.booking_id
         WHERE b.train_id = :train_id
           AND b.travel_date = :travel_date
           AND b.ticket_type = :ticket_type
           AND b.status = "WAITING"'
    );
    $stmt->execute([
        'train_id' => $trainId,
        'travel_date' => $travelDate,
        'ticket_type' => $ticketType,
    ]);

    return ((int) $stmt->fetchColumn()) + 1;
}

function createWaitingListEntry(PDO $pdo, int $bookingId, int $queueNo): void
{
    if (tableHasColumn($pdo, 'waiting_list', 'wl_number')) {
        $stmt = $pdo->prepare(
            'INSERT INTO waiting_list (
                booking_id,
                queue_no,
                wl_number,
                created_at
            ) VALUES (
                :booking_id,
                :queue_no,
                :wl_number,
                CURRENT_TIMESTAMP
            )'
        );
        $stmt->execute([
            'booking_id' => $bookingId,
            'queue_no' => $queueNo,
            'wl_number' => 'WL' . $queueNo,
        ]);

        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO waiting_list (
            booking_id,
            queue_no,
            created_at
        ) VALUES (
            :booking_id,
            :queue_no,
            CURRENT_TIMESTAMP
        )'
    );
    $stmt->execute([
        'booking_id' => $bookingId,
        'queue_no' => $queueNo,
    ]);
}

function promoteNextWaitingBooking(
    PDO $pdo,
    int $trainId,
    string $travelDate,
    string $ticketType,
    int $seatNumber
): ?array
{
    $select = $pdo->prepare(
        'SELECT wl.id AS waiting_id, wl.booking_id
         FROM waiting_list wl
         INNER JOIN bookings b ON b.id = wl.booking_id
         WHERE b.train_id = :train_id
           AND b.travel_date = :travel_date
           AND b.ticket_type = :ticket_type
           AND b.status = "WAITING"
         ORDER BY wl.queue_no ASC, wl.created_at ASC, wl.id ASC
         LIMIT 1
         FOR UPDATE'
    );
    $select->execute([
        'train_id' => $trainId,
        'travel_date' => $travelDate,
        'ticket_type' => $ticketType,
    ]);

    $waiting = $select->fetch();
    if (!$waiting) {
        return null;
    }

    $updateBooking = $pdo->prepare(
        'UPDATE bookings
         SET status = "CONFIRMED",
             seat_number = :seat_number,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :booking_id'
    );
    $updateBooking->execute([
        'seat_number' => $seatNumber,
        'booking_id' => (int) $waiting['booking_id'],
    ]);

    $deleteWaiting = $pdo->prepare('DELETE FROM waiting_list WHERE id = :id');
    $deleteWaiting->execute(['id' => (int) $waiting['waiting_id']]);

    return loadBookingById($pdo, (int) $waiting['booking_id']);
}

function findBookingByRequestToken(PDO $pdo, string $requestToken): ?array
{
    if ($requestToken === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, user_id, pnr, status
         FROM bookings
         WHERE request_token = :request_token
         LIMIT 1'
    );
    $stmt->execute([
        'request_token' => $requestToken,
    ]);

    $row = $stmt->fetch();

    return $row ?: null;
}

function findBookingReference(
    PDO $pdo,
    int $bookingId = 0,
    string $pnr = '',
    ?int $userId = null
): ?array {
    ensure($bookingId > 0 || $pnr !== '', 'booking_id or pnr is required.');

    $sql = 'SELECT id, user_id, train_id, travel_date
            FROM bookings
            WHERE ';
    $params = [];

    if ($bookingId > 0) {
        $sql .= 'id = :booking_id';
        $params['booking_id'] = $bookingId;
    } else {
        $sql .= 'pnr = :pnr';
        $params['pnr'] = $pnr;
    }

    if ($userId !== null) {
        $sql .= ' AND user_id = :user_id';
        $params['user_id'] = $userId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

function cancelBookingById(PDO $pdo, int $bookingId, ?int $userId = null): array
{
    $bookingReference = findBookingReference($pdo, $bookingId, '', $userId);
    ensure($bookingReference !== null, 'Booking not found.', 404);

    $lockName = acquireJourneyLock($pdo, (int) $bookingReference['train_id'], (string) $bookingReference['travel_date']);
    $cancelledBooking = null;
    $promotedBooking = null;

    try {
        $pdo->beginTransaction();

        $select = $pdo->prepare(
            'SELECT id, user_id, train_id, travel_date, ticket_type, seat_number, status, pnr
             FROM bookings
             WHERE id = :booking_id' . ($userId !== null ? ' AND user_id = :user_id' : '') . '
             LIMIT 1
             FOR UPDATE'
        );
        $params = [
            'booking_id' => (int) $bookingReference['id'],
        ];
        if ($userId !== null) {
            $params['user_id'] = $userId;
        }
        $select->execute($params);

        $booking = $select->fetch();
        ensure($booking !== false, 'Booking not found.', 404);
        ensure($booking['status'] !== 'CANCELLED', 'Ticket is already cancelled.', 409);

        $cancelStatement = $pdo->prepare(
            'UPDATE bookings
             SET status = "CANCELLED",
                 seat_number = NULL,
                 cancelled_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :booking_id'
        );
        $cancelStatement->execute([
            'booking_id' => (int) $booking['id'],
        ]);

        $deleteWaiting = $pdo->prepare('DELETE FROM waiting_list WHERE booking_id = :booking_id');
        $deleteWaiting->execute([
            'booking_id' => (int) $booking['id'],
        ]);

        if ($booking['status'] === 'CONFIRMED') {
            $promotedBooking = promoteNextWaitingBooking(
                $pdo,
                (int) $booking['train_id'],
                (string) $booking['travel_date'],
                (string) $booking['ticket_type'],
                (int) $booking['seat_number']
            );
        }

        $cancelledBooking = loadBookingById($pdo, (int) $booking['id']);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    } finally {
        releaseJourneyLock($pdo, $lockName);
    }

    return [
        'cancelled_booking' => $cancelledBooking,
        'promoted_booking' => $promotedBooking,
    ];
}

function confirmWaitingBookingById(PDO $pdo, int $bookingId): array
{
    $bookingReference = findBookingReference($pdo, $bookingId);
    ensure($bookingReference !== null, 'Booking not found.', 404);

    $lockName = acquireJourneyLock($pdo, (int) $bookingReference['train_id'], (string) $bookingReference['travel_date']);
    $confirmedBooking = null;

    try {
        $pdo->beginTransaction();

        $selectBooking = $pdo->prepare(
            'SELECT id, train_id, travel_date, ticket_type, status
             FROM bookings
             WHERE id = :booking_id
             LIMIT 1
             FOR UPDATE'
        );
        $selectBooking->execute([
            'booking_id' => (int) $bookingReference['id'],
        ]);
        $booking = $selectBooking->fetch();

        ensure($booking !== false, 'Booking not found.', 404);
        ensure($booking['status'] === 'WAITING', 'Only waiting-list bookings can be manually confirmed.', 409);

        $train = getTrainById($pdo, (int) $booking['train_id'], true);
        ensure($train !== null, 'Train not found.', 404);

        $ticketType = (string) $booking['ticket_type'];
        $classSeatLimit = getTrainClassSeatLimit($train, $ticketType);
        $confirmedCount = countJourneyBookingsByStatus(
            $pdo,
            (int) $booking['train_id'],
            (string) $booking['travel_date'],
            'CONFIRMED',
            $ticketType
        );
        ensure($confirmedCount < $classSeatLimit, 'No confirmed seat is available in this class.', 409);

        $seatNumber = getNextAvailableSeatNumber(
            $pdo,
            (int) $booking['train_id'],
            (string) $booking['travel_date'],
            $ticketType,
            $classSeatLimit
        );
        ensure($seatNumber !== null, 'No confirmed seat is available in this class.', 409);

        $updateBooking = $pdo->prepare(
            'UPDATE bookings
             SET status = "CONFIRMED",
                 seat_number = :seat_number,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :booking_id'
        );
        $updateBooking->execute([
            'seat_number' => $seatNumber,
            'booking_id' => (int) $booking['id'],
        ]);

        $deleteWaiting = $pdo->prepare('DELETE FROM waiting_list WHERE booking_id = :booking_id');
        $deleteWaiting->execute([
            'booking_id' => (int) $booking['id'],
        ]);

        $confirmedBooking = loadBookingById($pdo, (int) $booking['id']);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    } finally {
        releaseJourneyLock($pdo, $lockName);
    }

    return [
        'booking' => $confirmedBooking,
    ];
}

function fetchBookingsForUser(PDO $pdo, int $userId, ?string $status = null, int $bookingId = 0, string $pnr = ''): array
{
    $sql = 'SELECT
                b.id,
                b.user_id,
                b.train_id,
                b.travel_date,
                b.passenger_name,
                b.age,
                b.gender,
                b.ticket_type,
                b.price,
                b.seat_number,
                b.pnr,
                b.request_token,
                b.status,
                b.cancelled_at,
                b.created_at,
                b.updated_at,
                t.train_number,
                t.train_name,
                t.source,
                t.destination,
                t.departure_time,
                t.arrival_time,
                wl.queue_no
            FROM bookings b
            INNER JOIN trains t ON t.id = b.train_id
            LEFT JOIN waiting_list wl ON wl.booking_id = b.id
            WHERE b.user_id = :user_id';

    $params = ['user_id' => $userId];

    if ($status !== null) {
        $sql .= ' AND b.status = :status';
        $params['status'] = $status;
    }

    if ($bookingId > 0) {
        $sql .= ' AND b.id = :booking_id';
        $params['booking_id'] = $bookingId;
    }

    if ($pnr !== '') {
        $sql .= ' AND b.pnr = :pnr';
        $params['pnr'] = $pnr;
    }

    $sql .= ' ORDER BY b.created_at DESC, b.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('hydrateBookingRow', $stmt->fetchAll());
}

function loadBookingById(PDO $pdo, int $bookingId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            b.id,
            b.user_id,
            b.train_id,
            b.travel_date,
            b.passenger_name,
            b.age,
            b.gender,
            b.ticket_type,
            b.price,
            b.seat_number,
            b.pnr,
            b.request_token,
            b.status,
            b.cancelled_at,
            b.created_at,
            b.updated_at,
            t.train_number,
            t.train_name,
            t.source,
            t.destination,
            t.departure_time,
            t.arrival_time,
            wl.queue_no
         FROM bookings b
         INNER JOIN trains t ON t.id = b.train_id
         LEFT JOIN waiting_list wl ON wl.booking_id = b.id
         WHERE b.id = :booking_id
         LIMIT 1'
    );
    $stmt->execute(['booking_id' => $bookingId]);
    $booking = $stmt->fetch();

    return $booking ? hydrateBookingRow($booking) : null;
}

function hydrateBookingRow(array $row): array
{
    $queueNo = isset($row['queue_no']) && $row['queue_no'] !== null ? (int) $row['queue_no'] : null;
    $durationMinutes = getTravelDurationMinutes((string) $row['departure_time'], (string) $row['arrival_time']);

    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'train_id' => (int) $row['train_id'],
        'train_number' => $row['train_number'],
        'train_name' => $row['train_name'],
        'source' => $row['source'],
        'destination' => $row['destination'],
        'travel_date' => $row['travel_date'],
        'departure_time' => $row['departure_time'],
        'arrival_time' => $row['arrival_time'],
        'departure_label' => formatTimeDisplay((string) $row['departure_time']),
        'arrival_label' => formatTimeDisplay((string) $row['arrival_time']),
        'duration_label' => formatDurationLabel($durationMinutes),
        'passenger_name' => $row['passenger_name'],
        'age' => $row['age'] !== null ? (int) $row['age'] : null,
        'gender' => $row['gender'],
        'ticket_type' => $row['ticket_type'],
        'price' => (float) $row['price'],
        'price_label' => formatCurrency((float) $row['price']),
        'seat_number' => $row['seat_number'] !== null ? (int) $row['seat_number'] : null,
        'pnr' => $row['pnr'],
        'request_token' => $row['request_token'],
        'status' => $row['status'],
        'wl_number' => $queueNo !== null ? 'WL' . $queueNo : null,
        'queue_no' => $queueNo,
        'display_status' => $row['status'] === 'WAITING' && $queueNo !== null ? 'WL' . $queueNo : $row['status'],
        'cancelled_at' => $row['cancelled_at'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function getRouteAvailableDates(PDO $pdo, string $source, string $destination, string $startDate, int $days = 7): array
{
    $trainStmt = $pdo->prepare(
        'SELECT id, total_seats, sleeper_seats, ac3_seats, ac2_seats, ac1_seats, price
         FROM trains
         WHERE LOWER(source) = LOWER(:source)
           AND LOWER(destination) = LOWER(:destination)
           AND is_active = 1'
    );
    $trainStmt->execute([
        'source' => $source,
        'destination' => $destination,
    ]);
    $trains = $trainStmt->fetchAll();

    if ($trains === []) {
        return [];
    }

    $dates = [];
    $cursor = new DateTimeImmutable($startDate);
    for ($i = 0; $i < $days; $i++) {
        $date = $cursor->modify('+' . $i . ' day')->format('Y-m-d');
        $journeyStats = getJourneyBookingStatsByClass($pdo, array_column($trains, 'id'), $date);

        foreach ($trains as $train) {
            $classAvailability = buildTrainClassAvailability($train, $journeyStats[(int) $train['id']] ?? []);
            $hasAvailableSeats = false;

            foreach ($classAvailability as $classData) {
                if ((int) $classData['available_seats'] > 0) {
                    $hasAvailableSeats = true;
                    break;
                }
            }

            if ($hasAvailableSeats) {
                $dates[] = $date;
                break;
            }
        }
    }

    return $dates;
}

bootApi();
