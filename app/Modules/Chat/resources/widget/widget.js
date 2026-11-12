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
        var side = left ? 'left' : 'right';
        var phone = window.matchMedia('(max-width: 480px)');
        var small = window.matchMedia('(max-width: 768px)').matches || (window.matchMedia('(pointer: coarse)').matches && window.matchMedia('(max-width: 1024px)').matches);

        // The brand chose where the chat shows: on computers only, on phones only, or on both.
        if ((config.show_on === 'desktop' && small) || (config.show_on === 'phone' && !small)) { return; }

        var frame = null;
        var isOpen = false;
        var unread = 0;
        var ready = false;
        var font = '-apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",system-ui,Roboto,sans-serif';
        var ease = 'cubic-bezier(.2,.8,.2,1)';
        var offsetX = Math.max(0, Math.min(160, parseInt(config.offset_x, 10) || 0));
        var offsetY = Math.max(0, Math.min(160, parseInt(config.offset_y, 10) || 0));
        var width = Math.max(320, Math.min(440, parseInt(config.width, 10) || 380));
        var radius = Math.max(0, Math.min(28, parseInt(config.radius, 10) || 16));
        var dark = config.theme === 'dark' || (config.theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        var style = config.style === 'pill' || config.style === 'card' ? config.style : 'bubble';
        var ICON = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.12 2.99 2.7 3.22 1.1.16 2.2.28 3.3.35.5.03.98.24 1.33.6l2.67 2.67 2.67-2.67c.35-.36.83-.57 1.33-.6 1.1-.07 2.2-.19 3.3-.35 1.58-.23 2.7-1.62 2.7-3.22V6.74c0-1.6-1.12-2.99-2.7-3.22A48.4 48.4 0 0 0 12 3c-2.4 0-4.76.17-7.05.52C3.37 3.75 2.25 5.14 2.25 6.74v6.02Z"/></svg>';
        var CHEVRON = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';

        var root = document.createElement('div');
        root.setAttribute('data-lq-chat', '');
        root.style.cssText = 'all:initial;position:fixed;z-index:2147483000;bottom:' + offsetY + 'px;' + side + ':' + offsetX + 'px;font-family:' + font + ';';

        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('aria-label', 'Open chat: ' + config.title);

        function node(tag, css, text) {
            var n = document.createElement(tag);
            n.style.cssText = 'all:initial;box-sizing:border-box;' + css;
            if (text !== undefined) { n.textContent = text; }
            return n;
        }

        // The launcher's face. Round button: just the icon. Pill: the brand's colour with the icon in an accent circle, the
        // words and a line saying whether somebody is here. Card: a light card with the status as a small heading.
        var face = node('span', 'display:flex;align-items:center;gap:12px;');
        var status = config.online ? config.online_sub : config.offline_sub;
        var dot = function (ring) { return node('span', 'position:absolute;right:0;bottom:0;width:12px;height:12px;border-radius:50%;background:' + (config.online ? '#30d158' : '#a1a1a6') + ';box-shadow:0 0 0 2.5px ' + ring + ';'); };

        if (style === 'bubble') {
            var bubbleIcon = node('span', 'display:flex;color:inherit;');
            bubbleIcon.innerHTML = ICON;
            face.appendChild(bubbleIcon);
        } else {
            var circle = node('span', 'position:relative;display:flex;align-items:center;justify-content:center;flex:none;width:44px;height:44px;border-radius:50%;'
                + (style === 'pill' ? 'background:' + config.accent + ';color:' + config.on_accent + ';' : 'background:' + config.color + ';color:' + config.on + ';'));
            circle.innerHTML = ICON;
            circle.appendChild(dot(style === 'pill' ? config.color : '#ffffff'));

            var words = node('span', 'display:flex;flex-direction:column;align-items:flex-start;gap:1px;padding-right:6px;text-align:left;');

            if (style === 'pill') {
                words.appendChild(node('span', 'font:650 15px/1.2 ' + font + ';letter-spacing:-.01em;color:' + config.on + ';white-space:nowrap;', config.label));
                if (status) { words.appendChild(node('span', 'font:400 12px/1.3 ' + font + ';color:' + config.on + ';opacity:.78;white-space:nowrap;', status)); }
            } else {
                if (status) { words.appendChild(node('span', 'font:600 10px/1.3 ' + font + ';letter-spacing:.12em;text-transform:uppercase;color:#6e6e73;white-space:nowrap;', status)); }
                words.appendChild(node('span', 'font:650 15px/1.25 ' + font + ';letter-spacing:-.01em;color:#1d1d1f;white-space:nowrap;', config.label));
            }

            face.appendChild(circle);
            face.appendChild(words);
        }

        var closer = node('span', 'display:none;color:inherit;');
        closer.innerHTML = CHEVRON;
        button.appendChild(face);
        button.appendChild(closer);

        var shadow = '0 10px 30px rgba(0,0,0,.18),0 2px 6px rgba(0,0,0,.08)';
        var shadowUp = '0 14px 36px rgba(0,0,0,.22),0 3px 8px rgba(0,0,0,.1)';

        function paint() {
            var round = isOpen || style === 'bubble';
            var base = 'all:initial;box-sizing:border-box;position:relative;display:flex;align-items:center;justify-content:center;cursor:pointer;font-family:' + font + ';transition:transform .25s ' + ease + ',box-shadow .25s ' + ease + ',width .3s ' + ease + ';-webkit-tap-highlight-color:transparent;';

            face.style.display = isOpen ? 'none' : 'flex';
            closer.style.display = isOpen ? 'flex' : 'none';

            if (round) {
                button.style.cssText = base + 'width:60px;height:60px;border-radius:50%;background:' + config.color + ';color:' + config.on + ';box-shadow:' + shadow + ';';
            } else if (style === 'pill') {
                button.style.cssText = base + 'height:60px;padding:8px 18px 8px 8px;border-radius:30px;background:' + config.color + ';color:' + config.on + ';box-shadow:' + shadow + ';';
            } else {
                button.style.cssText = base + 'height:62px;padding:8px 18px 8px 9px;border-radius:20px;background:rgba(255,255,255,.92);-webkit-backdrop-filter:saturate(180%) blur(20px);backdrop-filter:saturate(180%) blur(20px);color:#1d1d1f;box-shadow:' + shadow + ',inset 0 0 0 1px rgba(0,0,0,.06);';
            }

            button.appendChild(badge);
        }

        button.onmouseenter = function () { button.style.transform = 'translateY(-2px)'; button.style.boxShadow = shadowUp; };
        button.onmouseleave = function () { button.style.transform = ''; button.style.boxShadow = shadow; };
        button.onmousedown = function () { button.style.transform = 'scale(.97)'; };
        button.onmouseup = function () { button.style.transform = 'translateY(-2px)'; };

        var badge = node('span', 'position:absolute;top:-4px;' + (left ? 'right' : 'left') + ':-4px;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:#ff3b30;color:#fff;font:700 12px/20px ' + font + ';text-align:center;display:none;box-shadow:0 0 0 2px #fff;');
        paint();
        root.appendChild(button);

        var hideTimer = null;

        function layout() {
            if (!frame) { return; }

            var bg = dark ? '#1c1c1e' : '#ffffff';
            var bottom = offsetY + 60 + 14;
            var place = phone.matches
                ? 'position:fixed;inset:0;width:100%;height:100%;border-radius:0;'
                : 'position:fixed;bottom:' + bottom + 'px;' + side + ':' + offsetX + 'px;width:' + width + 'px;max-width:calc(100vw - 24px);height:min(680px,calc(100vh - ' + (bottom + 20) + 'px));border-radius:' + radius + 'px;box-shadow:0 24px 64px rgba(0,0,0,.24),0 4px 16px rgba(0,0,0,.08);';
            var shown = isOpen ? 'opacity:1;transform:none;' : 'opacity:0;transform:translateY(16px) scale(.96);pointer-events:none;';

            frame.style.cssText = 'all:initial;' + place + 'border:0;background:' + bg + ';z-index:2147483001;color-scheme:' + (dark ? 'dark' : 'light') + ';transform-origin:bottom ' + side + ';transition:opacity .22s ease,transform .32s ' + ease + ';' + shown;
            frame.style.display = frame.getAttribute('data-shown') === '1' ? 'block' : 'none';
        }

        function reveal(open) {
            if (!frame) { return; }

            clearTimeout(hideTimer);

            if (open) {
                frame.setAttribute('data-shown', '1');
                isOpen = false; layout(); isOpen = true;
                // One frame later, so the browser animates from the closed look.
                requestAnimationFrame(function () { requestAnimationFrame(layout); });
            } else {
                layout();
                hideTimer = setTimeout(function () { if (!isOpen) { frame.setAttribute('data-shown', '0'); layout(); } }, 320);
            }
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
            card.style.cssText = 'all:initial;position:absolute;bottom:76px;' + side + ':0;width:272px;box-sizing:border-box;padding:13px 40px 13px 15px;border-radius:18px;background:rgba(255,255,255,.96);-webkit-backdrop-filter:saturate(180%) blur(20px);backdrop-filter:saturate(180%) blur(20px);color:#1d1d1f;box-shadow:0 14px 40px rgba(0,0,0,.18),inset 0 0 0 1px rgba(0,0,0,.05);font:14px/1.4 ' + font + ';cursor:pointer;opacity:0;transform:translateY(8px) scale(.97);transform-origin:bottom ' + side + ';transition:opacity .25s ease,transform .35s ' + ease + ';';
            setTimeout(function () { card.style.opacity = '1'; card.style.transform = 'none'; }, 20);

            var who = document.createElement('div');
            who.style.cssText = 'all:initial;display:block;font:650 12px/1.3 ' + font + ';color:#6e6e73;margin-bottom:3px;';
            who.textContent = data.from;

            var words = document.createElement('div');
            words.style.cssText = 'all:initial;display:block;font:14px/1.4 ' + font + ';letter-spacing:-.01em;color:#1d1d1f;';
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
            frame.setAttribute('allow', 'clipboard-write; autoplay');
            frame.setAttribute('data-shown', '0');
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

            paint();
            reveal(open);
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

        // A greeting by the button after a few seconds, once a visit, for a visitor who has not written yet.
        var greetAfter = Math.max(0, Math.min(120, parseInt(config.greet_after, 10) || 0));
        var greetKey = 'lq_chat_greeted_' + key;

        if (greetAfter > 0 && config.welcome && storage('localStorage', startedKey) !== '1' && storage('sessionStorage', greetKey) !== '1') {
            setTimeout(function () {
                if (isOpen) { return; }
                storage('sessionStorage', greetKey, '1');
                invite({ text: config.welcome, from: config.title });
            }, greetAfter * 1000);
        }
    }
})();
