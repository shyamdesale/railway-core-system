<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

function normalizeAdminDate(?string $value, bool $allowEmpty = true): ?string
{
    $normalized = sanitizeString($value ?? '');
    if ($normalized === '') {
        return $allowEmpty ? null : validateTravelDate('');
    }

    return validateTravelDate($normalized, true);
}

function normalizeTimeValue(mixed $value): string
{
    $raw = sanitizeString($value);
    ensure($raw !== '', 'Time value is required.');

    $formats = ['H:i:s', 'H:i'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $raw);
        if ($date !== false) {
            return $date->format('H:i:s');
        }
    }

    throw new ApiException('Invalid time format. Expected HH:MM or HH:MM:SS.');
}

function normalizeMoney(mixed $value): float
{
    $raw = sanitizeString($value);
    ensure($raw !== '' && is_numeric($raw), 'Valid base price is required.');
    $amount = round((float) $raw, 2);
    ensure($amount >= 0, 'Price cannot be negative.');

    return $amount;
}

function normalizeSeatCount(mixed $value, string $label): int
{
    $raw = sanitizeString($value);
    ensure($raw !== '', $label . ' seat count is required.');

    $seatCount = filter_var(
        $raw,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => 1000]]
    );
    ensure($seatCount !== false, $label . ' seat count must be between 0 and 1000.');

    return (int) $seatCount;
}

function normalizeActiveFlag(mixed $value): int
{
    $normalized = strtolower(sanitizeString($value));
    if ($normalized === '' || in_array($normalized, ['1', 'true', 'yes', 'active'], true)) {
        return 1;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'inactive'], true)) {
        return 0;
    }

    throw new ApiException('Invalid active flag.');
}

function buildAdminDateRangeSql(
    string $column,
    ?string $dateFrom,
    ?string $dateTo,
    array &$params,
    string $prefix
): string {
    $sql = '';

    if ($dateFrom !== null) {
        $key = $prefix . '_from';
        $sql .= ' AND ' . $column . ' >= :' . $key;
        $params[$key] = $dateFrom;
    }

    if ($dateTo !== null) {
        $key = $prefix . '_to';
        $sql .= ' AND ' . $column . ' <= :' . $key;
        $params[$key] = $dateTo;
    }

    return $sql;
}

function hydrateTrainRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'train_number' => $row['train_number'],
        'train_name' => $row['train_name'],
        'source' => $row['source'],
        'destination' => $row['destination'],
        'departure_time' => $row['departure_time'],
        'arrival_time' => $row['arrival_time'],
        'departure_label' => formatTimeDisplay((string) $row['departure_time']),
        'arrival_label' => formatTimeDisplay((string) $row['arrival_time']),
        'price' => (float) $row['price'],
        'price_label' => formatCurrency((float) $row['price']),
        'total_seats' => getTrainTotalSeatLimit($row),
        'sleeper_seats' => (int) $row['sleeper_seats'],
        'ac3_seats' => (int) $row['ac3_seats'],
        'ac2_seats' => (int) $row['ac2_seats'],
        'ac1_seats' => (int) $row['ac1_seats'],
        'is_active' => (int) $row['is_active'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function fetchAdminTrains(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT
            id,
            train_number,
            train_name,
            source,
            destination,
            departure_time,
            arrival_time,
            price,
            total_seats,
            sleeper_seats,
            ac3_seats,
            ac2_seats,
            ac1_seats,
            is_active,
            created_at,
            updated_at
         FROM trains
         ORDER BY train_name ASC, train_number ASC'
    );
    $stmt->execute();

    return array_map('hydrateTrainRow', $stmt->fetchAll());
}

