# LiquifyCRM — done and pending

Checked against `docs/LiquifyCRM — MVP Architecture (Laravel).docx` (the copy in Downloads is byte-identical) on 8 October 2026.
State of the code: 7 commits on `main`, 65 Pest tests passing, Pint and Larastan level 6 clean.

## At a glance

| Phase in the document | Status | Notes |
| --- | --- | --- |
| Week 1 · Foundation | **Done** | Plus departments (pulled forward from week 5), brand-isolation tests (from week 6) and the theme (from week 6) |
| Week 2 · Leads core | **Next** | Nothing started; permissions and brand settings for it already exist |
| Week 3 · Website sources | Pending | Needs the LeadsHub / LuminoCRM folders for the health-check port |
| Week 4 · Meta | Pending | Needs Meta app credentials |
| Week 5 · Sales and targets | Pending (departments done) | |
| Week 6 · Hardening and launch | Pending (theme and isolation tests done) | |
| Phase 2 · Files, NDA, client portal | Pending | Tables designed in the document, not created (agreed) |

## Done

### Project and stack
- [x] Laravel 13 on PHP 8.4, Filament 5 (Livewire 4, Tailwind 4, Vite), Pest 5, Pint, Larastan level 6.
- [x] Packages from the document's table installed: filament, sanctum, spatie permission / activitylog / backup / health / schedule-monitor / settings / query-builder, laravel-excel, laravel-phone, league/color-extractor, deployer, larastan, pest, pint.
- [x] Module layout `app/Modules/<Module>` (Activity, Auth, Brands, Core, Departments, System), each with its own service provider, migrations, policies, actions, jobs and Filament screens.
- [x] Two panels: `/admin` (Super Admin and Admin) and `/b/{brand}` (brand workspace, Brand is the Filament tenant).
- [x] README with local setup, env keys, Hostinger and deploy steps.

### Auth and roles
- [x] Login, password reset, profile, two-factor with an authenticator app (recovery codes), rate-limited login.
- [x] Two-factor forced for Super Admin and Admin in both panels. Switchable with `LIQUIFY_REQUIRE_TWO_FACTOR` (default true; false in the local `.env` for now).
- [x] Super Admin and Admin as `users.role`; Manager and Agent as brand memberships mirrored into spatie teams (team = brand).
- [x] All 21 permission names from the Permissions table (`App\Modules\Core\Enums\Permission`), seeded by `RolesAndPermissionsSeeder`.
- [x] Super Admin protection: `is_protected`, nobody else can edit, suspend or delete one, the last Super Admin cannot be removed (10 tests).
- [x] Admin accounts screen in `/admin` (create, edit, suspend, delete).
- [x] Sign-in audit: login, logout, failed login, password reset written to `activity_log`.
- [x] `php artisan liquify:install` seeds roles and creates the first Super Admin. `DemoSeeder` creates two brands with an Admin, Managers and Agents.

### Brands and sharing
- [x] Brands screen in `/admin`: name, slug, website, logo, favicon, two colours, currency, timezone, notification emails, reveal limit per hour, active flag.
- [x] Brand switcher (Filament tenancy) showing only the user's brands; global admins see every brand.
- [x] Brand isolation layer 1: `BelongsToBrand` global scope from `CurrentBrand`; layer 2: policies; layer 3: permission names. Tested: an Agent of Brand A gets 404 on every Brand B URL (11 tests).
- [x] Members screen per brand: invite by email (signed link, expires after `INVITATION_EXPIRY_DAYS`, accept page sets the password), add a person with a password, change role, suspend / reactivate, remove, last sign-in. Pending-invitations widget with resend and revoke.
- [x] Departments per brand (Team group): create, edit, remove; Managers lead, Agents sit in them; Managers edit their own department.
- [x] Audit trail helper `Audit::log()`; `activity_log` carries brand, department, IP and browser. Logged areas so far: auth, brands, departments, members, system.

