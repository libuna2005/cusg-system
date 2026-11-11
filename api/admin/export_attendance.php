<?php
// This script now emits a genuine .xlsx file (a real ZIP/OOXML package) instead
// of the old SpreadsheetML-2003-as-.xls trick. That older format is text-based
// and extremely fragile — a single stray byte, a bad control character, or a
// truncated response makes Excel refuse the whole file as "corrupted". A real
// .xlsx is a proper ZIP container that Excel/Google Sheets/LibreOffice all read
// natively, so it's far more robust.
//
// Output buffering from the very first line: even though the final payload is
// written straight to a temp file (see below), everything BEFORE that point
// (config.php, session handling, schema checks) must not leak any stray text
// into the response stream.
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0'); // never let a notice/warning print into the output stream

if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/common.php';

if (empty($_SESSION['cusg_admin'])) {
    ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

if (!class_exists('ZipArchive')) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'The PHP zip extension is not enabled on this server, so a real .xlsx file cannot be built. On InfinityFree this is usually enabled by default — check your hosting control panel under PHP extensions for "zip".',
    ]);
    exit;
}

/**
 * Self-healing schema checks, mirrored from the other admin endpoints, so
 * this export keeps working even on a database that hasn't been touched by
 * every feature yet.
 */
function ensureSchema(PDO $pdo): void {
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

    $cols = [
        'events' => [
            'group_id' => "ALTER TABLE events ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER id",
            'sub_label' => "ALTER TABLE events ADD COLUMN sub_label VARCHAR(100) NULL AFTER title",
            'target_year_levels' => "ALTER TABLE events ADD COLUMN target_year_levels VARCHAR(255) NULL AFTER is_flagged",
            'target_courses' => "ALTER TABLE events ADD COLUMN target_courses VARCHAR(500) NULL AFTER target_year_levels",
            'target_sections' => "ALTER TABLE events ADD COLUMN target_sections VARCHAR(255) NULL AFTER target_courses",
        ],
        'attendance_logs' => [
            'status' => "ALTER TABLE attendance_logs ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'present' AFTER source",
        ],
        'students' => [
            'section' => "ALTER TABLE students ADD COLUMN section VARCHAR(5) NULL AFTER year_level",
        ],
    ];

    foreach ($cols as $table => $columns) {
        foreach ($columns as $col => $ddl) {
            $exists = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'")->fetch();
            if (!$exists) {
                $pdo->exec($ddl);
            }
        }
    }
}

// Mirrors the course -> college optgroups in views/admin.html so exports
// group students the same way the "Excuse a Class" picker does.
function collegeForCourse(string $course): string {
    static $map = [
        'BSIT' => 'CCS', 'BSIS' => 'CCS',
        'BS CPE' => 'COE', 'BS ECE' => 'COE',
        'BIT Architectural Drafting Technology' => 'CIT',
        'BIT Culinary Technology' => 'CIT',
        'BIT Computer Technology' => 'CIT',
        'BIT Electronic Technology' => 'CIT',
        'BIT Electrical Technology' => 'CIT',
        'BIT Automotive Technology' => 'CIT',
        'BIT Mechanical Technology' => 'CIT',
        'BTVTED Major in Electrical' => 'COED',
        'BTVTED Major in Electronics' => 'COED',
    ];
    return $map[$course] ?? 'OTHER';
}

function collegeSortRank(string $college): int {
    static $order = ['CCS' => 0, 'COE' => 1, 'CIT' => 2, 'COED' => 3, 'OTHER' => 4];
    return $order[$college] ?? 99;
}

function yearSortRank(string $year): int {
    static $order = ['1st Year' => 0, '2nd Year' => 1, '3rd Year' => 2, '4th Year' => 3];
    return $order[$year] ?? 99;
}

// ---------------------------------------------------------------------------
// Minimal .xlsx (OOXML) writer — no external library needed, just ZipArchive.
// Cells are written as inlineStr (text embedded directly in the cell) rather
// than a sharedStrings table, which keeps this generator simple and avoids
// an entire extra XML part to keep in sync.
// ---------------------------------------------------------------------------

