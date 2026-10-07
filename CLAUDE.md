# LiquifyCRM — working notes for AI assistants

The architecture document in `docs/LiquifyCRM — MVP Architecture (Laravel).docx`
is the single source of truth. Read it before changing behaviour. Build happens
in the document's weekly phases; never skip a phase or silently change the design.

## Stack
- Laravel 13, PHP 8.4, MySQL/MariaDB, Filament 5 (Livewire 4, Tailwind 4, Vite), Pest 5.
- Only packages from the document's "Libraries and packages" table. Ask before adding any other.
- Hostinger shared hosting: database queue drained by the scheduler (one cron), no Redis, no daemons.

## Local toolchain (Windows, XAMPP)
- PHP 8.4 lives in `C:\php84` (XAMPP ships 8.2, which is too old). Prepend it to PATH:
  `export PATH="/c/php84:$PATH"` in Git Bash. `composer` there is a wrapper for the global composer.phar.
- MariaDB from XAMPP: databases `liquifycrm` (dev) and `liquifycrm_test` (Pest, configured in phpunit.xml).
- Run the app with `php artisan serve` (http://localhost:8000). Build assets with `npm run build`.

## Layout
- `app/Modules/<Module>/` holds Models, Policies, Actions, Jobs, Http, Database/Migrations,
  resources/views and `Filament/Admin|App/{Resources,Pages,Widgets}`. Each module has a
  `<Module>ServiceProvider` extending `App\Modules\Core\ModuleServiceProvider` and is registered
  in `bootstrap/providers.php`.
- Two Filament panels: `/admin` (global, Super Admin + Admin) and `/b/{brand}` (brand workspace,
  Brand is the tenant). Resources are discovered from `Filament/Admin` and `Filament/App` folders.
- Brand isolation: `BelongsToBrand` trait (global scope from `CurrentBrand`), Policies, permission
  names in `App\Modules\Core\Enums\Permission`. Super Admin/Admin are `users.role`; Manager/Agent are
  brand memberships mirrored into spatie teams (team = brand_id).
- Audit trail: always log through `App\Modules\Core\Support\Audit::log(...)`.
- Theme: `resources/css/filament/liquify/theme.css`. Palette tokens come from the document.

## Conventions
- Form Requests for validation, Policies for access, Actions/Services for business logic,
  queued Jobs for slow work. `declare(strict_types=1)` everywhere. Pint (laravel preset) and
  Larastan level 6 must pass: `composer lint`, `composer analyse`, `composer test`.
- Heroicons only. One primary (orange) button per screen. Plain words on user screens.
- Commit after each meaningful step. Never commit `.env` or secrets.
