<?php
// ============================================================
//  session_completion.php
//  File path: api/admin/session_completion.php
//  Shared "Mark Session Done" flag for the Scan Attendance panel.
//  Used to live only in each officer's browser localStorage, so a
//  checkmark made on one phone never showed up on anyone else's.
//  This stores the flag in the database instead — one officer marks
//  a session done and every officer's device sees it.
//
//  GET  -> { success, completed: [ { event_id, completed_by, completed_at }, ... ] }
//  POST { event_id, done: 1|0 } -> marks/unmarks that session as done
// ============================================================

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/common.php';

if (empty($_SESSION['cusg_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

/**
 * Self-healing schema check, same pattern used elsewhere in this app
 * (see api/admin/attendance_lock.php, api/admin/event_groups.php), so
 * this endpoint keeps working even on a database that hasn't been
 * touched since this feature was added — nothing manual to run on
 * InfinityFree.
 */
function ensureSessionCompletionsTable(PDO $pdo): void {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'session_completions'")->fetch();
    if ($tableExists) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS session_completions (
        event_id BIGINT UNSIGNED NOT NULL,
        completed_by VARCHAR(255) NULL,
        completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $pdo->exec("ALTER TABLE session_completions ADD CONSTRAINT fk_session_completions_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE");
    } catch (Throwable $e) {
        // Some shared-hosting setups restrict adding FKs after the fact;
        // harmless either way — the dropdown only ever offers real event ids.
    }
}

try {
    ensureSessionCompletionsTable($pdo);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $pdo->query('SELECT event_id, completed_by, completed_at FROM session_completions ORDER BY completed_at DESC');
        echo json_encode(['success' => true, 'completed' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($method === 'POST') {
        $input = readJsonInput();
        $eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;

        if (!$eventId || !array_key_exists('done', $input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'event_id and done (0 or 1) are required.']);
            exit;
        }

        $done = (int) (bool) $input['done'];

        if ($done) {
            $completedBy = trim((string) ($_SESSION['cusg_admin']['full_name'] ?? ''));
            if ($completedBy === '') {
                $completedBy = trim((string) ($_SESSION['cusg_admin']['username'] ?? ''));
            }

            $stmt = $pdo->prepare('INSERT INTO session_completions (event_id, completed_by, completed_at)
                                    VALUES (?, ?, CURRENT_TIMESTAMP)
                                    ON DUPLICATE KEY UPDATE completed_by = VALUES(completed_by), completed_at = CURRENT_TIMESTAMP');
            $stmt->execute([$eventId, $completedBy !== '' ? $completedBy : null]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM session_completions WHERE event_id = ?');
            $stmt->execute([$eventId]);
        }

        echo json_encode(['success' => true, 'event_id' => $eventId, 'done' => (bool) $done]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
