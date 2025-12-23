PRAGMA foreign_keys = ON;

/* ============================================================
MIGRATIONS
============================================================ */
CREATE TABLE db_migrations (
    version TEXT PRIMARY KEY,
    applied_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

/* ============================================================
SERVERS (identity + network)
1 row per machine
============================================================ */
CREATE TABLE servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT NOT NULL,
    ip TEXT NOT NULL UNIQUE,
    display_name TEXT,
    first_seen INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    last_seen INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE INDEX idx_servers_last_seen ON servers (last_seen);

/* ============================================================
SERVER SYSTEM INFO (static / rarely changes)
============================================================ */
CREATE TABLE server_system (
    server_id INTEGER PRIMARY KEY,
    os TEXT,
    kernel TEXT,
    arch TEXT,
    cpu_model TEXT,
    cpu_cores INTEGER,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

/* ============================================================
SERVER RESOURCES (semi-static)
============================================================ */
CREATE TABLE server_resources (
    server_id INTEGER PRIMARY KEY,
    ram_total INTEGER,
    swap_total INTEGER,
    disk_total INTEGER,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

/* ============================================================
METRICS (HOT TABLE)
============================================================ */
CREATE TABLE metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id INTEGER NOT NULL,
    cpu_load REAL NOT NULL,
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
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

CREATE INDEX idx_metrics_server_time ON metrics (server_id, created_at DESC);

/* ============================================================
ALERTS
============================================================ */
CREATE TABLE alerts (
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
CREATE TABLE alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_id INTEGER NOT NULL,
    metric TEXT NOT NULL, -- cpu | ram | disk | network | offline
    operator TEXT NOT NULL, -- > >= < <=
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

CREATE INDEX idx_alert_rules_alert ON alert_rules (alert_id);

/* ============================================================
ALERT TARGETS
============================================================ */
CREATE TABLE alert_rule_targets (
    rule_id INTEGER NOT NULL,
    server_id INTEGER NOT NULL,
    PRIMARY KEY (rule_id, server_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

/* ============================================================
ALERT CHANNELS
============================================================ */
CREATE TABLE alert_channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    config_json TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE TABLE alert_rule_channels (
    rule_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    PRIMARY KEY (rule_id, channel_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES alert_channels (id) ON DELETE CASCADE
);

/* ============================================================
ALERT STATE (dedup + cooldown)
============================================================ */
CREATE TABLE alert_state (
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
CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);