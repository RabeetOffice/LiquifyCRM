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
- One person in several brands (user decision, 14 Oct 2026): only Admins share brands, on People › Edit (`PersonForm`
  `addBrand` / `changeBrandRole` / `changeBrandDepartment` / `removeBrand`, policy `UserPolicy::shareBrands`). Each brand
  has its own `brand_user` row, role and spatie team, so a person can be Manager in one brand and Agent in another;
  `ChangeBrandAccess` keeps their department seats in that brand in step with the role. Managers still add people to
  their own brand, but `BrandSharing::refusal()` stops them adding (or inviting) somebody who works in another brand or
  is already in theirs. Never let a Manager's action reach a person's other brands.
- Role audit (14 Oct 2026), rules that keep it clean: what a screen hides must also be refused on the server. A
  method behind a password dialog starts with `ensureConfirmed()` (`WithConfirmation::$confirm` is `#[Locked]`); a
  public property that unlocks something (`showRecoveryCodes`, `ClientShow::$revealed`) is `#[Locked]`. Every way of
  assigning a lead goes through `AssignableAgents::find()` / `for()` (never somebody who only works clients); leads are
  never sent to an Upsell department. A link to a record is shown only when `can('view')` would let it open. Outside a
  brand (profile) a Manager or Agent gets the menu and search of `Menu::homeBrand()`, never the admin menu. An
  invitation never signs in an existing account (it signs in first). On a client the viewer is not on, money and
  sales come from `Sale::visibleTo()`, as on the client page. Re-crawl every screen per role when screens change
  (Admin, preview Manager, preview Agent, Upsell agent login files in storage/app/private).
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
- One file per PHP website (user request, 16 Oct 2026; the LeadsHub "Hub Agent" was removed): `liquify.php`
  (`resources/stubs/liquify.php.stub`, `LiquifySnippet::file()`) goes in the website's ROOT folder. It reads
  written-out `define('DB_…', '…')` from `includes/config.php` with the tokenizer; a config that works them out when
  it runs (Purple Giraffe: `define('DB_NAME', pgp_setting(…))`) is included at the file's TOP level (its variables must
  be global), only after the signature check, output discarded. It finds the `leads` table and answers
  signed questions (`SiteConnector`: `ping`, `leads`, `diagnostics`; `X-Liquify-Timestamp` / `X-Liquify-Signature`,
  the site secret, 5 minutes) and nothing else. Such a site is `mode = pull`, `pull_config.driver = file`
  (`Site::readsThroughFile()`); `sites:pull` runs every minute (`PullSiteLeads` is unique per site) and its first run
  only notes `max_id`. The same file's `liquify_send()` (two optional lines after `$db = log_lead_to_db($lead)`) sends
  at once with the row id as `external_id`, so push and pull dedupe. Keep the stub PHP 7.4-safe and
  self-contained. `driver = database` reads the database straight from LiquifyCRM. Pushes from the older
  `includes/liquify.php` are still accepted; `ConnectionTester` moves a push PHP site to the file once it answers.
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
  `activity_log`, apart from `DeleteLeadForever`; entries on a lead's timeline stay as long as the lead does and go only
  when it is deleted forever. Retention lives in `LogSettings` (spatie settings).
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
- LiquifyCRM's logo (user request, 20 Oct 2026): rising bars with an arrow. `<x-ui.logo>` shows `liquify-logo.webp` (dark
  lettering) or `liquify-logo-dark.webp` (white lettering) by mode; `liquify-mark.webp` is the bars alone (narrow sidebar).
  The browser tab is the orange tile (`favicon.ico`, `favicon-96.png`); the installed app, home screen and desktop alerts
  are the dark tile (`icon-180/192/512.png`, `icon-maskable-512.png`), all in `public/images/brand`. The offline page
  carries its icon inline (no server there). Show the logo through the component, not as text beside a mark.
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
- Alerts and the installed app (user request, 14 Oct 2026): `NotificationBell::tick()` dispatches `lq-alert` with up to three
  new entries; `app.js` plays a chime made with Web Audio (no file) and asks `public/sw.js` for desktop notifications, which it
  shows only while no LiquifyCRM window is focused. Sound and desktop alerts are choices of the computer (localStorage
  `lq_sound` / `lq_desktop`, "On this computer" under the bell); one tab claims each entry (`lq_alerted`). While alerts are
  on, a hidden tab still asks `/api/live`, every 15 seconds. The service worker sends page opens straight to the server and
  keeps only `offline.html`: never cache app pages or build files there (a deploy must be used at once), and a failure to
  keep the offline page must never stop it installing. Its fetch handler is what lets Chrome offer "Install app" (account
  menu, bell).
