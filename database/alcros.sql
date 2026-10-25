-- ALCROS Civil Registry System - MySQL Schema
-- Import via phpMyAdmin or: mysql -u root < database/alcros.sql
--
-- Table groups (20 tables):
--   staff, staff_resource_locks
--   document_requests, request_status_history
--   appointments, appointment_follow_ups
--   queue_tickets, queue_announcements
--   civil_records, birth/death/marriage_record_details, civil_record_updates
--   print_templates, print_fields, print_jobs
--   delivery_logs, activity_logs, system_settings, system_errors
--
-- ═══════════════════════════════════════════════════════════════════════════
-- LOGICAL DATA MODEL (relationships with and without MySQL FOREIGN KEY)
-- Diagram tools only draw lines where FOREIGN KEY exists below.
-- ═══════════════════════════════════════════════════════════════════════════
--
-- Legend:
--   [FK]     = enforced in this file (ON DELETE CASCADE or RESTRICT)
--   [logic]  = linked in PHP only (JOIN, soft delete, optional, or polymorphic)
--   [none]   = standalone / key-value / append-only log
--
-- ── Staff & access ─────────────────────────────────────────────────────────
-- staff
--   [none] Root account table. Business key: staff.staff_id (UNIQUE), not staff.id.
-- staff (embedded auth)
--   [none] remember_token_* — at most one “Remember me” session per staff_id.
--   [none] password_reset_otp_* — at most one active forgot-password OTP per staff_id.
-- staff_resource_locks
--   [FK]    staff_id → staff.staff_id ON DELETE CASCADE
--   [logic] (resource_type, resource_id) polymorphic parent (cannot be one FK in MySQL):
--            • document_request → document_requests.id
--            • appointment      → appointments.id
--            • civil_record     → civil_records.id
--
-- ── Citizen document pipeline ───────────────────────────────────────────────
-- document_requests
--   [FK]    civil_record_id → civil_records.id ON DELETE SET NULL (optional link)
--   [none]  deleted_at — soft delete; row kept for restore/history
-- request_status_history
--   [FK]    request_id → document_requests.id ON DELETE CASCADE
--   [logic] tracking_code copies document_requests.tracking_code (lookup without JOIN)
--   [FK]    changed_by → staff.staff_id ON DELETE SET NULL
--
-- ── Appointments ────────────────────────────────────────────────────────────
-- appointments
--   [FK]    tracking_code → document_requests.tracking_code ON DELETE SET NULL
--   [none]  deleted_at — soft delete
-- appointment_follow_ups
--   [FK]    appointment_id → appointments.id ON DELETE CASCADE
--   [FK]    cancelled_appointment_id → appointments.id ON DELETE SET NULL
--   [FK]    created_by → staff.staff_id ON DELETE SET NULL
--
-- ── Queue (front desk) ────────────────────────────────────────────────────
-- queue_tickets
--   [logic] reference_code — may match appointment_code or tracking_code (hint only)
--   [none]  No FK to appointments/document_requests (ticket can outlive or omit link)
-- queue_announcements
--   [logic] ticket_number + purpose — pairs with queue_tickets for speaker queue (no FK)
--
-- ── Civil registry ──────────────────────────────────────────────────────────
-- civil_records
--   [none]  record_type determines which detail table applies (birth/death/marriage)
--   [none]  deleted_at — soft delete
-- birth_record_details | death_record_details | marriage_record_details
--   [FK]    civil_record_id → civil_records.id ON DELETE CASCADE (1:1 extension rows)
-- civil_record_updates
--   [FK]    civil_record_id → civil_records.id ON DELETE CASCADE
--   [FK]    staff_id → staff.staff_id ON DELETE RESTRICT
--
-- ── Printing ────────────────────────────────────────────────────────────────
-- print_templates
--   [none]  cal_* columns — per-template calibration (formerly print_calibrations)
--   [FK]    cal_updated_by → staff.staff_id ON DELETE SET NULL
-- print_fields
--   [FK]    template_id → print_templates.id ON DELETE CASCADE
-- print_jobs
--   [FK]    template_id → print_templates.id ON DELETE RESTRICT
--   [FK]    request_id → document_requests.id ON DELETE SET NULL
--   [FK]    civil_record_id → civil_records.id ON DELETE SET NULL
--   [FK]    printed_by → staff.staff_id ON DELETE SET NULL
--
-- ── Notifications & audit (mostly standalone) ───────────────────────────────
-- delivery_logs
--   [logic] reference_code — often tracking_code, appointment_code, or staff_id (free text)
--   [none]  No FK — log rows must survive if a request is purged
-- activity_logs
--   [FK]    staff_id → staff.staff_id ON DELETE SET NULL
-- system_settings
--   [none]  Key/value store; no foreign keys
-- system_errors
--   [none]  Admin alert inbox; href may point to settings URLs (not a DB relation)
--
-- ── Cross-module links (application level) ──────────────────────────────────
--   document_requests.tracking_code ←→ appointments.tracking_code (optional 1:1 style link)
--   document_requests.civil_record_id → civil_records.id (optional N:1)
--   Notifications UI reads live from document_requests, appointments, queue_tickets,
--   and system_errors — not from a separate notifications table.
--
-- ═══════════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS alcros_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE alcros_db;

