/*
 * LiquifyCRM live chat: the launcher a website carries in one line.
 *   <script src="https://crm.example/chat/widget.js" data-key="wk_..." async></script>
 * It asks the CRM whether this website may show the chat (the CRM answers only the websites listed for the
 * widget), draws a round button, and opens the chat box in an iframe, so the website's own styles and scripts
 * never touch it. It keeps nothing about the visitor: the chat box does that, on the CRM's own origin.
 */
(function () {
    'use strict';

    var script = document.currentScript;

    if (!script || window.__lqChat) { return; }

    var key = script.getAttribute('data-key');

    if (!key) { return; }

    window.__lqChat = true;

    var base;

    try { base = new URL(script.src).origin; } catch (e) { return; }

    var openKey = 'lq_chat_open_' + key;
    var startedKey = 'lq_chat_started_' + key;
    var vidKey = 'lq_vid_' + key;
    var inviteKey = 'lq_chat_invite_' + key;
    var storage = function (area, name, value) {
        try {
            if (value === undefined) { return window[area].getItem(name); }
            window[area].setItem(name, value);
        } catch (e) { return null; }
    };

    fetch(base + '/chat/api/config?key=' + encodeURIComponent(key) + '&o=' + encodeURIComponent(location.origin), { credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (config) { if (config && config.active) { build(config); } })
        .catch(function () {});

    function build(config) {
        var left = config.position === 'left';
        var phone = window.matchMedia('(max-width: 480px)');
        var frame = null;
        var isOpen = false;
        var unread = 0;
        var ready = false;

        var root = document.createElement('div');
        root.setAttribute('data-lq-chat', '');
        root.style.cssText = 'all:initial;position:fixed;z-index:2147483000;bottom:20px;' + (left ? 'left' : 'right') + ':20px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;';

        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('aria-label', 'Open chat: ' + config.title);
        button.style.cssText = 'all:initial;box-sizing:border-box;position:relative;display:flex;align-items:center;justify-content:center;width:60px;height:60px;border-radius:50%;cursor:pointer;background:' + config.color + ';color:' + config.on + ';box-shadow:0 8px 24px rgba(0,0,0,.22);transition:transform .15s ease;';
        button.innerHTML = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.12 2.99 2.7 3.22 1.1.16 2.2.28 3.3.35.5.03.98.24 1.33.6l2.67 2.67 2.67-2.67c.35-.36.83-.57 1.33-.6 1.1-.07 2.2-.19 3.3-.35 1.58-.23 2.7-1.62 2.7-3.22V6.74c0-1.6-1.12-2.99-2.7-3.22A48.4 48.4 0 0 0 12 3c-2.4 0-4.76.17-7.05.52C3.37 3.75 2.25 5.14 2.25 6.74v6.02Z"/></svg>';
        button.onmouseenter = function () { button.style.transform = 'scale(1.06)'; };
        button.onmouseleave = function () { button.style.transform = ''; };

        var badge = document.createElement('span');
        badge.style.cssText = 'all:initial;position:absolute;top:-4px;' + (left ? 'right' : 'left') + ':-4px;min-width:20px;height:20px;padding:0 5px;box-sizing:border-box;border-radius:10px;background:#e5484d;color:#fff;font:700 12px/20px system-ui,sans-serif;text-align:center;display:none;';
        button.appendChild(badge);
        root.appendChild(button);

        function layout() {
            if (!frame) { return; }

            frame.style.cssText = phone.matches
                ? 'all:initial;position:fixed;inset:0;width:100%;height:100%;border:0;background:#fff;z-index:2147483001;'
                : 'all:initial;position:fixed;bottom:92px;' + (left ? 'left' : 'right') + ':20px;width:380px;height:min(640px,calc(100vh - 112px));border:0;border-radius:16px;background:#fff;box-shadow:0 16px 48px rgba(0,0,0,.28);z-index:2147483001;color-scheme:light;';
            frame.style.display = isOpen ? 'block' : 'none';
        }

        function post(message) {
            if (ready && frame && frame.contentWindow) {
                message.lqChat = true;
                frame.contentWindow.postMessage(message, base);
            }
        }

        function pageInfo() {
            return { type: 'page', vid: storage('localStorage', vidKey), url: location.href.split('#')[0].slice(0, 500), title: (document.title || '').slice(0, 200), referrer: (document.referrer || '').slice(0, 500) };
        }

        // ---- who is on the website (only when the brand switched Live visitors on) -------------------------------
        // The team sees this browser as "on the page /memoirs" while the website is open, with the pages it read. It
        // sends the page's address without its query, the title and where it came from, and nothing else about the
        // page. A random code kept in the website's own storage tells one browser from another.
        function vidValue() {
            var v = storage('localStorage', vidKey);

            if (!v || !/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/.test(v)) {
                v = uuid();
                storage('localStorage', vidKey, v);
            }

            return v;
        }

        function uuid() {
            if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }

            var b = new Uint8Array(16);
            (window.crypto || window.msCrypto).getRandomValues(b);
            b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
            var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');

            return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
        }

        var inviteCard = null;

        function dropInvite() {
            if (inviteCard && inviteCard.parentNode) { inviteCard.parentNode.removeChild(inviteCard); }
            inviteCard = null;
        }

        // The team invited this visitor: a small card by the button; opening it opens the chat, where the words are the first message.
        function invite(data) {
            if (isOpen || inviteCard || storage('sessionStorage', inviteKey) === data.text) { return; }

            storage('sessionStorage', inviteKey, data.text);

            var card = document.createElement('div');
            card.setAttribute('role', 'dialog');
            card.setAttribute('aria-label', 'Message from ' + data.from);
            card.style.cssText = 'all:initial;position:absolute;bottom:74px;' + (left ? 'left' : 'right') + ':0;width:264px;box-sizing:border-box;padding:12px 38px 12px 14px;border-radius:14px;background:#fff;color:#1c1b19;box-shadow:0 10px 30px rgba(0,0,0,.22);font:14px/1.4 system-ui,sans-serif;cursor:pointer;';

            var who = document.createElement('div');
            who.style.cssText = 'all:initial;display:block;font:700 12px/1.3 system-ui,sans-serif;color:' + config.color + ';margin-bottom:2px;';
            who.textContent = data.from;

            var words = document.createElement('div');
            words.style.cssText = 'all:initial;display:block;font:14px/1.4 system-ui,sans-serif;color:#1c1b19;';
            words.textContent = data.text;

            var close = document.createElement('button');
            close.type = 'button';
            close.setAttribute('aria-label', 'Dismiss');
            close.textContent = '×';
            close.style.cssText = 'all:initial;position:absolute;top:6px;right:8px;width:24px;height:24px;text-align:center;font:20px/24px system-ui,sans-serif;color:#8a857d;cursor:pointer;';

            card.appendChild(who); card.appendChild(words); card.appendChild(close);
            card.onclick = function () { dropInvite(); show(true); };
            close.onclick = function (event) { event.stopPropagation(); dropInvite(); };

            inviteCard = card;
            root.insertBefore(card, button);
            unread = 1;
            badge.textContent = '1';
            badge.style.display = 'block';
        }

        function ping() {
            if (document.hidden) { return; }

            var body = JSON.stringify({ key: key, vid: vidValue(), url: location.origin + location.pathname, title: (document.title || '').slice(0, 200), referrer: (document.referrer || '').slice(0, 500) });

            // A plain-text body is a "simple" request: the browser sends it at once, without asking first.
            fetch(base + '/chat/api/ping', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: body, credentials: 'omit', keepalive: true })
                .then(function (response) { return response.status === 200 ? response.json() : null; })
                .then(function (data) { if (data && data.invite) { invite(data.invite); } })
                .catch(function () {});
        }

        function track() {
            var lastPath = location.pathname;
            var soon = null;
            var changed = function () { clearTimeout(soon); soon = setTimeout(function () { if (location.pathname !== lastPath) { lastPath = location.pathname; ping(); } }, 400); };

            ping();
            setInterval(ping, 30000);
            document.addEventListener('visibilitychange', function () { if (!document.hidden) { ping(); } });
            window.addEventListener('popstate', changed);

            // A website that changes pages without loading them again (a single-page site).
            ['pushState', 'replaceState'].forEach(function (name) {
                var original = history[name];

                history[name] = function () { var result = original.apply(this, arguments); changed(); return result; };
            });
        }

        function ensureFrame() {
            if (frame) { return; }

            frame = document.createElement('iframe');
            frame.title = config.title;
            frame.src = config.frame + '?o=' + encodeURIComponent(location.origin);
            frame.setAttribute('allow', 'clipboard-write');
            document.body.appendChild(frame);
            layout();
        }

        function show(open) {
            isOpen = open;
            storage('sessionStorage', openKey, open ? '1' : '0');

            if (open) {
                ensureFrame();
                dropInvite();
                unread = 0;
                badge.style.display = 'none';
            }

            layout();
            post({ type: 'visible', open: open });
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        button.onclick = function () { show(!isOpen); };
        phone.addEventListener ? phone.addEventListener('change', layout) : null;
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && isOpen) { show(false); } });

        window.addEventListener('message', function (event) {
            if (event.origin !== base || !frame || event.source !== frame.contentWindow || !event.data || event.data.lqChat !== true) { return; }

            var data = event.data;

            if (data.type === 'ready') { ready = true; post(pageInfo()); post({ type: 'visible', open: isOpen }); }
            if (data.type === 'close') { show(false); }
            if (data.type === 'started') { storage('localStorage', startedKey, '1'); }
            if (data.type === 'unread' && !isOpen) {
                unread = Math.max(0, parseInt(data.count, 10) || 0);
                badge.textContent = unread > 9 ? '9+' : String(unread);
                badge.style.display = unread > 0 ? 'block' : 'none';
            }
        });

        document.body.appendChild(root);

        // A visitor with a chat under way has it back on the next page, and hears about answers while it is closed.
        if (storage('sessionStorage', openKey) === '1') {
            show(true);
        } else if (storage('localStorage', startedKey) === '1') {
            ensureFrame();
            layout();
        }

        if (config.track) { track(); }
    }
})();
