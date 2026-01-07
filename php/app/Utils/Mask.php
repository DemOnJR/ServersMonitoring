<?php
declare(strict_types=1);

namespace Utils;

/**
 * Provides masking helpers for public-safe display of sensitive identifiers.
 *
 * Includes masking for IP addresses and hostnames to reduce data exposure
 * while preserving enough context for end users.
 */
final class Mask
{
    /**
     * Prevents instantiation; this class exposes only static helpers.
     */
    private function __construct()
    {
    }

    /**
     * Masks an IP address for public display.
     *
     * @param string $ip IP address (IPv4 or IPv6).
     *
     * @return string Masked IP string.
     */
    public static function ip(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);

            return sprintf('%s.***.***.%s', $p[0], $p[3]);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $p = explode(':', $ip);

            return $p[0] . ':****:****::' . end($p);
        }

        return '***.***.***.***';
    }

    /**
     * Masks a hostname while keeping the provider domain visible.
     *
     * @param string $hostname Hostname (expected to include a domain).
     *
     * @return string Masked hostname string.
     */
    public static function hostname(string $hostname): string
    {
        $parts = explode('.', $hostname, 2);

        if (count($parts) !== 2) {
            return '****';
        }

        [$host, $domain] = $parts;

        if (preg_match('/^([a-zA-Z]+)(.*)$/', $host, $m)) {
            return $m[1] . '****.' . $domain;
        }

        return '****.' . $domain;
    }
}