-- ── Staff & access ──────────────────────────────────────────────────────────

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
    remember_token_hash CHAR(64) DEFAULT NULL,
    remember_token_expires_at DATETIME DEFAULT NULL,
    password_reset_otp_hash VARCHAR(255) DEFAULT NULL,
    password_reset_otp_expires_at DATETIME DEFAULT NULL,
    password_reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staff_remember_hash (remember_token_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_resource_locks (
    resource_type VARCHAR(24) NOT NULL,
    resource_id INT NOT NULL,
    staff_id VARCHAR(50) NOT NULL,
    staff_name VARCHAR(120) NOT NULL DEFAULT '',
    locked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (resource_type, resource_id),
    INDEX idx_expires (expires_at),
    INDEX idx_staff_resource_locks_staff (staff_id),
    CONSTRAINT fk_staff_resource_locks_staff
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ── Citizen requests ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS document_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_code VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) DEFAULT NULL,
    last_name VARCHAR(80) NOT NULL,
    requester_first_name VARCHAR(80) DEFAULT NULL,
    requester_middle_name VARCHAR(80) DEFAULT NULL,
    requester_last_name VARCHAR(80) DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    date_of_marriage DATE DEFAULT NULL,
    date_of_death DATE DEFAULT NULL,
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
    sms_reminder_1h_sent_at TIMESTAMP NULL DEFAULT NULL,
    appointment_date DATE DEFAULT NULL,
    appointment_time TIME DEFAULT NULL,
    status ENUM('pending','processing','printing','printed','quality_check','verified','ready','completed','rejected') NOT NULL DEFAULT 'pending',
    civil_record_id INT DEFAULT NULL,
    print_fill_data JSON DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_citizen_name (last_name, first_name),
    INDEX idx_deleted_at (deleted_at),
    INDEX idx_civil_record (civil_record_id)
) ENGINE=InnoDB;

