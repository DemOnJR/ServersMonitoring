<?php
declare(strict_types=1);

namespace Utils;

final class Formatter
{
    private function __construct()
    {
    }

    /**
     * Format duration (seconds ? 1d 2h 3m)
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
     * Format bytes ? MB / GB / TB
     */
    public static function bytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 MB';
        }

        $gb = $bytes / 1024 / 1024 / 1024;

        if ($gb >= 1024) {
            return round($gb / 1024, 2) . ' TB';
        }
        if ($gb >= 1) {
            return round($gb, 2) . ' GB';
        }

        return round($bytes / 1024 / 1024, 2) . ' MB';
    }

    /**
     * Format memory stored in MB
     */
    public static function bytesMB(int $mb): string
    {
        if ($mb <= 0) {
            return '0 MB';
        }

        return match (true) {
            $mb >= 1024 * 1024 => round($mb / (1024 * 1024), 2) . ' TB',
            $mb >= 1024 => round($mb / 1024, 1) . ' GB',
            default => $mb . ' MB'
        };
    }

    /**
     * Format disk stored in KB
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
     * Format percent (1 decimal)
     */
    public static function pct(float $value): string
    {
        return number_format($value, 1) . '%';
    }

    /**
     * CPU load (0..n ? %)
     */
    public static function cpuPct(float $load): string
    {
        return self::pct($load * 100);
    }

    /* ==================================================
       NETWORK FORMATTERS (AUTO DELTA)
    ================================================== */

    /**
     * RX traffic per minute (human readable)
     */
    public static function networkRxPerMinute(array $metrics): string
    {
        return self::bytesPerMinute(
            self::networkDelta($metrics, 'rx_bytes')
        );
    }

    /**
     * TX traffic per minute (human readable)
     */
    public static function networkTxPerMinute(array $metrics): string
    {
        return self::bytesPerMinute(
            self::networkDelta($metrics, 'tx_bytes')
        );
    }

    /**
     * Format bytes per minute ? KB / MB / GB
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
     * INTERNAL: calculate RX/TX delta between last 2 samples
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
