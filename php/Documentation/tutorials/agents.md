# Tutorial: Agents (Linux/Windows)

## Purpose
Agents collect system metrics and send them to `/api/report.php`.

## Install endpoints
- Linux: `/install/machine/?os=linux`
- Windows: `/install/machine/?os=windows`

Optional:
- `&token=<64-hex>` to reuse an existing server identity
- `&base_url=https://site` to override auto-detection

## How install scripts are generated
File: `php/public/install/machine/index.php`

Behavior:
- Picks a token:
  - If provided and valid: used as-is
  - If missing: generates a new 64-hex token and (optionally) pre-registers it
- Replaces placeholders in `install.sh` / `install.ps1`:
  - `__BASE_URL__`
  - `__API_URL__`
  - `__TOKEN__`
- Returns full script content as plain text

## How agents report metrics
Endpoint: `POST /api/report.php`
File: `php/public/api/report.php`

Payload highlights:
- `hostname` (required)
- `metrics` (required, includes cpu/ram/disk/network)
- `machine` (optional system details)
- `service_issues` (optional logs for Services UI)
- `agent.token` or `X-Agent-Token` header for stable identity

## Linux vs Windows (data collected)
Linux agent collects:
- CPU model/cores/arch, virtualization
- Disk and filesystem JSON (lsblk/df)
- Load average, RAM/swap, disk, network counters
- Failed services and open ports

Windows agent collects:
- CPU usage (native API), RAM, disk, network counters
- OS description + kernel version

Both send the same JSON structure to the API.

## Identity and IP
Identity rules:
- If token exists: server is identified by token (stable across IP changes).
- Else: legacy IP-based behavior (upsert by IP).

IP history:
- Every report inserts/updates `server_ip_history`.