function saveAdminTrain(PDO $pdo, array $input): array
{
    $trainId = (int) ($input['train_id'] ?? $input['id'] ?? 0);
    $trainNumber = strtoupper(sanitizeString($input['train_number'] ?? ''));
    $trainName = sanitizeString($input['train_name'] ?? '');
    $source = sanitizeString($input['source'] ?? '');
    $destination = sanitizeString($input['destination'] ?? '');
    $departureTime = normalizeTimeValue($input['departure_time'] ?? '');
    $arrivalTime = normalizeTimeValue($input['arrival_time'] ?? '');
    $basePrice = normalizeMoney($input['price'] ?? $input['base_price'] ?? '');
    $sleeperSeats = normalizeSeatCount($input['sleeper_seats'] ?? 30, 'Sleeper');
    $ac3Seats = normalizeSeatCount($input['ac3_seats'] ?? 30, '3AC');
    $ac2Seats = normalizeSeatCount($input['ac2_seats'] ?? 20, '2AC');
    $ac1Seats = normalizeSeatCount($input['ac1_seats'] ?? 10, '1AC');
    $isActive = normalizeActiveFlag($input['is_active'] ?? 1);

    ensure($trainNumber !== '', 'Train number is required.');
    ensure($trainName !== '', 'Train name is required.');
    ensure($source !== '', 'Source is required.');
    ensure($destination !== '', 'Destination is required.');
    ensure($source !== $destination, 'Source and destination must be different.');

    $trainNumber = ensureMaxStringLength($trainNumber, 10, 'Train number');
    $trainName = ensureMaxStringLength($trainName, 150, 'Train name');
    $source = ensureMaxStringLength($source, 100, 'Source');
    $destination = ensureMaxStringLength($destination, 100, 'Destination');

    $totalSeats = $sleeperSeats + $ac3Seats + $ac2Seats + $ac1Seats;
    ensure($totalSeats > 0, 'At least one seat must be configured.');

    if ($trainId > 0) {
        ensure(getTrainById($pdo, $trainId) !== null, 'Train not found.', 404);
    }

    $existingNumber = $pdo->prepare(
        'SELECT id
         FROM trains
         WHERE train_number = :train_number
           AND id != :train_id
         LIMIT 1'
    );
    $existingNumber->execute([
        'train_number' => $trainNumber,
        'train_id' => $trainId,
    ]);
    ensure(!$existingNumber->fetch(), 'Train number already exists.', 409);

    if ($trainId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE trains
             SET train_number = :train_number,
                 train_name = :train_name,
                 source = :source,
                 destination = :destination,
                 departure_time = :departure_time,
                 arrival_time = :arrival_time,
                 price = :price,
                 total_seats = :total_seats,
                 sleeper_seats = :sleeper_seats,
                 ac3_seats = :ac3_seats,
                 ac2_seats = :ac2_seats,
                 ac1_seats = :ac1_seats,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :train_id'
        );
        $params = [
            'train_id' => $trainId,
            'train_number' => $trainNumber,
            'train_name' => $trainName,
            'source' => $source,
            'destination' => $destination,
            'departure_time' => $departureTime,
            'arrival_time' => $arrivalTime,
            'price' => $basePrice,
            'total_seats' => $totalSeats,
            'sleeper_seats' => $sleeperSeats,
            'ac3_seats' => $ac3Seats,
            'ac2_seats' => $ac2Seats,
            'ac1_seats' => $ac1Seats,
            'is_active' => $isActive,
        ];
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO trains (
                train_number,
                train_name,
                source,
                destination,
                departure_time,
                arrival_time,
                price,
                total_seats,
                sleeper_seats,
                ac3_seats,
                ac2_seats,
                ac1_seats,
                is_active,
                created_at,
                updated_at
            ) VALUES (
                :train_number,
                :train_name,
                :source,
                :destination,
                :departure_time,
                :arrival_time,
                :price,
                :total_seats,
                :sleeper_seats,
                :ac3_seats,
                :ac2_seats,
                :ac1_seats,
                :is_active,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )'
        );
        $params = [
            'train_number' => $trainNumber,
            'train_name' => $trainName,
            'source' => $source,
            'destination' => $destination,
            'departure_time' => $departureTime,
            'arrival_time' => $arrivalTime,
            'price' => $basePrice,
            'total_seats' => $totalSeats,
            'sleeper_seats' => $sleeperSeats,
            'ac3_seats' => $ac3Seats,
            'ac2_seats' => $ac2Seats,
            'ac1_seats' => $ac1Seats,
            'is_active' => $isActive,
        ];
    }

    $stmt->execute($params);

    $savedTrainId = $trainId > 0 ? $trainId : (int) $pdo->lastInsertId();
    $train = getTrainById($pdo, $savedTrainId);
    ensure($train !== null, 'Train could not be loaded after save.', 500);

    return hydrateTrainRow($train);
}

function deleteAdminTrain(PDO $pdo, int $trainId): void
{
    ensure($trainId > 0, 'Valid train_id is required.');
    ensure(getTrainById($pdo, $trainId) !== null, 'Train not found.', 404);

    $bookingCheck = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE train_id = :train_id');
    $bookingCheck->execute(['train_id' => $trainId]);
    ensure((int) $bookingCheck->fetchColumn() === 0, 'Train cannot be deleted because bookings already exist. Set it inactive instead.', 409);

    $stmt = $pdo->prepare('DELETE FROM trains WHERE id = :train_id');
    $stmt->execute(['train_id' => $trainId]);
}

