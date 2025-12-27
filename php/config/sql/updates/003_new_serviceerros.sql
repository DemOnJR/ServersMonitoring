CREATE TABLE service_error_fingerprints (
  id INTEGER PRIMARY KEY AUTOINCREMENT,

  -- store a SHORT hash (recommended: first 16 hex chars of sha256 = 64-bit)
  -- Example from agent: sha256(unit|normalized_msg) then take substr(1,16)
  hash TEXT NOT NULL UNIQUE,

  -- store original info once
  normalized_message TEXT NOT NULL,
  sample_message TEXT,           -- optional: keep an example with nicer formatting
  first_seen INTEGER NOT NULL DEFAULT (strftime('%s','now')),
  last_seen  INTEGER NOT NULL DEFAULT (strftime('%s','now')),

  -- optional stats
  seen_total INTEGER NOT NULL DEFAULT 1
);

CREATE INDEX idx_sef_last_seen ON service_error_fingerprints(last_seen);

CREATE TABLE service_error_occurrences (
  id INTEGER PRIMARY KEY AUTOINCREMENT,

  server_id INTEGER NOT NULL,
  fingerprint_id INTEGER NOT NULL,

  service TEXT NOT NULL,          -- spamd.service
  priority TEXT,                  -- warning/error/crit...
  active_state TEXT,
  sub_state TEXT,
  exec_status TEXT,
  restarts TEXT,

  first_seen INTEGER NOT NULL DEFAULT (strftime('%s','now')),
  last_seen  INTEGER NOT NULL DEFAULT (strftime('%s','now')),

  hit_count INTEGER NOT NULL DEFAULT 1,

  -- optional: store last payload info without changing schema every time
  last_payload_json TEXT,

  FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
  FOREIGN KEY (fingerprint_id) REFERENCES service_error_fingerprints(id) ON DELETE CASCADE,

  -- one row per (server, service, fingerprint)
  UNIQUE(server_id, service, fingerprint_id)
);

CREATE INDEX idx_seo_server_last ON service_error_occurrences(server_id, last_seen DESC);
CREATE INDEX idx_seo_fingerprint ON service_error_occurrences(fingerprint_id);

CREATE TABLE service_error_daily (
  server_id INTEGER NOT NULL,
  fingerprint_id INTEGER NOT NULL,
  service TEXT NOT NULL,

  day INTEGER NOT NULL,          -- YYYYMMDD as integer (example: 20251227)
  hit_count INTEGER NOT NULL DEFAULT 1,

  first_seen INTEGER NOT NULL DEFAULT (strftime('%s','now')),
  last_seen  INTEGER NOT NULL DEFAULT (strftime('%s','now')),

  FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
  FOREIGN KEY (fingerprint_id) REFERENCES service_error_fingerprints(id) ON DELETE CASCADE,

  PRIMARY KEY (server_id, service, fingerprint_id, day)
);

CREATE INDEX idx_sed_day ON service_error_daily(day);
CREATE INDEX idx_sed_server_day ON service_error_daily(server_id, day DESC);
