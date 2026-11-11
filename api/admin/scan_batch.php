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
// officer's requests (uploads + background refreshes) from queueing up behind
// each other on shared hosting.
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Keep each request small/fast so it comfortably finishes inside shared-hosting
// execution time limits. The client is responsible for chunking bigger queues
// into requests of this size or smaller.
const MAX_BATCH_SIZE = 300;

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

// A durable, permanent trail of every upload attempt — independent of
// attendance_logs — so "did an upload happen, and what did it record" can
// always be answered later, even if the underlying scans were duplicates,
// failures, or otherwise didn't create an attendance_logs row.
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
    // Self-heal existing installs created before name-mismatch tracking existed.
    $col = $pdo->query("SHOW COLUMNS FROM upload_audit_log LIKE 'name_mismatch_count'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE upload_audit_log ADD COLUMN name_mismatch_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER failed_count");
    }
}

// Best-effort insert — must never throw or block the actual batch response,
// since the attendance-saving path already succeeded/failed independently
// of whether this trail gets written.
function logUnresolvedScan(PDO $pdo, ?string $deviceLabel, int $eventId, string $attemptedStudentId, string $qrDataRaw, string $reason, string $source): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO unresolved_scans (device_label, event_id, attempted_student_id, qr_data_raw, reason, source, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $deviceLabel !== '' ? $deviceLabel : null,
            $eventId > 0 ? $eventId : null,
            $attemptedStudentId !== '' ? $attemptedStudentId : null,
            $qrDataRaw !== '' ? mb_substr($qrDataRaw, 0, 500) : null,
            $reason,
            $source,
        ]);
    } catch (Throwable $e) {
        // Diagnostic trail only - never blocks the actual batch response, but not silent either.
        logScanProblem('unresolved-scan log failed', $e->getMessage() . " (event {$eventId}, id '{$attemptedStudentId}', reason {$reason})");
    }
}

