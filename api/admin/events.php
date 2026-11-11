<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/common.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Self-healing schema check so this endpoint doesn't error out if the
 * event_groups feature hasn't been touched yet on this database.
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

    $yearCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'school_year'")->fetch();
    if (!$yearCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN school_year VARCHAR(50) NULL AFTER event_date");
    }

    // Audience targeting: comma-separated lists. NULL/empty = visible to everyone.
    $targetYearCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'target_year_levels'")->fetch();
    if (!$targetYearCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN target_year_levels VARCHAR(255) NULL AFTER is_flagged");
    }
    $targetCourseCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'target_courses'")->fetch();
    if (!$targetCourseCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN target_courses VARCHAR(500) NULL AFTER target_year_levels");
    }
    $targetSectionCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'target_sections'")->fetch();
    if (!$targetSectionCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN target_sections VARCHAR(255) NULL AFTER target_courses");
    }
}

try {
    ensureEventGroupsColumnsExist($pdo);

    if ($method === 'GET') {
        $stmt = $pdo->query('SELECT e.id, e.title, e.event_date, e.school_year, e.is_flagged, e.group_id, e.sub_label, e.target_year_levels, e.target_courses, e.target_sections, g.title AS group_title
                              FROM events e
                              LEFT JOIN event_groups g ON g.id = e.group_id
                              ORDER BY e.event_date ASC, e.id ASC');
        echo json_encode(['success' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($method === 'POST') {
        $input = readJsonInput();
        $title = strtoupper(trim($input['title'] ?? ''));
        $eventDate = trim($input['event_date'] ?? '');
        $schoolYear = trim($input['school_year'] ?? '');
        $isFlagged = isset($input['is_flagged']) ? (int) (bool) $input['is_flagged'] : 0;
        $targetYearLevels = normalizeTargetList($input['target_year_levels'] ?? null);
        $targetCourses = normalizeTargetList($input['target_courses'] ?? null);
        $targetSections = normalizeTargetList($input['target_sections'] ?? null);

        if (!$title || !$eventDate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Title and event date are required.']);
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO events (title, event_date, school_year, is_flagged, target_year_levels, target_courses, target_sections, status) VALUES (?, ?, ?, ?, ?, ?, ?, "Scheduled")');
        $stmt->execute([$title, $eventDate, $schoolYear !== '' ? $schoolYear : null, $isFlagged, $targetYearLevels, $targetCourses, $targetSections]);

        echo json_encode([
            'success' => true,
            'message' => 'Event created successfully.',
            'event' => [
                'id' => (int) $pdo->lastInsertId(),
                'title' => $title,
                'event_date' => $eventDate,
                'school_year' => $schoolYear,
                'is_flagged' => $isFlagged,
                'target_year_levels' => $targetYearLevels,
                'target_courses' => $targetCourses,
                'target_sections' => $targetSections,
            ],
        ]);
        exit;
    }

    if ($method === 'PUT') {
        $input = readJsonInput();
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $title = strtoupper(trim($input['title'] ?? ''));
        $eventDate = trim($input['event_date'] ?? '');
        $schoolYear = trim($input['school_year'] ?? '');
        $isFlagged = isset($input['is_flagged']) ? (int) (bool) $input['is_flagged'] : 0;
        $targetYearLevels = normalizeTargetList($input['target_year_levels'] ?? null);
        $targetCourses = normalizeTargetList($input['target_courses'] ?? null);
        $targetSections = normalizeTargetList($input['target_sections'] ?? null);

        if (!$id || !$title || !$eventDate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid event id, title, and event date are required.']);
            exit;
        }

        // If this row is a sub-session of an event group (sub_label is set),
        // keep sub_label in sync with the edited title so the student view
        // (which prefers sub_label) reflects the rename too.
        $stmt = $pdo->prepare('UPDATE events SET title = ?, event_date = ?, school_year = ?, is_flagged = ?, target_year_levels = ?, target_courses = ?, target_sections = ?, sub_label = CASE WHEN sub_label IS NOT NULL THEN ? ELSE sub_label END WHERE id = ?');
        $stmt->execute([$title, $eventDate, $schoolYear !== '' ? $schoolYear : null, $isFlagged, $targetYearLevels, $targetCourses, $targetSections, $title, $id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event not found, or nothing changed.']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Event updated successfully.']);
        exit;
    }

    if ($method === 'DELETE') {
        $input = readJsonInput();
        $id = isset($input['id']) ? (int) $input['id'] : 0;

        if (!$id) {
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        }

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event id is required.']);
            exit;
        }

        $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event not found.']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Event deleted successfully.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}