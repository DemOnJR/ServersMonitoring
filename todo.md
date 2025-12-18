# ITSchool Project

Servers Monitoring   
Demo: [https://itschool.pbcv.dev/](https://itschool.pbcv.dev/)

## Requirements

### VPS
```c++
root (access)
ipv4
*curl
*crontab
```

### Webhost
```c++
*PHP 8.4
 - *SQLITE MODULE
```

## Install   
```bash
sqlite3 db/monitor.sqlite < sql/schema.sql
```

## Todo (Maybe 🤣)
- Alert System (Discord, Email)
  - Down
  - Network Attack (DDoS Detection)
  - Services (Extra)
    - Error, Down

- *Services Monitoring (Extra)
  - Services list
  - Service page
    - Uptime, Logs (Filter Errors)

- Discord Server Alerts Manager (Extra)
  - Create / Choose Channel
    - Permission Groups

- N8N Auto Solve issues (Extra)
  - todo