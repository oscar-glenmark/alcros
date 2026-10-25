<?php
/**
 * One-time (idempotent) schema cleanup: unify staff locks, drop retired notification tables.
 */

require_once __DIR__ . '/staff_resource_locks.php';

function alcrosSchemaReorganizationApplied(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM civil_record_edit_locks LIMIT 1');

        return false;
    } catch (Throwable) {
        // Table gone — assume migration ran (locks live in staff_resource_locks).
    }

    foreach (['staff_notifications', 'staff_push_events'] as $legacy) {
        try {
            $pdo->query("SELECT 1 FROM `$legacy` LIMIT 1");

            return false;
        } catch (Throwable) {
        }
    }

    return true;
}

function ensureAlcrosSchemaReorganization(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    foreach ([
        static fn () => ensureStaffResourceLocksTable($pdo),
        static fn () => alcrosMigrateCivilRecordEditLocksTable($pdo),
        static fn () => alcrosDropLegacyStaffNotificationTables($pdo),
        static fn () => alcrosMigrateStaffRememberTokensToStaffColumns($pdo),
        static fn () => alcrosEnsureDeliveryLogsTable($pdo),
        static fn () => alcrosMigrateEmailSmsLogsToDeliveryLogs($pdo),
        static fn () => alcrosMigrateStaffPasswordOtpsToStaffColumns($pdo),
        static fn () => alcrosMigratePrintCalibrationsToTemplates($pdo),
        static fn () => alcrosEnsureReferentialIntegrityConstraints($pdo),
    ] as $step) {
        try {
            $step();
        } catch (Throwable) {
            // Shared hosts: partial migrations must not block login or staff pages.
        }
    }

    $done = true;
}

function alcrosForeignKeyExists(PDO $pdo, string $table, string $constraintName): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'
         LIMIT 1"
    );
    $stmt->execute([$table, $constraintName]);

    return (bool) $stmt->fetchColumn();
}

function alcrosAddForeignKey(PDO $pdo, string $table, string $constraintName, string $alterSql): void
{
    if (alcrosForeignKeyExists($pdo, $table, $constraintName)) {
        return;
    }

    try {
        $pdo->exec($alterSql);
    } catch (Throwable) {
        // Orphan rows or hosting limits — app still uses logical links in PHP.
    }
}

