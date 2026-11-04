# Live chat for LiquifyCRM — plan (3 Nov 2026, nothing built yet)

Source: `LIVECHAT_SPEC.md` (Rabeet's wish list). This file is how it fits LiquifyCRM as it is today.
Where the two differ, this file wins; the spec stays the list of things we want.

## User decisions (3 Nov 2026)
1. Build it inside LiquifyCRM, as one more channel of the existing Inbox (not a separate app).
2. **A chat is not a lead until the visitor gives details** (email or phone, in the form or in the chat).
   Anonymous chats must never fill the Leads panel (the problem Messenger has today).
3. Free AI only. No paid API.
4. No VPS. Hostinger shared hosting runs PHP; a Node app can run next to it (also wanted later for WhatsApp).
5. The website chat has things Messenger/Instagram do not (page trail, files, notes, forms, canned replies,
   visitor online status…): the Inbox must show them where they exist and stay quiet where they do not.
6. Plan first, build later, phase by phase (project rule).

## What changes compared with the spec
| Spec | Here |
|---|---|
| New `chat_conversations`, `chat_messages` | Reuse `conversations` / `messages` (`channel = livechat`). One Inbox, one search, one unread count. |
| Reverb + Redis + Supervisor | Not possible. Polling first (the `Live` stamps we have), a small Node relay for instant delivery if the Hostinger test passes. |
| Claude API | Free provider behind an `AiProvider` interface (see AI). No new package: plain `Http`. |
| `app/Http/Controllers/Chat`… | `app/Modules/Chat` with its own ServiceProvider, like every other module. |
| Own roles/policies | Brand membership + `ConversationPolicy` already used by the Inbox. |
| Redis presence | `Cache` with a TTL (database cache), keys per brand. |
| GeoLite2 | Needs the `geoip2` package (ask first). Until then country from the CDN header if Hostinger sends one. |

## The lead gate (core rule)
A live chat has two states:
- **Visitor** — no lead. Lives only in the Inbox tab "Visitors". Agents/AI can talk to them. Auto-deleted after N days
  (setting, default 14) if no details ever arrive. Not counted in lead numbers, dashboards or targets.
- **Contact** — the moment a valid email or phone arrives, `CreateLead` runs (source `LiveChat`, brand of the widget,
  `LeadRouting::resolve()` + round robin as for any lead). The chat moves to Open and shows the lead beside it.

How details arrive: (a) pre-chat form per brand (fields chosen per brand), (b) a "details card" the bot or agent sends in
the chat (name + email/phone, validated), (c) an email/phone typed in a message, found by regex (not by AI), asked back
as "Is this the best way to reach you?" before it becomes a lead. A name alone never makes a lead.
Junk filter: email syntax + disposable-domain list, phone 7–15 digits, honeypot, rate limit.
Same person again: `LeadMatcher` by email/phone → the chat attaches to the existing lead (`Lead::heardFrom()`),
no second lead. No manual "Create lead" button in V1.
Optional later: the same gate for Messenger/Instagram per Page (today they make a lead for every chat).

## Inbox fit (channel capabilities)
One `ChannelCapabilities` map decides what each channel's thread and side pane show.
| Feature | Messenger | Instagram | Live chat |
|---|---|---|---|
| 24-hour reply window | yes | yes | no |
| Text, receipts | yes | yes | yes |
| Files both ways | limited | limited | yes (private disk, signed links) |
| Typing indicator | no | no | yes |
| Internal notes, system lines | yes | yes | yes |
| Canned replies (`/`) | yes | yes | yes |
| Visitor card: page now, page trail, UTM, referrer, country/device, visits | – | – | yes |
| Online/offline dot | – | – | yes |
| AI handling label + "Take over" | – | – | yes |
| Forms / cards in chat (details, book intake, booking) | – | – | yes |
| Rating, transcript email | – | – | yes |
Inbox tabs: Open · Mine · Unassigned · **Visitors** (no details yet) · AI handling · Closed; channel chip filter.
Message gets `kind` (text, file, note, event, card) and `sender_type` (visitor, agent, ai, system) columns.

## Real-time on Hostinger shared hosting
Transport order, the widget and the Inbox try in turn: WebSocket (Node relay) → SSE (Node relay) → polling (Laravel).
- **Polling (always works, ships first):** agents use the existing `/api/live` stamps (4 s). The visitor widget polls a tiny
  endpoint: 2 s while a chat is open, 30–60 s heartbeat otherwise. One cache read per call.
- **Node relay — RULED OUT for WebSockets by Phase 0 (see findings); only an idea for a VPS or an SSE experiment:** a small Node app holds the connections; Laravel pushes "something changed"
  (conversation id, no message text) with a shared secret; clients then fetch from Laravel. Node never touches the database.
  Same relay later serves WhatsApp if we choose the unofficial QR route; the official WhatsApp Cloud API needs only a PHP
  webhook, like the Meta module.
- **Spike first (half a day), before any chat code:** on the company Hostinger plan, can a Node app (a) stay alive,
  (b) accept WebSocket upgrades through the CDN, (c) stay inside memory/CPU limits with ~200 idle connections?
  If not: SSE, or a free-tier hosted channel (Pusher/Ably) carrying only pings, or polling alone.
- Heavy polling from every brand site is the cost to watch (hosting entry-process limits). The widget therefore does
  nothing on pages where nobody opened the chat, apart from one lightweight presence ping.

## AI (free, replaceable)
- `AiProvider` interface + a chain with fallback: first choice a free-tier model (Google Gemini Flash-class, Groq, Cloudflare
  Workers AI, OpenRouter free models are the candidates). **Check each one's current free limits, region rules and data-use
  terms before choosing** — free tiers change and some use prompts for training.
- Privacy: only the chat text goes out, with emails/phones/names replaced by placeholders. Extraction of contact details
  is local regex, so the provider never sees them. The privacy pages name the provider and the 30-day style retention
  (CLAUDE.md rule: change the pages with the feature).
- Order of answering: brand FAQ/quick replies first (no AI), then AI from the brand's knowledge box, then hand to a person.
  AI never states a price that is not in the knowledge box. Labelled "Virtual assistant" + "Talk to a person".
- Caps per chat, per brand, per month; every call logged (`chat_ai_usage`). Failure or cap → offline form.
- Modes per brand: off · only when nobody is online · after N seconds without reply · always first.

## Tables (new, small)
`chat_widgets` (brand, key, domains, look, pre-chat fields, ai mode) · `chat_visitors` (token, name, email/phone encrypted,
lead_id, geo, device, visits) · `chat_page_views` · `chat_triggers` · `chat_ai_settings` · `chat_ai_usage` · `chat_bans` ·
`canned_replies`. On `conversations`: `chat_visitor_id`, `chat_widget_id`, `assigned_user_id`, `status`, `ai_active`, `utm`,
`rating`. On `messages`: `kind`, `sender`. All behind `LiveChat::ready()` so the code can be on the server before
"Update system" (project convention). Delete-forever and the privacy pages must cover the new tables.

## Build phases (stop and review after each)
0. Spike: Node on Hostinger + free AI limits + decide transport. No product code.
1. Foundation: module, tables, widget CRUD with embed snippet, channel-aware Inbox shell, `Visitors` tab.
2. Widget: loader + iframe, visitor token, send/receive by polling, pre-chat/details card, **lead gate** with tests.
3. Inbox features: assign/transfer/close, notes, canned replies, files, typing, visitor card, agent status, alerts.
4. Real-time upgrade (Node relay) if phase 0 allowed it.
5. AI: provider chain, knowledge box, hand-over, caps.
6. Live visitors page, invites, triggers.
7. Reports, transcripts, ratings, retention pruning, log lines (`Audit::log`), changelog entries.
8. Hardening: XSS, rate limits, origin checks, role crawl per role, speed check.
Later (spec Phase 2/3): book intake card, manuscript upload, AI summary and scoring, WhatsApp, booking, translate, SLA.

## Hosting and pilot (user answers, 4 Nov 2026)
- Node.js: the user's other Hostinger account runs a Node app (Next.js, GitHub auto-deploy) on the same plan the company
  account (chiraag) has, so a Node app can be deployed. **But Hostinger's own docs say Node apps on this plan cannot
  accept inbound WebSockets** (Phase 0 findings below), so a Node WebSocket relay is not possible here.
- Pilot brand: **Dublin Publishing Press** (`dublinpublishingpress.ie`; local clone folder is named `.com` but the site's
  domain inside is `.ie`; its repo is RabeetOffice/dublinpublishingpress, footer at `includes/footer.php`).
  The user adds the one script line to the site; we never edit the brand sites.
- Everything is built for **many brands from day one**, the pilot is only the first widget switched on.

## Multi-brand design
- One CRM, one relay (one Node app), one widget loader file (`/chat/widget.js`) for all brands. A brand = one
  `chat_widgets` row (key `wk_…`, allowed domains, look from the brand's colours and logo, pre-chat fields, AI mode,
  routing). A brand with several sites lists several domains on one widget.
- Adding a brand's chat: Admin/Manager creates its widget (Brand › Live chat), copies the one-line snippet, the user pastes
  it into that site's footer. No code change, no deploy per brand.
- Isolation: chats are `BelongsToBrand`; the Inbox shows only the viewer's brands (Admins all, with the brand filter);
  the relay uses one room per brand and per conversation, joined only with a short-lived HMAC token that Laravel signs
  (brand id, conversation id or agent id, expiry). The relay holds no database and no AI key.
- Per-brand settings: AI mode and knowledge box, canned replies (global + brand + personal), triggers, bans, retention,
  transcript email, who sees unassigned chats, lead department/person (reuses `lead_routes`).
- Relay address: its own subdomain on the company domain (e.g. `live.luminocrm.online`) as a second Hostinger website
  (Node). To be confirmed in Phase 0.

## Visitors the Tawk.to way (user decision, 4 Nov 2026)
Anonymous visitors are visible and can be talked to, exactly like Tawk.to: a Live Visitors list ("Visitor 1234", country,
current page, time on site, visits, chat status) per brand; an agent can open any visitor, see the page trail, send an
invite or start a chat, and reply to anyone who writes, with or without details. Still no lead until email/phone arrives
(the lead gate above). So the Inbox has two doors to the same chat: Live Visitors (who is on the site now) and the Visitors
tab (anonymous chats). Phase 6 (live visitors page) moves up to sit right after Phase 3 so agents get this early.

## Phase 0 findings (4 Nov 2026, research only; nothing deployed)
**Hosting.** Hostinger's support page "Node.js on web and cloud hosting" says: inbound WebSockets are not supported (an
app may only open outgoing ones), every request is stopped after 120 seconds, background workers are not supported
(cron instead), and all sites on a plan share one process limit (NPROC 120 on Business/Unlimited; reaching it gives 503).
The company account has two sites today (luminocrm.online, one `hostingersite.com` addon), both non-Node.
So: no WebSocket relay, no Reverb, no always-on Node worker on this hosting. WhatsApp: the official Cloud API needs only a
PHP webhook (fits); an unofficial QR library (Baileys) needs an always-on process (does not fit).
**Real-time options, best first:**
1. Polling from Laravel (adaptive, one cache read per call) behind a `Transport` interface in the widget and Inbox. Ships in V1.
2. A free hosted channel (Ably or Pusher free tier) carrying only "something changed" pings; Laravel publishes over HTTP,
   browsers connect to the provider. Instant delivery, no server of ours. Check each one's free connection/message limits first.
