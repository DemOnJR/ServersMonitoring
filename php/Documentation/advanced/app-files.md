# Advanced: App file map

This page lists every file under `php/App` with a short role and key methods.

## Bootstrap
### `php/App/Bootstrap.php`
- Role: load config, autoload classes, open DB, start session, set headers.
- Key: `appBaseUrl()` returns scheme/host (proxy-aware).

## Auth
### `php/App/Auth/Auth.php`
- Role: session auth, login, logout, brute-force block.
- Key methods: `check()`, `login()`, `logout()`, `isBlocked()`.

### `php/App/Auth/Guard.php`
- Role: protect routes, redirect to login when not authenticated.
- Key method: `protect()`.

## Database
### `php/App/Database/PDO.php`
- Role: PDO wrapper with secure defaults (exceptions, assoc fetch, no emulation).

## Install
### `php/App/Install/AgentInstall.php`
- Role: build install URL + command for Linux/Windows.
- Key methods: `fromServer()`, `isWindows()`, `makeInstallUrl()`, `makeCmd()`.

## Metrics
### `php/App/Metrics/MetricsRepository.php`
- Role: insert and read metric snapshots.
- Key methods: `insert()`, `today()`, `latest()`, `todayStartTimestamp()`.

### `php/App/Metrics/MetricsService.php`
- Role: chart series + uptime grid on top of repository.
- Key methods: `cpuRamSeries()`, `networkSeries()`, `uptimeGrid()`.

## Preview
### `php/App/Preview/PublicPreviewRepository.php`
- Role: load public preview config by slug + server resources.
- Key methods: `findPageBySlug()`, `getResourcesByServerId()`.

## Server
### `php/App/Server/ServerRepository.php`
- Role: server read models + identity upsert.
- Key methods: `fetchAllWithLastMetric()`, `findById()`, `upsert()`,
  `getPublicPage()`, `getIpHistory()`, `listForSelect()`.

### `php/App/Server/ServerService.php`
- Role: validate and update display name.
- Key method: `rename()`.

### `php/App/Server/ServerActions.php`
- Role: AJAX action router for server operations.

### `php/App/Server/PublicPageRepository.php`
- Role: public page settings + slug helpers + bulk map.
- Key methods: `getSettingsOrDefaults()`, `publicUrlFromSlug()`,
  `slugBaseForInput()`, `mapByServerIds()`.

### `php/App/Server/ServerViewHelpers.php`
- Role: UI helpers for OS badges and percentage colors.
- Key methods: `osBadge()`, `pctVal()`, `ringColor()`.

## Services
### `php/App/Services/ServicesOverviewService.php`
- Role: aggregated issue counts per server.
- Key method: `listServersWithServiceIssueCounts()`.

### `php/App/Services/ServiceIssuesService.php`
- Role: server info + issue list for a single server.
- Key methods: `getServer()`, `listIssuesByServerId()`.

## Utils
### `php/App/Utils/Mask.php`
- Role: mask IP and hostname for public display.
- Key methods: `ip()`, `hostname()`.

### `php/App/Utils/Formatter.php`
- Role: format bytes, disk, duration, network throughput.
- Key methods: `bytes()`, `bytesMB()`, `diskKB()`, `networkRxPerMinute()`,
  `networkTxPerMinute()`.

### `php/App/Utils/ChartSeries.php`
- Role: normalize and downsample Chart.js series.
- Key methods: `percent()`, `network()`, `downsample()`, `j()`.

## Alert
### `php/App/Alert/AlertRepository.php`
- Role: read helpers for alert headers.
- Key methods: `listAlerts()`, `findById()`, `exists()`.

### `php/App/Alert/AlertRuleRepository.php`
- Role: load rules with targets and channel config.
- Key method: `listByAlertIdWithTargetsAndChannel()`.

### `php/App/Alert/AlertSettingsRepository.php`
- Role: global alert enabled flag.
- Key method: `isAlertsEnabled()`.

### `php/App/Alert/AlertEvaluator.php`
- Role: evaluate rules against incoming metrics.
- Key method: `evaluate()`.

### `php/App/Alert/AlertDispatcher.php`
- Role: build Discord payload and send to channels.
- Key method: `dispatch()`.

### `php/App/Alert/AlertChannelRepository.php`
- Role: read enabled channels linked to a rule.
- Key methods: `getChannelsForRule()`, `getById()`.

### `php/App/Alert/AlertStateRepository.php`
- Role: cooldown state and last send time.
- Key methods: `canSend()`, `markSent()`.

### `php/App/Alert/Channel/ChannelInterface.php`
- Role: interface contract for channel delivery.

### `php/App/Alert/Channel/DiscordChannel.php`
- Role: send Discord webhook payloads.
- Key method: `send()`.

### `php/App/Alert/Channel/DiscordException.php`
- Role: Discord webhook exception wrapper.
