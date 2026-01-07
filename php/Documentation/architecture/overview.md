# Architecture overview

## High level
The app has two main layers:
- `php/App`: domain logic (services, repositories, helpers).
- `php/public`: entry points (UI pages, AJAX, API, install, preview).

## Request flow
1. Browser or agent hits `php/public/*`.
2. Controllers/pages call services and repositories in `php/App`.
3. Repositories read/write SQLite.

## Data flow (agent -> UI)
1. Agent posts metrics to `/api/report.php`.
2. Metrics are written to DB, server identity is updated.
3. UI pages read latest snapshots and render charts.
