<?php
declare(strict_types=1);

namespace Utils;

final class Mask
{
    private function __construct() {}

    /**
     * Mask IP address for public display
     *
     * IPv4: 192.168.1.12 ? 192.***.***.12
     * IPv6: 2001:db8::1 ? 2001:****:****::1
     */
    public static function ip(string $ip): string
    {
        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return sprintf('%s.***.***.%s', $p[0], $p[3]);
        }

        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $p = explode(':', $ip);
            return $p[0] . ':****:****::' . end($p);
        }

        return '***.***.***.***';
    }

    /**
     * Mask hostname while keeping provider domain visible
     *
     * vmi2557073.contaboserver.net ? vmi****.contaboserver.net
     * server-123.myhost.com ? server-***.myhost.com
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
