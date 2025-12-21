-- Remove the old unique constraint (SQLite style)
DROP INDEX IF EXISTS alert_channels_type_name_unique;

-- Create a new unique index based on webhook
CREATE UNIQUE INDEX IF NOT EXISTS alert_channels_discord_webhook_unique
ON alert_channels (type, config_json);

DROP TABLE alert_channels;

CREATE TABLE alert_channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    config_json TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT,
    updated_at TEXT
);
