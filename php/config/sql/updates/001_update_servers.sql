PRAGMA foreign_keys = OFF;

DROP TABLE IF EXISTS servers;

CREATE TABLE servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT NOT NULL,
    ip TEXT NOT NULL,
    display_name TEXT,
    agent_token TEXT NOT NULL CHECK (
        length(agent_token) = 64
        AND agent_token GLOB '[0-9a-fA-F]*'
    ),
    first_seen INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    last_seen INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE UNIQUE INDEX idx_servers_agent_token ON servers (agent_token);

CREATE INDEX idx_servers_ip ON servers (ip);

CREATE INDEX idx_servers_last_seen ON servers (last_seen);

PRAGMA foreign_keys = ON;