- Web Push (user request, 14 Oct 2026; package `minishlink/web-push`, approved): desktop alerts also with LiquifyCRM closed.
  `push_subscriptions` (behind `WebPush::ready()`) holds each browser's endpoint and encrypted keys; `PushSettings` holds the
  VAPID pair, made on first use by `WebPush::keys()` (Windows PHP needs `OPENSSL_CONF` for that; on failure it waits an
  hour). Every new bell entry of someone with a subscription goes out through `SendWebPush` (RunSoon, one try, tag = the
  bell entry's id, so the page path and the push never show twice); 404/410/403 forget the browser. The page subscribes on
  every load while desktop alerts are on and posts to `/push/subscription`, which accepts only the browser makers' push
  hosts (the server posts there: never loosen that list). Signing out deletes this browser's row (session `push_endpoint`).
  `sw.js` shows a push unless a LiquifyCRM window is focused, and keeps the sound choice for closed-app pushes in Cache
  Storage. Tests swap `PushTransport` for a fake and use a fixed key pair.
- Sign-in pages (user request, 14 Oct 2026): `x-layouts.guest` is a split screen (form side `lq-auth-side`, artwork
  `lq-auth-art` with decorative floating cards from 1024 px). Pages that wrap their form in `.lq-glass` lose the card
  inside `.lq-auth-panel`. Error pages keep their own markup (`lq-guest-*`).
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
- Front sales sees its team (user request, 19 Oct 2026): `Lead::scopeVisibleTo` adds, for anyone on a front (non-Upsell)
  team (`User::frontDepartmentIds()`), that team's leads and the leads with no department. `scopeWorkedBy` is the old
  rule (own leads, a Manager's departments) and is what `LeadPolicy::update` / `reveal` / `recordSale`, `Task::visibleTo`,
  an Agent's dashboard numbers and the Pipeline's draggable cards use: a colleague's lead is read-only (contact masked,
  its money only through `Sale::visibleTo()`, its chat not marked read, only its owner replies). A free lead is taken
  with `TakeLead` (row lock, `LeadAlreadyTaken` names who was first) through `TakesLeads::takeLead()` on the list,
  panel, dashboard and Inbox (`inbox-take`): it logs "Taken by", opens the contact as a reveal and offers Undo (`take`).
  Take is the one way to get a lead that skips `AssignableAgents`; `LeadPolicy::take` guards it. `AssignableAgents`
  offers Managers too (the asker first, "(you)"); round robin stays Agents only. `LeadNotifier` gives a free lead's
  front people a bell (sound, desktop, push) without email.
- Archived leads (user request, 19 Oct 2026): `leads.archived_at` / `archived_by`, behind `LeadArchive::ready()`.
  `ArchiveLead` (handle, `bringBack`) for whoever may `assign` the lead (`LeadPolicy::archive`; `archiveAny` for the
  Archived view and the bulk buttons); Agents do not archive. An archived lead stays visible and openable but is out
  of every working list: `unassigned()` / `queue()` leave it out by themselves, and the list's views, Pipeline,
  Inbox list, `Task::visibleTo`, an Agent's counts and the menu badges use `unarchived()`. Arrival numbers (charts,
  Overview, reports) still count it. Whatever means the person got in touch again calls `Lead::heardFrom()` instead
  of `touchActivity()` (repeat enquiry unless `silent`, `MergeLeads::join`, an inbound message, a tapped ad), which
  brings the lead back with a timeline line. A new working list of leads must use `unarchived()`.
- When a lead arrived (user request, 19 Oct 2026): `<x-ui.received :at>` (`inline` for one line) shows "6h ago" over
  the day and time for three days, then the date over the day and time, in the viewer's Profile time zone (else
  `liquify.default_timezone`). Most other times in the app are still shown in UTC.
- Won stays Won (user request, 19 Oct 2026): `ChangeLeadStatus` throws `LeadIsWon` when a lead with a sale
  (`Lead::hasSale()`) would leave Won; every caller catches it (panel, pipeline, bulk status, undo) and the path and
  pipeline card stand still. A wrong Won is undone only by `CorrectSale::delete` (Admins), which frees the lead.
- Part payments (user request, 19 Oct 2026; the user chose that the whole deal counts towards targets): `sales.amount`
  is the deal, `sales.amount_paid` what is in, each payment a `sale_payments` row (behind `SalePayments::ready()`; the
  update gives every older sale one payment of its full amount). `RecordPayment` (`handle` never takes more than is
  owed; `opening` for "Paid now" in `RecordSale` / `RecordUpsell`), `SalePolicy::recordPayment` (seller, their
  Manager, the client's care team, Admins), the `RecordsPayments` trait with `sales::partials.payment-sheet` and
  `<x-sales::sale-payments>`. A payment is never a new sale: targets, lifetime value and lead value read `amount`.
- Resources (user request, 19 Oct 2026; module `app/Modules/Resources`): `BrandResource` (table `resources`, behind
  `BrandResources::ready()`) is a brand's template or material, a file (a `files` row, fileable the resource, saved by
  `SaveResource`, served only by `ResourceFileController`) or a link. Everyone in the brand sees and downloads; Admins
  and Managers manage (`BrandResourcePolicy`). In every brand menu.
