# LiquifyCRM — done and pending

Checked against `docs/LiquifyCRM — MVP Architecture (Laravel).docx` on 8 October 2026.
State of the code: 152 Pest tests passing, Pint and Larastan level 6 clean.

## At a glance

| Phase in the document | Status | Notes |
| --- | --- | --- |
| Week 1 · Foundation | **Done** | Rebuilt on 8 October without Filament (see "Agreed differences") |
| Week 2 · Leads core | **Done** | Facebook source arrives in week 4 |
| Week 3 · Website sources | **Done** | Built 8 October; health checks and pull mode ported from the LeadsHub folder |
| Week 4 · Meta | **Done** | Built 8 October against the Graph API with faked responses; a live run needs your Meta app keys in `.env` |
| Week 5 · Sales and targets | **Done** | Built 8 October |
| Week 6 · Hardening and launch | **Built** | Staging deploy, off-server backups and the first client need details from you |
| Phase 2 · Files, NDA, client portal | Pending | Tables designed in the document, not created (agreed) |

## Done

### Project and stack
- [x] Laravel 13 on PHP 8.4, Livewire 4, Tailwind 4, Vite, Pest 5, Pint, Larastan level 6.
- [x] Packages: laravel/fortify, livewire/livewire, sanctum, spatie permission / activitylog / backup / health / schedule-monitor / settings / query-builder, laravel-excel, laravel-phone, league/color-extractor, deployer, blade-ui-kit/blade-heroicons (icons), chart.js (dashboard charts).
- [x] Module layout `app/Modules/<Module>` (Activity, Auth, Brands, Core, Departments, Leads, Meta, Sales, Sites, System), each with its own service provider, migrations, policies, actions, jobs, Livewire screens and views.
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

### Website sources (week 3)
- [x] Tables `sites` (site key, encrypted secret, push / pull mode, encrypted `pull_config`, last lead at), `site_checks` (health history) and `inbound_events` (raw copy of every incoming lead, with status, error and attempts).
- [x] `POST /api/v1/ingest/site/{site_key}`: HMAC-SHA256 of timestamp.body, 5-minute replay window, de-duplication on site + external id, raw payload stored, 202 Accepted, 120 requests a minute per website.
- [x] `IngestLead` job on top of `CreateLead`: site to brand, field names recognised by their many spellings (list carried over from LeadsHub), phone in E.164, UTM tags, unknown fields kept as extra fields, repeat enquiries joined to the open lead, New / Unassigned queue, timeline entry, notifications. A lead with nobody to contact, or with a filled hidden field, is kept as failed.
- [x] Websites screen (`/b/{brand}/sites`): favicon, platform badge (PHP, WordPress, Elementor), green "Connected" badge with the last lead time, amber "Quiet for 48 hours", the five health checks in red / amber / green, Check now. Managers look, Admins edit.
- [x] Connect website wizard in 3 steps: add the website, download the pre-filled `liquify.php` and copy the 2 lines (or copy the webhook address, or enter the pull details), Test connection (website answers, file uploaded, a signed test passes, first real lead).
- [x] Website page: health checks with messages and history, every lead received with Retry (one or all failed), settings, new secret and remove (both with password re-confirm).
- [x] Elementor / WordPress: the form's Webhook action posts to the same address with a token; simple and "Advanced data" formats are both read.
- [x] Pull mode (`sites:pull` every 5 minutes): new rows of the website's own leads table through the LeadsHub agent (`hub-agent.php`) or straight from its database. The first run only notes where the table ends.
- [x] Health checks ported from LeadsHub (`sites:check` every 30 minutes, `POST /admin/sites/{site}/check`): website is up, SSL expiry (amber under 14 days), form handler in place, and email and database through the LeadsHub agent (database also through the pull-mode connection).
- [x] Quiet for 48 hours: the website turns amber and the Admins get one bell notification and email; it resets when the next lead arrives.
- [x] Brand email settings (`/b/{brand}/settings/email`): the brand's own SMTP stored as encrypted `mail_settings`, "Send me a test email", and the optional automatic reply to the customer, noted on the lead's timeline.
- [x] Setup checklist step "Connect your first website" ticks itself.

