<?php
/**
 * uploads.php
 *
 * Same validate -> move_uploaded_file -> save-path pattern used in the
 * attendance-portal app (AccountController::updateProfilePicture,
 * AttendanceController::uploadExcuse). Real files on disk under
 * assets/uploads/, short paths stored in the database — not raw base64
 * stuffed into a column.
 */

define('PROFILE_UPLOAD_DIR', __DIR__ . '/../../assets/uploads/profiles/');
define('PROFILE_UPLOAD_URL', '/cusg/assets/uploads/profiles/');

define('QR_UPLOAD_DIR', __DIR__ . '/../../assets/uploads/qrcodes/');
define('QR_UPLOAD_URL', '/cusg/assets/uploads/qrcodes/');

const UPLOAD_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const UPLOAD_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

// Profile pictures are shown as small avatars/thumbnails, so there's no need
// to keep the full-resolution phone-camera photo a student uploads (often
// several MB and thousands of pixels wide). Cap the longest side and
// re-encode as JPEG so every stored photo is small and fast to load.
const PROFILE_PHOTO_MAX_DIMENSION = 200; // px, longest side
const PROFILE_PHOTO_JPEG_QUALITY = 30;   // 0-100

/**
 * Resizes an image (down only, never upscales) so its longest side is at
 * most PROFILE_PHOTO_MAX_DIMENSION, and re-encodes it as JPEG. Requires the
 * GD extension. Returns the encoded JPEG binary, or null on failure (e.g.
 * GD missing) so the caller can fall back to saving the original file.
 */
function resizeAndCompressImage(string $sourcePath): ?string {
    if (!function_exists('imagecreatefromstring')) {
        return null; // GD not available on this server
    }

    $raw = @file_get_contents($sourcePath);
    if ($raw === false) {
        return null;
    }

    $srcImage = @imagecreatefromstring($raw);
    if ($srcImage === false) {
        return null;
    }

    // Respect EXIF orientation for JPEGs so photos taken on phones don't end
    // up sideways once the orientation tag is stripped during re-encoding.
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        $orientation = $exif['Orientation'] ?? 1;
        switch ($orientation) {
            case 3:
                $srcImage = imagerotate($srcImage, 180, 0);
                break;
            case 6:
                $srcImage = imagerotate($srcImage, -90, 0);
                break;
            case 8:
                $srcImage = imagerotate($srcImage, 90, 0);
                break;
        }
    }

    $width = imagesx($srcImage);
    $height = imagesy($srcImage);
    $longestSide = max($width, $height);

    if ($longestSide > PROFILE_PHOTO_MAX_DIMENSION) {
        $scale = PROFILE_PHOTO_MAX_DIMENSION / $longestSide;
        $newWidth = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    $dstImage = imagecreatetruecolor($newWidth, $newHeight);
    // Flatten transparency (PNG/WEBP) onto white before JPEG conversion,
    // since JPEG has no alpha channel.
    $white = imagecolorallocate($dstImage, 255, 255, 255);
    imagefill($dstImage, 0, 0, $white);
    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    ob_start();
    imagejpeg($dstImage, null, PROFILE_PHOTO_JPEG_QUALITY);
    $encoded = ob_get_clean();

    imagedestroy($srcImage);
    imagedestroy($dstImage);

    return $encoded !== false ? $encoded : null;
}

/**
 * Handles a real multipart file upload (a $_FILES[...] entry) for a
 * student's profile picture. Deletes any previously saved photo for that
 * student first so old files don't pile up under different names.
 *
 * @param array  $file      One entry from $_FILES, e.g. $_FILES['profile_picture'].
 * @param string $studentId Used to name the file and to clean up old ones.
 * @return array{success:bool, path?:string, message?:string}
 */
