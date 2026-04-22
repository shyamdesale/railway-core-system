<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'minirail_db';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';
const DB_CONNECT_TIMEOUT = 5;
const DB_ERROR_LOG = __DIR__ . '/../logs/error.log';

function getPdoOptions(): array
{
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => DB_CONNECT_TIMEOUT,
    ];
}

function createPdoConnection(string $dsn): PDO
{
    return new PDO($dsn, DB_USER, DB_PASS, getPdoOptions());
}

function logDatabaseBootstrapFailure(Throwable $exception, string $stage): void
{
    $logDirectory = dirname(DB_ERROR_LOG);
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0775, true);
    }

    $message = sprintf(
        '[%s] [db:%s] %s in %s:%d %s',
        date('c'),
        $stage,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );

    error_log($message . PHP_EOL, 3, DB_ERROR_LOG);
}

function initializeDatabaseSchema(PDO $pdo): void
{
    try {
        ensureDatabaseSchema($pdo);
    } catch (Throwable $exception) {
        logDatabaseBootstrapFailure($exception, 'schema');

        throw new RuntimeException(
            'Database schema synchronization failed. Verify that MySQL is running and that the live schema matches database/minirail.sql.',
            0,
            $exception
        );
    }
}

function getPDO(): PDO
{
    static $pdo = null;
    static $schemaReady = false;

    if ($pdo instanceof PDO) {
        if (!$schemaReady) {
            initializeDatabaseSchema($pdo);
            $schemaReady = true;
        }

        return $pdo;
    }

    try {
        $server = createPdoConnection('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET);
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET ' . DB_CHARSET . ' COLLATE utf8mb4_unicode_ci'
        );

        $pdo = createPdoConnection('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET);
    } catch (Throwable $exception) {
        logDatabaseBootstrapFailure($exception, 'connection');

        throw new RuntimeException(
            'Database connection failed. Verify that MySQL is running and that config/db.php credentials are correct.',
            0,
            $exception
        );
    }

    initializeDatabaseSchema($pdo);
    $schemaReady = true;

    return $pdo;
}

function ensureDatabaseSchema(PDO $pdo): void
{
    createCoreTables($pdo);
    syncLegacySchema($pdo);
    dropLegacyIndexes($pdo);
    ensureDatabaseIndexes($pdo);
    seedTrainData($pdo);
    seedAdminUser($pdo);
    normalizeExistingWaitingQueues($pdo);
}

function createCoreTables(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL,
            phone VARCHAR(20) NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('USER','ADMIN') NOT NULL DEFAULT 'USER',
            status ENUM('ACTIVE','BLOCKED') NOT NULL DEFAULT 'ACTIVE',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_users_email (email),
            UNIQUE KEY uq_users_phone (phone),
            KEY idx_users_role_status (role, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    $pdo->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS trains (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            train_number VARCHAR(10) NOT NULL,
            train_name VARCHAR(150) NOT NULL,
            source VARCHAR(100) NOT NULL,
            destination VARCHAR(100) NOT NULL,
            departure_time TIME NOT NULL,
            arrival_time TIME NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            total_seats SMALLINT UNSIGNED NOT NULL DEFAULT 90,
            sleeper_seats SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            ac3_seats SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            ac2_seats SMALLINT UNSIGNED NOT NULL DEFAULT 20,
            ac1_seats SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_trains_train_number (train_number),
            KEY idx_route_lookup (source, destination, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    $pdo->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS bookings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            train_id BIGINT UNSIGNED NOT NULL,
            travel_date DATE NOT NULL,
            passenger_name VARCHAR(120) NOT NULL,
            age TINYINT UNSIGNED NULL,
            gender ENUM('M','F','O') NOT NULL DEFAULT 'O',
            ticket_type ENUM('Sleeper','3AC','2AC','1AC') NOT NULL DEFAULT 'Sleeper',
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            seat_number TINYINT UNSIGNED NULL,
            pnr VARCHAR(20) NOT NULL,
            request_token CHAR(36) NULL,
            status ENUM('CONFIRMED','WAITING','CANCELLED') NOT NULL DEFAULT 'CONFIRMED',
            cancelled_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_bookings_train FOREIGN KEY (train_id) REFERENCES trains(id) ON DELETE CASCADE,
            UNIQUE KEY uq_booking_pnr (pnr),
            UNIQUE KEY uq_booking_request_token (request_token),
            UNIQUE KEY uq_booking_seat (train_id, travel_date, ticket_type, seat_number),
            KEY idx_booking_lookup (train_id, travel_date, ticket_type, status),
            KEY idx_user_booking_lookup (user_id, created_at),
            KEY idx_booking_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    $pdo->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS waiting_list (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id BIGINT UNSIGNED NOT NULL,
            queue_no INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_waiting_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
            UNIQUE KEY uq_waiting_booking (booking_id),
            KEY idx_waiting_queue (queue_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );
}

function syncLegacySchema(PDO $pdo): void
{
    ensureColumn(
        $pdo,
        'users',
        'phone',
        "ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email"
    );
    ensureColumn(
        $pdo,
        'users',
        'role',
        "ALTER TABLE users ADD COLUMN role ENUM('USER','ADMIN') NOT NULL DEFAULT 'USER' AFTER password"
    );
    ensureColumn(
        $pdo,
        'users',
        'status',
        "ALTER TABLE users ADD COLUMN status ENUM('ACTIVE','BLOCKED') NOT NULL DEFAULT 'ACTIVE' AFTER role"
    );
    ensureColumn(
        $pdo,
        'users',
        'updated_at',
        "ALTER TABLE users ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
    );

    ensureColumn(
        $pdo,
        'trains',
        'train_number',
        "ALTER TABLE trains ADD COLUMN train_number VARCHAR(10) NULL AFTER id"
    );
    ensureColumn(
        $pdo,
        'trains',
        'is_active',
        "ALTER TABLE trains ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER total_seats"
    );
    ensureColumn(
        $pdo,
        'trains',
        'sleeper_seats',
        "ALTER TABLE trains ADD COLUMN sleeper_seats SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER total_seats"
    );
    ensureColumn(
        $pdo,
        'trains',
        'ac3_seats',
        "ALTER TABLE trains ADD COLUMN ac3_seats SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER sleeper_seats"
    );
    ensureColumn(
        $pdo,
        'trains',
        'ac2_seats',
        "ALTER TABLE trains ADD COLUMN ac2_seats SMALLINT UNSIGNED NOT NULL DEFAULT 20 AFTER ac3_seats"
    );
    ensureColumn(
        $pdo,
        'trains',
        'ac1_seats',
        "ALTER TABLE trains ADD COLUMN ac1_seats SMALLINT UNSIGNED NOT NULL DEFAULT 10 AFTER ac2_seats"
    );
    ensureColumn(
        $pdo,
        'trains',
        'updated_at',
        "ALTER TABLE trains ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
    );

    ensureColumn(
        $pdo,
        'bookings',
        'passenger_name',
        "ALTER TABLE bookings ADD COLUMN passenger_name VARCHAR(120) NULL AFTER travel_date"
    );
    ensureColumn(
        $pdo,
        'bookings',
        'age',
        "ALTER TABLE bookings ADD COLUMN age TINYINT UNSIGNED NULL AFTER passenger_name"
    );
    ensureColumn(
        $pdo,
        'bookings',
        'gender',
        "ALTER TABLE bookings ADD COLUMN gender ENUM('M','F','O') NOT NULL DEFAULT 'O' AFTER age"
    );
    ensureColumn(
        $pdo,
        'bookings',
        'ticket_type',
        "ALTER TABLE bookings ADD COLUMN ticket_type ENUM('Sleeper','3AC','2AC','1AC') NOT NULL DEFAULT 'Sleeper' AFTER gender"
    );
    ensureColumn(
        $pdo,
        'bookings',
        'price',
        "ALTER TABLE bookings ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ticket_type"
    );
    ensureColumn(
        $pdo,
        'bookings',
        'request_token',
        "ALTER TABLE bookings ADD COLUMN request_token CHAR(36) NULL AFTER pnr"
    );
    ensureColumn(
        $pdo,
        'bookings',
        'cancelled_at',
        "ALTER TABLE bookings ADD COLUMN cancelled_at DATETIME NULL AFTER status"
    );
    ensureColumn(
        $pdo,
        'bookings',
        'updated_at',
        "ALTER TABLE bookings ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
    );

    ensureColumn(
        $pdo,
        'waiting_list',
        'queue_no',
        "ALTER TABLE waiting_list ADD COLUMN queue_no INT UNSIGNED NULL AFTER booking_id"
    );
    ensureColumn(
        $pdo,
        'waiting_list',
        'created_at',
        "ALTER TABLE waiting_list ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER queue_no"
    );

    backfillLegacyData($pdo);
}

function ensureDatabaseIndexes(PDO $pdo): void
{
    ensureIndex(
        $pdo,
        'users',
        ['email'],
        'CREATE UNIQUE INDEX uq_users_email ON users (email)',
        true
    );
    ensureIndex(
        $pdo,
        'users',
        ['phone'],
        'CREATE UNIQUE INDEX uq_users_phone ON users (phone)',
        true
    );
    ensureIndex(
        $pdo,
        'users',
        ['role', 'status'],
        'CREATE INDEX idx_users_role_status ON users (role, status)'
    );
    ensureIndex(
        $pdo,
        'trains',
        ['train_number'],
        'CREATE UNIQUE INDEX uq_trains_train_number ON trains (train_number)',
        true
    );
    ensureIndex(
        $pdo,
        'trains',
        ['source', 'destination', 'is_active'],
        'CREATE INDEX idx_route_lookup ON trains (source, destination, is_active)'
    );
    ensureIndex(
        $pdo,
        'bookings',
        ['pnr'],
        'CREATE UNIQUE INDEX uq_booking_pnr ON bookings (pnr)',
        true
    );
    ensureIndex(
        $pdo,
        'bookings',
        ['request_token'],
        'CREATE UNIQUE INDEX uq_booking_request_token ON bookings (request_token)',
        true
    );
    ensureIndex(
        $pdo,
        'bookings',
        ['train_id', 'travel_date', 'ticket_type', 'seat_number'],
        'CREATE UNIQUE INDEX uq_booking_seat ON bookings (train_id, travel_date, ticket_type, seat_number)',
        true
    );
    ensureIndex(
        $pdo,
        'bookings',
        ['train_id', 'travel_date', 'ticket_type', 'status'],
        'CREATE INDEX idx_booking_lookup ON bookings (train_id, travel_date, ticket_type, status)'
    );
    ensureIndex(
        $pdo,
        'bookings',
        ['user_id', 'created_at'],
        'CREATE INDEX idx_user_booking_lookup ON bookings (user_id, created_at)'
    );
    ensureIndex(
        $pdo,
        'bookings',
        ['created_at'],
        'CREATE INDEX idx_booking_created_at ON bookings (created_at)'
    );
    ensureIndex(
        $pdo,
        'waiting_list',
        ['booking_id'],
        'CREATE UNIQUE INDEX uq_waiting_booking ON waiting_list (booking_id)',
        true
    );
    ensureIndex(
        $pdo,
        'waiting_list',
        ['queue_no'],
        'CREATE INDEX idx_waiting_queue ON waiting_list (queue_no)'
    );
}

function dropLegacyIndexes(PDO $pdo): void
{
    dropIndexIfExists($pdo, 'bookings', 'idx_duplicate_guard');
    dropIndexIfExists($pdo, 'bookings', 'unique_seat_per_train_date');
}

function ensureColumn(PDO $pdo, string $table, string $column, string $alterSql): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($alterSql);
    }
}

function ensureIndex(PDO $pdo, string $table, array $columns, string $createSql, bool $unique = false): void
{
    $stmt = $pdo->prepare(
        'SELECT index_name, non_unique, seq_in_index, column_name
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         ORDER BY index_name ASC, seq_in_index ASC'
    );
    $stmt->execute(['table_name' => $table]);

    $indexes = [];
    foreach ($stmt->fetchAll() as $row) {
        $indexName = (string) $row['index_name'];
        if ($indexName === 'PRIMARY') {
            continue;
        }

        if (!isset($indexes[$indexName])) {
            $indexes[$indexName] = [
                'unique' => (int) $row['non_unique'] === 0,
                'columns' => [],
            ];
        }

        $indexes[$indexName]['columns'][] = $row['column_name'];
    }

    foreach ($indexes as $index) {
        if ($index['columns'] === $columns && (!$unique || $index['unique'])) {
            return;
        }
    }

    if (preg_match('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+([^\s]+)\s+ON/i', $createSql, $matches) === 1) {
        $indexName = $matches[1];
        if (isset($indexes[$indexName])) {
            if ($indexes[$indexName]['columns'] === $columns && (!$unique || $indexes[$indexName]['unique'])) {
                return;
            }

            $pdo->exec(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $indexName));
        }
    }

    $pdo->exec($createSql);
}

function dropIndexIfExists(PDO $pdo, string $table, string $indexName): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'index_name' => $indexName,
    ]);

    if ((int) $stmt->fetchColumn() > 0) {
        $pdo->exec(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $indexName));
    }
}

