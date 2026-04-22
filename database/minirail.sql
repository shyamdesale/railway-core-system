CREATE DATABASE IF NOT EXISTS minirail_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE minirail_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS waiting_list;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS trains;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20) NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('USER','ADMIN') NOT NULL DEFAULT 'USER',
    status ENUM('ACTIVE','BLOCKED') NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trains (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    train_number VARCHAR(10) NOT NULL UNIQUE,
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
    INDEX idx_route_lookup (source, destination, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    train_id BIGINT UNSIGNED NOT NULL,
    travel_date DATE NOT NULL,
    passenger_name VARCHAR(120) NOT NULL,
    age TINYINT UNSIGNED NULL,
    gender ENUM('M','F','O') NOT NULL DEFAULT 'O',
    ticket_type ENUM('Sleeper','3AC','2AC','1AC') NOT NULL DEFAULT 'Sleeper',
    price DECIMAL(10,2) NOT NULL,
    seat_number TINYINT UNSIGNED NULL,
    pnr VARCHAR(20) NOT NULL UNIQUE,
    request_token CHAR(36) NULL UNIQUE,
    status ENUM('CONFIRMED','WAITING','CANCELLED') NOT NULL DEFAULT 'CONFIRMED',
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_train FOREIGN KEY (train_id) REFERENCES trains(id) ON DELETE CASCADE,
    UNIQUE KEY uq_booking_seat (train_id, travel_date, ticket_type, seat_number),
    INDEX idx_booking_lookup (train_id, travel_date, ticket_type, status),
    INDEX idx_user_booking_lookup (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE waiting_list (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL UNIQUE,
    queue_no INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_waiting_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    INDEX idx_waiting_queue (queue_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO trains (
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
) VALUES
('12951', 'MiniRail Western Express', 'Delhi', 'Mumbai', '06:15:00', '18:40:00', 1249.00, 90, 30, 30, 20, 10),
('12952', 'MiniRail Superfast Connect', 'Delhi', 'Mumbai', '09:00:00', '19:10:00', 1099.00, 90, 30, 30, 20, 10),
('12015', 'Capital MiniRail', 'Delhi', 'Jaipur', '07:20:00', '12:15:00', 699.00, 90, 30, 30, 20, 10),
('12621', 'Southern Breeze', 'Bangalore', 'Chennai', '06:45:00', '11:25:00', 599.00, 90, 30, 30, 20, 10),
('12760', 'Deccan Coast Link', 'Chennai', 'Hyderabad', '14:00:00', '23:30:00', 899.00, 90, 30, 30, 20, 10),
('19411', 'Desert Voyager', 'Ahmedabad', 'Jaipur', '05:55:00', '14:05:00', 779.00, 90, 30, 30, 20, 10),
('11008', 'Metro Intercity', 'Mumbai', 'Pune', '08:10:00', '11:00:00', 399.00, 90, 30, 30, 20, 10),
('17233', 'Night Arrow', 'Hyderabad', 'Bangalore', '22:00:00', '06:30:00', 949.00, 90, 30, 30, 20, 10),
('19019', 'Sunrise Line', 'Surat', 'Mumbai', '06:20:00', '10:20:00', 449.00, 90, 30, 30, 20, 10),
('12985', 'Royal Desert Return', 'Jaipur', 'Delhi', '16:40:00', '21:25:00', 689.00, 90, 30, 30, 20, 10);

INSERT INTO users (
    name,
    email,
    phone,
    password,
    role,
    status
) VALUES (
    'IndianRail Admin',
    'admin@gmail.com',
    NULL,
    '$2y$12$NUTsZIVzcVitgraTftZtH.CROa3XNAOkRCk1yeHAUlVHslm8yht1K',
    'ADMIN',
    'ACTIVE'
);
