<?php
declare(strict_types=1);

namespace Install;

final class AgentInstall
{
  private function __construct()
  {
  }

  public static function fromServer(
    string $base,
    ?string $os,
    ?string $token
  ): array {
    $isWindows = self::isWindows($os);
    $url = self::makeInstallUrl($base, $isWindows, (string) $token);

    return [
      'isWindows' => $isWindows,
      'url' => $url,
      'cmd' => self::makeCmd($url, $isWindows),
    ];
  }

  public static function isWindows(?string $os): bool
  {
    return str_contains(strtolower((string) $os), 'windows');
  }

  public static function makeInstallUrl(string $base, bool $isWindows, string $token): string
  {
    $url = rtrim($base, '/') . '/?os=' . ($isWindows ? 'windows' : 'linux');
    if ($token !== '')
      $url .= '&token=' . urlencode($token);
    return $url;
  }

  public static function makeCmd(string $url, bool $isWindows): string
  {
    return $isWindows
      ? "iwr -UseBasicParsing \"{$url}\" -OutFile servermonitor-install.ps1\n"
      . "powershell -NoProfile -ExecutionPolicy Bypass -File .\\servermonitor-install.ps1"
      : "curl -fsSLo servermonitor-install.sh \"{$url}\"\n"
      . "sudo bash servermonitor-install.sh";
  }
}
