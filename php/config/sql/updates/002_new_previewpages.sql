
CREATE TABLE server_public_pages (
  server_id INTEGER PRIMARY KEY,
  enabled INTEGER NOT NULL DEFAULT 0,

  slug TEXT NOT NULL UNIQUE,            -- link public: ex "paris-vps"
  is_private INTEGER NOT NULL DEFAULT 0,
  password_hash TEXT,                   -- NULL dac? nu e parol?

-- ce afi?ezi pe pagina public?
show_cpu INTEGER NOT NULL DEFAULT 1,
  show_ram INTEGER NOT NULL DEFAULT 1,
  show_disk INTEGER NOT NULL DEFAULT 1,
  show_network INTEGER NOT NULL DEFAULT 1,
  show_uptime INTEGER NOT NULL DEFAULT 1,

  created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
  updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),

  FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

CREATE INDEX idx_public_pages_enabled ON server_public_pages (enabled);

CREATE INDEX idx_public_pages_slug ON server_public_pages (slug);