<?php
declare(strict_types=1);

namespace Alert;

use Alert\Channel\DiscordChannel;

final class AlertEvaluator
{
  public function __construct(
    private AlertRuleRepository $rules,
    private AlertChannelRepository $channels,
    private AlertStateRepository $state
  ) {
  }

  public function evaluate(
    int $serverId,
    string $hostname,
    array $metrics
  ): void {
    $rules = $this->rules->getActiveRulesForServer($serverId);

    foreach ($rules as $rule) {
      $metric = $rule['metric'];

      if (!isset($metrics[$metric])) {
        continue;
      }

      $value = (float) $metrics[$metric];

      if (!$this->compare($value, $rule['operator'], (float) $rule['threshold'])) {
        continue;
      }

      if (
        !$this->state->canSend(
          (int) $rule['id'],
          $serverId,
          (int) $rule['cooldown_seconds']
        )
      ) {
        continue;
      }

      $channels = $this->channels->getChannelsForRule((int) $rule['id']);

      foreach ($channels as $channel) {
        if ($channel['type'] !== 'discord') {
          continue;
        }

        $cfg = json_decode($channel['config_json'], true);
        if (!isset($cfg['webhook'])) {
          continue;
        }

        $discord = new DiscordChannel();

        $discord->send(
          $cfg['webhook'],
          $rule['title'] ?: 'Alert triggered',
          $rule['description'] ?: 'Threshold exceeded',
          [
            'hostname' => $hostname,
            'ip' => $this->rules->getServerIp($serverId),
            'metric' => $metric,
            'value' => round($value, 2) . '%',
            'threshold' => "{$rule['operator']} {$rule['threshold']}%",
            'mentions' => $rule['mentions'] ?? null,
            'color' => match ($metric) {
              'cpu' => 15105570,
              'ram' => 15158332,
              'disk' => 3447003,
              'network' => 10181046,
              default => 9807270,
            },
            'title' => $rule['title'],
            'description' => $rule['description'],
          ]
        );
      }

      $this->state->markSent(
        (int) $rule['id'],
        $serverId,
        $value
      );
    }
  }

  private function compare(float $value, string $op, float $threshold): bool
  {
    return match ($op) {
      '>' => $value > $threshold,
      '>=' => $value >= $threshold,
      '<' => $value < $threshold,
      '<=' => $value <= $threshold,
      default => false,
    };
  }
}
