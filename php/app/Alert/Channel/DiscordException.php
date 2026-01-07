<?php
declare(strict_types=1);

namespace Alert\Channel;

use RuntimeException;

/**
 * Represents a Discord webhook delivery failure.
 *
 * Wraps a user-friendly message plus optional raw HTTP response content for debugging.
 */
class DiscordException extends RuntimeException
{
  /**
   * Raw response body returned by Discord, when available.
   *
   * @var string|null
   */
  public ?string $rawResponse = null;

  /**
   * DiscordException constructor.
   *
   * @param string $userMessage User-facing error message.
   * @param int $httpCode HTTP status code returned by Discord.
   * @param string|null $rawResponse Raw response body for diagnostics.
   *
   * @return void
   */
  public function __construct(
    string $userMessage,
    int $httpCode = 0,
    ?string $rawResponse = null
  ) {
    parent::__construct($userMessage, $httpCode);

    if ($rawResponse !== null && $rawResponse !== '') {
      $this->rawResponse = $rawResponse;
    }
  }
}