function backfillLegacyData(PDO $pdo): void
{
    $pdo->exec("UPDATE users SET role = 'USER' WHERE role IS NULL OR role = ''");
    $pdo->exec("UPDATE users SET status = 'ACTIVE' WHERE status IS NULL OR status = ''");
    $pdo->exec('UPDATE users SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)');

    $stmt = $pdo->prepare('SELECT id FROM trains WHERE train_number IS NULL OR train_number = "" ORDER BY id ASC');
    $stmt->execute();
    $trainsWithoutNumber = $stmt->fetchAll();

    if ($trainsWithoutNumber !== []) {
        $updateTrainNumber = $pdo->prepare('UPDATE trains SET train_number = :train_number WHERE id = :id');
        foreach ($trainsWithoutNumber as $row) {
            $updateTrainNumber->execute([
                'train_number' => sprintf('MR%04d', (int) $row['id']),
                'id' => (int) $row['id'],
            ]);
        }
    }

    $pdo->exec('UPDATE trains SET sleeper_seats = 30 WHERE sleeper_seats IS NULL OR sleeper_seats <= 0');
    $pdo->exec('UPDATE trains SET ac3_seats = 30 WHERE ac3_seats IS NULL OR ac3_seats <= 0');
    $pdo->exec('UPDATE trains SET ac2_seats = 20 WHERE ac2_seats IS NULL OR ac2_seats <= 0');
    $pdo->exec('UPDATE trains SET ac1_seats = 10 WHERE ac1_seats IS NULL OR ac1_seats <= 0');
    $pdo->exec('UPDATE trains SET total_seats = sleeper_seats + ac3_seats + ac2_seats + ac1_seats');
    $pdo->exec('UPDATE trains SET is_active = 1 WHERE is_active IS NULL');
    $pdo->exec('UPDATE trains SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)');

    $pdo->exec(
        'UPDATE bookings b
         INNER JOIN users u ON u.id = b.user_id
         SET b.passenger_name = u.name
         WHERE (b.passenger_name IS NULL OR b.passenger_name = "")'
    );
    $pdo->exec("UPDATE bookings SET gender = 'O' WHERE gender IS NULL OR gender = ''");
    $pdo->exec("UPDATE bookings SET ticket_type = 'Sleeper' WHERE ticket_type IS NULL OR ticket_type = ''");
    $pdo->exec(
        "UPDATE bookings b
         INNER JOIN trains t ON t.id = b.train_id
         SET b.price = CASE b.ticket_type
             WHEN '3AC' THEN ROUND(t.price * 1.5, 2)
             WHEN '2AC' THEN ROUND(t.price * 2.0, 2)
             WHEN '1AC' THEN ROUND(t.price * 3.0, 2)
             ELSE ROUND(t.price, 2)
         END
         WHERE b.price IS NULL OR b.price = 0"
    );
    $pdo->exec(
        'UPDATE bookings
         SET cancelled_at = COALESCE(cancelled_at, created_at)
         WHERE status = "CANCELLED" AND cancelled_at IS NULL'
    );
    $pdo->exec('UPDATE bookings SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)');

    $legacyWaitingColumnExists = tableHasColumn($pdo, 'waiting_list', 'wl_number');
    $updateQueue = $pdo->prepare('UPDATE waiting_list SET queue_no = :queue_no WHERE id = :id');

    if ($legacyWaitingColumnExists) {
        // Older installs stored waiting-list positions as wl_number instead of queue_no.
        $selectWaiting = $pdo->prepare('SELECT id, queue_no, wl_number FROM waiting_list ORDER BY id ASC');
        $selectWaiting->execute();
        foreach ($selectWaiting->fetchAll() as $row) {
            if ((int) ($row['queue_no'] ?? 0) > 0) {
                continue;
            }

            $queueNo = max(1, (int) preg_replace('/[^0-9]/', '', (string) ($row['wl_number'] ?? '1')));
            $updateQueue->execute([
                'queue_no' => $queueNo,
                'id' => (int) $row['id'],
            ]);
        }
    }

    $missingTokens = $pdo->prepare('SELECT id FROM bookings WHERE request_token IS NULL OR request_token = "" ORDER BY id ASC');
    $missingTokens->execute();
    $updateToken = $pdo->prepare('UPDATE bookings SET request_token = :request_token WHERE id = :id');
    foreach ($missingTokens->fetchAll() as $row) {
        $updateToken->execute([
            'request_token' => generateBootstrapUuid(),
            'id' => (int) $row['id'],
        ]);
    }
}

