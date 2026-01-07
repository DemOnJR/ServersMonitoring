# Tutorial: Alerts

## Purpose
Alerts evaluate metrics and send notifications (Discord).

## UI pages
- Rules list: `/?page=alerts-rules`
- Rule editor: `/?page=alerts-edit&id=ALERT_ID`
- General settings: `/?page=alerts-general`

## Rules list
File: `php/public/pages/alerts/rules.php`

Actions:
- Create alert: button -> `/?page=alerts-edit`
- Edit alert: `/?page=alerts-edit&id=...`
- Delete alert: POST `/ajax/alert_rule_delete.php` with `id`

## Rule editor
File: `php/public/pages/alerts/rules_edit.php`

Rule structure (think "Alert" + many "Sub-rules"):
- Alert header: title, description, enabled.
- Sub-rule fields:
  - Metric: `cpu`, `ram`, `disk`, `network`
  - Operator: `>`, `>=`, `<`, `<=`
  - Threshold (percentage)
  - Cooldown (seconds)
  - Discord webhook + optional mentions
  - Target servers

Tips:
- Cooldown `0` means "send every time".
- Mentions support role ids (they are formatted as `<@&ROLE_ID>`).
- Each sub-rule can target different server sets.

Save endpoint:
- POST `/ajax/alert_rule_save.php`

Test webhook:
- POST `/ajax/discord_test.php`

## General settings
File: `php/public/pages/alerts/general.php`

Save endpoint:
- POST `/ajax/alerts_general.php` with `enabled=0|1`

## How alert evaluation works
When `/api/report.php` receives metrics:
- It normalizes metrics to percentages (CPU/RAM/Disk).
- It calls `AlertEvaluator` to evaluate rules and dispatch notifications.

Dispatch flow:
1. `AlertEvaluator` checks metric vs threshold.
2. `AlertStateRepository` enforces cooldown.
3. `AlertDispatcher` sends to Discord webhook.
