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
- Brand isolation: `BelongsToBrand` trait (global scope from `CurrentBrand`), Policies, permission
  names in `App\Modules\Core\Enums\Permission`. Super Admin/Admin are `users.role`; Manager/Agent are
  brand memberships mirrored into spatie teams (team = brand_id).
- Audit trail: always log through `App\Modules\Core\Support\Audit::log(...)`.
- Website leads (`app/Modules/Sites`): `POST /api/v1/ingest/site/{site_key}` only verifies and stores an
  `InboundEvent`; the `IngestLead` job normalises it and calls `CreateLead`. Pull mode and health checks
  reuse that pipeline (`PullSiteLeads`, `SiteHealthChecker`). Secrets use encrypted casts and never reach a view.
- Facebook (`app/Modules/Meta`): `/webhooks/meta` verifies `X-Hub-Signature-256` and stores `InboundEvent`s;
  `ProcessMetaEvent` turns them into leads (Lead Ads, via `MetaGraph`) or `Conversation` / `Message` rows (Messenger).
  One global connection (`MetaConnection`), Pages mapped to brands (`MetaSource`, not brand-scoped). Each channel
  registers its job with `InboundEvent::processWith()` so the event log can retry anything.
- Design system: `resources/css/app.css` (tokens from the document, plain-CSS `lq-*` components in
  `@layer components`) and `resources/views/components/ui`. Light and dark via the `.dark` class.

## Conventions
- Form Requests for validation, Policies for access, Actions/Services for business logic,
  queued Jobs for slow work. `declare(strict_types=1)` everywhere. Pint (laravel preset) and
  Larastan level 6 must pass: `composer lint`, `composer analyse`, `composer test`.
- Heroicons only. One primary (orange) button per screen. Plain words on user screens.
- Commit after each meaningful step. Never commit `.env` or secrets.
