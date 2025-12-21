<?php
declare(strict_types=1);

namespace Alert;

use PDO;

final class AlertStateRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    /**
     * Cooldown check.
     * Returns true if a send is allowed NOW.
     */
    public function canSend(int $ruleId, int $serverId, int $cooldownSeconds): bool
    {
        $stmt = $this->db->prepare("
            SELECT last_sent_at
            FROM alert_state
            WHERE rule_id = ? AND server_id = ?
            LIMIT 1
        ");
        $stmt->execute([$ruleId, $serverId]);

        $lastSentAt = $stmt->fetchColumn();

        // Never sent before
        if ($lastSentAt === false || $lastSentAt === null) {
            return true;
        }

        $lastSentAt = (int) $lastSentAt;
        $now = time();

        // If cooldown passed, we can send again
        return ($now - $lastSentAt) >= max(0, $cooldownSeconds);
    }

    /**
     * Save cooldown state after a successful send.
     */
    public function markSent(int $ruleId, int $serverId, float $value): void
    {
        $now = time();

        // SQLite UPSERT (works on SQLite 3.24+)
        $stmt = $this->db->prepare("
            INSERT INTO alert_state (rule_id, server_id, last_sent_at, last_value)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(rule_id, server_id)
            DO UPDATE SET
                last_sent_at = excluded.last_sent_at,
                last_value   = excluded.last_value
        ");
        $stmt->execute([$ruleId, $serverId, $now, $value]);
    }

    /**
     * Optional helper (useful for debugging/reset)
     */
    public function reset(int $ruleId, int $serverId): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM alert_state
            WHERE rule_id = ? AND server_id = ?
        ");
        $stmt->execute([$ruleId, $serverId]);
    }
}
