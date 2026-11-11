<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/common.php';

$studentId = trim($_GET['student_id'] ?? '');
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
    exit;
}

/**
 * Self-healing schema check, mirrored from api/admin/events.php, so this
 * endpoint keeps working even if the event_groups feature hasn't been
 * touched yet on this particular database.
 */
function ensureEventGroupsColumnsExist(PDO $pdo): void {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'event_groups'")->fetch();
    if (!$tableExists) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            event_date DATE NOT NULL,
            is_flagged TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(50) NOT NULL DEFAULT 'Scheduled',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $groupCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'group_id'")->fetch();
    if (!$groupCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER id");
        $pdo->exec("ALTER TABLE events ADD KEY idx_events_group (group_id)");
    }

    $labelCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'sub_label'")->fetch();
    if (!$labelCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN sub_label VARCHAR(100) NULL AFTER title");
    }

    $statusCol = $pdo->query("SHOW COLUMNS FROM attendance_logs LIKE 'status'")->fetch();
    if (!$statusCol) {
        $pdo->exec("ALTER TABLE attendance_logs ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'present' AFTER source");
    }

    $eventTargetYearCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'target_year_levels'")->fetch();
    if (!$eventTargetYearCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN target_year_levels VARCHAR(255) NULL AFTER is_flagged");
    }
    $eventTargetCourseCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'target_courses'")->fetch();
    if (!$eventTargetCourseCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN target_courses VARCHAR(500) NULL AFTER target_year_levels");
    }
    $eventTargetSectionCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'target_sections'")->fetch();
    if (!$eventTargetSectionCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN target_sections VARCHAR(255) NULL AFTER target_courses");
    }
}

function getGlobalAttendanceLock(PDO $pdo): bool {
    ensurePortalSettingsTable($pdo);
    $row = $pdo->query("SELECT attendance_locked FROM portal_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    return $row ? (bool) $row['attendance_locked'] : false;
}

try {
    ensureEventGroupsColumnsExist($pdo);

    // Needed to filter events down to this student's audience (year level / course).
    $studentRowStmt = $pdo->prepare('SELECT course_program, year_level, section FROM students WHERE student_id = ? LIMIT 1');
    $studentRowStmt->execute([$studentId]);
    $studentRow = $studentRowStmt->fetch(PDO::FETCH_ASSOC);

    if (!$studentRow) {
        echo json_encode(['success' => false, 'message' => 'Student record not found.']);
        exit;
    }

    $eventsStmt = $pdo->prepare('SELECT e.id, e.title, e.event_date, e.is_flagged, e.group_id, e.sub_label, e.target_year_levels, e.target_courses, e.target_sections, g.title AS group_title
                                  FROM events e
                                  LEFT JOIN event_groups g ON g.id = e.group_id
                                  ORDER BY e.event_date ASC, e.id ASC');
    $eventsStmt->execute();
    $allEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $events = array_values(array_filter($allEvents, function ($ev) use ($studentRow) {
        return studentMatchesTarget($ev['target_year_levels'], $studentRow['year_level'])
            && studentMatchesTarget($ev['target_courses'], $studentRow['course_program'])
            && studentMatchesTarget($ev['target_sections'], (string) ($studentRow['section'] ?? ''));
    }));

    $attendanceStmt = $pdo->prepare('SELECT event_id, status FROM attendance_logs WHERE student_id = ?');
    $attendanceStmt->execute([$studentId]);
    $attendanceRows = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);

    $attendanceMap = [];
    foreach ($attendanceRows as $row) {
        $attendanceMap[(string) $row['event_id']] = $row['status'] ?: 'present';
    }

    echo json_encode([
        'success' => true,
        'events' => $events,
        'attendance' => array_keys($attendanceMap), // kept for backward compatibility
        'attendance_status' => $attendanceMap,
        'attendance_locked' => getGlobalAttendanceLock($pdo)
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}