function alcrosEnsureReferentialIntegrityConstraints(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM staff LIMIT 1');
    } catch (Throwable) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE staff_resource_locks MODIFY staff_id VARCHAR(50) NOT NULL');
    } catch (Throwable) {
    }
    try {
        $pdo->exec('ALTER TABLE civil_record_updates MODIFY staff_id VARCHAR(50) NOT NULL');
    } catch (Throwable) {
    }

    $pdo->exec(
        'UPDATE document_requests dr
         LEFT JOIN civil_records cr ON cr.id = dr.civil_record_id
         SET dr.civil_record_id = NULL
         WHERE dr.civil_record_id IS NOT NULL AND cr.id IS NULL'
    );
    $pdo->exec(
        'UPDATE print_jobs pj
         LEFT JOIN document_requests dr ON dr.id = pj.request_id
         SET pj.request_id = NULL
         WHERE pj.request_id IS NOT NULL AND dr.id IS NULL'
    );
    $pdo->exec(
        'UPDATE print_jobs pj
         LEFT JOIN civil_records cr ON cr.id = pj.civil_record_id
         SET pj.civil_record_id = NULL
         WHERE pj.civil_record_id IS NOT NULL AND cr.id IS NULL'
    );
    $pdo->exec(
        'UPDATE appointments a
         LEFT JOIN document_requests dr ON dr.tracking_code = a.tracking_code
         SET a.tracking_code = NULL
         WHERE a.tracking_code IS NOT NULL AND a.tracking_code != \'\' AND dr.tracking_code IS NULL'
    );
    $pdo->exec(
        'UPDATE appointment_follow_ups f
         LEFT JOIN appointments a ON a.id = f.cancelled_appointment_id
         SET f.cancelled_appointment_id = NULL
         WHERE f.cancelled_appointment_id IS NOT NULL AND a.id IS NULL'
    );

    foreach ([
        ['activity_logs', 'staff_id'],
        ['request_status_history', 'changed_by'],
        ['appointment_follow_ups', 'created_by'],
        ['print_templates', 'cal_updated_by'],
        ['print_jobs', 'printed_by'],
        ['staff_resource_locks', 'staff_id'],
    ] as [$table, $column]) {
        $pdo->exec(
            "UPDATE `$table` t
             LEFT JOIN staff s ON s.staff_id = t.`$column`
             SET t.`$column` = NULL
             WHERE t.`$column` IS NOT NULL AND t.`$column` != '' AND s.staff_id IS NULL"
        );
    }

    $pdo->exec(
        'DELETE u FROM civil_record_updates u
         LEFT JOIN staff s ON s.staff_id = u.staff_id
         WHERE s.staff_id IS NULL'
    );

    alcrosAddForeignKey(
        $pdo,
        'document_requests',
        'fk_document_requests_civil_record',
        'ALTER TABLE document_requests
            ADD CONSTRAINT fk_document_requests_civil_record
            FOREIGN KEY (civil_record_id) REFERENCES civil_records(id)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'appointments',
        'fk_appointments_tracking_code',
        'ALTER TABLE appointments
            ADD CONSTRAINT fk_appointments_tracking_code
            FOREIGN KEY (tracking_code) REFERENCES document_requests(tracking_code)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'appointment_follow_ups',
        'fk_follow_up_cancelled_appointment',
        'ALTER TABLE appointment_follow_ups
            ADD CONSTRAINT fk_follow_up_cancelled_appointment
            FOREIGN KEY (cancelled_appointment_id) REFERENCES appointments(id)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'appointment_follow_ups',
        'fk_follow_up_created_by',
        'ALTER TABLE appointment_follow_ups
            ADD CONSTRAINT fk_follow_up_created_by
            FOREIGN KEY (created_by) REFERENCES staff(staff_id)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'request_status_history',
        'fk_status_history_changed_by',
        'ALTER TABLE request_status_history
            ADD CONSTRAINT fk_status_history_changed_by
            FOREIGN KEY (changed_by) REFERENCES staff(staff_id)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'activity_logs',
        'fk_activity_logs_staff',
        'ALTER TABLE activity_logs
            ADD CONSTRAINT fk_activity_logs_staff
            FOREIGN KEY (staff_id) REFERENCES staff(staff_id)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'civil_record_updates',
        'fk_civil_record_updates_staff',
        'ALTER TABLE civil_record_updates
            ADD CONSTRAINT fk_civil_record_updates_staff
            FOREIGN KEY (staff_id) REFERENCES staff(staff_id)
            ON DELETE RESTRICT ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'staff_resource_locks',
        'fk_staff_resource_locks_staff',
        'ALTER TABLE staff_resource_locks
            ADD CONSTRAINT fk_staff_resource_locks_staff
            FOREIGN KEY (staff_id) REFERENCES staff(staff_id)
            ON DELETE CASCADE ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'print_templates',
        'fk_print_templates_cal_updated_by',
        'ALTER TABLE print_templates
            ADD CONSTRAINT fk_print_templates_cal_updated_by
            FOREIGN KEY (cal_updated_by) REFERENCES staff(staff_id)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'print_jobs',
        'fk_print_jobs_request',
        'ALTER TABLE print_jobs
            ADD CONSTRAINT fk_print_jobs_request
            FOREIGN KEY (request_id) REFERENCES document_requests(id)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'print_jobs',
        'fk_print_jobs_civil_record',
        'ALTER TABLE print_jobs
            ADD CONSTRAINT fk_print_jobs_civil_record
            FOREIGN KEY (civil_record_id) REFERENCES civil_records(id)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    alcrosAddForeignKey(
        $pdo,
        'print_jobs',
        'fk_print_jobs_printed_by',
        'ALTER TABLE print_jobs
            ADD CONSTRAINT fk_print_jobs_printed_by
            FOREIGN KEY (printed_by) REFERENCES staff(staff_id)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );
}

function alcrosEnsureDeliveryLogsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS delivery_logs (
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
        ) ENGINE=InnoDB"
    );
}

function alcrosMigrateEmailSmsLogsToDeliveryLogs(PDO $pdo): void
{
    alcrosEnsureDeliveryLogsTable($pdo);

    try {
        $pdo->query('SELECT 1 FROM email_logs LIMIT 1');
        $hasEmail = true;
    } catch (Throwable) {
        $hasEmail = false;
    }

    try {
        $pdo->query('SELECT 1 FROM sms_logs LIMIT 1');
        $hasSms = true;
    } catch (Throwable) {
        $hasSms = false;
    }

    if (!$hasEmail && !$hasSms) {
        return;
    }

    if ($hasEmail) {
        $pdo->exec(
            "INSERT INTO delivery_logs (channel, recipient, subject, body, delivery_type, reference_code, success, error_message, sent_at)
             SELECT 'email', recipient, subject, '', email_type, reference_code, success, error_message, sent_at FROM email_logs"
        );
        $pdo->exec('DROP TABLE email_logs');
    }

    if ($hasSms) {
        $pdo->exec(
            "INSERT INTO delivery_logs (channel, recipient, subject, body, delivery_type, reference_code, success, error_message, sent_at)
             SELECT 'sms', recipient, NULL, message, sms_type, reference_code, success, error_message, sent_at FROM sms_logs"
        );
        $pdo->exec('DROP TABLE sms_logs');
    }
}

function alcrosMigrateStaffPasswordOtpsToStaffColumns(PDO $pdo): void
{
    try {
        $pdo->query('SELECT password_reset_otp_hash FROM staff LIMIT 1');
    } catch (Throwable) {
        try {
            $pdo->exec(
                'ALTER TABLE staff
                    ADD COLUMN password_reset_otp_hash VARCHAR(255) NULL DEFAULT NULL AFTER remember_token_expires_at,
                    ADD COLUMN password_reset_otp_expires_at DATETIME NULL DEFAULT NULL AFTER password_reset_otp_hash,
                    ADD COLUMN password_reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER password_reset_otp_expires_at'
            );
        } catch (Throwable) {
        }
    }

    try {
        $pdo->query('SELECT 1 FROM staff_password_otps LIMIT 1');
    } catch (Throwable) {
        return;
    }

    $rows = $pdo->query(
        'SELECT staff_id, otp_hash, expires_at, attempts FROM staff_password_otps ORDER BY id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    $update = $pdo->prepare(
        'UPDATE staff SET password_reset_otp_hash = ?, password_reset_otp_expires_at = ?, password_reset_otp_attempts = ?
         WHERE staff_id = ?'
    );
    foreach ($rows as $row) {
        $staffId = (string) ($row['staff_id'] ?? '');
        if ($staffId === '' || isset($seen[$staffId])) {
            continue;
        }
        $seen[$staffId] = true;
        $update->execute([
            (string) ($row['otp_hash'] ?? ''),
            (string) ($row['expires_at'] ?? ''),
            (int) ($row['attempts'] ?? 0),
            $staffId,
        ]);
    }

    $pdo->exec('DROP TABLE staff_password_otps');
}