try {
    ensureSectionColumn($pdo);
    ensureAttendanceStatusColumn($pdo);
    ensureUploadAuditLogTable($pdo);
    ensureUnresolvedScansTable($pdo);
    ensureNamePartColumns($pdo);

    $input = readJsonInput();
    $scans = isset($input['scans']) && is_array($input['scans']) ? $input['scans'] : [];
    // Officer account first; the phone's own label is only a fallback.
    $deviceLabel = $officerLabel !== '' ? $officerLabel : trim((string) ($input['device_label'] ?? ''));

    if (empty($scans)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No scans were included in this batch.']);
        exit;
    }

    if (count($scans) > MAX_BATCH_SIZE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Batch too large. Please send ' . MAX_BATCH_SIZE . ' scans or fewer per upload.']);
        exit;
    }

    // ---- Normalize incoming items -------------------------------------------------
    $items = [];
    foreach ($scans as $i => $raw) {
        $tempId = isset($raw['temp_id']) ? (string) $raw['temp_id'] : ('idx_' . $i);
        $eventId = isset($raw['event_id']) ? (int) $raw['event_id'] : 0;
        $qrData = trim((string) ($raw['qr_data'] ?? ''));
        $manualStudentId = cleanScanText((string) ($raw['student_id'] ?? ''));
        $studentId = $manualStudentId !== '' ? $manualStudentId : extractStudentId($qrData);

        $items[] = [
            'temp_id' => $tempId,
            'event_id' => $eventId,
            'student_id' => $studentId,
            'qr_data_raw' => $qrData,
        ];
    }

    // ---- Preload every event referenced in this batch in one query ----------------
    $eventIds = array_values(array_unique(array_filter(array_map(fn($it) => $it['event_id'], $items))));
    $events = [];
    if (!empty($eventIds)) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $pdo->prepare("SELECT id, title, event_date, is_flagged FROM events WHERE id IN ($placeholders)");
        $stmt->execute($eventIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[(int) $row['id']] = $row;
        }
    }

    // ---- Preload every student referenced in this batch in one query --------------
    $studentIds = array_values(array_unique(array_filter(array_map(fn($it) => $it['student_id'], $items), fn($v) => $v !== '')));
    $students = [];
    if (!empty($studentIds)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare("SELECT student_id, full_name, last_name, first_name, course_program, year_level, section, profile_picture_url FROM students WHERE student_id IN ($placeholders)");
        $stmt->execute($studentIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['profile_picture_url'] = normalizeProfilePicture($row['profile_picture_url']);
            // MySQL matched these IDs case-insensitively, so key them that way here too.
            $students[strtoupper($row['student_id'])] = $row;
        }
    }

    // ---- Preload existing attendance rows for the (event, student) pairs involved -
    $existing = []; // key "eventId|studentId" => ['scanned_at'=>..., 'status'=>...]
    if (!empty($eventIds) && !empty($studentIds)) {
        $eventPlaceholders = implode(',', array_fill(0, count($eventIds), '?'));
        $studentPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT event_id, student_id, scanned_at, status FROM attendance_logs
             WHERE event_id IN ($eventPlaceholders) AND student_id IN ($studentPlaceholders)"
        );
        $stmt->execute(array_merge($eventIds, $studentIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$row['event_id'] . '|' . $row['student_id']] = $row;
        }
    }

    // ---- Walk the batch, classify each item, insert the new ones ------------------
    // Deliberately NOT wrapped in one big transaction: each scan is an independent
    // unit of work (like one bank transfer, not a batch file). If row #180 hits an
    // unexpected error, rows 1-179 must stay committed — they should never be rolled
    // back just because an unrelated later row failed.
    $insertStmt = $pdo->prepare(
        "INSERT INTO attendance_logs (student_id, event_id, source, status, scanned_at) VALUES (?, ?, 'admin_qr_scan', 'present', NOW())"
    );
    // Read-back verification: after each insert, independently re-read the row
    // back from the database before telling the phone it's safe to forget this
    // scan. This runs inside the same server-side request — it does NOT cost an
    // extra hit from the phone, just one more (fast, indexed) query per record.
    $verifyStmt = $pdo->prepare(
        'SELECT id FROM attendance_logs WHERE student_id = ? AND event_id = ? LIMIT 1'
    );

    $results = [];
    $seenInBatch = []; // guards against the same pair appearing twice in one payload

    foreach ($items as $item) {
        $tempId = $item['temp_id'];
        $eventId = $item['event_id'];
        $studentId = $item['student_id'];
        $qrDataRaw = $item['qr_data_raw'];

        if (!$eventId || !isset($events[$eventId])) {
            $results[] = ['temp_id' => $tempId, 'status' => 'invalid', 'message' => 'Selected event was not found.', 'student_id' => $studentId];
            logUnresolvedScan($pdo, $deviceLabel, $eventId, $studentId, $qrDataRaw, 'invalid', 'batch');
            continue;
        }
        if ($studentId === '') {
            $results[] = ['temp_id' => $tempId, 'status' => 'invalid', 'message' => 'Could not read a valid student ID from the QR code.'];
            logUnresolvedScan($pdo, $deviceLabel, $eventId, $studentId, $qrDataRaw, 'invalid', 'batch');
            continue;
        }
        // The ID on the card is not registered: try the name inside the QR (unique exact match only).
        $matchedByName = false;
        $qrStudentId = $studentId;
        if (!isset($students[strtoupper($studentId)])) {
            $byName = findStudentByQrName($pdo, $qrDataRaw);
            if ($byName) {
                $byName['profile_picture_url'] = normalizeProfilePicture($byName['profile_picture_url']);
                $students[strtoupper($byName['student_id'])] = $byName;
                $studentId = $byName['student_id'];
                $matchedByName = true;
                logScanProblem('matched by name', "QR id '{$qrStudentId}' not registered; matched {$studentId} by name");
                // The preload above only covered the IDs printed on the cards, so load this pair's record now.
                $priorStmt = $pdo->prepare('SELECT event_id, student_id, scanned_at, status FROM attendance_logs WHERE event_id = ? AND student_id = ? LIMIT 1');
                $priorStmt->execute([$eventId, $studentId]);
                if ($priorRow = $priorStmt->fetch(PDO::FETCH_ASSOC)) {
                    $existing[$eventId . '|' . $studentId] = $priorRow;
                }
            }
        }
        if (!isset($students[strtoupper($studentId)])) {
            $results[] = ['temp_id' => $tempId, 'status' => 'not_found', 'message' => "No student record found for ID \"{$studentId}\".", 'student_id' => $studentId];
            logUnresolvedScan($pdo, $deviceLabel, $eventId, $studentId, $qrDataRaw, 'not_found', 'batch');
            continue;
        }

        $student = $students[strtoupper($studentId)];
        $event = $events[$eventId];

        // Use the ID exactly as stored from here on, so the duplicate checks and
        // the saved row all line up with the student's real record.
        $studentId = $student['student_id'];
        $pairKey = $eventId . '|' . $studentId;

        // Name cross-check: same reasoning as scan.php -- the ID matched a
        // real student, but does the name embedded in the QR actually
        // belong to them? Catches an ID that collides with a *different*
        // real student instead of silently crediting the wrong person.
        $qrNameParts = extractQrNameParts($qrDataRaw);
        if (!namesLikelyMatch($qrNameParts['last_name'], $qrNameParts['first_name'], $student['last_name'] ?? '', $student['first_name'] ?? '')) {
            $results[] = [
                'temp_id' => $tempId,
                'status' => 'name_mismatch',
                'message' => "This QR's ID belongs to {$student['full_name']}, but the name on the card doesn't match. Not recorded -- please verify manually.",
                'student' => $student,
                'event' => $event,
            ];
            logUnresolvedScan($pdo, $deviceLabel, $eventId, $studentId, $qrDataRaw, 'name_mismatch', 'batch');
            continue;
        }

        if (isset($existing[$pairKey])) {
            $priorStatus = $existing[$pairKey]['status'] ?? 'present';
            if ($priorStatus === 'excused') {
                $priorLabel = 'already marked excused';
            } elseif ($priorStatus === 'no_class') {
                $priorLabel = 'already marked as no class / not required';
            } else {
                $priorLabel = 'already scanned present';
            }
            $results[] = [
                'temp_id' => $tempId,
                'status' => 'duplicate',
                'prior_status' => $priorStatus,
                'message' => $student['full_name'] . ' was ' . $priorLabel . ' for this event.',
                'student' => $student,
                'event' => $event,
            ];
            continue;
        }

        if (isset($seenInBatch[$pairKey])) {
            // Same student scanned twice within this offline batch (e.g. queued
            // twice before upload) — the first occurrence already inserted it.
            $results[] = [
                'temp_id' => $tempId,
                'status' => 'duplicate',
                'message' => $student['full_name'] . ' was already recorded earlier in this same upload.',
                'student' => $student,
                'event' => $event,
            ];
            continue;
        }

        try {
            executeInsertWithRetry($insertStmt, [$studentId, $eventId]);
            $seenInBatch[$pairKey] = true;

            // Independently confirm the row is really there before reporting
            // success — catches the rare case of a bug or environment quirk
            // where the INSERT didn't throw but the row still isn't readable.
            $verifyStmt->execute([$studentId, $eventId]);
            $verified = $verifyStmt->fetch(PDO::FETCH_ASSOC);

            if ($verified) {
                $results[] = [
                    'temp_id' => $tempId,
                    'status' => 'success',
                    'message' => 'Attendance recorded for ' . $student['full_name'] . '.'
                        . ($matchedByName ? " (The QR's ID \"{$qrStudentId}\" is not registered, so this was matched by NAME to ID {$studentId} - please confirm it is the right person.)" : ''),
                    'matched_by' => $matchedByName ? 'name' : 'id',
                    'student' => $student,
                    'event' => $event,
                ];
            } else {
                // INSERT reported no error, but the read-back can't find the row.
                // Treat this as unresolved rather than trusting the INSERT alone —
                // it stays in the phone's queue and gets retried, never silently
                // reported as saved when it might not actually be.
                logScanProblem('save not confirmed', "student '{$studentId}', event {$eventId}, device '{$deviceLabel}'");
                $results[] = [
                    'temp_id' => $tempId,
                    'status' => 'failed',
                    'message' => 'Save could not be confirmed for ' . $student['full_name'] . '. It will be retried on the next upload.',
                    'student' => $student,
                    'event' => $event,
                ];
            }
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000 || strpos($e->getMessage(), 'uq_attendance_once_per_student_event') !== false) {
                $seenInBatch[$pairKey] = true;
                $results[] = [
                    'temp_id' => $tempId,
                    'status' => 'duplicate',
                    'message' => $student['full_name'] . ' was already scanned for this event.',
                    'student' => $student,
                    'event' => $event,
                ];
                continue;
            }
            // Any OTHER database error (a transient connection hiccup, etc.) must not
            // kill the whole batch — record this one row as failed and keep processing
            // the rest. It's still sitting untouched in the phone's local queue, so it
            // will be retried automatically.
            logScanProblem('insert failed', $e->getMessage() . " (student '{$studentId}', event {$eventId}, device '{$deviceLabel}')");
            $results[] = [
                'temp_id' => $tempId,
                'status' => 'failed',
                'message' => 'Could not save this record (' . $e->getMessage() . '). It will be retried on the next upload.',
                'student' => $student,
                'event' => $event,
            ];
        } catch (Throwable $e) {
            logScanProblem('insert failed (non-PDO)', $e->getMessage() . " (student '{$studentId}', event {$eventId}, device '{$deviceLabel}')");
            $results[] = [
                'temp_id' => $tempId,
                'status' => 'failed',
                'message' => 'Could not save this record (' . $e->getMessage() . '). It will be retried on the next upload.',
                'student' => $student,
                'event' => $event,
            ];
        }
    }

    $summary = ['success' => 0, 'duplicate' => 0, 'not_found' => 0, 'invalid' => 0, 'failed' => 0, 'name_mismatch' => 0];
    foreach ($results as $r) {
        if (isset($summary[$r['status']])) {
            $summary[$r['status']]++;
        }
    }

    // ---- Durable audit trail ---------------------------------------------------
    // Independent of attendance_logs — a permanent record that this upload attempt
    // happened at all, with what counts, from which device. If a student later says
    // "I scanned but it's not showing", this is how you check whether their scan was
    // ever part of an upload the server actually received, instead of only being
    // able to say "there's no record" with no way to tell why.
    try {
        $eventIdsInBatch = array_values(array_unique(array_map(fn($it) => $it['event_id'], $items)));
        $logStmt = $pdo->prepare(
            'INSERT INTO upload_audit_log (device_label, event_ids, item_count, success_count, duplicate_count, not_found_count, invalid_count, failed_count, name_mismatch_count, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $logStmt->execute([
            $deviceLabel !== '' ? $deviceLabel : null,
            implode(',', $eventIdsInBatch),
            count($items),
            $summary['success'],
            $summary['duplicate'],
            $summary['not_found'],
            $summary['invalid'],
            $summary['failed'],
            $summary['name_mismatch'],
        ]);
    } catch (Throwable $e) {
        // Never let audit logging failure block the actual attendance save that
        // already succeeded above — this is a nice-to-have trail, not the source
        // of truth for whether attendance was recorded.
        logScanProblem('upload audit log failed', $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'results' => $results,
    ]);
} catch (Throwable $e) {
    logScanProblem('unhandled error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
