# LiquifyCRM

A standalone multi-brand leads and sales CRM. Every brand's leads (website forms,
Facebook Lead Ads, Messenger) land in one database, and each person sees only the
brands and leads shared with them. The architecture document in
[`docs/`](docs/) is the single source of truth.

**Stack:** Laravel 13 · PHP 8.4 · MySQL/MariaDB · Livewire 4 + Laravel Fortify (Tailwind 4, Vite,
Chart.js, Heroicons) with LiquifyCRM's own layout and design system · Pest 5 · spatie permission,
activitylog, backup, settings, health, schedule-monitor · Laravel-Excel · Deployer 8 + GitHub
Actions → Hostinger.

## Build status (phase plan from the document)

| Week | Phase | Status |
|------|-------|--------|
| 1 | Foundation: project, auth, brands, memberships, roles, brand switcher, departments, System screen, CI/deploy | ✅ done |
| 2 | Leads core: leads, statuses, notes, tasks, timeline, list/detail/kanban, import, New queue, assignment, Reveal | ✅ done |
| 3 | Website sources: sites, ingest API + liquify.php, pull mode, health checks | ✅ done |
| 4 | Meta: Connect Facebook, Page mapping, Lead Ads, Messenger inbox | ⏳ |
| 5 | Sales and targets: people profiles, Mark as Won, clients, targets, dashboards, brand identity fetch, distribution | ⏳ |
| 6 | Hardening: logs screen, retention, theme polish, speed checks, backups, launch | ⏳ |

---

## Local setup (Windows, XAMPP)

The document targets PHP 8.4. XAMPP ships PHP 8.2, so a separate PHP 8.4 build is used
for Composer and Artisan while XAMPP's MariaDB stays as the database.

1. **PHP 8.4** — download the *NTS x64* zip from <https://windows.php.net/download/> and
   extract to `C:\php84`. Copy `php.ini-development` to `php.ini` and enable:
   `bcmath curl fileinfo gd intl mbstring exif openssl pdo_mysql pdo_sqlite sodium zip`,
   set `extension_dir = "C:/php84/ext"`, `memory_limit = 1024M` and
   `curl.cainfo` / `openssl.cafile` to `C:/xampp/apache/bin/curl-ca-bundle.crt`.
   (This repository was set up exactly this way; see `CLAUDE.md`.)
2. **Composer** — the global Composer works with PHP 8.4 via
   `C:\php84\php.exe C:\ProgramData\ComposerSetup\bin\composer.phar …`.
   In Git Bash: `export PATH="/c/php84:$PATH"` puts `php` and the `composer` wrapper first.
3. **Databases** — start MySQL in the XAMPP control panel, then:

   ```bash
   /c/xampp/mysql/bin/mysql.exe -uroot -e "CREATE DATABASE liquifycrm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE liquifycrm_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

4. **Install**

   ```bash
   composer install
   npm install
   cp .env.example .env            # then set DB_*, DB_DUMP_BINARY_PATH, DEPLOY_HOOK_TOKEN
   php artisan key:generate
   php artisan migrate
   php artisan storage:link
   npm run build                   # or `npm run dev` while working on the theme
   php artisan liquify:install     # seeds roles and creates the first Super Admin
   php artisan db:seed --class=DemoSeeder   # optional demo brands, departments and people
   php artisan serve
   ```

5. Open <http://localhost:8000/login> and sign in as the Super Admin. You are sent to
   **Password and 2FA** to set up the authenticator app (2FA is required for Super Admins and
   Admins unless `LIQUIFY_REQUIRE_TWO_FACTOR=false` is set locally). The admin area is at
   <http://localhost:8000/admin>; a brand workspace at <http://localhost:8000/b/{brand}>.
   `php artisan db:seed --class=LeadsDemoSeeder` adds sample leads to every brand (local only).

**Laravel Herd** works too: use Herd's PHP 8.4 and point `DB_*` at any MySQL 8 / MariaDB 10.4+.

### Queue and scheduler on localhost

Jobs (backups, database updates, invitation emails) run through the database queue. Either run
the scheduler the way Hostinger does, once a minute:

```bash
php artisan schedule:work
```

or drain the queue by hand when a job is waiting:

```bash
php artisan queue:work --stop-when-empty
```

### Demo accounts (password `password`)

| Role | Email |
|------|-------|
| Admin, all brands | admin@liquify.test |
| Manager, Sales Team A (UK Publishing House) | manager.a@liquify.test |
| Manager, Publishing Consultants (UK Publishing House) | manager.c@liquify.test |
| Agents, Sales Team A | agent.one@liquify.test, agent.two@liquify.test |
| Agent, Publishing Consultants | agent.three@liquify.test |
| Manager / Agent, ANZ Publishing House | manager.anz@liquify.test / agent.anz@liquify.test |

The Super Admin is whatever you entered in `liquify:install`.

## .env keys

| Key | Purpose |
|-----|---------|
| `APP_URL` | Public URL, e.g. `https://crm.yourdomain.com` |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL. On Hostinger use `127.0.0.1` |
| `DB_DUMP_BINARY_PATH` | Folder containing `mysqldump` (XAMPP: `C:/xampp/mysql/bin`; Hostinger: usually `/usr/bin`) |
| `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database` | No Redis needed |
| `MAIL_*` | SMTP for invitations and alerts (`log` driver locally) |
| `DEPLOY_HOOK_TOKEN` | Shared secret for `POST /hooks/deploy/{token}` (GitHub Actions secret `DEPLOY_TOKEN`) |
| `DEPLOY_PATH` | Deployer root on the server, e.g. `/home/USER/crm`; enables release rollback on the System screen |
| `REPORTING_CURRENCY`, `DEFAULT_CURRENCY`, `DEFAULT_TIMEZONE`, `REVEAL_LIMIT_PER_HOUR` | Defaults from the document (GBP, Europe/London, 30) |
| `INGEST_REPLAY_WINDOW`, `INGEST_RATE_LIMIT`, `SITE_QUIET_HOURS` | Website leads: seconds a signed request stays valid (300), requests per website per minute (120), hours without a lead before a website turns amber (48) |
| `LIQUIFY_REQUIRE_TWO_FACTOR` | `true` (default) forces an authenticator app on Super Admins and Admins; set `false` only on a local machine |
| `APP_DEBUG=false`, `APP_ENV=production` | Always in production |