3. A small VPS for the Node relay (the user said no VPS; listed for completeness).
4. SSE or long-poll through a Node site, each held request under ~100 s and reconnecting. Unproven; it also spends the shared
   process limit. Only worth a test if option 1 turns out too slow.
Watch item for option 1: every visitor poll is a short PHP request; the CRM shares the plan's process limit, so the widget
sends nothing until the chat is opened, plus one light presence ping.
**Free AI** (numbers are from third-party pages and drift; confirm in each console before building):
- Google Gemini free tier: prompts may be used to improve Google's products and reports say the free tier may not be used to
  serve users in the EU/EEA, UK or Switzerland. Our brands serve UK, Ireland, EU and ANZ visitors, so do not use it for them.
- Groq: free limits per model (about 30 requests/min; ~14,400 requests/day on the 8B model, ~1,000/day on the 70B one, token
  caps bind first); docs say Zero Data Retention can be switched on by any customer and training on inputs needs consent.
  First candidate (with ZDR on).
- Cloudflare Workers AI: 10,000 free Neurons/day, no overage on the free plan; training policy not confirmed. Second candidate.
- OpenRouter `:free` models: low limits tied to purchased credits; prompts are not logged by OpenRouter by default but the
  provider behind each free model varies. Backup only.
Plan: FAQ/quick replies first (no AI call), then the provider chain Groq → Cloudflare, switchable per brand. Needs the user
to create the provider accounts and API keys (stored in `.env`, never in code or chat).

