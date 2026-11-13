# Voice calls from the website chat box: plan

User request, 12 Nov 2026: "a call button as well" in the live chat, with WebRTC, free services only.
Status: **built on 13 Nov 2026 (1.58.0)**, tested with a real call between two headless browser pages on the dev machine.
Not yet tried: an iPhone, a live website, a website that serves the chat through liquify-chat.php.

## What the user gets

- A phone button in the chat box's header. The visitor clicks it, allows the microphone, and the CRM rings.
- In the CRM a ringing card (any screen, bottom right) with the visitor's name, Accept and Decline. The first
  person to accept gets the call; the others see "answered by …".
- Both sides talk in the browser. Mute and Hang up on both sides, a running clock.
- Each call leaves lines in the chat for the team ("Voice call · 3 min 12 s · answered by Sara", "Missed call") and
  a missed call puts an entry in the bell.

## Decisions

Taken by the user:
1. Free services only. No VPS, no paid telephone service.
2. Relay: ExpressTURN free plan (the user opened the account on 12 Nov; tested from the dev machine, a relay
   address came back in 0.3 s). 1,000 GB a month by their website, a fixed username and password.

Confirmed by the user on 13 Nov, with these changes: the team can send an invitation to call (a message with a
button; still only the visitor calls); a call rings for everybody who works the brand's website visitors, whoever
the chat is assigned to; the switch is on by default ("build it first, then we see"); recording later:
3. Voice only, no video.
4. No recording.
5. Only the visitor starts a call; the team cannot call a visitor in version 1.
6. Who rings: whoever may `reply` to the chat (`ConversationPolicy::reply`): the person the chat is assigned to,
   else everybody who sees visitor chats; Admins and the brand's Managers always. Upsell-only people never.
7. Direct call where possible, the relay only when needed (not "every call through the relay"): the free relay has
   one location, seen in France, and a Melbourne visitor's relayed call would take a detour. The price is that the
   two browsers see each other's IP address in a direct call. Named on the privacy page.
8. The button is off by default; an Admin switches it on per chat box in the designer.

## Why this fits the hosting

Hostinger Business runs up to 5 Node.js apps, but they cannot accept WebSockets, open their own ports or keep
workers (Hostinger's limits page, updated 29 Oct 2026), so Node.js cannot be the relay or a signalling server.
None is needed: the sound goes browser to browser, and the two set-up messages go through the CRM's own PHP
addresses. No new PHP package, no JavaScript library.

## How a call is set up (no trickle ICE)

Each side waits until its browser has finished collecting its addresses (at most 3 seconds) and then sends one
complete description. So a call needs only two messages, which suits polling:

1. Visitor clicks the button → `POST /chat/api/call/ice` gives the STUN and relay servers → microphone →
   offer → `POST /chat/api/call` with the offer. The server stores a `ringing` call, writes the event line, rings
   the team (bell entry, `Live::touchChat`, the Ably ping).
2. An agent clicks Accept → `POST /calls/{call}/accept` claims the call in one UPDATE (`where status = ringing`),
   so only one person wins, and returns the offer and the servers → microphone → answer →
   `POST /calls/{call}/answer`.
3. The visitor's chat box asks `GET /chat/api/call/{id}` every second while it rings, gets the answer, and the
   call is connected. While talking both sides ask every 3 seconds: that is the heartbeat.
4. Hang up from either side: `POST …/end`. A page that closes sends it with `sendBeacon`.

Ends by itself: ringing for 45 seconds with no answer is a missed call; a side not heard from for 20 seconds
during a call ends it. Both are worked out when a call is read, no scheduler involved.

The offer and answer contain IP addresses. They never go to Ably (Ably only carries "changed", as now) and are
wiped from the row the moment the call ends.

## Data

Table `chat_calls`, behind `LiveChat::callsReady()` (`KnownSchema::table('chat_calls')`):

| Column | What |
|---|---|
| `brand_id`, `conversation_id` (cascade) | whose call |
| `status` | ringing, connecting, active, ended, missed, declined, failed |
| `answered_by` | the user who accepted |
| `offer`, `answer` (text, nullable) | set-up messages, emptied at the end |
| `rang_at`, `answered_at`, `ended_at`, `ended_by` (visitor / team / timeout) | times |
| `visitor_seen_at`, `agent_seen_at` | heartbeats |

Model `ChatCall` (`BelongsToBrand`). Deleted with its chat (`ChatErasure`, `chat:prune`, `DeleteLeadForever` go
through the conversation; the foreign key cascades). No sound is ever stored.

## Settings

- `.env`: `CHAT_TURN_URL`, `CHAT_TURN_USERNAME`, `CHAT_TURN_PASSWORD` → `config('liquify.chat.turn')`. Never in
  the repository. Empty = calls still work for the roughly 4 in 5 that connect directly.
- STUN: `stun:stun.cloudflare.com:3478` and `stun:stun.l.google.com:19302`.
- The relay's password is handed to a browser only for a call (visitor: `call/ice`, rate limited; agent: on
  accept), never in the frame's page or the config answer. It is still a fixed password on the free plan, so
  anybody who starts a call can read it; the harm is the relay's quota, and "Refresh Credentials" in the
  ExpressTURN dashboard ends it. Changing provider later is three lines of `.env`.
