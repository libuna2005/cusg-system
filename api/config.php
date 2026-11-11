<?php
$host = 'sql102.infinityfree.com';
$dbname = 'if0_42364777_attendance';
$username = 'if0_42364777';
$password = 'chmsucusgalijis';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    error_log('[cusg] database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// ── Brevo (transactional email) settings ──────────────────────────────────
// Get your API key from https://app.brevo.com -> Settings -> SMTP & API -> API Keys
// BREVO_SENDER_EMAIL must be a verified sender in Brevo (Settings -> Senders).
if (!defined('BREVO_API_KEY')) {
    define('BREVO_API_KEY', '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');
}
if (!defined('BREVO_SENDER_EMAIL')) {
    define('BREVO_SENDER_EMAIL', 'cusgalijis@gmail.com');
}
if (!defined('BREVO_SENDER_NAME')) {
    define('BREVO_SENDER_NAME', 'chmsu');
}
