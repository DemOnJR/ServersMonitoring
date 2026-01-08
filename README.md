# Servers Monitoring

A lightweight, self-hosted server monitoring tool built for simplicity, performance, and full control.

**Live Demo**
[https://servermonitor.pbcv.dev](https://servermonitor.pbcv.dev)

---

## Navigation

[License](LICENSE) ·
[Security](SECURITY.md) ·
[Changelog](CHANGELOG.md) ·
[Hosted Version](#hosted-version)

---

## Overview

**Server Monitor** is designed for you if you want a simple and reliable way to monitor your servers without relying on third-party services.

You keep full control over:

* Your data
* Your infrastructure
* Your deployment

The project is intentionally minimal, focused on stability and clarity rather than unnecessary features. It is ideal for VPS monitoring, personal infrastructure, or internal tools.

---

## Requirements

Before you install Server Monitor, make sure your environment meets the following requirements:

* **PHP 8.4**
* **PHP SQLite extension enabled** (`pdo_sqlite`)
* A web server (Apache or Nginx)
* A Linux-based system (recommended)
* Cron enabled (required for the monitoring agent)

No external database such as MySQL or PostgreSQL is required.

---

## Installation (Web Installer)

Server Monitor includes a built-in web installer that guides you through the setup.

### Step 1: Upload the files

Upload all project files (from php or nodejs, ecc. directory) to your web server directory (for example `public_html`).

### Step 2: Run the installer

Open the installer in your browser:

```
https://your-website-url/install/web/
```

The installer will:

* Create and initialize the SQLite database
* Apply the database schema
* Secure the database directory
* Prepare the application for first use

### Step 3: Access the dashboard

After installation completes, you will be redirected automatically to the login page or you can go to:

```
https://your-website-url/
```

---

## Monitoring Agent Installation

Once the web interface is installed, you can start monitoring servers by installing the agent.

Run the following command on each server you want to monitor:

Linux
```bash
curl -fsSLo servermonitor-install.sh "http://localhost/install/machine/?os=linux"
sudo bash servermonitor-install.sh
```

Windows
```bash
iwr -UseBasicParsing "http://localhost/install/machine/?os=windows" -OutFile servermonitor-install.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\servermonitor-install.ps1
```

The agent will:

* Collect system metrics
* Send data to your dashboard every minute
* Start automatically on reboot

---

## Key Features

When you use Server Monitor, you get:

* A lightweight monitoring agent
* A centralized web dashboard
* CPU, memory, disk, network, and process metrics
* SQLite database with no external dependencies
* Automatic installer and updater
* Secure-by-default filesystem permissions
* Full self-hosting control

---

## Architecture

Your setup consists of the following components:

* **Agent:** Bash-based script running via cron on each monitored server
* **Backend:** PHP application using SQLite
* **Frontend:** Bootstrap-based dashboard with charts
* **Database:** Local SQLite database optimized for single-user use

---

## Latest Release

<!-- CHANGELOG:START -->
## [v1.0.5] - 2026-01-07 16:00

### Added
- v1 service monitoring
- docs
- code cleaning
- other optimizations
- v1 public page added

---

<!-- CHANGELOG:END -->

> For the full history, see the [Changelog](CHANGELOG.md).

---

## Hosted Version

If you prefer a managed solution, a hosted multi-user version is planned.

The hosted version will provide:

* Multi-user access
* Centralized account management
* Extended data retention and analytics
* Managed infrastructure

The hosted service will not be open source and will be governed by separate Terms of Service.

More details will be announced later.

---

## License

You can use Server Monitor under the terms described in the [LICENSE](LICENSE) file.

---

## Security

If you find a security issue or want to review security-related guidelines, refer to
[SECURITY.md](SECURITY.md).

---

## Disclaimer

Server Monitor is intended for personal or internal use.

You are responsible for securing your deployment and ensuring compliance with your local policies and regulations.
