// ============================================================
//  studentController.js
//  File path: controllers/studentController.js
//  Handles: session load, welcome header, settings pre-fill,
//           attendance grid, profile update, password change
// ============================================================

(function () {

    const POLL_INTERVAL_MS = 1000; // faster polling (1s) so updates appear quickly

    let currentUser = null;
    let attendanceTimer = null;
    let lastAttendanceState = null;
    let lastRefreshAt = 0;
    let uploadedProfileImageDataUrl = null;

    try {
        const raw = localStorage.getItem('cusg_current_user');
        if (raw) currentUser = JSON.parse(raw);
    } catch (_) {}

    if (!currentUser) {
        window.location.href = 'index.html';
        return;
    }

    function formatDate(isoDate) {
        if (!isoDate) return '—';
        const d = new Date(isoDate);
        if (isNaN(d.getTime())) return isoDate;
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    const COURSE_LABELS = {
        'BSIT': 'BS in Information Technology',
        'BSIS': 'BS in Information Systems',
        'BS CPE': 'BS in Computer Engineering',
        'BS ECE': 'BS in Electronics Engineering',
        'BIT ARCHITECTURAL DRAFTING TECHNOLOGY': 'BIT - Architectural Drafting Technology',
        'BIT CULINARY TECHNOLOGY': 'BIT - Culinary Technology',
        'BIT COMPUTER TECHNOLOGY': 'BIT - Computer Technology',
        'BIT ELECTRONIC TECHNOLOGY': 'BIT - Electronic Technology',
        'BIT ELECTRICAL TECHNOLOGY': 'BIT - Electrical Technology',
        'BIT AUTOMOTIVE TECHNOLOGY': 'BIT - Automotive Technology',
        'BIT MECHANICAL TECHNOLOGY': 'BIT - Mechanical Technology',
        'BTVTED MAJOR IN ELECTRICAL': 'BTVTEd Major in Electrical',
        'BTVTED MAJOR IN ELECTRONICS': 'BTVTEd Major in Electronics',
    };

    function courseLabel(code) {
        if (!code) return 'No Program';
        return COURSE_LABELS[code.toUpperCase()] || code.toUpperCase();
    }

    function yearLabel(val) {
        if (!val) return 'No Year';
        return val.toUpperCase();
    }

    function populateHeader(user) {
        const nameEl = document.getElementById('welcomeStudentName');
        const metaEl = document.getElementById('welcomeStudentMeta');

        if (nameEl) {
            nameEl.textContent = `Welcome, ${user.full_name || 'Student'}!`;
        }

        if (metaEl) {
            const course = courseLabel(user.course_program);
            const year = yearLabel(user.year_level);
            const sectionText = user.section ? ` — SECTION ${user.section.toUpperCase()}` : '';
            metaEl.innerHTML = `<i class="fa-solid fa-graduation-cap"></i> ${year} — ${course}${sectionText}`;
        }

        if (user.profile_picture_url) {
            const imgs = document.querySelectorAll('.profile-preview-target');
            imgs.forEach(img => {
                img.src = user.profile_picture_url;
                img.style.display = 'block';
            });
            document.getElementById('headerAvatarIcon') && (document.getElementById('headerAvatarIcon').style.display = 'none');
            document.getElementById('settingsAvatarIcon') && (document.getElementById('settingsAvatarIcon').style.display = 'none');
        }
    }

    function populateSettings(user) {
        // Prefer the separate name columns (reliable). Only fall back to parsing
        // full_name for older accounts registered before this was split out.
        let last = user.last_name || '';
        let first = user.first_name || '';
        let middle = user.middle_name || '';

        if (!last && !first && user.full_name) {
            const raw = user.full_name.trim();
            if (raw.includes(',')) {
                const [lastPart, restPart] = raw.split(',').map(s => s.trim());
                last = lastPart || '';
                const restWords = (restPart || '').split(/\s+/).filter(Boolean);
                first = restWords[0] || '';
                middle = restWords.slice(1).join(' ');
            } else {
                const words = raw.split(/\s+/).filter(Boolean);
                last = words[0] || '';
                first = words[1] || '';
                middle = words.slice(2).join(' ');
            }
        }

        const fields = {
            editStudentId: user.student_id || '',
            editLastName: last,
            editFirstName: first,
            editMiddleName: middle,
            editEmail: user.institutional_email || '',
        };

        Object.entries(fields).forEach(([id, val]) => {
            const el = document.getElementById(id);
            if (el) el.value = val;
        });

        const courseEl = document.getElementById('editCourseProgram');
        if (courseEl && user.course_program) {
            const opt = [...courseEl.options].find(o => o.value === user.course_program);
            if (opt) courseEl.value = user.course_program;
        }

        const yearEl = document.getElementById('editYearLevel');
        if (yearEl && user.year_level) {
            const opt = [...yearEl.options].find(o => o.value === user.year_level);
            if (opt) yearEl.value = user.year_level;
        }

        const sectionEl = document.getElementById('editSection');
        if (sectionEl) {
            if (sectionEl.options.length <= 1) {
                const SECTION_COUNT = 7; // sections A to G
                for (let i = 0; i < SECTION_COUNT; i++) {
                    const letter = String.fromCharCode(65 + i); // 'A' .. 'G'
                    const opt = document.createElement('option');
                    opt.value = letter;
                    opt.textContent = `Section ${letter}`;
                    sectionEl.appendChild(opt);
                }
            }
            if (user.section) {
                // A student already in a section beyond G keeps it (it is added so saving does not wipe it).
                if (![...sectionEl.options].some(o => o.value === user.section)) {
                    const keep = document.createElement('option');
                    keep.value = user.section; keep.textContent = `Section ${user.section}`;
                    sectionEl.appendChild(keep);
                }
                sectionEl.value = user.section;
            }
        }
    }

    function buildSectionLabel(text, icon) {
        const el = document.createElement('div');
        el.className = 'att-section-label';
        el.innerHTML = `<i class="fa-solid ${icon}"></i> ${text}`;
        return el;
    }

    function escapeHtmlLocal(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function buildGroupLabel(text) {
        const el = document.createElement('div');
        el.className = 'att-group-title';
        el.innerHTML = `<i class="fa-solid fa-folder-open"></i> ${escapeHtmlLocal(text)}`;
        return el;
    }

    // Splits a flat list of events into rows: events that share a group_id
    // become one row (a "folder" of sub-sessions), ungrouped events each
    // become their own single-item row.
    function groupEventsIntoRows(eventList) {
        const rows = [];
        const groupIndex = new Map();
        let standaloneRow = null;

        eventList.forEach(ev => {
            if (ev.group_id) {
                const key = String(ev.group_id);
                if (!groupIndex.has(key)) {
                    const row = { groupId: ev.group_id, groupTitle: ev.group_title, events: [] };
                    groupIndex.set(key, row);
                    rows.push(row);
                }
                groupIndex.get(key).events.push(ev);
            } else {
                // All standalone (ungrouped) events share ONE linear row, same as before —
                // only actual event groups (folders like "Uweek Day 1") get their own row.
                if (!standaloneRow) {
                    standaloneRow = { groupId: null, groupTitle: null, events: [] };
                    rows.push(standaloneRow);
                }
                standaloneRow.events.push(ev);
            }
        });

        return rows;
    }

    // One status pill: colour + icon + WORD (never colour alone).
    function statusPillHtml(status, globallyLocked) {
        if (status === 'excused')  return '<span class="att-pill att-excused"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Excused</span>';
        if (status === 'no_class') return '<span class="att-pill att-no-class"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i> No Class</span>';
        if (status === 'present')  return '<span class="att-pill att-present"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Present</span>';
        // No record yet and the admin has locked the whole dashboard (scans still being
        // uploaded): say "To Be Posted" instead of a false Absent.
        if (globallyLocked)        return '<span class="att-pill att-processing"><i class="fa-solid fa-lock" aria-hidden="true"></i> To Be Posted</span>';
        return '<span class="att-pill att-absent"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Absent</span>';
    }

    // Renders one block (a group of sub-sessions, or the standalone events) as a
    // vertical list: event name + date on the left, status on the right.
    function renderEventRow(container, row, statusMap, numberPrefix, counterRef, globallyLocked) {
        if (row.groupTitle) {
            container.appendChild(buildGroupLabel(row.groupTitle));
        }

        const list = document.createElement('div');
        list.className = 'att-list';

        row.events.forEach(ev => {
            counterRef.n += 1;
            // Show the real event name, not "Event 15". Sub-sessions keep their
            // own label (AM Login...) with the group title as a second line.
            const label = ev.sub_label ? ev.sub_label : (ev.title || `${numberPrefix} ${counterRef.n}`);
            const showTitle = !!ev.sub_label && ev.title && ev.title !== ev.sub_label;
            const status = statusMap.get(String(ev.id)); // 'present' | 'excused' | 'no_class' | undefined (absent)

            const item = document.createElement('div');
            item.className = 'att-item';
            item.innerHTML = `
                <div class="att-item-main">
                    <span class="att-event-label">${escapeHtmlLocal(label)}</span>
                    <span class="att-event-date">${escapeHtmlLocal(formatDate(ev.event_date))}${showTitle ? ' · ' + escapeHtmlLocal(ev.title) : ''}</span>
                </div>
                ${statusPillHtml(status, globallyLocked)}`;
            list.appendChild(item);
        });

        container.appendChild(list);
    }

    // Renders every row (grouped or standalone) for a section, one row per
    // event / folder, instead of cramming everything into a single line.
    function buildAttendanceSection(container, eventList, statusMap, numberPrefix, globallyLocked) {
        const rows = groupEventsIntoRows(eventList);
        const counter = { n: 0 };
        rows.forEach(row => renderEventRow(container, row, statusMap, numberPrefix, counter, globallyLocked));
    }

    function renderAttendance(container, result) {
        const events = Array.isArray(result.events) ? result.events : [];
        const statusMap = new Map(Object.entries(result.attendance_status || {}));
        const globallyLocked = !!result.attendance_locked;

        if (!events.length) {
            container.innerHTML = `
                <div class="attendance-empty">
                    <i class="fa-solid fa-calendar-xmark" style="font-size:2rem;color:#cbd5e1;margin-bottom:8px;"></i>
                    <p style="color:#94a3b8;font-size:0.875rem;margin:0;">No events scheduled yet. Check back after an admin creates one.</p>
                </div>`;
            return;
        }

        const flags = events.filter(e => e.is_flagged);
        const regular = events.filter(e => !e.is_flagged);
        // "present", "excused", and "no_class" all count as accounted-for in
        // the summary ratio — an excuse or a no-class mark means the absence
        // isn't held against the student.
        const flagPresent = flags.filter(e => statusMap.has(String(e.id))).length;
        const regularPresent = regular.filter(e => statusMap.has(String(e.id))).length;

        container.innerHTML = '';

        const anyUnrecorded = events.some(e => !statusMap.has(String(e.id)));
        if (globallyLocked && anyUnrecorded) {
            const banner = document.createElement('div');
            banner.className = 'att-locked-banner';
            banner.innerHTML = `
                <i class="fa-solid fa-lock"></i>
                <span>Attendance is still being processed right now. It'll update here once scanning is uploaded — no need to keep refreshing.</span>
            `;
            container.appendChild(banner);
        }

        // Three plain-language tiles instead of two fractions.
        const presentCount = events.filter(e => statusMap.get(String(e.id)) === 'present').length;
        const excusedCount = events.filter(e => ['excused', 'no_class'].includes(statusMap.get(String(e.id)))).length;
        const missingCount = events.filter(e => !statusMap.has(String(e.id))).length;

        const tiles = document.createElement('div');
        tiles.className = 'att-tiles';
        tiles.setAttribute('role', 'group');
        tiles.setAttribute('aria-label', 'Attendance summary');
        tiles.innerHTML = `
            <div class="att-tile"><div class="att-tile-label"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Present</div><div class="att-tile-value">${presentCount}</div></div>
            <div class="att-tile"><div class="att-tile-label"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Excused / No class</div><div class="att-tile-value">${excusedCount}</div></div>
            <div class="att-tile"><div class="att-tile-label"><i class="fa-solid ${globallyLocked ? 'fa-lock' : 'fa-circle-xmark'}" aria-hidden="true"></i> ${globallyLocked ? 'To be posted' : 'Absent'}</div><div class="att-tile-value">${missingCount}</div></div>`;
        container.appendChild(tiles);

        if (missingCount > 0 && !globallyLocked) {
            const help = document.createElement('div');
            help.className = 'att-help';
            help.innerHTML = '<i class="fa-solid fa-circle-question" aria-hidden="true"></i> Scanned but it says Absent? Tap <strong>Refresh</strong> first. If it is still missing, tell an officer your <strong>Student ID</strong> and the <strong>event name</strong> so they can check it.';
            container.appendChild(help);
        }

        if (flags.length > 0) {
            container.appendChild(buildSectionLabel(`Flag Ceremonies · ${flagPresent} of ${flags.length} accounted for`, 'fa-flag'));
            buildAttendanceSection(container, flags, statusMap, 'Flag', globallyLocked);
        }

        if (regular.length > 0) {
            container.appendChild(buildSectionLabel(`Institutional Events · ${regularPresent} of ${regular.length} accounted for`, 'fa-calendar-days'));
            buildAttendanceSection(container, regular, statusMap, 'Event', globallyLocked);
        }
    }

    function renderStudentQR(qrDataUrl) {
        const emptyState = document.getElementById('qrEmptyState');
        const content = document.getElementById('qrContent');
        const preview = document.getElementById('qrPreviewImage');
        const editButton = document.getElementById('editQrButton');

        const openCusg = document.getElementById('openCusgButton');
        const openCusgEmpty = document.getElementById('openCusgEmptyButton');
        if (!emptyState || !content || !preview || !editButton) return;

        if (qrDataUrl) {
            emptyState.style.display = 'none';
            content.style.display = 'block';
            preview.src = qrDataUrl;
            const fsImg = document.getElementById('qrFsImage');
            if (fsImg) fsImg.src = qrDataUrl;
            const dl = document.getElementById('downloadQrLink');
            if (dl) {
                dl.href = qrDataUrl;
                dl.download = `${String(currentUser.student_id || 'CUSG').replace(/[^A-Za-z0-9_-]/g, '_')}_QR_ID.png`;
            }
            editButton.onclick = () => regenerateQrFromButton(editButton);
            if (openCusg) {
                openCusg.onclick = () => { window.location.href = 'cusg.html'; };
            }
        } else {
            emptyState.style.display = 'flex';
            content.style.display = 'none';
            if (openCusgEmpty) {
                openCusgEmpty.onclick = () => regenerateQrFromButton(openCusgEmpty);
            }
        }
    }

    async function loadStudentQR(opts) {
        try {
            const response = await fetch(`../api/student/qr.php?student_id=${encodeURIComponent(currentUser.student_id)}`, {
                headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();
            if (response.ok && result.success && result.qr_code_image) {
                renderStudentQR(result.qr_code_image);
                if (!(opts && opts.skipSync)) syncQrCardIfStale();
                return true;
            }
            renderStudentQR(null);
            return false;
        } catch (err) {
            console.error('QR load failed:', err);
            renderStudentQR(null);
            return false;
        }
    }

    // ── "Updated 4:17 PM" label + inline error line ──
    function markUpdated() {
        const el = document.getElementById('attLastUpdated');
        if (el) el.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        lastRefreshAt = Date.now();
    }

    function setAttendanceMessage(text) {
        const el = document.getElementById('attMsg');
        if (!el) return;
        el.textContent = text || '';
        el.style.display = text ? 'block' : 'none';
    }

    async function refreshAttendance(force = false) {
        const container = document.getElementById('attendanceGrid');
        if (!container) return;

        // The big spinner only shows on the first load; later refreshes keep the
        // current list on screen and only the button spins.
        if (force) {
            container.innerHTML = `
                <p style="color:#94a3b8;font-size:0.85rem;padding:12px 0;">
                    <i class="fa-solid fa-spinner fa-spin"></i> Loading your attendance…
                </p>`;
        }

        try {
            const response = await fetch(`../api/student/attendance.php?student_id=${encodeURIComponent(currentUser.student_id)}`, {
                headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to load attendance.');
            }

            setAttendanceMessage('');
            markUpdated();

            const nextState = JSON.stringify({
                events: result.events || [],
                attendance: result.attendance || [],
                attendance_status: result.attendance_status || {},
                attendance_locked: !!result.attendance_locked
            });

            if (!force && nextState === lastAttendanceState) {
                return; // nothing changed - leave the rendered list alone
            }

            lastAttendanceState = nextState;
            renderAttendance(container, result);
        } catch (err) {
            console.error('Attendance load error:', err);
            if (!lastAttendanceState) {
                container.innerHTML = '<div class="attendance-empty"><i class="fa-solid fa-wifi" style="font-size:2rem;color:#cbd5e1;margin-bottom:8px;"></i><p style="color:#64748b;font-size:0.9rem;margin:0;">We could not load your attendance. Check your connection, then tap Refresh.</p></div>';
            }
            setAttendanceMessage('Could not refresh just now. What you see may be out of date - check your connection and tap Refresh.');
            const el = document.getElementById('attLastUpdated');
            if (el && !lastRefreshAt) el.textContent = 'Not loaded yet';
        }
    }

    // Button handler: spins the button, keeps the list on screen.
    window.refreshAttendanceNow = async function () {
        const btn = document.getElementById('attRefreshBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-rotate fa-spin" aria-hidden="true"></i> Refreshing…'; }
        try { await refreshAttendance(false); }
        finally { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh'; } }
    };

    // Coming back to the tab after a minute or more: refresh once, so students are not
    // looking at an old screen (the 1-second poll stays off to spare the free host).
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && lastRefreshAt && Date.now() - lastRefreshAt > 60000) refreshAttendance(false);
    });

    // After a profile change the saved QR card picture still shows the OLD section / name / course.
    // Redraw it with the shared card design and save it. Returns true (done), false (tried and failed),
    // or null (student has no saved card yet, or the card drawing code is not available: nothing to refresh).
    function qrSigKey() { return `cusg_qr_sig_v1_${currentUser.student_id}`; }
    function qrFailKey() { return `cusg_qr_fail_${currentUser.student_id}`; }
    function profileSignature() {
        const u = currentUser;
        return [u.student_id, u.last_name, u.first_name, u.middle_name || '', u.course_program, u.year_level, u.section, u.profile_picture_url || ''].join('|');
    }
    function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function lsSet(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* ignore */ } }

    function setQrStatus(text, kind) {
        const el = document.getElementById('qrStatus');
        if (!el) return;
        el.textContent = text || '';
        el.className = 'att-msg' + (kind ? ' ' + kind : '');
        el.style.display = text ? 'block' : 'none';
    }

    let qrBusy = false;
    // Draws a fresh QR ID card from the current profile and saves it. Returns true/false.
    async function regenerateQrCard() {
        if (qrBusy) return false;
        qrBusy = true;
        try {
            if (typeof CusgQrCard === 'undefined' || typeof QRCode === 'undefined') return false;
            const sig = profileSignature();
            const canvas = await CusgQrCard.render({
                id: currentUser.student_id, last: currentUser.last_name, first: currentUser.first_name, middle: currentUser.middle_name || '',
                course: currentUser.course_program, year: currentUser.year_level, section: currentUser.section,
                photoUrl: currentUser.profile_picture_url || null
            });
            const response = await fetch('../api/student/qr.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ student_id: currentUser.student_id, qr_code_image: canvas.toDataURL('image/png', 1.0) })
            });
            const result = await response.json().catch(() => ({}));
            const ok = !!(response.ok && result.success);
            if (ok) { lsSet(qrSigKey(), sig); lsSet(qrFailKey(), null); }
            else { lsSet(qrFailKey(), String(Date.now())); }
            return ok;
        } catch (err) {
            console.warn('QR card regeneration failed:', err);
            lsSet(qrFailKey(), String(Date.now()));
            return false;
        } finally {
            qrBusy = false;
        }
    }

    // Manual button: regenerate in place (also used to create the very first card).
    async function regenerateQrFromButton(btn) {
        const original = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Working...'; }
        setQrStatus('Making your QR ID...', '');
        const ok = await regenerateQrCard();
        if (ok) {
            await loadStudentQR({ skipSync: true });
            setQrStatus('Your QR ID is up to date with your current details.', 'ok');
        } else {
            setQrStatus('Could not make your QR ID. Check your connection and try again.', 'error');
        }
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
    }

    // On page open: if a saved card exists but was made from older details, quietly redo it.
    async function syncQrCardIfStale() {
        if (lsGet(qrSigKey()) === profileSignature()) return;
        const failedAt = Number(lsGet(qrFailKey()) || 0);
        if (failedAt && Date.now() - failedAt < 30 * 60 * 1000) return;
        const ok = await regenerateQrCard();
        if (ok) {
            await loadStudentQR({ skipSync: true });
            setQrStatus('Your QR ID was refreshed to match your latest details.', 'ok');
        }
    }

    // ── QR full screen ("Show to Officer"): white background, picture as large as the screen allows ──
    let qrWakeLock = null;
    window.openQrFullscreen = async function () {
        const fs = document.getElementById('qrFullscreen');
        if (!fs) return;
        fs.classList.add('active');
        try { if (navigator.wakeLock) qrWakeLock = await navigator.wakeLock.request('screen'); } catch (_) { /* optional nicety */ }
    };
    window.closeQrFullscreen = function () {
        const fs = document.getElementById('qrFullscreen');
        if (fs) fs.classList.remove('active');
        try { if (qrWakeLock) { qrWakeLock.release(); qrWakeLock = null; } } catch (_) {}
    };
    document.addEventListener('keydown', e => { if (e.key === 'Escape') window.closeQrFullscreen(); });

    function startAttendancePolling() {
        if (attendanceTimer) window.clearInterval(attendanceTimer);
        refreshAttendance(true);
     //   attendanceTimer = window.setInterval(() => refreshAttendance(false), POLL_INTERVAL_MS);
    }

    async function handleUpdateProfile(event) {
        event.preventDefault();

        const newStudentId = document.getElementById('editStudentId').value.trim().toUpperCase();
        const lastName = document.getElementById('editLastName').value.trim().toUpperCase();
        const firstName = document.getElementById('editFirstName').value.trim().toUpperCase();
        const middleName = document.getElementById('editMiddleName').value.trim().toUpperCase();
        const fullName = `${lastName}, ${firstName}${middleName ? ' ' + middleName : ''}`;
        const courseEl = document.getElementById('editCourseProgram');
        const yearEl = document.getElementById('editYearLevel');
        const sectionEl = document.getElementById('editSection');
        const email = document.getElementById('editEmail').value.trim().toLowerCase();
        const profileFileInput = document.getElementById('profileImageFileInput');

        const course = courseEl.value; // fixed canonical value from the dropdown — do not alter casing
        const yearStored = yearEl.value; // e.g. "1st Year" — matches what registration stores
        const section = sectionEl ? sectionEl.value : '';

        if (!newStudentId || !lastName || !firstName || !course || !yearStored || !section || !email) {
            alert('Please fill in all required fields.');
            return;
        }

        const saveBtn = document.querySelector('#editProfileForm .btn-save');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        }

        try {
            // Photo upload is a real multipart request to its own endpoint —
            // same "validate -> move_uploaded_file -> save path" pattern used
            // for excuse letters, kept separate from the plain-JSON text fields
            // below.
            const newPhotoFile = profileFileInput && profileFileInput.files && profileFileInput.files[0];
            let profilePictureUrl = null;
            if (newPhotoFile) {
                const photoForm = new FormData();
                photoForm.append('student_id', currentUser.student_id);
                photoForm.append('profile_picture', newPhotoFile);

                const photoResponse = await fetch('../api/student/upload_profile_picture.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: photoForm
                });
                const photoResult = await photoResponse.json();
                if (!photoResponse.ok || !photoResult.success) {
                    throw new Error(photoResult.message || 'Unable to update your profile picture.');
                }
                profilePictureUrl = photoResult.path;
            }

            const payload = {
                // `student_id` identifies which existing record to update
                // (the ID this browser is currently signed in as);
                // `new_student_id` is whatever's in the field right now,
                // which may be an actual change. The backend only rewrites
                // the ID if the two differ.
                student_id: currentUser.student_id,
                new_student_id: newStudentId,
                full_name: fullName,
                last_name: lastName,
                first_name: firstName,
                middle_name: middleName,
                course_program: course,
                year_level: yearStored,
                section: section,
                institutional_email: email
            };

            const response = await fetch('../api/student/profile.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to update your profile.');
            }

            // The server confirms the (possibly new) student_id here.
            // Every other student-facing endpoint -- QR, attendance,
            // photo upload, password change, delete account, and the QR
            // generator page -- looks the record up by this value, so it
            // has to be kept in sync in localStorage or those calls will
            // start failing against the old, now-renamed ID.
            currentUser.student_id = result.student_id || newStudentId;
            currentUser.full_name = fullName;
            currentUser.last_name = lastName;
            currentUser.first_name = firstName;
            currentUser.middle_name = middleName;
            currentUser.course_program = course;
            currentUser.year_level = yearStored;
            currentUser.section = section;
            currentUser.institutional_email = email;
            if (profilePictureUrl) {
                currentUser.profile_picture_url = profilePictureUrl;
            }
            localStorage.setItem('cusg_current_user', JSON.stringify(currentUser));
            populateHeader(currentUser);
            const cardRefreshed = await regenerateQrCard();
            await loadStudentQR({ skipSync: true });
            alert(cardRefreshed
                ? 'Profile updated successfully!\n\nYour QR ID was updated automatically with your new details.'
                : 'Profile updated successfully!\n\nYour QR ID could not be refreshed right now. Open "My QR ID" and tap "Regenerate QR" to update it.');
        } catch (err) {
            alert('Update failed: ' + err.message);
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Profile Info';
            }
        }
    }

    async function handleChangePassword(event) {
        event.preventDefault();

        const currentPass = document.getElementById('currentPassword').value;
        const newPass = document.getElementById('newPassword').value;
        const confirmPass = document.getElementById('confirmPassword').value;

        if (newPass !== confirmPass) {
            alert('New passwords do not match.');
            return;
        }

        if (newPass.length < 8) {
            alert('New password must be at least 8 characters.');
            return;
        }

        const passBtn = document.querySelector('#changePasswordForm .btn-save');
        if (passBtn) {
            passBtn.disabled = true;
            passBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating…';
        }

        try {
            const response = await fetch('../api/student/password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    student_id: currentUser.student_id,
                    current_password: currentPass,
                    new_password: newPass,
                    confirm_password: confirmPass
                })
            });

            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to change your password.');
            }

            alert('Password changed successfully.');
            document.getElementById('changePasswordForm').reset();
        } catch (err) {
            alert('Password change failed: ' + err.message);
        } finally {
            if (passBtn) {
                passBtn.disabled = false;
                passBtn.innerHTML = '<i class="fa-solid fa-key"></i> Modify System Password';
            }
        }
    }

    // Show password-confirm modal and perform delete after verification
    async function handleDeleteAccount() {
        const modal = document.getElementById('deleteModal');
        const pwInput = document.getElementById('deletePasswordInput');
        const errEl = document.getElementById('deleteError');
        if (!modal || !pwInput) return;
        errEl.style.display = 'none';
        pwInput.value = '';
        modal.classList.add('active');

        const cancelBtn = document.getElementById('cancelDeleteBtn');
        const confirmBtn = document.getElementById('confirmDeleteBtn');

        function closeModal() {
            modal.classList.remove('active');
            pwInput.value = '';
            errEl.style.display = 'none';
        }

        cancelBtn && cancelBtn.addEventListener('click', closeModal, { once: true });

        confirmBtn && confirmBtn.addEventListener('click', async () => {
            const pwd = pwInput.value || '';
            if (!pwd) {
                errEl.textContent = 'Please enter your current password to confirm.';
                errEl.style.display = 'block';
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Deleting…';

            try {
                const response = await fetch('../api/student/delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ student_id: currentUser.student_id, password: pwd })
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to delete account.');
                }

                // clear client state and redirect to landing
                localStorage.removeItem('cusg_current_user');
                alert('Account deleted successfully. You will be redirected.');
                window.location.href = 'index.html';
            } catch (err) {
                errEl.textContent = 'Delete failed: ' + err.message;
                errEl.style.display = 'block';
            } finally {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm Delete';
            }
        }, { once: true });
    }

    window.handleLogoutRoute = function () {
        localStorage.removeItem('cusg_current_user');
        window.location.href = 'index.html';
    };

    document.addEventListener('DOMContentLoaded', () => {
        populateHeader(currentUser);
        populateSettings(currentUser);
        startAttendancePolling();

        ['editStudentId', 'editLastName', 'editFirstName', 'editMiddleName'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.style.textTransform = 'uppercase';
            el.addEventListener('input', () => {
                const pos = el.selectionStart;
                el.value = el.value.toUpperCase();
                el.setSelectionRange(pos, pos);
            });
        });

        const profileForm = document.getElementById('editProfileForm');
        if (profileForm) {
            profileForm.onsubmit = null;
            profileForm.addEventListener('submit', handleUpdateProfile);
        }

        const passForm = document.getElementById('changePasswordForm');
        if (passForm) {
            passForm.onsubmit = null;
            passForm.addEventListener('submit', handleChangePassword);
        }

        const delBtn = document.getElementById('deleteAccountBtn');
        if (delBtn) {
            delBtn.addEventListener('click', handleDeleteAccount);
        }

        loadStudentQR();
    });

})();