<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/uploads.php';

if (empty($_SESSION['cusg_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

function tableExists(PDO $pdo, string $table): bool {
    // SHOW statements can't take bound placeholders on MariaDB, so quote the
    // (hard-coded) table name instead.
    return (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetch();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];
    $studentId = trim((string) ($input['student_id'] ?? ''));

    if ($studentId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT student_id, full_name, profile_picture_url FROM students WHERE student_id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student not found or already deleted.']);
        exit;
    }

    $counts = [
        'attendance_logs' => 0,
        'event_registrations' => 0,
        'attendance_undo_log' => 0,
        'unresolved_scans' => 0,
    ];

    $pdo->beginTransaction();

    // Every record tied to this student ID. Tables are checked first because
    // a few of them are self-healing and may not exist on every database yet.
    if (tableExists($pdo, 'attendance_logs')) {
        $d = $pdo->prepare('DELETE FROM attendance_logs WHERE student_id = ?');
        $d->execute([$studentId]);
        $counts['attendance_logs'] = $d->rowCount();
    }
    if (tableExists($pdo, 'event_registrations')) {
        $d = $pdo->prepare('DELETE FROM event_registrations WHERE student_id = ?');
        $d->execute([$studentId]);
        $counts['event_registrations'] = $d->rowCount();
    }
    if (tableExists($pdo, 'attendance_undo_log')) {
        $d = $pdo->prepare('DELETE FROM attendance_undo_log WHERE student_id = ?');
        $d->execute([$studentId]);
        $counts['attendance_undo_log'] = $d->rowCount();
    }
    if (tableExists($pdo, 'unresolved_scans')) {
        $d = $pdo->prepare('DELETE FROM unresolved_scans WHERE attempted_student_id = ? OR resolved_student_id = ?');
        $d->execute([$studentId, $studentId]);
        $counts['unresolved_scans'] = $d->rowCount();
    }

    $d = $pdo->prepare('DELETE FROM students WHERE student_id = ?');
    $d->execute([$studentId]);
    if ($d->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student not found or already deleted.']);
        exit;
    }

    $pdo->commit();

    // Database is clean — now remove the files (profile photo + QR ID card).
    $files = deleteStudentUploadedFiles($studentId);

    // Also remove the exact file the profile column pointed to, in case it
    // doesn't follow the profile_{id}_* naming (older uploads).
    $url = trim((string) ($student['profile_picture_url'] ?? ''));
    if ($url !== '' && strpos($url, PROFILE_UPLOAD_URL) === 0) {
        $base = basename(parse_url($url, PHP_URL_PATH) ?: '');
        $path = PROFILE_UPLOAD_DIR . $base;
        if ($base !== '' && is_file($path) && @unlink($path)) {
            $files['profile_removed']++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Deleted ' . $student['full_name'] . ' (' . $student['student_id'] . ') and all related records.',
        'removed' => array_merge($counts, $files),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
