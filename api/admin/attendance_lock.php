<?php
// ============================================================
//  attendance_lock.php
//  File path: api/admin/attendance_lock.php
//  ONE global on/off switch (not per-event) that hides the entire
//  student attendance dashboard while Batch Mode scans are still
//  sitting in an officer's phone, un-uploaded — otherwise students
//  refresh mid-event, see "Absent" that isn't true yet, and
//  complain that the system is broken.
//
//  GET  -> { success, locked: true|false }
//  POST { locked: 1|0 } -> locks/unlocks the whole dashboard
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/common.php';

try {
    ensurePortalSettingsTable($pdo);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $row = $pdo->query("SELECT attendance_locked FROM portal_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'locked' => $row ? (bool) $row['attendance_locked'] : false]);
        exit;
    }

    if ($method === 'POST') {
        $input = readJsonInput();

        if (!isset($input['locked'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'locked (0 or 1) is required.']);
            exit;
        }
        $locked = (int) (bool) $input['locked'];

        $stmt = $pdo->prepare("UPDATE portal_settings SET attendance_locked = ? WHERE id = 1");
        $stmt->execute([$locked]);

        echo json_encode([
            'success' => true,
            'message' => $locked ? 'Attendance dashboard locked for all students.' : 'Attendance dashboard unlocked for all students.',
            'locked' => (bool) $locked,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}