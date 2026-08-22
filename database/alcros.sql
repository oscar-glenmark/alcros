-- ALCROS Civil Registry System - MySQL Schema
-- Import via phpMyAdmin or: mysql -u root < database/alcros.sql

CREATE DATABASE IF NOT EXISTS alcros_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE alcros_db;

CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'Registrar',
    profile_photo_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_code VARCHAR(20) NOT NULL UNIQUE,
    citizen_name VARCHAR(150) NOT NULL,
    date_of_birth DATE DEFAULT NULL,
    sex ENUM('male','female') DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    phone VARCHAR(30) DEFAULT NULL,
    document_type ENUM('birth','death','marriage','cenomar') NOT NULL,
    purpose VARCHAR(255) DEFAULT NULL,
    id_front_path VARCHAR(255) DEFAULT NULL,
    id_back_path VARCHAR(255) DEFAULT NULL,
    privacy_agreed TINYINT(1) NOT NULL DEFAULT 0,
    notify_email TINYINT(1) NOT NULL DEFAULT 0,
    reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
    appointment_date DATE DEFAULT NULL,
    appointment_time TIME DEFAULT NULL,
    status ENUM('pending','verified','ready','completed','rejected') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_citizen (citizen_name)
) ENGINE=InnoDB; 

CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_code VARCHAR(20) NOT NULL UNIQUE,
    citizen_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    notify_email TINYINT(1) NOT NULL DEFAULT 0,
    reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
    service_type VARCHAR(100) NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status ENUM('scheduled','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
    source VARCHAR(32) NOT NULL DEFAULT 'standalone',
    tracking_code VARCHAR(20) DEFAULT NULL,
    id_front_path VARCHAR(255) DEFAULT NULL,
    id_back_path VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (appointment_date),
    INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS queue_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(10) NOT NULL,
    purpose ENUM('walk_in','appointment','document_claim') NOT NULL,
    status ENUM('waiting','serving','completed','skipped') NOT NULL DEFAULT 'waiting',
    citizen_name VARCHAR(150) DEFAULT NULL,
    reference_code VARCHAR(20) DEFAULT NULL,
    window_number INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    called_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_date (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS civil_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_type ENUM('birth','death','marriage') NOT NULL,
    registry_number VARCHAR(50) DEFAULT NULL,
    person_name VARCHAR(150) NOT NULL,
    birth_date DATE DEFAULT NULL,
    event_date DATE DEFAULT NULL,
    place VARCHAR(255) DEFAULT NULL,
    father_name VARCHAR(150) DEFAULT NULL,
    mother_name VARCHAR(150) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (record_type),
    INDEX idx_person (person_name),
    INDEX idx_deleted (deleted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default administrator (change password after first login in System Settings).
INSERT INTO staff (staff_id, name, password_hash, role) VALUES
('ALORAN-001', 'Glen Mark Gonzaga', '$2y$10$Cx6KHQWZUxmyrz.7v3s.UeGNWmwmyncSad1FhNh8N.YPqoUwL5zbO', 'Administrator')
ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role);

-- Default office settings (no other sample records).
INSERT INTO system_settings (setting_key, setting_value) VALUES
('site_name', 'ALCROS'),
('office_name', 'Local Civil Registrar Office (LCRO) of Aloran'),
('office_address', 'Municipal Hall, Aloran, Misamis Occidental, Philippines'),
('office_phone', '+639473212350'),
('office_email', 'aloran@gov.ph'),
('office_hours', '8:00 AM - 5:00 PM (Monday to Friday)'),
('office_head', 'ATTY. LOCAL CIVIL REGISTRAR'),
('overview_text', 'This guide covers the requirements, steps, and fees for all core civil registration services handled by the <strong>{office}</strong>.'),
('portal_title', 'ALCROS Online Request Portal'),
('portal_description', 'Request document submissions or track application statuses online.'),
('queue_window', '1'),
('smtp_host', 'smtp.gmail.com'),
('smtp_port', '587')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