function fetchAdminDashboardStats(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COUNT(*) FROM users WHERE status = "BLOCKED") AS blocked_users,
            (SELECT COUNT(*) FROM trains) AS total_trains,
            (SELECT COUNT(*) FROM trains WHERE is_active = 1) AS active_trains,
            (SELECT COUNT(*) FROM bookings) AS total_bookings,
            (SELECT COUNT(*) FROM bookings WHERE status = "CONFIRMED") AS confirmed_bookings,
            (SELECT COUNT(*) FROM bookings WHERE status = "WAITING") AS waiting_bookings,
            (SELECT COUNT(*) FROM bookings WHERE status = "CANCELLED") AS cancelled_bookings,
            (SELECT COALESCE(SUM(price), 0) FROM bookings WHERE status = "CONFIRMED") AS confirmed_revenue'
    );
    $stmt->execute();
    $stats = $stmt->fetch();

    return [
        'total_users' => (int) ($stats['total_users'] ?? 0),
        'blocked_users' => (int) ($stats['blocked_users'] ?? 0),
        'total_trains' => (int) ($stats['total_trains'] ?? 0),
        'active_trains' => (int) ($stats['active_trains'] ?? 0),
        'total_bookings' => (int) ($stats['total_bookings'] ?? 0),
        'confirmed_bookings' => (int) ($stats['confirmed_bookings'] ?? 0),
        'waiting_bookings' => (int) ($stats['waiting_bookings'] ?? 0),
        'cancelled_bookings' => (int) ($stats['cancelled_bookings'] ?? 0),
        'confirmed_revenue' => (float) ($stats['confirmed_revenue'] ?? 0),
        'confirmed_revenue_label' => formatCurrency((float) ($stats['confirmed_revenue'] ?? 0)),
    ];
}

function normalizeAdminPage(mixed $value, int $defaultPage = 1): int
{
    $raw = sanitizeString($value);
    if ($raw === '') {
        return $defaultPage;
    }

    $page = filter_var(
        $raw,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100000]]
    );
    ensure($page !== false, 'Page must be a positive integer.');

    return (int) $page;
}

function normalizeAdminLimit(mixed $value, int $defaultLimit = 5): ?int
{
    $raw = strtolower(sanitizeString($value));
    if ($raw === '') {
        return $defaultLimit;
    }

    if ($raw === 'all') {
        return null;
    }

    $limit = filter_var(
        $raw,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100]]
    );
    ensure($limit !== false, 'Limit must be between 1 and 100, or "all".');

    return (int) $limit;
}

function buildAdminBookingScope(array $filters = []): array
{
    $params = [];
    $fromSql = ' FROM bookings b
                 INNER JOIN trains t ON t.id = b.train_id
                 INNER JOIN users u ON u.id = b.user_id
                 LEFT JOIN waiting_list wl ON wl.booking_id = b.id
                 WHERE 1 = 1';

    if (!empty($filters['train_id'])) {
        $fromSql .= ' AND b.train_id = :train_id';
        $params['train_id'] = (int) $filters['train_id'];
    }

    $travelDate = normalizeAdminDate($filters['travel_date'] ?? $filters['date'] ?? null);
    if ($travelDate !== null) {
        $fromSql .= ' AND b.travel_date = :travel_date';
        $params['travel_date'] = $travelDate;
    }

    $status = normalizeStatusFilter((string) ($filters['status'] ?? 'ALL'));
    if ($status !== null) {
        $fromSql .= ' AND b.status = :status';
        $params['status'] = $status;
    }

    $ticketType = sanitizeString($filters['ticket_type'] ?? '');
    if ($ticketType !== '') {
        $fromSql .= ' AND b.ticket_type = :ticket_type';
        $params['ticket_type'] = normalizeTicketType($ticketType);
    }

    $pnr = sanitizeString($filters['pnr'] ?? '');
    if ($pnr !== '') {
        $fromSql .= ' AND b.pnr = :pnr';
        $params['pnr'] = $pnr;
    }

    $dateFrom = normalizeAdminDate($filters['date_from'] ?? null);
    $dateTo = normalizeAdminDate($filters['date_to'] ?? null);
    $fromSql .= buildAdminDateRangeSql('b.travel_date', $dateFrom, $dateTo, $params, 'booking_date');

    return [
        'from_sql' => $fromSql,
        'params' => $params,
    ];
}

