PRAGMA foreign_keys = ON;

/* ============================================================
MIGRATIONS
============================================================ */
CREATE TABLE IF NOT EXISTS db_migrations (
    version TEXT PRIMARY KEY,
    applied_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

/* ============================================================
SERVERS (identity + network)
============================================================ */
CREATE TABLE IF NOT EXISTS servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT NOT NULL,
    ip TEXT NOT NULL,
    display_name TEXT,
    agent_token TEXT,
    first_seen INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    last_seen INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_servers_last_seen ON servers (last_seen);

CREATE INDEX IF NOT EXISTS idx_servers_ip ON servers (ip);

CREATE UNIQUE INDEX IF NOT EXISTS idx_servers_agent_token ON servers (agent_token);

/* ============================================================
SERVER IP HISTORY
============================================================ */
CREATE TABLE IF NOT EXISTS server_ip_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id INTEGER NOT NULL,
    ip TEXT NOT NULL,
    first_seen INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    last_seen INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    seen_count INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE,
    UNIQUE (server_id, ip)
);

CREATE INDEX IF NOT EXISTS idx_server_ip_history_server_id ON server_ip_history (server_id);

CREATE INDEX IF NOT EXISTS idx_server_ip_history_ip ON server_ip_history (ip);

/* ============================================================
SERVER SYSTEM INFO (static)
============================================================ */
CREATE TABLE IF NOT EXISTS server_system (
    server_id INTEGER PRIMARY KEY,
    os TEXT,
    kernel TEXT,
    arch TEXT,
    cpu_model TEXT,
    cpu_vendor TEXT,
    cpu_cores INTEGER,
    cpu_max_mhz TEXT,
    cpu_min_mhz TEXT,
    virtualization TEXT,
    machine_id TEXT,
    boot_id TEXT,
    fs_root TEXT,
    dmi_uuid TEXT,
    dmi_serial TEXT,
    board_serial TEXT,
    macs TEXT,
    disks TEXT,
    disks_json TEXT,
    filesystems_json TEXT,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_server_system_machine_id ON server_system (machine_id);

CREATE INDEX IF NOT EXISTS idx_server_system_dmi_uuid ON server_system (dmi_uuid);

/* ============================================================
SERVER RESOURCES
============================================================ */
CREATE TABLE IF NOT EXISTS server_resources (
    server_id INTEGER PRIMARY KEY,
    ram_total INTEGER,
    swap_total INTEGER,
    disk_total INTEGER,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

/* ============================================================
SERVER SNAPSHOTS
============================================================ */
CREATE TABLE IF NOT EXISTS server_snapshots (
    server_id INTEGER PRIMARY KEY,
    disks_json TEXT,
    filesystems_json TEXT,
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

/* ============================================================
METRICS (HOT TABLE)
============================================================ */
CREATE TABLE IF NOT EXISTS metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id INTEGER NOT NULL,
    cpu_load REAL NOT NULL,
    cpu_load_5 REAL,
    cpu_load_15 REAL,
    ram_used INTEGER NOT NULL,
    swap_used INTEGER NOT NULL,
    disk_used INTEGER NOT NULL,
    rx_bytes INTEGER NOT NULL,
    tx_bytes INTEGER NOT NULL,
    processes INTEGER NOT NULL,
    zombies INTEGER NOT NULL,
    failed_services INTEGER NOT NULL,
    open_ports INTEGER NOT NULL,
    uptime TEXT,
    public_ip TEXT,
    filesystems_json TEXT,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_metrics_server_time ON metrics (server_id, created_at DESC);

/* ============================================================
ALERTS
============================================================ */
CREATE TABLE IF NOT EXISTS alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

/* ============================================================
ALERT RULES
============================================================ */
CREATE TABLE IF NOT EXISTS alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_id INTEGER NOT NULL,
    metric TEXT NOT NULL,
    operator TEXT NOT NULL,
    threshold REAL NOT NULL,
    cooldown_seconds INTEGER NOT NULL DEFAULT 1800,
    enabled INTEGER NOT NULL DEFAULT 1,
    title TEXT,
    description TEXT,
    mentions TEXT,
    color INTEGER,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    FOREIGN KEY (alert_id) REFERENCES alerts (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_alert_rules_alert ON alert_rules (alert_id);

/* ============================================================
ALERT TARGETS
============================================================ */
CREATE TABLE IF NOT EXISTS alert_rule_targets (
    rule_id INTEGER NOT NULL,
    server_id INTEGER NOT NULL,
    PRIMARY KEY (rule_id, server_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

/* ============================================================
ALERT CHANNELS
============================================================ */
CREATE TABLE IF NOT EXISTS alert_channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    config_json TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE TABLE IF NOT EXISTS alert_rule_channels (
    rule_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    PRIMARY KEY (rule_id, channel_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES alert_channels (id) ON DELETE CASCADE
);

/* ============================================================
ALERT STATE
============================================================ */
CREATE TABLE IF NOT EXISTS alert_state (
    rule_id INTEGER NOT NULL,
    server_id INTEGER NOT NULL,
    last_sent_at INTEGER NOT NULL DEFAULT 0,
    last_value REAL,
    PRIMARY KEY (rule_id, server_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

/* ============================================================
SETTINGS
============================================================ */
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);