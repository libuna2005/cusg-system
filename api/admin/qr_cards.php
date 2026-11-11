<?php
/**
 * qr_cards.php (admin only, read-only)
 *
 * Feeds the "Regenerate all QR ID cards" tool in the admin Settings page:
 * one row per student with exactly what the card needs (name parts, course,
 * year, section, profile photo path). The card itself is drawn in the browser
 * (assets/js/qr-card.js) and saved through the existing api/student/qr.php.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

if (empty($_SESSION['cusg_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

/** Same self-healing used elsewhere, so this works on databases that never ran those migrations. */
function ensureStudentNameColumns(PDO $pdo): void {
    $wanted = [
        'last_name'   => "ALTER TABLE students ADD COLUMN last_name VARCHAR(100) NULL AFTER full_name",
        'first_name'  => "ALTER TABLE students ADD COLUMN first_name VARCHAR(100) NULL AFTER last_name",
        'middle_name' => "ALTER TABLE students ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name",
        'section'     => "ALTER TABLE students ADD COLUMN section VARCHAR(5) NULL AFTER year_level",
    ];
    foreach ($wanted as $col => $ddl) {
        if (!$pdo->query("SHOW COLUMNS FROM students LIKE '$col'")->fetch()) {
            $pdo->exec($ddl);
        }
    }
}

/** Older accounts only have full_name ("LAST, FIRST MIDDLE"); split it the same way the student page does. */
function splitFullName(string $fullName): array {
    $raw = trim($fullName);
    if ($raw === '') return ['', '', ''];
    if (strpos($raw, ',') !== false) {
        [$last, $rest] = array_map('trim', explode(',', $raw, 2));
        $words = preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return [$last, $words[0] ?? '', implode(' ', array_slice($words, 1))];
    }
    $words = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return [$words[0] ?? '', $words[1] ?? '', implode(' ', array_slice($words, 2))];
}

try {
    ensureStudentNameColumns($pdo);

    $stmt = $pdo->query('SELECT student_id, full_name, last_name, first_name, middle_name, course_program, year_level, section,
                                profile_picture_url, (qr_code_image IS NOT NULL AND qr_code_image <> \'\') AS has_qr
                         FROM students ORDER BY student_id ASC');
    $students = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $last = trim((string) $row['last_name']);
        $first = trim((string) $row['first_name']);
        $middle = trim((string) $row['middle_name']);
        if ($last === '' && $first === '') {
            [$last, $first, $middle] = splitFullName((string) $row['full_name']);
        }
        $students[] = [
            'student_id' => $row['student_id'],
            'last' => $last,
            'first' => $first,
            'middle' => $middle,
            'course' => $row['course_program'],
            'year' => $row['year_level'],
            'section' => (string) ($row['section'] ?? ''),
            'photo_url' => $row['profile_picture_url'] ?: null,
            'has_qr' => (bool) $row['has_qr'],
        ];
    }

    echo json_encode(['success' => true, 'count' => count($students), 'students' => $students]);
} catch (Throwable $e) {
    error_log('[cusg qr_cards] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load students.']);
}
