# Changelog

Every time changes are made, the file name gets a new version number and a
short summary is added here, newest on top.

From v22 onward each entry also lists which files were changed, added or
removed, so you know exactly what to copy over your existing project.
(Entries v21 and older don't have file lists.)\

## v44 - refactor: shared PHP helpers, dead code removed (no behavior change)

-added: api/lib/common.php
-added: api/lib/schema.php
-added: api/lib/scan_common.php
-changed: api/admin/attendance_lock.php
-changed: api/admin/event_groups.php
-changed: api/admin/events.php
-changed: api/admin/excuse.php
-changed: api/admin/export_attendance.php
-changed: api/admin/scan.php
-changed: api/admin/scan_batch.php
-changed: api/admin/session_completion.php
-changed: api/admin/students.php
-changed: api/admin/unresolved_scans.php
-changed: api/auth/login.php
-changed: api/auth/register.php
-changed: api/student/attendance.php
-changed: api/student/profile.php
-changed: views/admin.html
-changed: controllers/Studentcontroller.js

- 19 helper functions that were copy-pasted across endpoints (up to 8 copies each) now live once in
  api/lib/common.php, schema.php and scan_common.php. Bodies are byte-identical to the originals.
- Removed two unused functions (setDeviceLabel in admin.html, readFileAsDataUrl in Studentcontroller.js).
- config.php, database schema, endpoints, request/response formats and auth checks are untouched.

## v43 - "not valid JSON" fixed properly + admin Sign out on phones

-added: assets/js/api-guard.js
-changed: views/admin.html
-changed: views/admin_login.html
-changed: views/index.html
-changed: views/register.html
-changed: views/student.html
-changed: views/cusg.html

- The old fix only retried the request, but the host's security page needs a small script to run
  first, so the error kept returning. assets/js/api-guard.js now runs that script itself, then
  repeats the request. If the server still answers with a web page, a clear message appears
  instead of "Unexpected token '<' ... not valid JSON" (the scanner queues the scan as before).
  The old copy-pasted wrappers inside each page were removed (one shared file now).
- Admin on phones: a "Sign out" button is always visible at the top right.

## v42 - student QR ID regenerates automatically

-changed: controllers/Studentcontroller.js
-changed: views/student.html

- Saving the profile now always redraws and re-saves the QR ID card (even if the student never made one).
- On opening the page, if the saved card was made from older details, it is quietly redrawn once.
  Failed uploads are not retried for 30 minutes (no loops).
- "Edit QR ID" is now "Regenerate QR" and works in place (no trip to the generator). The empty
  state button is "Generate my QR ID". Added a short note on the QR tab and in Settings.

## v41 - scan falls back to the NAME when the ID on the card is not registered

-changed: api/admin/scan.php
-changed: api/admin/scan_batch.php

- When the ID in the QR is not found (for example the student changed their Student ID after the card was made), the scanner now looks up the name inside the QR (last + first). If EXACTLY ONE registered student has that name (case and accent insensitive), the attendance is saved under that student's current ID.
- The result says so: "(The QR's ID "OLD" is not registered, so this was matched by NAME to ID NEW - please confirm it is the right person.)" and the response carries matched_by = "name" (normal scans carry "id").
- No guessing: two students with the same name, a QR with no name (manual entry, old cards), or an unknown name still give "not found" and go to Unresolved Scans exactly as before.
- A registered ID with a wrong name is still blocked (name mismatch), unchanged.
- The match is written to the PHP error log ("[cusg scan] matched by name ...").
- Applies to live scans and Batch Mode uploads. No database change.

## v40 - student phone navigation + sections A to G

-changed: views/student.html
-changed: controllers/Studentcontroller.js
-changed: controllers/registerController.js
-changed: views/cusg.html
-changed: views/admin.html

- Student page, phone width: the slide-out side panel and its hamburger button are removed (the bottom bar already has Attendance / My QR / Settings). The empty strip they used to reserve at the top is gone. Sign out moved into the profile header on phones. On PC (wider than 860 px) the side panel is unchanged.
- Section choices are now A to G everywhere: registration, student Settings, QR generator, and the admin Directory / Excuse / Event audience lists.
- A student who is already in a section beyond G keeps it: it is added to their own dropdown so saving does not wipe it.
- The admin page keeps its side menu on phones (it has 7 sections and the bottom bar only fits 4 + More).
- No server-side restriction was added, so existing students in sections H-Z are not affected.

## v39 - student profile save (section change) fix

-changed: api/student/profile.php
-changed: views/student.html
-changed: controllers/Studentcontroller.js

- FIXED: saving the profile when nothing differed from what is already stored (double tap on "Update Profile Info", pressing Save again after it worked) showed "Update failed: Student profile not found." MySQL's rowCount() counts CHANGED rows, not matched rows, so 0 was wrongly treated as "no such student". It now checks the student really exists before reporting that error. A genuinely missing student still gets "Student profile not found."
- FIXED: after a student changes section / name / course / year, the saved QR ID card picture kept showing the OLD details. The student page now redraws the card with the shared design (assets/js/qr-card.js) and saves it right after the profile saves. If that step fails the student is told to open "Edit QR ID"; the profile itself is already saved.
- Verified: changing the section does NOT affect scanning - the scan matches on student ID and name only, so a card printed with the old section still records attendance.

## v38 - scans are recorded under the officer account, not a device name

-changed: api/admin/scan.php
-changed: api/admin/scan_batch.php
-changed: views/admin.html

- The server now takes "who scanned" from the logged-in officer account (full name + username) for Unresolved Scans and the Upload Audit Log, for both live scans and batch uploads. Before, it used a free-text "Officer / Device Name" typed on the phone (often blank or different per phone).
- Scan page: the typed device-name box is replaced by a "Scanning as <name (username)>" box.
- Unresolved Scans now read "... by <officer account>"; audit log blank label reads "Unknown officer".
- No database change (same device_label column, now holding the account name). Old rows keep the label they had.
- Not stored: which officer recorded each SUCCESSFUL attendance (attendance_logs has no such column). See decision in the reply.

## v37 - student redesign + new QR ID card (includes v34-v36)

-changed: views/student.html
-changed: controllers/Studentcontroller.js

STUDENT PAGES (no API, endpoint or database change)
- My Attendance: one readable row per event (no sideways scrolling), status shown as colour + icon + word, three summary tiles (Present / Excused or No class / Absent), section headings with "x of y accounted for".
- Refresh button + "Updated 8:35 PM" label (the old text said "refresh to UPDATE the record"). Coming back to the tab after a minute refreshes once. If the network fails, a clear message shows and the last list stays on screen.
- Absent rows explain what to do: tap Refresh, then tell an officer your Student ID and the event name. The locked "To Be Posted" state is unchanged (never a false Absent).
- My QR ID: large card, brightness tip, "Show to Officer" (full-screen white page, keeps the screen awake where the phone allows), Download, Edit QR ID. The duplicate "Open QR Generator" button was removed. A clear message shows if the picture cannot load.
- Settings: Profile (open), Change Password and Delete Account (collapsed, delete styled as danger).
- Phone bottom bar (Attendance / My QR / Settings), keyboard-usable menu, visible focus outline.

ALSO IN THIS TREE SINCE v36 (was not listed before)
- assets/js/qr-card.js: the ONE place the QR ID card is drawn (new design: navy/green/orange header, photo or initials, QR on a white panel with a full quiet zone, error-correction M).
  Blue/green/orange stripes are thicker (14 px under the header with a white gap so the blue shows, 26 px at the bottom).
  The data inside the QR is unchanged: student_id,last,first,middle,course,year,section - cards already printed or saved keep scanning.
- views/cusg.html now draws the card through qr-card.js.
- api/admin/qr_cards.php (admin only, read-only) + Settings > "QR ID cards": regenerates every student's saved card with the new design and replaces the saved image (assets/uploads/qrcodes/qrid_<ID>.png). Resumable, retries only failures.

## v36 - admin redesign, part 2 (tables and phone navigation)

-changed: views/admin.html

NEW (no API, endpoint or database change; all filtering/sorting/paging is done in the browser on lists the page already loads)
- Recent Scans (Scan tab): status filter (Present / Excused / No Class), sort (newest / oldest / name), 25 per page.
- Standalone Events: search by title, filter by type and date range, sort, 25 per page, "More columns" (A.Y. Term hidden by default), clear empty-state message when filters match nothing.
- Students directory results: narrow by name/ID, sort, 50 per page (no more hundreds of cards at once).
- Admin accounts: search, "More columns" (the always-masked Temporary Password column is hidden by default).
- Phone bottom bar (Home / Scan / Events / Students / More) with current-section highlight; hidden on tablets/desktop.
- Any change to a search, filter or sort jumps back to page 1.
- Dashboard now has an event picker (it only displayed the event before, so it could not be changed from there). It drives the same active event as the Scan tab.

## v35 - admin redesign (navigation, Dashboard, focused Scan tab)

-changed: views/admin.html

NEW / MOVED (no feature removed, no API or database change)
- Sidebar is now 7 sections: Dashboard, Scan Attendance, Events & Sessions, Students, Attendance Records, Reports & Export, Settings.
- Dashboard (landing page): event being recorded, Recorded / Waiting on this phone / Needs review counts, plain-language alerts, latest scans, one main "Open Scanner" button. Refreshes every 30 s while visible.
- Scan tab now holds only the event picker and scanning tools. Excuse a Class, Excuse an Individual Student and Manual Batch Upload moved to Attendance Records (collapsible). Student-dashboard lock moved to Settings. Export button moved to Reports & Export.
- Settings groups the lock switch, Admin accounts and the End-of-School-Year purge (collapsed "Danger zone").
- Status pills use colour + icon + word (Present / Excused / No Class / Already scanned / Not recorded).
- Keyboard: sidebar items focusable and operable with Enter/Space; visible focus outline. 16 px / 44 px touch targets and phone layout checked on all 7 panels.

FIXED
- "Execute Storage Purge Cascade" used to alert that a purge was "successfully broadcasted" although it never called anything. It now says nothing was deleted (the purge is still not connected - see notes).

## v34 - scans that were witnessed but never recorded (scan-flow reliability fix)

-changed: api/admin/scan.php
-changed: api/admin/scan_batch.php
-changed: api/config.php
-changed: views/admin.html
-changed: views/cusg.html

FIXED
- Phone queue lost scans: finishing an upload wrote the phone's OLD copy of the queue back to storage, erasing every
  scan made while the upload was running (the 3-minute background sync made this likely). The live queue is now
  re-read before removing uploaded items.
- Scans now upload by themselves ~20 s after scanning pauses, when the phone reconnects and when the tab returns to the
  foreground - no dependence on someone pressing "Upload Now". Only one upload runs at a time.
- If a scan cannot be saved on the phone (storage full) it goes straight to the server instead of showing a false "saved".
- On-screen message now says a batch-mode scan is "saved on THIS PHONE only - not on the server yet".
- Officers are told (alert, or a tap-to-dismiss banner for background sync) when an upload contained scans that were NOT
  recorded (unknown ID / unreadable QR / name mismatch) - they used to vanish into Unresolved Scans unnoticed.
- Server: an ID typed/encoded in a different case ("crn05210800") or with invisible characters (no-break space,
  zero-width, BOM) was rejected as "not found" in batch uploads; live scans stored the record under the typed spelling.
  IDs are now cleaned and always saved under the student's stored ID.
- Server: the live-scan response showed PHP's clock while the database stored its own; it now returns the stored time.
- Server: scan.php / scan_batch.php now self-heal the students.section column they select (a missing column made every
  scan return a 500).
- Host anti-bot HTML page (caused "Unexpected token '<'") is retried automatically on the admin page.
- Scanner: QR-only decoding, scan area 85% of the camera view (was a fixed 250 px box), HD video with automatic fallback,
  15 fps and continuous autofocus. QR generator uses error-correction M (37x37 grid instead of 49x49).

CLEANED
- Scanner start/stop, scan routing, queue, upload and recordScan logic rewritten into small single-purpose helpers with
  named constants (same function names and behaviour for everything the page calls).
- Removed the duplicated per-scan fetch/headers/JSON boilerplate (one private apiRequest helper).

OPTIMIZED
- Both scan endpoints release the PHP session lock right after the login check (requests from one officer no longer
  queue behind each other).
- Insert is retried (max 3) on MySQL deadlock / lock-wait errors instead of failing the scan.
- Every swallowed error in the scan flow now also writes to the PHP error log ("[cusg scan] ...").

## v33 - delete student (admin) + clear unresolved scans

-added: api/admin/delete_student.php
-changed: api/admin/unresolved_scans.php
-changed: views/admin.html

- Student Attendance Directory: after searching a student there is now a **Delete Student** button. It asks for confirmation and for the Student ID to be typed, then permanently removes the account, all attendance records, event registrations, undo-log rows, related unresolved scans, and the profile photo + QR ID card files from assets/uploads/.
- Unresolved Scans: new **Clear** button deletes every entry in the current view (open entries, or handled ones when "Show handled" is ticked).

## v29 - fix phone UI stuck zoomed in on the admin scan page

-changed: views/admin.html

- Disables double-tap zoom (pinch zoom stays available).
- Form controls are forced to 16px on phones so iOS no longer auto-zooms when an
  officer taps an input (Officer/Device Name, Manual Entry, filters, etc.).
- Scan layout grid uses minmax(0,1fr) so wide content can't stretch it off-screen.
- Long unbroken text (odd QR contents, URLs, device names) now wraps instead of widening the page.
- Edit Event Group: the Sub-Sessions box is now editable and pre-filled with the group's current sessions. Add any custom names (not just Login/Logout); removed names are deleted after a confirmation, and a removed+added pair can be treated as a rename (keeps attendance).
- After uploading, officers should fully close and reopen the page once.

## v28 - student dashboard shows event name instead of "Event N"

-changed: controllers/Studentcontroller.js
-changed: views/student.html
-changed: assets/css/student/student.css

## v27 - manual batch upload for students

-changed: views/admin.html

## v26 - fix attedance export excel

-changed: api/admin/export_attendance.php

## v25 — Cleaner Recent Scans / Unresolved Scans lists in the Scan tab
- Both lists used to be a heading followed by an unbounded table/list that
  just kept growing, pushing the rest of the page down and making it hard
  to find anything once an event had a lot of scans. Both now live in their
  own bordered card with a toolbar and a fixed-height, independently
  scrollable body (sticky column header for Recent Scans).
- **Recent Scans**: added a live count badge and a "Filter by name or ID"
  search box (filters instantly, no server call). Columns condensed from 6
  to 4 — Student now shows name + ID stacked together, and the Undo button
  moved into the "Scanned" column next to the timestamp — so rows are less
  cramped and easier to scan at a glance. Zebra-striped rows.
- **Unresolved Scans**: added filter pills (All / ID Not Found / Name
  Mismatch / Unreadable) plus a search box for the attempted ID, so a long
  list can be narrowed down instead of scrolled through entry by entry. The
  raw QR text on each entry (rarely needed) is now tucked behind a "Raw QR
  data" toggle instead of always being shown, and the fix/dismiss actions
  are visually separated from the entry's details with a divider.
- No behavior changes to how scans are recorded, undone, or resolved — this
  is layout/readability only. Existing action buttons (Undo, Save & Record,
  Dismiss) work exactly as before.

**Files**
- Changed: `views/admin.html` — new CSS (`.scan-list-card`,
  `.scan-list-toolbar`, `.scan-list-search`, `.scan-list-scroll`,
  `.filter-pills`/`.filter-pill`, `.unresolved-item*`, etc.), reworked
  markup for the Recent Scans table and Unresolved Scans list in the Scan
  tab, and JS changes: `loadRecentScans` now caches into `lastRecentScans`
  and delegates rendering to new `renderRecentScans()` /
  `updateRecentScansCount()`; `loadUnresolvedScans` now caches into
  `lastUnresolvedItems` and delegates to new `renderUnresolvedScans()` /
  `setUnresolvedReasonFilter()`.
- Changed: `CHANGELOG.md` (this file).
- Added: none
- Removed: none
- Database / `api/` (PHP): no changes.
- To update from v24: replace `views/admin.html` only.

## v24 — Admin student search only shows events the student is actually targeted for
- Fixed: searching a student by ID in the Admin "Student Attendance" panel was
  showing *every* event in the "Live Attendance Breakdown", including events
  targeted to a different department/course (e.g. a CCS-only event would
  still show up when searching an Engineering student). The admin search now
  applies the same audience filter (target year level / course / section)
  that the student's own dashboard already used, so only events that student
  is actually eligible for are listed.
- The Flag/Event totals shown next to the breakdown ("X / Y attended") are
  now computed from that same filtered list, so the numbers match what's
  displayed instead of counting events the student was never part of.

**Files**
- Changed: `api/admin/students.php` — added the `studentMatchesTarget()`
  helper (copied from `api/student/attendance.php`) and self-healing
  creation of the `target_year_levels` / `target_courses` / `target_sections`
  columns on `events`. The single-student lookup (`?student_id=...`) now
  fetches those columns, filters the event list down to the student's
  audience before returning it, and recomputes `total_flags` / `total_events`
  / `flag_attended` / `event_attended` from that filtered list instead of
  from every event in the table.
- Changed: `CHANGELOG.md` (this file).
- Added: none
- Removed: none
- `views/admin.html`: no changes — it already just renders whatever `events`
  array the API returns, so the fix is API-side only.
- To update from v23: replace `api/admin/students.php` only.

## v23 — Import QR ID photos, unified import card
- New **QR ID Photos** button: pick one or many photos/screenshots of student
  ID QR codes and each decoded QR is added to the pending queue for the
  selected event (photos never leave the device — only the decoded ID text
  is uploaded). Uses the browser's native QR detector when available
  (Chrome on Android, also reads several codes in one photo) and falls back
  to the html5-qrcode library, retrying on downscaled and cropped versions
  so a small QR inside a full ID-card photo still reads.
- Photos that contain no readable QR are listed in the confirmation so they
  can be retaken or entered manually.
- Import card now covers both the Scanner App CSV and QR photos, with an
  optional "Upload immediately after importing" checkbox.
- CSV import verified against a real export from the scanner app
  (single quoted `text` cell, `date,time,Timezone,format,text,...` header).

**Files**
- Changed: `views/admin.html` — replaces the v22 CSV-only card with the
  "Import Scans (CSV or QR Photos)" card (buttons, hidden file inputs
  `scannerCsvInput` / `qrImageInput`, the "Upload immediately after
  importing" checkbox, hidden `qrImageDecodeHost`). New JS functions:
  `setImportStatus`, `getImportEventContext`, `queueImportedEntries`,
  `cropBitmapToBlob`, `decodeQrTextsFromImage`, `importQrImages`;
  `importScannerCsv` now uses the shared `queueImportedEntries`.
- Changed: `CHANGELOG.md` (this file).
- Added: none
- Removed: none
- Database / `api/` (PHP): no changes.
- To update from v21 or v22: replace `views/admin.html` only.

## v22 — Import CSV from an external scanner app
- New "Import CSV from a Scanner App" card in the Scan tab. Pick the event,
  choose the CSV exported from a Play Store scanner app, and the rows are
  added to the existing pending queue — review them, then press Upload Now.
- Reuses the whole batch pipeline: chunked upload, duplicate handling,
  name-mismatch check, Unresolved Scans trail and the upload audit log.
- Handles both export layouts: the full ID-card text in one quoted cell, or
  split across columns (the card QR itself contains commas). Also detects
  `;` and tab delimiters, strips the BOM and skips a header row.
- Uses the cached student list to find the ID in each row, so Sync Student
  List must be done first. Duplicate rows (same student twice) are skipped.
- One event per CSV file. Attendance time is the upload time, not the time
  the scanner app recorded (same as batch mode).

**Files**
- Changed: `views/admin.html` — new "Import CSV from a Scanner App" card in
  the Scan tab and the JS behind it (`parseDelimitedText`,
  `detectCsvDelimiter`, `extractQrFromCsvRow`, `importScannerCsv`). The
  card itself was reworked in v23.
- Changed: `CHANGELOG.md` (this file).
- Added: none
- Removed: none
- Database / `api/` (PHP): no changes.

## v21 — Autocomplete dropdown for Excuse Individual Student
- The Student ID(s) box in "Excuse an Individual Student" now shows a live
  dropdown as you type — matches on Student ID **or** name (first, last,
  or any part of it), so you can find someone even if you don't remember
  their exact ID.
- Works correctly with the comma-separated list: it only searches/replaces
  whichever ID you're currently typing, leaving any other IDs already in
  the box untouched.
- Keyboard support: ↑/↓ to move through suggestions, Enter to pick the
  highlighted one, Esc to close the dropdown. Plain Enter with nothing
  highlighted inserts a comma separator instead of a line break, so it
  never breaks the comma-separated format.
- Click a suggestion to insert it — a comma and space are added
  automatically so you can keep typing the next one right away.
- No extra network calls — reuses the same student directory the app
  already loads for its other autocomplete fields.

## v20 — Individual student excuses (comma-separated, multiple at once)
- New "Excuse an Individual Student" card in the Excuse area, separate from
  "Excuse a Class" — for excusing one or a handful of specific students
  instead of a whole course/year/section.
- The Student ID field accepts a **comma-separated list** (e.g.
  `22-12345, 22-12346, 22-12347`) — type or paste several IDs and mark them
  all at once.
- Live preview as you type: shows the matched name/course/section for each
  ID, flags any student who already has a record for that event (will be
  skipped, not overwritten), and lists any IDs that didn't match anyone.
- Undo button removes just the Excused/No Class records for the exact
  student(s) and event entered — never touches a real scanned "present."
- Backend: `api/admin/excuse.php` GET/POST/DELETE all now accept a
  `student_id` parameter containing one or many comma-separated IDs,
  alongside the existing course/year/section (class-level) mode.

## v19 — QR name-mismatch safety check, auto-reconcile by name, save-name fixes, Unresolved Scans improvements
(Baseline this changelog starts tracking from — earlier history lives in
chat, not repeated here.)