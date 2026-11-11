<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/common.php';

ensureSectionColumn($pdo);

/**
 * Self-healing schema, mirrored from api/student/attendance.php, so the
 * per-event breakdown query below keeps working even on databases where
 * this migration hasn't run yet.
 */
function ensureEventGroupsColumnsExistAdmin(PDO $pdo): void {
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

    // Audience targeting columns (mirrors api/student/attendance.php / api/admin/event_groups.php)
    // so the admin single-student search can filter events down to the same
    // audience a student would actually see on their own dashboard.
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
ensureEventGroupsColumnsExistAdmin($pdo);

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    if (isset($_GET['filter'])) {
        // Directory search: filter students by department/course/year/section.
        // Any of these left blank (or "ALL") is treated as "match everything"
        // for that field, so "all CCS" or "all BSIT 3C" both work with the
        // same endpoint.
        $department = strtoupper(trim($_GET['department'] ?? ''));
        $course = trim($_GET['course_program'] ?? '');
        $year = trim($_GET['year_level'] ?? '');
        $section = strtoupper(trim($_GET['section'] ?? ''));

        $departmentCourses = [
            'CCS' => ['BSIT', 'BSIS'],
            'COE' => ['BS CPE', 'BS ECE'],
            'CIT' => [
                'BIT Architectural Drafting Technology',
                'BIT Culinary Technology',
                'BIT Computer Technology',
                'BIT Electronic Technology',
                'BIT Electrical Technology',
                'BIT Automotive Technology',
                'BIT Mechanical Technology',
            ],
            'COED' => ['BTVTED Major in Electrical', 'BTVTED Major in Electronics'],
        ];

        $where = [];
        $params = [];

        if ($course !== '' && strtoupper($course) !== 'ALL') {
            $where[] = 'course_program = ?';
            $params[] = $course;
        } elseif ($department !== '' && $department !== 'ALL' && isset($departmentCourses[$department])) {
            $courseList = $departmentCourses[$department];
            $placeholders = implode(',', array_fill(0, count($courseList), '?'));
            $where[] = "course_program IN ($placeholders)";
            foreach ($courseList as $c) {
                $params[] = $c;
            }
        }

        if ($year !== '' && strtoupper($year) !== 'ALL') {
            $where[] = 'year_level = ?';
            $params[] = $year;
        }

        if ($section !== '' && $section !== 'ALL') {
            $where[] = 'section = ?';
            $params[] = $section;
        }

        $sql = 'SELECT student_id, full_name, course_program, year_level, section, institutional_email FROM students';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY course_program ASC, year_level ASC, section ASC, full_name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'count' => count($students), 'students' => $students]);
        exit;
    }

    if (isset($_GET['summary'])) {
        $stmt = $pdo->query('SELECT COUNT(*) AS total_students FROM students');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'total_students' => (int) ($row['total_students'] ?? 0)]);
        exit;
    }

    if (isset($_GET['list'])) {
        // Lightweight id+name list for autocomplete/typeahead fields (manual scan entry,
        // clearance search, etc.) — deliberately excludes photos and other heavy columns.
        $stmt = $pdo->query('SELECT student_id, full_name, course_program, year_level, section FROM students ORDER BY student_id ASC');
        echo json_encode(['success' => true, 'students' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $studentId = trim($_GET['student_id'] ?? '');
    if (!$studentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
        exit;
    }

    $studentStmt = $pdo->prepare('SELECT student_id, full_name, course_program, year_level, section, institutional_email, profile_picture_url FROM students WHERE student_id = ? LIMIT 1');
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        exit;
    }

    // Normalize profile picture URL: accept full data URIs or raw base64 stored in DB.
    $profile = trim((string) ($student['profile_picture_url'] ?? ''));
    if ($profile !== '') {
        // If it's raw base64 without data: prefix, assume PNG.
        if (!preg_match('/^data:\w+\/[\w+\-\.]+;base64,/', $profile) && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $profile)) {
            $profile = 'data:image/png;base64,' . preg_replace('/\s+/', '', $profile);
        }
    } else {
        // Fallback to a public asset path so the UI shows a placeholder avatar.
        // Adjust this path if your app is served from a different base URL.
        $profile = '/cusg/assets/cusg_logo.jpg';
    }
    $student['profile_picture_url'] = $profile;

    // Full per-event breakdown (mirrors api/student/attendance.php) so the admin
    // dashboard can render the same row/column "Live Attendance Breakdown" grid
    // that students see on their own dashboard, instead of just totals.
    $eventsStmt = $pdo->prepare('SELECT e.id, e.title, e.event_date, e.is_flagged, e.group_id, e.sub_label, e.target_year_levels, e.target_courses, e.target_sections, g.title AS group_title
                                  FROM events e
                                  LEFT JOIN event_groups g ON g.id = e.group_id
                                  ORDER BY e.event_date ASC, e.id ASC');
    $eventsStmt->execute();
    $allEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Only keep events whose audience targeting actually includes this
    // student (course/year/section) — same filter students.php's own
    // dashboard endpoint (api/student/attendance.php) applies, so a CCS-only
    // event no longer shows up when searching an Engineering student here.
    // A null/empty target list still means "visible to everyone".
    $events = array_values(array_filter($allEvents, function ($ev) use ($student) {
        return studentMatchesTarget($ev['target_year_levels'], $student['year_level'])
            && studentMatchesTarget($ev['target_courses'], $student['course_program'])
            && studentMatchesTarget($ev['target_sections'], (string) ($student['section'] ?? ''));
    }));

    $eligibleEventIds = array_map(fn($ev) => (int) $ev['id'], $events);

    // Totals/attended counts are computed from the same filtered event list
    // above (not the whole events table) so the "X / Y" summary numbers
    // shown next to the breakdown always match what's actually rendered.
    $totalFlags = 0;
    $totalEvents = 0;
    foreach ($events as $ev) {
        if ((int) $ev['is_flagged'] === 1) {
            $totalFlags++;
        } else {
            $totalEvents++;
        }
    }

    $flagAttended = 0;
    $eventAttended = 0;
    $attendanceStatusMap = [];
    if ($eligibleEventIds) {
        $placeholders = implode(',', array_fill(0, count($eligibleEventIds), '?'));
        $logStmt = $pdo->prepare("SELECT attendance_logs.event_id, attendance_logs.status, events.is_flagged
                                   FROM attendance_logs
                                   JOIN events ON attendance_logs.event_id = events.id
                                   WHERE attendance_logs.student_id = ? AND attendance_logs.event_id IN ($placeholders)");
        $logStmt->execute(array_merge([$studentId], $eligibleEventIds));
        $logRows = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($logRows as $row) {
            $attendanceStatusMap[(string) $row['event_id']] = $row['status'] ?: 'present';
            if ((int) $row['is_flagged'] === 1) {
                $flagAttended++;
            } else {
                $eventAttended++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'student' => $student,
        'attendance' => [
            'total_flags' => $totalFlags,
            'total_events' => $totalEvents,
            'flag_attended' => $flagAttended,
            'event_attended' => $eventAttended,
        ],
        'events' => $events,
        'attendance_status' => $attendanceStatusMap
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}