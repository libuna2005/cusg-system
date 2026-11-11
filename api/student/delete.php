<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/uploads.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $studentId = trim((string) ($data['student_id'] ?? ''));
    $password = $data['password'] ?? '';

    if (!$studentId || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Student ID and password are required.']);
        exit;
    }

    // Verify password first
    $stmt = $pdo->prepare('SELECT password_hash FROM students WHERE student_id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Password is incorrect.']);
        exit;
    }

    // Perform deletion inside a transaction and explicitly remove dependent rows
    try {
        $pdo->beginTransaction();

        // delete attendance_logs and event_registrations explicitly (cascade should also handle it)
        $del1 = $pdo->prepare('DELETE FROM attendance_logs WHERE student_id = ?');
        $del1->execute([$studentId]);

        $del2 = $pdo->prepare('DELETE FROM event_registrations WHERE student_id = ?');
        $del2->execute([$studentId]);

        $del3 = $pdo->prepare('DELETE FROM students WHERE student_id = ?');
        $del3->execute([$studentId]);

        if ($del3->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Student not found or already deleted.']);
            exit;
        }

        $pdo->commit();

        // Account row is gone — also remove the generated profile photo and
        // QR ID card from disk so deleted accounts don't leave files behind
        // in assets/uploads/.
        deleteStudentUploadedFiles($studentId);

        echo json_encode(['success' => true, 'message' => 'Student account deleted successfully.']);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
