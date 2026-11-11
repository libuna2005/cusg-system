<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $stmt = $pdo->query('SELECT id, username, email, full_name, role, created_at FROM admin_users ORDER BY created_at DESC');
        echo json_encode(['success' => true, 'admins' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = isset($input['id']) ? (int) $input['id'] : 0;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Admin ID is required.']);
            exit;
        }

        $stmt = $pdo->prepare('DELETE FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Admin not found.']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Admin account deleted successfully.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
