-- phpMyAdmin Designer: two pages for alcros_db (Option A — same database, separate diagrams)
-- Prerequisites:
--   - XAMPP phpMyAdmin configuration storage (database `phpmyadmin`, tables pma__pdf_pages / pma__table_coords)
--   - ALCROS core + print schema already imported into alcros_db
--
-- Apply via: php scripts/setup_phpmyadmin_designer.php
-- Or phpMyAdmin SQL tab on database `phpmyadmin` (run whole file).
--
-- Replaces any existing Designer pages for alcros_db (including a single "ALCROS ERD" page).

DELETE tc FROM `pma__table_coords` AS tc
INNER JOIN `pma__pdf_pages` AS p ON tc.pdf_page_number = p.page_nr
WHERE tc.db_name = 'alcros_db';

DELETE FROM `pma__pdf_pages` WHERE db_name = 'alcros_db';

INSERT INTO `pma__pdf_pages` (db_name, page_descr) VALUES ('alcros_db', 'ALCROS Core');
SET @alcros_core_page := LAST_INSERT_ID();

INSERT INTO `pma__pdf_pages` (db_name, page_descr) VALUES ('alcros_db', 'ALCROS Print');
SET @alcros_print_page := LAST_INSERT_ID();

-- ---------------------------------------------------------------------------
-- Page: ALCROS Core (LCRO operations — no print_* tables)
-- ---------------------------------------------------------------------------
INSERT INTO `pma__table_coords` (db_name, table_name, pdf_page_number, x, y) VALUES
('alcros_db', 'staff', @alcros_core_page, 50, 50),
('alcros_db', 'system_settings', @alcros_core_page, 320, 50),
('alcros_db', 'staff_password_otps', @alcros_core_page, 590, 50),
('alcros_db', 'document_requests', @alcros_core_page, 50, 220),
('alcros_db', 'appointments', @alcros_core_page, 380, 220),
('alcros_db', 'queue_tickets', @alcros_core_page, 50, 390),
('alcros_db', 'queue_announcements', @alcros_core_page, 380, 390),
('alcros_db', 'civil_records', @alcros_core_page, 240, 560),
('alcros_db', 'civil_record_edit_locks', @alcros_core_page, 50, 730),
('alcros_db', 'birth_record_details', @alcros_core_page, 280, 730),
('alcros_db', 'death_record_details', @alcros_core_page, 510, 730),
('alcros_db', 'marriage_record_details', @alcros_core_page, 740, 730),
('alcros_db', 'activity_logs', @alcros_core_page, 50, 900),
('alcros_db', 'delivery_logs', @alcros_core_page, 320, 900),
('alcros_db', 'system_errors', @alcros_core_page, 590, 900);

-- ---------------------------------------------------------------------------
-- Page: ALCROS Print (four print_* tables only; links to core live on ALCROS Core page)
-- Internal FK lines: fields/calibrations/jobs → print_templates.
-- print_jobs → document_requests / civil_records exist in MySQL but are not drawn here.
-- ---------------------------------------------------------------------------
INSERT INTO `pma__table_coords` (db_name, table_name, pdf_page_number, x, y) VALUES
('alcros_db', 'print_templates', @alcros_print_page, 320, 60),
('alcros_db', 'print_fields', @alcros_print_page, 80, 280),
('alcros_db', 'print_calibrations', @alcros_print_page, 320, 280),
('alcros_db', 'print_jobs', @alcros_print_page, 560, 280);