function fetchAdminBookingCollection(PDO $pdo, array $filters = []): array
{
    $paginationLimit = normalizeAdminLimit($filters['limit'] ?? null, 5);
    $requestedPage = normalizeAdminPage($filters['page'] ?? 1);
    $scope = buildAdminBookingScope($filters);

    $countStmt = $pdo->prepare('SELECT COUNT(DISTINCT b.id)' . $scope['from_sql']);
    $countStmt->execute($scope['params']);
    $totalBookings = (int) $countStmt->fetchColumn();

    $statsStmt = $pdo->prepare(
        'SELECT
            COUNT(DISTINCT b.id) AS total_count,
            COALESCE(SUM(CASE WHEN b.status = "CONFIRMED" THEN 1 ELSE 0 END), 0) AS confirmed_count,
            COALESCE(SUM(CASE WHEN b.status = "WAITING" THEN 1 ELSE 0 END), 0) AS waiting_count,
            COALESCE(SUM(CASE WHEN b.status = "CANCELLED" THEN 1 ELSE 0 END), 0) AS cancelled_count'
        . $scope['from_sql']
    );
    $statsStmt->execute($scope['params']);
    $statsRow = $statsStmt->fetch() ?: [];

    $totalPages = $paginationLimit === null
        ? ($totalBookings > 0 ? 1 : 0)
        : (int) ceil($totalBookings / $paginationLimit);
    $currentPage = 1;
    $offset = 0;

    if ($paginationLimit !== null) {
        $currentPage = min($requestedPage, max(1, $totalPages));
        $offset = ($currentPage - 1) * $paginationLimit;
    }

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
                wl.queue_no,
                u.name AS user_name,
                u.email AS user_email,
                u.phone AS user_phone,
                u.role AS user_role,
                u.status AS user_status'
        . $scope['from_sql']
        . ' ORDER BY b.created_at DESC, b.id DESC';

    if ($paginationLimit !== null) {
        $sql .= ' LIMIT :limit OFFSET :offset';
    }

    $stmt = $pdo->prepare($sql);
    foreach ($scope['params'] as $key => $value) {
        $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    if ($paginationLimit !== null) {
        $stmt->bindValue(':limit', $paginationLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }

    $stmt->execute();

    $bookings = array_map(
        static function (array $row): array {
            return array_merge(hydrateBookingRow($row), [
                'user_name' => $row['user_name'],
                'user_email' => $row['user_email'],
                'user_phone' => $row['user_phone'],
                'user_role' => $row['user_role'],
                'user_status' => $row['user_status'],
            ]);
        },
        $stmt->fetchAll()
    );

    $showingFrom = $totalBookings === 0 ? 0 : ($paginationLimit === null ? 1 : ($offset + 1));
    $showingTo = $totalBookings === 0
        ? 0
        : ($paginationLimit === null ? $totalBookings : min($offset + $paginationLimit, $totalBookings));

    return [
        'bookings' => $bookings,
        'pagination' => [
            'page' => $currentPage,
            'limit' => $paginationLimit,
            'total' => $totalBookings,
            'total_pages' => $totalPages,
            'has_previous' => $paginationLimit !== null && $currentPage > 1,
            'has_next' => $paginationLimit !== null && $currentPage < $totalPages,
            'showing_from' => $showingFrom,
            'showing_to' => $showingTo,
            'is_all' => $paginationLimit === null,
        ],
        'stats' => [
            'total' => (int) ($statsRow['total_count'] ?? $totalBookings),
            'confirmed' => (int) ($statsRow['confirmed_count'] ?? 0),
            'waiting' => (int) ($statsRow['waiting_count'] ?? 0),
            'cancelled' => (int) ($statsRow['cancelled_count'] ?? 0),
        ],
    ];
}

function fetchAdminBookings(PDO $pdo, array $filters = []): array
{
    if (!array_key_exists('limit', $filters) && !array_key_exists('page', $filters)) {
        $filters['limit'] = 'all';
    }

    return fetchAdminBookingCollection($pdo, $filters)['bookings'];
}

function fetchAdminWaitingList(PDO $pdo, array $filters = []): array
{
    $params = [];
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
                wl.queue_no,
                u.name AS user_name,
                u.email AS user_email,
                u.phone AS user_phone,
                u.role AS user_role,
                u.status AS user_status
            FROM waiting_list wl
            INNER JOIN bookings b ON b.id = wl.booking_id
            INNER JOIN trains t ON t.id = b.train_id
            INNER JOIN users u ON u.id = b.user_id
            WHERE b.status = "WAITING"';

    if (!empty($filters['train_id'])) {
        $sql .= ' AND b.train_id = :train_id';
        $params['train_id'] = (int) $filters['train_id'];
    }

    $travelDate = normalizeAdminDate($filters['travel_date'] ?? $filters['date'] ?? null);
    if ($travelDate !== null) {
        $sql .= ' AND b.travel_date = :travel_date';
        $params['travel_date'] = $travelDate;
    }

    $ticketType = sanitizeString($filters['ticket_type'] ?? '');
    if ($ticketType !== '') {
        $sql .= ' AND b.ticket_type = :ticket_type';
        $params['ticket_type'] = normalizeTicketType($ticketType);
    }

    $sql .= ' ORDER BY b.train_id ASC, b.travel_date ASC, b.ticket_type ASC, wl.queue_no ASC, wl.created_at ASC, wl.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $positions = [];
    $waitingList = [];

    foreach ($rows as $row) {
        $booking = array_merge(hydrateBookingRow($row), [
            'user_name' => $row['user_name'],
            'user_email' => $row['user_email'],
            'user_phone' => $row['user_phone'],
            'user_role' => $row['user_role'],
            'user_status' => $row['user_status'],
        ]);

        $groupKey = implode(':', [
            $booking['train_id'],
            $booking['travel_date'],
            $booking['ticket_type'],
        ]);
        $positions[$groupKey] = ($positions[$groupKey] ?? 0) + 1;
        $booking['current_position'] = $positions[$groupKey];
        $booking['display_status'] = 'WL' . $positions[$groupKey];
        $waitingList[] = $booking;
    }

    return $waitingList;
}

function fetchAdminUsers(PDO $pdo, array $filters = []): array
{
    $params = [];
    $sql = 'SELECT
                u.id,
                u.name,
                u.email,
                u.phone,
                u.role,
                u.status,
                u.created_at,
                u.updated_at,
                COALESCE(stats.total_bookings, 0) AS total_bookings,
                COALESCE(stats.confirmed_bookings, 0) AS confirmed_bookings,
                COALESCE(stats.waiting_bookings, 0) AS waiting_bookings,
                COALESCE(stats.cancelled_bookings, 0) AS cancelled_bookings
            FROM users u
            LEFT JOIN (
                SELECT
                    user_id,
                    COUNT(*) AS total_bookings,
                    SUM(CASE WHEN status = "CONFIRMED" THEN 1 ELSE 0 END) AS confirmed_bookings,
                    SUM(CASE WHEN status = "WAITING" THEN 1 ELSE 0 END) AS waiting_bookings,
                    SUM(CASE WHEN status = "CANCELLED" THEN 1 ELSE 0 END) AS cancelled_bookings
                FROM bookings
                GROUP BY user_id
            ) stats ON stats.user_id = u.id
            WHERE 1 = 1';

    $status = sanitizeString($filters['status'] ?? '');
    if ($status !== '') {
        $sql .= ' AND u.status = :status';
        $params['status'] = normalizeUserStatus($status);
    }

    $role = sanitizeString($filters['role'] ?? '');
    if ($role !== '') {
        $sql .= ' AND u.role = :role';
        $params['role'] = normalizeUserRole($role);
    }

    $sql .= ' ORDER BY u.created_at DESC, u.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(
        static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'role' => $row['role'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'total_bookings' => (int) $row['total_bookings'],
                'confirmed_bookings' => (int) $row['confirmed_bookings'],
                'waiting_bookings' => (int) $row['waiting_bookings'],
                'cancelled_bookings' => (int) $row['cancelled_bookings'],
            ];
        },
        $stmt->fetchAll()
    );
}

