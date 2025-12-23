<?php
declare(strict_types=1);

namespace Alert;

final class AlertEvaluator
{
  public function __construct(
    private AlertRuleRepository $ruleRepo,
    private AlertStateRepository $stateRepo,
    private AlertDispatcher $dispatcher
  ) {
  }

  public function evaluate(
    int $serverId,
    string $hostname,
    string $ip,
    array $metrics
  ): void {

    foreach ($this->ruleRepo->getActiveRulesForServer($serverId) as $rule) {

      $metric = $rule['metric'];

      if (!isset($metrics[$metric])) {
        continue;
      }

      $value = (float) $metrics[$metric];

      if (
        !$this->compare(
          $value,
          $rule['operator'],
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

      // ?? Delegate everything else
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
