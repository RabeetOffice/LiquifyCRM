# LiquifyCRM — done and pending

Checked against `docs/LiquifyCRM — MVP Architecture (Laravel).docx` on 8 October 2026.
State of the code: 84 Pest tests passing, Pint and Larastan level 6 clean.

## At a glance

| Phase in the document | Status | Notes |
| --- | --- | --- |
| Week 1 · Foundation | **Done** | Rebuilt on 8 October without Filament (see "Agreed differences") |
| Week 2 · Leads core | **Done** | Website and Facebook sources arrive in weeks 3 and 4 |
| Week 3 · Website sources | **Next** | Needs the LeadsHub / LuminoCRM folders for the health-check port |
| Week 4 · Meta | Pending | Needs Meta app credentials |
| Week 5 · Sales and targets | Pending (departments done) | |
| Week 6 · Hardening and launch | Pending (theme and isolation tests done) | |
| Phase 2 · Files, NDA, client portal | Pending | Tables designed in the document, not created (agreed) |

## Done

### Project and stack
- [x] Laravel 13 on PHP 8.4, Livewire 4, Tailwind 4, Vite, Pest 5, Pint, Larastan level 6.
- [x] Packages: laravel/fortify, livewire/livewire, sanctum, spatie permission / activitylog / backup / health / schedule-monitor / settings / query-builder, laravel-excel, laravel-phone, league/color-extractor, deployer, blade-ui-kit/blade-heroicons (icons), chart.js (dashboard charts).
- [x] Module layout `app/Modules/<Module>` (Activity, Auth, Brands, Core, Departments, Leads, System), each with its own service provider, migrations, policies, actions, jobs, Livewire screens and views.
- [x] Two areas: `/admin` (Super Admin and Admin) and `/b/{brand}` (brand workspace; the brand in the URL is the tenant, bound before every other route parameter).
- [x] Own design system in `resources/css/app.css` (tokens from the document, plain-CSS components) and a Blade component set (`resources/views/components/ui`): buttons, fields, cards, stat cards, badges, status pills, tables, tabs, modals, slide-over, confirm dialog, toasts with Undo, empty states, pagination.
- [x] Shell: full-height sidebar with logo, brand switcher, menus by role and user card; top bar with greeting, scheduler status, Ctrl K / ⌘K search, notification bell, theme toggle and avatar menu. Light and dark follow the device with a manual switch.
- [x] README with local setup, env keys, Hostinger and deploy steps.

### Auth and roles
- [x] Laravel Fortify: sign-in, password reset, authenticator-app two-factor with recovery codes, confirm password. Rate-limited login; suspended people cannot sign in.
- [x] Two-factor forced for Super Admin and Admin in both areas (they are sent to Password and 2FA until set up). Switchable with `LIQUIFY_REQUIRE_TWO_FACTOR` (default true; false in the local `.env`).
- [x] Super Admin and Admin as `users.role`; Manager and Agent as brand memberships mirrored into spatie teams (team = brand).
- [x] All 21 permission names from the Permissions table, seeded by `RolesAndPermissionsSeeder`.
- [x] Super Admin protection: `is_protected`, nobody else can edit, suspend or delete one, the last Super Admin cannot be removed.
- [x] Admin accounts screen (create, edit, suspend, remove with password re-confirm).
- [x] Sign-in audit: login, logout, failed login, password reset, password change, 2FA on and off.
- [x] Profile (name, email, phone, timezone, photo) and Password and 2FA screens.
- [x] `php artisan liquify:install` seeds roles and creates the first Super Admin; `DemoSeeder` and `LeadsDemoSeeder` give a local install brands, people and sample leads.

### Brands and sharing
- [x] Brands screen: list with search and filter, form with basics (name, address, website, currency, timezone, active), look (logo, favicon, two colours) and rules (reveal limit per hour, notification emails).
- [x] Brand switcher in the sidebar showing only the person's brands; global admins see every brand.
- [x] Brand isolation layer 1: `/b/{brand}` binding plus `brand.member` middleware (404 for strangers, 403 for suspended members); `BelongsToBrand` global scope; layer 2: policies; layer 3: permission names. Tested: an Agent of Brand A gets 404 on every Brand B URL.
- [x] Members screen per brand: invite by email (modal; signed link, expiry, accept page), add a person with a password, change role and status, suspend / reactivate, remove (password re-confirm; their open leads return to the queue), pending invitations with resend and revoke.
- [x] Departments per brand: list, form, and people management (add a brand member with a role, change role, remove).
- [x] Audit trail helper `Audit::log()`; `activity_log` carries brand, department, IP and browser. Logged areas: auth, brands, departments, members, leads, system.

