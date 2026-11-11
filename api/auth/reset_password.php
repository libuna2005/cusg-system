<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim($input['email'] ?? ''));
$otp = trim($input['otp'] ?? '');
$newPassword = $input['new_password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';

if (!$email || !$otp || !$newPassword || !$confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Please complete all fields.']);
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

$stmt = $pdo->prepare('SELECT id, reset_otp_hash, reset_otp_expires_at, reset_otp_attempts FROM students WHERE institutional_email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !$user['reset_otp_hash'] || !$user['reset_otp_expires_at']) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired code. Please request a new one.']);
    exit;
}

if (strtotime($user['reset_otp_expires_at']) < time()) {
    $pdo->prepare('UPDATE students SET reset_otp_hash = NULL, reset_otp_expires_at = NULL, reset_otp_attempts = 0 WHERE id = ?')
        ->execute([$user['id']]);
    echo json_encode(['success' => false, 'message' => 'This code has expired. Please request a new one.']);
    exit;
}

if ((int) $user['reset_otp_attempts'] >= 5) {
    $pdo->prepare('UPDATE students SET reset_otp_hash = NULL, reset_otp_expires_at = NULL, reset_otp_attempts = 0 WHERE id = ?')
        ->execute([$user['id']]);
    echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.']);
    exit;
}

if (!password_verify($otp, $user['reset_otp_hash'])) {
    $pdo->prepare('UPDATE students SET reset_otp_attempts = reset_otp_attempts + 1 WHERE id = ?')->execute([$user['id']]);
    $remaining = 5 - ((int) $user['reset_otp_attempts'] + 1);
    echo json_encode(['success' => false, 'message' => "Incorrect code. {$remaining} attempt(s) remaining."]);
    exit;
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$upStmt = $pdo->prepare('UPDATE students SET password_hash = ?, reset_otp_hash = NULL, reset_otp_expires_at = NULL, reset_otp_attempts = 0, updated_at = NOW() WHERE id = ?');
$upStmt->execute([$newHash, $user['id']]);

echo json_encode(['success' => true, 'message' => 'Password reset successfully. You can now sign in.']);