## Free servers and realtime services (from free-for.dev's README, read 4 Nov 2026; limits as listed there, verify before use)
- Managed realtime, no server of ours: **Ably** 3M messages/month, 100 peak connections; **Pusher** 100 connections,
  200k messages/day; **Scaledrone** 20 connections; **PubNub** 1M transactions/month; **Synadia NATS** 50 connections.
  Our use: only pings ("conversation 123 changed", no text), connections only for open chats and agent tabs.
- Free VM: **Oracle Cloud Always Free** (2 small AMD VMs, or Arm cores with up to 12 GB per that page, 10 TB egress; idle
  instances may be reclaimed; card needed; pick a European region). **Google Cloud e2-micro** (always free, US regions only).
  **Koyeb** free (550 hours/month, not a full month). Render/others sleep when idle (not suited).
- Also not on that page but relevant (from memory, verify): Cloudflare Workers can accept WebSockets.
- Pick for the pilot: Ably free behind the `Transport` interface (no server to look after); if the 100-connection cap or
  the privacy view is a problem, a tiny Node relay on an Oracle Always Free VM in Europe, same interface.

## Decision (4 Nov 2026): Ably free for real-time
Phase 0 is finished. Real-time = polling first (Phase 2/3), Ably free tier for instant pings in Phase 4, behind the `Transport`
interface (Oracle free VM stays the fallback). Ably carries no chat text: Laravel publishes "conversation N changed" with its
REST API; browsers subscribe with short-lived token requests that Laravel signs per brand/conversation (Ably capability per
channel), so a visitor can only listen to their own chat and an agent only to their brands. The Ably API key lives in `.env`
(user creates the account), never in code, JS or chat. Add Ably's name to the privacy pages with Phase 4.
Phase 1 (foundation) is next; stop for review after it.

