# LiquifyCRM — working notes for AI assistants

The architecture document in `docs/LiquifyCRM — MVP Architecture (Laravel).docx`
is the single source of truth. Read it before changing behaviour. Build happens
in the document's weekly phases; never skip a phase or silently change the design.

## Stack
- Laravel 13, PHP 8.4, MySQL/MariaDB, Livewire 4 + Laravel Fortify (Tailwind 4, Vite, Chart.js,
  blade-heroicons), Pest 5. Filament was removed on the user's decision (8 Oct 2026): never
  reintroduce it; every screen is a Livewire page on LiquifyCRM's own layout and design system.
- Only packages from the document's "Libraries and packages" table (plus the approved
  blade-ui-kit/blade-heroicons and chart.js). Ask before adding any other.
- Hostinger shared hosting: database queue drained by the scheduler (one cron), no Redis, no daemons.

## Local toolchain (Windows, XAMPP)
- PHP 8.4 lives in `C:\php84` (XAMPP ships 8.2, which is too old). Prepend it to PATH:
  `export PATH="/c/php84:$PATH"` in Git Bash. `composer` there is a wrapper for the global composer.phar.
- MariaDB from XAMPP: databases `liquifycrm` (dev) and `liquifycrm_test` (Pest, configured in phpunit.xml).
- Run the app with `php artisan serve` (http://localhost:8000). Build assets with `npm run build`.

## Layout
- `app/Modules/<Module>/` holds Models, Policies, Actions, Jobs, Http, Database/Migrations,
  `Livewire/Admin|App` (full-page screens) and `resources/views/livewire`. Each module has a
  `<Module>ServiceProvider` extending `App\Modules\Core\ModuleServiceProvider` and is registered
  in `bootstrap/providers.php`.
- Two areas routed in `routes/web.php`: `admin.*` under `/admin` (`global-admin` / `super-admin`
  middleware) and `brand.*` under `/b/{brand}` (the brand is bound in `BrandsServiceProvider`,
  `brand.member` middleware sets `CurrentBrand`; strangers get 404). Screens use
  `#[Layout('components.layouts.app')]`, the `x-ui.*` components and the traits in
  `App\Modules\Core\Livewire\Concerns` (WithToasts, WithConfirmation, WithTableState).
  `Menu::for()` builds the sidebar; `AppNotification` is the one bell + email notification.
  The switcher at the top of the sidebar moves between "All brands" (the admin area) and each brand; the
  account menu (profile, password, sign out) is the user card at the bottom. Modals, sheets and confirm
  dialogs teleport to `<body>` (`x-teleport`), because a blurred or animated parent would trap a fixed element.
- Brand isolation: `BelongsToBrand` trait (global scope from `CurrentBrand`), Policies, permission
  names in `App\Modules\Core\Enums\Permission`. Super Admin/Admin are `users.role`; Manager/Agent are
  brand memberships mirrored into spatie teams (team = brand_id).
- Roles (user decision, 9 Oct 2026): an Admin has the same control as a Super Admin (People, System, logs,
  every permission). The only difference is protection: nobody else can edit, suspend or remove a Super
  Admin, only a Super Admin can make another one, and the last one cannot be removed. Admins and Super
  Admins add Admins, Managers and Agents on `/admin/people` (`PeopleIndex`, `PersonForm`); people carry a
  `designation`.
- Forgotten password (user request, 12 Oct 2026): besides Fortify's emailed link, `/forgot-password/authenticator`
  (`AuthenticatorResetController`, `ResetPasswordWithAuthenticator`) takes the email, the authenticator code and a new
  password. The code is the only proof there, so keep its guards: wrong tries counted per email and per address, one
  answer for every reason, an audit line and a notice to the person, and never sign anybody in from that screen.
- Public pages (user request, 12 Oct 2026): `/privacy` and `/data-deletion` (`resources/views/legal`, layout
  `x-layouts.legal`, linked under every guest card). No sign-in; the Meta app's settings point at both. The name and
  contact address come from `config('liquify.legal')`. They state what the app stores and for how long, so when
  that changes (a new source, a retention setting, a new outside service) change the pages and their `updated` date.
- Audit trail: always log through `App\Modules\Core\Support\Audit::log(...)`.
- Website leads (`app/Modules/Sites`): `POST /api/v1/ingest/site/{site_key}` only verifies and stores an
  `InboundEvent`; the `IngestLead` job normalises it and calls `CreateLead`. Pull mode and health checks
  reuse that pipeline (`PullSiteLeads`, `SiteHealthChecker`). Secrets use encrypted casts and never reach a view.
- Facebook (`app/Modules/Meta`): `/webhooks/meta` verifies `X-Hub-Signature-256` and stores `InboundEvent`s;
  `ProcessMetaEvent` turns them into leads (Lead Ads, via `MetaGraph`) or `Conversation` / `Message` rows (Messenger).
  One global connection (`MetaConnection`), Pages mapped to brands (`MetaSource`, not brand-scoped). Each channel
  registers its job with `InboundEvent::processWith()` so the event log can retry anything.
- Sales (`app/Modules/Sales`): a lead only becomes Won through `RecordSale` (amount required); it creates or updates the
  `Client`. `SalesReport` produces every dashboard number for a scope (one brand, narrowed by role); `Progress` is the
  achieved-versus-target maths; `DistributeLeads` moves leads in bulk. The exchange rates screen was removed on the
  user's decision (9 Oct 2026): the admin sales dashboard shows one brand at a time in its own currency, never a
  converted total. Do not bring cross-currency totals back without asking.
- Round robin: a department's `assignment_mode` is `manual` or `round_robin` (set on the Distribute screen by its
  Manager or an Admin). `AutoAssignLead` gives a lead that lands in a round-robin department to the next active
  Agent in turn; it is called from `SendLeadToDepartment`, `CreateLead` and `DistributeLeads`.