### Meta (week 4)
- [x] Tables `meta_connections` (encrypted token), `meta_sources` (each Page, its encrypted Page token and its brand), `conversations` and `messages`.
- [x] `GET /webhooks/meta` (verify token) and `POST /webhooks/meta` (`X-Hub-Signature-256`): every event is stored in `inbound_events` and answered with 200 at once; repeats are stored once; 120 calls a minute per Page.
- [x] Connect Facebook (`/integrations/facebook/connect` and `/callback`): Facebook Login (Login for Business when a configuration id is set), 60-day token, every Page listed and subscribed to leads and messages. Or paste a System User token that never expires.
- [x] Integrations screen (`/admin/integrations`, Super Admin and Admin): setup steps with the webhook and redirect addresses, connection state, Page to brand mapping, Refresh Pages, Disconnect (password re-confirm), and the event log for websites and Facebook with Retry.
- [x] Lead Ads: the lead is fetched from Graph API v26, fields recognised like website fields, form, campaign and ad names kept, lead lands in the brand's New / Unassigned queue with notifications.
- [x] A Page without a brand keeps its events; mapping the Page processes them.
- [x] Messenger: messages attach to a conversation by PSID; the first message creates a lead (so does a new message on a closed lead); later ones notify the lead's owner; delivered and seen receipts update replies.
- [x] Inbox (`/b/{brand}/inbox`) for visible leads only, with unread counts, search, the 24-hour reply window countdown and a reply box; the same thread on the lead page (Messenger tab). `POST /b/{brand}/conversations/{conversation}/messages` for the API.
- [x] Replies are queued, shown as Sending, Sent, Delivered, Seen or Not sent; "reply sent" and "reply failed" are logged on the lead; a reply outside the window is refused.
- [x] Token refresh in the scheduler (`meta:refresh-tokens`, daily); the Super Admins are told when Facebook must be connected again.
- [x] Setup checklist step "Connect Facebook" ticks itself; Inbox in every role's menu, Integrations in the admin menu.

