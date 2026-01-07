<?php
declare(strict_types=1);

namespace Alert;

use Alert\AlertChannelRepository;
use Alert\Channel\DiscordChannel;

/**
 * Dispatches alert notifications to the configured channels.
 *
 * Translates rule + runtime context into channel-specific payloads.
 */
final class AlertDispatcher
{
  /**
   * AlertDispatcher constructor.
   *
   * @param AlertChannelRepository $channelRepo Repository for channels bound to a rule.
   */
  public function __construct(
    private AlertChannelRepository $channelRepo
  ) {
  }

  /**
   * Dispatches an alert to all channels configured for the given rule.
   *
   * @param array<string, mixed> $rule Rule configuration and presentation details.
   * @param int $serverId Server identifier.
   * @param string $hostname Server hostname.
   * @param string $ip Server IP address.
   * @param float|int $value Observed metric value that triggered the rule.
   *
   * @return void
   */
  public function dispatch(
    array $rule,
    int $serverId,
    string $hostname,
    string $ip,
    float|int $value
  ): void {

    foreach ($this->channelRepo->getChannelsForRule((int) $rule['id']) as $channel) {

      if ((string) ($channel['type'] ?? '') !== 'discord') {
        continue;
      }

      $cfg = json_decode((string) ($channel['config_json'] ?? '{}'), true) ?: [];
      if (empty($cfg['webhook'])) {
        // Skip silently to avoid blocking alerts when a channel is misconfigured.
        continue;
      }

      $mentions = $this->buildMentions(isset($rule['mentions']) ? (string) $rule['mentions'] : null);

      $embed = [
        'title' => !empty($rule['title']) ? (string) $rule['title'] : 'Alert triggered',
        'description' => !empty($rule['description']) ? (string) $rule['description'] : null,
        'color' => $rule['color'] ?? METRIC_COLORS[$rule['metric']] ?? 15158332,
        'fields' => [
          ['name' => 'Server', 'value' => $hostname, 'inline' => true],
          ['name' => 'IP', 'value' => $ip, 'inline' => true],
          ['name' => 'Metric', 'value' => strtoupper((string) ($rule['metric'] ?? '')), 'inline' => true],
          ['name' => 'Value', 'value' => (string) $value, 'inline' => true],
          ['name' => 'Threshold', 'value' => (string) ($rule['threshold'] ?? ''), 'inline' => true],
        ],
        'timestamp' => date('c'),
        'footer' => ['text' => 'Server Monitor'],
      ];

      (new DiscordChannel())->send(
        (string) $cfg['webhook'],
        $mentions,
        $embed
      );
    }
  }

  /**
   * Builds Discord role mentions from raw ids.
   *
   * Accepts comma/space separated role ids and converts them to <@&ROLE_ID>.
   *
   * @param string|null $raw Raw mentions input.
   *
   * @return string|null Formatted mentions string or null if none are valid.
   */
  private function buildMentions(?string $raw): ?string
  {
    if ($raw === null || trim($raw) === '') {
      return null;
    }

    $ids = preg_split('/[\s,]+/', trim($raw)) ?: [];
    $out = [];

    foreach ($ids as $id) {
      if ($id !== '' && ctype_digit($id)) {
        $out[] = "<@&{$id}>";
      }
    }

    return $out ? implode(' ', $out) : null;
  }
}
