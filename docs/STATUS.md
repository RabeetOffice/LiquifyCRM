# LiquifyCRM — done and pending

Checked against `docs/LiquifyCRM — MVP Architecture (Laravel).docx` on 8 October 2026.
State of the code (14 October): 267 Pest tests passing, Pint and Larastan level 6 clean.

## At a glance

| Phase in the document | Status | Notes |
| --- | --- | --- |
| Week 1 · Foundation | **Done** | Rebuilt on 8 October without Filament (see "Agreed differences") |
| Week 2 · Leads core | **Done** | Facebook source arrives in week 4 |
| Week 3 · Website sources | **Done** | Built 8 October; health checks and pull mode ported from the LeadsHub folder |
| Week 4 · Meta | **Done** | Built 8 October against the Graph API with faked responses; a live run needs your Meta app keys in `.env` |
| Week 5 · Sales and targets | **Done** | Built 8 October |
| Week 6 · Hardening and launch | **Built** | Staging deploy, off-server backups and the first client need details from you |
| Phase 2 · Files, NDA, client portal | Started | The `files` table exists and holds each client's storage (12 October); files on leads and brands, NDA and the portal are pending |

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
- [x] ~~Exchange rates screen~~: built, then removed on 9 October (see "Agreed differences").
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
- [x] Onboarding: Add brand wizard (basics, look fetched from the website, check at the end; four steps since 13 October, see "Add a brand from its website"), 3-tip welcome tour for new Managers and Agents (skippable, shown once), inline "?" help next to settings.
- [x] `POST /b/{brand}/leads/{lead}/reveal`.
- [x] `deploy.php` no longer calls the two Filament commands (they would have failed the first deploy).

### System screen and deployment
- [x] System screen (Super Admin): version, environment, pending-migrations banner, Backup and run database update with password re-confirm (backup first, then `migrate --force`, output kept), Back up now, retry failed jobs, release rollback (password re-confirm), health checks, queue and cron health, deployment history with logs, latest backups. Refreshes itself while a job runs.
- [x] `POST /hooks/deploy/{token}` records each deployment and tells Super Admins when database updates wait.
- [x] GitHub Actions: `ci.yml` (Pint, Larastan, Pest) and `deploy.yml` (`main` → production, `develop` → staging) with Deployer release folders and symlink switch, then the deploy hook.
- [x] Scheduler for the single Hostinger cron: queue drained by the scheduler, heartbeat, health checks, daily database backup and clean-up, pruning.

### Refinement pass (9 October, asked for by the user)
- [x] Look: one corner scale (16 / 12 / 10 px), more room in cards and forms, calmer glass surfaces in dark mode (translucent cards, frosted sidebar, top bar, menus and dialogs), a redrawn orange hero card, quiet scrollbars.
- [x] Motion: pages arrive block by block, numbers count up, bars grow, menus and dialogs pop in, rows that arrive live are lit for a moment. Reduced-motion is respected.
- [x] Fixed: dark mode was lost when moving to another page; the search box opened trapped inside the top bar (it is a centred palette now, and every dialog renders at the end of the page).
- [x] Navigation: one switcher at the top of the sidebar for "All brands" (admin area) and each brand; one account menu at the bottom. The duplicate avatar menu, "Admin area" and "Open a brand" entries are gone.
- [x] People (`/admin/people`): Admins and Super Admins add Admins, Managers and Agents from one form (role, brand, department, designation, phone, monthly target) and see everyone in one list with role tabs and a brand filter.
- [x] Profile: header with photo (click to change, saves at once), name, designation, role, brands and departments, four numbers for the month, the target bars, and the details form. The second Save button is gone.
- [x] Sales dashboard: the admin area shows one brand at a time in its own currency (brand cards on top). Every scope (brand, department, one person) gets a six-month revenue chart and a lead-source doughnut, plus an Average sale card.
- [x] Integrations in three tabs: Facebook (three numbered steps with Copy buttons until it is connected; the Pages only afterwards), Websites (every brand's websites, Connect website with a brand picker), Received (the event log, refreshing by itself).
- [x] Add brand: paste the website link and the name and address fill themselves in; a live preview; the look step shows what was fetched and proper colour pickers.
- [x] Round robin: each department is shared "By hand" or "Round robin" (Distribute screen; a Manager for their own departments, an Admin for all). A new lead in a round-robin department goes to the next active Agent in turn; "Share the waiting leads" does the same for the queue.
- [x] Live updates without reloading: the bell checks every 15 seconds and shows a toast for what arrived; the dashboards, the leads list, the inbox and Received refresh by themselves (polling; the hosting has no websockets).

### Updates and email providers (9 October, asked for by the user)
- [x] Updates screen (`/admin/updates`, Admins and Super Admins): "What's new" for every version from `resources/changelog.php`, whether a version waits to be applied, the "Update system" button (database backup, pending database changes, caches, version recorded; runs at once, needs no cron) and the update history with details. If the backup cannot be made nothing is changed and "Update without a backup" is offered.
- [x] A bar on every screen tells Admins when a version is ready; the Overview shows the version; Managers and Agents see none of it.
- [x] Email settings: a picker of ready-made providers (Google, Microsoft 365 / Exchange, Hostinger, Titan, webmail, Zoho, Yahoo, GoDaddy, Namecheap, iCloud, SendGrid, Brevo, Mailgun, or another) fills in server, port and security and shows what each one asks for. Icons come from Google's favicon service (user's choice).

