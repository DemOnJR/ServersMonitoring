<?php
declare(strict_types=1);

namespace Metrics;

/**
 * Provides metric retrieval and chart-ready transformations.
 *
 * Acts as an application-layer service over MetricsRepository,
 * keeping controllers thin and centralizing series/grid computations.
 */
class MetricsService
{
  /**
   * MetricsService constructor.
   *
   * @param MetricsRepository $repo Metrics repository.
   */
  public function __construct(
    private MetricsRepository $repo
  ) {
  }

  /**
   * Returns today's metrics for a server (00:00 -> now).
   *
   * @param int $serverId Server id.
   *
   * @return array<int, array<string, mixed>> Metric rows for today.
   */
  public function today(int $serverId): array
  {
    return $this->repo->today($serverId);
  }

  /**
   * Returns the latest metric snapshot for a server.
   *
   * @param int $serverId Server id.
   *
   * @return array<string, mixed>|null Latest snapshot or null if none exists.
   */
  public function latest(int $serverId): ?array
  {
    return $this->repo->latest($serverId);
  }

  /**
   * Builds CPU and RAM percentage series for charts.
   *
   * Requires RAM totals from server resources to compute RAM %.
   *
   * @param array<int, array<string, mixed>> $metrics Metric rows ordered by time.
   * @param array<string, int|string|float|null> $resources Server resources (expects ram_total).
   *
   * @return array{labels: array<int, string>, cpu: array<int, float>, ram: array<int, float>}
   */
  public function cpuRamSeries(array $metrics, array $resources): array
  {
    $labels = [];
    $cpu = [];
    $ram = [];

    $ramTotal = (int) ($resources['ram_total'] ?? 0);

    foreach ($metrics as $row) {
      $labels[] = date('H:i', (int) $row['created_at']);

      $cpu[] = min(max(((float) $row['cpu_load']) * 100, 0), 100);

      $ram[] = $ramTotal > 0
        ? min(max((((int) $row['ram_used']) / $ramTotal) * 100, 0), 100)
        : 0.0;
    }

    return [
      'labels' => $labels,
      'cpu' => $cpu,
      'ram' => $ram,
    ];
  }

  /**
   * Builds RX/TX network series in MB per interval for charts.
   *
   * Uses deltas between snapshots; negative deltas are clamped to 0
   * to handle counter resets or agent restarts.
   *
   * @param array<int, array<string, mixed>> $metrics Metric rows ordered by time.
   *
   * @return array{labels: array<int, string>, rx: array<int, float>, tx: array<int, float>}
   */
  public function networkSeries(array $metrics): array
  {
    $labels = [];
    $rx = [];
    $tx = [];

    $prevRx = null;
    $prevTx = null;

    foreach ($metrics as $row) {
      $labels[] = date('H:i', (int) $row['created_at']);

      if ($prevRx === null || $prevTx === null) {
        $rx[] = 0.0;
        $tx[] = 0.0;
      } else {
        $rx[] = max(0.0, (((int) $row['rx_bytes']) - (int) $prevRx) / 1024 / 1024);
        $tx[] = max(0.0, (((int) $row['tx_bytes']) - (int) $prevTx) / 1024 / 1024);
      }

      $prevRx = $row['rx_bytes'];
      $prevTx = $row['tx_bytes'];
    }

    return [
      'labels' => $labels,
      'rx' => $rx,
      'tx' => $tx,
    ];
  }

  /**
   * Builds a 24h×60m uptime grid for a heatmap-style UI.
   *
   * Marks future minutes explicitly to avoid misleading "offline" states
   * for time that has not happened yet.
   *
   * @param array<int, array<string, mixed>> $metrics Metric rows for today.
   *
   * @return array<int, array<int, string>> Grid with values: online|offline|future.
   */
  public function uptimeGrid(array $metrics): array
  {
    $grid = [];

    $nowH = (int) date('H');
    $nowM = (int) date('i');

    for ($h = 0; $h < 24; $h++) {
      for ($m = 0; $m < 60; $m++) {
        $grid[$h][$m] =
          ($h > $nowH || ($h === $nowH && $m > $nowM))
          ? 'future'
          : 'offline';
      }
    }

    foreach ($metrics as $row) {
      $ts = (int) $row['created_at'];
      $h = (int) date('H', $ts);
      $m = (int) date('i', $ts);
      $grid[$h][$m] = 'online';
    }

    return $grid;
  }

}
