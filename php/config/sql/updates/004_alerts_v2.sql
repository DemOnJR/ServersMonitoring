-- =====================================================
-- ALERT SYSTEM v2 (FULL RESET)
-- =====================================================
-- This migration REPLACES the old alert system completely.
-- It DROPS previous alert tables and recreates a clean model.
-- =====================================================

PRAGMA foreign_keys = OFF;

-- -----------------------------------------------------
-- DROP OLD / BROKEN TABLES (if exist)
-- -----------------------------------------------------
DROP TABLE IF EXISTS alert_state;

DROP TABLE IF EXISTS alert_rule_channels;

DROP TABLE IF EXISTS alert_rule_servers;

DROP TABLE IF EXISTS alert_rules;

DROP TABLE IF EXISTS alert_channels;

DROP TABLE IF EXISTS alerts;

-- Older variants (safety)
DROP TABLE IF EXISTS alert_targets;

DROP TABLE IF EXISTS alert_rules_targets;

DROP TABLE IF EXISTS alert_settings;

PRAGMA foreign_keys = ON;

-- =====================================================
-- NEW ALERT SYSTEM (V2)
-- =====================================================

-- -----------------------------------------------------
-- ALERT CONTAINER
-- One alert = logical group (e.g. "Production")
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
-- One alert can have MANY rules (CPU, RAM, etc.)
-- -----------------------------------------------------
CREATE TABLE alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_id INTEGER NOT NULL,
    metric TEXT NOT NULL, -- cpu_pct, ram_pct, disk_pct, network_rx, offline
    operator TEXT NOT NULL DEFAULT '>=',
    threshold REAL NOT NULL,
    cooldown_seconds INTEGER NOT NULL DEFAULT 1800,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT(datetime('now')),
    updated_at TEXT NOT NULL DEFAULT(datetime('now')),
    FOREIGN KEY (alert_id) REFERENCES alerts (id) ON DELETE CASCADE
);

-- -----------------------------------------------------
-- RULE ? SERVER TARGETS
-- If empty => rule applies to ALL servers
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
-- Each webhook / email / SMS = ONE ROW
-- -----------------------------------------------------
CREATE TABLE alert_channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL, -- discord, email, sms, whatsapp
    name TEXT NOT NULL, -- "Discord Prod", "Discord CPU Alerts"
    config_json TEXT NOT NULL, -- {"webhook":"https://discord.com/api/webhooks/..."}
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT(datetime('now')),
    updated_at TEXT NOT NULL DEFAULT(datetime('now'))
);

-- -----------------------------------------------------
-- RULE ? CHANNELS
-- A rule can send to MANY channels
-- -----------------------------------------------------
CREATE TABLE alert_rule_channels (
    rule_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    PRIMARY KEY (rule_id, channel_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES alert_channels (id) ON DELETE CASCADE
);

-- -----------------------------------------------------
-- ALERT STATE (COOLDOWN / LAST TRIGGER)
-- Per RULE + SERVER
-- -----------------------------------------------------
CREATE TABLE alert_state (
    rule_id INTEGER NOT NULL,
    server_id INTEGER NOT NULL,
    last_sent_at INTEGER DEFAULT 0, -- UNIX timestamp
    last_value REAL DEFAULT NULL,
    PRIMARY KEY (rule_id, server_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules (id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
);

-- =====================================================
-- OPTIONAL GLOBAL SETTINGS (SAFE)
-- =====================================================
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- Enable alerting globally by default
INSERT
    OR IGNORE INTO settings (key, value)
VALUES ('alerts_enabled', '1');