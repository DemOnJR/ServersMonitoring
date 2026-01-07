<?php
declare(strict_types=1);

namespace Install;

/**
 * Generates installation metadata and commands for the monitoring agent.
 *
 * Provides OS-aware URLs and shell commands based on server context,
 * without performing any I/O or side effects.
 */
final class AgentInstall
{
  /**
   * Prevents instantiation; this class exposes only static helpers.
   */
  private function __construct()
  {
  }

  /**
   * Builds installation data based on server parameters.
   *
   * @param string $base Base installation endpoint.
   * @param string|null $os Reported operating system string.
   * @param string|null $token Optional registration token.
   *
   * @return array{
   *   isWindows: bool,
   *   url: string,
   *   cmd: string
   * }
   */
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

  /**
   * Determines whether the target OS should be treated as Windows.
   *
   * @param string|null $os Raw operating system string.
   *
   * @return bool True if the OS indicates Windows.
   */
  public static function isWindows(?string $os): bool
  {
    return str_contains(strtolower((string) $os), 'windows');
  }

  /**
   * Builds the agent installation URL.
   *
   * @param string $base Base installation endpoint.
   * @param bool $isWindows Whether the target OS is Windows.
   * @param string $token Optional registration token.
   *
   * @return string Fully qualified installation URL.
   */
  public static function makeInstallUrl(string $base, bool $isWindows, string $token): string
  {
    $url = rtrim($base, '/') . '/?os=' . ($isWindows ? 'windows' : 'linux');

    if ($token !== '') {
      $url .= '&token=' . urlencode($token);
    }

    return $url;
  }

  /**
   * Builds the installation command for the target OS.
   *
   * @param string $url Installation URL.
   * @param bool $isWindows Whether the target OS is Windows.
   *
   * @return string Shell command to execute on the target machine.
   */
  public static function makeCmd(string $url, bool $isWindows): string
  {
    return $isWindows
      ? "iwr -UseBasicParsing \"{$url}\" -OutFile servermonitor-install.ps1\n"
      . "powershell -NoProfile -ExecutionPolicy Bypass -File .\\servermonitor-install.ps1"
      : "curl -fsSLo servermonitor-install.sh \"{$url}\"\n"
      . "sudo bash servermonitor-install.sh";
  }
}
