<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title }}</title>
<style>
@verbatim
*{box-sizing:border-box}
html,body{height:100%;margin:0}
/* Light and dark: the chat box takes the visitor's choice when the brand left it to them (theme-auto). */
body{--bg:#fff;--thread:#f5f5f7;--text:#1d1d1f;--muted:#6e6e73;--line:rgba(0,0,0,.08);--bubble:#fff;--field:#fff;--fieldline:#d2d2d7;--soft:#ececf0;
  font:15px/1.45 -apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",system-ui,Roboto,sans-serif;letter-spacing:-.01em;color:var(--text);background:var(--bg);display:flex;flex-direction:column;overflow:hidden;position:relative;-webkit-font-smoothing:antialiased}
body.theme-dark{--bg:#1c1c1e;--thread:#111113;--text:#f5f5f7;--muted:#98989d;--line:rgba(255,255,255,.1);--bubble:#2c2c2e;--field:#2c2c2e;--fieldline:#3a3a3c;--soft:#2c2c2e;color-scheme:dark}
@media (prefers-color-scheme:dark){body.theme-auto{--bg:#1c1c1e;--thread:#111113;--text:#f5f5f7;--muted:#98989d;--line:rgba(255,255,255,.1);--bubble:#2c2c2e;--field:#2c2c2e;--fieldline:#3a3a3c;--soft:#2c2c2e;color-scheme:dark}}
button,input,textarea{font:inherit;color:inherit;letter-spacing:inherit}
header{flex:none;display:flex;align-items:center;gap:12px;padding:14px 14px 14px 16px;background:var(--c);color:var(--on);z-index:2}
body.h-gradient header{background:linear-gradient(135deg,var(--c) 0%,color-mix(in srgb,var(--c) 62%,var(--a)) 100%)}
body.h-glass header{position:absolute;inset:0 0 auto;background:color-mix(in srgb,var(--bg) 70%,transparent);-webkit-backdrop-filter:saturate(180%) blur(20px);backdrop-filter:saturate(180%) blur(20px);color:var(--text);border-bottom:1px solid var(--line)}
body.h-glass main{padding-top:84px}
.av{position:relative;flex:none;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--a);color:var(--ona);font-weight:650;font-size:16px}
.av img{width:100%;height:100%;border-radius:50%;object-fit:contain;background:#fff;padding:5px}
.av i{position:absolute;right:-1px;bottom:-1px;width:11px;height:11px;border-radius:50%;background:#a1a1a6;box-shadow:0 0 0 2px var(--c)}
body.h-glass .av i{box-shadow:0 0 0 2px var(--bg)}
.av i.on{background:#30d158}
header .t{flex:1;min-width:0;font-weight:650;font-size:16px;letter-spacing:-.02em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
header .s{display:block;font-weight:400;font-size:12px;letter-spacing:0;opacity:.78;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
header button{all:unset;cursor:pointer;display:grid;place-items:center;width:32px;height:32px;border-radius:50%;color:inherit;background:rgba(127,127,127,.18);transition:background .15s}
header button:hover{background:rgba(127,127,127,.3)}
header button:focus-visible,.send:focus-visible,.btn:focus-visible{outline:2px solid var(--a);outline-offset:2px}
main{flex:1;min-height:0;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:6px;background:radial-gradient(120% 60% at 100% 0%,color-mix(in srgb,var(--a) 7%,transparent),transparent 60%),radial-gradient(100% 50% at 0% 100%,color-mix(in srgb,var(--c) 6%,transparent),transparent 60%),var(--thread);scroll-behavior:smooth}
header{position:relative}header::after{content:'';position:absolute;inset:0 0 auto;height:50%;background:linear-gradient(180deg,rgba(255,255,255,.1),transparent);pointer-events:none}body.h-glass header{position:absolute}
.row{display:flex;flex-direction:column;max-width:82%}
.row.visitor{align-self:flex-end;align-items:flex-end}
.row.team,.row.ai{align-self:flex-start}
.bubble{padding:9px 14px;border-radius:20px;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
.visitor .bubble{background:var(--c);color:var(--on);border-bottom-right-radius:6px}
.team .bubble,.ai .bubble{background:var(--bubble);color:var(--text);border-bottom-left-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,.05),0 0 0 1px var(--line)}
.visitor .bubble{box-shadow:0 4px 12px color-mix(in srgb,var(--c) 28%,transparent)}
.meta{font-size:11px;color:var(--muted);margin:3px 6px 4px}
.card{background:color-mix(in srgb,var(--bubble) 80%,transparent);-webkit-backdrop-filter:saturate(160%) blur(14px);backdrop-filter:saturate(160%) blur(14px);border:1px solid var(--line);border-radius:18px;padding:16px;display:flex;flex-direction:column;gap:10px;box-shadow:0 8px 24px rgba(0,0,0,.06)}
.card h2{margin:0;font-size:15px;letter-spacing:-.02em}
.card p{margin:0;font-size:13px;color:var(--muted)}
label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--muted)}
input[type=text],input[type=email],input[type=tel],textarea{width:100%;border:1px solid transparent;border-radius:12px;padding:10px 12px;background:var(--soft);color:var(--text);outline:none;transition:border-color .15s,box-shadow .15s}
input:focus,textarea:focus{border-color:var(--a);box-shadow:0 0 0 4px color-mix(in srgb,var(--a) 20%,transparent)}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.actions{display:flex;gap:8px}
.btn{all:unset;cursor:pointer;text-align:center;padding:11px 16px;border-radius:12px;font-weight:600;font-size:14px;background:linear-gradient(180deg,rgba(255,255,255,.18),rgba(255,255,255,0)),var(--a);color:var(--ona);box-shadow:0 6px 16px color-mix(in srgb,var(--a) 30%,transparent),inset 0 1px 0 rgba(255,255,255,.25);transition:filter .15s,transform .1s}
.btn:hover{filter:brightness(1.06)}.btn:active{transform:scale(.98)}
.btn.ghost{background:transparent;color:var(--muted)}
.btn:disabled{opacity:.55;cursor:default}
.err{color:#ff453a;font-size:12px;margin:0}
.consent{font-size:11px;color:var(--muted);text-align:center;margin:4px 12px 0}
form.composer{flex:none;display:flex;align-items:flex-end;gap:6px;padding:10px 10px 12px;border-top:1px solid var(--line);background:var(--bg)}
form.composer textarea{resize:none;max-height:120px;min-height:40px;line-height:1.35;border-radius:20px;padding:9px 14px;background:var(--soft);border-color:transparent}
form.composer textarea:focus{background:var(--field);border-color:var(--a)}
.attach{all:unset;cursor:pointer;flex:none;display:grid;place-items:center;width:40px;height:40px;border-radius:50%;color:var(--muted)}
.attach:hover{background:var(--soft);color:var(--text)}
.attach:focus-visible{outline:2px solid var(--a);outline-offset:2px}
.pic{display:block;max-width:220px;margin-bottom:4px;border-radius:16px;overflow:hidden;border:1px solid var(--line);background:var(--bubble)}
.pic img{display:block;width:100%;height:auto;max-height:240px;object-fit:cover}
.doc{display:flex;gap:8px;align-items:center;max-width:240px;margin-bottom:4px;padding:10px 12px;border-radius:16px;border:1px solid var(--line);background:var(--bubble);color:inherit;text-decoration:none;font-size:13px}
.doc span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.doc small{margin-left:auto;color:var(--muted);white-space:nowrap}
.visitor .doc,.visitor .pic{border-color:transparent}
.send{all:unset;cursor:pointer;flex:none;display:grid;place-items:center;width:40px;height:40px;border-radius:50%;background:var(--a);color:var(--ona);transition:transform .12s,opacity .15s}
.send:active{transform:scale(.92)}
.send:disabled{opacity:.45;cursor:default}
/* typing dots */
.typing{display:flex;gap:4px;align-items:center;padding:13px 14px}
.typing span{width:7px;height:7px;border-radius:50%;background:var(--muted);animation:dot 1.2s infinite ease-in-out}
.typing span:nth-child(2){animation-delay:.15s}.typing span:nth-child(3){animation-delay:.3s}
@keyframes dot{0%,80%,100%{opacity:.3;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}
.rate{display:flex;gap:4px}.rate button{width:30px;height:30px;background:rgba(127,127,127,.16);transition:background .15s,transform .15s}.rate button:active{transform:scale(.9)}.rate .down svg{transform:scale(-1,-1)}.rate button[aria-pressed=true]{background:rgba(255,255,255,.92);color:var(--a)}body.h-glass .rate button[aria-pressed=true]{background:var(--a);color:var(--ona)}
.hidden{display:none!important}
@media (prefers-reduced-motion:no-preference){.row,.card{animation:in .28s cubic-bezier(.2,.8,.2,1)}@keyframes in{from{opacity:0;transform:translateY(6px) scale(.98)}}}
@endverbatim
</style>
</head>
<body class="theme-{{ $design['theme'] }} h-{{ $design['header'] }}" style="--c: {{ $design['color'] }}; --on: {{ $design['on'] }}; --a: {{ $design['accent'] }}; --ona: {{ $design['on_accent'] }}" data-key="{{ $key }}" data-origin="{{ $origin }}" data-api="{{ $api }}" data-sound="{{ $design['sound'] ? '1' : '0' }}">
<header>
    <div class="av" aria-hidden="true">@if ($logo)<img src="{{ $logo }}" alt="">@else{{ mb_strtoupper(mb_substr($title, 0, 1)) }}@endif<i id="dot"></i></div>
    <div class="t"><span id="title">{{ $title }}</span><span class="s" id="sub" data-reply="{{ $design['reply_time'] }}"></span></div>
    <div class="rate hidden" id="rate" role="group" aria-label="Rate this chat"><button type="button" id="rate-up" aria-label="Good chat" aria-pressed="false"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.633 10.25c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 0 1 2.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 0 0 .322-1.672V2.75a.75.75 0 0 1 .75-.75 2.25 2.25 0 0 1 2.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282m0 0h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 0 1-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 0 0-1.423-.23H5.904m10.598-9.75H14.25M5.904 18.5c.083.205.173.405.27.602.197.4-.078.898-.523.898h-.908c-.889 0-1.713-.518-1.972-1.368a12 12 0 0 1-.521-3.507c0-1.553.295-3.036.831-4.398C3.387 9.953 4.167 9.5 5 9.5h1.053c.472 0 .745.556.5.96a8.958 8.958 0 0 0-1.302 4.665c0 1.194.232 2.333.654 3.375Z"/></svg></button><button type="button" id="rate-down" aria-label="Bad chat" aria-pressed="false" class="down"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.633 10.25c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 0 1 2.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 0 0 .322-1.672V2.75a.75.75 0 0 1 .75-.75 2.25 2.25 0 0 1 2.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282m0 0h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 0 1-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 0 0-1.423-.23H5.904m10.598-9.75H14.25M5.904 18.5c.083.205.173.405.27.602.197.4-.078.898-.523.898h-.908c-.889 0-1.713-.518-1.972-1.368a12 12 0 0 1-.521-3.507c0-1.553.295-3.036.831-4.398C3.387 9.953 4.167 9.5 5 9.5h1.053c.472 0 .745.556.5.96a8.958 8.958 0 0 0-1.302 4.665c0 1.194.232 2.333.654 3.375Z"/></svg></button></div>
    <button type="button" id="close" aria-label="Close chat"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
</header>
<main id="thread" aria-live="polite" aria-label="Conversation"></main>
<form class="composer hidden" id="composer" autocomplete="off">
    <button class="attach hidden" id="attach" type="button" aria-label="Attach a file" title="Attach a file"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/></svg></button>
    <input type="file" id="file" class="hidden" tabindex="-1" aria-hidden="true">
    <textarea id="box" rows="1" maxlength="2000" placeholder="Write a message…" aria-label="Your message"></textarea>
    <button class="send" id="send" type="submit" aria-label="Send"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 12L3.27 3.13a.75.75 0 0 1 1.03-.9l16.5 8.25a.75.75 0 0 1 0 1.34L4.3 19.77a.75.75 0 0 1-1.03-.9L6 12zm0 0h7.5"/></svg></button>
</form>
<script>
@verbatim
(function () {
    'use strict';

    var body = document.body;
    var KEY = body.getAttribute('data-key');
    var ORIGIN = body.getAttribute('data-origin');
    var API = body.getAttribute('data-api') || '/chat/api';
    // Through liquify-chat.php the address is "…/liquify-chat.php?p=api", so "/messages?after=3" becomes "…?p=api/messages&after=3".
    function api(path) {
        if (API.indexOf('?') < 0) { return API + path; }
        var cut = path.indexOf('?');
        return API + (cut < 0 ? path : path.slice(0, cut) + '&' + path.slice(cut + 1));
    }
    var STORE = 'lq_v_' + KEY;
    var $ = function (id) { return document.getElementById(id); };
    var thread = $('thread'), composer = $('composer'), box = $('box'), sendButton = $('send');
    var attachButton = $('attach'), fileInput = $('file');

    var creds = null, config = null, visitor = null;
    var seen = {}, lastId = 0, open = false, unread = 0, page = null, timer = null, failures = 0;
    var started = false, detailsShown = false, detailsDismissed = false, tooltip = null;
    var typingRow = null, lastTyped = 0, closedShown = false, loaded = false;
    var rating = null;

    // Thumbs up or down for the chat, once the team has answered (and when the brand allows it).
    function showRating() { if (config && config.rating) { $('rate').classList.remove('hidden'); } }
    function paintRating() {
        $('rate-up').setAttribute('aria-pressed', rating === 'up' ? 'true' : 'false');
        $('rate-down').setAttribute('aria-pressed', rating === 'down' ? 'true' : 'false');
    }
    function rate(value) {
        var before = rating;
        rating = value; paintRating();
        call('POST', '/rate', { rating: value }).then(function (r) {
            if (r.status !== 200) { rating = before; paintRating(); return; }
            addNote(value === 'up' ? 'Thank you! Glad we could help.' : 'Thank you. We will do better.');
        }).catch(function () { rating = before; paintRating(); });
    }
    var rt = { source: null, ok: false, refresh: null };

    function load() { try { return JSON.parse(localStorage.getItem(STORE) || 'null'); } catch (e) { return null; } }
    function save(value) { try { localStorage.setItem(STORE, JSON.stringify(value)); } catch (e) {} }
    function tell(message) { message.lqChat = true; try { parent.postMessage(message, ORIGIN); } catch (e) {} }

    function headers() {
        var h = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Chat-Key': KEY };
        if (creds) { h['X-Chat-Token'] = creds.token; h['X-Chat-Sig'] = creds.sig; }
        return h;
    }

    function call(method, path, data) {
        return fetch(api(path), { method: method, headers: headers(), body: data ? JSON.stringify(data) : undefined, credentials: 'omit' })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (json) { return { status: response.status, json: json }; });
            });
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function clock(iso) {
        var d = new Date(iso);
        return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function scroll() { thread.scrollTop = thread.scrollHeight; }

    function addMessage(m) {
        if (seen[m.id]) { return; }
        seen[m.id] = true;
        lastId = Math.max(lastId, m.id);

        var row = el('div', 'row ' + m.from);
        // Files: a picture in place, anything else as a card to download. The links are signed by the CRM.
        (m.files || []).forEach(function (f) {
            var url;

            try { url = new URL(f.url, location.href); } catch (e) { return; }
            if (url.origin !== location.origin) { return; }

            var link = el('a', f.image ? 'pic' : 'doc');
            link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer';

            if (f.image) {
                var img = el('img'); img.src = url.href; img.alt = f.name; img.decoding = 'async';
                img.onload = scroll;
                link.appendChild(img);
            } else {
                link.appendChild(el('span', '', f.name));
                link.appendChild(el('small', '', f.size));
            }

            row.appendChild(link);
        });

        if (m.body) { row.appendChild(el('div', 'bubble', m.body)); }
        row.appendChild(el('div', 'meta', (m.from === 'team' && m.name ? m.name + ' · ' : m.from === 'ai' ? 'Virtual assistant · ' : '') + clock(m.at)));
        row.setAttribute('data-id', m.id);
        thread.insertBefore(row, tooltip && tooltip.parentNode === thread ? tooltip : (typingRow && typingRow.parentNode === thread ? typingRow : null));
        scroll();

        if (m.from !== 'visitor') {
            if (m.from === 'team') { showRating(); }
            if (loaded && (!open || document.hidden)) { chime(); }
            if (!open) { unread += 1; tell({ type: 'unread', count: unread }); }
        } else if (!started) {
            started = true; tell({ type: 'started' });
        }
    }

    // A soft two-note sound for an answer while the box is closed or the tab is behind another (made here, no file).
    var audio = null;
    function chime() {
        if (body.getAttribute('data-sound') !== '1') { return; }
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            [[880, 0], [1320, 0.12]].forEach(function (note) {
                var o = audio.createOscillator(), g = audio.createGain(), t = audio.currentTime + note[1];
                o.type = 'sine'; o.frequency.value = note[0];
                g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.12, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.35);
                o.connect(g); g.connect(audio.destination); o.start(t); o.stop(t + 0.4);
            });
        } catch (e) {}
    }

    function clearTooltip() { if (tooltip && tooltip.parentNode) { tooltip.parentNode.removeChild(tooltip); } tooltip = null; }

    // The team is typing: three dots at the end of the thread while it lasts.
    function setTyping(on) {
        if (on && !typingRow) {
            typingRow = el('div', 'row team');
            var bubble = el('div', 'bubble typing');
            bubble.appendChild(el('span')); bubble.appendChild(el('span')); bubble.appendChild(el('span'));
            bubble.setAttribute('aria-label', 'The team is typing');
            typingRow.appendChild(bubble);
            thread.appendChild(typingRow);
            scroll();
        } else if (!on && typingRow) {
            if (typingRow.parentNode) { typingRow.parentNode.removeChild(typingRow); }
            typingRow = null;
        }
    }

    // The team closed the chat: said once; writing again opens it.
    function setClosed(closed) {
        if (closed && !closedShown) { closedShown = true; addNote('The team closed this chat. Write again if you need anything else.'); }
        if (!closed) { closedShown = false; }
    }

    // Nobody of the team is here: a form instead of a chat that would wait.
    function offlineCard() {
        var form = el('form', 'card');
        form.appendChild(el('h2', '', 'We are away right now'));
        form.appendChild(el('p', '', config.offline));
        form.appendChild(field('Name', 'text', 'name', 'Your name', false));
        form.appendChild(field('Email', 'email', 'email', 'you@example.com', false));
        form.appendChild(field('Phone number', 'tel', 'phone', '+44 …', false));
        var label = el('label', '', 'Your message');
        var ta = el('textarea'); ta.name = 'message'; ta.rows = 3; ta.maxLength = 2000; ta.required = true;
        label.appendChild(ta); form.appendChild(label);
        form.appendChild(honeypot());
        var go = el('button', 'btn', 'Send message'); go.type = 'submit'; form.appendChild(go);

        form.onsubmit = function (event) {
            event.preventDefault();
            var data = readForm(form);

            if (!String(data.email || '').trim() && !String(data.phone || '').trim()) { showErrors(form, { email: 'Add an email address or a phone number, so we can reach you.' }); return; }

            go.disabled = true;
            call('POST', '/details', data).then(function (r) {
                go.disabled = false;
                if (r.status === 422 && r.json.errors) { showErrors(form, r.json.errors); return; }
                if (r.status >= 400) { showErrors(form, { message: 'Something went wrong. Please try again.' }); return; }
                visitor.has_details = true;
                form.parentNode && form.parentNode.removeChild(form);
                if (r.json.message) { addMessage(r.json.message); }
                addNote('Thank you. We will reply as soon as we are back.');
                composer.classList.remove('hidden');
            }).catch(function () { go.disabled = false; showErrors(form, { message: 'No connection. Please try again.' }); });
        };

        thread.appendChild(form);
    }

    // ---- the forms ------------------------------------------------------------------------------------------
    function field(labelText, type, name, placeholder, required) {
        var label = el('label', '', labelText);
        var input = el('input');
        input.type = type; input.name = name; input.placeholder = placeholder || ''; input.autocomplete = name === 'name' ? 'name' : name === 'email' ? 'email' : 'tel';
        if (required) { input.required = true; }
        label.appendChild(input);
        return label;
    }

    function honeypot() {
        var wrap = el('div', 'hp'); wrap.setAttribute('aria-hidden', 'true');
        var input = el('input'); input.type = 'text'; input.name = 'website'; input.tabIndex = -1; input.autocomplete = 'off';
        wrap.appendChild(input);
        return wrap;
    }

    function readForm(form) {
        var data = { page: page ? page.url : null };
        Array.prototype.forEach.call(form.elements, function (input) { if (input.name) { data[input.name] = input.value; } });
        return data;
    }

    function showErrors(form, errors) {
        Array.prototype.forEach.call(form.querySelectorAll('.err'), function (node) { node.parentNode.removeChild(node); });
        Object.keys(errors || {}).forEach(function (name) {
            var input = form.elements[name];
            var message = el('p', 'err', errors[name]);
            (input && input.parentNode && input.parentNode.tagName === 'LABEL' ? input.parentNode : form).appendChild(message);
        });
    }

    function prechatCard() {
        var form = el('form', 'card');
        form.appendChild(el('h2', '', 'Before we start'));
        form.appendChild(el('p', '', 'Tell us who you are, so we can follow up if we get cut off.'));
        var f = config.prechat.fields;

        if (f.name) { form.appendChild(field('Name', 'text', 'name', 'Your name', true)); }
        if (f.email) { form.appendChild(field('Email', 'email', 'email', 'you@example.com', !f.phone)); }
        if (f.phone) { form.appendChild(field('Phone number', 'tel', 'phone', '+44 …', !f.email)); }

        if (f.message) {
            var label = el('label', '', config.prechat.labels.message);
            var ta = el('textarea'); ta.name = 'message'; ta.rows = 3; ta.maxLength = 2000; ta.required = true;
            label.appendChild(ta); form.appendChild(label);
        }

        form.appendChild(honeypot());
        var go = el('button', 'btn', 'Start chat'); go.type = 'submit'; form.appendChild(go);
        form.appendChild(el('p', 'consent', config.consent));

        form.onsubmit = function (event) {
            event.preventDefault();
            go.disabled = true;
            call('POST', '/details', readForm(form)).then(function (r) {
                go.disabled = false;
                if (r.status === 422 && r.json.errors) { showErrors(form, r.json.errors); return; }
                if (r.status >= 400) { showErrors(form, { name: 'Something went wrong. Please try again.' }); return; }
                if (form.parentNode) { form.parentNode.removeChild(form); }
                visitor.has_details = visitor.has_details || !!r.json.lead || !!(form.elements.email && form.elements.email.value) || !!(form.elements.phone && form.elements.phone.value);
                if (r.json.message) { addMessage(r.json.message); }
                composer.classList.remove('hidden'); box.focus();
            }).catch(function () { showErrors(form, { name: 'No connection. Please try again.' }); go.disabled = false; });
        };

        thread.appendChild(form);
    }

    function detailsCard() {
        if (detailsShown || detailsDismissed || visitor.has_details) { return; }
        detailsShown = true;

        var form = el('form', 'card');
        form.appendChild(el('h2', '', 'Want a reply by email or phone?'));
        form.appendChild(el('p', '', 'Leave your details and we can reach you if you close this page.'));
        form.appendChild(field('Name', 'text', 'name', 'Your name', false));
        form.appendChild(field('Email', 'email', 'email', 'you@example.com', false));
        form.appendChild(field('Phone number', 'tel', 'phone', '+44 …', false));
        form.appendChild(honeypot());
        var actions = el('div', 'actions');
        var go = el('button', 'btn', 'Send'); go.type = 'submit';
        var skip = el('button', 'btn ghost', 'Not now'); skip.type = 'button';
        actions.appendChild(go); actions.appendChild(skip); form.appendChild(actions);

        skip.onclick = function () { detailsDismissed = true; form.parentNode && form.parentNode.removeChild(form); };
        form.onsubmit = function (event) {
            event.preventDefault();
            var data = readForm(form);

            if (!String(data.email || '').trim() && !String(data.phone || '').trim()) {
                showErrors(form, { email: 'Add an email address or a phone number.' });
                return;
            }

            go.disabled = true;
            call('POST', '/details', data).then(function (r) {
                go.disabled = false;
                if (r.status === 422 && r.json.errors) { showErrors(form, r.json.errors); return; }
                if (r.status >= 400) { showErrors(form, { email: 'Something went wrong. Please try again.' }); return; }
                visitor.has_details = true;
                form.parentNode && form.parentNode.removeChild(form);
                addNote('Thank you. We have your details.');
            }).catch(function () { go.disabled = false; showErrors(form, { email: 'No connection. Please try again.' }); });
        };

        thread.appendChild(form);
        scroll();
    }

    function addNote(text) { var note = el('p', 'consent', text); thread.appendChild(note); scroll(); }

    function suggestCard(suggest) {
        clearTooltip();
        var value = suggest.email || suggest.phone;
        var card = el('div', 'card');
        card.appendChild(el('h2', '', 'Is ' + value + ' the best way to reach you?'));
        card.appendChild(el('p', '', 'We will use it only to answer you.'));
        var actions = el('div', 'actions');
        var yes = el('button', 'btn', 'Yes'); yes.type = 'button';
        var no = el('button', 'btn ghost', 'No'); no.type = 'button';
        actions.appendChild(yes); actions.appendChild(no); card.appendChild(actions);
        no.onclick = clearTooltip;
        yes.onclick = function () {
            yes.disabled = true;
            call('POST', '/details', { email: suggest.email || '', phone: suggest.phone || '', page: page ? page.url : null }).then(function (r) {
                clearTooltip();
                if (r.status < 300) { visitor.has_details = true; addNote('Thank you. We have your ' + (suggest.email ? 'email address' : 'number') + '.'); }
            }).catch(function () { yes.disabled = false; });
        };
        tooltip = card;
        thread.appendChild(card);
        scroll();
    }

    // ---- sending and polling --------------------------------------------------------------------------------
    // A file from the paperclip: checked here for size and kind (the CRM checks again), then sent as it is.
    function upload(file) {
        var limit = config.files.max_mb * 1048576;
        var kinds = config.files.accept.split(',');
        var dot = file.name.lastIndexOf('.');
        var extension = dot >= 0 ? file.name.slice(dot).toLowerCase() : '';

        if (kinds.indexOf(extension) < 0) { addNote('This kind of file is not accepted. You can send pictures, PDF, Word documents and text files.'); return; }
        if (file.size > limit) { addNote('That file is too big. The most we take is ' + config.files.max_mb + ' MB.'); return; }
        if (file.size === 0) { addNote('That file is empty.'); return; }

        var data = new FormData();
        data.append('file', file);

        var h = headers();
        delete h['Content-Type'];

        var pending = el('p', 'consent', 'Sending ' + file.name + '…');
        thread.appendChild(pending); scroll();
        attachButton.disabled = true;

        return fetch(api('/upload'), { method: 'POST', headers: h, body: data, credentials: 'omit' })
            .then(function (response) { return response.json().catch(function () { return {}; }).then(function (json) { return { status: response.status, json: json }; }); })
            .then(function (r) {
                attachButton.disabled = false;
                if (pending.parentNode) { pending.parentNode.removeChild(pending); }
                if (r.status === 422 || r.status === 429 || r.status === 413) { addNote(r.json.error || (r.json.errors && r.json.errors.file ? r.json.errors.file[0] : 'The file was not sent. Please try again.')); return; }
                if (r.status >= 400) { addNote('The file was not sent. Please try again.'); return; }
                addMessage(r.json.message);
                if (!visitor.has_details) { detailsCard(); }
            })
            .catch(function () { attachButton.disabled = false; if (pending.parentNode) { pending.parentNode.removeChild(pending); } addNote('No connection. Please try again.'); });
    }

    function send(text) {
        sendButton.disabled = true;
        return call('POST', '/messages', { body: text }).then(function (r) {
            sendButton.disabled = false;
            if (r.status === 422 || r.status === 429) { addNote(r.json.error || 'Slow down a little, please.'); return false; }
            if (r.status >= 400) { addNote('The message was not sent. Please try again.'); return false; }
            box.value = ''; resize();
            addMessage(r.json.message);
            if (r.json.suggest) { suggestCard(r.json.suggest); }
            else if (!visitor.has_details) { detailsCard(); }
            return true;
        }).catch(function () { sendButton.disabled = false; addNote('No connection. Please try again.'); return false; });
    }

    function resize() { box.style.height = 'auto'; box.style.height = Math.min(box.scrollHeight, 120) + 'px'; }

    function poll() {
        clearTimeout(timer);
        if (!creds) { return; }

        call('GET', '/messages?after=' + lastId).then(function (r) {
            if (r.status === 200) { failures = 0; (r.json.messages || []).forEach(addMessage); setTyping(!!r.json.agent_typing); setClosed(!!r.json.closed); }
            else if (r.status === 401 || r.status === 404) { return; }
            else { failures += 1; }
            schedule();
        }).catch(function () { failures += 1; schedule(); });
    }

    function schedule() {
        clearTimeout(timer);
        // While the instant channel is open, asking is only a safety net: the news comes by itself.
        var base = rt.ok ? 30000 : (open && !document.hidden ? 3000 : 20000);
        timer = setTimeout(poll, Math.min(60000, base * (1 + failures)));
    }

    // Instant delivery (Ably, when the CRM has it switched on): a tiny "something changed" signal arrives on the
    // visitor's own channel and the chat box asks for what is new at once. Anything that goes wrong closes the
    // channel and the ordinary asking every few seconds carries on.
    function closeRealtime() {
        clearTimeout(rt.refresh);
        if (rt.source) { rt.source.close(); }
        rt.source = null;
        rt.ok = false;
    }

    function connectRealtime() {
        if (!config || !config.realtime || typeof EventSource === 'undefined' || !creds) { return; }

        call('POST', '/realtime', {}).then(function (r) {
            if (r.status !== 200 || !r.json.enabled) { return; }

            closeRealtime();

            var source = new EventSource(r.json.url);
            rt.source = source;
            source.onopen = function () { rt.ok = true; schedule(); };
            source.addEventListener('message', function () { poll(); });
            source.onerror = function () {
                rt.ok = false;
                schedule();

                // The connection is gone for good (an expired token, Ably away): try again later with a new address.
                if (source.readyState === 2) {
                    closeRealtime();
                    rt.refresh = setTimeout(connectRealtime, 20000);
                }
            };

            clearTimeout(rt.refresh);
            rt.refresh = setTimeout(connectRealtime, Math.max(60, r.json.refresh_in || 2700) * 1000);
        }).catch(function () {});
    }

    // ---- start ----------------------------------------------------------------------------------------------
    function begin() {
        creds = load();
        call('POST', '/init', { vid: page && page.vid ? page.vid : null }).then(function (r) {
            if (r.status === 404 || r.status === 403) { thread.appendChild(el('div', 'card', 'The chat is not available right now.')); return; }
            if (r.status !== 200) { thread.appendChild(el('div', 'card', 'The chat could not start. Please try again later.')); return; }

            creds = { token: r.json.token, sig: r.json.sig }; save(creds);
            config = r.json.config; visitor = r.json.visitor;
            $('title').textContent = config.title;
            // Under the title: whether somebody is here, and how soon they usually answer.
            var sub = $('sub');
            sub.textContent = config.online ? (sub.getAttribute('data-reply') || 'Online now') : 'Away right now. Leave a message.';
            $('dot').className = config.online ? 'on' : '';

            if (config.files) {
                fileInput.accept = config.files.accept;
                attachButton.classList.remove('hidden');
            }

            var messages = r.json.messages || [];

            if (!messages.length) {
                var welcome = el('div', 'row team'); welcome.appendChild(el('div', 'bubble', config.welcome));
                thread.appendChild(welcome);
            }

            rating = r.json.rating || null; paintRating();
            messages.forEach(function (m) { addMessage(m); });
            loaded = true;
            unread = 0; tell({ type: 'unread', count: 0 });
            setClosed(!!r.json.closed);

            if (!messages.length && !config.online && !visitor.has_details) { offlineCard(); }
            else if (!messages.length && config.prechat.enabled && !visitor.has_details) { prechatCard(); }
            else {
                composer.classList.remove('hidden');
                if (!messages.length) { thread.appendChild(el('p', 'consent', config.consent)); }
                else if (!visitor.has_details) { detailsCard(); }
            }

            schedule();
            connectRealtime();
        }).catch(function () { thread.appendChild(el('div', 'card', 'No connection. Please try again later.')); });
    }

    composer.onsubmit = function (event) { event.preventDefault(); var text = box.value.trim(); if (text) { send(text); } };
    box.addEventListener('input', function () {
        resize();
        var now = Date.now();
        if (started && box.value.trim() !== '' && now - lastTyped > 3000) { lastTyped = now; call('POST', '/typing', {}).catch(function () {}); }
        // The box is empty again: the team's dots go at once.
        else if (started && box.value.trim() === '' && lastTyped > 0) { lastTyped = 0; call('POST', '/typing', { stop: true }).catch(function () {}); }
    });
    box.addEventListener('keydown', function (event) { if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); composer.requestSubmit(); } });
    attachButton.onclick = function () { fileInput.click(); };
    fileInput.onchange = function () { var file = fileInput.files && fileInput.files[0]; fileInput.value = ''; if (file && config && config.files) { upload(file); } };
    $('close').onclick = function () { tell({ type: 'close' }); };
    $('rate-up').onclick = function () { rate('up'); };
    $('rate-down').onclick = function () { rate('down'); };
    document.addEventListener('visibilitychange', schedule);

    window.addEventListener('message', function (event) {
        if (event.source !== parent || event.origin !== ORIGIN || !event.data || event.data.lqChat !== true) { return; }

        if (event.data.type === 'page') { page = { vid: event.data.vid, url: event.data.url, title: event.data.title, referrer: event.data.referrer }; if (waiting) { waiting(); } }
        if (event.data.type === 'visible') {
            open = !!event.data.open;
            if (open) { unread = 0; scroll(); if (!composer.classList.contains('hidden')) { box.focus(); } }
            schedule();
        }
    });

    // The website's launcher tells this box which browser it is (its "vid"), so a chat the team started with an invitation is found.
    // The chat opens without it after a moment, as it did before.
    var waiting = function () { waiting = null; clearTimeout(waitTimer); begin(); };
    var waitTimer = setTimeout(function () { if (waiting) { waiting(); } }, 700);
    tell({ type: 'ready' });
})();
@endverbatim
</script>
</body>
</html>