function saveUploadedProfilePhoto(array $file, string $studentId): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed. Please try again.'];
    }

    if (!in_array($file['type'], UPLOAD_ALLOWED_TYPES, true)) {
        return ['success' => false, 'message' => 'Only JPG, PNG, or WEBP images are allowed.'];
    }

    if (($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
        return ['success' => false, 'message' => 'Image is too large (5MB max).'];
    }

    // Confirm the uploaded bytes are actually a valid image, not just a file
    // with a spoofed MIME type/extension.
    if (@getimagesize($file['tmp_name']) === false) {
        return ['success' => false, 'message' => 'That file is not a valid image.'];
    }

    $safeId = preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentId);
    if ($safeId === '') {
        return ['success' => false, 'message' => 'Invalid student ID.'];
    }

    if (!is_dir(PROFILE_UPLOAD_DIR)) {
        @mkdir(PROFILE_UPLOAD_DIR, 0755, true);
    }

    // Clean up any previously saved photo for this student (any extension,
    // any old timestamp) before saving the new one.
    foreach (glob(PROFILE_UPLOAD_DIR . 'profile_' . $safeId . '_*') as $oldFile) {
        @unlink($oldFile);
    }

    // Resize + re-encode as JPEG so the stored file is small and fast to
    // load, regardless of how big the original upload was. Every resized
    // photo is saved as .jpg for consistency; if GD isn't available on the
    // server, fall back to saving the original upload untouched.
    $resized = resizeAndCompressImage($file['tmp_name']);
    $ext = $resized !== null ? 'jpg' : (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg');
    $filename = 'profile_' . $safeId . '_' . time() . '.' . $ext;
    $destination = PROFILE_UPLOAD_DIR . $filename;

    if ($resized !== null) {
        if (file_put_contents($destination, $resized) === false) {
            return ['success' => false, 'message' => 'Failed to save file.'];
        }
    } elseif (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'message' => 'Failed to save file.'];
    }

    return ['success' => true, 'path' => PROFILE_UPLOAD_URL . $filename];
}

/**
 * The QR ID card is composited client-side on a <canvas> (photo + QR code +
 * text baked into one image), so there's no native $_FILES entry for it —
 * the browser can only hand it over as a base64 data URL. This saves that
 * data URL to a real file under assets/uploads/qrcodes/ instead of writing
 * the base64 text straight into the qr_code_image column.
 *
 * @return array{success:bool, path?:string, message?:string}
 */
function saveQrCodeImage(string $dataUrl, string $studentId): array {
    if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,/i', $dataUrl, $m)) {
        return ['success' => false, 'message' => 'Invalid image data.'];
    }

    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }

    $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($binary === false || strlen($binary) === 0 || strlen($binary) > UPLOAD_MAX_BYTES) {
        return ['success' => false, 'message' => 'Invalid or oversized image data.'];
    }

    if (@getimagesizefromstring($binary) === false) {
        return ['success' => false, 'message' => 'That file is not a valid image.'];
    }

    $safeId = preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentId);
    if ($safeId === '') {
        return ['success' => false, 'message' => 'Invalid student ID.'];
    }

    if (!is_dir(QR_UPLOAD_DIR)) {
        @mkdir(QR_UPLOAD_DIR, 0755, true);
    }

    // One ID card per student — always the same filename, just overwritten,
    // so old versions don't accumulate.
    $filename = 'qrid_' . $safeId . '.' . $ext;
    $destination = QR_UPLOAD_DIR . $filename;

    if (file_put_contents($destination, $binary) === false) {
        return ['success' => false, 'message' => 'Failed to save file.'];
    }

    // Cache-bust since the filename is reused on every re-generation.
    return ['success' => true, 'path' => QR_UPLOAD_URL . $filename . '?v=' . time()];
}

/**
 * Removes a student's generated profile photo(s) and QR ID card from disk.
 * Called when an account is deleted so orphaned files don't sit around
 * taking up space in assets/uploads/ forever.
 *
 * Matches by the same safe-id filename prefix used when saving
 * (profile_{id}_*.*  and  qrid_{id}.*), so it cleans up regardless of
 * timestamp or image extension.
 *
 * @return array{profile_removed:int, qr_removed:int}
 */
function deleteStudentUploadedFiles(string $studentId): array {
    $safeId = preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentId);
    $removed = ['profile_removed' => 0, 'qr_removed' => 0];

    if ($safeId === '') {
        return $removed;
    }

    foreach (glob(PROFILE_UPLOAD_DIR . 'profile_' . $safeId . '_*') as $oldFile) {
        if (@unlink($oldFile)) {
            $removed['profile_removed']++;
        }
    }

    foreach (glob(QR_UPLOAD_DIR . 'qrid_' . $safeId . '.*') as $oldFile) {
        if (@unlink($oldFile)) {
            $removed['qr_removed']++;
        }
    }

    return $removed;
}