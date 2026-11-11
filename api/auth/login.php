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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Please provide both email and password.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, student_id, full_name, last_name, first_name, middle_name, course_program, year_level, section, institutional_email, password_hash, profile_picture_url FROM students WHERE institutional_email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'No user found. Register now.']);
    exit;
}

if (!password_verify($password, $user['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
    exit;
}

unset($user['password_hash']);

echo json_encode(['success' => true, 'user' => $user]);