- Chat box: `appearance.call` (`'0'` default) → `ChatWidget::design()['call']`; the frame also needs
  `callsReady()` and somebody online to show the button.

## Files

New:
- `Chat/Database/Migrations/…_create_chat_calls.php`
- `Chat/Models/ChatCall.php`
- `Chat/Actions/StartCall.php`, `AnswerCall.php` (accept + answer), `EndCall.php`
- `Chat/Support/CallServers.php` (the ICE server list)
- `Chat/Http/Controllers/TeamCallController.php` (incoming, accept, answer, show, end, decline)
- `tests/Feature/Chat/ChatCallsTest.php`

Changed:
- `Chat/Support/LiveChat.php` (`callsReady`), `ChatServiceProvider.php` (limiters `chat-call`, `chat-call-poll`,
  each per token and per IP through `ChatProxy::ip()`)
- `Chat/routes/public.php`, `routes/web.php` (team routes under `auth`, outside a brand so an Admin on
  "All brands" also rings; each asks `can('reply', $conversation)`)
- `Chat/Http/Controllers/WidgetController.php` (call endpoints, `settings()['call']`)
- `Chat/resources/views/frame.blade.php` (button, call bar, the WebRTC code)
- `Chat/resources/widget/widget.js` line 447: `allow` gets `microphone`
- `Chat/Models/ChatWidget.php`, `Chat/Livewire/App/ChatWidgets.php`, `partials/designer.blade.php` (the switch and
  the preview's button)
- `Chat/Support/ChatNotifier.php` (`calling`, `missedCall`)
- `resources/js/app.js` (Alpine `callDesk`: listens to the chat stamp, asks `/calls/incoming`, rings with Web
  Audio, holds the call), `resources/views/components/layouts/app.blade.php` (the card, in `@persist` so a call
  survives `wire:navigate`), `resources/css/app.css` (`lq-call-*`)
- `resources/views/legal/privacy.blade.php` and `config/liquify.php` `legal.updated`: calls are not recorded,
  what a call keeps (who, when, how long), ExpressTURN as the relay, IP addresses in a direct call
- `resources/changelog.php` (1.58.0), `CLAUDE.md`

## Phases

1. **Server**: migration, model, actions, both sets of endpoints, limits, tests. No screen changes. Safe to deploy.
2. **Chat box**: the button, the call bar, the microphone, `allow="microphone"`. Tested locally against phase 1.
3. **CRM**: the ringing card, accept, the call bar, bell entries, event lines.
4. **Around it**: the designer's switch, privacy page, changelog, CLAUDE.md, role crawl (Admin, Manager, Agent,
   Upsell agent), lint, analyse, tests.
5. **Real calls**: two browsers on the dev machine, then the live site: Chrome, Edge, Firefox, Android Chrome and
   an iPhone.

## Tests (Pest)

- A visitor starts a call only when the box has calls on, the tables exist and the token is valid; a second
  ringing call for the same chat replaces nothing and is refused.
- Two agents accept at once: one wins, the other gets "already answered".
- An Agent cannot accept a chat assigned to a colleague; an Upsell-only person gets no incoming calls; a stranger 404.
- Offer and answer are emptied when the call ends; the event lines and the missed-call bell entry are written.
- Ringing over 45 seconds reads as missed; a silent side for 20 seconds ends the call.
- The relay's password is not in `/chat/api/config`, `/chat/api/init` or the frame's page.
- Limits: per token and per IP.

## Risks and what is not known yet

- **iPhone Safari** in a chat box that is an iframe from another address: `allow="microphone"` is documented for
  Chrome; for current Safari only old reports were found. Needs a real phone. Websites using `liquify-chat.php`
  serve the frame from their own address and avoid the question.
- **The proxy file and call set-up**: `liquify-chat.php` passes `api/…` paths, so the new `api/call…` paths should
  go through unchanged. To check in `ChatProxyTest`.
- **A website's own `Permissions-Policy` header** can forbid the microphone for frames. Then the button says the
  call could not start and offers the chat.
- **ExpressTURN free plan**: port 3478 only (a strict office firewall may block it), one location, no promise how
  long the plan stays. If the relay stops answering, direct calls carry on and Admins get a bell entry.
- **The agent must have the CRM open** in a tab. A push alert can ring with it closed; answering opens the tab.
- Not a phone call: nothing here rings a telephone number.

## Later, only if asked

Video, recording with consent, the team calling a visitor, transferring a call, a call from a lead's page.