function alcrosMigratePrintCalibrationsToTemplates(PDO $pdo): void
{
    try {
        $pdo->query('SELECT cal_x_offset_mm FROM print_templates LIMIT 1');
    } catch (Throwable) {
        try {
            $pdo->exec(
                'ALTER TABLE print_templates
                    ADD COLUMN cal_x_offset_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER margin_left_mm,
                    ADD COLUMN cal_y_offset_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER cal_x_offset_mm,
                    ADD COLUMN cal_scale_x DECIMAL(8,4) NOT NULL DEFAULT 1.0000 AFTER cal_y_offset_mm,
                    ADD COLUMN cal_scale_y DECIMAL(8,4) NOT NULL DEFAULT 1.0000 AFTER cal_scale_x,
                    ADD COLUMN cal_updated_by VARCHAR(50) DEFAULT NULL AFTER cal_scale_y,
                    ADD COLUMN cal_updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER cal_updated_by'
            );
        } catch (Throwable) {
        }
    }

    try {
        $pdo->query('SELECT 1 FROM print_calibrations LIMIT 1');
    } catch (Throwable) {
        return;
    }

    $pdo->exec(
        'UPDATE print_templates pt
         INNER JOIN print_calibrations pc ON pc.template_id = pt.id
         SET pt.cal_x_offset_mm = pc.x_offset_mm,
             pt.cal_y_offset_mm = pc.y_offset_mm,
             pt.cal_scale_x = pc.scale_x,
             pt.cal_scale_y = pc.scale_y,
             pt.cal_updated_by = pc.updated_by,
             pt.cal_updated_at = pc.updated_at'
    );

    $pdo->exec('DROP TABLE print_calibrations');
}

function alcrosMigrateCivilRecordEditLocksTable(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM civil_record_edit_locks LIMIT 1');
    } catch (Throwable) {
        return;
    }

    $rows = $pdo->query(
        'SELECT civil_record_id, staff_id, staff_name, locked_at, expires_at FROM civil_record_edit_locks'
    )->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare(
        'INSERT INTO staff_resource_locks (resource_type, resource_id, staff_id, staff_name, locked_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            staff_id = VALUES(staff_id),
            staff_name = VALUES(staff_name),
            locked_at = VALUES(locked_at),
            expires_at = VALUES(expires_at)'
    );

    foreach ($rows as $row) {
        $recordId = (int) ($row['civil_record_id'] ?? 0);
        if ($recordId <= 0) {
            continue;
        }
        $insert->execute([
            STAFF_LOCK_TYPE_CIVIL_RECORD,
            $recordId,
            (string) ($row['staff_id'] ?? ''),
            (string) ($row['staff_name'] ?? ''),
            (string) ($row['locked_at'] ?? date('Y-m-d H:i:s')),
            (string) ($row['expires_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    $pdo->exec('DROP TABLE civil_record_edit_locks');
}

function alcrosMigrateStaffRememberTokensToStaffColumns(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM staff_remember_tokens LIMIT 1');
    } catch (Throwable) {
        return;
    }

    try {
        $pdo->query('SELECT remember_token_hash FROM staff LIMIT 1');
    } catch (Throwable) {
        try {
            $pdo->exec(
                'ALTER TABLE staff
                    ADD COLUMN remember_token_hash CHAR(64) NULL DEFAULT NULL AFTER profile_photo_path,
                    ADD COLUMN remember_token_expires_at DATETIME NULL DEFAULT NULL AFTER remember_token_hash'
            );
        } catch (Throwable) {
        }
        try {
            $pdo->exec('ALTER TABLE staff ADD UNIQUE KEY uq_staff_remember_hash (remember_token_hash)');
        } catch (Throwable) {
        }
    }

    $rows = $pdo->query(
        'SELECT staff_id, token_hash, expires_at FROM staff_remember_tokens ORDER BY expires_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    $update = $pdo->prepare(
        'UPDATE staff SET remember_token_hash = ?, remember_token_expires_at = ? WHERE staff_id = ?'
    );
    foreach ($rows as $row) {
        $staffId = (string) ($row['staff_id'] ?? '');
        if ($staffId === '' || isset($seen[$staffId])) {
            continue;
        }
        $seen[$staffId] = true;
        $update->execute([
            (string) ($row['token_hash'] ?? ''),
            (string) ($row['expires_at'] ?? ''),
            $staffId,
        ]);
    }

    $pdo->exec('DROP TABLE staff_remember_tokens');
}

function alcrosDropLegacyStaffNotificationTables(PDO $pdo): void
{
    foreach ([
        'staff_push_subscriptions',
        'staff_push_preferences',
        'staff_push_events',
        'staff_notifications',
    ] as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
        } catch (Throwable) {
        }
    }
}
