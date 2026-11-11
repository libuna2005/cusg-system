<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$studentId = trim($input['student_id'] ?? '');
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';

if (!$studentId || !$currentPassword || !$newPassword || !$confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Please complete all password fields.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters long.']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT password_hash FROM students WHERE student_id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upStmt = $pdo->prepare('UPDATE students SET password_hash = ?, updated_at = NOW() WHERE student_id = ?');
    $upStmt->execute([$newHash, $studentId]);

    echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
