<?php
// Small helpers shared by many endpoints.

function readJsonInput(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Turns a JSON array (or comma-separated string) of values into a clean
 * comma-separated string for storage, or null if the list is empty — an
 * empty/null value means "visible to everyone", matching the old behavior.
 */
function normalizeTargetList($raw): ?string {
    if (is_string($raw)) {
        $raw = array_map('trim', explode(',', $raw));
    }
    if (!is_array($raw)) {
        return null;
    }
    $clean = array_values(array_unique(array_filter(array_map('trim', $raw), fn($v) => $v !== '')));
    return count($clean) > 0 ? implode(',', $clean) : null;
}

function studentMatchesTarget(?string $targetList, string $studentValue): bool {
    if ($targetList === null || trim($targetList) === '') {
        return true;
    }
    $allowed = array_map('trim', explode(',', $targetList));
    return in_array(trim($studentValue), $allowed, true);
}
