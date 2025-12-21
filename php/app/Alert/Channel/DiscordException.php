<?php
declare(strict_types=1);

namespace Alert\Channel;

use RuntimeException;

class DiscordException extends RuntimeException
{
  public function __construct(
    string $userMessage,
    int $httpCode = 0,
    ?string $rawResponse = null
  ) {
    parent::__construct($userMessage, $httpCode);

    if ($rawResponse) {
      $this->rawResponse = $rawResponse;
    }
  }

  public ?string $rawResponse = null;
}
