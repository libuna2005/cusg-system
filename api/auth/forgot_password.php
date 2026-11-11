<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/mailer.php';

/**
 * Self-healing schema: adds OTP columns to students if missing, mirroring
 * the ensureSectionColumn() pattern already used elsewhere in this app.
 */
function ensureOtpColumns(PDO $pdo): void {
    $col = $pdo->query("SHOW COLUMNS FROM students LIKE 'reset_otp_hash'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE students ADD COLUMN reset_otp_hash VARCHAR(255) NULL AFTER password_hash");
    }
    $col = $pdo->query("SHOW COLUMNS FROM students LIKE 'reset_otp_expires_at'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE students ADD COLUMN reset_otp_expires_at DATETIME NULL AFTER reset_otp_hash");
    }
    $col = $pdo->query("SHOW COLUMNS FROM students LIKE 'reset_otp_attempts'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE students ADD COLUMN reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reset_otp_expires_at");
    }
}
ensureOtpColumns($pdo);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim($input['email'] ?? ''));

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, full_name, first_name, institutional_email FROM students WHERE institutional_email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'No account found with that email. Please check for typos or register first.']);
    exit;
}

// Simple rate limit: don't allow a new OTP request within 60 seconds of the
// previous one still being valid, to reduce spam / cost on the Brevo account.
$checkStmt = $pdo->prepare('SELECT reset_otp_expires_at FROM students WHERE id = ?');
$checkStmt->execute([$user['id']]);
$existing = $checkStmt->fetchColumn();
if ($existing && strtotime($existing) - time() > (10 * 60 - 60)) {
    // An OTP was issued less than 60 seconds ago (expiry is still almost 10 min away)
    echo json_encode(['success' => false, 'message' => 'A code was already sent. Please wait a minute before requesting another.']);
    exit;
}

$otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otpHash = password_hash($otp, PASSWORD_DEFAULT);
$expiresAt = date('Y-m-d H:i:s', time() + (10 * 60)); // 10 minutes

$upStmt = $pdo->prepare('UPDATE students SET reset_otp_hash = ?, reset_otp_expires_at = ?, reset_otp_attempts = 0 WHERE id = ?');
$upStmt->execute([$otpHash, $expiresAt, $user['id']]);

$name = $user['first_name'] ?: $user['full_name'];
$html = buildOtpEmailHtml($name, $otp);
$emailResult = sendBrevoEmail($user['institutional_email'], $name, 'Your CUSG Alijis password reset code', $html);

if (!$emailResult['success']) {
    // Roll back the issued OTP so a broken email config doesn't leave a
    // dangling valid code, then surface the real error (useful while setting
    // up Brevo; consider logging instead of echoing this in production).
    $pdo->prepare('UPDATE students SET reset_otp_hash = NULL, reset_otp_expires_at = NULL WHERE id = ?')->execute([$user['id']]);
    echo json_encode(['success' => false, 'message' => 'Failed to send the reset email: ' . $emailResult['message']]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'A one-time code has been sent to your email.']);