- After the first sale (user request, 12 Oct 2026). The user's flow: front sales works a lead until the first sale,
  then the client goes to the Upsell department (project managers and upsellers), who sell more to the same client
  until the client is closed. A department's `kind` is `sales` or `upsell`: read it with `Department::kind()` /
  `isUpsell()`, never the attribute. `HandOverClient` moves a client to an Upsell team (from the Mark as Won dialog or
  the won lead); `AssignClient` chooses who looks after it and who else works on it (`client_user`); `RecordUpsell`
  adds a sale with no lead (`sales.kind = upsell`) under the name of whoever sold it; `CloseClient` ends it. A client's
  stage (`ClientStage`) is worked out from its columns. `CareTeams` lists the Upsell teams and parses a choice
  ("12" a team, "12:34" a person in it).
- Front sales must not see what Upsell sells (the user's rule): every access check goes through `ClientPolicy` and
  `Client::visibleTo()` / `inCareOf()`. The seller keeps the lead, its sale and the contract they added; a front
  Manager sees a client only until it is handed over; scoped reports (`SalesReport::topClients`) count only what the
  viewer's team sold. Do not show `lifetime_value` or a client's sales to anyone who is not on the client.
- Client storage: `ClientFile` is a row of the `files` table the architecture document designs for Phase 2
  (`fileable` is the client, or the lead front sales added it from; `ClientFile::ofClient()` is the whole storage).
  Files live on the private disk under a random name and are served only by `ClientFileController`, which asks the
  policy; only PDFs and pictures open in the browser. `ClientNote` is text a client shared (the body has an encrypted
  cast and never goes into a log); a hidden one is shown by `reveal()`, which writes who looked. Choosing files again
  in a Livewire upload adds to the list, so every picker shows the chosen files with a way to take one out
  (`x-sales::picked-files`).
- Where new leads go: `lead_routes` holds, per website and per Facebook Page (its lead forms and its Messenger chats
  share one row), the department and optionally the person. `LeadRouting::resolve()` is applied in `CreateLead` unless
  the caller already chose or the import is `silent`; a person who left falls back to the department. Set by Admins on
  the Distribute screen.
- New tables and columns of that update are behind `ClientCare::ready()` (one `Schema::hasTable`, remembered): code is
  on the server before an Admin clicks "Update system", and it has to work as before until then. Keep new code that
  touches them behind it. Someone who works only on an Upsell team (`CareTeams::worksClientsOnly`) lands on Clients and
  has no lead screens in the menu.
- Logs are for Admins and Super Admins only (user decision, 12 Oct 2026): the Logs screen (admin area and inside
  a brand), its downloads and the brand dashboard's "Recent activity". Managers and Agents see none of it, and no
  brand role carries a logs permission; do not add one. A lead's own timeline is part of the lead and stays.
  "Update system" re-runs `RolesAndPermissionsSeeder`, so a change to `BrandRole::permissions()` reaches the server.
- Logs (`app/Modules/Activity`): `LogArchiver` writes daily archive files and is the only code that deletes from
  `activity_log`; entries on a lead's timeline are never deleted. Retention lives in `LogSettings` (spatie settings).
- Updates (`app/Modules/System`): the site deploys itself when `main` is pushed, so what is left for an Admin is to
  apply the new version. `resources/changelog.php` lists every release in plain words; its top entry is the code
  version. `SystemUpdater` says an update is ready when that version differs from the installed one (the last
  successful `DeploymentType::Update` row) or when migrations are pending; `/admin/updates` (`UpdatesPage`) shows
  what changed and the "Update system" button, which backs up, migrates, clears the caches and records the
  version inside the request (no queue, no scheduler). Admins see a bar on every screen until it is applied.
  **Every push that changes what a person sees adds its lines to `resources/changelog.php`** (new version on top:
  last number for fixes, middle number for features). Never cache config or routes on the server: a later
  deploy would run against the stale cache.
- Dashboard numbers go through `DashboardCache` (60 seconds, flushed by the lead events). After changing lead queries run
  `php artisan liquify:speed-check`.
- Design system: `resources/css/app.css` (tokens from the document, plain-CSS `lq-*` components in
  `@layer components`) and `resources/views/components/ui`. Light and dark via the `.dark` class; the choice is
  kept in localStorage and the `lq_theme` cookie, which the layout reads so the mode survives `wire:navigate`.
  One corner scale: 16 px cards (`--lq-radius-card`), 12 px inner panes and menus (`--lq-radius-inner`), 10 px
  controls. Cards use `--lq-card` (translucent in dark mode); only the sidebar, top bar, menus and dialogs blur.
- Working screens (12 Oct 2026): list screens put their views (`lq-views`), search (`lq-search`) and filters on the
  list card; filters in use are chips (`LeadsIndex::chips()`, `removeFilter()`). `LeadPanel` is the lead everywhere:
  slide-over on the list and the pipeline (`PreviewsLead` trait plus the `lead-sheet` partial) and two columns on its
  own page (`page: true`). Its pipeline path calls `moveTo()`; Won always goes through the sale sheet. Pipeline and
  Inbox are full width (`lq-wide` on the root). People outside the team get a coloured avatar (`<x-ui.avatar tone>`);
  due dates go through `<x-ui.due>`. In a table the name cell is `lq-td-person` (it takes the free width and cuts long
  text short) and nice-to-have columns are `lq-col-wide` (hidden below 1340 px): a table must fit its card at 1280 px.
- Record screens (12 Oct 2026): a list or a record starts with a row of small facts (`<x-ui.mini>` in `lq-minis`).
  A record's own page is a hero plus `lq-split` (the story on the left, a 22 rem rail of facts on the right).
  Targets are blocks per department (`lq-tgroup`, `target-row` partial); the logs are a feed by day (`lq-feed`)
  with the kind of event as views and the other filters as chips. A website's check tiles are `lq-probe`:
  `lq-check` is the checkbox label, so never reuse that name. A bar that Livewire shows with `wire:dirty` goes
  inside a wrapper (`lq-floatbar-hold`), because Livewire sets its own `display` on the element.
- Error pages live in `resources/views/errors` (one `page.blade.php`, a line per code) and use the sign-in look;
  they must work without the database or a signed-in person. Menus are solid, not see-through: a menu inside the
  blurred top bar cannot blur what is behind it. A pivot role is an enum (`DepartmentRole`), never compare it with
  a string.
- Live updates (12 Oct 2026), never websockets: the hosting has no daemons. `App\Modules\Core\Support\Live` keeps a
  stamp per brand and per person that moves on every change (`BelongsToBrand` models, `Message`, a new bell entry).
  `app.js` asks `GET /api/live` every 4 seconds with the signed token on `<main data-live>` (no session, one cache
  read) and, when a stamp moved, reloads the roots marked `data-live-refresh`, fires `lq-changed` (the board) and
  `lq-notified` (the bell). A new screen that shows shared data gets `data-live-refresh` on its root, never a short
  `wire:poll`; a form that people type into (Targets, lead form) does not. `wire:poll.60s` stays as a fallback.
- Nothing a person waits for depends on the scheduler's minute: `AppNotification` writes the bell entry at once
  (`viaConnections`, only the email is queued), and `RunSoon::dispatch()` runs a job right after the response
  (incoming website and Meta events, Messenger replies). Only for jobs that keep their own failure on the record.
- The lead slide-over (`lead-sheet` partial) is always in the page and shown with `x-show="$wire.open !== null"`:
  a click sets `$wire.open` in the browser first, so it opens at once with a skeleton. Dialogs and slide-overs are
  solid (`--lq-panel`) and only the backdrop's dim fades: a blurred panel inside a blurred, fading backdrop flickers.
- Speed (measured on the live site, 12 Oct 2026): the server answers in 25 to 160 ms, but one round trip from the
  user's network costs about 270 ms, so what counts is fewer round trips and showing something before the answer
  comes. Sidebar links are `wire:navigate.hover` (the page is fetched on hover; only for screens whose GET changes
  nothing, never a chat link, which marks the chat read). Chart.js is a separate chunk, imported by the `chart`
  Alpine component on first use. Keep the page-enter animation short (280 ms).
- A lead's details (name, phone, email, company, address, service) are changed through `UpdateLeadDetails`; the
  dialog is the `EditsLeadDetails` trait plus the `lead-details` partial, on `LeadPanel` and in the Inbox. Someone
  who sees email and phone masked never gets them shown there. The Inbox assigns with `AssignLead` from its side
  pane (a select) or, below 1440 px where that pane is hidden, from the two buttons in the thread's head.
- A Messenger contact's name and picture come from `SyncContactProfile` (`MetaGraph::contactProfile()` asks the
  profile, then the Page's own chats for the name, then the picture alone; again once a day, the picture's address
  expires). Meta shares them only for people the app may see, and never a profile link.
