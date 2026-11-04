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
