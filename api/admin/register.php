<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$full_name = strtoupper(trim($input['full_name'] ?? ''));
$email = trim($input['email'] ?? '');
$role = trim($input['role'] ?? 'admin');

if (!$full_name || !$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Full name and email are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

// Generate temporary password (8 characters: uppercase, lowercase, numbers, special chars)
function generateTempPassword($length = 8) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

$temp_password = generateTempPassword();
$password_hash = password_hash($temp_password, PASSWORD_DEFAULT);
$username = $email; // Use email as username

try {
    $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, full_name, email, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$username, $password_hash, $full_name, $email, $role]);

    echo json_encode([
        'success' => true,
        'message' => 'Admin account created successfully.',
        'admin' => [
            'id' => (int) $pdo->lastInsertId(),
            'username' => $username,
            'email' => $email,
            'full_name' => $full_name,
            'role' => $role,
            'temp_password' => $temp_password,
        ]
    ]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This email is already registered as an admin.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}