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

    var through = null;

    try {
        var source = new URL(script.src);
        base = source.origin;
        // Served by liquify-chat.php on the brand's own website: the chat's calls go through it, never to LiquifyCRM directly.
        if (/\.php$/.test(source.pathname)) { through = source.origin + source.pathname; }
    } catch (e) { return; }

    function chatUrl(path, query) {
        return through ? through + '?p=' + path + (query ? '&' + query : '') : base + '/chat/' + path + (query ? '?' + query : '');
    }

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

    fetch(chatUrl('api/config', 'key=' + encodeURIComponent(key) + '&o=' + encodeURIComponent(location.origin)), { credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (config) { if (config && config.active) { build(config); } })
        .catch(function () {});

    function build(config) {
        var left = config.position === 'left';
        var side = left ? 'left' : 'right';
        var phone = window.matchMedia('(max-width: 480px)');
        var small = window.matchMedia('(max-width: 768px)').matches || (window.matchMedia('(pointer: coarse)').matches && window.matchMedia('(max-width: 1024px)').matches);

        // The brand chose where the chat shows: on computers only, on phones only, or on both; and maybe not while away.
        if ((config.show_on === 'desktop' && small) || (config.show_on === 'phone' && !small)) { return; }
        if (config.away_mode === 'hide' && !config.online && storage('localStorage', 'lq_chat_started_' + key) !== '1') { return; }

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
        var STYLES = ['bubble', 'pill', 'glass', 'card', 'square', 'outline', 'bar', 'tab'];
        var style = small && STYLES.indexOf(config.style_phone) >= 0 ? config.style_phone : config.style;
        if (STYLES.indexOf(style) < 0) { style = 'bubble'; }
        var ICONS = {
            chat: 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z',
            chats: 'M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155',
            question: 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z',
            sparkles: 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z',
            hand: 'M10.05 4.575a1.575 1.575 0 1 0-3.15 0v3m3.15-3v-1.5a1.575 1.575 0 0 1 3.15 0v1.5m-3.15 0 .075 5.925m3.075.75V4.575m0 0a1.575 1.575 0 0 1 3.15 0V15M6.9 7.575a1.575 1.575 0 1 0-3.15 0v8.175a6.75 6.75 0 0 0 6.75 6.75h2.018a5.25 5.25 0 0 0 3.712-1.538l1.732-1.732a5.25 5.25 0 0 0 1.538-3.712l.003-2.024a.668.668 0 0 1 .198-.471 1.575 1.575 0 1 0-2.228-2.228 3.818 3.818 0 0 0-1.12 2.687M6.9 7.575V12m6.27 4.318A4.49 4.49 0 0 1 16.35 15m.002 0h-.002',
            envelope: 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75'
        };
        var iconPath = ICONS[config.icon] || ICONS.chat;
        var svg = function (path, size, stroke) { return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + stroke + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + path + '"/></svg>'; };
        var ICON = svg(iconPath, 24, 1.7);
        var CHEVRON = svg('M6 9l6 6 6-6', 24, 2.2);
        var status = config.online ? config.online_sub : config.offline_sub;
        var dotColour = config.online ? '#30d158' : '#a1a1a6';

        // A moving touch for the button (once in a while, never while open, never for people who asked for less motion).
        var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!calm && config.effect && config.effect !== 'none' && !document.getElementById('lq-chat-motion')) {
            var motion = document.createElement('style');
            motion.id = 'lq-chat-motion';
            motion.textContent = '@keyframes lqchat-pulse{0%{box-shadow:0 0 0 0 var(--lqchat-ring)}70%{box-shadow:0 0 0 16px transparent}100%{box-shadow:0 0 0 0 transparent}}'
                + '@keyframes lqchat-bounce{0%,100%{transform:translateY(0)}30%{transform:translateY(-10px)}50%{transform:translateY(0)}65%{transform:translateY(-4px)}}'
                + '@keyframes lqchat-wiggle{0%,100%{transform:rotate(0)}20%{transform:rotate(-8deg)}40%{transform:rotate(7deg)}60%{transform:rotate(-5deg)}80%{transform:rotate(3deg)}}';
            document.head.appendChild(motion);
        }

        var root = document.createElement('div');
        root.setAttribute('data-lq-chat', '');
        root.style.cssText = style === 'tab'
            ? 'all:initial;position:fixed;z-index:2147483000;top:50%;' + side + ':0;transform:translateY(-50%);font-family:' + font + ';'
            : 'all:initial;position:fixed;z-index:2147483000;bottom:' + (style === 'bar' ? 0 : offsetY) + 'px;' + side + ':' + offsetX + 'px;font-family:' + font + ';';

        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('aria-label', 'Open chat: ' + config.title);

        function node(tag, css, text) {
            var n = document.createElement(tag);
            n.style.cssText = 'all:initial;box-sizing:border-box;' + css;
            if (text !== undefined) { n.textContent = text; }
            return n;
        }

        function iconNode(css) {
            var n = node('span', 'display:flex;align-items:center;justify-content:center;color:inherit;' + (css || ''));
            n.innerHTML = ICON;
            return n;
        }

        var dot = function (ring) { return node('span', 'position:absolute;right:0;bottom:0;width:12px;height:12px;border-radius:50%;background:' + dotColour + ';box-shadow:0 0 0 2.5px ' + ring + ';'); };

        // The button's face, by style. Words go in as text only.
        var face = node('span', 'display:flex;align-items:center;gap:12px;');

        if (style === 'bubble' || style === 'square') {
            face.appendChild(iconNode());
        } else if (style === 'pill' || style === 'card' || style === 'glass') {
            var circle = iconNode('position:relative;flex:none;width:44px;height:44px;border-radius:50%;'
                + (style === 'pill' ? 'background:' + config.accent + ';color:' + config.on_accent + ';' : 'background:' + config.color + ';color:' + config.on + ';'));
            circle.appendChild(dot(style === 'pill' ? config.color : style === 'glass' ? 'rgba(255,255,255,.9)' : '#ffffff'));
            var words = node('span', 'display:flex;flex-direction:column;align-items:flex-start;gap:1px;padding-right:6px;text-align:left;');

            if (style === 'glass') {
                words.appendChild(node('span', 'font:650 15px/1.2 ' + font + ';letter-spacing:-.01em;color:#1d1d1f;white-space:nowrap;', config.label));
                if (status) { words.appendChild(node('span', 'font:500 12px/1.3 ' + font + ';color:#3a3a3c;opacity:.8;white-space:nowrap;', status)); }
            } else if (style === 'pill') {
                words.appendChild(node('span', 'font:650 15px/1.2 ' + font + ';letter-spacing:-.01em;color:' + config.on + ';white-space:nowrap;', config.label));
                if (status) { words.appendChild(node('span', 'font:400 12px/1.3 ' + font + ';color:' + config.on + ';opacity:.78;white-space:nowrap;', status)); }
            } else {
                if (status) { words.appendChild(node('span', 'font:600 10px/1.3 ' + font + ';letter-spacing:.12em;text-transform:uppercase;color:#6e6e73;white-space:nowrap;', status)); }
                words.appendChild(node('span', 'font:650 15px/1.25 ' + font + ';letter-spacing:-.01em;color:#1d1d1f;white-space:nowrap;', config.label));
            }

            face.appendChild(circle);
            face.appendChild(words);
        } else if (style === 'outline') {
            face.appendChild(iconNode('color:' + config.color + ';'));
            face.appendChild(node('span', 'font:650 15px/1.2 ' + font + ';letter-spacing:-.01em;color:' + config.color + ';white-space:nowrap;padding-right:4px;', config.label));
        } else if (style === 'bar') {
            var barDot = node('span', 'flex:none;width:9px;height:9px;border-radius:50%;background:' + dotColour + ';box-shadow:0 0 0 2px rgba(255,255,255,.35);');
            var barWords = node('span', 'flex:1;display:flex;flex-direction:column;align-items:flex-start;text-align:left;');
            barWords.appendChild(node('span', 'font:650 15px/1.2 ' + font + ';color:' + config.on + ';white-space:nowrap;', config.label));
            if (status) { barWords.appendChild(node('span', 'font:400 12px/1.3 ' + font + ';color:' + config.on + ';opacity:.78;white-space:nowrap;', status)); }
            var up = node('span', 'display:flex;color:' + config.on + ';transform:rotate(180deg);');
            up.innerHTML = CHEVRON;
            face.style.width = '100%';
            face.appendChild(barDot); face.appendChild(barWords); face.appendChild(up);
        } else {
            // Side tab: the words run up the edge of the page.
            face.style.cssText += 'flex-direction:column;gap:10px;';
            face.appendChild(iconNode('transform:rotate(' + (left ? 90 : -90) + 'deg);'));
            face.appendChild(node('span', 'writing-mode:vertical-rl;' + (left ? '' : 'transform:rotate(180deg);') + 'font:650 14px/1 ' + font + ';letter-spacing:.02em;color:' + config.on + ';white-space:nowrap;', config.label));
        }

        var closer = node('span', 'display:none;color:inherit;');
        closer.innerHTML = CHEVRON;
        button.appendChild(face);
        button.appendChild(closer);

        var shadow = '0 10px 30px rgba(0,0,0,.18),0 2px 6px rgba(0,0,0,.08)';
        var shadowUp = '0 14px 36px rgba(0,0,0,.22),0 3px 8px rgba(0,0,0,.1)';
        var effect = calm ? 'none' : config.effect;
        var effectTimer = null;

        function paint() {
            var base = 'all:initial;box-sizing:border-box;position:relative;display:flex;align-items:center;justify-content:center;width:max-content;white-space:nowrap;text-align:left;cursor:pointer;font-family:' + font + ';transition:transform .25s ' + ease + ',box-shadow .25s ' + ease + ';-webkit-tap-highlight-color:transparent;--lqchat-ring:' + config.color + '88;';

            face.style.display = isOpen ? 'none' : 'flex';
            closer.style.display = isOpen ? 'flex' : 'none';

            if (style === 'bar') { root.style.bottom = (isOpen ? offsetY : 0) + 'px'; }

            if (isOpen && style === 'tab') {
                root.style.top = 'auto'; root.style.bottom = offsetY + 'px'; root.style.transform = 'none'; root.style[side] = offsetX + 'px';
            } else if (style === 'tab') {
                root.style.top = '50%'; root.style.bottom = 'auto'; root.style.transform = 'translateY(-50%)'; root.style[side] = '0';
            }

            if (isOpen || style === 'bubble') {
                button.style.cssText = base + 'width:60px;height:60px;border-radius:50%;background:linear-gradient(180deg,rgba(255,255,255,.16),rgba(255,255,255,0) 60%),' + config.color + ';color:' + config.on + ';box-shadow:' + shadow + ',inset 0 1px 0 rgba(255,255,255,.25);';
            } else if (style === 'square') {
                button.style.cssText = base + 'width:60px;height:60px;border-radius:18px;background:' + config.color + ';color:' + config.on + ';box-shadow:' + shadow + ';';
            } else if (style === 'pill') {
                button.style.cssText = base + 'height:60px;padding:8px 18px 8px 8px;border-radius:30px;background:linear-gradient(180deg,rgba(255,255,255,.14),rgba(255,255,255,0) 55%),' + config.color + ';color:' + config.on + ';box-shadow:' + shadow + ',inset 0 1px 0 rgba(255,255,255,.22);';
            } else if (style === 'glass') {
                // Frosted: the page shows through, blurred, with a soft light edge, the way glass panels look on a phone.
                button.style.cssText = base + 'height:60px;padding:8px 18px 8px 8px;border-radius:30px;background:linear-gradient(135deg,rgba(255,255,255,.72),rgba(255,255,255,.42));-webkit-backdrop-filter:saturate(180%) blur(22px);backdrop-filter:saturate(180%) blur(22px);color:#1d1d1f;box-shadow:' + shadow + ',inset 0 1px 0 rgba(255,255,255,.9),inset 0 0 0 1px rgba(255,255,255,.55);';
            } else if (style === 'card') {
                button.style.cssText = base + 'height:62px;padding:8px 18px 8px 9px;border-radius:20px;background:rgba(255,255,255,.92);-webkit-backdrop-filter:saturate(180%) blur(20px);backdrop-filter:saturate(180%) blur(20px);color:#1d1d1f;box-shadow:' + shadow + ',inset 0 0 0 1px rgba(0,0,0,.06);';
            } else if (style === 'outline') {
                button.style.cssText = base + 'height:52px;padding:0 20px 0 16px;border-radius:26px;background:#ffffff;color:' + config.color + ';box-shadow:' + shadow + ',inset 0 0 0 2px ' + config.color + ';';
            } else if (style === 'bar') {
                button.style.cssText = base + 'width:' + Math.min(300, window.innerWidth - offsetX * 2) + 'px;height:54px;padding:0 16px 0 18px;border-radius:' + Math.max(radius, 12) + 'px ' + Math.max(radius, 12) + 'px 0 0;background:' + config.color + ';color:' + config.on + ';box-shadow:0 -4px 24px rgba(0,0,0,.16);';
            } else {
                button.style.cssText = base + 'width:46px;padding:16px 0;border-radius:' + (left ? '0 14px 14px 0' : '14px 0 0 14px') + ';background:' + config.color + ';color:' + config.on + ';box-shadow:' + shadow + ';';
            }

            button.appendChild(badge);
            animate();
        }

        // The touch runs every few seconds while the chat is closed, and stops once the visitor opened it.
        function animate() {
            clearInterval(effectTimer);
            button.style.animation = '';

            if (effect === 'none' || isOpen || effect === undefined) { return; }

            var run = function () {
                button.style.animation = 'none';
                void button.offsetWidth;
                button.style.animation = effect === 'pulse' ? 'lqchat-pulse 1.6s ease-out 2' : effect === 'bounce' ? 'lqchat-bounce 1s ' + ease : 'lqchat-wiggle .8s ease-in-out';
            };

            setTimeout(run, 1200);
            effectTimer = setInterval(run, 9000);
        }

        button.onmouseenter = function () { if (style !== 'bar' && style !== 'tab') { button.style.transform = 'translateY(-2px)'; } button.style.boxShadow = shadowUp + (style === 'outline' && !isOpen ? ',inset 0 0 0 2px ' + config.color : ''); };
        button.onmouseleave = function () { button.style.transform = ''; paint(); };

        var badge = node('span', 'position:absolute;top:-4px;' + (left ? 'right' : 'left') + ':-4px;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:#ff3b30;color:#fff;font:700 12px/20px ' + font + ';text-align:center;display:none;box-shadow:0 0 0 2px #fff;');
        paint();
        root.appendChild(button);

        // A few words above the button ("Need help?"), until the visitor opens the chat or closes them.
        var teaserKey = 'lq_chat_teaser_' + key;
        var teaser = null;

        if (config.teaser && style !== 'tab' && storage('sessionStorage', teaserKey) !== '1') {
            teaser = node('span', 'position:absolute;width:max-content;bottom:' + (style === 'bar' ? 64 : 72) + 'px;' + side + ':0;display:flex;align-items:center;gap:8px;max-width:240px;padding:9px 12px 9px 14px;border-radius:999px;background:#ffffff;color:#1d1d1f;font:600 13px/1.2 ' + font + ';white-space:nowrap;box-shadow:0 8px 24px rgba(0,0,0,.16),inset 0 0 0 1px rgba(0,0,0,.05);cursor:pointer;opacity:0;transform:translateY(6px);transition:opacity .3s ease,transform .4s ' + ease + ';');
            var teaserText = node('span', 'overflow:hidden;text-overflow:ellipsis;color:#1d1d1f;font:inherit;', config.teaser);
            var teaserClose = node('span', 'display:flex;color:#8a8a8e;font:18px/1 ' + font + ';cursor:pointer;', '×');
            teaserClose.setAttribute('role', 'button');
            teaserClose.setAttribute('aria-label', 'Dismiss');
            teaser.appendChild(teaserText); teaser.appendChild(teaserClose);
            teaser.onclick = function () { dropTeaser(); show(true); };
            teaserClose.onclick = function (event) { event.stopPropagation(); dropTeaser(); };
            root.appendChild(teaser);
            setTimeout(function () { if (teaser) { teaser.style.opacity = '1'; teaser.style.transform = 'none'; } }, 900);
        }

        function dropTeaser() {
            storage('sessionStorage', teaserKey, '1');
            if (teaser && teaser.parentNode) { teaser.parentNode.removeChild(teaser); }
            teaser = null;
        }

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

            var zone = '';
            try { zone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
            var body = JSON.stringify({ key: key, vid: vidValue(), url: location.origin + location.pathname, title: (document.title || '').slice(0, 200), referrer: (document.referrer || '').slice(0, 500), tz: zone.slice(0, 64), human: human });

            // A plain-text body is a "simple" request: the browser sends it at once, without asking first.
            fetch(chatUrl('api/ping'), { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: body, credentials: 'omit', keepalive: true })
                .then(function (response) { return response.status === 200 ? response.json() : null; })
                .then(function (data) { if (data && data.invite) { invite(data.invite); } })
                .catch(function () {});
        }

        // A person scrolls, taps, clicks, types or moves the mouse; a program that only loads the page usually does not.
        var human = false;
        var moved = 0, lastX = null, lastY = null;

        function noticePerson() {
            if (human) { return; }
            human = true;
            ['pointerdown', 'keydown', 'touchstart', 'wheel', 'scroll', 'mousemove'].forEach(function (name) { window.removeEventListener(name, onSign, true); });
            ping();
        }

        function onSign(event) {
            if (event.type !== 'mousemove') { noticePerson(); return; }
            // A real hand moves the mouse a little here and there; one jump is not enough.
            if (lastX !== null) { moved += Math.abs(event.clientX - lastX) + Math.abs(event.clientY - lastY); }
            lastX = event.clientX; lastY = event.clientY;
            if (moved > 60) { noticePerson(); }
        }

        function track() {
            ['pointerdown', 'keydown', 'touchstart', 'wheel', 'scroll', 'mousemove'].forEach(function (name) { window.addEventListener(name, onSign, { capture: true, passive: true }); });
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
            frame.src = config.frame + (config.frame.indexOf('?') < 0 ? '?' : '&') + 'o=' + encodeURIComponent(location.origin);
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
                dropTeaser();
                if (typeof dropGrabber === 'function') { dropGrabber(); }
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

        // The attention grabber: a card with a picture, a question and two answers. "Yes" opens the chat; "Just browsing"
        // puts it away for a day. It takes the place of the plain greeting.
        var grabberKey = 'lq_chat_grabber_no_' + key;
        var grabber = null;
        var browsingSince = parseInt(storage('localStorage', grabberKey) || '0', 10);

        function dropGrabber() {
            if (grabber && grabber.parentNode) { grabber.parentNode.removeChild(grabber); }
            grabber = null;
        }

        function showGrabber() {
            if (isOpen || grabber || storage('localStorage', startedKey) === '1') { return; }

            storage('sessionStorage', greetKey, '1');
            dropTeaser();

            var card = node('div', 'position:absolute;width:min(284px,calc(100vw - ' + (offsetX + 12) + 'px));bottom:' + (style === 'bar' ? 64 : style === 'tab' ? 0 : 76) + 'px;' + side + ':' + (style === 'tab' ? 56 : 0) + 'px;overflow:hidden;border-radius:22px;background:rgba(255,255,255,.94);-webkit-backdrop-filter:saturate(180%) blur(24px);backdrop-filter:saturate(180%) blur(24px);box-shadow:0 24px 60px rgba(0,0,0,.22),0 4px 14px rgba(0,0,0,.08),inset 0 0 0 1px rgba(255,255,255,.7);font-family:' + font + ';opacity:0;transform:translateY(12px) scale(.96);transform-origin:bottom ' + side + ';transition:opacity .3s ease,transform .45s ' + ease + ';');
            card.setAttribute('role', 'dialog');
            card.setAttribute('aria-label', config.title);

            if (config.grabber_art) {
                var art = node('div', 'display:flex;align-items:center;justify-content:center;height:104px;background:radial-gradient(120% 120% at 20% 0%,' + config.accent + '33,transparent 60%),radial-gradient(120% 120% at 100% 100%,' + config.color + '2e,transparent 60%),#f5f5f7;font:52px/1 "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif;', config.grabber_art);
                art.setAttribute('aria-hidden', 'true');
                card.appendChild(art);
            }

            var body = node('div', 'display:flex;flex-direction:column;gap:12px;padding:14px 16px 16px;');
            body.appendChild(node('div', 'font:500 14.5px/1.45 ' + font + ';letter-spacing:-.01em;color:#1d1d1f;white-space:normal;', config.grabber_text || config.welcome));

            var row = node('div', 'display:flex;gap:8px;');
            var yes = node('button', 'flex:1;padding:10px 12px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,.18),rgba(255,255,255,0)),' + config.accent + ';color:' + config.on_accent + ';font:650 13.5px/1.2 ' + font + ';text-align:center;cursor:pointer;box-shadow:0 6px 16px ' + config.accent + '4d,inset 0 1px 0 rgba(255,255,255,.25);', config.grabber_yes || 'Yes, I do');
            var no = node('button', 'flex:1;padding:10px 12px;border-radius:12px;background:rgba(118,118,128,.12);color:#1d1d1f;font:600 13.5px/1.2 ' + font + ';text-align:center;cursor:pointer;', config.grabber_no || 'Just browsing');
            yes.type = 'button'; no.type = 'button';
            row.appendChild(yes); row.appendChild(no);
            body.appendChild(row);
            card.appendChild(body);

            var shut = node('button', 'position:absolute;top:10px;right:10px;width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.75);color:#6e6e73;font:17px/26px ' + font + ';text-align:center;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.12);', '×');
            shut.type = 'button';
            shut.setAttribute('aria-label', 'Dismiss');
            card.appendChild(shut);

            yes.onclick = function () { dropGrabber(); show(true); };
            no.onclick = function () { storage('localStorage', grabberKey, String(Date.now())); dropGrabber(); };
            shut.onclick = no.onclick;

            grabber = card;
            root.appendChild(card);
            setTimeout(function () { card.style.opacity = '1'; card.style.transform = 'none'; }, 20);
        }

        if (config.grabber && Date.now() - browsingSince > 86400000 && storage('sessionStorage', greetKey) !== '1') {
            setTimeout(showGrabber, (greetAfter > 0 ? greetAfter : 4) * 1000);
        } else if (!config.grabber && greetAfter > 0 && config.welcome && storage('localStorage', startedKey) !== '1' && storage('sessionStorage', greetKey) !== '1') {
            setTimeout(function () {
                if (isOpen) { return; }
                storage('sessionStorage', greetKey, '1');
                invite({ text: config.welcome, from: config.title });
            }, greetAfter * 1000);
        }
    }
})();
