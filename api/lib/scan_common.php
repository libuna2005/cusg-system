<?php
// Helpers shared by the live scanner (scan.php) and the offline batch upload (scan_batch.php).

/**
 * Trims whitespace AND the invisible characters that sneak in from copy/paste,
 * CSV exports and some QR apps (no-break space, zero-width space/joiners, BOM).
 * A plain trim() leaves those behind and the ID then matches nobody.
 */
function cleanScanText(string $text): string {
    $text = preg_replace('/[\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text) ?? $text;
    return trim($text);
}

/**
 * Runs the attendance INSERT, retrying a couple of times when MySQL aborts it
 * because of a deadlock / lock-wait timeout (many officers writing at once).
 * Any other error - including the duplicate-key one - is rethrown untouched.
 */
function executeInsertWithRetry(PDOStatement $stmt, array $params): void {
    $attempts = 0;
    while (true) {
        try {
            $stmt->execute($params);
            return;
        } catch (PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            $isTransient = in_array($driverCode, [1213, 1205], true); // deadlock, lock wait timeout
            if (!$isTransient || ++$attempts >= 3) {
                throw $e;
            }
            logScanProblem('insert retry', "attempt {$attempts} after MySQL error {$driverCode}");
            usleep(100000 * $attempts);
        }
    }
}

/**
 * Pulls the last/first name back out of the same comma-separated card text
 * (see extractStudentId above). Returns empty strings for a plain manual
 * ID or an old-format QR with no name embedded -- callers should treat
 * that as "nothing to check against", not as a mismatch.
 */
function extractQrNameParts(string $rawQrText): array {
    $rawQrText = trim($rawQrText);
    if ($rawQrText === '' || strpos($rawQrText, ',') === false) {
        return ['last_name' => '', 'first_name' => ''];
    }
    $parts = array_map('trim', explode(',', $rawQrText));
    return [
        'last_name' => $parts[1] ?? '',
        'first_name' => $parts[2] ?? '',
    ];
}

/**
 * The student ID card QR encodes a comma-separated string:
 * student_id,last_name,first_name,middle_name,course,year_level,section
 * We only need the first field, but we defensively handle plain student IDs too.
 */
function extractStudentId(string $rawQrText): string {
    $rawQrText = cleanScanText($rawQrText);
    if ($rawQrText === '') {
        return '';
    }
    if (strpos($rawQrText, ',') !== false) {
        $parts = explode(',', $rawQrText);
        return cleanScanText($parts[0]);
    }
    return $rawQrText;
}

/**
 * Fallback for a QR whose ID is no longer registered (for example the student changed their
 * Student ID after the card was made). Looks the student up by the name inside the QR - but
 * ONLY when exactly one registered student has that last + first name (case and accent
 * insensitive, whole name, no guessing). Two students with the same name, or a QR with no
 * name (manual entry, old cards), returns null and the scan is reported "not found" as before.
 */
function findStudentByQrName(PDO $pdo, string $rawQrText): ?array {
    $parts = extractQrNameParts($rawQrText);
    $last = trim($parts['last_name']);
    $first = trim($parts['first_name']);
    if ($last === '' || $first === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT student_id, full_name, last_name, first_name, course_program, year_level, section, profile_picture_url FROM students WHERE last_name = ? AND first_name = ? LIMIT 2');
    $stmt->execute([$last, $first]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

/**
 * Logs a scan-flow problem to the PHP error log with enough context to trace
 * it later. Never throws and never changes the response sent to the officer.
 */
function logScanProblem(string $where, string $detail): void {
    error_log('[cusg scan] ' . $where . ': ' . $detail);
}

/**
 * Best-effort check that the name baked into the QR roughly matches the
 * name on file for the ID it decoded to. This is a safety net for the case
 * where a scanned ID happens to collide with a *different real* student
 * (typo, copy-paste slip) -- the ID lookup alone can't tell those apart,
 * since it never looks at anything but the ID. A short similarity score on
 * "last + first" tolerates minor formatting/typo differences while still
 * catching two names that are simply unrelated to each other.
 */
function namesLikelyMatch(string $qrLast, string $qrFirst, string $dbLast, string $dbFirst): bool {
    if ($qrLast === '' && $qrFirst === '') {
        return true; // nothing embedded to compare against (manual entry / old QR)
    }
    $qrCombined = normalizeNameForCompare($qrLast . ' ' . $qrFirst);
    $dbCombined = normalizeNameForCompare((string) $dbLast . ' ' . (string) $dbFirst);
    if ($qrCombined === '' || $dbCombined === '') {
        return true; // DB has no name-part data to compare against either
    }
    similar_text($qrCombined, $dbCombined, $percent);
    return $percent >= 55.0;
}

function normalizeNameForCompare(string $name): string {
    $name = strtoupper(trim($name));
    $name = preg_replace('/[^A-Z0-9 ]/', '', $name) ?? '';
    return preg_replace('/\s+/', ' ', $name) ?? '';
}

function normalizeProfilePicture(?string $profile): string {
    $profile = trim((string) $profile);
    if ($profile === '') {
        return '/cusg/assets/cusg_logo.jpg';
    }
    if (!preg_match('/^data:\w+\/[\w+\-\.]+;base64,/', $profile) && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $profile)) {
        $profile = 'data:image/png;base64,' . preg_replace('/\s+/', '', $profile);
    }
    return $profile;
}
