<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/schema.php';

ensureSectionColumn($pdo);

function ensureNamePartColumns(PDO $pdo): void {
    $lastCol = $pdo->query("SHOW COLUMNS FROM students LIKE 'last_name'")->fetch();
    if (!$lastCol) {
        $pdo->exec("ALTER TABLE students ADD COLUMN last_name VARCHAR(100) NULL AFTER full_name");
    }
    $firstCol = $pdo->query("SHOW COLUMNS FROM students LIKE 'first_name'")->fetch();
    if (!$firstCol) {
        $pdo->exec("ALTER TABLE students ADD COLUMN first_name VARCHAR(100) NULL AFTER last_name");
    }
    $middleCol = $pdo->query("SHOW COLUMNS FROM students LIKE 'middle_name'")->fetch();
    if (!$middleCol) {
        $pdo->exec("ALTER TABLE students ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name");
    }
}
ensureNamePartColumns($pdo);

/**
 * NOTE: profile picture uploads are handled separately by
 * api/student/upload_profile_picture.php (real multipart $_FILES upload,
 * same pattern as the attendance-portal app). This endpoint only ever
 * touches the text fields, so it stays plain JSON.
 */
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$studentId = trim($input['student_id'] ?? '');
$newStudentId = strtoupper(trim($input['new_student_id'] ?? ''));
$lastName = strtoupper(trim($input['last_name'] ?? ''));
$firstName = strtoupper(trim($input['first_name'] ?? ''));
$middleName = strtoupper(trim($input['middle_name'] ?? ''));
$fullName = strtoupper(trim($input['full_name'] ?? ''));
if (!$fullName && ($lastName || $firstName)) {
    $fullName = trim($lastName . ', ' . $firstName . ($middleName ? ' ' . $middleName : ''), ', ');
}
$courseProgram = trim($input['course_program'] ?? '');
$yearLevel = trim($input['year_level'] ?? '');
$section = strtoupper(trim($input['section'] ?? ''));
$institutionalEmail = trim($input['institutional_email'] ?? '');

if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
    exit;
}

// `new_student_id` is the value the student typed into the "Student ID
// Number" field this time around, which may or may not actually differ
// from their current one. Fall back to the current ID if the field was
// left blank/unsent, since student_id is otherwise a required column.
if (!$newStudentId) {
    $newStudentId = $studentId;
}

if (!$lastName || !$firstName || !$courseProgram || !$yearLevel || !$section || !$institutionalEmail) {
    echo json_encode(['success' => false, 'message' => 'Please complete all required fields, including last name and first name.']);
    exit;
}

try {
    if ($newStudentId !== $studentId) {
        // Changing the Student ID number itself (not just a typo fix to
        // another field). Check for a collision first so we can give a
        // friendly message -- attendance_logs/event_registrations both
        // have this column as a foreign key with ON UPDATE CASCADE, so
        // once this succeeds their rows follow automatically.
        $dupStmt = $pdo->prepare('SELECT id FROM students WHERE student_id = ? AND student_id <> ? LIMIT 1');
        $dupStmt->execute([$newStudentId, $studentId]);
        if ($dupStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'That Student ID is already in use by another account.']);
            exit;
        }
    }

    $stmt = $pdo->prepare('UPDATE students SET student_id = ?, full_name = ?, last_name = ?, first_name = ?, middle_name = ?, course_program = ?, year_level = ?, section = ?, institutional_email = ?, updated_at = NOW() WHERE student_id = ?');
    $stmt->execute([$newStudentId, $fullName, $lastName, $firstName, $middleName ?: null, $courseProgram, $yearLevel, $section, $institutionalEmail, $studentId]);

    // MySQL's rowCount() counts rows that CHANGED, not rows that matched. Saving the same
    // values twice (double tap, or Save pressed again) changes nothing, so 0 does not mean
    // "not found" - check the row really exists before reporting an error.
    if ($stmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare('SELECT 1 FROM students WHERE student_id = ? LIMIT 1');
        $existsStmt->execute([$newStudentId]);
        if (!$existsStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Student profile not found.']);
            exit;
        }
    }

    // Tell the frontend the (possibly new) student_id so it can update
    // its stored copy -- every other endpoint (QR, attendance, photo
    // upload, password, delete) looks the student up by this value, so if
    // the frontend keeps using the stale one after a successful rename,
    // those calls will start 404ing.
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully.', 'student_id' => $newStudentId]);
} catch (Throwable $e) {
    // A race where two people grab the same new ID between our check and
    // the UPDATE above would land here as a duplicate-key error.
    if ($e instanceof PDOException && (int) $e->getCode() === 23000) {
        echo json_encode(['success' => false, 'message' => 'That Student ID is already in use by another account.']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
