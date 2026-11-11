<?php
require_once __DIR__ . '/../config.php';

$username = 'cusgalijis@gmail.com';
$password = 'cusgalijischmsu';
$full_name = 'Super Admin';
$email = 'cusgalijis@gmail.com';

$password_hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, full_name, email, role) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name), role = VALUES(role)');
$stmt->execute([$username, $password_hash, $full_name, $email, 'superadmin']);

$verify = $pdo->prepare('SELECT id, username, email, role FROM admin_users WHERE username = ?');
$verify->execute([$username]);
$result = $verify->fetch(PDO::FETCH_ASSOC);

echo "Admin account created/updated successfully.\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
