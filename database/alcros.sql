-- ALCROS Civil Registry System - MySQL Schema
-- Import via phpMyAdmin or: mysql -u root < database/alcros.sql

CREATE DATABASE IF NOT EXISTS alcros_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE alcros_db;

CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) DEFAULT NULL,
    last_name VARCHAR(80) NOT NULL,
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
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) DEFAULT NULL,
    last_name VARCHAR(80) NOT NULL,
    date_of_birth DATE DEFAULT NULL,
    date_of_marriage DATE DEFAULT NULL,
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
    notify_sms TINYINT(1) NOT NULL DEFAULT 0,
    reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
    reminder_5h_sent_at TIMESTAMP NULL DEFAULT NULL,
    reminder_3h_sent_at TIMESTAMP NULL DEFAULT NULL,
    reminder_1h_sent_at TIMESTAMP NULL DEFAULT NULL,
    sms_reminder_3h_sent_at TIMESTAMP NULL DEFAULT NULL,
    appointment_date DATE DEFAULT NULL,
    appointment_time TIME DEFAULT NULL,
    status ENUM('pending','processing','printing','printed','quality_check','verified','ready','completed','rejected') NOT NULL DEFAULT 'pending',
    civil_record_id INT DEFAULT NULL,
    print_fill_data JSON DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_citizen_name (last_name, first_name),
    INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_code VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) DEFAULT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    notify_email TINYINT(1) NOT NULL DEFAULT 0,
    notify_sms TINYINT(1) NOT NULL DEFAULT 0,
    reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
    reminder_5h_sent_at TIMESTAMP NULL DEFAULT NULL,
    reminder_3h_sent_at TIMESTAMP NULL DEFAULT NULL,
    reminder_1h_sent_at TIMESTAMP NULL DEFAULT NULL,
    sms_reminder_3h_sent_at TIMESTAMP NULL DEFAULT NULL,
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_date (appointment_date),
    INDEX idx_status (status),
    INDEX idx_citizen_name (last_name, first_name),
    INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS queue_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(10) NOT NULL,
    purpose ENUM('walk_in','appointment','document_claim') NOT NULL,
    status ENUM('waiting','serving','completed','skipped') NOT NULL DEFAULT 'waiting',
    first_name VARCHAR(80) DEFAULT NULL,
    middle_name VARCHAR(80) DEFAULT NULL,
    last_name VARCHAR(80) DEFAULT NULL,
    reference_code VARCHAR(20) DEFAULT NULL,
    window_number INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    called_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_date (created_at)
) ENGINE=InnoDB;