- Chat (12 Oct 2026): a reply is sent inside the request (`SendMessengerReply::dispatchSync`) and its bubble is
  drawn by Alpine before the answer (`sending` on the thread root, inside `wire:ignore`). A reply written on
  Facebook itself arrives as an echo (`message_echoes` is in `MetaSource::FIELDS` and must be ticked in the Meta
  app's webhook) and is stored as an outgoing message with no sender; the echo of our own app is skipped by
  `app_id`. Attachments are shown from Facebook's address out of `messages.raw` (`Message::attachments()`), never
  copied: the privacy policy says so.
- A brand with a logo is a light tile ringed in its colour (`lq-avatar-logo`), the logo `contain`ed with padding.
  `Brand::primaryColor()` skips a colour that says nothing (near white or black) and falls back to the second one.
  A chart inside `wire:ignore` carries a `wire:key` built from its data so it redraws when the numbers change.
  The Chart instance lives in a closure, never on Alpine's reactive `this` (a proxied Chart recurses on update).
  A row that joins a `lq-live` list is lit by `app.js` (`lq-arrived`), never by a CSS rule on every row.
- Add brand (user request, 13 Oct 2026): `BrandWizard` is four steps (basics, look, teams, check) and its first
  screen asks for the website alone. `BrandWebsite::details()` reads the site (schema.org data first, then links and
  tags, then its contact page; a page is kept five minutes) and fills the name, link, currency and time zone
  (`Countries`, from the country) and the company details. What somebody typed (`$typed`) is never replaced.
- Company details (phone, email, address, registered name, company number, tax number) live in
  `brands.settings.company`, on purpose not in columns: no screen waits for "Update system". Read them with
  `Brand::company()` / `legalName()`; the brand's invoices and contracts will. Every writer of `settings` merges.
- A brand's colours (the user's rule, 13 Oct 2026): the website's colour is `colors.secondary`, and `colors.primary`
  is white, the tile behind the mark (`Brand::tileColor()`, passed as `:tile` to `<x-ui.avatar>`, `--brand-tile`). A
  logo on a tile of its own colour disappears. `primaryColor()` is still the brand's own colour for chips and charts,
  so older brands (colour in `primary`) look as before. `BrandIdentity::fetch()` also stores the main logo
  (`brands/logos/fetched-{id}`; WebP and AVIF as PNG for documents). It leaves out a white logo on a see-through
  background, refuses an SVG with a script, and never replaces a picture somebody uploaded (only `fetched-` files).