- Invoices (user request, 19 Oct 2026; `spatie/laravel-pdf` with its DOMPDF driver, from the architecture document's
  package table, called with `->driver('dompdf')` so no config is needed): `CreateInvoice` numbers per brand and year
  (`invoices`, behind `Invoices::ready()`, a snapshot of who, what, total and paid), renders it with `InvoicePdf`
  (`sales::invoices.pdf`: tables and DejaVu Sans only, DOMPDF has no flex or grid; the mark from `InvoiceLogo`, which
  passes raster logos, turns WebP/AVIF into PNG, keeps an SVG only when plain, else a 96 px+ favicon, else initials) and keeps the PDF as a client file (category invoice, on
  the lead for a front sale). `SalePolicy::invoice` = the people of `recordPayment`; the `CreatesInvoices` trait with
  `sales::partials.invoice-sheet` never fills in a contact the person sees masked. Number prefix, bank details, due
  days and note are `brands.settings.invoice` (`Brand::invoiceSettings()`), edited on the Admin brand form.
- Copy a lead (user request, 19 Oct 2026): `LeadEnquiry::copyText()` (name, phone, email, source, message and
  answers, contact masked as the viewer sees it) behind the copy button in the lead's hero.
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
  `messages` with `is_echo`; a deleted message (`is_deleted`) is stored as `mid:deleted` and wipes the words; a
  `referral` alone (a returning person tapped an ad) puts the ad on the open lead (`leads.ad_tapped`). Replies
  carry no `messaging_type` and fit `Conversation::replyTooLong()` (1,000 bytes). The new columns are behind
  `Instagram::ready()`. Code that shows a chat asks `isInstagram()` / `channelLabel()` instead of saying "Messenger".
- Connect Facebook must stay quick (live 503 on 14 Oct 2026: Hostinger cuts a long request): `MetaGraph::pages()` brings
  the Pages, their tokens and Instagram accounts in one call, and `SubscribePages` (via `RunSoon`, after the answer)
  subscribes only Pages that need it (`force` on Refresh Pages). Never add a Graph call per Page inside a request.
  A refused reply is shown through `MetaException::forPeople()` (368 is Meta blocking the account, not us).
- A click on a lead in the list or the pipeline opens the slide-over, always; the row carries no link to the full
  page (it stole clicks). "Open full page" in the slide-over builds its address from `$wire.open` in the browser.
- The bin (user request, 16 Oct 2026): `LeadsBin` at `/b/{brand}/leads/bin` (Admins only, `LeadPolicy::restoreAny`; Settings
  menu and a Bin button on Leads). `Lead::inBin()` is a deleted lead with a `leads.deleted` line on its timeline, so every
  way of deleting a lead must log that line. A merged lead has none (its history moved) and is never in the bin: restoring
  it would make an empty twin. `RestoreLead` brings a lead back and sends it to the queue if its team or owner is gone.
- Delete forever (user decisions, 16 Oct 2026): every Admin (`LeadPolicy::forceDelete`), password asked again.
  `DeleteLeadForever` removes the lead, its notes, follow-ups, chat (conversation and messages), inbound events, bell
  entries linking to it, its timeline and the leads merged into it, and writes one `leads.deleted_forever` line with no
  personal details. A sale stays (its `lead_id` becomes null) and so does the client; files added on the lead move to the
  client (deleted from disk when there is none). `leads:empty-bin` (nightly, 00:50) does it after `KEEP_DAYS` (30). The
  privacy pages promise those 30 days: change them with it. A new table that keeps a lead's data must be wiped there too.

## Conventions
- Form Requests for validation, Policies for access, Actions/Services for business logic,
  queued Jobs for slow work. `declare(strict_types=1)` everywhere. Pint (laravel preset) and
  Larastan level 6 must pass: `composer lint`, `composer analyse`, `composer test`.
- Heroicons only, plus the service marks in `x-ui.brand-icon` (Facebook, Messenger). One primary (orange)
  button per screen. Plain words on user screens.
- When a helper script rewrites a file, never write the result of a failed `preg_replace` (it returns null and
  blanks the file): use `str_replace`, and check the length before writing.
- Commit after each meaningful step. Never commit `.env` or secrets.
