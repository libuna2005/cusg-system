<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

if (empty($_SESSION['cusg_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

function ensureUploadAuditLogTable(PDO $pdo): void {
    $exists = $pdo->query("SHOW TABLES LIKE 'upload_audit_log'")->fetch();
    if (!$exists) {
        $pdo->exec("CREATE TABLE upload_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_label VARCHAR(100) NULL,
            event_ids VARCHAR(255) NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            success_count INT UNSIGNED NOT NULL DEFAULT 0,
            duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
            not_found_count INT UNSIGNED NOT NULL DEFAULT 0,
            invalid_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            name_mismatch_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_upload_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return;
    }
    $col = $pdo->query("SHOW COLUMNS FROM upload_audit_log LIKE 'name_mismatch_count'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE upload_audit_log ADD COLUMN name_mismatch_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER failed_count");
    }
}

try {
    ensureUploadAuditLogTable($pdo);

    $eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
    $limit = isset($_GET['limit']) ? min(200, max(1, (int) $_GET['limit'])) : 50;

    if ($eventId) {
        // Match any log row whose event_ids CSV includes this exact id.
        $stmt = $pdo->prepare(
            "SELECT * FROM upload_audit_log
             WHERE FIND_IN_SET(?, event_ids) > 0
             ORDER BY created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$eventId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM upload_audit_log ORDER BY created_at DESC LIMIT {$limit}");
        $stmt->execute();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // A quick roll-up so the admin doesn't have to add these up by hand.
    $totals = ['item_count' => 0, 'success_count' => 0, 'duplicate_count' => 0, 'not_found_count' => 0, 'invalid_count' => 0, 'failed_count' => 0, 'name_mismatch_count' => 0];
    foreach ($rows as $r) {
        foreach ($totals as $key => $val) {
            $totals[$key] += (int) $r[$key];
        }
    }

    echo json_encode(['success' => true, 'entries' => $rows, 'totals' => $totals]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
