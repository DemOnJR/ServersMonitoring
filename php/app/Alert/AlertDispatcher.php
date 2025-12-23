<?php
declare(strict_types=1);

namespace Alert;

use Alert\AlertChannelRepository;
use Alert\Channel\DiscordChannel;

final class AlertDispatcher
{
  public function __construct(
    private AlertChannelRepository $channelRepo
  ) {
  }

  public function dispatch(
    array $rule,
    int $serverId,
    string $hostname,
    string $ip,
    float|int $value
  ): void {

    foreach ($this->channelRepo->getChannelsForRule((int) $rule['id']) as $channel) {

      if ($channel['type'] !== 'discord') {
        continue;
      }

      $cfg = json_decode($channel['config_json'], true);
      if (empty($cfg['webhook'])) {
        continue;
      }

      $mentions = $this->buildMentions($rule['mentions'] ?? null);

      $embed = [
        'title' => $rule['title'] ?: 'Alert triggered',
        'description' => $rule['description'] ?: null,
        'color' => $rule['color'] ?? METRIC_COLORS[$rule['metric']] ?? 15158332,
        'fields' => [
          ['name' => 'Server', 'value' => $hostname, 'inline' => true],
          ['name' => 'IP', 'value' => $ip, 'inline' => true],
          ['name' => 'Metric', 'value' => strtoupper($rule['metric']), 'inline' => true],
          ['name' => 'Value', 'value' => (string) $value, 'inline' => true],
          ['name' => 'Threshold', 'value' => (string) $rule['threshold'], 'inline' => true],
        ],
        'timestamp' => date('c'),
        'footer' => ['text' => 'Server Monitor'],
      ];

      (new DiscordChannel())->send(
        $cfg['webhook'],
        $mentions,
        $embed
      );
    }
  }

  private function buildMentions(?string $raw): ?string
  {
    if (!$raw) {
      return null;
    }

    $ids = preg_split('/[\s,]+/', trim($raw));
    $out = [];

    foreach ($ids as $id) {
      if (ctype_digit($id)) {
        $out[] = "<@&{$id}>";
      }
    }

    return $out ? implode(' ', $out) : null;
  }
}
