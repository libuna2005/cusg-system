<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide your admin username and password.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, username, full_name, email, role, password_hash FROM admin_users WHERE username = ? OR email = ? LIMIT 1');
$stmt->execute([$username, $username]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || !password_verify($password, $admin['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid admin credentials.']);
    exit;
}

$_SESSION['cusg_admin_id'] = (int) $admin['id'];
$_SESSION['cusg_admin'] = [
    'id' => (int) $admin['id'],
    'username' => $admin['username'],
    'full_name' => $admin['full_name'] ?? '',
    'email' => $admin['email'] ?? '',
    'role' => $admin['role'] ?? 'admin',
];

session_regenerate_id(true);

echo json_encode(['success' => true, 'admin' => $_SESSION['cusg_admin']]);
