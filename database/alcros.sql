-- ALCROS Civil Registry System - Core MySQL Schema
-- Import order (phpMyAdmin or CLI):
--   1. database/alcros.sql
--   2. database/alcros_print.sql
--
-- Table map (phpMyAdmin Designer shows lines for FOREIGN KEY constraints only):
--   Staff & config     staff, system_settings, staff_password_otps
--   Citizen services   document_requests, appointments
--   Queue              queue_tickets, queue_announcements
--   Civil registry     civil_records + birth/death/marriage_record_details, civil_record_edit_locks
--   Audit & messaging  activity_logs, delivery_logs
--   Operations         system_errors
--   Printing           see alcros_print.sql
--
-- phpMyAdmin Designer (two pages: Core + Print, same alcros_db):
--   C:\xampp\php\php.exe scripts/setup_phpmyadmin_designer.php
--
CREATE DATABASE IF NOT EXISTS alcros_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE alcros_db;

-- ---------------------------------------------------------------------------
-- staff
-- LCRO staff accounts for the admin portal (login, roles, profile).
-- staff_id is the business login ID (e.g. ALORAN-001); id is the internal PK.
-- Referenced in logs/locks by staff_id (string), not always by id.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- document_requests
-- Online certificate requests from the public portal (request.php).
-- tracking_code is what citizens use to track status; soft-deleted via deleted_at.
-- status drives staff workflow (pending → processing → printing → ready → completed).
-- civil_record_id links to the registry row staff matched for printing (optional).
-- print_fill_data stores per-request overlay values for certificate printing (JSON).
-- appointment_date/time on this row are pickup slots tied to the request when set.
-- ---------------------------------------------------------------------------
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
    sms_reminder_1h_sent_at TIMESTAMP NULL DEFAULT NULL,
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
    INDEX idx_deleted_at (deleted_at),
    INDEX idx_civil_record (civil_record_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- appointments
-- Office visit bookings (book_appointment.php, appointment.php).
-- appointment_code is the citizen tracking ID for appointments (like tracking_code).
-- source = standalone vs tied to a document request; tracking_code links when linked.
-- Reminder_* columns track email/SMS pickup reminders (cron/API).
-- Soft-deleted via deleted_at; does not replace document_requests (separate flow).
-- ---------------------------------------------------------------------------
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_date (appointment_date),
    INDEX idx_status (status),
    INDEX idx_citizen_name (last_name, first_name),
    INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- queue_tickets
-- Live queue state for kiosk and staff queue UI (live-queue.php, kiosk.php).
-- One row per ticket issued today: waiting → serving → completed/skipped.
-- purpose: walk_in | appointment | document_claim (why they are in line).
-- reference_code may hold appointment_code or tracking_code for display/call.
-- window_number set when staff calls the ticket to a service window.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- queue_announcements
-- Ordered speaker/TTS queue: staff “call ticket” clicks enqueue rows here.
-- Processed pending → playing → completed so only one announcement runs at a time.
-- Pairs with queue_tickets (ticket_number + window) but is separate timing/state.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- civil_records
-- Master row for each birth/death/marriage registry entry (records.php).
-- Shared identity fields; type-specific PSA form fields live in *_record_details.
-- registry/book/page support official register references and print overlays.
-- print_fill_data: JSON cache of values mapped onto certificate print fields.
-- Soft-deleted via deleted_at (record hidden, not physically removed).
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- civil_record_edit_locks
-- Prevents two staff editing the same civil record at once (record_locks.php).
-- One row per locked record; expires_at TTL; removed when lock released or expired.
-- civil_record_id PK = at most one active lock row per record.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS civil_record_edit_locks (
    civil_record_id INT NOT NULL PRIMARY KEY,
    staff_id VARCHAR(32) NOT NULL,
    staff_name VARCHAR(120) NOT NULL DEFAULT '',
    locked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    INDEX idx_expires (expires_at),
    CONSTRAINT fk_edit_locks_record
        FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- birth_record_details
-- 1:1 extension of civil_records for birth certificates (PSA Form 102).
-- civil_record_id is PK and FK; deleted automatically if parent record deleted.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- death_record_details
-- 1:1 extension of civil_records for death certificates (PSA Form 103).
-- Includes infant/maternal supplemental cause fields where applicable.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- marriage_record_details
-- 1:1 extension of civil_records for marriage certificates (PSA Form 97).
-- Husband/wife blocks, consent, solemnizer, witnesses.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- activity_logs
-- Staff audit trail: logins, record edits, request status changes, admin actions.
-- staff_id is the string login ID; details is free-text or structured message.
-- Exported from System Settings; shown on dashboard and activity-log.php.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- system_settings
-- Key-value configuration: office info, portal text, SMTP, queue window, flags.
-- Read via getSetting()/setSetting() in helpers.php (cached per request).
-- Print-specific keys are seeded in alcros_print.sql.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- delivery_logs
-- Audit of outbound citizen email and SMS (helpers.php, sms.php).
-- channel = email | sms; reference_code often tracking/appointment code.
-- system_errors.php scans failures here for admin “delivery failing” alerts.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delivery_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('email','sms') NOT NULL,
    recipient VARCHAR(150) NOT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    body VARCHAR(500) DEFAULT NULL,
    delivery_type VARCHAR(50) NOT NULL DEFAULT 'general',
    reference_code VARCHAR(30) DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(255) DEFAULT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_channel_sent (channel, sent_at),
    INDEX idx_recipient (recipient),
    INDEX idx_reference (reference_code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- system_errors
-- Active operational issues for staff (SMTP missing, SMS failures, maintenance).
-- error_key is stable for dedupe; resolved_at NULL = still shown in notifications.
-- Synced into the admin bell via systemErrorsAsNotifications().
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- staff_password_otps
-- Short-lived hashed OTPs for staff password reset (login flow in helpers.php).
-- Rows deleted after use, expiry, or max attempts; not long-term credential storage.
-- ---------------------------------------------------------------------------
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
INSERT INTO staff (staff_id, first_name, middle_name, last_name, password_hash, role, email) VALUES
('ALORAN-001', 'Glen Mark', NULL, 'Gonzaga', '$2y$10$lJL7jF91HNFhyCDWNTknX.TdpL3a0.x31/e7obGLYaopM94g3ceRi', 'Administrator', 'glenmarkgonzaga57@gmail.com')
ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), middle_name = VALUES(middle_name), last_name = VALUES(last_name), role = VALUES(role), email = VALUES(email);

-- Default office settings (no other sample records).
INSERT INTO system_settings (setting_key, setting_value) VALUES
('site_name', 'ALCROS'),
('office_name', 'Local Civil Registrar Office (LCRO) of Aloran'),
('office_address', 'Municipal Hall, Aloran, Misamis Occidental, Philippines'),
('office_phone', '+69067334380'),
('office_email', 'aloran@gov.ph'),
('office_hours', '8:00 AM - 5:00 PM (Monday to Friday)'),
('office_head', 'ATTY. Euri Buladaco'),
('overview_text', 'This guide covers the requirements, steps, and fees for all core civil registration services handled by the <strong>{office}</strong>.'),
('portal_title', 'ALCROS Online Request Portal'),
('portal_description', 'Request document submissions or track application statuses online.'),
('queue_window', '1'),
('smtp_host', 'smtp.gmail.com'),
('smtp_port', '587')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ---------------------------------------------------------------------------
-- Foreign keys (phpMyAdmin Designer draws lines only for these, not for app-only links)
--
-- ENFORCED (Core ERD):
--   staff ← staff_password_otps, activity_logs, civil_record_edit_locks
--   civil_records ← document_requests (optional link), edit_locks, *\_record_details
--   document_requests ← appointments.tracking_code (when appointment follows a request)
--
-- LOGICAL ONLY (no FK — see table comments above):
--   queue_tickets, queue_announcements  (today's line; reference_code is optional text)
--   delivery_logs                       (audit; reference_code may be any code)
--   system_errors, system_settings      (config / alerts, not row parents)
-- print_jobs FKs: alcros_print.sql
-- ---------------------------------------------------------------------------

ALTER TABLE document_requests
    ADD CONSTRAINT fk_document_requests_civil_record
        FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE SET NULL;

ALTER TABLE staff_password_otps
    ADD CONSTRAINT fk_staff_password_otps_staff
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE;

ALTER TABLE activity_logs
    ADD CONSTRAINT fk_activity_logs_staff
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE SET NULL;

ALTER TABLE civil_record_edit_locks
    ADD CONSTRAINT fk_edit_locks_staff
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE;

ALTER TABLE appointments
    ADD INDEX idx_tracking_code (tracking_code);

ALTER TABLE appointments
    ADD CONSTRAINT fk_appointments_document_request
        FOREIGN KEY (tracking_code) REFERENCES document_requests(tracking_code)
        ON DELETE SET NULL ON UPDATE CASCADE;