function seedAdminUser(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = "ADMIN"');
    $stmt->execute();

    if ((int) $stmt->fetchColumn() > 0) {
        return;
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
            NULL,
            :password,
            "ADMIN",
            "ACTIVE",
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )'
    );

    $insert->execute([
        'name' => 'IndianRail Admin',
        'email' => 'admin@gmail.com',
        'password' => password_hash('admin@006', PASSWORD_BCRYPT),
    ]);
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function seedTrainData(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM trains');
    $stmt->execute();

    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $trains = [
        ['12951', 'MiniRail Western Express', 'Delhi', 'Mumbai', '06:15:00', '18:40:00', 1249.00, 90, 30, 30, 20, 10],
        ['12952', 'MiniRail Superfast Connect', 'Delhi', 'Mumbai', '09:00:00', '19:10:00', 1099.00, 90, 30, 30, 20, 10],
        ['12015', 'Capital MiniRail', 'Delhi', 'Jaipur', '07:20:00', '12:15:00', 699.00, 90, 30, 30, 20, 10],
        ['12621', 'Southern Breeze', 'Bangalore', 'Chennai', '06:45:00', '11:25:00', 599.00, 90, 30, 30, 20, 10],
        ['12760', 'Deccan Coast Link', 'Chennai', 'Hyderabad', '14:00:00', '23:30:00', 899.00, 90, 30, 30, 20, 10],
        ['19411', 'Desert Voyager', 'Ahmedabad', 'Jaipur', '05:55:00', '14:05:00', 779.00, 90, 30, 30, 20, 10],
        ['11008', 'Metro Intercity', 'Mumbai', 'Pune', '08:10:00', '11:00:00', 399.00, 90, 30, 30, 20, 10],
        ['17233', 'Night Arrow', 'Hyderabad', 'Bangalore', '22:00:00', '06:30:00', 949.00, 90, 30, 30, 20, 10],
        ['19019', 'Sunrise Line', 'Surat', 'Mumbai', '06:20:00', '10:20:00', 449.00, 90, 30, 30, 20, 10],
        ['12985', 'Royal Desert Return', 'Jaipur', 'Delhi', '16:40:00', '21:25:00', 689.00, 90, 30, 30, 20, 10],
    ];

    $insert = $pdo->prepare(
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
            ac1_seats
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
            :ac1_seats
        )'
    );

    foreach ($trains as $train) {
        $insert->execute([
            'train_number' => $train[0],
            'train_name' => $train[1],
            'source' => $train[2],
            'destination' => $train[3],
            'departure_time' => $train[4],
            'arrival_time' => $train[5],
            'price' => $train[6],
            'total_seats' => $train[7],
            'sleeper_seats' => $train[8],
            'ac3_seats' => $train[9],
            'ac2_seats' => $train[10],
            'ac1_seats' => $train[11],
        ]);
    }
}

