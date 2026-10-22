# LiquifyCRM handoff (12 October 2026)

Give this file to the next chat. It says where the project stands, what was done in the last
session, what the user decided, what is still open, and how to work on it without repeating
mistakes. It holds no passwords or keys.

The user is Rabeet. He writes in Roman Urdu; answer in Roman Urdu. He judges the product by how it
looks and how obvious each screen is.

## 1. Where things stand

- LiquifyCRM (Laravel 13, PHP 8.4, Livewire 4, Fortify, Tailwind 4, Pest 5) is built through all six
  weekly phases of the architecture document and is **live** at
  https://luminocrm.online (the company's Hostinger account since 22 Oct 2026; it was
  https://slategrey-leopard-774735.hostingersite.com before).
- The code is at **version 1.6.1** (`resources/changelog.php`), commit `b727689` on `main`, pushed.
  This file and `tests/tools/shots.mjs` were added after that push; see section 9 for their state.
- 187 Pest tests pass; Pint and Larastan level 6 are clean.
- Every screen has been redesigned or checked against the new design (section 3).
- On the live site the code is deployed, but **an Admin must click "Update system"** on
  `/admin/updates` to record the version (no database changes are waiting). Whether that was done for
  1.4.0 to 1.6.1 on live is not known. Locally the installed version is 1.5.0, so the local app shows
  "Version 1.6.1 is ready".

## 2. Read these first