### Working screens redesign (12 October, asked for by the user)
- [x] Leads list: views as tabs on the list card, search and Filters beside them, filters in use as removable chips, a floating bar for bulk actions, coloured initials for leads, owner and department in one cell, follow-up in words ("Today, 14:00", "Overdue · 9 Oct"). Fits its card from 1280 px up (the Received column hides below 1340 px).
- [x] Lead (slide-over and page): name and source, the pipeline as a clickable path (`moveTo`), action tiles with the next step in orange, contact with copy buttons and Reveal, details, sales, then Timeline / Message / Notes / Follow-ups / Messenger. The page lays the same panel out in two columns. Escape closes a dialog before the slide-over.
- [x] Pipeline: full-width board, columns as tall as the window with their own scroll, cards with service, source, follow-up, owner and age, a "quiet" mark after 7 days without activity, counts above the board, an owner filter, the Won total in its column, and the lead slide-over on click. Refreshes every 30 seconds unless a card is being dragged.
- [x] Inbox: one card in three panes (chats, thread, lead), All / Unread / Can reply, messages grouped by day with joined bubbles, a rounded reply box. Two panes below 1440 px, one on a phone with a back button.
- [x] Follow-ups as a checklist. People, Members and Departments: Edit keeps its word, Suspend and Remove are icon buttons with a tooltip.
- [x] Glitches fixed on the way: every row of a live list flashed about a second after each page load; charts threw an error when the mode changed; right-aligned column headings sat on the left; the People and Members tables were wider than their card.
- The Agent's masked view of a lead was checked by tests, not by eye.

### Record screens redesign (12 October, asked for by the user)
- [x] Clients: four facts on top (clients, lifetime value, average per client, bought again), the list in four orders as views (`ClientsIndex::ORDERS`), a bar under each lifetime value against the best client, the whole row opens the client.
- [x] Client page: hero, then sales and enquiries on the left and a rail with lifetime value, facts and contact (copy buttons) on the right.
- [x] Targets: four facts for the month, one block per department with its people, fields with the currency in front and "sales" behind, a floating reminder while targets are typed but not saved (`wire:dirty`).
- [x] Websites: totals on top; each website card says its state in a word and shows the five checks as marks. Website page: hero, four facts (health, connection, last lead, last check), underline tabs, the five checks as tiles, received leads with the result as views.
- [x] Logs: a feed grouped by day with an icon per kind, the kind as views, brand / person / dates behind Filters and shown as chips, one Download menu, storage as four facts, Delete logs marked as a danger card.