- `StarterTeams` / `CreateStarterTeams`: Front Sales (kind `sales`), Upsell and Project Management (both kind
  `upsell`) in one click, in the wizard and on the Departments screen while a brand has none. A long list is a
  `<x-ui.picker model options pinned>` (search in the browser; time zones come from `Timezones::options()`), not a
  `<select>` of four hundred.
- A lead's screen (user request, 13 Oct 2026): what the person asked for is always in view. `LeadEnquiry` splits
  `leads.fields` into the answers (shown under "What they asked for", written as sentences) and the source facts
  (their own block); there is no Message tab. `LeadPanel`'s blocks are in two groups (`lq-lead-col-main` /
  `lq-lead-col-side`): `display: contents` plus `order` on one column (the slide-over), two columns on a wide page.
  Tabs are Notes (default, with the note box), Follow-ups, Messenger, Timeline. `lq-composer` is the chat's box,
  the note box is `lq-notebox`.
- Assigning from where you are: `<x-leads::assign-menu>` inside an `x-data="assignMenu({...})"` (the list's root,
  the panel's root), opened with `openFor($el, {lead, dept, owner})`; `quickAssign` / `quickSend` on `LeadsIndex` and
  `LeadPanel`. `AssignableAgents::people()` leaves out people who only work clients, and `find()` is the server's
  check. The menu is teleported and `position: fixed` (a table's scroll box or the slide-over would cut it).
- One person, one lead: a Facebook lead form carries `inbox_url`, whose number is the person's Messenger id on that
  Page (`LeadMatcher::messengerId()`). `ProcessMetaEvent` joins a form to the chat's open lead (`MergeLeads::join`)
  and a chat to the form's open lead (`LeadMatcher::byMessenger`). `LeadMatcher::twins()` finds existing pairs for
  the panel's hint; `MergeLeads::handle()` merges two open leads (whoever may `assign` both). Never merge on a name
  alone: a name is only a hint. A lead's timeline moves with it (`activity_log.subject_id`), nothing is deleted.
- Instagram direct messages (user request, 14 Oct 2026) use Meta's Instagram API with Facebook Login: the same app,
  webhook and Page token as Messenger. `meta_sources.instagram_id` / `instagram_username` is the professional account
  linked to a Page (read by `ConnectFacebook::syncPages`), so the account follows its Page's brand and `lead_routes` row.
  An `object: instagram` webhook entry is the account and the sender an IGSID; the event is `ProcessMetaEvent::INSTAGRAM`,
  the chat `channel = instagram` (the @username in `contact_handle`), the lead `LeadSource::Instagram`. Echoes come in
  `messages` with `is_echo`; a deleted message (`is_deleted`) is stored as `mid:deleted` and wipes the words. Replies
  carry no `messaging_type` and fit `Conversation::replyTooLong()` (1,000 bytes). The new columns are behind
  `Instagram::ready()`. Code that shows a chat asks `isInstagram()` / `channelLabel()` instead of saying "Messenger".
- A click on a lead in the list or the pipeline opens the slide-over, always; the row carries no link to the full
  page (it stole clicks). "Open full page" in the slide-over builds its address from `$wire.open` in the browser.

## Conventions
- Form Requests for validation, Policies for access, Actions/Services for business logic,
  queued Jobs for slow work. `declare(strict_types=1)` everywhere. Pint (laravel preset) and
  Larastan level 6 must pass: `composer lint`, `composer analyse`, `composer test`.
- Heroicons only, plus the service marks in `x-ui.brand-icon` (Facebook, Messenger). One primary (orange)
  button per screen. Plain words on user screens.
- When a helper script rewrites a file, never write the result of a failed `preg_replace` (it returns null and
  blanks the file): use `str_replace`, and check the length before writing.
- Commit after each meaningful step. Never commit `.env` or secrets.
