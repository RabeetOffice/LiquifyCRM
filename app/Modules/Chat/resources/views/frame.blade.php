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
body{font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1c1b19;background:#fff;display:flex;flex-direction:column;overflow:hidden;-webkit-font-smoothing:antialiased}
button,input,textarea{font:inherit;color:inherit}
header{flex:none;display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--c);color:var(--on)}
header .t{flex:1;min-width:0;font-weight:650;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
header .s{display:block;font-weight:400;font-size:12px;opacity:.85}
header button{all:unset;cursor:pointer;display:grid;place-items:center;width:34px;height:34px;border-radius:10px;color:var(--on)}
header button:hover{background:rgba(127,127,127,.25)}
header button:focus-visible,.send:focus-visible,.btn:focus-visible{outline:2px solid var(--c);outline-offset:2px}
main{flex:1;min-height:0;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;background:#f6f5f3}
.row{display:flex;flex-direction:column;max-width:84%}
.row.visitor{align-self:flex-end;align-items:flex-end}
.row.team,.row.ai{align-self:flex-start}
.bubble{padding:9px 13px;border-radius:16px;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
.visitor .bubble{background:var(--c);color:var(--on);border-bottom-right-radius:5px}
.team .bubble,.ai .bubble{background:#fff;border:1px solid #e7e4df;border-bottom-left-radius:5px}
.meta{font-size:11px;color:#8a857d;margin:3px 4px 0}
.card{background:#fff;border:1px solid #e7e4df;border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:10px}
.card h2{margin:0;font-size:14px}
.card p{margin:0;font-size:13px;color:#5f5a52}
label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#5f5a52}
input[type=text],input[type=email],input[type=tel],textarea{width:100%;border:1px solid #d9d5ce;border-radius:10px;padding:9px 11px;background:#fff;outline:none}
input:focus,textarea:focus{border-color:var(--c);box-shadow:0 0 0 3px color-mix(in srgb,var(--c) 22%,transparent)}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.actions{display:flex;gap:8px}
.btn{all:unset;cursor:pointer;text-align:center;padding:9px 14px;border-radius:10px;font-weight:600;font-size:14px;background:var(--c);color:var(--on)}
.btn.ghost{background:transparent;color:#5f5a52}
.btn:disabled{opacity:.55;cursor:default}
.err{color:#b42318;font-size:12px;margin:0}
.consent{font-size:11px;color:#8a857d;text-align:center;margin:2px 8px 0}
form.composer{flex:none;display:flex;align-items:flex-end;gap:8px;padding:10px 12px;border-top:1px solid #e7e4df;background:#fff}
form.composer textarea{resize:none;max-height:120px;min-height:40px;line-height:1.35}
.attach{all:unset;cursor:pointer;flex:none;display:grid;place-items:center;width:40px;height:40px;border-radius:12px;color:#8a857d}
.attach:hover{background:#f1efec;color:#1c1b19}
.attach:focus-visible{outline:2px solid var(--c);outline-offset:2px}
.pic{display:block;max-width:220px;margin-bottom:4px;border-radius:12px;overflow:hidden;border:1px solid #e7e4df;background:#fff}
.pic img{display:block;width:100%;height:auto;max-height:240px;object-fit:cover}
.doc{display:flex;gap:8px;align-items:center;max-width:240px;margin-bottom:4px;padding:9px 12px;border-radius:12px;border:1px solid #e7e4df;background:#fff;color:inherit;text-decoration:none;font-size:13px}
.doc span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.doc small{margin-left:auto;color:#8a857d;white-space:nowrap}
.visitor .doc,.visitor .pic{border-color:transparent}
.send{all:unset;cursor:pointer;flex:none;display:grid;place-items:center;width:40px;height:40px;border-radius:12px;background:var(--c);color:var(--on)}
.send:disabled{opacity:.5;cursor:default}
/* typing dots */
.typing{display:flex;gap:4px;align-items:center;padding:12px 14px}
.typing span{width:6px;height:6px;border-radius:50%;background:#a39d93;animation:dot 1.2s infinite ease-in-out}
.typing span:nth-child(2){animation-delay:.15s}.typing span:nth-child(3){animation-delay:.3s}
@keyframes dot{0%,80%,100%{opacity:.3;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}
.hidden{display:none!important}
@media (prefers-reduced-motion:no-preference){.row,.card{animation:in .18s ease-out}@keyframes in{from{opacity:0;transform:translateY(4px)}}}
@endverbatim
</style>
</head>
<body style="--c: {{ $color }}; --on: {{ $on }}" data-key="{{ $key }}" data-origin="{{ $origin }}">
<header>
    <div class="t"><span id="title">{{ $title }}</span><span class="s" id="sub"></span></div>
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
    var API = '/chat/api';
    var STORE = 'lq_v_' + KEY;
    var $ = function (id) { return document.getElementById(id); };
    var thread = $('thread'), composer = $('composer'), box = $('box'), sendButton = $('send');
    var attachButton = $('attach'), fileInput = $('file');

    var creds = null, config = null, visitor = null;
    var seen = {}, lastId = 0, open = false, unread = 0, page = null, timer = null, failures = 0;
    var started = false, detailsShown = false, detailsDismissed = false, tooltip = null;
    var typingRow = null, lastTyped = 0, closedShown = false;

    function load() { try { return JSON.parse(localStorage.getItem(STORE) || 'null'); } catch (e) { return null; } }
    function save(value) { try { localStorage.setItem(STORE, JSON.stringify(value)); } catch (e) {} }
    function tell(message) { message.lqChat = true; try { parent.postMessage(message, ORIGIN); } catch (e) {} }

    function headers() {
        var h = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Chat-Key': KEY };
        if (creds) { h['X-Chat-Token'] = creds.token; h['X-Chat-Sig'] = creds.sig; }
        return h;
    }

    function call(method, path, data) {
        return fetch(API + path, { method: method, headers: headers(), body: data ? JSON.stringify(data) : undefined, credentials: 'omit' })
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
                var img = el('img'); img.src = url.href; img.alt = f.name; img.loading = 'lazy';
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
            if (!open) { unread += 1; tell({ type: 'unread', count: unread }); }
        } else if (!started) {
            started = true; tell({ type: 'started' });
        }
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

        return fetch(API + '/upload', { method: 'POST', headers: h, body: data, credentials: 'omit' })
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
        var base = open && !document.hidden ? 3000 : 20000;
        timer = setTimeout(poll, Math.min(60000, base * (1 + failures)));
    }

    // ---- start ----------------------------------------------------------------------------------------------
    function begin() {
        creds = load();
        call('POST', '/init', {}).then(function (r) {
            if (r.status === 404 || r.status === 403) { thread.appendChild(el('div', 'card', 'The chat is not available right now.')); return; }
            if (r.status !== 200) { thread.appendChild(el('div', 'card', 'The chat could not start. Please try again later.')); return; }

            creds = { token: r.json.token, sig: r.json.sig }; save(creds);
            config = r.json.config; visitor = r.json.visitor;
            $('title').textContent = config.title;

            if (config.files) {
                fileInput.accept = config.files.accept;
                attachButton.classList.remove('hidden');
            }

            var messages = r.json.messages || [];

            if (!messages.length) {
                var welcome = el('div', 'row team'); welcome.appendChild(el('div', 'bubble', config.welcome));
                thread.appendChild(welcome);
            }

            messages.forEach(function (m) { addMessage(m); });
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
        }).catch(function () { thread.appendChild(el('div', 'card', 'No connection. Please try again later.')); });
    }

    composer.onsubmit = function (event) { event.preventDefault(); var text = box.value.trim(); if (text) { send(text); } };
    box.addEventListener('input', function () {
        resize();
        var now = Date.now();
        if (started && box.value.trim() !== '' && now - lastTyped > 4000) { lastTyped = now; call('POST', '/typing', {}).catch(function () {}); }
    });
    box.addEventListener('keydown', function (event) { if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); composer.requestSubmit(); } });
    attachButton.onclick = function () { fileInput.click(); };
    fileInput.onchange = function () { var file = fileInput.files && fileInput.files[0]; fileInput.value = ''; if (file && config && config.files) { upload(file); } };
    $('close').onclick = function () { tell({ type: 'close' }); };
    document.addEventListener('visibilitychange', schedule);

    window.addEventListener('message', function (event) {
        if (event.source !== parent || event.origin !== ORIGIN || !event.data || event.data.lqChat !== true) { return; }

        if (event.data.type === 'page') { page = { url: event.data.url, title: event.data.title, referrer: event.data.referrer }; }
        if (event.data.type === 'visible') {
            open = !!event.data.open;
            if (open) { unread = 0; scroll(); if (!composer.classList.contains('hidden')) { box.focus(); } }
            schedule();
        }
    });

    tell({ type: 'ready' });
    begin();
})();
@endverbatim
</script>
</body>
</html>
