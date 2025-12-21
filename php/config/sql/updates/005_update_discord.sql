-- =====================================================
-- ALERT SYSTEM v2 · DISCORD UPDATE (SAFE SQLITE)
-- =====================================================
-- This migration:
--  - Safely cleans old discord relations
--  - Keeps existing alerts & rules
--  - Avoids FK constraint errors
-- =====================================================

PRAGMA foreign_keys = OFF;

-- -----------------------------------------------------
-- 1. REMOVE BROKEN RULE ? CHANNEL LINKS
-- -----------------------------------------------------
DELETE FROM alert_rule_channels
WHERE
    channel_id IN (
        SELECT id
        FROM alert_channels
        WHERE
            type = 'discord'
    );

-- -----------------------------------------------------
-- 2. REMOVE OLD DISCORD CHANNELS
-- -----------------------------------------------------
DELETE FROM alert_channels WHERE type = 'discord';

-- -----------------------------------------------------
-- 3. (OPTIONAL) INSERT A DEFAULT DISCORD CHANNEL
-- -----------------------------------------------------
-- You can delete this INSERT if you prefer only UI-created channels
INSERT INTO
    alert_channels (
        type,
        name,
        config_json,
        enabled,
        created_at,
        updated_at
    )
VALUES (
        'discord',
        'Default Discord Webhook',
        json (
            '{"webhook":"https://discord.com/api/webhooks/REPLACE_ME"}'
        ),
        1,
        datetime('now'),
        datetime('now')
    );

PRAGMA foreign_keys = ON;

-- -----------------------------------------------------
-- 4. FINAL INTEGRITY CHECK (MANUAL)
-- -----------------------------------------------------
-- Run manually after migration:
-- PRAGMA foreign_key_check;