-- ================================
-- SERVERS TABLE
-- One row per VPS (IP = identity)
-- ================================

CREATE TABLE IF NOT EXISTS servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT,
    ip TEXT NOT NULL UNIQUE,
    display_name TEXT,
    os TEXT,
    kernel TEXT,
    arch TEXT,
    last_seen DATETIME
);

-- ================================
-- METRICS TABLE
-- Time-series metrics
-- ================================

CREATE TABLE IF NOT EXISTS metrics (
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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers (id)
);

-- ================================
-- INDEXES (PERFORMANCE)
-- ================================
CREATE INDEX IF NOT EXISTS idx_servers_ip ON servers (ip);

CREATE INDEX IF NOT EXISTS idx_metrics_server_time ON metrics (server_id, created_at);

CREATE INDEX IF NOT EXISTS idx_metrics_server_time_desc ON metrics (server_id, created_at DESC);