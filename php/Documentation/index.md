# Project structure (with files)

```
ServersMonitoring/
├─ CHANGELOG.md
└─ php/
   ├─ mkdocs.yml
   ├─ Documentation/
   │  ├─ index.md
   │  ├─ architecture/
   │  │  └─ overview.md
   │  ├─ tutorials/
   │  │  ├─ servers.md
   │  │  ├─ public-pages.md
   │  │  ├─ services.md
   │  │  ├─ alerts.md
   │  │  └─ agents.md
   │  └─ advanced/
   │     ├─ app-files.md
   │     ├─ public-files.md
   │     └─ api-payload.md
   ├─ App/
   │  ├─ Bootstrap.php
   │  ├─ Auth/
   │  │  ├─ Auth.php
   │  │  └─ Guard.php
   │  ├─ Alert/
   │  │  ├─ AlertRepository.php
   │  │  ├─ AlertRuleRepository.php
   │  │  ├─ AlertSettingsRepository.php
   │  │  ├─ AlertEvaluator.php
   │  │  ├─ AlertDispatcher.php
   │  │  ├─ AlertChannelRepository.php
   │  │  ├─ AlertStateRepository.php
   │  │  └─ Channel/
   │  │     ├─ ChannelInterface.php
   │  │     ├─ DiscordChannel.php
   │  │     └─ DiscordException.php
   │  ├─ Metrics/
   │  │  ├─ MetricsRepository.php
   │  │  └─ MetricsService.php
   │  ├─ Server/
   │  │  ├─ ServerRepository.php
   │  │  ├─ ServerService.php
   │  │  ├─ ServerActions.php
   │  │  ├─ PublicPageRepository.php
   │  │  └─ ServerViewHelpers.php
   │  ├─ Services/
   │  │  ├─ ServicesOverviewService.php
   │  │  └─ ServiceIssuesService.php
   │  ├─ Install/
   │  │  └─ AgentInstall.php
   │  ├─ Preview/
   │  │  └─ PublicPreviewRepository.php
   │  └─ Utils/
   │     ├─ Mask.php
   │     ├─ Formatter.php
   │     └─ ChartSeries.php
   ├─ public/
   │  ├─ index.php
   │  ├─ login.php
   │  ├─ logout.php
   │  ├─ api/
   │  │  └─ report.php
   │  ├─ ajax/
   │  │  ├─ server.php
   │  │  ├─ public.php
   │  │  ├─ alert_rule_save.php
   │  │  ├─ alert_rule_delete.php
   │  │  ├─ alerts_general.php
   │  │  └─ discord_test.php
   │  ├─ pages/
   │  │  ├─ dashboard.php
   │  │  ├─ servers/
   │  │  │  ├─ servers.php
   │  │  │  ├─ server.php
   │  │  │  └─ public.php
   │  │  ├─ services/
   │  │  │  ├─ services.php
   │  │  │  └─ service.php
   │  │  └─ alerts/
   │  │     ├─ general.php
   │  │     ├─ rules.php
   │  │     └─ rules_edit.php
   │  ├─ preview/
   │  │  └─ index.php
   │  └─ install/
   │     ├─ machine/
   │     │  └─ index.php
   │     └─ web/
   │        ├─ index.php
   │        └─ migrate.php
   └─ config/
      ├─ config.php
      └─ config.local.php
```

# UI categories and key pages

## Servers
- List: `https://site/?page=servers`
- Detail: `https://site/?page=server&id=SERVER_ID`
- Public settings (admin): `https://site/?page=public&id=SERVER_ID`
- Public page (anyone): `https://site/preview/?slug=<slug>`

## Services
- Overview: `https://site/?page=services`
- Server issues: `https://site/?page=service&id=SERVER_ID`

## Alerts
- Rules list: `https://site/?page=alerts-rules`
- Rule editor: `https://site/?page=alerts-edit&id=ALERT_ID`
- General: `https://site/?page=alerts-general`

## Install
- Web installer: `https://site/install/web/`
- Agent scripts:
  - Linux: `https://site/install/machine/?os=linux`
  - Windows: `https://site/install/machine/?os=windows`