### Audit of every screen (12 October, asked for by the user)
Every screen was opened in a headless browser, light and dark, and judged against the new design. Redesigned or repaired in this pass:
- [x] Brands: cards with four numbers each, totals on top, All / Active / Inactive as views (the table was wider than its card and broke brand names over three lines).
- [x] System: four facts, health checks as tiles, scheduler card with the cron line and Copy, environment in two columns, backups and history tidied.
- [x] Department form: people first (wide), details in the rail; one card when creating. Bug fixed: the role select compared an enum with a string, so every person showed as Manager.
- [x] Add a lead: form on the left, "Who gets it" and "What happens next" in the rail.
- [x] Error pages 403, 404, 419, 429, 500 and 503 in the sign-in look (`resources/views/errors`); the invitation "can't be used" card; the sign-in card centred.
- [x] File fields styled; menus solid (a menu inside the blurred top bar cannot blur the page, so it must not be see-through); leads in the dashboard queue carry their avatar.
Looked at and left as they are, because they already follow the design: Overview, Sales dashboards, People and the person form, Integrations, Updates, the brand wizard and brand form, Members and the member form, Distribute, the website wizard, Email settings, Profile, Password and 2FA, sign-in, forgot and reset password, global search, the notifications menu, the import dialog.
Not looked at by eye: the two-factor challenge and confirm-password pages (they need a second factor), the invitation accept form (needs a live invitation), the welcome tour (shown once to a new Agent).

### After the first sale: routing, hand-over, upsells, client storage (12 October, asked for by the user)
The user's flow: a lead from the website or Facebook is worked by front sales until the first sale; the client then goes to the Upsell department (project managers and upsellers), who sell more to the same client until the client is closed. Front sales must not see what is sold afterwards.
- [x] Where new leads go: on Distribute leads an Admin chooses, for each website and each Facebook Page (lead forms and Messenger together), the department or the one person its leads go to on arrival (`lead_routes`, `LeadRouting`, applied in `CreateLead`). No choice: the queue, assigned by hand as before. A person who left falls back to the department; a quiet import of old leads is not routed.
- [x] A department is Front sales or Upsell (`departments.kind`, set on the department form).
- [x] Hand-over (`HandOverClient`): in the Mark as Won dialog, or later from the won lead, the seller hands the client to an Upsell team or to one person in it and can attach the contract. A round-robin Upsell department gives each new client to the next person in turn. The lead's timeline says where its client went.
- [x] Upsells (`RecordUpsell`): another sale on the client, any number of times, with no lead. It is under the name of whoever sold it (a Manager or an Admin may record it for someone on the team) and counts towards that person's, the department's and the brand's targets.
- [x] Client page: the story in order (front sale, hand-over, each upsell with the total so far, closed), lifetime value split into front sale and upsells, who looks after the client and who else works on it (`client_user`), files and saved text in tabs.
- [x] Client storage: files up to 20 MB on the private disk, served only through a route that checks who asks (`files` table, `ClientFileController`); saved text stored encrypted, a hidden one covered until someone clicks Show, each look written to the log (`client_notes`).
- [x] Clients list: views All, Mine, Waiting for a person, Not handed over, Closed; who looks after each client; how much came from upsells; tick several and give them to a person or a team.
- [x] Close and reopen a client (`CloseClient`).
- [x] Who sees what (`ClientPolicy`): Admins everything; the Upsell side (the person who looks after the client, the people added to it, the Managers of its department) the whole client; front sales only its own lead and sale, the contract it added, and (a front Manager) a client until it is handed over. The Sales dashboard's "Top clients" counts only what the viewer's own team sold.
- [x] People who work only on an Upsell team land on My clients, have no lead screens in their menu and get welcome tips about clients.
- [x] Privacy policy and "Delete your data" updated for a client's files and shared text.
- [x] Everything new asks `ClientCare::ready()` first, so the code works on the server before "Update system" has created the tables (checked once against a database without the three migrations).