## Phase 1 built (4 Nov 2026, version 1.44.0)
`app/Modules/Chat`: `ChatWidget` and `ChatVisitor` (tables `chat_widgets`, `chat_visitors`; `conversations.chat_widget_id` /
`chat_visitor_id`; `messages.kind` / `sender_type`; the column is not called `sender` because `Message::sender()` is the agent who wrote it), all behind `LiveChat::ready()`; the settings screen `/b/{brand}/live-chat`
(Admins and the brand's Managers: websites, colour, position, texts, pre-chat fields, "Agents see visitors", on/off, the
one-line snippet with a copy button; `ChatWidgetPolicy`); `ChannelCapabilities` (what each channel can do);
`ChatAccess` (who sees visitors with no lead); `Conversation::CHANNEL_LIVECHAT`, `scopeVisibleTo` / `listedFor` now also
list anonymous website chats, `ConversationPolicy::view` allows them by `ChatAccess` and `reply` refuses them for now;
Inbox: the Visitors view (only anonymous chats; every other view is chats of leads), the Live chat channel filter and a
visitor card. Not built yet: `widget.js`, visitor requests, replying (Phase 2 and 3). Tests: tests/Feature/Chat.
Not yet in this phase (moved on purpose): the `canned_replies`, `chat_page_views`, `chat_triggers`, `chat_ai_*`, `chat_bans`
tables, each arrives with the phase that uses it.

## Open questions for the user
- Anonymous chats kept 14 days by default (not answered, this is the default).
- Which free AI to try first (decided in Phase 0 after checking real limits)?

