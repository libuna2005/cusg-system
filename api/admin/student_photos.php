<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

if (empty($_SESSION['cusg_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

// Photos are already resized to a 200px-longest-side JPEG at upload time
// (see api/lib/uploads.php), so each one is typically only a few KB — small
// enough to bundle many into one response instead of one request per
// student, which would burn through InfinityFree's daily hit limit fast.
//
// Still processed in PAGES rather than all-at-once: with thousands of
// students, doing every file read + base64 encode in a single PHP request
// risks hitting the execution time or memory limit on shared hosting,
// which kills the request mid-response and leaves the client with a
// truncated, unparseable JSON body ("Unexpected end of JSON input").
// Chunking this the same way batch scan uploads are chunked avoids that.
const PROFILE_UPLOAD_URL_PREFIX = '/cusg/assets/uploads/profiles/';
const PHOTO_PAGE_SIZE = 200; // students per request

try {
    $uploadsDir = realpath(__DIR__ . '/../../assets/uploads/profiles/');
    if ($uploadsDir === false) {
        echo json_encode(['success' => true, 'photos' => [], 'count' => 0, 'skipped' => 0, 'has_more' => false, 'next_offset' => 0]);
        exit;
    }

    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    // Only rows that actually have a non-empty profile_picture_url are worth
    // paging through — most students on the default placeholder never reach
    // the file-read step at all, keeping this fast for the common case.
    $totalStmt = $pdo->query("SELECT COUNT(*) AS c FROM students WHERE profile_picture_url IS NOT NULL AND profile_picture_url <> ''");
    $total = (int) $totalStmt->fetch(PDO::FETCH_ASSOC)['c'];

    $stmt = $pdo->prepare(
        "SELECT student_id, profile_picture_url FROM students
         WHERE profile_picture_url IS NOT NULL AND profile_picture_url <> ''
         ORDER BY student_id ASC
         LIMIT " . PHOTO_PAGE_SIZE . " OFFSET :offset"
    );
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $photos = [];
    $skipped = 0;

    foreach ($rows as $row) {
        $url = trim((string) $row['profile_picture_url']);

        // Only real uploaded files under the known profiles folder are worth
        // caching — the default placeholder logo or any other value already
        // has an equivalent local fallback icon on the client, so skip those.
        if ($url === '' || strpos($url, PROFILE_UPLOAD_URL_PREFIX) !== 0) {
            $skipped++;
            continue;
        }

        $filename = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        $filePath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;

        // Guard against path traversal and confirm the resolved path is still
        // actually inside the intended uploads folder before reading it.
        $resolvedFilePath = realpath($filePath);
        if ($resolvedFilePath === false || strpos($resolvedFilePath, $uploadsDir) !== 0) {
            $skipped++;
            continue;
        }

        $bytes = @file_get_contents($resolvedFilePath);
        if ($bytes === false) {
            $skipped++;
            continue;
        }

        $ext = strtolower(pathinfo($resolvedFilePath, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
        $photos[$row['student_id']] = 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    $nextOffset = $offset + count($rows);
    $hasMore = $nextOffset < $total;

    echo json_encode([
        'success' => true,
        'photos' => $photos,
        'count' => count($photos),
        'skipped' => $skipped,
        'total' => $total,
        'next_offset' => $nextOffset,
        'has_more' => $hasMore,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

