<?php
declare(strict_types=1);

namespace Utils;

/**
 * Normalizes and downsamples line series for Chart.js.
 *
 * Provides helpers to dedupe labels, clamp/round values, optionally fill gaps,
 * downsample to a maximum number of points, and safely JSON-encode data for inline JS.
 */
final class ChartSeries
{
  /**
   * Prevents instantiation; this class exposes only static helpers.
   */
  private function __construct()
  {
  }

  /**
   * Normalizes percent-like series (0..100).
   *
   * @param array{labels?: array<int, mixed>} $series Series data with labels.
   * @param array<int, string> $keys Series keys (e.g. ['cpu', 'ram']).
   * @param int $round Decimal precision.
   * @param bool $fillGaps Whether to carry forward previous values for missing points.
   *
   * @return array<string, array<int, mixed>> Normalized series.
   */
  public static function percent(
    array $series,
    array $keys,
    int $round = 2,
    bool $fillGaps = true
  ): array {
    return self::normalizeLineSeries($series, $keys, $round, 0.0, 100.0, $fillGaps);
  }

  /**
   * Normalizes network-like series (>= 0, no upper clamp).
   *
   * @param array{labels?: array<int, mixed>} $series Series data with labels.
   * @param array<int, string> $keys Series keys (e.g. ['rx', 'tx']).
   * @param int $round Decimal precision.
   * @param bool $fillGaps Whether to carry forward previous values for missing points.
   *
   * @return array<string, array<int, mixed>> Normalized series.
   */
  public static function network(
    array $series,
    array $keys,
    int $round = 2,
    bool $fillGaps = true
  ): array {
    return self::normalizeLineSeries($series, $keys, $round, 0.0, null, $fillGaps);
  }

  /**
   * Normalizes a series using custom bounds.
   *
   * @param array{labels?: array<int, mixed>} $series Series data with labels.
   * @param array<int, string> $keys Series keys.
   * @param int $round Decimal precision.
   * @param float $min Minimum clamp value.
   * @param float|null $max Maximum clamp value or null to disable upper clamp.
   * @param bool $fillGaps Whether to carry forward previous values for missing points.
   *
   * @return array<string, array<int, mixed>> Normalized series.
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
   * Downsamples series to a maximum number of points by keeping every Nth point.
   *
   * @param array<string, mixed> $series Series data with labels and value arrays.
   * @param array<int, string> $keys Series keys to downsample alongside labels.
   * @param int $maxPoints Maximum number of points to keep.
   *
   * @return array<string, array<int, mixed>> Downsampled series.
   */
  public static function downsample(array $series, array $keys, int $maxPoints = 240): array
  {
    $labels = array_values($series['labels'] ?? []);
    $n = count($labels);

    if ($n === 0 || $n <= $maxPoints) {
      return $series;
    }

    $step = (int) max(1, (int) ceil($n / $maxPoints));

    $out = ['labels' => []];
    foreach ($keys as $k) {
      $out[$k] = [];
    }

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
   * JSON-encodes a value for safe embedding into inline JavaScript.
   *
   * @param mixed $v Value to encode.
   *
   * @return string Encoded JSON string.
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

  /**
   * Normalizes a line series by deduping labels and keeping the last value per label.
   *
   * Optionally fills gaps by carrying the previous known value forward.
   *
   * @param array<string, mixed> $series Series data; expects 'labels' + arrays for each key.
   * @param array<int, string> $keys Series keys (e.g. ['cpu', 'ram']).
   * @param int $round Decimal precision.
   * @param float $min Minimum clamp value.
   * @param float|null $max Maximum clamp value or null to disable upper clamp.
   * @param bool $fillGaps Whether to carry forward previous values for missing points.
   *
   * @return array<string, array<int, mixed>> Normalized series.
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
    foreach ($keys as $k) {
      $out[$k] = [];
    }

    /** @var array<string, int> $indexByLabel */
    $indexByLabel = [];

    $n = count($labels);

    for ($i = 0; $i < $n; $i++) {
      $label = (string) ($labels[$i] ?? '');

      if ($label === '') {
        continue;
      }

      if (!isset($indexByLabel[$label])) {
        $indexByLabel[$label] = count($out['labels']);
        $out['labels'][] = $label;

        foreach ($keys as $k) {
          $out[$k][] = null;
        }
      }

      $idx = $indexByLabel[$label];

      foreach ($keys as $k) {
        $vals = $series[$k] ?? [];
        $val = $vals[$i] ?? null;

        if ($val === null || $val === '') {
          continue;
        }

        $f = self::toFloat($val);

        if ($f < $min) {
          $f = $min;
        }
        if ($max !== null && $f > $max) {
          $f = $max;
        }

        $out[$k][$idx] = round($f, $round);
      }
    }

    if ($fillGaps) {
      foreach ($keys as $k) {
        $prev = null;

        foreach ($out[$k] as $i => $v) {
          if ($v === null) {
            $out[$k][$i] = $prev;
            continue;
          }

          $prev = $v;
        }
      }
    }

    return $out;
  }

  /**
   * Converts mixed numeric input into a float.
   *
   * Accepts comma decimals and strips non-numeric characters except dot and minus.
   *
   * @param mixed $v Input value.
   *
   * @return float Parsed float value.
   */
  private static function toFloat(mixed $v): float
  {
    if (is_int($v) || is_float($v)) {
      return (float) $v;
    }

    $s = trim((string) $v);

    if ($s === '') {
      return 0.0;
    }

    $s = str_replace(',', '.', $s);
    $s = preg_replace('/[^0-9\.\-]+/', '', $s) ?? '0';

    return (float) $s;
  }
}