-- Shared civil registry row (all record types).
CREATE TABLE IF NOT EXISTS civil_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_type ENUM('birth','death','marriage') NOT NULL,
    registry_number VARCHAR(50) DEFAULT NULL,
    book_number VARCHAR(20) DEFAULT NULL,
    page_number VARCHAR(20) DEFAULT NULL,
    first_name VARCHAR(80) DEFAULT NULL,
    middle_name VARCHAR(80) DEFAULT NULL,
    last_name VARCHAR(80) DEFAULT NULL,
    birth_date DATE DEFAULT NULL,
    event_date DATE DEFAULT NULL,
    place VARCHAR(255) DEFAULT NULL,
    father_name VARCHAR(150) DEFAULT NULL,
    mother_name VARCHAR(150) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    print_fill_data JSON DEFAULT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (record_type),
    INDEX idx_person_name (last_name, first_name),
    INDEX idx_deleted (deleted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS civil_record_edit_locks (
    civil_record_id INT NOT NULL PRIMARY KEY,
    staff_id VARCHAR(32) NOT NULL,
    staff_name VARCHAR(120) NOT NULL DEFAULT '',
    locked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- Birth certificate fields (Form 102).
CREATE TABLE IF NOT EXISTS birth_record_details (
    civil_record_id INT NOT NULL PRIMARY KEY,
    sex VARCHAR(10) DEFAULT NULL,
    birth_time VARCHAR(20) DEFAULT NULL,
    birth_type VARCHAR(20) DEFAULT 'Single',
    birth_order VARCHAR(50) DEFAULT NULL,
    birth_weight VARCHAR(20) DEFAULT NULL,
    mother_age INT DEFAULT NULL,
    mother_nationality VARCHAR(100) DEFAULT NULL,
    mother_religion VARCHAR(100) DEFAULT NULL,
    mother_occupation VARCHAR(150) DEFAULT NULL,
    mother_residence VARCHAR(255) DEFAULT NULL,
    mother_children_born_alive VARCHAR(10) DEFAULT NULL,
    mother_children_still_living VARCHAR(10) DEFAULT NULL,
    mother_children_born_alive_now_dead VARCHAR(10) DEFAULT NULL,
    father_age INT DEFAULT NULL,
    father_nationality VARCHAR(100) DEFAULT NULL,
    father_religion VARCHAR(100) DEFAULT NULL,
    father_occupation VARCHAR(150) DEFAULT NULL,
    father_residence VARCHAR(255) DEFAULT NULL,
    parents_marriage_date DATE DEFAULT NULL,
    parents_marriage_place VARCHAR(255) DEFAULT NULL,
    registration_date DATE DEFAULT NULL,
    CONSTRAINT fk_birth_record_details_record
        FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Death certificate fields (Form 103).
CREATE TABLE IF NOT EXISTS death_record_details (
    civil_record_id INT NOT NULL PRIMARY KEY,
    sex VARCHAR(10) DEFAULT NULL,
    registration_date DATE DEFAULT NULL,
    residence_deceased VARCHAR(255) DEFAULT NULL,
    residence_length_place VARCHAR(100) DEFAULT NULL,
    residence_length_ph VARCHAR(100) DEFAULT NULL,
    nationality VARCHAR(100) DEFAULT NULL,
    civil_status VARCHAR(50) DEFAULT NULL,
    religion VARCHAR(100) DEFAULT NULL,
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
    child_age_mother VARCHAR(20) DEFAULT NULL,
    child_delivery_method VARCHAR(80) DEFAULT NULL,
    child_pregnancy_length VARCHAR(40) DEFAULT NULL,
    child_birth_type VARCHAR(40) DEFAULT NULL,
    child_birth_order_infant VARCHAR(20) DEFAULT NULL,
    infant_cause_a VARCHAR(255) DEFAULT NULL,
    infant_cause_b VARCHAR(255) DEFAULT NULL,
    infant_cause_c VARCHAR(255) DEFAULT NULL,
    infant_cause_d VARCHAR(255) DEFAULT NULL,
    infant_cause_e VARCHAR(255) DEFAULT NULL,
    postmortem_cause VARCHAR(255) DEFAULT NULL,
    CONSTRAINT fk_death_record_details_record
        FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Marriage certificate fields (Form 97).
CREATE TABLE IF NOT EXISTS marriage_record_details (
    civil_record_id INT NOT NULL PRIMARY KEY,
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
    husband_father_citizenship VARCHAR(100) DEFAULT NULL,
    husband_mother_citizenship VARCHAR(100) DEFAULT NULL,
    husband_consent_person_name VARCHAR(150) DEFAULT NULL,
    husband_consent_relationship VARCHAR(80) DEFAULT NULL,
    husband_consent_residence VARCHAR(255) DEFAULT NULL,
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
    wife_father_citizenship VARCHAR(100) DEFAULT NULL,
    wife_mother_citizenship VARCHAR(100) DEFAULT NULL,
    wife_consent_person_name VARCHAR(150) DEFAULT NULL,
    wife_consent_relationship VARCHAR(80) DEFAULT NULL,
    wife_consent_residence VARCHAR(255) DEFAULT NULL,
    marriage_time VARCHAR(20) DEFAULT NULL,
    solemnized_by VARCHAR(150) DEFAULT NULL,
    witnesses TEXT DEFAULT NULL,
    CONSTRAINT fk_marriage_record_details_record
        FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE CASCADE
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

-- Citizen SMS delivery audit trail (Semaphore).
CREATE TABLE IF NOT EXISTS sms_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(20) NOT NULL,
    message VARCHAR(500) NOT NULL,
    sms_type VARCHAR(50) NOT NULL DEFAULT 'general',
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
INSERT INTO staff (staff_id, first_name, middle_name, last_name, password_hash, role) VALUES
('ALORAN-001', 'Glen Mark', NULL, 'Gonzaga', '$2y$10$Cx6KHQWZUxmyrz.7v3s.UeGNWmwmyncSad1FhNh8N.YPqoUwL5zbO', 'Administrator')
ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), middle_name = VALUES(middle_name), last_name = VALUES(last_name), role = VALUES(role);

-- Default office settings (no other sample records).
INSERT INTO system_settings (setting_key, setting_value) VALUES
('site_name', 'ALCROS'),
('office_name', 'Local Civil Registrar Office (LCRO) of Aloran'),
('office_address', 'Municipal Hall, Aloran, Misamis Occidental, Philippines'),
('office_phone', '+639473212350'),
('office_email', 'aloran@gov.ph'),
('office_hours', '8:00 AM - 5:00 PM (Monday to Friday)'),
('office_head', 'ATTY. Euri Buladaco'),
('overview_text', 'This guide covers the requirements, steps, and fees for all core civil registration services handled by the <strong>{office}</strong>.'),
('portal_title', 'ALCROS Online Request Portal'),
('portal_description', 'Request document submissions or track application statuses online.'),
('queue_window', '1'),
('smtp_host', 'smtp.gmail.com'),
('smtp_port', '587'),
('print_mode', 'preprinted'),
('print_global_x_offset_mm', '0'),
('print_global_y_offset_mm', '0'),
('print_global_scale_x', '1'),
('print_global_scale_y', '1'),
('print_back_orientation_hint', 'flip_long_edge'),
('print_province', 'Misamis Occidental'),
('print_city_municipality', 'Aloran'),
('print_paper_preset', 'legal'),
('print_paper_width_mm', '215.9'),
('print_paper_height_mm', '358.9')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

CREATE TABLE IF NOT EXISTS print_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    certificate_type ENUM('birth','death','marriage') NOT NULL,
    page_side ENUM('front','back') NOT NULL,
    form_number VARCHAR(10) NOT NULL,
    paper_width_mm DECIMAL(8,2) NOT NULL DEFAULT 215.90,
    paper_height_mm DECIMAL(8,2) NOT NULL DEFAULT 358.90,
    orientation ENUM('portrait','landscape') NOT NULL DEFAULT 'portrait',
    margin_top_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    margin_left_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    reference_image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cert_page (certificate_type, page_side)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS print_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    field_name VARCHAR(80) NOT NULL,
    label VARCHAR(120) DEFAULT NULL,
    x_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    y_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    width_mm DECIMAL(8,2) NOT NULL DEFAULT 50.00,
    height_mm DECIMAL(8,2) NOT NULL DEFAULT 5.00,
    font_family VARCHAR(60) NOT NULL DEFAULT 'Arial',
    font_size DECIMAL(4,1) NOT NULL DEFAULT 10.0,
    font_weight VARCHAR(20) NOT NULL DEFAULT 'normal',
    alignment ENUM('left','center','right') NOT NULL DEFAULT 'left',
    max_length INT NOT NULL DEFAULT 120,
    line_height DECIMAL(4,2) NOT NULL DEFAULT 1.20,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_template_field (template_id, field_name),
    CONSTRAINT fk_print_fields_template
        FOREIGN KEY (template_id) REFERENCES print_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS print_calibrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    x_offset_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    y_offset_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    scale_x DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
    scale_y DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
    updated_by VARCHAR(50) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_template_calibration (template_id),
    CONSTRAINT fk_print_calibrations_template
        FOREIGN KEY (template_id) REFERENCES print_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS print_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT DEFAULT NULL,
    civil_record_id INT DEFAULT NULL,
    template_id INT NOT NULL,
    page_side ENUM('front','back') NOT NULL,
    certificate_type ENUM('birth','death','marriage') NOT NULL,
    registry_number VARCHAR(50) DEFAULT NULL,
    book_number VARCHAR(20) DEFAULT NULL,
    page_number VARCHAR(20) DEFAULT NULL,
    printer_name VARCHAR(120) DEFAULT NULL,
    printed_by VARCHAR(50) DEFAULT NULL,
    print_mode ENUM('preview','test','production') NOT NULL DEFAULT 'production',
    copies INT NOT NULL DEFAULT 1,
    status ENUM('queued','completed','failed','cancelled') NOT NULL DEFAULT 'completed',
    notes TEXT DEFAULT NULL,
    printed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request (request_id),
    INDEX idx_record (civil_record_id),
    INDEX idx_printed (printed_at),
    CONSTRAINT fk_print_jobs_template
        FOREIGN KEY (template_id) REFERENCES print_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