| File | What it is |
| --- | --- |
| `CLAUDE.md` | Working rules, layout of the code, the design system, every standing decision |
| `docs/STATUS.md` | Every item of the architecture document as done or pending, and the agreed differences |
| `resources/changelog.php` | What changed in each version, in plain words (also the app's version) |
| `docs/LiquifyCRM — MVP Architecture (Laravel).docx` | The contract; read before changing behaviour |
| The memory folder of this project | Decisions, preferences, hosting facts and tooling notes |

Note: the "Week 6 · needs you" list in `docs/STATUS.md` was written before the Hostinger launch.
Its lines about a staging deploy and about the local Meta setup are out of date; section 5 below is
the current list.

## 3. What was done in the last session

In order, with the commit and the version each one shipped as.

1. **Leftovers** (`f02d3d1`, 1.1.0): a website's favicon and name as the lead source; the target bar on Profile.
2. **Live on Hostinger** (`216c763`): GitHub auto-deploy of `main` into `public_html`; the root
   `.htaccess` sends everything to `public/`; `public/build` is committed because the server has no npm.
3. **Facebook on the live domain**: the Meta app "LiquifyCRM Leads" (id 1736979774197502) points at
   the live webhook and OAuth callback; Facebook is connected on live.
4. **Refinement pass** (`d5e3b46`, 1.2.0): premium look, subtle glass in dark mode, animations,
   dark mode that survives navigation, one account menu, People screen (Admins, Managers, Agents with
   designation and target), round robin per department, the admin sales dashboard one brand at a
   time, Integrations in three tabs, brand wizard that fills name and address from the website, live
   updates by polling. The exchange rates screen was removed.
5. **Email providers and Updates** (`f12e545`, `9ddeb6f`, 1.3.0): ready-made SMTP providers with
   icons from Google's favicon service; the Updates screen with "What's new" and "Update system".
6. **Leads, pipeline, inbox** (`22025e8`, 1.4.0): leads list with views, filter chips and a floating
   bulk bar; the lead panel with a clickable pipeline path, action tiles, contact card (slide-over on
   the list and the pipeline, two columns on its own page); pipeline board with scrolling columns,
   owner filter and a "quiet" mark; three-pane inbox; follow-ups as a checklist.
7. **Clients, targets, websites, logs** (`de08f85`, 1.5.0): facts on top of each list, the client
   page with a rail, targets in blocks per department with an unsaved-changes bar, website checks as
   tiles, logs as a feed by day with chips.
8. **Audit of every screen** (`2d9755c`, 1.6.0): Brands as cards, System with tiles, the department
   form, Add a lead, error pages 403 / 404 / 419 / 429 / 500 / 503, the invitation error card, solid
   menus, styled file fields.
9. **Logs for Admins only** (`b727689`, 1.6.1): see section 4.

Bugs found and fixed on the way (all have a line in the changelog):

- Every row of a live list flashed orange about a second after each page load.
- Charts threw an error when the light or dark mode changed (the Chart instance sat in Alpine's reactive data).
- Right-aligned column headings sat on the left; the People, Members and Brands tables were wider than their card.
- In a department every person was shown as Manager (an enum was compared with a string).
- Managers could see and download logs, and the dashboard's "Recent activity" showed the brand's
  audit trail to Managers and Agents.

## 4. Decisions the user made (keep them)

- **Filament is gone for good** (8 Oct). Every screen is a Livewire page on LiquifyCRM's own design system.
- **Only approved packages**: the document's table plus `blade-ui-kit/blade-heroicons` and `chart.js`. Ask before adding any other.
- **Off-server backups: declined** (8 Oct). Do not add a flysystem package or raise it again.
- **Admin equals Super Admin** (9 Oct), except protection: nobody else can edit, suspend or remove a
  Super Admin, only a Super Admin makes another one, and the last one cannot be removed.
- **No exchange rates, no converted totals** (9 Oct). The admin sales dashboard shows one brand at a
  time in its own currency. Do not bring cross-currency totals back without asking.
- **Email provider icons come from Google's favicon service** (9 Oct).
- **Logs are for Admins and Super Admins only** (12 Oct): the Logs screen, its downloads and the
  dashboard's "Recent activity". Managers and Agents see none of it, and no brand role carries a logs
  permission. A lead's own timeline was left visible to whoever may see that lead; the user was
  asked whether that should also be Admin-only and has not answered.
- **Look**: premium, uncramped, subtle glass in dark mode (not too glassy), animations, live data
  without refreshing, one corner scale, one orange button per screen, nothing duplicated, nothing
  shown before it is usable. He called the first panel "AI slop".

## 5. Open items

Needs the user:

- [ ] Click **Update system** on the live `/admin/updates`.
- [ ] Add the **scheduler cron** on Hostinger (type Custom, every minute). Without it the queue,
  notifications, Facebook leads and the automatic reply are not processed, and System shows
  "Scheduler: Not running". The exact line is on the System screen with a Copy button; it must use
  `/opt/alt/php84/usr/bin/php`, not the default `/usr/bin/php` (8.1).
- [ ] Delete the two leftover `setup.sh` crons in hPanel.
- [ ] Map the two Facebook Pages to brands on the live Integrations screen.
- [ ] Ask Meta to review the business portfolio "Rabeet Ahmed" (advertising restricted since 2022);
  Lead Ads test leads stay "Pending" at Meta, most likely because of it.
- [ ] SMTP details for the live site's email.
- [ ] Answer: should a lead's own timeline also be hidden from Managers and Agents?

Not checked by eye (covered by tests or untouched code only):

- The two-factor challenge and confirm-password pages, the invitation accept form, the welcome tour.
- The Agent's masked view of a lead; what a Manager sees on Targets and on a client page.
- Dragging a card on the pipeline with a mouse (the `move` action behind it was run).
- On live: nothing was looked at while signed in after 1.3.0. Only that the new build is served, the
  sign-in page opens and the 404 page is the new one.
- The database backup step of "Update system" has never run on Hostinger (no update so far had a
  database change). If it fails, the screen offers "Update without a backup".
- No real email was sent through any SMTP preset. Microsoft 365 may refuse password sign-in.

Not built (later phases of the document): Files, NDA and contracts, client portal, and the rest of Phase 2.

## 6. How to work locally (Windows, XAMPP)

```bash
export PATH="/c/php84:$PATH"        # XAMPP's own PHP is too old
```

- MariaDB: `cd /c/xampp && ./mysql/bin/mysqld.exe --defaults-file=mysql/bin/my.ini --standalone`
  (run in the background; it stops when the session ends). Databases `liquifycrm` and `liquifycrm_test`.
- App: the preview server named `liquify` in `.claude/launch.json` (port 8000), or `php artisan serve`.
- Assets: `npm run build` after every CSS or JS change, and commit `public/build`.
- Checks before every commit: `vendor/bin/pint`, `composer analyse`, `php artisan test --compact`.
- Local brands: `uk-publishing-house` and `anz-publishing-house` (the second has the demo leads, one
  Messenger chat, one client and one website).

## 7. How to look at screens

The browser panes are often hidden, and then screenshots time out. `tests/tools/shots.mjs` drives a
headless Chrome instead and needs no package:

```bash
node tests/tools/shots.mjs <out dir> <jobs.json>
```

- A job is `{ name, url, theme, width, height, js, wait, full }`; the header of the script explains it.
- Sign-ins come from git-ignored files under `storage/app/private/`: `local-preview-login.txt`
  (an Admin, `preview@liquify.test`) and `local-preview-manager-login.txt` (a Manager of ANZ Sales,
  `preview.manager@liquify.test`). Both accounts exist only in the local database and were made for
  this purpose. `GUEST=1` looks as a signed-out visitor. Never print the passwords.
- Look at 1280, 1366, 1440 and 1536 px and at 390 px, in light and in dark. A table must fit its card at 1280 px.
- Do not take screenshots while the test suite runs: on Windows the two fight over compiled views
  and a test fails with "rename ... Access is denied".
- Trying actions this way changes local demo data. Say so when it happens.

## 8. Deploy and the live site

- Every push to `main` deploys by itself (about 40 seconds): Hostinger pulls the repository into
  `public_html` and runs `composer install`. Untracked files (`.env`, `storage`) survive.
- After a push, Admins see "Version X is ready" and click **Update system** (`/admin/updates`): it
  backs up, runs pending migrations, re-runs `RolesAndPermissionsSeeder`, clears the caches and
  records the version, all inside the request. Never cache config or routes on the server.
- **Every push that changes what a person sees adds its lines to `resources/changelog.php`**
  (last number for fixes, middle number for features).
- To see whether a deploy has landed when no asset changed, read `resources/changelog.php` on the
  server through the Hostinger connector (username `u574013069`).
- The first Super Admin login of the live site is in `storage/app/private/hostinger-login.txt`
  (git-ignored). Never print it.
- Not allowed for Claude in earlier sessions (the user did these himself): starting a tunnel,
  creating content on his Facebook Page, creating a Hostinger cron through the connector.

## 9. State of the repository

- `main` at `b727689` is pushed and deployed.
- Added after it and committed locally, **not pushed**: this file (`docs/HANDOFF.md`) and
  `tests/tools/shots.mjs`. They change nothing a person sees, so they need no changelog line; they
  go out with the next push.
- The folder `storage/framework/phpstan` is tracked in git (it was like that before); its files
  change on every analysis run and are committed along.

## 10. Design system in one page

All in `resources/css/app.css` (plain CSS `lq-*` classes in `@layer components`) and
`resources/views/components/ui`.

- Corners: 16 px cards, 12 px inner panes and menus, 10 px controls. Only the sidebar, top bar and
  dialogs blur; menus are solid.
- A list: `lq-viewbar` with `lq-views` (tabs), `lq-search`, a Filters button, `lq-filters`, and the
  filters in use as `lq-chip`s. Rows in `lq-table lq-table-rows`; the name cell is `lq-td-person`;
  nice-to-have columns are `lq-col-wide` (hidden below 1340 px); row actions in `lq-row-tools`.
- Small facts on top: `<x-ui.mini>` inside `lq-minis`.
- A record's page: a hero (`lq-lead-hero`), then `lq-split` (the story on the left, a 22 rem rail on the right).
- A lead: `LeadPanel` everywhere (`PreviewsLead` trait and the `lead-sheet` partial for the slide-over).
- People outside the team: `<x-ui.avatar tone>`. Due dates: `<x-ui.due>`.
- Tiles for checks: `lq-probe`. **`lq-check` is the checkbox label; never reuse that name.**
- Live updates are `wire:poll`, never websockets. A row that joins a `lq-live` list is lit by `app.js`.

## 11. Mistakes not to repeat

- A helper script that rewrites a file must use `str_replace` and check the length before writing; a
  failed `preg_replace` returns null and blanked files twice.
- Bash heredocs that contain an apostrophe fail here: write the script to a file and run it.
- A Chart.js instance must live in a closure, never on Alpine's reactive `this`.
- A bar that Livewire shows with `wire:dirty` goes inside a wrapper, because Livewire sets its own `display`.
- A pivot role is an enum (`DepartmentRole`); never compare it with a string.
- When adding a screen, check what it shows to a Manager and to an Agent, not only to an Admin.
- `RETURNING` is a reserved word on newer MariaDB: do not use it as a column alias.