-- ── Appointments ────────────────────────────────────────────────────────────

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
    sms_reminder_1h_sent_at TIMESTAMP NULL DEFAULT NULL,
    service_type VARCHAR(100) NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status ENUM('scheduled','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
    source VARCHAR(32) NOT NULL DEFAULT 'standalone',
    tracking_code VARCHAR(20) DEFAULT NULL,
    id_front_path VARCHAR(255) DEFAULT NULL,
    id_back_path VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_date (appointment_date),
    INDEX idx_status (status),
    INDEX idx_citizen_name (last_name, first_name),
    INDEX idx_deleted_at (deleted_at),
    INDEX idx_appointments_tracking (tracking_code),
    CONSTRAINT fk_appointments_tracking_code
        FOREIGN KEY (tracking_code) REFERENCES document_requests(tracking_code)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointment_follow_ups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    follow_up_date DATE NOT NULL,
    staff_note TEXT DEFAULT NULL,
    status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    cancel_reason VARCHAR(32) DEFAULT NULL,
    cancelled_appointment_id INT DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    notify_email TINYINT(1) NOT NULL DEFAULT 0,
    notify_sms TINYINT(1) NOT NULL DEFAULT 0,
    service_type VARCHAR(100) NOT NULL DEFAULT '',
    service_slug VARCHAR(80) DEFAULT NULL,
    reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
    sms_reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
    created_by VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_follow_up_date (follow_up_date, status),
    INDEX idx_status (status),
    INDEX idx_appointment (appointment_id),
    INDEX idx_email (email),
    INDEX idx_follow_up_created_by (created_by),
    CONSTRAINT fk_follow_up_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_up_cancelled_appointment
        FOREIGN KEY (cancelled_appointment_id) REFERENCES appointments(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_follow_up_created_by
        FOREIGN KEY (created_by) REFERENCES staff(staff_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ── Queue ───────────────────────────────────────────────────────────────────

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
    first_called_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_date (created_at)
) ENGINE=InnoDB;

-- Sequential speaker queue: one table announcement at a time, ordered by click time.
CREATE TABLE IF NOT EXISTS queue_announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purpose ENUM('walk_in','appointment','document_claim') NOT NULL,
    ticket_number VARCHAR(10) NOT NULL,
    window_number INT NOT NULL,
    status ENUM('pending','playing','completed') NOT NULL DEFAULT 'pending',
    requested_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    started_at TIMESTAMP(6) NULL DEFAULT NULL,
    completed_at TIMESTAMP(6) NULL DEFAULT NULL,
    INDEX idx_status_requested (status, requested_at),
    INDEX idx_purpose_status (purpose, status)
) ENGINE=InnoDB;

-- ── Civil registry ──────────────────────────────────────────────────────────

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
    INDEX idx_deleted (deleted_at),
    UNIQUE INDEX uniq_civil_registry_per_type (record_type, registry_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS civil_record_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    civil_record_id INT NOT NULL,
    event_type ENUM('created','updated') NOT NULL,
    staff_id VARCHAR(50) NOT NULL,
    staff_name VARCHAR(120) NOT NULL,
    staff_role VARCHAR(40) NOT NULL DEFAULT 'Staff',
    summary VARCHAR(255) NOT NULL,
    edit_reason VARCHAR(500) NULL DEFAULT NULL,
    changes_json JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_record_created (civil_record_id, created_at),
    CONSTRAINT fk_civil_record_updates_record
        FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_civil_record_updates_staff
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE RESTRICT ON UPDATE CASCADE
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

-- ── Audit & settings ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_activity_logs_staff (staff_id),
    CONSTRAINT fk_activity_logs_staff
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_errors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_key VARCHAR(80) NOT NULL UNIQUE,
    category VARCHAR(30) NOT NULL DEFAULT 'system',
    title VARCHAR(150) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    fix_action VARCHAR(50) NOT NULL DEFAULT 'acknowledge',
    href VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolved_by VARCHAR(30) DEFAULT NULL,
    INDEX idx_active (resolved_at, updated_at)
) ENGINE=InnoDB;

-- ── Notification delivery logs ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS delivery_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('email','sms') NOT NULL,
    recipient VARCHAR(150) NOT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    body VARCHAR(500) NOT NULL DEFAULT '',
    delivery_type VARCHAR(50) NOT NULL DEFAULT 'general',
    reference_code VARCHAR(30) DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(255) DEFAULT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_channel_sent (channel, sent_at),
    INDEX idx_recipient (recipient),
    INDEX idx_reference (reference_code)
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
        FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_status_history_changed_by
        FOREIGN KEY (changed_by) REFERENCES staff(staff_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Default administrator (change password after first login in System Settings).
INSERT INTO staff (staff_id, first_name, middle_name, last_name, password_hash, role, email) VALUES
('ALORAN-001', 'Glen Mark', NULL, 'Gonzaga', '$2y$10$lJL7jF91HNFhyCDWNTknX.TdpL3a0.x31/e7obGLYaopM94g3ceRi', 'Administrator', 'glenmarkgonzaga57@gmail.com')
ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), middle_name = VALUES(middle_name), last_name = VALUES(last_name), role = VALUES(role), email = VALUES(email);

-- Default office settings (no other sample records).
INSERT INTO system_settings (setting_key, setting_value) VALUES
('site_name', 'ALCROS'),
('office_name', 'Local Civil Registrar Office (LCRO) of Aloran'),
('office_address', 'Municipal Hall, Aloran, Misamis Occidental, Philippines'),
('office_phone', '+69067334380'),
('office_email', 'aloranlcr@gmail.com'),
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

-- ── Printing ────────────────────────────────────────────────────────────────

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
    cal_x_offset_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    cal_y_offset_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    cal_scale_x DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
    cal_scale_y DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
    cal_updated_by VARCHAR(50) DEFAULT NULL,
    cal_updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    reference_image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cert_page (certificate_type, page_side),
    INDEX idx_print_templates_cal_updated_by (cal_updated_by),
    CONSTRAINT fk_print_templates_cal_updated_by
        FOREIGN KEY (cal_updated_by) REFERENCES staff(staff_id) ON DELETE SET NULL ON UPDATE CASCADE
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
        FOREIGN KEY (template_id) REFERENCES print_templates(id) ON DELETE RESTRICT,
    CONSTRAINT fk_print_jobs_request
        FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_print_jobs_civil_record
        FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_print_jobs_printed_by
        FOREIGN KEY (printed_by) REFERENCES staff(staff_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Deferred FK (document_requests is created before civil_records in this file).
ALTER TABLE document_requests
    ADD CONSTRAINT fk_document_requests_civil_record
    FOREIGN KEY (civil_record_id) REFERENCES civil_records(id)
    ON DELETE SET NULL ON UPDATE CASCADE;

