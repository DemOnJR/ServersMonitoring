<?php
declare(strict_types=1);

namespace Alert\Channel;

/**
 * Defines a contract for alert delivery channels.
 *
 * Implementations are responsible only for delivering a prepared alert
 * to a specific destination (e.g. Discord, email, SMS).
 */
interface ChannelInterface
{
  /**
   * Sends an alert message to a destination.
   *
   * @param string $to Destination identifier (webhook URL, email address, phone number).
   * @param string $title Alert title.
   * @param string $message Alert message body.
   * @param array<string, mixed> $context Optional channel-specific context data.
   *
   * @return void
   */
  public function send(
    string $to,
    string $title,
    string $message,
    array $context = []
  ): void;
}
