<?php
declare(strict_types=1);

namespace Auth;

class Guard
{
  public static function protect(): void
  {
    if (!Auth::check()) {
      header('Location: /login.php');
      exit;
    }
  }
}