function normalizeAdminSearchQuery(mixed $value): string
{
    $query = preg_replace('/\s+/u', ' ', sanitizeString($value));
    $query = trim((string) $query);
    if ($query === '') {
        return '';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
    ensure($length <= 80, 'Search query must be 80 characters or fewer.');

    return $query;
}

function buildAdminSearchLike(string $value): string
{
    return '%' . str_replace(
        ['\\', '%', '_'],
        ['\\\\', '\%', '\_'],
        $value
    ) . '%';
}

function fetchAdminGlobalSearch(PDO $pdo, string $query, int $limitPerGroup = 5): array
{
    $normalizedQuery = normalizeAdminSearchQuery($query);
    if ($normalizedQuery === '') {
        return [
            'users' => [],
            'bookings' => [],
            'trains' => [],
        ];
    }

    $queryLength = function_exists('mb_strlen') ? mb_strlen($normalizedQuery) : strlen($normalizedQuery);
    if ($queryLength < 2) {
        return [
            'users' => [],
            'bookings' => [],
            'trains' => [],
        ];
    }

    $limit = max(1, min($limitPerGroup, 10));
    $searchLike = buildAdminSearchLike($normalizedQuery);
    $isNumericQuery = ctype_digit($normalizedQuery);
    $queryId = $isNumericQuery ? (int) $normalizedQuery : 0;

    $usersSql = 'SELECT
                    u.id,
                    u.name,
                    u.email,
                    u.status,
                    u.role,
                    COALESCE(stats.total_bookings, 0) AS total_bookings
                 FROM users u
                 LEFT JOIN (
                    SELECT
                        user_id,
                        COUNT(*) AS total_bookings
                    FROM bookings
                    GROUP BY user_id
                 ) stats ON stats.user_id = u.id
                 WHERE CAST(u.id AS CHAR) LIKE :search_like_id
                    OR u.name LIKE :search_like_name
                    OR u.email LIKE :search_like_email
                 ORDER BY
                    CASE
                        WHEN :has_query_id = 1 AND u.id = :query_id THEN 0
                        WHEN u.email = :query_exact_email THEN 1
                        WHEN u.name = :query_exact_name THEN 2
                        ELSE 3
                    END,
                    u.created_at DESC,
                    u.id DESC
                 LIMIT :limit';
    logSqlQuery($usersSql, [
        'search_like' => $searchLike,
        'query_exact' => $normalizedQuery,
        'has_query_id' => $isNumericQuery ? 1 : 0,
        'query_id' => $queryId,
        'limit' => $limit,
    ], [
        'module' => 'admin_global_search',
        'group' => 'users',
        'query' => $normalizedQuery,
    ]);
    $usersStmt = $pdo->prepare($usersSql);
    $usersStmt->bindValue(':search_like_id', $searchLike, PDO::PARAM_STR);
    $usersStmt->bindValue(':search_like_name', $searchLike, PDO::PARAM_STR);
    $usersStmt->bindValue(':search_like_email', $searchLike, PDO::PARAM_STR);
    $usersStmt->bindValue(':query_exact_email', $normalizedQuery, PDO::PARAM_STR);
    $usersStmt->bindValue(':query_exact_name', $normalizedQuery, PDO::PARAM_STR);
    $usersStmt->bindValue(':has_query_id', $isNumericQuery ? 1 : 0, PDO::PARAM_INT);
    $usersStmt->bindValue(':query_id', $queryId, PDO::PARAM_INT);
    $usersStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $usersStmt->execute();
    $users = array_map(
        static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'status' => $row['status'],
                'role' => $row['role'],
                'total_bookings' => (int) $row['total_bookings'],
            ];
        },
        $usersStmt->fetchAll()
    );

    $bookingsSql = 'SELECT
                        b.id,
                        b.user_id,
                        b.train_id,
                        b.travel_date,
                        b.passenger_name,
                        b.ticket_type,
                        b.seat_number,
                        b.pnr,
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
                    WHERE b.pnr LIKE :search_like_pnr
                       OR b.passenger_name LIKE :search_like_passenger
                       OR b.status LIKE :search_like_status
                       OR b.ticket_type LIKE :search_like_ticket_type
                    ORDER BY
                        CASE
                            WHEN b.pnr = :query_exact_pnr THEN 0
                            WHEN b.passenger_name = :query_exact_passenger THEN 1
                            WHEN b.ticket_type = :query_exact_ticket_type THEN 2
                            WHEN b.status = :query_exact_status THEN 3
                            ELSE 4
                        END,
                        b.created_at DESC,
                        b.id DESC
                    LIMIT :limit';
    logSqlQuery($bookingsSql, [
        'search_like' => $searchLike,
        'query_exact' => $normalizedQuery,
        'limit' => $limit,
    ], [
        'module' => 'admin_global_search',
        'group' => 'bookings',
        'query' => $normalizedQuery,
    ]);
    $bookingsStmt = $pdo->prepare($bookingsSql);
    $bookingsStmt->bindValue(':search_like_pnr', $searchLike, PDO::PARAM_STR);
    $bookingsStmt->bindValue(':search_like_passenger', $searchLike, PDO::PARAM_STR);
    $bookingsStmt->bindValue(':search_like_status', $searchLike, PDO::PARAM_STR);
    $bookingsStmt->bindValue(':search_like_ticket_type', $searchLike, PDO::PARAM_STR);
    $bookingsStmt->bindValue(':query_exact_pnr', $normalizedQuery, PDO::PARAM_STR);
    $bookingsStmt->bindValue(':query_exact_passenger', $normalizedQuery, PDO::PARAM_STR);
    $bookingsStmt->bindValue(':query_exact_ticket_type', $normalizedQuery, PDO::PARAM_STR);
    $bookingsStmt->bindValue(':query_exact_status', $normalizedQuery, PDO::PARAM_STR);
    $bookingsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $bookingsStmt->execute();
    $bookings = array_map(
        static function (array $row): array {
            $queueNo = isset($row['queue_no']) && $row['queue_no'] !== null ? (int) $row['queue_no'] : null;
            $displayStatus = $row['status'] === 'WAITING' && $queueNo !== null
                ? 'WL' . $queueNo
                : $row['status'];

            return [
                'id' => (int) $row['id'],
                'pnr' => $row['pnr'],
                'passenger_name' => $row['passenger_name'],
                'ticket_type' => $row['ticket_type'],
                'status' => $row['status'],
                'display_status' => $displayStatus,
                'travel_date' => $row['travel_date'],
                'train_id' => (int) $row['train_id'],
                'train_name' => $row['train_name'],
                'train_number' => $row['train_number'],
                'source' => $row['source'],
                'destination' => $row['destination'],
                'seat_number' => $row['seat_number'] !== null ? (int) $row['seat_number'] : null,
                'wl_number' => $queueNo,
            ];
        },
        $bookingsStmt->fetchAll()
    );

    $trainsSql = 'SELECT
                      id,
                      train_number,
                      train_name,
                      source,
                      destination,
                      is_active
                  FROM trains
                  WHERE train_number LIKE :search_like_number
                     OR train_name LIKE :search_like_name
                     OR source LIKE :search_like_source
                     OR destination LIKE :search_like_destination
                  ORDER BY
                      CASE
                          WHEN train_number = :query_exact_number THEN 0
                          WHEN train_name = :query_exact_name THEN 1
                          ELSE 2
                      END,
                      train_name ASC,
                      train_number ASC
                  LIMIT :limit';
    logSqlQuery($trainsSql, [
        'search_like' => $searchLike,
        'query_exact' => $normalizedQuery,
        'limit' => $limit,
    ], [
        'module' => 'admin_global_search',
        'group' => 'trains',
        'query' => $normalizedQuery,
    ]);
    $trainsStmt = $pdo->prepare($trainsSql);
    $trainsStmt->bindValue(':search_like_number', $searchLike, PDO::PARAM_STR);
    $trainsStmt->bindValue(':search_like_name', $searchLike, PDO::PARAM_STR);
    $trainsStmt->bindValue(':search_like_source', $searchLike, PDO::PARAM_STR);
    $trainsStmt->bindValue(':search_like_destination', $searchLike, PDO::PARAM_STR);
    $trainsStmt->bindValue(':query_exact_number', $normalizedQuery, PDO::PARAM_STR);
    $trainsStmt->bindValue(':query_exact_name', $normalizedQuery, PDO::PARAM_STR);
    $trainsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $trainsStmt->execute();
    $trains = array_map(
        static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'train_number' => $row['train_number'],
                'train_name' => $row['train_name'],
                'source' => $row['source'],
                'destination' => $row['destination'],
                'is_active' => (int) $row['is_active'],
            ];
        },
        $trainsStmt->fetchAll()
    );

    return [
        'users' => $users,
        'bookings' => $bookings,
        'trains' => $trains,
    ];
}

