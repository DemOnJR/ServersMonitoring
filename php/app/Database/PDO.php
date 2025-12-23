<?php
declare(strict_types=1);

namespace Database;

class PDO extends \PDO
{
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