function normalizeExistingWaitingQueues(PDO $pdo): void
{
    $hasLegacyWaitingLabel = tableHasColumn($pdo, 'waiting_list', 'wl_number');
    $journeys = $pdo->prepare(
        'SELECT DISTINCT b.train_id, b.travel_date, b.ticket_type
         FROM waiting_list wl
         INNER JOIN bookings b ON b.id = wl.booking_id
         WHERE b.status = "WAITING"
         ORDER BY b.train_id ASC, b.travel_date ASC, b.ticket_type ASC'
    );
    $journeys->execute();

    $selectQueue = $pdo->prepare(
        $hasLegacyWaitingLabel
            ? 'SELECT wl.id, wl.queue_no, wl.wl_number
               FROM waiting_list wl
               INNER JOIN bookings b ON b.id = wl.booking_id
               WHERE b.train_id = :train_id
                 AND b.travel_date = :travel_date
                 AND b.ticket_type = :ticket_type
                 AND b.status = "WAITING"
               ORDER BY wl.created_at ASC, wl.id ASC'
            : 'SELECT wl.id, wl.queue_no
               FROM waiting_list wl
               INNER JOIN bookings b ON b.id = wl.booking_id
               WHERE b.train_id = :train_id
                 AND b.travel_date = :travel_date
                 AND b.ticket_type = :ticket_type
                 AND b.status = "WAITING"
               ORDER BY wl.created_at ASC, wl.id ASC'
    );
    $updateQueue = $hasLegacyWaitingLabel
        ? $pdo->prepare('UPDATE waiting_list SET queue_no = :queue_no, wl_number = :wl_number WHERE id = :id')
        : $pdo->prepare('UPDATE waiting_list SET queue_no = :queue_no WHERE id = :id');

    foreach ($journeys->fetchAll() as $journey) {
        $selectQueue->execute([
            'train_id' => (int) $journey['train_id'],
            'travel_date' => $journey['travel_date'],
            'ticket_type' => $journey['ticket_type'],
        ]);

        $currentMaxQueueNo = 0;
        foreach ($selectQueue->fetchAll() as $row) {
            $currentQueueNo = (int) ($row['queue_no'] ?? 0);
            if ($currentQueueNo > 0) {
                $currentMaxQueueNo = max($currentMaxQueueNo, $currentQueueNo);
                continue;
            }

            $legacyQueueNo = max(0, (int) preg_replace('/[^0-9]/', '', (string) ($row['wl_number'] ?? '')));
            $assignedQueueNo = $legacyQueueNo > 0 ? $legacyQueueNo : ($currentMaxQueueNo + 1);
            $currentMaxQueueNo = max($currentMaxQueueNo, $assignedQueueNo);

            $params = [
                'queue_no' => $assignedQueueNo,
                'id' => (int) $row['id'],
            ];

            if ($hasLegacyWaitingLabel) {
                $params['wl_number'] = 'WL' . $assignedQueueNo;
            }

            $updateQueue->execute($params);
        }
    }
}

function generateBootstrapUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($bytes), 4)
    );
}
