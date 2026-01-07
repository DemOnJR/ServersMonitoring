# Advanced: Public file map

This page lists every file under `php/public` with role and key routes.

## Root files
### `php/public/index.php`
- Role: main front controller for the admin UI.
- Routes `/?page=...` to files under `php/public/pages`.
- Enforces auth with `Guard::protect()`.

### `php/public/login.php`
- Role: login form + session auth.

### `php/public/logout.php`
- Role: clear session and redirect to login.

### `php/public/machine (deprecated) .php`
- Role: legacy install endpoint (backward compatibility).

## Pages (admin UI)
### `php/public/pages/dashboard.php`
- Role: summary cards and install commands.
- Redirects to installer if DB schema is missing.

### `php/public/pages/servers/servers.php`
- Role: servers list, rename/delete, public toggle.
- AJAX: `/ajax/server.php`, `/ajax/public.php`.

### `php/public/pages/servers/server.php`
- Role: server detail page (charts, uptime grid, disks/filesystems).
- Shows reinstall command when token exists.

### `php/public/pages/servers/public.php`
- Role: public page settings (slug, privacy, widgets).
- AJAX: `/ajax/public.php?action=saveSettings`.

### `php/public/pages/services/services.php`
- Role: services overview (issue counts per server).

### `php/public/pages/services/service.php`
- Role: service issues for one server (hashes, payloads).

### `php/public/pages/alerts/general.php`
- Role: global alerts enable/disable.
- AJAX: `/ajax/alerts_general.php`.

### `php/public/pages/alerts/rules.php`
- Role: alert rules list + delete.

### `php/public/pages/alerts/rules_edit.php`
- Role: alert/rule editor + Discord test + targets.

## AJAX endpoints
### `php/public/ajax/server.php`
- `action=saveName` (POST: `id`, `name`)
- `action=delete` (POST: `id`)

### `php/public/ajax/public.php`
- `action=toggleEnabled` (POST: `id`, `enabled`)
- `action=saveSettings` (POST: settings)

### `php/public/ajax/alert_rule_save.php`
- Creates or updates alert rules and targets.

### `php/public/ajax/alert_rule_delete.php`
- Deletes a rule and its bindings.

### `php/public/ajax/alerts_general.php`
- Saves global alerts enabled flag.

### `php/public/ajax/discord_test.php`
- Sends a Discord test message to a webhook.

## API
### `php/public/api/report.php`
- Role: agent report endpoint; writes to DB.
- Also triggers alert evaluation.

## Preview (public pages)
### `php/public/preview/index.php`
- Role: public page renderer by slug.
- Optional password gate, export CSV/TXT.

## Install
### `php/public/install/machine/index.php`
- Role: returns OS-specific install scripts (Linux/Windows).
- Token generation and optional preregistration.

### `php/public/install/web/index.php`
- Role: web installer UI (set password, run migrations).

### `php/public/install/web/migrate.php`
- Role: password write + schema install + migrations.
