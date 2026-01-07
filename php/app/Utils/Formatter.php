<?php
declare(strict_types=1);

namespace Utils;

/**
 * Provides common formatting helpers for UI output.
 *
 * Includes duration, byte/unit formatting, percentages, and network throughput helpers.
 */
final class Formatter
{
    /**
     * Prevents instantiation; this class exposes only static helpers.
     */
    private function __construct()
    {
    }

    /**
     * Formats a duration in seconds as "Xd Xh Xm".
     *
     * @param int $seconds Duration in seconds.
     *
     * @return string Human-readable duration.
     */
    public static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%dd %dh %dm', $days, $hours, $minutes);
    }

    /**
     * Formats a byte value into B/KB/MB/GB/TB.
     *
     * @param float $bytes Bytes value.
     *
     * @return string Human-readable bytes string.
     */
    public static function bytes(float $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    /**
     * Format memory stored in MB.
     *
     * @param int|float $mb
     * @return string
     */
    public static function bytesMB(int|float $mb): string
    {
        $mb = (float) $mb;

        if ($mb <= 0) {
            return '0 MB';
        }

        return match (true) {
            $mb >= 1024 * 1024 => round($mb / (1024 * 1024), 2) . ' TB',
            $mb >= 1024 => round($mb / 1024, 1) . ' GB',
            default => (string) ((int) round($mb)) . ' MB',
        };
    }

    /**
     * Formats disk usage stored in KB into GB/TB.
     *
     * @param int $kb Disk value in KB.
     *
     * @return string Human-readable disk string.
     */
    public static function diskKB(int $kb): string
    {
        if ($kb <= 0) {
            return '0 GB';
        }

        $gb = $kb / 1024 / 1024;

        return $gb >= 1024
            ? round($gb / 1024, 2) . ' TB'
            : round($gb, 2) . ' GB';
    }

    /**
     * Formats a percentage value with one decimal.
     *
     * @param float $value Percentage value.
     *
     * @return string Formatted percentage string.
     */
    public static function pct(float $value): string
    {
        return number_format($value, 1) . '%';
    }

    /**
     * Converts CPU load (0..n) into a percentage string.
     *
     * @param float $load CPU load value.
     *
     * @return string CPU percentage string.
     */
    public static function cpuPct(float $load): string
    {
        return self::pct($load * 100);
    }

    /**
     * Formats RX throughput per minute based on the last two samples.
     *
     * @param array<int, array<string, mixed>> $metrics Metric snapshots ordered by time.
     *
     * @return string Human-readable throughput string.
     */
    public static function networkRxPerMinute(array $metrics): string
    {
        return self::bytesPerMinute(
            self::networkDelta($metrics, 'rx_bytes')
        );
    }

    /**
     * Formats TX throughput per minute based on the last two samples.
     *
     * @param array<int, array<string, mixed>> $metrics Metric snapshots ordered by time.
     *
     * @return string Human-readable throughput string.
     */
    public static function networkTxPerMinute(array $metrics): string
    {
        return self::bytesPerMinute(
            self::networkDelta($metrics, 'tx_bytes')
        );
    }

    /**
     * Formats bytes per minute into KB/min, MB/min or GB/min.
     *
     * @param int|float $bytes Bytes per minute.
     *
     * @return string Human-readable throughput string.
     */
    public static function bytesPerMinute(int|float $bytes): string
    {
        if ($bytes <= 0) {
            return '0 KB/min';
        }

        $kb = $bytes / 1024;

        if ($kb < 1024) {
            return number_format($kb, 2) . ' KB/min';
        }

        $mb = $kb / 1024;

        if ($mb < 1024) {
            return number_format($mb, 2) . ' MB/min';
        }

        $gb = $mb / 1024;

        return number_format($gb, 2) . ' GB/min';
    }

    /**
     * Computes RX/TX delta between the last two snapshots.
     *
     * Negative deltas are clamped to 0 to handle counter resets.
     *
     * @param array<int, array<string, mixed>> $metrics Metric snapshots ordered by time.
     * @param string $key Counter key (e.g. rx_bytes, tx_bytes).
     *
     * @return int Delta bytes for the last interval.
     */
    private static function networkDelta(array $metrics, string $key): int
    {
        $count = count($metrics);

        if ($count < 2) {
            return 0;
        }

        $last = (int) ($metrics[$count - 1][$key] ?? 0);
        $prev = (int) ($metrics[$count - 2][$key] ?? 0);

        return max(0, $last - $prev);
    }
}
