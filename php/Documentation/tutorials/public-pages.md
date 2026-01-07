# Tutorial: Public pages

## Purpose
Public pages show selected metrics without login.
They can be open to everyone or protected by a password.

## Configure
- Admin page: `/?page=public&id=SERVER_ID`

## Enable flow
1. Open `/?page=public&id=SERVER_ID`.
2. Enable the switch.
3. Save. A slug is generated (or kept).

Public URL:
- `/preview/?slug=<slug>`

## Privacy (password gate)
When enabled:
- The login form is rendered in `php/public/preview/index.php`.
- Password is verified with `password_verify`.
- Access is stored in session key: `public_page_access_<serverId>`.

## Visible widgets
You can toggle:
- CPU, RAM, Disk, Network, Uptime

These flags are stored in `server_public_pages` and read by the preview page.

## Export data
Public preview supports:
- CSV: `/preview/?slug=<slug>&export=csv`
- TXT: `/preview/?slug=<slug>&export=txt`

Export behavior:
- Uses today's metrics when available.
- Falls back to latest snapshot when no data for today.
