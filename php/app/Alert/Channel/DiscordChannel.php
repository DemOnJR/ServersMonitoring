<?php
declare(strict_types=1);

namespace Alert\Channel;

const METRIC_COLORS = [
  'cpu'     => 15158332,
  'ram'     => 3447003,
  'disk'    => 15844367,
  'network' => 10181046,
];

use RuntimeException;
use Alert\Channel\DiscordException;

class DiscordChannel
{
    public function send(
        string $webhook,
        ?string $mentions = null,
        array $embed = []
    ): void {
        $payload = [];

        // mentions (optional)
        if ($mentions) {
            $payload['content'] = $mentions;

            // IMPORTANT: allow mentions to actually ping
            $payload['allowed_mentions'] = [
                'parse' => ['roles', 'users', 'everyone']
            ];
        }

        // embed (required for rich alerts)
        if (!empty($embed)) {
            $payload['embeds'] = [$embed];
        }

        if (empty($payload)) {
            throw new RuntimeException('Discord payload is empty');
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('JSON encode failed');
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

        if ($error) {
            throw new RuntimeException('Discord cURL error: ' . $error);
        }

        // Discord returns 204 No Content on success
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
