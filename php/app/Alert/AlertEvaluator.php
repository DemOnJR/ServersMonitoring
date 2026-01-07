<?php
declare(strict_types=1);

namespace Alert;

/**
 * Evaluates alert rules against incoming server metrics.
 *
 * Responsible for deciding whether a rule should trigger,
 * without handling persistence or delivery side effects directly.
 */
final class AlertEvaluator
{
  /**
   * AlertEvaluator constructor.
   *
   * @param AlertRuleRepository $ruleRepo Repository for active alert rules.
   * @param AlertStateRepository $stateRepo Repository for rule execution state.
   * @param AlertDispatcher $dispatcher Dispatcher responsible for delivering alerts.
   */
  public function __construct(
    private AlertRuleRepository $ruleRepo,
    private AlertStateRepository $stateRepo,
    private AlertDispatcher $dispatcher
  ) {
  }

  /**
   * Evaluates all active rules for a server against current metrics.
   *
   * @param int $serverId Server identifier.
   * @param string $hostname Server hostname.
   * @param string $ip Server IP address.
   * @param array<string, float|int> $metrics Latest collected metrics indexed by metric key.
   *
   * @return void
   */
  public function evaluate(
    int $serverId,
    string $hostname,
    string $ip,
    array $metrics
  ): void {

    foreach ($this->ruleRepo->getActiveRulesForServer($serverId) as $rule) {

      $metric = (string) $rule['metric'];

      if (!isset($metrics[$metric])) {
        continue;
      }

      $value = (float) $metrics[$metric];

      if (
        !$this->compare(
          $value,
          (string) $rule['operator'],
          (float) $rule['threshold']
        )
      ) {
        continue;
      }

      if (
        !$this->stateRepo->canSend(
          (int) $rule['id'],
          $serverId,
          (int) $rule['cooldown_seconds']
        )
      ) {
        continue;
      }

      // Dispatching is delegated to keep evaluation free of transport concerns.
      $this->dispatcher->dispatch(
        rule: $rule,
        serverId: $serverId,
        hostname: $hostname,
        ip: $ip,
        value: $value
      );

      $this->stateRepo->markSent(
        (int) $rule['id'],
        $serverId,
        $value
      );
    }
  }

  /**
   * Compares a metric value against a threshold using a dynamic operator.
   *
   * @param float $value Current metric value.
   * @param string $op Comparison operator.
   * @param float $threshold Configured threshold.
   *
   * @return bool True if comparison matches, false otherwise.
   */
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
