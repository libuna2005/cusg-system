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

// Who is scanning = the officer account that is logged in. Taken from the session (not from
// the phone), so it is always filled in, cannot be mistyped, and shows up in the audit log /
// Unresolved Scans as the person, not as a device name.
$officerLabel = (function (array $account): string {
    $name = trim((string) ($account['full_name'] ?? ''));
    $user = trim((string) ($account['username'] ?? ''));
    $label = ($name !== '' && $user !== '' && strcasecmp($name, $user) !== 0) ? "{$name} ({$user})" : ($name !== '' ? $name : $user);
    return function_exists('mb_substr') ? mb_substr($label, 0, 100) : substr($label, 0, 100);
})($_SESSION['cusg_admin']);

// The session is only read from here on. Releasing its file lock now stops one
// officer's requests (scan + recent-scans refresh + uploads) from queueing up
// behind each other on shared hosting.
session_write_close();

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Self-healing schema, mirrored from api/student/profile.php -- needed
 * here so the name-mismatch check below has last_name/first_name columns
 * to compare against on installs that haven't touched profile.php yet.
 */
function ensureNamePartColumns(PDO $pdo): void {
    $lastCol = $pdo->query("SHOW COLUMNS FROM students LIKE 'last_name'")->fetch();
    if (!$lastCol) {
        $pdo->exec("ALTER TABLE students ADD COLUMN last_name VARCHAR(100) NULL AFTER full_name");
    }
    $firstCol = $pdo->query("SHOW COLUMNS FROM students LIKE 'first_name'")->fetch();
    if (!$firstCol) {
        $pdo->exec("ALTER TABLE students ADD COLUMN first_name VARCHAR(100) NULL AFTER last_name");
    }
}

function logUnresolvedScan(PDO $pdo, ?string $deviceLabel, int $eventId, string $attemptedStudentId, string $qrDataRaw, string $reason, string $source): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO unresolved_scans (device_label, event_id, attempted_student_id, qr_data_raw, reason, source, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $deviceLabel !== null && $deviceLabel !== '' ? $deviceLabel : null,
            $eventId > 0 ? $eventId : null,
            $attemptedStudentId !== '' ? $attemptedStudentId : null,
            $qrDataRaw !== '' ? mb_substr($qrDataRaw, 0, 500) : null,
            $reason,
            $source,
        ]);
    } catch (Throwable $e) {
        // Diagnostic trail only - never blocks the actual scan response, but not silent either.
        logScanProblem('unresolved-scan log failed', $e->getMessage() . " (event {$eventId}, id '{$attemptedStudentId}', reason {$reason})");
    }
}