### Sales and targets (week 5)
- [x] Tables `clients`, `sales`, `targets`, `exchange_rates`, `lead_distributions`.
- [x] Mark as Won sheet on the lead (amount in the brand's currency, service or package, close date) and `POST /b/{brand}/leads/{lead}/won`. A lead can only become Won with an amount: choosing Won under Change status opens the sheet, and the pipeline and bulk status refuse it. "Add another sale" on a won lead.
- [x] Clients: created on the first sale, a later purchase by the same email or phone joins the same client, lifetime value and sales count recounted from the sales; a new enquiry from a buyer is linked to its client. Clients screen by lifetime value and a client page with every sale, who closed it, and every lead (Admins all, a Manager the clients their departments sold to).
- [x] Monthly targets at three levels (brand, department, person) as revenue, number of sales, or both. Targets screen: month grid with live progress, Copy last month, planning up to a year ahead. The Admin sets every row; a Manager sets their own Agents.
- [x] Progress bars and the Over-achieved badge ("132%, £4,800 over target"); the leaderboard sorts by % of target.
- [x] Sales dashboard: `/admin/sales` for all brands in the reporting currency with drill-down brand, department, Agents; `/b/{brand}/sales` for a Manager (own departments) and an Agent ("My target": own bar and sales). Cards: revenue and sales vs last month, target, brand performance (leads, conversion, revenue, % of target), department ranking, leaderboard, top clients, lead sources with conversion, speed to first contact, sales of the month. Month picker.
- [x] Exchange rates screen (`/admin/exchange-rates`): monthly rate per currency to the reporting currency; a month without a rate uses the latest earlier one; saving re-converts the sales it affects; the dashboard warns when a sale has no rate instead of guessing.
- [x] People profiles: Add person now also takes the monthly revenue and sales target (photo, login, password or invite, department and role were there).
- [x] Brand favicon and colours fetched from the website (theme-color, web manifest, else the icon's main colours through league/color-extractor): by itself when a brand is added, "Fetch from website" on the brand form with the colours left adjustable, `POST /admin/brands/{id}/fetch-identity`, and a weekly favicon refresh.
- [x] Import old leads from a website's own leads table for a date range (website page, needs the agent or database details): original dates kept, duplicates joined, marked Imported, no per-lead notifications, one summary to the person who started it.
- [x] Distribute leads (`/b/{brand}/leads/distribute`): the Admin filters leads and splits them across departments equally, by percentage or by count; a Manager shares their department's leads among Agents equally or by count; preview before moving; one notification per department or Agent; every run (and bulk assigns from the Leads list, as "by hand") in the `lead_distributions` history.
- [x] Setup checklist step "Set monthly targets" ticks itself; menus: Sales / My target, Targets, Clients, Distribute leads, Sales dashboard, Exchange rates.

### Hardening (week 6)
- [x] Logs screen (`/admin/logs` for every brand, `/b/{brand}/logs` for one): filters by brand, person, kind of event and dates; search by lead name or email; click a row for the details. Admins see everything; a Manager sees and downloads their own departments' logs; Agents have no Logs screen.
- [x] Download last 7 / 15 / 30 days: CSV inside a ZIP built in the queue, link in notifications and on the screen, valid 24 hours, the download itself is logged, expired files are removed.
- [x] Nightly `logs:archive`: every finished day goes to `logs/YYYY/MM/DATE.jsonl.gz` in private storage with row count and checksum (`log_archives`); rows past the database retention are deleted in batches of 1,000, only when their day is safely archived; archive files past the archive retention are deleted. Archive files can be downloaded by Admins, filtered by date.
- [x] Retention settings (Super Admin): 7 / 15 / 30 / 60 / 90 days in the database, 90 / 180 / 365 days as archives. Storage card with rows and archive size.
- [x] Delete logs before a date (Super Admin, password re-confirm, never the last 7 days, archived first, in the queue, logged).
- [x] `inbound_events` pruned daily: processed after 14 days, failed after 30. Laravel error log as daily files for 14 days.
- [x] Dashboard numbers cached 60 seconds per brand and cleared when a lead arrives, is assigned, changes status or is distributed.
- [x] Speed check `php artisan liquify:speed-check`: 100,000 leads in one brand, lead list 19 ms, Unassigned view 20 ms, search 10 ms, dashboard numbers 244 ms uncached, queue 2 ms on the local XAMPP database. Four indexes were added to `leads` for this.
- [x] Backups: the daily backup now carries the database and the log archive folder; the destination disks come from `BACKUP_DISKS`.
- [x] PWA manifest for add-to-home-screen on iPhone and Android.
- [x] Onboarding: 3-step Add brand wizard (basics, look fetched from the website, check at the end), 3-tip welcome tour for new Managers and Agents (skippable, shown once), inline "?" help next to settings.
- [x] `POST /b/{brand}/leads/{lead}/reveal`.
- [x] `deploy.php` no longer calls the two Filament commands (they would have failed the first deploy).

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
- `inbound_events` carries four columns beyond the document's list (`source_id`, `brand_id`, `lead_id`, `processed_at`) so an event links to its website, brand and lead; `sites` adds `url`, `platform`, `form_path`, `favicon_path`, `pull_cursor`, health and timing columns.
- Elementor cannot sign a request, so its address carries a token derived from the site secret (`?token=`) instead of the HMAC headers.
- The email and database checks look from inside the website, so they need the LeadsHub agent file on it (or, for the database, the pull-mode connection); without either they show "Not set up" rather than a guess.
- Pull mode starts from the newest row and never imports history; old leads come in through the import tool in week 5.
- The received-leads log with Retry lives on each website's page; the global Integrations screen arrives with Facebook in week 4.
- Meta tables carry a few columns beyond the document's list: `meta_connections` (`is_system_user`, `last_refreshed_at`, `error`), `meta_sources` (`meta_connection_id`, `subscribed_at`, `last_event_at`, `error`), `conversations` (`contact_name`, `last_message_at`, `last_message_preview`, `unread_count`), `messages` (`error`, plus a `sending` status before `sent`).
- Delivered and seen receipts update messages directly and are not kept in the event log (they carry no lead).
- Attachments in Messenger show as "[1 attachment]"; the Files module (Phase 2) will store them.
- `targets` has a `brand_id` (a person can work in two brands, each with its own target and currency); `clients` adds `last_sale_at`; `sales` adds `recorded_by`.
- The document's `/dashboard/sales` lives at `/admin/sales` (all brands) and `/b/{brand}/sales` (one brand), matching the two areas of the app. Distribution is a screen at `/b/{brand}/leads/distribute` rather than a bare POST.
- Bulk distribution writes one history line and one notification per department or Agent, not a timeline entry on each of the leads.
- Log clean-up keeps the entries on a lead's own timeline in the database (they are still archived). The document deletes everything after 30 days, which would empty the timeline of every older lead.
- "Delete logs" checks that the period is archived; it cannot check an off-server backup until one is set up.
- The Logs routes are `/admin/logs` and `/b/{brand}/logs`; export and delete are actions on the screen rather than bare `POST /logs/export` and `DELETE /admin/logs`.
- The speed check times the database queries behind the screens, not a full page in a browser.
- Light page background is `#f4f4f2` (a shade below the document's `#FAFAF9`) so white cards stand out, and stat-card icons sit in rounded tiles rather than circles, both from the reference designs the user chose.

## Pending

### Week 3 · left over
- [ ] Gmail and Outlook logos on the email settings screen (a text badge shows the detected provider today; the official SVGs go in `resources/svg/brands/` together with the Facebook and Messenger logos in week 4).
- [ ] Website favicon as the source logo in the lead list (the lead shows the website name under extra fields today).

### Week 4 · left over
- [ ] A live run against your real Meta app (everything so far is tested with faked Graph API answers).
- [ ] Official Facebook, Messenger, Gmail and Outlook logos as local SVGs in `resources/svg/brands/`; they must come from each company's brand page, so they are not drawn by hand. Heroicons stand in today.
- [ ] A brand rule such as "all Facebook leads go to Sales Team A" (the document mentions it as optional; leads go to the New queue for the Admin today).

### Week 5 · left over
- [x] Correcting or removing a recorded sale: Super Admins and Admins only (decided 8 October), from the client page. Lifetime value, lead value and targets are counted again, both are written to the lead's timeline, removal asks for the password, and a lead whose only sale is removed leaves Won for the last open status.
- [ ] A progress bar on each person's own Profile screen (it is on Sales / My target, the leaderboard and Targets).
- [ ] The two CSV drill-downs the document hints at ("Per brand and department" for sources, "Per Agent" for speed) show inside the brand and department views rather than as separate expanding cards.

### Week 6 · needs you
- [x] Off-server backups: decided against on 8 October. Backups stay on the server (`BACKUP_DISKS=backups`); the switch remains in `.env` if this changes.
- [ ] First staging deploy: postponed (8 October). Needs the Hostinger SSH key, host, user and port as GitHub secrets, plus the staging subdomain. Then run `liquify:speed-check` there.
- [ ] A live run against the real Meta app: **in progress, paused 8 October, resume here.**
  - Done: the first Meta app ("LiquifyCRM", id 1409421898009379) cannot hold the Page, lead and Messenger use cases, so a second app **"LiquifyCRM Leads" (id 1736979774197502)** was created with all three. Its six permissions are "Ready for testing" (`pages_show_list`, `pages_read_engagement`, `pages_manage_metadata`, `business_management`, `leads_retrieval`, `pages_messaging`). Its Page webhook points at `https://audience-percolate-unclog.ngrok-free.dev/webhooks/meta` and is verified, and the five fields are subscribed (`leadgen`, `messages`, `messaging_postbacks`, `message_deliveries`, `message_reads`).
  - Done locally: `TRUSTED_PROXIES=*` so links are https behind the tunnel; the tunnel must forward to port 8000 (`ngrok http 8000`), not to XAMPP's port 80.
  - **Next 1:** the local `.env` still holds the id and secret of the *first* app. Put the new app's `META_APP_ID` (1736979774197502) and its App secret (Meta dashboard > App settings > Basic > Show) into `.env`, then `php artisan config:clear`. Until then signed webhooks from the new app are refused and Connect Facebook uses the wrong app.
  - **Next 2:** start `php artisan serve`, `php artisan queue:work` and `ngrok http 8000`.
  - **Next 3:** Admin area > Integrations > Connect Facebook, then choose a brand for each Page. If Facebook refuses the redirect, add `http://localhost:8000/integrations/facebook/callback` (or the ngrok one) under Facebook Login for Business > Settings > Valid OAuth Redirect URIs.
  - **Next 4:** use the "Test" buttons next to `leadgen` and `messages` on the Meta Webhooks page and check Admin area > Integrations > Received. While the app is unpublished Meta only delivers these dashboard tests, no real leads or messages, so a real end-to-end test needs the app published.
- [ ] The first client (UK Publishing House) with its Managers and Agents; Meta App Review only if ever needed.
- [ ] Official brand logos (Facebook, Messenger, Gmail, Outlook) as local SVGs.
- [ ] A larger install icon (192 and 512 px PNG) for Android's install prompt; the manifest uses the 180 px touch icon today.

### Phase 2 and later
- [ ] Files module, NDA and contracts, client portal (in that order), then email inbox, automations, reports, Instagram DMs, mobile app, WhatsApp, Viewer role, commissions, invoices, forecasts, automatic exchange rates, monthly PDF report.

## Needed from you before the next phases
- The six weeks are built. What is left is in "Week 6 · needs you" above.
- Week 4 live test: put the Meta app id, secret and a verify token in `.env`, run the Cloudflare tunnel, then Connect Facebook on the Integrations screen.
- First deploy: Hostinger SSH key, host, user and port as GitHub secrets, plus the staging subdomain.
