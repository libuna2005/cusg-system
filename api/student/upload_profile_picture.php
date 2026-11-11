<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/uploads.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Same silent-empty-POST issue as register.php — catch it here too.
if (empty($_POST) && empty($_FILES) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Your photo is too large for this server\'s upload limit (post_max_size in php.ini). Try a smaller image, or ask whoever manages the server to raise post_max_size and upload_max_filesize.'
    ]);
    exit;
}

$studentId = trim($_POST['student_id'] ?? '');
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
    exit;
}

if (empty($_FILES['profile_picture'])) {
    echo json_encode(['success' => false, 'message' => 'Please choose an image.']);
    exit;
}

$result = saveUploadedProfilePhoto($_FILES['profile_picture'], $studentId);
if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE students SET profile_picture_url = ?, updated_at = NOW() WHERE student_id = ?');
    $stmt->execute([$result['path'], $studentId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Student profile not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Profile picture updated successfully.', 'path' => $result['path']]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