## Quality checks

```bash
composer lint      # Pint (laravel preset, strict types)
composer analyse   # Larastan level 6
composer test      # Pest against liquifycrm_test
```

The same three run in GitHub Actions on every push (`.github/workflows/ci.yml`).

## Hostinger setup (one time)

1. Plan with SSH access (Premium, Business or Cloud). In hPanel set **PHP 8.4** and enable the
   `intl`, `gd`, `zip`, `exif`, `bcmath`, `sodium` extensions.
2. Create the MySQL database and user in hPanel. The app connects to `127.0.0.1`.
3. Create the deploy folder, e.g. `~/crm`, and inside it `shared/.env` with production values
   (`APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `DB_*`, `MAIL_*`, `DEPLOY_HOOK_TOKEN`,
   `DEPLOY_PATH=/home/USER/crm`, `DB_DUMP_BINARY_PATH=/usr/bin`).
4. Point the domain's document root at `~/crm/current/public` (or replace `public_html` with a
   symlink to it).
5. Add the GitHub Actions public key under **hPanel → Advanced → SSH Access**.
6. One cron job, every minute:

   ```
   * * * * * /usr/bin/php8.4 /home/USER/crm/current/artisan schedule:run >> /dev/null 2>&1
   ```

   The scheduler drains the queue (`queue:work --stop-when-empty --max-time=55`), records the cron
   heartbeat, runs health checks, the nightly backup and housekeeping. No always-on worker is needed.

7. First release only: SSH in and run `php artisan migrate --force` and `php artisan liquify:install`
   from `~/crm/current`. After that every database change goes through the System screen.

### GitHub repository secrets

Set these per environment (`production` for `main`, `staging` for `develop`):

`DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USER`, `DEPLOY_PATH`, `DEPLOY_PHP` (e.g. `/usr/bin/php8.4`),
`DEPLOY_SSH_KEY` (private key), `APP_URL`, `DEPLOY_TOKEN` (same value as `DEPLOY_HOOK_TOKEN`).

## Deploy steps

1. Push to `develop` (staging) or `main` (production).
2. GitHub Actions installs dependencies, builds assets, runs the tests, then runs
   `vendor/bin/dep deploy` (`deploy.php`): it uploads the build into `releases/<n>`, links the shared
   `.env` and `storage`, warms the caches and switches the `current` symlink. Users never see a
   half-uploaded site.
3. Actions calls `POST /hooks/deploy/{token}`. LiquifyCRM records the deployment and checks for
   pending migrations. Super Admins get an in-app notification if any are waiting.
4. On **System** (`/admin/system`) the Super Admin clicks **Backup and run database update**, confirms
   their password, and a queued job takes a database dump with spatie/laravel-backup, runs
   `migrate --force` and logs every line on the deployment record.
5. Something wrong? **Roll back to previous release** switches `current` back (code only); the
   backup from step 4 is on the `backups` disk for a database restore.

## Project layout

```
app/Modules/<Module>/            Models, Policies, Actions, Jobs, Http, Database/Migrations,
                                 Livewire/Admin|App (full-page screens), resources/views/livewire
app/Providers/FortifyServiceProvider.php  sign-in, password reset, 2FA, profile actions
routes/web.php                   admin.* under /admin, brand.* under /b/{brand}
resources/css/app.css            the design system (tokens from the design section)
resources/views/components/ui/   buttons, fields, cards, stats, tables, modals, toasts…
resources/views/components/layouts/, partials/   the shell (sidebar, top bar), guest layout
tests/Feature/<Module>/          Pest feature tests
deploy.php                       Deployer recipe (no migrations)
docs/                            the architecture document
```

See `CLAUDE.md` for conventions used throughout the code.
