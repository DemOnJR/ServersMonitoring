PRAGMA foreign_keys = OFF;

-- =====================================================
-- DROP EVERYTHING
-- =====================================================

DROP TABLE IF EXISTS alert_state;

DROP TABLE IF EXISTS alert_rule_channels;

DROP TABLE IF EXISTS alert_rule_targets;

DROP TABLE IF EXISTS alert_rules;

DROP TABLE IF EXISTS alert_channels;

DROP TABLE IF EXISTS alerts;

DROP TABLE IF EXISTS settings;

DROP TABLE IF EXISTS metrics;

DROP TABLE IF EXISTS servers;

-- Legacy safety
DROP TABLE IF EXISTS alert_targets;

DROP TABLE IF EXISTS alert_rules_targets;

DROP TABLE IF EXISTS alert_settings;

-- =====================================================
-- SERVERS TABLE
-- One row per VPS (IP = identity)
-- =====================================================

CREATE TABLE servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT,
    ip TEXT NOT NULL UNIQUE,
    display_name TEXT,
    os TEXT,
    kernel TEXT,
    arch TEXT,
    last_seen TEXT
);

CREATE INDEX idx_servers_ip ON servers (ip);

-- =====================================================
-- METRICS TABLE
-- Time-series metrics
-- =====================================================

CREATE TABLE metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id INTEGER NOT NULL,
    cpu_load REAL,
    cpu_cores INTEGER,
    ram_used INTEGER,
    ram_total INTEGER,
    swap_used INTEGER,
    swap_total INTEGER,
    disk_used INTEGER,
    disk_total INTEGER,
    rx_bytes INTEGER,
    tx_bytes INTEGER,
    processes INTEGER,
    zombies INTEGER,
    failed_services INTEGER,
    open_ports INTEGER,
    uptime TEXT,
    created_at TEXT NOT NULL DEFAULT(datetime('now')),
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

CREATE INDEX idx_metrics_server_time ON metrics (server_id, created_at);

CREATE INDEX idx_metrics_server_time_desc ON metrics (server_id, created_at DESC);

-- =====================================================
-- ALERT SYSTEM v2 (FINAL)
-- =====================================================

-- -----------------------------------------------------
-- ALERTS (logical container)
-- -----------------------------------------------------

CREATE TABLE alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT DEFAULT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT(datetime('now')),
    updated_at TEXT NOT NULL DEFAULT(datetime('now'))
);

-- -----------------------------------------------------
-- ALERT RULES
-- One alert ? many rules
-- -----------------------------------------------------

CREATE TABLE alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_id INTEGER NOT NULL,
    metric TEXT NOT NULL, -- cpu | ram | disk | network | offline
    operator TEXT NOT NULL, -- > >= < <=
    threshold REAL NOT NULL,
    cooldown_seconds INTEGER NOT NULL DEFAULT 1800,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT(datetime('now')),
    updated_at TEXT NOT NULL DEFAULT(datetime('now')),
    FOREIGN KEY (alert_id) REFERENCES alerts (id) ON DELETE CASCADE
);

-- -----------------------------------------------------
-- RULE ? SERVER TARGETS
-- Empty = applies to ALL servers
-- -----------------------------------------------------

CREATE TABLE alert_rule_targets (
    rule_id INTEGER NOT NULL,
    server_id INTEGER NOT NULL,
    PRIMARY KEY (rule_id, server_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

-- -----------------------------------------------------
-- ALERT CHANNELS
-- IMPORTANT: Discord webhook is stored in config_json
-- -----------------------------------------------------

CREATE TABLE alert_channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL, -- discord | email | sms | whatsapp
    name TEXT NOT NULL, -- label shown in UI
    config_json TEXT NOT NULL, -- {"webhook":"https://discord.com/api/webhooks/..."}
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT(datetime('now')),
    updated_at TEXT NOT NULL DEFAULT(datetime('now')),
    UNIQUE (type, name)
);

-- -----------------------------------------------------
-- RULE ? CHANNELS (many-to-many)
-- -----------------------------------------------------

CREATE TABLE alert_rule_channels (
    rule_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    PRIMARY KEY (rule_id, channel_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES alert_channels (id) ON DELETE CASCADE
);

-- -----------------------------------------------------
-- ALERT STATE (cronless cooldown)
-- One row per rule + server
-- -----------------------------------------------------

CREATE TABLE alert_state (
    rule_id INTEGER NOT NULL,
    server_id INTEGER NOT NULL,
    last_sent_at INTEGER NOT NULL DEFAULT 0, -- UNIX timestamp
    last_value REAL DEFAULT NULL,
    PRIMARY KEY (rule_id, server_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

-- -----------------------------------------------------
-- GLOBAL SETTINGS
-- -----------------------------------------------------

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT
    OR IGNORE INTO settings (key, value)
VALUES ('alerts_enabled', '1');

PRAGMA foreign_keys = ON;