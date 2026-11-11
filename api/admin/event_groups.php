<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/common.php';

if (empty($_SESSION['cusg_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

/**
 * Self-healing schema: creates the event_groups table and adds the
 * group_id / sub_label columns to events if they don't exist yet.
 * Mirrors the ensureQrColumn() pattern already used in api/student/qr.php,
 * so nothing manual has to be run on InfinityFree for this to work.
 */
function ensureEventGroupsSchema(PDO $pdo): void {
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

    $groupCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'group_id'")->fetch();
    if (!$groupCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER id");
        $pdo->exec("ALTER TABLE events ADD KEY idx_events_group (group_id)");
        try {
            $pdo->exec("ALTER TABLE events ADD CONSTRAINT fk_events_group FOREIGN KEY (group_id) REFERENCES event_groups(id) ON DELETE CASCADE ON UPDATE CASCADE");
        } catch (Throwable $e) {
            // Some shared-hosting setups restrict adding FKs after the fact; the app
            // deletes sub-sessions explicitly in the DELETE handler below either way.
        }
    }

    $labelCol = $pdo->query("SHOW COLUMNS FROM events LIKE 'sub_label'")->fetch();
    if (!$labelCol) {
        $pdo->exec("ALTER TABLE events ADD COLUMN sub_label VARCHAR(100) NULL AFTER title");
    }

    // Audience targeting: comma-separated lists. NULL/empty = visible to everyone.
    // Stored on event_groups (the source of truth for a group) and mirrored onto
    // every child session row in `events` so the student-facing filter (which
    // only ever reads from `events`) doesn't need to join back to the group.
    $groupTargetYearCol = $pdo->query("SHOW COLUMNS FROM event_groups LIKE 'target_year_levels'")->fetch();
    if (!$groupTargetYearCol) {
        $pdo->exec("ALTER TABLE event_groups ADD COLUMN target_year_levels VARCHAR(255) NULL AFTER is_flagged");
    }
    $groupTargetCourseCol = $pdo->query("SHOW COLUMNS FROM event_groups LIKE 'target_courses'")->fetch();
    if (!$groupTargetCourseCol) {
        $pdo->exec("ALTER TABLE event_groups ADD COLUMN target_courses VARCHAR(500) NULL AFTER target_year_levels");
    }
    $groupTargetSectionCol = $pdo->query("SHOW COLUMNS FROM event_groups LIKE 'target_sections'")->fetch();
    if (!$groupTargetSectionCol) {
        $pdo->exec("ALTER TABLE event_groups ADD COLUMN target_sections VARCHAR(255) NULL AFTER target_courses");
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

try {
    ensureEventGroupsSchema($pdo);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $groupsStmt = $pdo->query('SELECT id, title, event_date, is_flagged, status, target_year_levels, target_courses, target_sections FROM event_groups ORDER BY event_date DESC, id DESC');
        $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

        $sessionsStmt = $pdo->query('SELECT id, group_id, title, sub_label, event_date, is_flagged, target_year_levels, target_courses, target_sections FROM events WHERE group_id IS NOT NULL ORDER BY event_date ASC, id ASC');
        $sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

        $byGroup = [];
        foreach ($sessions as $s) {
            $byGroup[(string) $s['group_id']][] = $s;
        }
        foreach ($groups as &$g) {
            $g['sessions'] = $byGroup[(string) $g['id']] ?? [];
        }
        unset($g);

        $standaloneStmt = $pdo->query('SELECT id, title, event_date, is_flagged, target_year_levels, target_courses, target_sections FROM events WHERE group_id IS NULL ORDER BY event_date DESC, id DESC');
        $standalone = $standaloneStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'groups' => $groups, 'standalone_events' => $standalone]);
        exit;
    }

    if ($method === 'POST') {
        $input = readJsonInput();
        $action = $input['action'] ?? 'create_group';

        if ($action === 'add_session') {
            $groupId = isset($input['group_id']) ? (int) $input['group_id'] : 0;
            $subLabel = strtoupper(trim((string) ($input['sub_label'] ?? '')));
            $eventDate = trim((string) ($input['event_date'] ?? ''));

            if (!$groupId || !$subLabel) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'A group and a session label are required.']);
                exit;
            }

            $groupStmt = $pdo->prepare('SELECT id, event_date, is_flagged, target_year_levels, target_courses, target_sections FROM event_groups WHERE id = ? LIMIT 1');
            $groupStmt->execute([$groupId]);
            $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Event group not found.']);
                exit;
            }

            $finalDate = $eventDate !== '' ? $eventDate : $group['event_date'];

            $insertStmt = $pdo->prepare('INSERT INTO events (group_id, title, sub_label, event_date, is_flagged, target_year_levels, target_courses, target_sections, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Scheduled")');
            $insertStmt->execute([$groupId, $subLabel, $subLabel, $finalDate, (int) $group['is_flagged'], $group['target_year_levels'], $group['target_courses'], $group['target_sections']]);

            echo json_encode([
                'success' => true,
                'message' => 'Session added.',
                'session' => [
                    'id' => (int) $pdo->lastInsertId(),
                    'group_id' => $groupId,
                    'title' => $subLabel,
                    'sub_label' => $subLabel,
                    'event_date' => $finalDate,
                    'is_flagged' => (int) $group['is_flagged'],
                    'target_year_levels' => $group['target_year_levels'],
                    'target_courses' => $group['target_courses'],
                    'target_sections' => $group['target_sections'],
                ],
            ]);
            exit;
        }

        // Default action: create a brand new event group along with its initial sessions.
        $title = strtoupper(trim((string) ($input['title'] ?? '')));
        $eventDate = trim((string) ($input['event_date'] ?? ''));
        $isFlagged = isset($input['is_flagged']) ? (int) (bool) $input['is_flagged'] : 0;
        $targetYearLevels = normalizeTargetList($input['target_year_levels'] ?? null);
        $targetCourses = normalizeTargetList($input['target_courses'] ?? null);
        $targetSections = normalizeTargetList($input['target_sections'] ?? null);
        $rawSessions = is_array($input['sessions'] ?? null) ? $input['sessions'] : [];
        $sessions = array_values(array_filter(array_map(fn($s) => strtoupper(trim($s)), $rawSessions), fn($s) => $s !== ''));

        if (!$title || !$eventDate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Group title and date are required.']);
            exit;
        }
        if (count($sessions) < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please add at least one session (e.g. AM Login).']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $groupStmt = $pdo->prepare('INSERT INTO event_groups (title, event_date, is_flagged, target_year_levels, target_courses, target_sections, status) VALUES (?, ?, ?, ?, ?, ?, "Scheduled")');
            $groupStmt->execute([$title, $eventDate, $isFlagged, $targetYearLevels, $targetCourses, $targetSections]);
            $groupId = (int) $pdo->lastInsertId();

            $insertStmt = $pdo->prepare('INSERT INTO events (group_id, title, sub_label, event_date, is_flagged, target_year_levels, target_courses, target_sections, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Scheduled")');
            foreach ($sessions as $label) {
                $insertStmt->execute([$groupId, $label, $label, $eventDate, $isFlagged, $targetYearLevels, $targetCourses, $targetSections]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Event group created with ' . count($sessions) . ' session(s).',
            'group_id' => $groupId,
        ]);
        exit;
    }

    if ($method === 'PUT') {
        $input = readJsonInput();
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $title = strtoupper(trim((string) ($input['title'] ?? '')));
        $eventDate = trim((string) ($input['event_date'] ?? ''));
        $isFlagged = isset($input['is_flagged']) ? (int) (bool) $input['is_flagged'] : 0;
        $targetYearLevels = normalizeTargetList($input['target_year_levels'] ?? null);
        $targetCourses = normalizeTargetList($input['target_courses'] ?? null);
        $targetSections = normalizeTargetList($input['target_sections'] ?? null);

        if (!$id || !$title || !$eventDate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid group id, title, and date are required.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE event_groups SET title = ?, event_date = ?, is_flagged = ?, target_year_levels = ?, target_courses = ?, target_sections = ? WHERE id = ?');
            $stmt->execute([$title, $eventDate, $isFlagged, $targetYearLevels, $targetCourses, $targetSections, $id]);
            $rowsChanged = $stmt->rowCount();

            // Keep every child session's targeting in sync with the group so the
            // student-facing filter (which reads only from `events`) stays correct.
            $pdo->prepare('UPDATE events SET target_year_levels = ?, target_courses = ?, target_sections = ? WHERE group_id = ?')
                ->execute([$targetYearLevels, $targetCourses, $targetSections, $id]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($rowsChanged === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event group not found or nothing changed.']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Event group updated successfully.']);
        exit;
    }

    if ($method === 'DELETE') {
        $input = readJsonInput();
        $id = isset($input['id']) ? (int) $input['id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event group id is required.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Delete sub-sessions explicitly rather than relying solely on the FK cascade,
            // since some shared hosts silently ignore the ADD CONSTRAINT above.
            $pdo->prepare('DELETE FROM events WHERE group_id = ?')->execute([$id]);
            $stmt = $pdo->prepare('DELETE FROM event_groups WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event group not found.']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Event group and all its sessions were deleted.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}