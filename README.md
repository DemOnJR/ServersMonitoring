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

* **Docker**

## Install
How to install our app:
```bash
docker pull pbdaemon/serversmonitoring:latest
```

You can also use older versions from 1.0.5 example:
```bash
docker pull pbdaemon/serversmonitoring:1.0.5
```

---

## Configuration

Server Monitor includes a built-in web installer that guides you through the setup.

### Step 1: Run the installer

Open the installer in your browser:

```
https://your-website-url/install/web/
```

The installer will:

* Create and initialize the SQLite database
* Apply the database schema
* Secure the database directory
* Prepare the application for first use

### Step 2: Access the dashboard

After installation completes, you will be redirected automatically to the login page or you can go to:

```
https://your-website-url/
```

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
