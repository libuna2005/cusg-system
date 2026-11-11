<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/uploads.php';

function ensureQrColumn(PDO $pdo): void {
    $stmt = $pdo->prepare('SHOW COLUMNS FROM students LIKE "qr_code_image"');
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE students ADD COLUMN qr_code_image MEDIUMTEXT NULL AFTER profile_picture_url');
    }
}

try {
    ensureQrColumn($pdo);

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $studentId = trim($_GET['student_id'] ?? '');
        if (!$studentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT qr_code_image FROM students WHERE student_id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        echo json_encode(['success' => true, 'qr_code_image' => $row['qr_code_image']]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $studentId = trim($input['student_id'] ?? '');
        $qrCodeImage = trim($input['qr_code_image'] ?? '');

        if (!$studentId || !$qrCodeImage) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Student ID and QR image data are required.']);
            exit;
        }

        // The ID card is composited on a <canvas> client-side, so it only
        // ever arrives as a base64 data URL — but it still gets saved as a
        // real file under assets/uploads/qrcodes/ instead of storing that
        // base64 text in the database.
        $saveResult = saveQrCodeImage($qrCodeImage, $studentId);
        if (!$saveResult['success']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $saveResult['message']]);
            exit;
        }
        $qrCodeImage = $saveResult['path'];

        $stmt = $pdo->prepare('UPDATE students SET qr_code_image = ? WHERE student_id = ?');
        $stmt->execute([$qrCodeImage, $studentId]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Student not found or no changes were made.']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'QR image saved successfully.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
