# Advanced: API payload reference

Endpoint: `POST /api/report.php`

## Headers
- `Content-Type: application/json`
- Optional: `X-Agent-Token: <64-hex>`

## Required fields
- `hostname`
- `metrics`

## Units
- RAM: MB
- Disk: KB
- Network counters: bytes

## Token rules
- Format: 64 hex chars.
- Invalid tokens are ignored.
- Token is accepted from header or `agent.token`.

## CPU value
`metrics.cpu` is agent-defined:
- Windows agent sends a 0..1 fraction.
- Linux agent currently sends load average (string).

## JSON Schema (simplified)
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "required": ["hostname", "metrics"],
  "properties": {
    "hostname": { "type": "string" },
    "agent": {
      "type": "object",
      "properties": {
        "token": { "type": "string", "pattern": "^[a-fA-F0-9]{64}$" }
      },
      "additionalProperties": true
    },
    "machine": {
      "type": "object",
      "properties": {
        "cpu_arch": { "type": "string" },
        "cpu_model": { "type": "string" },
        "cpu_vendor": { "type": "string" },
        "cpu_cores": { "type": "integer" },
        "cpu_max_mhz": { "type": ["number", "string"] },
        "cpu_min_mhz": { "type": ["number", "string"] },
        "virtualization": { "type": "string" },
        "fs_root": { "type": "string" },
        "machine_id": { "type": "string" },
        "boot_id": { "type": "string" },
        "dmi_uuid": { "type": "string" },
        "dmi_serial": { "type": "string" },
        "board_serial": { "type": "string" },
        "macs": { "type": "string" },
        "disks": { "type": "string" },
        "disks_json": { "type": ["array", "string"] }
      },
      "additionalProperties": true
    },
    "metrics": {
      "type": "object",
      "required": ["cpu", "ram_used", "ram_total", "disk_used", "disk_total"],
      "properties": {
        "os": { "type": "string" },
        "kernel": { "type": "string" },
        "cpu": { "type": ["number", "string"] },
        "cpu_load_5": { "type": ["number", "string"] },
        "cpu_load_15": { "type": ["number", "string"] },
        "public_ip": { "type": "string" },
        "ram_used": { "type": "integer" },
        "ram_total": { "type": "integer" },
        "swap_used": { "type": "integer" },
        "swap_total": { "type": "integer" },
        "disk_used": { "type": "integer" },
        "disk_total": { "type": "integer" },
        "rx_bytes": { "type": "integer" },
        "tx_bytes": { "type": "integer" },
        "processes": { "type": "integer" },
        "zombies": { "type": "integer" },
        "failed_services": { "type": "integer" },
        "open_ports": { "type": "integer" },
        "uptime": { "type": "string" },
        "filesystems_json": { "type": ["array", "string"] }
      },
      "additionalProperties": true
    },
    "service_issues": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["service", "new_unique_logs"],
        "properties": {
          "service": { "type": "string" },
          "new_unique_logs": { "type": "string" },
          "priority": { "type": "string" },
          "active_state": { "type": "string" },
          "sub_state": { "type": "string" },
          "exec_status": { "type": "string" },
          "restarts": { "type": ["string", "integer"] }
        },
        "additionalProperties": true
      }
    }
  },
  "additionalProperties": true
}
```

## Minimal payload example
```json
{
  "hostname": "srv-1",
  "metrics": {
    "cpu": 0.12,
    "ram_used": 512,
    "ram_total": 2048,
    "disk_used": 1048576,
    "disk_total": 8388608,
    "rx_bytes": 12000,
    "tx_bytes": 9000
  }
}
```

## Full payload example (Linux-style)
```json
{
  "hostname": "srv-1",
  "agent": { "token": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef" },
  "machine": {
    "cpu_model": "Intel(R) Xeon(R)",
    "cpu_vendor": "GenuineIntel",
    "cpu_cores": 4,
    "cpu_arch": "x86_64",
    "cpu_max_mhz": "3600.0000",
    "cpu_min_mhz": "800.0000",
    "virtualization": "kvm",
    "fs_root": "ext4",
    "machine_id": "abcdef",
    "boot_id": "123456",
    "dmi_uuid": "uuid-here",
    "dmi_serial": "serial-here",
    "board_serial": "board-serial",
    "macs": "00:11:22:33:44:55",
    "disks": "sda:disk:ssd:Samsung",
    "disks_json": [
      { "name": "sda", "size": "100G", "media": "ssd", "model": "Samsung" }
    ]
  },
  "metrics": {
    "os": "Ubuntu 22.04",
    "kernel": "5.15.0",
    "cpu": "0.35",
    "cpu_load_5": "0.20",
    "cpu_load_15": "0.10",
    "ram_used": 1024,
    "ram_total": 4096,
    "swap_used": 0,
    "swap_total": 0,
    "disk_used": 2097152,
    "disk_total": 8388608,
    "rx_bytes": 1234567,
    "tx_bytes": 7654321,
    "processes": 132,
    "zombies": 0,
    "failed_services": 0,
    "open_ports": 12,
    "uptime": "up 2 days, 3 hours",
    "public_ip": "203.0.113.10",
    "filesystems_json": [
      { "filesystem": "/dev/sda1", "fstype": "ext4", "total_kb": 8388608, "used_kb": 2097152, "used_percent": 25, "mount": "/" }
    ]
  },
  "service_issues": [
    {
      "service": "nginx",
      "new_unique_logs": "failed to start\\nport already in use",
      "priority": "high",
      "active_state": "failed",
      "sub_state": "dead",
      "exec_status": "1",
      "restarts": "3"
    }
  ]
}
```
