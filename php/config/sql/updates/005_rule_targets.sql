CREATE TABLE IF NOT EXISTS alert_rule_targets (
    rule_id   INTEGER NOT NULL,
    server_id INTEGER NOT NULL,

    PRIMARY KEY (rule_id, server_id),
    FOREIGN KEY (rule_id) REFERENCES alert_rules(id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);
