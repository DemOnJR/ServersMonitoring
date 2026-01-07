# Tutorial: Services

## Purpose
The Services area aggregates service/systemd issues reported by the agent
via the `service_issues` payload.

## Pages
- Overview: `/?page=services`
- Server details: `/?page=service&id=SERVER_ID`

## Overview page
File: `php/public/pages/services/services.php`

What it shows:
- Total issues per server
- Open issues (last 24h)
- Total hits in the open window

How it works:
- Uses `ServicesOverviewService`.
- Open window = 24h (configurable in the page).

## Server details page
File: `php/public/pages/services/service.php`

What it shows:
- Issue list with hash, message, last seen.
- Payload snapshot for debugging.

How it works:
- Uses `ServiceIssuesService`.
- "OPEN" is last seen within the open window.

## Data source (agent)
Endpoint: `POST /api/report.php`

Payload section:
- `service_issues[]`
  - `service` (required)
  - `new_unique_logs` (required, newline-separated)

Storage:
- Fingerprints are deduped and stored in:
  - `service_error_fingerprints`
  - `service_error_occurrences`
  - `service_error_daily` (rollup)

If the agent does not send `service_issues`, Services will be empty.
