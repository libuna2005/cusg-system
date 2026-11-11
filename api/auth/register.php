<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/uploads.php';

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

ensureSectionColumn($pdo);
ensureNamePartColumns($pdo);

// PHP silently empties BOTH $_POST and $_FILES (no warning to the browser)
// when the whole multipart request exceeds post_max_size in php.ini — which
// looks identical to "the user forgot to fill in the form" from here. This
// catches that specific case and reports it clearly instead of the generic
// "please complete all required fields" message.
if (empty($_POST) && empty($_FILES) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Your photo is too large for this server\'s upload limit (post_max_size in php.ini). Try a smaller image, or ask whoever manages the server to raise post_max_size and upload_max_filesize.'
    ]);
    exit;
}

// Registration now submits as multipart/form-data (real <input type="file">
// upload) instead of JSON + base64, so fields come from $_POST/$_FILES here
// — same as the rest of this form's real file input.
$studentId = strtoupper(trim($_POST['student_id'] ?? ''));
$lastName = strtoupper(trim($_POST['last_name'] ?? ''));
$firstName = strtoupper(trim($_POST['first_name'] ?? ''));
$middleName = strtoupper(trim($_POST['middle_name'] ?? ''));
// full_name kept for backward-compat display in places that haven't been
// switched over to the separate columns yet; rebuild it server-side too as
// a defensive fallback in case a client ever sends it without the parts.
$fullName = strtoupper(trim($_POST['full_name'] ?? ''));
if (!$fullName && ($lastName || $firstName)) {
    $fullName = trim($lastName . ', ' . $firstName . ($middleName ? ' ' . $middleName : ''), ', ');
}
$courseProgram = trim($_POST['course_program'] ?? '');
$yearLevel = trim($_POST['year_level'] ?? '');
$section = strtoupper(trim($_POST['section'] ?? ''));
$institutionalEmail = trim($_POST['institutional_email'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$termsAccepted = !empty($_POST['terms']);
$hasPhoto = !empty($_FILES['profile_picture']) && ($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if (!$studentId || !$lastName || !$firstName || !$courseProgram || !$yearLevel || !$section || !$institutionalEmail || !$password || !$confirmPassword || !$hasPhoto) {
    echo json_encode(['success' => false, 'message' => 'Please complete all required fields, including your last name, first name, and a profile picture.']);
    exit;
}

if ($password !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
    exit;
}

if (!$termsAccepted) {
    echo json_encode(['success' => false, 'message' => 'Please confirm the terms and conditions.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id FROM students WHERE student_id = ? OR institutional_email = ? LIMIT 1');
$stmt->execute([$studentId, $institutionalEmail]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'A student with this ID or email already exists.']);
    exit;
}

// Only touch the filesystem once every other check has passed, so a bad
// submission (duplicate ID, weak password, etc.) doesn't leave an orphaned
// upload behind.
$photoResult = saveUploadedProfilePhoto($_FILES['profile_picture'], $studentId);
if (!$photoResult['success']) {
    echo json_encode(['success' => false, 'message' => $photoResult['message']]);
    exit;
}
$profilePictureUrl = $photoResult['path'];

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO students (student_id, full_name, last_name, first_name, middle_name, course_program, year_level, section, institutional_email, password_hash, profile_picture_url, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$studentId, $fullName, $lastName, $firstName, $middleName ?: null, $courseProgram, $yearLevel, $section, $institutionalEmail, $hashedPassword, $profilePictureUrl]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Account created successfully.']);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
}
