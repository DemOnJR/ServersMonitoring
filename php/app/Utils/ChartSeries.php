<?php
declare(strict_types=1);

namespace Utils;

/**
 * Utils\ChartSeries
 *
 * Normalizes + downsample series for Chart.js:
 * - Dedupe duplicated labels (keep last value)
 * - Clamp / round values
 * - Optionally fill gaps (carry forward previous)
 * - Downsample to max points
 * - Safe JSON encoding helper
 */
final class ChartSeries
{
  private function __construct()
  {
  }

  /* -----------------------------
     Public API
  ----------------------------- */

  /**
   * Normalize percent-like series (0..100).
   * Example input: ['labels'=>[], 'cpu'=>[], 'ram'=>[]]
   */
  public static function percent(
    array $series,
    array $keys,
    int $round = 2,
    bool $fillGaps = true
  ): array {
    $norm = self::normalizeLineSeries($series, $keys, $round, 0.0, 100.0, $fillGaps);
    return $norm;
  }

  /**
   * Normalize network-like series (>= 0, no max clamp).
   * Example input: ['labels'=>[], 'rx'=>[], 'tx'=>[]]
   */
  public static function network(
    array $series,
    array $keys,
    int $round = 2,
    bool $fillGaps = true
  ): array {
    $norm = self::normalizeLineSeries($series, $keys, $round, 0.0, null, $fillGaps);
    return $norm;
  }

  /**
   * Generic normalize (custom min/max).
   * If $max is null => no upper clamp.
   */
  public static function normalize(
    array $series,
    array $keys,
    int $round = 2,
    float $min = 0.0,
    ?float $max = null,
    bool $fillGaps = true
  ): array {
    return self::normalizeLineSeries($series, $keys, $round, $min, $max, $fillGaps);
  }

  /**
   * Downsample series to max points (keeps every Nth point).
   */
  public static function downsample(array $series, array $keys, int $maxPoints = 240): array
  {
    $labels = array_values($series['labels'] ?? []);
    $n = count($labels);
    if ($n === 0 || $n <= $maxPoints)
      return $series;

    $step = (int) max(1, (int) ceil($n / $maxPoints));

    $out = ['labels' => []];
    foreach ($keys as $k)
      $out[$k] = [];

    for ($i = 0; $i < $n; $i += $step) {
      $out['labels'][] = $labels[$i];
      foreach ($keys as $k) {
        $arr = $series[$k] ?? [];
        $out[$k][] = $arr[$i] ?? null;
      }
    }

    return $out;
  }

  /**
   * Safe JSON encode for inline JS.
   * Uses HEX_* options to avoid "</script>" issues and safer embedding.
   */
  public static function j(mixed $v): string
  {
    return (string) json_encode(
      $v,
      JSON_UNESCAPED_UNICODE
      | JSON_UNESCAPED_SLASHES
      | JSON_HEX_TAG
      | JSON_HEX_AMP
      | JSON_HEX_APOS
      | JSON_HEX_QUOT
    );
  }

  /* -----------------------------
     Internals
  ----------------------------- */

  /**
   * Dedupe labels; keep last value per label for each key; clamp + round.
   * Optionally fill gaps with previous values.
   *
   * @param array $series expects 'labels' + key arrays
   * @param array $keys   series keys e.g. ['cpu','ram']
   */
  private static function normalizeLineSeries(
    array $series,
    array $keys,
    int $round,
    float $min,
    ?float $max,
    bool $fillGaps
  ): array {
    $labels = array_values($series['labels'] ?? []);

    $out = ['labels' => []];
    foreach ($keys as $k)
      $out[$k] = [];

    /** @var array<string,int> $indexByLabel */
    $indexByLabel = [];

    $n = count($labels);
    for ($i = 0; $i < $n; $i++) {
      $label = (string) ($labels[$i] ?? '');
      if ($label === '')
        continue;

      if (!isset($indexByLabel[$label])) {
        $indexByLabel[$label] = count($out['labels']);
        $out['labels'][] = $label;
        foreach ($keys as $k)
          $out[$k][] = null;
      }

      $idx = $indexByLabel[$label];

      foreach ($keys as $k) {
        $vals = $series[$k] ?? [];
        $val = $vals[$i] ?? null;

        if ($val === null || $val === '')
          continue;

        $f = self::toFloat($val);

        // clamp
        if ($f < $min)
          $f = $min;
        if ($max !== null && $f > $max)
          $f = $max;

        $f = round($f, $round);

        // keep last value for the label
        $out[$k][$idx] = $f;
      }
    }

    if ($fillGaps) {
      foreach ($keys as $k) {
        $prev = null;
        foreach ($out[$k] as $i => $v) {
          if ($v === null) {
            $out[$k][$i] = $prev;
          } else {
            $prev = $v;
          }
        }
      }
    }

    return $out;
  }

  private static function toFloat(mixed $v): float
  {
    if (is_int($v) || is_float($v))
      return (float) $v;

    // handle "12,34" -> "12.34"
    $s = trim((string) $v);
    if ($s === '')
      return 0.0;
    $s = str_replace(',', '.', $s);

    // remove any non-number chars except . and -
    $s = preg_replace('/[^0-9\.\-]+/', '', $s) ?? '0';

    return (float) $s;
  }
}
