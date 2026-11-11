<?php
/**
 * resize_existing_profile_photos.php
 
 https://chmsucusgalijis.site.je/cusg/api/lib/resize_existing_profile_photos.php?token=resize2026
 *
 * One-off maintenance script. Resizes every profile picture already sitting
 * in assets/uploads/profiles/ down to the same size/quality that new
 * uploads now get automatically (see uploads.php -> resizeAndCompressImage).
 *
 * Run this ONCE after deploying the updated uploads.php, to shrink photos
 * that were uploaded before that change. New uploads don't need this —
 * they're resized automatically at upload time.
 *
 * HOW TO RUN
 * ----------
 * Command line (if your host gives you SSH/terminal access):
 *   php resize_existing_profile_photos.php
 *
 * Browser (InfinityFree-style shared hosting, no SSH):
 *   1. Upload this file into api/lib/ alongside uploads.php
 *   2. Visit https://yourdomain.com/cusg/api/lib/resize_existing_profile_photos.php
 *      in your browser once, while logged in as admin isn't required — but
 *      DELETE this file from the server immediately after running it, since
 *      anyone who finds the URL could re-run it (harmless, but pointless
 *      exposure).
 *
 * Safe to run more than once — files already at/under the target size are
 * left alone (skipped, not re-compressed again and again).
 */

require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/../config.php'; // gives us $pdo, same connection the app uses

// Basic guard so this can't be triggered by a random bot crawling the URL
// on a public host without you knowing. Change this token before uploading,
// then visit ...resize_existing_profile_photos.php?token=YOUR_TOKEN
const RUN_TOKEN = 'resize2026';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['token'] ?? '') !== RUN_TOKEN) {
        http_response_code(403);
        echo "Forbidden. Add ?token=YOUR_TOKEN (set in this file) to the URL to run.\n";
        exit;
    }
}

function out(string $line): void {
    echo $line . (php_sapi_name() === 'cli' ? "\n" : "\n");
    if (php_sapi_name() !== 'cli' && ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

$files = glob(PROFILE_UPLOAD_DIR . 'profile_*');
if ($files === false || count($files) === 0) {
    out('No profile photos found in ' . PROFILE_UPLOAD_DIR);
    exit;
}

out('Found ' . count($files) . ' profile photo(s). Starting...');
out(str_repeat('-', 60));

$totalBefore = 0;
$totalAfter = 0;
$resizedCount = 0;
$skippedCount = 0;
$failedCount = 0;

foreach ($files as $path) {
    $originalSize = filesize($path);
    $totalBefore += $originalSize;
    $basename = basename($path);

    $resized = resizeAndCompressImage($path);

    if ($resized === null) {
        out("SKIP  (couldn't process, e.g. not a valid image): $basename");
        $totalAfter += $originalSize;
        $skippedCount++;
        continue;
    }

    $newSize = strlen($resized);

    // If somehow the "resized" version is bigger (tiny/already-compressed
    // originals), just keep the original rather than making things worse.
    if ($newSize >= $originalSize) {
        out('SKIP  (already small enough, ' . round($originalSize / 1024, 1) . " KB): $basename");
        $totalAfter += $originalSize;
        $skippedCount++;
        continue;
    }

    // New file is always .jpg regardless of original extension, to match
    // what uploads.php now produces for new uploads.
    $newPath = preg_replace('/\.[^.]+$/', '.jpg', $path);

    if (file_put_contents($newPath, $resized) === false) {
        out("FAIL  (couldn't write resized file): $basename");
        $totalAfter += $originalSize;
        $failedCount++;
        continue;
    }

    // If the extension changed (e.g. .png -> .jpg), the filename on disk no
    // longer matches what's stored in profile_picture_url. Update the DB
    // row so the app keeps pointing at a file that actually exists, then
    // remove the old file so it doesn't linger as an orphan.
    if ($newPath !== $path) {
        $oldUrl = PROFILE_UPLOAD_URL . basename($path);
        $newUrl = PROFILE_UPLOAD_URL . basename($newPath);
        try {
            $stmt = $pdo->prepare('UPDATE students SET profile_picture_url = ? WHERE profile_picture_url = ?');
            $stmt->execute([$newUrl, $oldUrl]);
        } catch (Throwable $e) {
            out("WARN  DB update failed for $basename: " . $e->getMessage());
        }
        @unlink($path);
    }

    $savedPct = round((1 - $newSize / $originalSize) * 100);
    out(sprintf(
        'OK    %s: %s KB -> %s KB (-%d%%)',
        basename($newPath),
        round($originalSize / 1024, 1),
        round($newSize / 1024, 1),
        $savedPct
    ));

    $totalAfter += $newSize;
    $resizedCount++;
}

out(str_repeat('-', 60));
out("Resized: $resizedCount   Skipped: $skippedCount   Failed: $failedCount");
out('Total size: ' . round($totalBefore / 1024 / 1024, 2) . ' MB -> ' . round($totalAfter / 1024 / 1024, 2) . ' MB');