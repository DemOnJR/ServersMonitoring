<?php
declare(strict_types=1);

namespace Database;

/**
 * PDO wrapper with secure default configuration.
 *
 * Enforces exception-based error handling, associative fetch mode,
 * and disables emulated prepared statements by default.
 */
class PDO extends \PDO
{
  /**
   * PDO constructor with opinionated defaults.
   *
   * Custom options override the provided defaults.
   *
   * @param string $dsn Data Source Name.
   * @param string|null $username Database username.
   * @param string|null $password Database password.
   * @param array<int, mixed> $options Optional PDO configuration overrides.
   *
   * @throws \PDOException When the connection fails.
   */
  public function __construct(
    string $dsn,
    ?string $username = null,
    ?string $password = null,
    array $options = []
  ) {
    $defaults = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    parent::__construct($dsn, $username, $password, $options + $defaults);
  }
}
