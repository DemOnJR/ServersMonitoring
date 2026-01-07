<?php
declare(strict_types=1);

namespace Auth;

/**
 * Enforces authentication for protected routes.
 *
 * Redirects unauthenticated users to the login page
 * and halts further execution.
 */
class Guard
{
  /**
   * Protects the current request by requiring authentication.
   *
   * @return void
   */
  public static function protect(): void
  {
    if (!Auth::check()) {
      header('Location: /login.php');
      exit;
    }
  }
}