/** Strips characters illegal in XML 1.0 (even Word/phone-keyboard invisible ones). */
function xmlSafe(?string $value): string {
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);
    if ($clean === null) {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value);
    }
    return htmlspecialchars((string) $clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Excel sheet name rules: <=31 chars, no \ / ? * [ ] :, can't start/end with an apostrophe, can't repeat. */
function sanitizeSheetName(string $raw, array &$usedNames): string {
    $clean = preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $raw);
    $clean = trim($clean, " '");
    if ($clean === '') {
        $clean = 'Event';
    }
    $clean = mb_substr($clean, 0, 31);

    $base = $clean;
    $suffix = 2;
    while (isset($usedNames[$clean])) {
        $trimBase = mb_substr($base, 0, 31 - strlen(" ({$suffix})"));
        $clean = "{$trimBase} ({$suffix})";
        $suffix++;
    }
    $usedNames[$clean] = true;
    return $clean;
}

/** Converts a 1-based column index to its Excel letter (1 -> A, 27 -> AA, ...). */
function columnLetter(int $index): string {
    $letters = '';
    while ($index > 0) {
        $rem = ($index - 1) % 26;
        $letters = chr(65 + $rem) . $letters;
        $index = intdiv($index - 1, 26);
    }
    return $letters;
}

/**
 * Builds one <row> element. $cells is a list of ['value' => string, 'style' => int].
 * $style refers to a cellXfs index defined in styles.xml (see STYLE_* constants below).
 */
function buildRowXml(int $rowNum, array $cells): string {
    $xml = '<row r="' . $rowNum . '">';
    foreach ($cells as $i => $cell) {
        $ref = columnLetter($i + 1) . $rowNum;
        $styleAttr = isset($cell['style']) ? ' s="' . $cell['style'] . '"' : '';
        $xml .= '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">' . xmlSafe($cell['value']) . '</t></is></c>';
    }
    $xml .= '</row>';
    return $xml;
}

const STYLE_CELL = 0;
const STYLE_HEADER = 1;
const STYLE_PRESENT = 2;
const STYLE_EXCUSED = 3;
const STYLE_ABSENT = 4;
const STYLE_NO_CLASS = 5;

function stylesXml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="6">'
        . '<font><sz val="10"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF065F46"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF1E40AF"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF991B1B"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="6">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1A3A8F"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="4" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="5" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

