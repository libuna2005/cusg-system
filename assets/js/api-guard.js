/* v43 - shared "host challenge" guard (loaded first on every page).
   InfinityFree sometimes answers an api/ call with an HTML anti-bot page instead of
   JSON ("Unexpected token '<' ... is not valid JSON"). That page needs a small script
   to run once so the browser gets a cookie. A plain retry never runs it, so the error
   kept coming back. This guard runs that script itself (no page reload, no navigation),
   then repeats the request, which never reached PHP, so repeating it is safe.
   If the server still answers with a web page, a clear message is thrown instead of
   the JSON parse error (the scanner treats a throw as "no connection" and queues). */
(function () {
    if (window.__cusgApiGuard) return;
    window.__cusgApiGuard = true;
    var realFetch = window.fetch.bind(window);
    var solving = null;

    function isChallenge(t) { return /slowAES|aes\.js|__test=|document\.cookie\s*=/.test(t) && !/^\s*[\[{]/.test(t); }
    function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    function solveChallenge(html) {
        if (solving) return solving;
        solving = (async function () {
            try {
                var inline = [], re = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi, m, aesSrc = null;
                while ((m = re.exec(html))) {
                    var src = /src\s*=\s*["']([^"']+)["']/i.exec(m[1]);
                    if (src) { if (/aes/i.test(src[1])) aesSrc = src[1]; }
                    else if (m[2].trim()) inline.push(m[2]);
                }
                var lib = '';
                if (aesSrc) {
                    var r = await realFetch(aesSrc, { credentials: 'same-origin' });
                    lib = await r.text();
                }
                var fakeDoc = { set cookie(v) { document.cookie = v; }, get cookie() { return document.cookie; } };
                var fakeLoc = { href: '', replace: function () {}, assign: function () {}, reload: function () {} };
                // eslint-disable-next-line no-new-func
                new Function('document', 'location', 'window', lib + '\n' + inline.join('\n'))(fakeDoc, fakeLoc, window);
                return true;
            } catch (e) {
                console.warn('Host challenge could not be solved:', e);
                return false;
            } finally {
                setTimeout(function () { solving = null; }, 0);
            }
        })();
        return solving;
    }

    window.fetch = async function (input, init) {
        var url = typeof input === 'string' ? input : ((input && input.url) || '');
        if (url.indexOf('api/') === -1) return realFetch(input, init);
        var res = await realFetch(input, init);
        for (var attempt = 0; attempt < 3; attempt++) {
            if ((res.headers.get('content-type') || '').indexOf('text/html') === -1) return res;
            var body = '';
            try { body = await res.clone().text(); } catch (e) { return res; }
            if (/^\s*[\[{]/.test(body)) return res;
            if (isChallenge(body)) { await solveChallenge(body); await sleep(150); }
            else { await sleep(700 * (attempt + 1)); }
            res = await realFetch(input, init);
        }
        if ((res.headers.get('content-type') || '').indexOf('text/html') !== -1) {
            var last = '';
            try { last = await res.clone().text(); } catch (e) { /* ignore */ }
            if (!/^\s*[\[{]/.test(last)) {
                throw new Error('The server is busy right now (hosting security check). Please wait a few seconds and try again.');
            }
        }
        return res;
    };
})();