### Leads (week 2)
- [x] Tables `lead_statuses` (the document's six per brand, created with every brand), `leads` (soft deletes, unique brand + source + external id, indexes and full-text), `lead_notes`, `tasks`.
- [x] `LeadPolicy` and `Lead::visibleTo($user)`: Admin all, Manager own department, Agent assigned only.
- [x] Leads list: saved views (All, New, Unassigned, Mine / Open, Follow-ups today, Won), filters (status, source, department, owner, assigned, dates), search by name, email or phone, sortable columns, 50 rows per page, 300 ms search wait, bulk assign / send to department / change status / delete, CSV export (logged), filters kept in the URL.
- [x] Lead slide-over (and full page for notification links): the five essentials first, then Timeline, Message, Notes and Follow-ups tabs; actions assign, send to department, change status, add note, follow up on, reveal, delete; keyboard shortcuts A, S, N, F; Undo on assign and status toasts for 10 seconds.
- [x] Pipeline (kanban) with drag and drop and the same visibility rules.
- [x] Follow-ups screen (overdue, today, upcoming, done) with mark done and reopen.
- [x] Add a lead by hand (phone normalised to E.164; a repeat enquiry from a known email or phone joins the existing lead's timeline).
- [x] CSV / Excel import with column mapping guessed from the headings; original dates kept, duplicates merged, rows tagged Imported.
- [x] New / Unassigned queue on the brand dashboard: count badge, oldest first, one-click assign. Admin sends to a department, Manager assigns to an Agent.
- [x] Masked contact details for Agents, Reveal logged with who, lead, time and IP, hourly limit from the brand setting.
- [x] Notifications: in-app bell plus email to the department's Managers on new leads (Admins while unrouted), to the Agent on assignment, and to the brand's notification emails.
- [x] Dashboard cards: new leads today and last 7 days with change, unassigned (or my open leads), overdue follow-ups, conversion, leads per day, by source, by status, recent activity. Agents see their own numbers.
- [x] Sidebar by role: Agents see My leads, Pipeline and Follow-ups; Managers and Admins also Departments and Members.
- [x] Events for later automations: LeadCreated, LeadAssigned, LeadStatusChanged.

### System screen and deployment
- [x] System screen (Super Admin): version, environment, pending-migrations banner, Backup and run database update with password re-confirm (backup first, then `migrate --force`, output kept), Back up now, retry failed jobs, release rollback (password re-confirm), health checks, queue and cron health, deployment history with logs, latest backups. Refreshes itself while a job runs.
- [x] `POST /hooks/deploy/{token}` records each deployment and tells Super Admins when database updates wait.
- [x] GitHub Actions: `ci.yml` (Pint, Larastan, Pest) and `deploy.yml` (`main` → production, `develop` → staging) with Deployer release folders and symlink switch, then the deploy hook.
- [x] Scheduler for the single Hostinger cron: queue drained by the scheduler, heartbeat, health checks, daily database backup and clean-up, pruning.

### Agreed differences from the document
- **Filament removed (8 October, user decision).** The document chose Filament for the dashboard; the user did not like the result and asked for a custom panel. Auth is now Laravel Fortify (which the document also lists), screens are Livewire 4 pages on LiquifyCRM's own layout and design system. Two packages were added with the user's approval: blade-ui-kit/blade-heroicons for the Heroicons the document specifies, and chart.js for dashboard charts.
- `spatie/laravel-webhook-client` and the Facebook PHP SDK dropped; webhooks and Graph API calls will use plain controllers and the Laravel HTTP client.
- Departments built in week 1 instead of week 5.
- Per-brand settings stored in `brands.settings` (json); `spatie/laravel-settings` reserved for global settings (exchange rates, retention).
- Phase 2 tables not created yet.
- Meta App Review treated as optional (only your own Business Manager connects).
- A repeat enquiry on a Won lead creates a new lead (linked to the client from week 5).
- Ingest endpoint will answer 202 Accepted.
- Light page background is `#f4f4f2` (a shade below the document's `#FAFAF9`) so white cards stand out, and stat-card icons sit in rounded tiles rather than circles, both from the reference designs the user chose.

## Pending

### Week 3 · Website sources (next)
- [ ] Tables `sites` (site key, encrypted secret, push / pull mode, last lead at) and `site_checks`; `inbound_events` with Retry.
- [ ] `POST /api/v1/ingest/site/{site_key}`: HMAC-SHA256 of timestamp.body, 5-minute replay window, de-duplication on site + external id, raw payload stored, 202 Accepted, rate limit 120 per minute per source.
- [ ] `IngestLead` job on top of the existing `CreateLead` action: resolve source to brand, normalise, de-duplicate, queue, timeline entry, notifications.
- [ ] Sites screen and the 3-step Connect website wizard: pre-filled `liquify.php` download, the 2 lines to paste, Test connection, green "Connected" badge with last lead time.
- [ ] Pull mode (`PullSiteLeads` every 5 minutes) for sites that cannot be touched; Elementor webhook on the same URL.
- [ ] Per-site health checks ported from LeadsHub / SiteWatch: form, SMTP, database, SSL expiry, uptime; red / amber / green; amber plus email when a site is quiet for 48 hours.
- [ ] Brand SMTP (encrypted `mail_settings`) for the optional auto-reply to the customer.
- [ ] Site favicon and platform badge (PHP, WordPress, Elementor) on the Websites screen.

### Week 4 · Meta
- [ ] Tables `meta_connections`, `meta_sources`, `conversations`, `messages`.
- [ ] Connect Facebook once (Login for Business now, System User token for production), Integrations screen mapping each Page to its brand.
- [ ] `GET / POST /webhooks/meta` with `X-Hub-Signature-256`; Lead Ads fetched from Graph API v26; Messenger messages attached by PSID.
- [ ] Inbox: Messenger threads for visible leads, 24-hour reply window indicator, replies from the lead page.
- [ ] Webhook event log with Retry; token refresh in the scheduler; Cloudflare tunnel for local webhook tests.
- [ ] Facebook and Messenger logos (local SVGs in `resources/svg/brands/`).

### Week 5 · Sales and targets
- [x] Departments (done in week 1).
- [ ] People profiles: photo, login email, password or invite, department, role and monthly target in one form (photo and profile exist; targets do not).
- [ ] Mark as Won sheet (amount, brand currency, service or package, close date); `sales` and `clients` tables; lifetime value; repeat purchases attach to the same client.
- [ ] Monthly targets at three levels (brand, department, person) as revenue, count or both; Targets month grid with live progress and "copy last month".
- [ ] Progress bars and Over-achieved badges; leaderboard.
- [ ] Reporting currency and monthly exchange rates screen (`exchange_rates`), cross-brand totals in GBP.
- [ ] Admin sales dashboard with drill-down: sales this month, brand performance, department ranking, agent leaderboard, top clients, lead sources, speed to first contact. Managers see their department, Agents their own.
- [ ] Clients screen.
- [ ] Brand favicon and colours fetched automatically from the website (column `identity_fetched_at` and league/color-extractor are ready); weekly refresh.
- [ ] Old lead import from a website's leads table and two-level distribution (equal, percent, count, manual) with the `lead_distributions` log (CSV / Excel import with mapping already exists).

### Week 6 · Hardening and launch
- [x] Brand-isolation test suite (done in week 1).
- [x] Apple-style theme, light and dark (done; polish continues as screens land).
- [ ] Logs screen: filters by brand, user, event and date; search by lead; 7 / 15 / 30-day downloads built in the queue with a 24-hour link; delete before a date (Super Admin, password re-confirm, never the last 7 days); retention settings; storage card.
- [ ] Nightly log archive (`logs/YYYY/MM/date.jsonl.gz`, 180 days), `log_archives` and `log_exports` tables, pruning of `inbound_events` and daily log files.
- [ ] Dashboard numbers cached 60 seconds per brand and refreshed on new leads.
- [ ] Speed checks: 100,000 leads under 300 ms, production caches (`Model::preventLazyLoading()` is already on in development).
- [ ] Off-server backup destination (S3-compatible or FTP) in `config/backup.php`; today backups stay on the server.
- [ ] PWA manifest for add-to-home-screen (touch icon exists).
- [ ] Onboarding: 3-step Add brand wizard with a test at the end, 3-tip welcome tour for new Agents and Managers, inline "?" help next to settings.
- [ ] Log retention entries for messages and integrations (the areas arrive with their modules).
- [ ] First staging deploy and the first client (UK Publishing House) with its Managers and Agents; Meta App Review if ever needed.

### Phase 2 and later
- [ ] Files module, NDA and contracts, client portal (in that order), then email inbox, automations, reports, Instagram DMs, mobile app, WhatsApp, Viewer role, commissions, invoices, forecasts, automatic exchange rates, monthly PDF report.

## Needed from you before the next phases
- Week 3 can start now: say "continue".
- Week 3: the LeadsHub and LuminoCRM source folders (the document's open question) so the health checks and the pull-mode lead import can be ported file by file.
- Week 4: Meta app id and secret, Business Manager access, and the Cloudflare tunnel hostname.
- First deploy: Hostinger SSH key, host, user and port as GitHub secrets, plus the staging subdomain.
- Backups: where off-server backups should go (Cloudflare R2, Backblaze, FTP).
