# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this app is

VetLink is a French-language veterinary practice management PWA. It handles clients (owners), patients (animals), consultations, interventions, invoices, reminders, and ordonnances. UI is in French; keep all user-facing text in French.

## Running the app

No build step. PHP is executed directly by the web server.

```bash
# Copy and fill env
cp .env.example .env

# Install the single Composer dependency (DomPDF)
composer install

# The app auto-migrates on boot when AUTO_MIGRATE=true (default)
```

Serve the project from the repo root at the path matching `BASE_URL` (default `/vetlink/`). Apache with mod_rewrite or PHP built-in server works.

```bash
php -S localhost:8080 -t /bkup/noxo/vetlink
# then open http://localhost:8080/index.php
```

## Routing

Single front controller: `index.php`. All pages use `?page=<name>`:

| `?page=` | File |
|---|---|
| `login` / `logout` | `pages/login.php` |
| `dashboard` | `pages/dashboard.php` |
| `clients` | `features/clients.php` |
| `client_view` | `features/client_view.php` |
| `patients` | `features/patients.php` |
| `patient_view` | `features/patient_view.php` |
| `consultation_edit` | `features/consultation_edit.php` |
| `rappel` | `features/rappel.php` |
| `factures` | `features/factures.php` |
| `intervention` | `features/intervention.php` |
| `users` | `features/users.php` (admin only) |

AJAX endpoints live in `api/`. Dashboard stats widgets each have their own endpoint (`api/dash_*.php`).

## Architecture

**No framework.** Layout = `includes/header.php` + feature file + `includes/footer.php`.

Key includes:
- `includes/auth.php` — sessions, remember-me cookies, device tokens (PWA), `require_login()`, `require_role()`
- `includes/db.php` — lazy PDO singleton via `db()`; always use prepared statements
- `includes/helpers.php` — `h()` (htmlspecialchars), `csrf_token()` / `csrf_input()` / `csrf_is_valid()`, `redirect()`, `base_url()`, `asset_url()`
- `config/env.php` — loads `.env` (and `.env.local` override) into PHP constants

**Feature files** handle both GET (render HTML) and POST (process form) in the same file. POST actions are dispatched on `$_POST['action']`.

**Modals / wizards** are included at the bottom of feature files (e.g. `includes/wizard_patient.php`) and expose `window.openWizardPatient()` / `window.openWizardPatientEdit(p)` globally.

**CSRF** — every POST form must include `<?= csrf_input() ?>`. The `csrf_check()` call at the top of each feature file validates it automatically.

## Database

- MySQL 8, database `vetlink_db`, charset `utf8mb4`
- Always use `db()->prepare(...)` — never string-interpolate into SQL
- Migrations: drop a `.sql` file in `migrations/` named `YYYYMMDD_HHMMSS_description.sql`. The runner (`includes/migrations.php`) executes each file once, tracked in `schema_migrations`. Write idempotent SQL.

## Config / env vars

Key vars (see `.env.example` for full list):

| Var | Purpose |
|---|---|
| `APP_ENV` | `prod` or `dev` |
| `DEBUG` | enables PHP error display |
| `AUTO_MIGRATE` | run migrations on every boot |
| `BASE_URL` | path prefix, e.g. `/vetlink/` |
| `OPEN_AI_API_KEY` | used in `api/voice_consult.php` for transcription |

## PWA / offline

Service worker in `assets/js/sw.js`. Device tokens (`auth_device_tokens` table) allow re-authentication without password after offline periods. `api/auth_ping.php` and `api/pwa_restore.php` support this flow.

## PDF generation

DomPDF (`vendor/`) used in `features/factures.php` and ordonnances. Render HTML then pass to DomPDF.
