<?php
declare(strict_types=1);

namespace Alert\Channel;

use RuntimeException;

/**
 * Default Discord embed colors per metric type.
 *
 * Used as a fallback when a rule does not explicitly define a color.
 */
const METRIC_COLORS = [
    'cpu' => 15158332,
    'ram' => 3447003,
    'disk' => 15844367,
    'network' => 10181046,
];

/**
 * Sends alert notifications to Discord via webhook.
 *
 * Responsible only for transport-level concerns and payload formatting,
 * without any alert evaluation or business logic.
 */
class DiscordChannel
{
    /**
     * Sends a Discord webhook payload.
     *
     * @param string $webhook Discord webhook URL.
     * @param string|null $mentions Optional mentions content (roles/users/everyone).
     * @param array<string, mixed> $embed Discord embed payload.
     *
     * @return void
     *
     * @throws RuntimeException When payload creation or transport fails.
     * @throws DiscordException When Discord responds with a non-success status.
     */
    public function send(
        string $webhook,
        ?string $mentions = null,
        array $embed = []
    ): void {
        $payload = [];

        if ($mentions !== null && trim($mentions) !== '') {
            $payload['content'] = $mentions;

            // Explicitly enable mentions to allow role/user pings when configured by the rule author.
            $payload['allowed_mentions'] = [
                'parse' => ['roles', 'users', 'everyone'],
            ];
        }

        if (!empty($embed)) {
            $payload['embeds'] = [$embed];
        }

        if ($payload === []) {
            throw new RuntimeException('Discord payload is empty.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Failed to encode Discord payload as JSON.');
        }

        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException('Discord cURL error: ' . $error);
        }

        // Discord returns HTTP 204 No Content for successful webhook delivery.
        if ($httpCode !== 204) {

            $msg = 'Discord webhook error';

            if ($httpCode === 401 || $httpCode === 403) {
                $msg = 'Invalid or revoked Discord webhook';
            } elseif ($httpCode === 404) {
                $msg = 'Discord webhook not found';
            } elseif ($httpCode >= 500) {
                $msg = 'Discord service error';
            }

            throw new DiscordException(
                $msg,
                $httpCode,
                $response
            );
        }
    }
}