### Add a brand from its website (13 October, asked for by the user)
- [x] Add brand is four steps (`BrandWizard`): the basics, the look, the teams, the check. The first screen asks for the website alone ("Read website"); "No website yet" opens the empty form.
- [x] `BrandWebsite` reads the website once (kept five minutes): the name, and the company's phone, email, postal address, registered name, company number and VAT number from its schema.org data, then from `tel:` / `mailto:` links, an `<address>` tag and the footer's wording, then from its contact page for whatever is still missing. The country (address, then the site's own ending such as `.co.uk`, then the phone's dialling code, then the page's language) gives the currency and the time zone (`Countries`; "Perth WA" is not on Sydney time). A field somebody typed is never replaced.
- [x] Company details on a brand (`Brand::company()`, `legalName()`): phone, email, address, registered company name, company number, VAT or tax number. On Add brand and on Edit brand ("Fill from website" fills the empty ones). The brand's invoices and contracts will read them.
- [x] The main logo is fetched with the favicon (`BrandIdentity`): the logo the site names for search engines, else the picture marked as the logo in its header; other companies' logos on the page are skipped. WebP and AVIF are kept as PNG so a PDF can show them. A white logo on a see-through background is left out, an SVG carrying a script is refused, and a logo or favicon somebody uploaded is never replaced. The weekly refresh still only touches the favicon.
- [x] Colours: the website's colour is the brand's second colour and the main colour is white, the tile behind its mark (`Brand::tileColor()`, `--brand-tile`); `Brand::primaryColor()` still gives the brand's own colour to chips and charts, so brands made before this look as they did. The preview in Add brand is the same tile every screen shows.
- [x] Time zone is a search picker (`<x-ui.picker>`, `Timezones`): city, country and today's distance from GMT; the usual zones first.
- [x] The teams (`StarterTeams`, `CreateStarterTeams`): Front Sales (works leads), Upsell and Project Management (both work clients), ticked and created in one click in step 3, and on the Departments screen while a brand has none. A name already in use is left alone; a removed one comes back.
- [x] The check also reports the logo, the company details and the departments, and leads on to "Add its people".

### A lead at a glance, assigning with one click, one person as one lead (13 October, asked for by the user)
- [x] What a lead asked for is in view when it opens (`LeadEnquiry`): the message and every answer of the Facebook or website form, written as sentences; where it came from (Page, form, campaign, ad, website, page, campaign tags) is a block of its own with "Open their chat on Facebook". The Message tab is gone; the tabs are Notes (with the note box), Follow-ups, Messenger, Timeline.
- [x] Assign from where you are (`assign-menu`, `quickAssign`, `quickSend`): the Owner cell of the Leads list and the Assign tile and Owner line of a lead open the people; one click assigns, the name shows before the answer, and the toast has Undo. Admins can send the lead to a team from the same menu. A lead in a department is offered that department's people; people who only work clients are not offered.
- [x] One person, one lead: a Facebook lead form and a Messenger chat of the same person join the same lead, whichever comes first, by the chat id in the form's `inbox_url` (`LeadMatcher`, `MergeLeads::join`, `ProcessMetaEvent`).
- [x] Existing pairs: "This person has another lead" (same Messenger chat, email or phone) or "This may be the same person" (same name, and one of the two has nothing but a name) on the lead, with Merge for whoever may assign both (`MergeLeads::handle`): the chat, notes, follow-ups, website events and timeline move, missing details are filled, the owner and the further status carry over, the other lead goes to the bin. Won and lost leads are not merged.
- [x] A click on a lead in the list or pipeline always opens the slide-over (the hover icon to the full page is removed; Ctrl + click still opens a new tab), and "Open full page" follows the lead that is open.

### One person in several brands (14 October, asked for by the user)
- [x] Admins share more brands with a Manager or Agent on People › Edit, with a role and a department per brand (Manager in one, Agent in another). Role and department change per brand, a brand can be removed, the others stay. Version 1.16.0, no migration: `brand_user` already held one row and one spatie team per brand.
- [x] Only Admins (`UserPolicy::shareBrands`). Managers keep adding new people to their own brand; adding or inviting somebody from another brand, or somebody already in theirs, is refused (`BrandSharing`). People list: every role, and "3 brands" with the marks.

### Instagram direct messages (14 October, asked for by the user, brought forward from the document's future modules)
- [x] The document's "Instagram DMs: Instagram messages into the same Inbox, same Meta app and webhook, new adapter", built through Meta's Instagram API with Facebook Login (Page token, no new package). Version 1.15.0, one migration (`meta_sources.instagram_id`, `instagram_username`, `conversations.contact_handle`), behind `Instagram::ready()` until "Update system".
- [x] Refresh Pages / Connect again reads the Instagram account linked to each Page; its messages go to the Page's brand and follow the Page's rule on Distribute. The webhook takes `object: instagram` (and the dashboard test's `changes` shape); `ProcessMetaEvent::INSTAGRAM` makes the chat (`channel = instagram`) and a lead with source Instagram, the ad's name when the person came from an ad, the reply written in the Instagram app (echo), a deleted message, a "seen" receipt.
- [x] Inbox: Every channel / Messenger / Instagram, the channel's mark on each chat, search by @username, the person's @username and profile link; replies up to 1,000 bytes, counted while typing (1.15.1). A returning person who taps an ad again gets the ad on the lead and its timeline (`messaging_referral`, 1.15.1).
- [ ] Not built, from the plan's optional list: bringing in Instagram chats from before the connection (Conversations API), the 7-day "Human Agent" reply tag (needs Meta's approval), joining an Instagram lead form with the same person's Instagram chat (Meta gives no shared id we know of). Lead panel tab named after the chat's channel. Privacy and data deletion pages updated (14 October).
- [ ] Needs the user: add `instagram_basic` and `instagram_manage_messages` to the Meta app (and to the Login for Business configuration if `META_LOGIN_CONFIG_ID` is set), subscribe the Instagram webhook object (`messages`, `messaging_postbacks`, `messaging_seen`, `messaging_referral`), link each Instagram account to its Page, turn on "Allow access to messages" in the Instagram app, click Update system, then Connect again. Messages from people without a role on the Meta app need App Review (Advanced Access) if they do not arrive under Standard Access.
- [ ] Meta App Review (names and pictures of chat contacts, Instagram messages from customers): the user does it after LiquifyCRM moves to its own domain. Every step, the texts to paste and the videos to record are in `docs/META-APP-REVIEW.md`.

### Agreed differences from the document
- **Company details live in `brands.settings` (13 October).** The document's `brands` table has no phone, email or address. They are kept under `settings.company` rather than as new columns, so the screens work the moment the code is on the server, with no "Update system" in between. Brands is the only module that writes them.
- **Live updates and nothing waiting for the minute (12 October, user request).** The document has the queue drained once a minute and screens polling. Now a stamp per brand and person (`Live`) is asked for every 4 seconds by one small request and screens reload only when it moved; bell entries are written at once; incoming website and Meta events and Messenger replies run right after the response (`RunSoon`). The scheduler cron was missing on the live server until 12 October (18 jobs were waiting); it was added that day and still does the emails, website pulls, backups, token renewal and log archive.
- **Privacy policy and "Delete your data" pages (12 October, user request).** Not in the document. `/privacy` and `/data-deletion` are public, written from what the code really stores, and are the two addresses in the Meta app's settings. Open point: the pages promise deletion on request, but the app can only soft-delete a lead and has no delete for a client or a Messenger chat, so a request is carried out by hand in the database until an "erase a person" tool exists.
- **Password reset with the authenticator app (12 October, user request).** The document only has the emailed reset link. "Forgot password" now also offers `/forgot-password/authenticator`: email, the 6-digit code and a new password (`ResetPasswordWithAuthenticator`). The code alone is the proof there, so it is guarded: five wrong tries per email and twenty per address in an hour, one answer whatever was wrong, a code works once, every try is in the logs, the person gets a bell and email notice, and nobody is signed in from that screen (sign-in still asks for the new password and a fresh code). Recovery codes are not accepted there.
- **Logs are for Admins and Super Admins only (12 October, user decision).** The document lets a Manager see and download the logs of their own departments. The user asked that nobody but Admins sees logs: the Logs screen, its downloads and the dashboard's "Recent activity" are closed to Managers and Agents, and the Manager role carries no logs permission. A lead's own timeline is part of the lead and stays visible to whoever may see that lead.
- **"Update system" beside "Backup and run database update" (9 October).** The document keeps database updates on the System screen, behind the queue. The Updates screen does the same work inside the request so it works without the scheduler, asks for the password only when the database will change, and lets an Admin go on without a backup when the server cannot make one.
- **Admin equals Super Admin, except protection (9 October, user decision).** The document reserves admin accounts, the System screen, log retention and log deletion for the Super Admin. Now every Admin has them too. What stays: nobody else can edit, suspend or remove a Super Admin, only a Super Admin can make another one, and the last one cannot be removed.
- **Exchange rates removed (9 October, user decision).** The document converts all brands into one reporting currency with monthly rates. The screen, its menu entry and the cross-brand totals are gone; the admin sales dashboard shows one brand at a time in that brand's currency. The `exchange_rates` table and the `amount_reporting` column stay unused.
- **Round robin** is an addition to the document. Its "all Facebook leads go to Sales Team A" rule is now "Where new leads go": a choice per website and per Facebook Page, down to one person.
- **After the first sale (12 October, user request).** The document's client only collects repeat sales. Added: Front sales and Upsell departments (`departments.kind`), the hand-over (`clients.department_id`, `owner_id`, `handed_over_at`, `handed_over_by`, `closed_at`, `closed_by`, `closed_note`), upsells (`sales.kind`), more people on a client (`client_user`), saved text (`client_notes`) and the rule per source (`lead_routes`). A Manager used to see every client their department sold to; now only until the client is handed to Upsell.
- **The `files` table is the document's Phase 2 design, created early** for the client storage, with the same columns (polymorphic `fileable`: a client, or the lead a file was added from). Two differences: it is a small model of our own instead of `spatie/laravel-medialibrary` (the package the document lists for the Files module; not installed), and there is no `files.manage` permission, because access follows the client (`ClientPolicy`). `expires_at` and `visibility` are there but not used yet.
- **Designation** on people is an addition to the document.
- Live updates are polling, not websockets (no daemons on the hosting).
- **Filament removed (8 October, user decision).** The document chose Filament for the dashboard; the user did not like the result and asked for a custom panel. Auth is now Laravel Fortify (which the document also lists), screens are Livewire 4 pages on LiquifyCRM's own layout and design system. Two packages were added with the user's approval: blade-ui-kit/blade-heroicons for the Heroicons the document specifies, and chart.js for dashboard charts.
- `spatie/laravel-webhook-client` and the Facebook PHP SDK dropped; webhooks and Graph API calls will use plain controllers and the Laravel HTTP client.
- Departments built in week 1 instead of week 5.
- Per-brand settings stored in `brands.settings` (json); `spatie/laravel-settings` reserved for global settings (exchange rates, retention).
- Phase 2 tables not created yet, except `files` (see above).
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
- [x] Website favicon as the source logo (9 October): a website lead shows the website's favicon and name as its source in the lead list, the pipeline, the lead page and the dashboard queue; without a favicon the globe icon stays next to the name.

### Week 4 · left over
- [ ] A live run against your real Meta app (everything so far is tested with faked Graph API answers).
- [ ] Official Facebook, Messenger, Gmail and Outlook logos as local SVGs in `resources/svg/brands/`; they must come from each company's brand page, so they are not drawn by hand. Heroicons stand in today.
- [ ] A brand rule such as "all Facebook leads go to Sales Team A" (the document mentions it as optional; leads go to the New queue for the Admin today).

### Week 5 · left over
- [x] Correcting or removing a recorded sale: Super Admins and Admins only (decided 8 October), from the client page. Lifetime value, lead value and targets are counted again, both are written to the lead's timeline, removal asks for the password, and a lead whose only sale is removed leaves Won for the last open status.
- [x] A progress bar on each person's own Profile screen (9 October): "Your target this month", one bar per brand the person works in, from their own sales. Super Admins and Admins carry no personal target and see no card.
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
- [ ] A larger install icon (192 and 512 px PNG) for Android's install prompt; the manifest uses the 180 px touch icon today. Needs the original artwork of the app icon at 512 px or larger: enlarging the 180 px file would be blurry.

### Phase 2 and later
- [ ] Files module (the `files` table and the client storage exist; still open: files on any lead and on a brand, expiring links, S3-compatible storage), NDA and contracts, client portal (in that order), then email inbox, automations, reports, mobile app, WhatsApp, Viewer role, commissions, invoices, forecasts, automatic exchange rates, monthly PDF report.

## Needed from you before the next phases
- The six weeks are built. What is left is in "Week 6 · needs you" above.
- Week 4 live test: put the Meta app id, secret and a verify token in `.env`, run the Cloudflare tunnel, then Connect Facebook on the Integrations screen.
- First deploy: Hostinger SSH key, host, user and port as GitHub secrets, plus the staging subdomain.
