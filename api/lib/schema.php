<?php
// Idempotent "make sure this column/table exists" helpers shared by endpoints.

/**
 * Self-healing schema, mirrored from api/admin/scan.php.
 */
function ensureAttendanceStatusColumn(PDO $pdo): void {
    $col = $pdo->query("SHOW COLUMNS FROM attendance_logs LIKE 'status'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE attendance_logs ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'present' AFTER source");
    }
}

/**
 * Same table scan.php writes to — a permanent trail of every undo action,
 * kept separate from attendance_logs itself since the rows it describes are
 * gone once undone.
 */
function ensureAttendanceUndoLogTable(PDO $pdo): void {
    $exists = $pdo->query("SHOW TABLES LIKE 'attendance_undo_log'")->fetch();
    if (!$exists) {
        $pdo->exec("CREATE TABLE attendance_undo_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id VARCHAR(50) NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            prior_status VARCHAR(20) NULL,
            prior_scanned_at DATETIME NULL,
            prior_source VARCHAR(50) NULL,
            undone_by VARCHAR(150) NULL,
            undone_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_undo_student (student_id),
            KEY idx_undo_event (event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/**
 * Self-healing schema check, same pattern used elsewhere in this app, so
 * this endpoint keeps working even on a database that hasn't been touched
 * since this feature was added.
 */
function ensurePortalSettingsTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_settings (
        id TINYINT UNSIGNED NOT NULL,
        attendance_locked TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO portal_settings (id, attendance_locked) VALUES (1, 0)");
}

/**
 * Self-healing schema: adds a status column ('present' / 'excused') to
 * attendance_logs if it doesn't exist yet, so an admin can bulk-excuse a
 * whole class instead of only ever recording a scanned "present".
 */
function ensureSectionColumn(PDO $pdo): void {
    $col = $pdo->query("SHOW COLUMNS FROM students LIKE 'section'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE students ADD COLUMN section VARCHAR(5) NULL AFTER year_level");
    }
}

// Individual, reviewable trail of scans that could NOT be turned into an
// attendance record (unreadable QR, or a student ID that doesn't match
// anyone in the roster). Mirrors the same table scan_batch.php writes to,
// so "I scanned but it's not showing" can be traced regardless of whether
// the officer was scanning live or in offline/batch mode.
function ensureUnresolvedScansTable(PDO $pdo): void {
    $exists = $pdo->query("SHOW TABLES LIKE 'unresolved_scans'")->fetch();
    if (!$exists) {
        $pdo->exec("CREATE TABLE unresolved_scans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_label VARCHAR(100) NULL,
            event_id BIGINT UNSIGNED NULL,
            attempted_student_id VARCHAR(100) NULL,
            qr_data_raw VARCHAR(500) NULL,
            reason VARCHAR(20) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'batch',
            resolved TINYINT(1) NOT NULL DEFAULT 0,
            resolved_student_id VARCHAR(50) NULL,
            resolved_by VARCHAR(150) NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_unresolved_resolved (resolved),
            KEY idx_unresolved_event (event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
