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
- Live updates are Livewire polling (`wire:poll`), never websockets: the hosting has no daemons. The bell checks
  every 15 seconds and toasts what arrived; dashboards, the leads list and the inbox refresh by themselves.
  A chart inside `wire:ignore` carries a `wire:key` built from its data so it redraws when the numbers change.
  The Chart instance lives in a closure, never on Alpine's reactive `this` (a proxied Chart recurses on update).
  A row that joins a `lq-live` list is lit by `app.js` (`lq-arrived`), never by a CSS rule on every row.

## Conventions
- Form Requests for validation, Policies for access, Actions/Services for business logic,
  queued Jobs for slow work. `declare(strict_types=1)` everywhere. Pint (laravel preset) and
  Larastan level 6 must pass: `composer lint`, `composer analyse`, `composer test`.
- Heroicons only, plus the service marks in `x-ui.brand-icon` (Facebook, Messenger). One primary (orange)
  button per screen. Plain words on user screens.
- When a helper script rewrites a file, never write the result of a failed `preg_replace` (it returns null and
  blanks the file): use `str_replace`, and check the length before writing.
- Commit after each meaningful step. Never commit `.env` or secrets.
