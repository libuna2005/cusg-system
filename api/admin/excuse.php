<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/common.php';

if (empty($_SESSION['cusg_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

/**
 * Splits "22-12345, 22-12346,22-12347" (or a single ID) into a clean,
 * de-duplicated, uppercased list. Used everywhere the individual-excuse
 * tool accepts one or more Student IDs, so a stray double-comma or extra
 * whitespace from copy-pasting a roster never produces a bogus empty ID.
 */
function parseStudentIdList(string $raw): array {
    $ids = [];
    $seen = [];
    foreach (explode(',', $raw) as $part) {
        $id = strtoupper(trim($part));
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $ids[] = $id;
    }
    return $ids;
}

try {
    ensureAttendanceStatusColumn($pdo);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Individual mode: look up one or more students by ID (comma
        // separated), with their existing attendance status for the
        // selected event if any, so the admin can confirm who they're
        // about to excuse before submitting.
        $studentIdParam = trim($_GET['student_id'] ?? '');
        if ($studentIdParam !== '') {
            $ids = parseStudentIdList($studentIdParam);
            if (count($ids) === 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No valid Student ID found in that input.']);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $studentStmt = $pdo->prepare("SELECT student_id, full_name, course_program, year_level, section, profile_picture_url FROM students WHERE student_id IN ($placeholders)");
            $studentStmt->execute($ids);
            $byId = [];
            foreach ($studentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byId[$row['student_id']] = $row;
            }

            $existingById = [];
            $eventIdParam = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
            if ($eventIdParam > 0 && count($byId) > 0) {
                $foundIds = array_keys($byId);
                $ePlaceholders = implode(',', array_fill(0, count($foundIds), '?'));
                $existStmt = $pdo->prepare("SELECT student_id, status, source, scanned_at FROM attendance_logs WHERE event_id = ? AND student_id IN ($ePlaceholders)");
                $existStmt->execute(array_merge([$eventIdParam], $foundIds));
                foreach ($existStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $existingById[$r['student_id']] = $r;
                }
            }

            $students = [];
            $notFound = [];
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $row = $byId[$id];
                    $row['existing_record'] = $existingById[$id] ?? null;
                    $students[] = $row;
                } else {
                    $notFound[] = $id;
                }
            }

            echo json_encode(['success' => true, 'students' => $students, 'not_found' => $notFound]);
            exit;
        }

        // Class mode: which students match this course/year/section combination?
        $course = trim($_GET['course_program'] ?? '');
        $year = trim($_GET['year_level'] ?? '');
        $section = strtoupper(trim($_GET['section'] ?? ''));

        if (!$course || !$year || !$section) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Course, year level, and section are all required.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT student_id, full_name FROM students WHERE course_program = ? AND year_level = ? AND section = ? ORDER BY full_name ASC');
        $stmt->execute([$course, $year, $section]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'students' => $students, 'count' => count($students)]);
        exit;
    }

    if ($method === 'POST') {
        $input = readJsonInput();
        $eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;
        $studentIdInput = trim((string) ($input['student_id'] ?? ''));
        $course = trim((string) ($input['course_program'] ?? ''));
        $year = trim((string) ($input['year_level'] ?? ''));
        $section = strtoupper(trim((string) ($input['section'] ?? '')));
        $reason = trim((string) ($input['reason'] ?? ''));

        // 'excused' = the class was expected to attend but has a valid reason
        // to miss it (e.g. kept by an instructor). 'no_class' = the class was
        // never part of the mandatory pool to begin with — its schedule
        // doesn't overlap the mandatory window (e.g. only an 11:00 AM class
        // that day, nothing at 7:30–9:00 AM). Kept as a distinct status so
        // reporting can tell "had a valid excuse" apart from "was never
        // required" instead of lumping both under one label.
        $markType = trim((string) ($input['mark_type'] ?? 'excused'));
        if (!in_array($markType, ['excused', 'no_class'], true)) {
            $markType = 'excused';
        }
        $statusLabel = $markType === 'no_class' ? 'NO CLASS / NOT REQUIRED' : 'EXCUSED';

        if (!$eventId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event is required.']);
            exit;
        }

        $eventStmt = $pdo->prepare('SELECT id, title FROM events WHERE id = ? LIMIT 1');
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Selected event was not found.']);
            exit;
        }

        $sourceLabel = $markType === 'no_class' ? 'admin_no_class' : 'admin_excuse';
        $source = $reason !== '' ? $sourceLabel . ': ' . $reason : $sourceLabel;

        // ---- Individual student mode -------------------------------------------
        // Excuses one or more specific students (comma separated), instead of
        // an entire course/year/section. Kept in this same file/table/undo-log
        // as the class-level flow below (same 'admin_excuse'/'admin_no_class'
        // source prefix), so it shows up identically everywhere else in the
        // app and Undo works the same way.
        if ($studentIdInput !== '') {
            $ids = parseStudentIdList($studentIdInput);
            if (count($ids) === 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No valid Student ID found in that input.']);
                exit;
            }

            $markedCount = 0;
            $alreadyRecordedCount = 0;
            $notFoundIds = [];

            $studentLookupStmt = $pdo->prepare('SELECT student_id, full_name FROM students WHERE student_id = ? LIMIT 1');
            $checkStmt = $pdo->prepare('SELECT status FROM attendance_logs WHERE student_id = ? AND event_id = ? LIMIT 1');
            $insertStmt = $pdo->prepare('INSERT INTO attendance_logs (student_id, event_id, source, status, scanned_at) VALUES (?, ?, ?, ?, NOW())');

            $pdo->beginTransaction();
            try {
                foreach ($ids as $id) {
                    $studentLookupStmt->execute([$id]);
                    $student = $studentLookupStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$student) {
                        $notFoundIds[] = $id;
                        continue;
                    }

                    $checkStmt->execute([$student['student_id'], $eventId]);
                    if ($checkStmt->fetch()) {
                        // Already has a record (present, excused, or no_class) — don't overwrite it.
                        $alreadyRecordedCount++;
                        continue;
                    }

                    $insertStmt->execute([$student['student_id'], $eventId, $source, $markType]);
                    $markedCount++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $verb = $markType === 'no_class' ? 'Marked' : 'Excused';
            $messageParts = ["{$verb} {$markedCount} of " . count($ids) . " student(s) as {$statusLabel} for \"{$event['title']}\"."];
            if ($alreadyRecordedCount > 0) {
                $messageParts[] = "{$alreadyRecordedCount} already had a record for this event and were left unchanged.";
            }
            if (count($notFoundIds) > 0) {
                $messageParts[] = count($notFoundIds) . ' ID(s) not found: ' . implode(', ', $notFoundIds) . '.';
            }

            echo json_encode([
                'success' => true,
                'mode' => 'individual',
                'message' => implode(' ', $messageParts),
                'marked_count' => $markedCount,
                'already_recorded_count' => $alreadyRecordedCount,
                'not_found_count' => count($notFoundIds),
                'not_found_ids' => $notFoundIds,
                'total_requested' => count($ids),
                'mark_type' => $markType,
                'event' => $event,
            ]);
            exit;
        }

        // ---- Class mode ---------------------------------------------------------
        if (!$course || !$year || !$section) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Course, year level, and section are all required.']);
            exit;
        }

        $studentsStmt = $pdo->prepare('SELECT student_id, full_name FROM students WHERE course_program = ? AND year_level = ? AND section = ?');
        $studentsStmt->execute([$course, $year, $section]);
        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($students) === 0) {
            echo json_encode(['success' => false, 'message' => 'No students found matching that course, year, and section.']);
            exit;
        }

        $markedCount = 0;
        $alreadyRecordedCount = 0;

        $pdo->beginTransaction();
        try {
            $checkStmt = $pdo->prepare('SELECT id FROM attendance_logs WHERE student_id = ? AND event_id = ? LIMIT 1');
            $insertStmt = $pdo->prepare('INSERT INTO attendance_logs (student_id, event_id, source, status, scanned_at) VALUES (?, ?, ?, ?, NOW())');

            foreach ($students as $student) {
                $checkStmt->execute([$student['student_id'], $eventId]);
                if ($checkStmt->fetch()) {
                    // Already has a record (present, excused, or no_class) — don't overwrite it.
                    $alreadyRecordedCount++;
                    continue;
                }
                $insertStmt->execute([$student['student_id'], $eventId, $source, $markType]);
                $markedCount++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $verb = $markType === 'no_class' ? 'Marked' : 'Excused';
        $message = "{$verb} {$markedCount} student(s) in {$course} {$year} - Section {$section} as {$statusLabel} for \"{$event['title']}\".";
        if ($alreadyRecordedCount > 0) {
            $message .= " {$alreadyRecordedCount} student(s) already had an attendance record for this event and were left unchanged.";
        }

        echo json_encode([
            'success' => true,
            'mode' => 'class',
            'message' => $message,
            'marked_count' => $markedCount,
            'excused_count' => $markedCount, // kept for backward compatibility with older callers
            'mark_type' => $markType,
            'already_recorded_count' => $alreadyRecordedCount,
            'total_matched' => count($students),
            'event' => $event,
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        // Bulk-undo: reverse an entire "excuse a class" / "mark no class"
        // action in one click, for when the wrong event or wrong
        // course/year/section was picked. Only removes rows this exact
        // tool created (source starts with 'admin_excuse' or
        // 'admin_no_class') for the matched students on that event — it
        // will NEVER touch a real scanned "present" record, even for the
        // same students/event, since those have a different source.
        ensureAttendanceUndoLogTable($pdo);

        $input = readJsonInput();
        $eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;
        $studentIdInput = trim((string) ($input['student_id'] ?? ''));
        $course = trim((string) ($input['course_program'] ?? ''));
        $year = trim((string) ($input['year_level'] ?? ''));
        $section = strtoupper(trim((string) ($input['section'] ?? '')));

        if (!$eventId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event is required.']);
            exit;
        }

        $adminName = $_SESSION['cusg_admin']['full_name']
            ?? ($_SESSION['cusg_admin']['username'] ?? 'unknown admin');

        // ---- Individual student undo -------------------------------------------
        if ($studentIdInput !== '') {
            $ids = parseStudentIdList($studentIdInput);
            if (count($ids) === 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No valid Student ID found in that input.']);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $findStmt = $pdo->prepare(
                "SELECT id, student_id, status, scanned_at, source FROM attendance_logs
                 WHERE event_id = ? AND student_id IN ($placeholders)
                 AND (source LIKE 'admin_excuse%' OR source LIKE 'admin_no_class%')"
            );
            $findStmt->execute(array_merge([$eventId], $ids));
            $rowsToRemove = $findStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($rowsToRemove) === 0) {
                echo json_encode(['success' => false, 'message' => 'Nothing to undo — no Excused / No Class record from this tool was found for those student(s) and event.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $deleteStmt = $pdo->prepare('DELETE FROM attendance_logs WHERE id = ?');
                $undoLogStmt = $pdo->prepare(
                    'INSERT INTO attendance_undo_log (student_id, event_id, prior_status, prior_scanned_at, prior_source, undone_by, undone_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                );
                foreach ($rowsToRemove as $row) {
                    $deleteStmt->execute([$row['id']]);
                    $undoLogStmt->execute([$row['student_id'], $eventId, $row['status'], $row['scanned_at'], $row['source'], $adminName]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode([
                'success' => true,
                'mode' => 'individual',
                'message' => count($rowsToRemove) . ' record(s) removed.',
                'removed_count' => count($rowsToRemove),
            ]);
            exit;
        }

        // ---- Class undo -----------------------------------------------------------
        if (!$course || !$year || !$section) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Course, year level, and section are all required.']);
            exit;
        }

        $studentsStmt = $pdo->prepare('SELECT student_id FROM students WHERE course_program = ? AND year_level = ? AND section = ?');
        $studentsStmt->execute([$course, $year, $section]);
        $studentIds = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($studentIds) === 0) {
            echo json_encode(['success' => false, 'message' => 'No students found matching that course, year, and section.']);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $findStmt = $pdo->prepare(
            "SELECT id, student_id, status, scanned_at, source FROM attendance_logs
             WHERE event_id = ? AND student_id IN ($placeholders)
             AND (source LIKE 'admin_excuse%' OR source LIKE 'admin_no_class%')"
        );
        $findStmt->execute(array_merge([$eventId], $studentIds));
        $rowsToRemove = $findStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rowsToRemove) === 0) {
            echo json_encode(['success' => false, 'message' => 'Nothing to undo — no Excused / No Class records from this tool were found for that course, year, section, and event.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $deleteStmt = $pdo->prepare('DELETE FROM attendance_logs WHERE id = ?');
            $undoLogStmt = $pdo->prepare(
                'INSERT INTO attendance_undo_log (student_id, event_id, prior_status, prior_scanned_at, prior_source, undone_by, undone_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            foreach ($rowsToRemove as $row) {
                $deleteStmt->execute([$row['id']]);
                $undoLogStmt->execute([$row['student_id'], $eventId, $row['status'], $row['scanned_at'], $row['source'], $adminName]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success' => true,
            'mode' => 'class',
            'message' => count($rowsToRemove) . " record(s) removed for {$course} {$year} - Section {$section}.",
            'removed_count' => count($rowsToRemove),
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}