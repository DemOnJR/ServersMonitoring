<?php
declare(strict_types=1);

namespace Alert\Channel;

interface ChannelInterface
{
  /**
   * @param string $to        Destination (webhook, email, phone)
   * @param string $title     Alert title
   * @param string $message   Alert body
   * @param array  $context   Extra data (optional)
   */
  public function send(
    string $to,
    string $title,
    string $message,
    array $context = []
  ): void;
}
