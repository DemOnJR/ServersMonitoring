<?php
declare(strict_types=1);

namespace Metrics;

class MetricsService
{
  public function __construct(
    private MetricsRepository $repo
  ) {
  }

  // ==================================================
  // TODAY METRICS (00:00 ? NOW)
  // ==================================================
  public function today(int $serverId): array
  {
    return $this->repo->today($serverId);
  }

  // ==================================================
  // LATEST SNAPSHOT
  // ==================================================
  public function latest(int $serverId): ?array
  {
    return $this->repo->latest($serverId);
  }

  // ==================================================
  // METRICS IN RANGE
  // ==================================================
  public function range(int $serverId, int $from, int $to): array
  {
    return $this->repo->range($serverId, $from, $to);
  }

  // ==================================================
  // CPU & RAM SERIES (CHART READY)
  // Requires server_resources totals
  // ==================================================
  public function cpuRamSeries(array $metrics, array $resources): array
  {
    $labels = [];
    $cpu = [];
    $ram = [];

    $ramTotal = (int) ($resources['ram_total'] ?? 0);

    foreach ($metrics as $row) {
      $labels[] = date('H:i', (int) $row['created_at']);

      // CPU %
      $cpu[] = min(max($row['cpu_load'] * 100, 0), 100);

      // RAM %
      $ram[] = $ramTotal > 0
        ? min(max(($row['ram_used'] / $ramTotal) * 100, 0), 100)
        : 0;
    }

    return [
      'labels' => $labels,
      'cpu' => $cpu,
      'ram' => $ram,
    ];
  }

  // ==================================================
  // NETWORK SERIES (MB / INTERVAL)
  // ==================================================
  public function networkSeries(array $metrics): array
  {
    $labels = [];
    $rx = [];
    $tx = [];

    $prevRx = null;
    $prevTx = null;

    foreach ($metrics as $row) {
      $labels[] = date('H:i', (int) $row['created_at']);

      if ($prevRx === null) {
        $rx[] = 0;
        $tx[] = 0;
      } else {
        $rx[] = max(0, ($row['rx_bytes'] - $prevRx) / 1024 / 1024);
        $tx[] = max(0, ($row['tx_bytes'] - $prevTx) / 1024 / 1024);
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

  // ==================================================
  // UPTIME GRID (24h × 60m)
  // ==================================================
  public function uptimeGrid(array $metrics): array
  {
    $grid = [];

    $nowH = (int) date('H');
    $nowM = (int) date('i');

    // init grid
    for ($h = 0; $h < 24; $h++) {
      for ($m = 0; $m < 60; $m++) {
        $grid[$h][$m] =
          ($h > $nowH || ($h === $nowH && $m > $nowM))
          ? 'future'
          : 'offline';
      }
    }

    // mark online minutes
    foreach ($metrics as $row) {
      $ts = (int) $row['created_at'];
      $h = (int) date('H', $ts);
      $m = (int) date('i', $ts);
      $grid[$h][$m] = 'online';
    }

    return $grid;
  }

  // ==================================================
  // UPTIME PERCENT (TODAY)
  // ==================================================
  public function uptimePercent(array $metrics): float
  {
    if (!$metrics) {
      return 0.0;
    }

    $minutes = [];

    foreach ($metrics as $row) {
      $minutes[date('Y-m-d H:i', (int) $row['created_at'])] = true;
    }

    $online = count($minutes);
    $total = 24 * 60;

    return round(($online / $total) * 100, 2);
  }
}