function updateAdminUserStatus(PDO $pdo, int $userId, string $status, array $actingAdmin): array
{
    ensure($userId > 0, 'Valid user_id is required.');
    $normalizedStatus = normalizeUserStatus($status);
    ensure((int) $actingAdmin['id'] !== $userId, 'You cannot change your own admin account status.', 409);

    $stmt = $pdo->prepare(
        'UPDATE users
         SET status = :status,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :user_id'
    );
    $stmt->execute([
        'status' => $normalizedStatus,
        'user_id' => $userId,
    ]);

    $userStmt = $pdo->prepare(
        'SELECT id, name, email, phone, role, status, created_at, updated_at
         FROM users
         WHERE id = :user_id
         LIMIT 1'
    );
    $userStmt->execute(['user_id' => $userId]);
    $row = $userStmt->fetch();
    ensure($row !== false, 'User not found.', 404);

    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'role' => $row['role'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function fetchAdminReports(PDO $pdo, array $filters = []): array
{
    $dateFrom = normalizeAdminDate($filters['date_from'] ?? null);
    $dateTo = normalizeAdminDate($filters['date_to'] ?? null);
    $trainId = (int) ($filters['train_id'] ?? 0);

    $params = [];
    $bookingScopeSql = ' WHERE 1 = 1';
    $bookingScopeSql .= buildAdminDateRangeSql('b.travel_date', $dateFrom, $dateTo, $params, 'report_date');
    if ($trainId > 0) {
        $bookingScopeSql .= ' AND b.train_id = :report_train_id';
        $params['report_train_id'] = $trainId;
    }

    $bookingsPerTrainStmt = $pdo->prepare(
        'SELECT
            t.id AS train_id,
            t.train_number,
            t.train_name,
            COUNT(b.id) AS total_bookings,
            SUM(CASE WHEN b.status = "CONFIRMED" THEN 1 ELSE 0 END) AS confirmed_bookings,
            SUM(CASE WHEN b.status = "WAITING" THEN 1 ELSE 0 END) AS waiting_bookings,
            SUM(CASE WHEN b.status = "CANCELLED" THEN 1 ELSE 0 END) AS cancelled_bookings
         FROM trains t
         LEFT JOIN bookings b ON b.train_id = t.id'
         . str_replace('b.', '', $bookingScopeSql)
         . '
         GROUP BY t.id, t.train_number, t.train_name
         ORDER BY total_bookings DESC, t.train_name ASC'
    );
    $bookingsPerTrainStmt->execute($params);
    $bookingsPerTrain = $bookingsPerTrainStmt->fetchAll();

    $revenueStmt = $pdo->prepare(
        'SELECT
            b.ticket_type,
            COUNT(*) AS confirmed_bookings,
            SUM(b.price) AS gross_revenue
         FROM bookings b'
         . $bookingScopeSql
         . ' AND b.status = "CONFIRMED"
         GROUP BY b.ticket_type
         ORDER BY gross_revenue DESC, b.ticket_type ASC'
    );
    $revenueStmt->execute($params);
    $revenueByTicketType = $revenueStmt->fetchAll();

    $cancellationStmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_bookings,
            SUM(CASE WHEN b.status = "CANCELLED" THEN 1 ELSE 0 END) AS cancelled_bookings
         FROM bookings b'
         . $bookingScopeSql
    );
    $cancellationStmt->execute($params);
    $cancellation = $cancellationStmt->fetch() ?: ['total_bookings' => 0, 'cancelled_bookings' => 0];
    $totalBookings = (int) ($cancellation['total_bookings'] ?? 0);
    $cancelledBookings = (int) ($cancellation['cancelled_bookings'] ?? 0);

    $waitingTrendStmt = $pdo->prepare(
        'SELECT
            b.travel_date,
            b.ticket_type,
            COUNT(*) AS waiting_count
         FROM bookings b'
         . $bookingScopeSql
         . ' AND b.status = "WAITING"
         GROUP BY b.travel_date, b.ticket_type
         ORDER BY b.travel_date ASC, b.ticket_type ASC'
    );
    $waitingTrendStmt->execute($params);
    $waitingTrends = $waitingTrendStmt->fetchAll();

    return [
        'filters' => [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'train_id' => $trainId > 0 ? $trainId : null,
        ],
        'bookings_per_train' => array_map(
            static function (array $row): array {
                return [
                    'train_id' => (int) $row['train_id'],
                    'train_number' => $row['train_number'],
                    'train_name' => $row['train_name'],
                    'total_bookings' => (int) $row['total_bookings'],
                    'confirmed_bookings' => (int) $row['confirmed_bookings'],
                    'waiting_bookings' => (int) $row['waiting_bookings'],
                    'cancelled_bookings' => (int) $row['cancelled_bookings'],
                ];
            },
            $bookingsPerTrain
        ),
        'revenue_by_ticket_type' => array_map(
            static function (array $row): array {
                $revenue = (float) ($row['gross_revenue'] ?? 0);

                return [
                    'ticket_type' => $row['ticket_type'],
                    'confirmed_bookings' => (int) $row['confirmed_bookings'],
                    'gross_revenue' => $revenue,
                    'gross_revenue_label' => formatCurrency($revenue),
                ];
            },
            $revenueByTicketType
        ),
        'cancellation_rate' => [
            'total_bookings' => $totalBookings,
            'cancelled_bookings' => $cancelledBookings,
            'rate_percent' => $totalBookings > 0 ? round(($cancelledBookings / $totalBookings) * 100, 2) : 0.0,
        ],
        'waiting_list_trends' => array_map(
            static function (array $row): array {
                return [
                    'travel_date' => $row['travel_date'],
                    'ticket_type' => $row['ticket_type'],
                    'waiting_count' => (int) $row['waiting_count'],
                ];
            },
            $waitingTrends
        ),
    ];
}