### System screen and deployment
- [x] System screen (`/admin/system`, Super Admin): version (git commit or `.release`), environment, pending-migrations banner, "Backup and run database update" with password re-confirm (backup first, then `migrate --force`, output kept on the deployment record), "Backup now", retry failed jobs, release rollback, health checks (database, cache, queue, schedule, disk, debug mode, environment, optimized app), queue and cron health, deployment history, latest backups.
- [x] `POST /hooks/deploy/{token}` records each deployment and checks `migrate:status`.
- [x] GitHub Actions: `ci.yml` (Pint, Larastan, Pest) and `deploy.yml` (`main` → production, `develop` → staging) with Deployer release folders and symlink switch (`deploy.php`), then the deploy hook.
- [x] Scheduler for the single Hostinger cron: queue drained by the scheduler, heartbeat, health checks, daily database backup and clean-up, pruning.
- [x] Backups: before every database update and daily (currently to the local `backups` disk).

### Look and feel
- [x] Custom Filament theme with the document's palette and rules: Liquify Orange only for actions and key numbers, ink text on orange, 14 px cards, 10 px controls, round pills, translucent blurred sidebar and top bar, system font stack, light and dark mode following the device with a manual switch, reduced-motion support, 44 px tap targets on mobile.
- [x] Dashboard cards: icon in an orange-tint circle, large number, muted label, footer link; first card is the orange hero (`LiquifyStat`).
- [x] Admin overview and brand dashboard with subheading and one primary button per screen ("Add brand", "Invite by email").
- [x] Setup checklist on the brand dashboard (create brand, create departments, add Managers tick themselves; connect website, connect Facebook, set targets are shown as coming in weeks 3, 4 and 5).
- [x] Branding: droplet logo mark and wordmark in the sidebar, favicon, touch icon, liquid artwork on the login and invitation pages, onboarding illustration on the dashboard. Web-sized copies live in `public/images/brand`; the originals stay in the untracked `LiquifyCRM-images` folder.
- [x] Ctrl K / ⌘K search in the top bar (brands and departments today; leads join in week 2). Notification bell with database notifications.

### Agreed differences from the document
- Filament's built-in auth and two-factor instead of Laravel Fortify.
- `spatie/laravel-webhook-client` and the Facebook PHP SDK dropped; webhooks and Graph API calls will use plain controllers and the Laravel HTTP client.
- Departments built in week 1 instead of week 5.
- Per-brand settings stored in `brands.settings` (json); `spatie/laravel-settings` reserved for global settings (exchange rates, retention).
- Phase 2 tables not created yet.
- Meta App Review treated as optional (only your own Business Manager connects).
- A repeat enquiry on a Won lead will create a new lead linked to the client.
- Ingest endpoint will answer 202 Accepted.

## Pending