try {
    ensureSchema($pdo);

    $eventsStmt = $pdo->query('SELECT e.id, e.title, e.sub_label, e.event_date, e.is_flagged, e.group_id, e.target_year_levels, e.target_courses, e.target_sections, g.title AS group_title
                                FROM events e
                                LEFT JOIN event_groups g ON g.id = e.group_id
                                ORDER BY e.event_date ASC, e.id ASC');
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $studentsStmt = $pdo->query('SELECT student_id, full_name, course_program, year_level, section FROM students ORDER BY full_name ASC');
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

    $attendanceStmt = $pdo->query('SELECT event_id, student_id, status, scanned_at FROM attendance_logs');
    $attendanceMap = []; // "eventId|studentId" => ['status'=>..,'scanned_at'=>..]
    foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $attendanceMap[$row['event_id'] . '|' . $row['student_id']] = $row;
    }

    if (empty($events)) {
        ob_end_clean();
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'No events to export yet.']);
        exit;
    }

    // ---- Build each worksheet's XML, plus the Summary sheet -------------------
    $usedNames = [];
    $sheetNames = ['Summary'];
    $sheetXmlByIndex = [];
    $summaryRows = [];

    $headerCells = function (array $labels): array {
        return array_map(fn($l) => ['value' => $l, 'style' => STYLE_HEADER], $labels);
    };

    foreach ($events as $event) {
        $matched = array_values(array_filter($students, function ($s) use ($event) {
            return studentMatchesTarget($event['target_year_levels'], $s['year_level'])
                && studentMatchesTarget($event['target_courses'], $s['course_program'])
                && studentMatchesTarget($event['target_sections'], (string) ($s['section'] ?? ''));
        }));

        usort($matched, function ($a, $b) {
            $ca = collegeForCourse($a['course_program']);
            $cb = collegeForCourse($b['course_program']);
            if ($ca !== $cb) return collegeSortRank($ca) <=> collegeSortRank($cb);
            if ($a['course_program'] !== $b['course_program']) return strcmp($a['course_program'], $b['course_program']);
            $ya = yearSortRank($a['year_level']);
            $yb = yearSortRank($b['year_level']);
            if ($ya !== $yb) return $ya <=> $yb;
            $sa = (string) ($a['section'] ?? '');
            $sb = (string) ($b['section'] ?? '');
            if ($sa !== $sb) return strcmp($sa, $sb);
            return strcmp($a['full_name'], $b['full_name']);
        });

        $presentCount = 0;
        $excusedCount = 0;
        $noClassCount = 0;
        $absentCount = 0;

        $rows = [];
        $rows[] = $headerCells(['College', 'Course / Program', 'Year Level', 'Section', 'Student ID', 'Full Name', 'Status', 'Time Scanned']);

        foreach ($matched as $s) {
            $key = $event['id'] . '|' . $s['student_id'];
            $log = $attendanceMap[$key] ?? null;
            $status = $log ? ($log['status'] ?: 'present') : 'absent';
            $scannedAt = $log['scanned_at'] ?? '';

            if ($status === 'excused') {
                $excusedCount++;
                $statusLabel = 'Excused';
                $statusStyle = STYLE_EXCUSED;
            } elseif ($status === 'no_class') {
                $noClassCount++;
                $statusLabel = 'No Class';
                $statusStyle = STYLE_NO_CLASS;
            } elseif ($status === 'present') {
                $presentCount++;
                $statusLabel = 'Present';
                $statusStyle = STYLE_PRESENT;
            } else {
                $absentCount++;
                $statusLabel = 'Absent';
                $statusStyle = STYLE_ABSENT;
            }

            $college = collegeForCourse($s['course_program']);
            $rows[] = [
                ['value' => $college, 'style' => STYLE_CELL],
                ['value' => $s['course_program'], 'style' => STYLE_CELL],
                ['value' => $s['year_level'], 'style' => STYLE_CELL],
                ['value' => (string) ($s['section'] ?? ''), 'style' => STYLE_CELL],
                ['value' => $s['student_id'], 'style' => STYLE_CELL],
                ['value' => $s['full_name'], 'style' => STYLE_CELL],
                ['value' => $statusLabel, 'style' => $statusStyle],
                ['value' => $scannedAt !== '' ? $scannedAt : '-', 'style' => STYLE_CELL],
            ];
        }

        $label = $event['sub_label'] ? ($event['group_title'] ? $event['group_title'] . ' - ' . $event['sub_label'] : $event['sub_label']) : $event['title'];
        $sheetName = sanitizeSheetName($label . ' ' . $event['event_date'], $usedNames);
        $sheetNames[] = $sheetName;

        $rowsXml = '';
        foreach ($rows as $i => $cells) {
            $rowsXml .= buildRowXml($i + 1, $cells);
        }

        $widths = [8, 30, 12, 9, 14, 28, 11, 18];
        $colsXml = '<cols>';
        foreach ($widths as $i => $w) {
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';

        $sheetXmlByIndex[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $colsXml
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . '</worksheet>';

        $summaryRows[] = [
            ['value' => $label, 'style' => STYLE_CELL],
            ['value' => $event['event_date'], 'style' => STYLE_CELL],
            ['value' => $event['is_flagged'] ? 'Flag Ceremony' : 'Institutional', 'style' => STYLE_CELL],
            ['value' => (string) count($matched), 'style' => STYLE_CELL],
            ['value' => (string) $presentCount, 'style' => STYLE_PRESENT],
            ['value' => (string) $excusedCount, 'style' => STYLE_EXCUSED],
            ['value' => (string) $absentCount, 'style' => STYLE_ABSENT],
        ];
    }

    // Summary sheet goes first.
    $summaryAllRows = [$headerCells(['Event', 'Date', 'Classification', 'Audience Matched', 'Present', 'Excused', 'Absent'])];
    foreach ($summaryRows as $r) {
        $summaryAllRows[] = $r;
    }
    $summaryRowsXml = '';
    foreach ($summaryAllRows as $i => $cells) {
        $summaryRowsXml .= buildRowXml($i + 1, $cells);
    }
    $summaryWidths = [32, 12, 14, 10, 9, 9, 9];
    $summaryColsXml = '<cols>';
    foreach ($summaryWidths as $i => $w) {
        $summaryColsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $summaryColsXml .= '</cols>';
    $summarySheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $summaryColsXml
        . '<sheetData>' . $summaryRowsXml . '</sheetData>'
        . '</worksheet>';

    array_unshift($sheetXmlByIndex, $summarySheetXml);
    // $sheetNames[0] is already 'Summary'; $sheetXmlByIndex[0] is now the summary sheet.

    // ---- Assemble the workbook-level XML parts ---------------------------------
    $sheetCount = count($sheetNames);

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $sheetCount; $i++) {
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $contentTypes .= '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>';
    foreach ($sheetNames as $i => $name) {
        $sheetIndex = $i + 1;
        $workbookXml .= '<sheet name="' . xmlSafe($name) . '" sheetId="' . $sheetIndex . '" r:id="rId' . $sheetIndex . '"/>';
    }
    $workbookXml .= '</sheets></workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    for ($i = 1; $i <= $sheetCount; $i++) {
        $workbookRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $workbookRels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $workbookRels .= '</Relationships>';

    // ---- Write the zip to a temp file, then stream it -------------------------
    // Writing to a real temp file (rather than building the zip in a PHP
    // string/memory buffer) is the most reliable approach on shared hosting:
    // ZipArchive needs a real filesystem path to work against.
    // The system temp dir is often not writable on shared hosting (open_basedir,
    // permissions), so try several locations, including a private folder that
    // lives next to this script.
    $tmpPath = false;
    $candidateDirs = [];
    $iniTmp = ini_get('upload_tmp_dir');
    if ($iniTmp) { $candidateDirs[] = $iniTmp; }
    $candidateDirs[] = sys_get_temp_dir();
    $candidateDirs[] = __DIR__ . '/../../tmp';
    $candidateDirs[] = __DIR__ . '/tmp';
    foreach ($candidateDirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            if (is_dir($dir)) {
                // Block direct web access to the temp folder.
                @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            }
        }
        if (is_dir($dir) && is_writable($dir)) {
            $candidate = @tempnam($dir, 'cusg_xlsx_');
            if ($candidate !== false) {
                $tmpPath = $candidate;
                break;
            }
        }
    }
    if ($tmpPath === false) {
        throw new RuntimeException('Could not create a temporary file for the export. Make sure the folder api/tmp (or api/admin/tmp) exists and is writable (chmod 755 or 775).');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the .xlsx package.');
    }

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', stylesXml());
    foreach ($sheetXmlByIndex as $i => $xml) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
    }
    $zip->close();

    $fileSize = filesize($tmpPath);
    if ($fileSize === false || $fileSize === 0) {
        @unlink($tmpPath);
        throw new RuntimeException('The generated .xlsx file came out empty.');
    }

    // Discard anything that may have leaked into the buffer up to this point
    // (stray notices, whitespace from included files, etc.) so the very
    // first byte the browser receives is genuinely the ZIP file signature.
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="cusg_attendance_export_' . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: max-age=0');

    readfile($tmpPath);
    @unlink($tmpPath);
    exit;
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}