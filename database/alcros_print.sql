-- ALCROS Print module schema (target overlay / certificate printing)
-- Requires core schema first: import database/alcros.sql, then this file.
-- Same database: alcros_db
--
-- Tables: print_templates, print_fields, print_calibrations, print_jobs
-- Runtime seeds & migrations: includes/printing.php (ensurePrintTables)
--
-- Designer page "ALCROS Print": scripts/setup_phpmyadmin_designer.php

USE alcros_db;

-- Default print settings in system_settings (global offsets, paper, LCRO header text).
-- Applied by print_calibration.php and printing engine; editable in System Settings.
INSERT INTO system_settings (setting_key, setting_value) VALUES
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

-- ---------------------------------------------------------------------------
-- print_templates
-- One row per certificate type + page side (birth/death/marriage × front/back).
-- Defines paper size, margins, form number, and optional reference scan path.
-- Seeded/updated by ensurePrintTables(); certification kinds added at runtime.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- print_fields
-- Overlay boxes on a template: field_name maps to registry/request data keys.
-- x_mm/y_mm/width_mm/height_mm position text on preprinted or blank stock.
-- Synced from print field catalog in includes/printing.php after install.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- print_calibrations
-- Per-template fine tuning: shift/scale so output aligns on the physical printer.
-- One calibration row per template (uniq_template_calibration).
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- print_jobs
-- History of print actions (preview/test/production) for analytics and audit.
-- request_id and/or civil_record_id identify what was printed (optional for tests).
-- template_id required; registry/book/page snapshot stored on the job row.
-- ---------------------------------------------------------------------------
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

-- Optional FKs: tie print history to online requests and registry records.
-- Requires alcros.sql core tables. ON DELETE SET NULL keeps jobs if request removed.
ALTER TABLE print_jobs
    ADD CONSTRAINT fk_print_jobs_request
        FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_print_jobs_civil_record
        FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE SET NULL;
