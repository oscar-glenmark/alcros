-- ALCROS Civil Registry System - MySQL Schema
-- Import via phpMyAdmin or: mysql -u root < database/alcros.sql

CREATE DATABASE IF NOT EXISTS alcros_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE alcros_db;

CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'Staff',
    email VARCHAR(150) DEFAULT NULL,
    recovery_gmail_2sv_confirmed TINYINT(1) NOT NULL DEFAULT 0,
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
    sex VARCHAR(10) DEFAULT NULL,
    birth_time VARCHAR(20) DEFAULT NULL,
    birth_type VARCHAR(20) DEFAULT 'Single',
    birth_order VARCHAR(50) DEFAULT NULL,
    event_date DATE DEFAULT NULL,
    place VARCHAR(255) DEFAULT NULL,
    father_name VARCHAR(150) DEFAULT NULL,
    mother_name VARCHAR(150) DEFAULT NULL,
    mother_age INT DEFAULT NULL,
    mother_nationality VARCHAR(100) DEFAULT NULL,
    mother_religion VARCHAR(100) DEFAULT NULL,
    father_age INT DEFAULT NULL,
    father_nationality VARCHAR(100) DEFAULT NULL,
    father_religion VARCHAR(100) DEFAULT NULL,
    parents_marriage_date DATE DEFAULT NULL,
    parents_marriage_place VARCHAR(255) DEFAULT NULL,
    registration_date DATE DEFAULT NULL,
    residence_deceased VARCHAR(255) DEFAULT NULL,
    residence_length_place VARCHAR(100) DEFAULT NULL,
    residence_length_ph VARCHAR(100) DEFAULT NULL,
    nationality VARCHAR(100) DEFAULT NULL,
    civil_status VARCHAR(50) DEFAULT NULL,
    age_death_years INT DEFAULT NULL,
    age_death_months INT DEFAULT NULL,
    age_death_days INT DEFAULT NULL,
    age_death_hours INT DEFAULT NULL,
    age_death_minutes INT DEFAULT NULL,
    stillbirth TINYINT(1) NOT NULL DEFAULT 0,
    occupation VARCHAR(150) DEFAULT NULL,
    surviving_spouse_name VARCHAR(150) DEFAULT NULL,
    surviving_spouse_address VARCHAR(255) DEFAULT NULL,
    place_of_burial VARCHAR(255) DEFAULT NULL,
    death_time VARCHAR(20) DEFAULT NULL,
    death_time_period VARCHAR(10) DEFAULT NULL,
    immediate_cause VARCHAR(255) DEFAULT NULL,
    contributory_cause VARCHAR(255) DEFAULT NULL,
    attending_physician VARCHAR(150) DEFAULT NULL,
    autopsy_performed VARCHAR(10) DEFAULT NULL,
    code_number VARCHAR(50) DEFAULT NULL,
    husband_name VARCHAR(150) DEFAULT NULL,
    husband_birth_date DATE DEFAULT NULL,
    husband_age INT DEFAULT NULL,
    husband_birth_place VARCHAR(255) DEFAULT NULL,
    husband_citizenship VARCHAR(100) DEFAULT NULL,
    husband_religion VARCHAR(100) DEFAULT NULL,
    husband_civil_status VARCHAR(50) DEFAULT NULL,
    husband_residence VARCHAR(255) DEFAULT NULL,
    husband_father_name VARCHAR(150) DEFAULT NULL,
    husband_mother_maiden_name VARCHAR(150) DEFAULT NULL,
    wife_name VARCHAR(150) DEFAULT NULL,
    wife_birth_date DATE DEFAULT NULL,
    wife_age INT DEFAULT NULL,
    wife_birth_place VARCHAR(255) DEFAULT NULL,
    wife_citizenship VARCHAR(100) DEFAULT NULL,
    wife_religion VARCHAR(100) DEFAULT NULL,
    wife_civil_status VARCHAR(50) DEFAULT NULL,
    wife_residence VARCHAR(255) DEFAULT NULL,
    wife_father_name VARCHAR(150) DEFAULT NULL,
    wife_mother_maiden_name VARCHAR(150) DEFAULT NULL,
    marriage_time VARCHAR(20) DEFAULT NULL,
    solemnized_by VARCHAR(150) DEFAULT NULL,
    witnesses TEXT DEFAULT NULL,
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

-- Persisted admin alerts (sidebar / bell notifications).
CREATE TABLE IF NOT EXISTS staff_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notif_key VARCHAR(80) NOT NULL UNIQUE,
    type ENUM('pending_request','ready_pickup','queue','appointment','system') NOT NULL DEFAULT 'system',
    title VARCHAR(150) NOT NULL,
    message VARCHAR(255) NOT NULL,
    detail VARCHAR(100) DEFAULT NULL,
    href VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_created (type, created_at)
) ENGINE=InnoDB;

-- Citizen Gmail delivery audit trail.
CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(150) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    email_type VARCHAR(50) NOT NULL DEFAULT 'general',
    reference_code VARCHAR(30) DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(255) DEFAULT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient),
    INDEX idx_reference (reference_code),
    INDEX idx_sent (sent_at)
) ENGINE=InnoDB;

-- Document request status change history.
CREATE TABLE IF NOT EXISTS request_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    tracking_code VARCHAR(20) NOT NULL,
    old_status VARCHAR(20) DEFAULT NULL,
    new_status VARCHAR(20) NOT NULL,
    changed_by VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request (request_id),
    INDEX idx_tracking (tracking_code),
    INDEX idx_created (created_at),
    CONSTRAINT fk_status_history_request
        FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_password_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_staff_otp (staff_id),
    INDEX idx_expires (expires_at)
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