## Phase 2 built (4 Nov 2026, version 1.45.0)
- Launcher `GET /chat/widget.js` (source `app/Modules/Chat/resources/widget/widget.js`, one hour of browser cache) and chat box
  `GET /chat/frame/{key}?o=<origin>` (Blade page with its own CSS and JS, `Content-Security-Policy: frame-ancestors` of the
  widget's websites, no cookie). Both in `routes/public.php` with no middleware at all: framework CORS answers every website on
  `/api/*`, so the widget's calls live under `/chat/api/*` (config, init, messages GET and POST, details), each with its own
  rate limit (`ChatServiceProvider`).
- A visitor's browser holds a token plus its HMAC signature (`VisitorToken`); requests carry `X-Chat-Key`, `X-Chat-Token`,
  `X-Chat-Sig`. The launcher's `config` call is answered only to an allowed `Origin`, with that origin as the CORS header.
- Lead gate: `CaptureVisitorDetails` (valid email or phone, disposable email list, `PhoneNormalizer::strict`, `CreateLead` with
  `LeadSource::LiveChat`, which also de-duplicates by email or phone). `SendVisitorMessage` refuses empty, over 2,000
  characters, over 2 links; `ChatContacts` finds an email or phone in a message and the widget asks before using it.
- Left for later phases: routing of live chat leads by widget (`lead_routes`, Distribute screen) so they land unassigned for now,
  the offline form and agent presence (Phase 3), typing, files, read receipts, sound, canned replies, replying from the Inbox
  (Phase 3), live visitors, page trail and invites (Phase 6), AI (Phase 5), Ably pings (Phase 4).
- Tests: tests/Feature/Chat/LiveChatWidgetTest.php (14). Checked by eye on a stand-in website page (desktop, phone, returning
  visitor, pre-chat form, suggestion card).

## Phase 3 built (4 Nov 2026, version 1.46.0)
Scope as the spec's Stage 4 (agent inbox): answering, notes, close and reopen, assigning, alerts, typing, agent presence and the offline
form. Canned replies and files are the spec's Stage 8 and are not built yet; read receipts and a manual Online/Away/Offline switch
are not either.
- Columns on `conversations`: `chat_assigned_to`, `chat_status` (open or closed), `chat_closed_at`, `first_response_at` (second
  migration, behind `LiveChat::workReady()`). A chat with a lead is assigned through the lead; `chat_assigned_to` is only for a
  visitor with no lead. Messages: `kind` text, note or event; notes and events never reach the visitor (`VisitorChats::messages`).
- Actions: `ReplyToVisitor` (reply, note, event line), `ChangeChat` (close, reopen, assign), `SendVisitorMessage` (reopens a closed
  chat, rings the bell). `ConversationPolicy::reply` for a visitor's chat: Admins and the brand's Managers; an Agent when nobody has
  it or it is theirs; `work` (close, notes) and `assignChat` (Admins, Managers) are new.
- `ChatNotifier` (bell through `AppNotification`, no email), `ChatTyping` and `AgentPresence` live in the cache only. Presence is
  noted from `Live::read` (every page of a brand asks `/api/live` every few seconds; `Live::whenSeen` is the hook), so nothing runs
  in the background; the chat box's `online` answer is worked out at most every 10 seconds per brand.
- Widget: typing both ways, a closed note, the offline form when `online` is false.
- Tests: tests/Feature/Chat/LiveChatTeamTest.php (10). Whole suite 554 tests pass. Checked by eye: the offline form, an answer and a
  note from the Inbox, close and reopen, the visitor seeing the answer and the closed line.

## Phase 3 built (4 Nov 2026, version 1.46.0)
Scope as the spec's Stage 4 (agent inbox): answering, notes, close and reopen, assigning, alerts, typing, agent presence and the offline
form. Canned replies and files are the spec's Stage 8 and are not built yet; read receipts and a manual Online/Away/Offline switch
are not either.
- Columns on `conversations`: `chat_assigned_to`, `chat_status` (open or closed), `chat_closed_at`, `first_response_at` (second
  migration, behind `LiveChat::workReady()`). A chat with a lead is assigned through the lead; `chat_assigned_to` is only for a
  visitor with no lead. Messages: `kind` text, note or event; notes and events never reach the visitor (`VisitorChats::messages`).
- Actions: `ReplyToVisitor` (reply, note, event line), `ChangeChat` (close, reopen, assign), `SendVisitorMessage` (reopens a closed
  chat, rings the bell). `ConversationPolicy::reply` for a visitor's chat: Admins and the brand's Managers; an Agent when nobody has
  it or it is theirs; `work` (close, notes) and `assignChat` (Admins, Managers) are new.
- `ChatNotifier` (bell through `AppNotification`, no email), `ChatTyping` and `AgentPresence` live in the cache only. Presence is
  noted from `Live::read` (every page of a brand asks `/api/live` every few seconds; `Live::whenSeen` is the hook), so nothing runs
  in the background; the chat box's `online` answer is worked out at most every 10 seconds per brand.
- Widget: typing both ways, a closed note, the offline form when `online` is false.
- Tests: tests/Feature/Chat/LiveChatTeamTest.php (10). Whole suite 554 tests pass. Checked by eye: the offline form, an answer and a
  note from the Inbox, close and reopen, the visitor seeing the answer and the closed line.

## Canned replies and files built (4 Nov 2026, version 1.47.0)
The spec's Stage 8, as the user asked right after Phase 3. Tables `canned_replies` (scope global, brand or personal) and
`chat_attachments`, behind `LiveChat::extrasReady()` (second migration after the work columns).
- Saved replies (UI word; the code says canned): `CannedReply` + `CannedReplyPolicy` (Admins write the company's, Managers the
  brand's, anybody their own), screen `/b/{brand}/saved-replies` for every brand member, and the `/` picker in the message box of
  every chat (Alpine `savedReplies` in app.js; `{visitor_name}`, `{agent_name}`, `{brand_name}` are filled in server-side per
  chat; a visitor with no name is "there"). Not built: a manual list of replies in the widget, or replies with attachments.
- Files: `ChatFiles` (allow list jpg, jpeg, png, webp, gif, pdf, txt, doc, docx; judged by content with finfo, `getimagesize`
  for pictures, a text file that looks like HTML is refused; random name on the private `local` disk under
  `chat/{brand}/{year}/{month}`; size limit per widget in `appearance.max_upload_mb`, default `liquify.chat.max_upload_mb`, at
  most 25). Visitor upload `POST /chat/api/upload` (6 a minute per visitor); the team attaches in the Inbox (Livewire upload).
  Served only by `ChatFileController`: the visitor's browser gets a link signed for six hours (`/chat/files/{id}`, no session),
  the team a signed-in route `/b/{brand}/chat-files/{id}` that asks the chat's policy; pictures inline, everything else
  as a download, with `nosniff` and a sandboxing CSP.
- Retention (`config('liquify.chat')`, the privacy page shows these numbers): `chat:prune` at 00:58 deletes files after
  `file_days` (180) and the chat, files and visitor of somebody with no lead after `anonymous_days` (14), and visitors who only
  looked. `DeleteLeadForever` now also removes a live chat's files from the disk (`ChatErasure`), the visitor rows of the
  lead and the bell entries and log lines that name the visitor. Privacy and data-deletion pages updated.
- Tests: tests/Feature/Chat/LiveChatExtrasTest.php (7). The whole suite passes. Checked by eye: the visitor's chat box with a
  picture and a PDF, the team's thread with them, the `/pr` menu, a picked reply with the names filled in, the saved replies page.
- Still open: read receipts, a manual Away switch, page trail and invites (Phase 6), AI (Phase 5), Ably pings (Phase 4), routing
  of live chat leads by widget.

## Phase 4 built (4 Nov 2026, version 1.48.0): instant signals through Ably
`Chat\Support\Realtime`. Optional: it does nothing without `ABLY_API_KEY` (format `appId.keyId:secret`, from the Ably dashboard,
an API key with Publish and Subscribe), and everything polls as before when Ably is away.
- Server → Ably: `POST /channels/{channel}/messages` with Basic auth, body `{"name":"changed","data":"1"}`, never any text. Sent
  after the response in a web request, at once in a command or test. A brand's chats changed → `Live::whenChatChanged` (hooked in
  `Live::write`, so at most about once a second) → channel `lq-b-{brandId}`. What a visitor sees changed (an answer, close,
  reopen, the team starts typing) → `lq-v-{24 hex of an HMAC of the visitor's token}`.
- Browsers listen with Ably's server-sent events (`EventSource`, no library): `GET {host}/event-stream?channels=…&v=1.2&accessToken=…`.
  The token comes from a signed TokenRequest (`POST /keys/{keyName}/requestToken`; mac = base64 HMAC-SHA-256 over keyName, ttl,
  capability, clientId (empty), timestamp, nonce, each followed by a newline), capability `{channel: ["subscribe"]}` only, one hour,
  kept 50 minutes. The visitor's chat box asks `POST /chat/api/realtime` (visitor headers); a brand page has `data-realtime`
  on `<main>` pointing at `GET /b/{brand}/realtime` (brand members only; 204 when off or Ably does not answer). A page asks for a
  new address after 45 minutes or when its connection is gone.
- In the browser: the CRM's `askLive` rounds run on a signal at once and carry on at their normal pace; the chat box polls every 30
  seconds instead of 3 while its channel is open (a safety net), and returns to 3 seconds when the channel breaks.
- Privacy page: Ably is named only while the key is set. `config/liquify.php` `realtime.ably_host` / `ably_scheme` exist only so
  a stand-in server can be tried locally (Ably's real host is `main.realtime.ably.net`).
- Tests: tests/Feature/Chat/LiveChatRealtimeTest.php (6; Ably faked with `Http::fake`, the token signature recomputed in the test).
  Browser code tried against a stand-in Ably server (token, publish, event stream): the chat box opened its channel, then showed
  the team's answer straight after the publish with no 3-second polling. **Not yet tried against the real Ably** (needs the
  user's account and key): its token request and event-stream formats follow Ably's published docs.

## Phase 4 built (4 Nov 2026, version 1.48.0): instant signals through Ably
`Chat\Support\Realtime`. Optional: it does nothing without `ABLY_API_KEY` (format `appId.keyId:secret`, from the Ably dashboard,
an API key with Publish and Subscribe), and everything polls as before when Ably is away.
- Server to Ably: `POST /channels/{channel}/messages` with Basic auth, body `{"name":"changed","data":"1"}`, never any text. Sent
  after the response in a web request, at once in a command or test. A brand's chats changed: `Live::whenChatChanged` (hooked in
  `Live::write`, so at most about once a second) sends on channel `lq-b-{brandId}`. What a visitor sees changed (an answer, close,
  reopen, the team starts typing): channel `lq-v-{24 hex of an HMAC of the visitor's token}`.
- Browsers listen with Ably's server-sent events (`EventSource`, no library): `GET {host}/event-stream?channels=...&v=1.2&accessToken=...`.
  The token comes from a signed TokenRequest (`POST /keys/{keyName}/requestToken`; mac = base64 HMAC-SHA-256 over keyName, ttl,
  capability, clientId (empty), timestamp, nonce, each followed by a newline), capability `{channel: ["subscribe"]}` only, one hour,
  kept 50 minutes. The visitor's chat box asks `POST /chat/api/realtime` (visitor headers); a brand page has `data-realtime`
  on `<main>` pointing at `GET /b/{brand}/realtime` (brand members only; 204 when off or Ably does not answer). A page asks for a
  new address after 45 minutes or when its connection is gone.
- In the browser: the CRM's `askLive` rounds run on a signal at once and carry on at their normal pace; the chat box polls every 30
  seconds instead of 3 while its channel is open (a safety net), and returns to 3 seconds when the channel breaks.
- Privacy page: Ably is named only while the key is set. `config/liquify.php` `realtime.ably_host` / `ably_scheme` exist only so
  a stand-in server can be tried locally (Ably's real host is `main.realtime.ably.net`).
- Tests: tests/Feature/Chat/LiveChatRealtimeTest.php (6; Ably faked with `Http::fake`, the token signature recomputed in the test).
  Browser code tried against a stand-in Ably server (token, publish, event stream): the chat box opened its channel, then showed
  the team's answer straight after the publish with no 3-second polling. Not yet tried against the real Ably (needs the user's
  account and key): its token request and event-stream formats follow Ably's published docs.

Real Ably check (4 Nov 2026, after the user's key was in the local .env): the signed token request was accepted (HTTP 201), a
subscriber on `lq-b-2` received both published "changed" messages (with `Accept: text/event-stream` the stream is real SSE,
`event: message` and a JSON `data:`, which is what `EventSource` sends), and in the browser the chat box opened its channel and
showed the team's answer straight after the publish. The first token request of the session once timed out after 8 seconds
(network on the dev machine); it worked on every retry, and a failure only means polling, as designed.

## Live chat tab and Live visitors built (4 Nov 2026, version 1.49.0): the spec's Stage 7 (page trail, invites)
- **One sidebar tab** (user request): `Menu::liveChatTab()` builds "Live chat" with children Live visitors (`ChatAccess::canSeeVisitors`),
  Saved replies (everybody in the brand) and Chat boxes (Admins, Managers). A menu item may have `children`; `sidebar.blade.php`
  opens it while a child is shown (narrow sidebar: the icon goes to the first child), `MenuBadgesController` reads the children's
  badges. New screens of the website chat go inside this tab (add a child in `liveChatTab`). The old Saved replies and Settings
  items are gone; the URLs are unchanged.
- **Tracking:** tables `chat_sessions` (a browser, by the `vid` UUID the launcher keeps in the website's own localStorage) and
  `chat_page_views`; `LiveChat::trackingReady()`. The launcher (`widget.js`, `track()`) pings `POST /chat/api/ping` at once and every 30
  seconds while the tab is visible, and on a page change (history API too). A plain-text body holding JSON, so there is no CORS
  preflight; answered only to an allowed `Origin`, which is also its CORS header. `RecordPing` keeps the address without query and
  fragment, a title without tags, the referrer as a host name only; a page outside the widget's websites is not kept. Online means
  seen in the last 90 seconds (`ChatSession::ONLINE_SECONDS`); a return after 30 minutes is a new visit.
- **Live visitors** (`/b/{brand}/live-chat/visitors`, polls every 15 s while visible: presence is time, not a stamp) and the
  Inbox's "Pages they read" (through `chat_sessions.chat_visitor_id`, set when the chat box's `init` sends the `vid`).
- **Invitations** (`InviteVisitor`): creates the visitor and the chat if needed, the team's words as the first message
  (`ReplyToVisitor`), keeps `invite_text` on the session; the next ping hands it over once and the launcher shows a card by the
  button. The chat box waits up to 0.7 s for the page's `vid`, then `init` gives a browser with an invited chat that visitor's token.
  Not built: automatic triggers ("after 30 s on /pricing"), exit-intent.
- Setting `appearance.track_visitors` ("0" turns a widget off); `liquify.chat.page_view_days` (30) with `chat:prune`; sessions and their pages
  go with a lead deleted for good (`ChatErasure`). Privacy page updated.
- The launcher's `config` answer is cached five minutes by browsers (`max-age=300`), so a changed setting reaches a website within five minutes.
- Tests: tests/Feature/Chat/LiveChatVisitorsTest.php (9). Checked by eye on the stand-in website: the nested tab, the list with a
  visitor and the page trail, the invite dialog, the card by the chat button, and the chat opening with the invited words.

### Chats inside the Live chat tab (4 Nov 2026, same version)

The user asked that a website visitor's chat never needs the Inbox. `LiveChats` (`/b/{brand}/live-chat/chats`, first child of the tab, with the unread count) is the Inbox screen kept to `channel = livechat`, views All, Unread, Visitors, Closed. The Inbox is now Messenger and Instagram only (views All, Unread, Can reply; channel row only once Instagram is there, which also fixed the row sticking out of the card). `ChatNotifier` links to the new screen; `ChatErasure` deletes bell entries of both addresses.