try {
    ensureSectionColumn($pdo);
    ensureAttendanceStatusColumn($pdo);
    ensureUnresolvedScansTable($pdo);
    ensureNamePartColumns($pdo);

    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'recent';

        if ($action === 'recent') {
            $eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
            $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;

            $sql = 'SELECT al.id, al.student_id, al.event_id, al.scanned_at, al.source, al.status,
                           s.full_name, s.course_program, s.year_level, s.section, e.title AS event_title,
                           g.title AS group_title
                    FROM attendance_logs al
                    JOIN students s ON s.student_id = al.student_id
                    JOIN events e ON e.id = al.event_id
                    LEFT JOIN event_groups g ON g.id = e.group_id';
            $params = [];
            if ($eventId > 0) {
                $sql .= ' WHERE al.event_id = ?';
                $params[] = $eventId;
            }
            $sql .= ' ORDER BY al.scanned_at DESC, al.id DESC LIMIT ' . $limit;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'scans' => $rows]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }

    if ($method === 'POST') {
        $input = readJsonInput();
        $eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;
        $qrData = trim((string) ($input['qr_data'] ?? ''));
        $manualStudentId = cleanScanText((string) ($input['student_id'] ?? ''));
        // Officer account first; the phone's own label is only a fallback.
        $deviceLabel = $officerLabel !== '' ? $officerLabel : trim((string) ($input['device_label'] ?? ''));

        if (!$eventId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please select an event before scanning.']);
            exit;
        }

        $studentId = $manualStudentId !== '' ? $manualStudentId : extractStudentId($qrData);

        if ($studentId === '') {
            http_response_code(400);
            logUnresolvedScan($pdo, $deviceLabel, $eventId, '', $qrData, 'invalid', 'live');
            echo json_encode(['success' => false, 'status' => 'invalid', 'message' => 'Could not read a valid student ID from the QR code.']);
            exit;
        }

        // Confirm the event exists.
        $eventStmt = $pdo->prepare('SELECT id, title, event_date, is_flagged FROM events WHERE id = ? LIMIT 1');
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            http_response_code(404);
            logUnresolvedScan($pdo, $deviceLabel, $eventId, $studentId, $qrData, 'invalid', 'live');
            echo json_encode(['success' => false, 'status' => 'invalid', 'message' => 'Selected event was not found.']);
            exit;
        }

        // Confirm the student exists.
        $studentStmt = $pdo->prepare('SELECT student_id, full_name, last_name, first_name, course_program, year_level, section, profile_picture_url FROM students WHERE student_id = ? LIMIT 1');
        $studentStmt->execute([$studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

        // The ID on the card is not registered: try the name inside the QR (unique exact match only).
        $matchedByName = false;
        $qrStudentId = $studentId;
        if (!$student) {
            $student = findStudentByQrName($pdo, $qrData);
            if ($student) {
                $matchedByName = true;
                logScanProblem('matched by name', "QR id '{$qrStudentId}' not registered; matched {$student['student_id']} by name");
            }
        }

        if (!$student) {
            http_response_code(404);
            logUnresolvedScan($pdo, $deviceLabel, $eventId, $studentId, $qrData, 'not_found', 'live');
            echo json_encode([
                'success' => false,
                'status' => 'not_found',
                'message' => "No student record found for ID \"{$studentId}\".",
                'student_id' => $studentId,
            ]);
            exit;
        }

        $student['profile_picture_url'] = normalizeProfilePicture($student['profile_picture_url']);

        // From here on use the ID exactly as stored. The lookup above is
        // case-insensitive, so a card typed "crn0521" would otherwise be saved
        // under that spelling and not line up with the student's own records.
        $studentId = $student['student_id'];

        // Name cross-check: the ID matched a real student, but does the name
        // baked into the QR actually belong to them? Catches a scanned ID
        // that happens to collide with a *different* real student (typo,
        // copy-paste slip) -- something the ID lookup alone can't detect.
        // Skipped entirely for manual entry / old-format QRs with no name
        // embedded (extractQrNameParts returns empty strings in that case).
        $qrNameParts = extractQrNameParts($qrData);
        if (!namesLikelyMatch($qrNameParts['last_name'], $qrNameParts['first_name'], $student['last_name'] ?? '', $student['first_name'] ?? '')) {
            http_response_code(409);
            logUnresolvedScan($pdo, $deviceLabel, $eventId, $studentId, $qrData, 'name_mismatch', 'live');
            echo json_encode([
                'success' => false,
                'status' => 'name_mismatch',
                'message' => "This QR's ID belongs to {$student['full_name']}, but the name on the card doesn't match. Attendance was NOT recorded -- please verify manually.",
                'student' => $student,
                'event' => $event,
            ]);
            exit;
        }

        // Check for an existing scan first so we can return a clean "duplicate" response.
        $existingStmt = $pdo->prepare('SELECT scanned_at, status FROM attendance_logs WHERE student_id = ? AND event_id = ? LIMIT 1');
        $existingStmt->execute([$studentId, $eventId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $priorStatus = $existing['status'] ?? 'present';
            if ($priorStatus === 'excused') {
                $priorLabel = 'already marked excused';
            } elseif ($priorStatus === 'no_class') {
                $priorLabel = 'already marked as no class / not required';
            } else {
                $priorLabel = 'already scanned present';
            }
            echo json_encode([
                'success' => true,
                'status' => 'duplicate',
                'prior_status' => $priorStatus,
                'message' => $student['full_name'] . ' was ' . $priorLabel . ' for this event.',
                'student' => $student,
                'event' => $event,
                'scanned_at' => $existing['scanned_at'],
            ]);
            exit;
        }

        try {
            $insertStmt = $pdo->prepare("INSERT INTO attendance_logs (student_id, event_id, source, status, scanned_at) VALUES (?, ?, ?, 'present', NOW())");
            executeInsertWithRetry($insertStmt, [$studentId, $eventId, 'admin_qr_scan']);

            // Read-back verification: independently re-read the row before telling
            // the officer it's saved, instead of only trusting that the INSERT
            // didn't throw an error. Also gives us the exact time the database stored.
            $verifyStmt = $pdo->prepare('SELECT id, scanned_at FROM attendance_logs WHERE student_id = ? AND event_id = ? LIMIT 1');
            $verifyStmt->execute([$studentId, $eventId]);
            $verified = $verifyStmt->fetch(PDO::FETCH_ASSOC);

            if (!$verified) {
                logScanProblem('save not confirmed', "student '{$studentId}', event {$eventId}");
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Save could not be confirmed for ' . $student['full_name'] . '. Please scan again.',
                    'student' => $student,
                    'event' => $event,
                ]);
                exit;
            }
        } catch (PDOException $e) {
            // Race condition: someone else recorded it a split second earlier.
            if ((int) $e->getCode() === 23000 || strpos($e->getMessage(), 'uq_attendance_once_per_student_event') !== false) {
                echo json_encode([
                    'success' => true,
                    'status' => 'duplicate',
                    'message' => $student['full_name'] . ' was already scanned for this event.',
                    'student' => $student,
                    'event' => $event,
                ]);
                exit;
            }
            throw $e;
        }

        echo json_encode([
            'success' => true,
            'status' => 'success',
            'message' => 'Attendance recorded for ' . $student['full_name'] . '.'
                . ($matchedByName ? " (The QR's ID \"{$qrStudentId}\" is not registered, so this was matched by NAME to ID {$studentId} - please confirm it is the right person.)" : ''),
            'matched_by' => $matchedByName ? 'name' : 'id',
            'student' => $student,
            'event' => $event,
            // The database's own timestamp (PHP's clock can be in a different timezone).
            'scanned_at' => $verified['scanned_at'],
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        // Undo: remove one student's attendance record for one event — for
        // when an officer accidentally scans a student into the wrong
        // event/session and needs to fix it without deleting the whole event
        // or contacting a developer.
        $input = readJsonInput();
        $studentId = trim((string) ($input['student_id'] ?? ''));
        $eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;

        if ($studentId === '' || !$eventId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Student ID and event are both required to undo an attendance record.']);
            exit;
        }

        ensureAttendanceUndoLogTable($pdo);

        $checkStmt = $pdo->prepare(
            'SELECT al.id, al.status, al.scanned_at, al.source, s.full_name, e.title AS event_title
             FROM attendance_logs al
             JOIN students s ON s.student_id = al.student_id
             JOIN events e ON e.id = al.event_id
             WHERE al.student_id = ? AND al.event_id = ? LIMIT 1'
        );
        $checkStmt->execute([$studentId, $eventId]);
        $record = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No attendance record found for that student on that event — nothing to undo.']);
            exit;
        }

        $deleteStmt = $pdo->prepare('DELETE FROM attendance_logs WHERE id = ?');
        $deleteStmt->execute([$record['id']]);

        try {
            $adminName = $_SESSION['cusg_admin']['full_name']
                ?? ($_SESSION['cusg_admin']['username'] ?? 'unknown admin');
            $undoStmt = $pdo->prepare(
                'INSERT INTO attendance_undo_log (student_id, event_id, prior_status, prior_scanned_at, prior_source, undone_by, undone_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $undoStmt->execute([$studentId, $eventId, $record['status'], $record['scanned_at'], $record['source'], $adminName]);
        } catch (Throwable $e) {
            // Non-fatal: the undo itself already succeeded above. Losing the
            // audit trail entry must never block the actual fix.
            logScanProblem('undo log failed', $e->getMessage() . " (student '{$studentId}', event {$eventId})");
        }

        echo json_encode([
            'success' => true,
            'message' => $record['full_name'] . '\'s attendance for "' . $record['event_title'] . '" was removed.',
            'student_id' => $studentId,
            'event_id' => $eventId,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    logScanProblem('unhandled error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}