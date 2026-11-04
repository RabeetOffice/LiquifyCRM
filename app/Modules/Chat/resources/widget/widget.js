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
            return { type: 'page', url: location.href.split('#')[0].slice(0, 500), title: (document.title || '').slice(0, 200), referrer: (document.referrer || '').slice(0, 500) };
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
    }
})();
