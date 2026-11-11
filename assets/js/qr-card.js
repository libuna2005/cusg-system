/**
 * qr-card.js - the ONE place that draws a student's QR ID card.
 *
 * Used by views/cusg.html (a student generating their own card) and by the
 * admin "Regenerate all QR ID cards" tool, so both always produce the same design.
 *
 * What is encoded in the QR is NOT changed (old printed/saved cards keep working):
 *   student_id,last,first,middle,course,year,section   (ASCII only)
 *
 * Scan-reliability rules this design follows on purpose:
 *   - pure black modules on a pure white panel
 *   - quiet zone (white margin) of at least 4 modules on every side (QR spec minimum)
 *   - error-correction level M (37x37 grid for a typical ID, bigger squares than level H)
 *   - nothing is drawn over, or touching, the quiet zone
 *
 * Requires the qrcodejs library (global QRCode) to be loaded before render() is called.
 */
(function (global) {
    'use strict';

    const WIDTH = 400;
    const HEIGHT = 720;
    const SCALE = 3;                 // 1200 x 2160 px output, crisp when printed or zoomed
    const QR_SIZE = 252;             // logical px; 37 modules => ~6.8 px per module
    const STRIPE_TOP = 14;           // blue/green/orange line under the header
    const STRIPE_GAP = 5;            // white gap so the blue third does not melt into the navy header
    const STRIPE_BOTTOM = 26;        // same line at the bottom edge
    const QUIET_ZONE = 32;           // >= 4 modules at 6.8 px (27 px)
    const NAVY = '#1a3a8f', GREEN = '#16a34a', ORANGE = '#f97316';
    const INK = '#0f172a', MUTED = '#64748b';
    const LOGO_URL = '../assets/cusg_logo.jpg';

    // qrcodejs cannot encode multi-byte UTF-8 reliably, so the QR text is ASCII only.
    // (The name printed on the card itself keeps its accents.)
    function sanitizeForQR(str) {
        return String(str == null ? '' : str)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^\x00-\x7F]/g, '');
    }

    function capitalizeWords(s) {
        return s ? String(s).split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ') : '';
    }

    function buildPayload(d) {
        return sanitizeForQR([d.id, d.last, d.first, d.middle || '', d.course, d.year, d.section].join(','));
    }

    function loadImage(src) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => reject(new Error('Image failed to load: ' + String(src).slice(0, 80)));
            img.src = src;
        });
    }

    async function ensureFont() {
        try {
            await document.fonts.load('bold 26px Inter');
            await document.fonts.load('600 17px Inter');
        } catch (_) { /* falls back to system sans-serif */ }
    }

    // qrcodejs draws into a <div>; wait until its canvas exists.
    async function makeQrCanvas(text) {
        if (typeof global.QRCode === 'undefined') throw new Error('QR library not loaded.');
        const holder = document.createElement('div');
        new global.QRCode(holder, { text, width: 800, height: 800, correctLevel: global.QRCode.CorrectLevel.M });
        for (let i = 0; i < 40; i++) {
            const canvas = holder.querySelector('canvas');
            if (canvas) return canvas;
            await new Promise(r => setTimeout(r, 25));
        }
        throw new Error('QR code could not be drawn.');
    }

    function roundedRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    function drawTricolorStripe(ctx, y, h) {
        const w = WIDTH / 3;
        ctx.fillStyle = NAVY;   ctx.fillRect(0, y, w, h);
        ctx.fillStyle = GREEN;  ctx.fillRect(w, y, w, h);
        ctx.fillStyle = ORANGE; ctx.fillRect(w * 2, y, w + 1, h);
    }

    function drawPhoto(ctx, img, cx, cy, r, initials) {
        ctx.save();
        ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.clip();
        if (img) {
            const d = r * 2;
            const k = Math.max(d / img.naturalWidth, d / img.naturalHeight);
            const sw = d / k, sh = d / k;
            ctx.drawImage(img, (img.naturalWidth - sw) / 2, (img.naturalHeight - sh) / 2, sw, sh, cx - r, cy - r, d, d);
        } else {
            // No profile photo on file: neutral initials badge instead of a blank hole.
            ctx.fillStyle = '#e2e8f0'; ctx.fillRect(cx - r, cy - r, r * 2, r * 2);
            ctx.fillStyle = NAVY; ctx.textAlign = 'center'; ctx.font = 'bold 44px Inter, sans-serif';
            ctx.fillText(initials, cx, cy + 15);
        }
        ctx.restore();
        ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2);
        ctx.strokeStyle = NAVY; ctx.lineWidth = 4; ctx.stroke();
    }

    function fitText(ctx, text, maxWidth, startSize, weight) {
        let size = startSize;
        ctx.font = `${weight} ${size}px Inter, sans-serif`;
        while (size > 12 && ctx.measureText(text).width > maxWidth) {
            size -= 1;
            ctx.font = `${weight} ${size}px Inter, sans-serif`;
        }
    }

    /**
     * data = { id, last, first, middle, course, year, section, photoUrl? }
     * Resolves to a <canvas> (1200 x 2160). Rejects with a readable Error on failure.
     */
    async function render(data) {
        await ensureFont();

        const [logo, photo] = await Promise.all([
            loadImage(LOGO_URL).catch(() => null),
            data.photoUrl ? loadImage(data.photoUrl).catch(() => null) : Promise.resolve(null),
        ]);
        const qrCanvas = await makeQrCanvas(buildPayload(data));

        const canvas = document.createElement('canvas');
        canvas.width = WIDTH * SCALE;
        canvas.height = HEIGHT * SCALE;
        const ctx = canvas.getContext('2d');
        ctx.scale(SCALE, SCALE);
        ctx.textAlign = 'center';

        ctx.fillStyle = '#FFFFFF'; ctx.fillRect(0, 0, WIDTH, HEIGHT);

        // ── Header ──
        const headerH = 92;
        ctx.fillStyle = NAVY; ctx.fillRect(0, 0, WIDTH, headerH);
        drawTricolorStripe(ctx, headerH + STRIPE_GAP, STRIPE_TOP);
        if (logo) ctx.drawImage(logo, 16, 16, 60, 60);
        ctx.textAlign = 'left';
        ctx.fillStyle = '#FFFFFF'; ctx.font = 'bold 18px Inter, sans-serif'; ctx.fillText('CHMSU - ALIJIS', 92, 36);
        ctx.fillStyle = 'rgba(255,255,255,0.85)'; ctx.font = '600 10px Inter, sans-serif'; ctx.fillText('UNIVERSITY STUDENT GOVERNMENT', 92, 54);
        ctx.fillStyle = '#86efac'; ctx.font = 'bold 10px Inter, sans-serif'; ctx.fillText('QR EVENT ID', 92, 72);
        ctx.textAlign = 'center';

        // ── Identity ──
        const mName = (data.middle || '').trim();
        const fullName = `${capitalizeWords(data.first)} ${mName ? capitalizeWords(mName) + ' ' : ''}${capitalizeWords(data.last)}`.trim();
        const initials = ((data.first || '?').charAt(0) + (data.last || '').charAt(0)).toUpperCase();
        drawPhoto(ctx, photo, 200, 168, 52, initials);

        ctx.fillStyle = INK; fitText(ctx, fullName, 360, 26, 'bold'); ctx.fillText(fullName, 200, 252);
        ctx.fillStyle = '#334155'; ctx.font = '600 16px Inter, sans-serif'; ctx.fillText(String(data.id || '').toUpperCase(), 200, 276);
        ctx.fillStyle = GREEN; ctx.font = 'bold 15px Inter, sans-serif'; ctx.fillText(`${data.course} ${data.year} - ${data.section}`, 200, 298);

        // ── QR panel: white, with a full quiet zone, no decoration touching it ──
        const panel = QR_SIZE + QUIET_ZONE * 2;          // 316
        const px = (WIDTH - panel) / 2, py = 318;
        ctx.fillStyle = '#FFFFFF'; roundedRect(ctx, px, py, panel, panel, 14); ctx.fill();
        ctx.strokeStyle = '#cbd5e1'; ctx.lineWidth = 1.5; roundedRect(ctx, px, py, panel, panel, 14); ctx.stroke();
        ctx.imageSmoothingEnabled = false;               // keep module edges sharp
        ctx.drawImage(qrCanvas, px + QUIET_ZONE, py + QUIET_ZONE, QR_SIZE, QR_SIZE);
        ctx.imageSmoothingEnabled = true;

        // ── Footer ──
        const fy = py + panel + 24;
        ctx.fillStyle = MUTED; ctx.font = '600 11px Inter, sans-serif';
        ctx.fillText('Show this to the officer  -  keep screen brightness high', 200, fy);
        ctx.fillStyle = '#475569'; ctx.font = 'bold 12px Inter, sans-serif';
        ctx.fillText('Valid for 1 Semester', 200, fy + 20);
        drawTricolorStripe(ctx, HEIGHT - STRIPE_BOTTOM, STRIPE_BOTTOM);

        return canvas;
    }

    global.CusgQrCard = { render, buildPayload, sanitizeForQR, WIDTH, HEIGHT };
})(window);
