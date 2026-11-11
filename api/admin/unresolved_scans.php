<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/scan_common.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/common.php';

if (empty($_SESSION['cusg_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

try {
    ensureUnresolvedScansTable($pdo);
    ensureAttendanceStatusColumn($pdo);

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $resolved = isset($_GET['resolved']) ? (int) $_GET['resolved'] : 0;
        $eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        $limit = isset($_GET['limit']) ? max(1, min(300, (int) $_GET['limit'])) : 100;

        $sql = 'SELECT u.id, u.device_label, u.event_id, u.attempted_student_id, u.qr_data_raw, u.reason,
                       u.source, u.resolved, u.resolved_student_id, u.resolved_by, u.resolved_at, u.created_at,
                       e.title AS event_title, e.event_date AS event_date
                FROM unresolved_scans u
                LEFT JOIN events e ON e.id = u.event_id
                WHERE u.resolved = ?';
        $params = [$resolved];

        if ($eventId > 0) {
            $sql .= ' AND u.event_id = ?';
            $params[] = $eventId;
        }

        $sql .= ' ORDER BY u.created_at DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM unresolved_scans WHERE resolved = 0');
        $countStmt->execute();
        $openCount = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        echo json_encode(['success' => true, 'items' => $rows, 'open_count' => $openCount]);
        exit;
    }

    if ($method === 'POST') {
        $input = readJsonInput();
        $action = trim((string) ($input['action'] ?? ''));
        $id = isset($input['id']) ? (int) $input['id'] : 0;

        // Bulk clear: permanently deletes every entry in the list currently
        // being viewed (open entries, or handled entries). No id needed.
        if ($action === 'clear') {
            $scope = (($input['scope'] ?? 'open') === 'handled') ? 1 : 0;
            $stmt = $pdo->prepare('DELETE FROM unresolved_scans WHERE resolved = ?');
            $stmt->execute([$scope]);
            $n = $stmt->rowCount();
            echo json_encode([
                'success' => true,
                'deleted' => $n,
                'message' => $n . ' unresolved scan' . ($n === 1 ? '' : 's') . ' cleared.',
            ]);
            exit;
        }

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing unresolved scan id.']);
            exit;
        }

        $itemStmt = $pdo->prepare('SELECT * FROM unresolved_scans WHERE id = ? LIMIT 1');
        $itemStmt->execute([$id]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'That unresolved scan no longer exists.']);
            exit;
        }

        $adminName = $_SESSION['cusg_admin']['full_name']
            ?? ($_SESSION['cusg_admin']['username'] ?? 'unknown admin');

        if ($action === 'dismiss') {
            $stmt = $pdo->prepare('UPDATE unresolved_scans SET resolved = 1, resolved_by = ?, resolved_at = NOW() WHERE id = ?');
            $stmt->execute([$adminName, $id]);
            echo json_encode(['success' => true, 'message' => 'Dismissed.']);
            exit;
        }

        if ($action === 'record') {
            $correctedStudentId = trim((string) ($input['student_id'] ?? ''));
            if ($correctedStudentId === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please enter the correct Student ID.']);
                exit;
            }
            if (!$item['event_id']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'This entry has no associated event (the event itself could not be identified at scan time), so attendance cannot be recorded from here.']);
                exit;
            }

            $studentStmt = $pdo->prepare('SELECT student_id, full_name, course_program, year_level, section, profile_picture_url FROM students WHERE student_id = ? LIMIT 1');
            $studentStmt->execute([$correctedStudentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => "No student found for ID \"{$correctedStudentId}\". Double-check the ID and try again."]);
                exit;
            }

            $eventStmt = $pdo->prepare('SELECT id, title FROM events WHERE id = ? LIMIT 1');
            $eventStmt->execute([$item['event_id']]);
            $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
            if (!$event) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'The event this scan was for no longer exists.']);
                exit;
            }

            $existingStmt = $pdo->prepare('SELECT id, status FROM attendance_logs WHERE student_id = ? AND event_id = ? LIMIT 1');
            $existingStmt->execute([$correctedStudentId, $item['event_id']]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Already recorded some other way (e.g. re-scanned successfully later) —
                // just close out this leftover entry instead of erroring.
                $stmt = $pdo->prepare('UPDATE unresolved_scans SET resolved = 1, resolved_student_id = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?');
                $stmt->execute([$correctedStudentId, $adminName, $id]);
                echo json_encode([
                    'success' => true,
                    'message' => $student['full_name'] . ' already has an attendance record for "' . $event['title'] . '" — this entry has been closed out, nothing new was added.',
                ]);
                exit;
            }

            try {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO attendance_logs (student_id, event_id, source, status, scanned_at) VALUES (?, ?, 'admin_manual_reconcile', 'present', NOW())"
                );
                $insertStmt->execute([$correctedStudentId, $item['event_id']]);
            } catch (PDOException $e) {
                if ((int) $e->getCode() !== 23000 && strpos($e->getMessage(), 'uq_attendance_once_per_student_event') === false) {
                    throw $e;
                }
                // Duplicate race — fine, fall through to marking resolved.
            }

            $stmt = $pdo->prepare('UPDATE unresolved_scans SET resolved = 1, resolved_student_id = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?');
            $stmt->execute([$correctedStudentId, $adminName, $id]);

            echo json_encode([
                'success' => true,
                'message' => 'Attendance recorded for ' . $student['full_name'] . ' — "' . $event['title'] . '".',
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
