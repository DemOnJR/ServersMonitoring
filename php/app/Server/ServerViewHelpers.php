<?php
declare(strict_types=1);

namespace Server;

/**
 * View-layer helper utilities for server-related UI rendering.
 *
 * Provides small, stateless helpers for presentation logic
 * such as badges, percentages and color mapping.
 */
final class ServerViewHelpers
{
  /**
   * Prevents instantiation; this class exposes only static helpers.
   */
  private function __construct()
  {
  }

  /**
   * Returns badge metadata for an operating system string.
   *
   * Maps detected OS keywords to icon, label and raw display value
   * for consistent UI rendering.
   *
   * @param string|null $os Raw operating system string.
   *
   * @return array{icon:string, label:string, raw:string} Badge metadata.
   */
  public static function osBadge(?string $os): array
  {
    $osRaw = trim((string) $os);
    $os = strtolower($osRaw);

    if ($os === '') {
      return ['icon' => 'fa-solid fa-server', 'label' => 'Unknown', 'raw' => 'Unknown'];
    }

    if (str_contains($os, 'windows')) {
      return ['icon' => 'fa-brands fa-windows', 'label' => 'Windows', 'raw' => $osRaw];
    }

    if (str_contains($os, 'freebsd')) {
      return ['icon' => 'fa-solid fa-anchor', 'label' => 'FreeBSD', 'raw' => $osRaw];
    }
    if (str_contains($os, 'openbsd')) {
      return ['icon' => 'fa-solid fa-anchor', 'label' => 'OpenBSD', 'raw' => $osRaw];
    }
    if (str_contains($os, 'netbsd')) {
      return ['icon' => 'fa-solid fa-anchor', 'label' => 'NetBSD', 'raw' => $osRaw];
    }

    if (str_contains($os, 'ubuntu')) {
      return ['icon' => 'fa-brands fa-ubuntu', 'label' => 'Ubuntu', 'raw' => $osRaw];
    }
    if (str_contains($os, 'debian')) {
      return ['icon' => 'fa-brands fa-debian', 'label' => 'Debian', 'raw' => $osRaw];
    }

    if (str_contains($os, 'centos')) {
      return ['icon' => 'fa-brands fa-centos', 'label' => 'CentOS', 'raw' => $osRaw];
    }
    if (str_contains($os, 'rocky')) {
      return ['icon' => 'fa-brands fa-redhat', 'label' => 'Rocky', 'raw' => $osRaw];
    }
    if (str_contains($os, 'alma')) {
      return ['icon' => 'fa-brands fa-redhat', 'label' => 'Alma', 'raw' => $osRaw];
    }
    if (str_contains($os, 'red hat') || str_contains($os, 'rhel')) {
      return ['icon' => 'fa-brands fa-redhat', 'label' => 'RHEL', 'raw' => $osRaw];
    }
    if (str_contains($os, 'fedora')) {
      return ['icon' => 'fa-brands fa-fedora', 'label' => 'Fedora', 'raw' => $osRaw];
    }

    if (str_contains($os, 'arch')) {
      return ['icon' => 'fa-brands fa-archlinux', 'label' => 'Arch', 'raw' => $osRaw];
    }
    if (str_contains($os, 'suse') || str_contains($os, 'opensuse')) {
      return ['icon' => 'fa-brands fa-suse', 'label' => 'SUSE', 'raw' => $osRaw];
    }

    if (str_contains($os, 'alpine')) {
      return ['icon' => 'fa-solid fa-mountain', 'label' => 'Alpine', 'raw' => $osRaw];
    }

    if (str_contains($os, 'linux')) {
      return ['icon' => 'fa-brands fa-linux', 'label' => 'Linux', 'raw' => $osRaw];
    }

    return ['icon' => 'fa-solid fa-server', 'label' => 'Other', 'raw' => $osRaw ?: 'Other'];
  }

  /**
   * Normalizes a percentage value to an integer between 0 and 100.
   *
   * @param float $v Raw percentage value.
   *
   * @return int Normalized percentage.
   */
  public static function pctVal(float $v): int
  {
    return (int) round(min(max($v, 0), 100));
  }

  /**
   * Returns a CSS class name based on a percentage threshold.
   *
   * @param int $pct Percentage value.
   *
   * @return string CSS class representing severity.
   */
  public static function ringColor(int $pct): string
  {
    return match (true) {
      $pct >= 90 => 'text-danger',
      $pct >= 75 => 'text-warning',
      default => 'text-success',
    };
  }
}