### Week 2 · Leads core (next)
- [ ] Tables: `lead_statuses` (per brand: New, Contacted, Qualified, Proposal sent, Won, Lost with the document's colours), `leads` (soft deletes, unique brand + source + external id), `lead_notes`, `tasks`.
- [ ] `LeadPolicy` and `Lead::visibleTo($user)`: Admin all, Manager own department, Agent assigned only.
- [ ] Leads list: filters (status, source, owner, date), search, saved views ("Unassigned", "My follow-ups today"), bulk assign, status chips, source icons, 50 rows per page, 300 ms search debounce.
- [ ] Lead detail as a slide-over: five essentials first (name, contact, status, owner, next follow-up); timeline, notes and messages in tabs.
- [ ] Kanban pipeline with the same visibility rules.
- [ ] Manual entry and CSV / Excel import with column mapping (Laravel-Excel), phone normalised to E.164 (Laravel-Phone).
- [ ] New / Unassigned queue at the top of the dashboard: count badge, oldest first, one-click assign. Admin sends to a department, Manager assigns to an Agent.
- [ ] Masked contact details for Agents, Reveal button logged with who, lead, time, IP; limit per hour from the brand setting (default 30).
- [ ] Activity timeline on the lead (created, viewed, revealed, assigned, status changed, note added, edited, deleted, imported, exported).
- [ ] Notifications: in-app bell plus email to the department's Manager on new and assigned leads.
- [ ] Dashboard cards: new leads today / 7 days, by source, unassigned count, overdue follow-ups, conversion by status; Agents see their own numbers.
- [ ] Export to CSV (Admin, Manager own department), logged.
- [ ] Removing a member returns their open leads to the department queue.
- [ ] Toast with Undo (10 seconds) after assign, status change and delete; keyboard shortcuts for assign and status.
- [ ] Empty states with the "empty-leads" artwork and one button.
- [ ] Sidebar by role (Agent: My leads, Follow-ups; Manager: Dashboard, Leads, Team).
- [ ] Lead events for later automations: LeadCreated, LeadAssigned, StatusChanged.

### Week 3 · Website sources
- [ ] Tables `sites` (site key, encrypted secret, push / pull mode, last lead at) and `site_checks`; `inbound_events` with Retry.
- [ ] `POST /api/v1/ingest/site/{site_key}`: HMAC-SHA256 of timestamp.body, 5-minute replay window, de-duplication on site + external id, raw payload stored, 202 Accepted, rate limit 120 per minute per source.
- [ ] `IngestLead` job: resolve source to brand, normalise fields, de-duplicate by email or phone inside the brand, put in New / Unassigned, timeline entry, notifications.
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
- [ ] People profiles: photo, login email, password or invite, department, role and monthly target in one form.
- [ ] Mark as Won sheet (amount, brand currency, service or package, close date); `sales` and `clients` tables; lifetime value; repeat purchases attach to the same client.
- [ ] Monthly targets at three levels (brand, department, person) as revenue, count or both; Targets month grid with live progress and "copy last month".
- [ ] Progress bars and Over-achieved badges ("132%, £4,800 over target"); leaderboard.
- [ ] Reporting currency and monthly exchange rates screen (`exchange_rates`), cross-brand totals in GBP.
- [ ] Admin sales dashboard with drill-down: sales this month, brand performance, department ranking, agent leaderboard, top clients, lead sources, speed to first contact. Managers see their department, Agents their own.
- [ ] Clients screen.
- [ ] Brand favicon and colours fetched automatically from the website (column `identity_fetched_at` and league/color-extractor are ready; the fetch job and preview are not written); weekly refresh.
- [ ] Old lead import (CSV, Excel or a website's leads table for a date range) and two-level distribution (equal, percent, count, manual) with the `lead_distributions` log.

### Week 6 · Hardening and launch
- [x] Brand-isolation test suite (done in week 1).
- [x] Apple-style theme, light and dark (done; polish continues as screens land).
- [ ] Logs screen: filters by brand, user, event and date; search by lead; 7 / 15 / 30-day downloads built in the queue with a 24-hour link; delete before a date (Super Admin, password re-confirm, never the last 7 days); retention settings; storage card.
- [ ] Nightly log archive (`logs/YYYY/MM/date.jsonl.gz`, 180 days), `log_archives` and `log_exports` tables, pruning of `inbound_events` and daily log files.
- [ ] Dashboard numbers cached 60 seconds per brand and refreshed on new leads.
- [ ] Speed checks: 100,000 leads under 300 ms, indexes and full-text search, `Model::preventLazyLoading()` in development, production caches.
- [ ] Off-server backup destination (S3-compatible or FTP) in `config/backup.php`; today backups stay on the server.
- [ ] PWA manifest for add-to-home-screen (touch icon exists).
- [ ] Onboarding: 3-step Add brand wizard with a test at the end, 3-tip welcome tour for new Agents and Managers, inline "?" help next to settings.
- [ ] Log retention entries for leads, messages and integrations (the areas arrive with their modules).
- [ ] First staging deploy and the first client (UK Publishing House) with its Managers and Agents; Meta App Review if ever needed.

### Phase 2 and later
- [ ] Files module, NDA and contracts, client portal (in that order), then email inbox, automations, reports, Instagram DMs, mobile app, WhatsApp, Viewer role, commissions, invoices, forecasts, automatic exchange rates, monthly PDF report.

## Needed from you before the next phases
- Week 2 can start now: say "continue".
- Week 3: the LeadsHub and LuminoCRM source folders (the document's open question) so the health checks and lead import can be ported file by file.
- Week 4: Meta app id and secret, Business Manager access, and the Cloudflare tunnel hostname.
- First deploy: Hostinger SSH key, host, user and port as GitHub secrets, plus the staging subdomain.
- Backups: where off-server backups should go (Cloudflare R2, Backblaze, FTP).
