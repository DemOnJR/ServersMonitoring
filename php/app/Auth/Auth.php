<?php
declare(strict_types=1);

namespace Auth;

class Auth
{
  public static function check(): bool
  {
    return ($_SESSION['auth'] ?? false) === true;
  }

  public static function login(string $password): bool
  {
    if (self::isBlocked()) {
      return false;
    }

    if (!password_verify($password, APP_PASSWORD_HASH)) {
      self::registerFailure();
      return false;
    }

    session_regenerate_id(true);

    $_SESSION['auth'] = true;
    $_SESSION['failures'] = 0;
    unset($_SESSION['blocked_until']);

    return true;
  }

  public static function logout(): void
  {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
      );
    }

    session_destroy();
  }

  public static function isBlocked(): bool
  {
    if (empty($_SESSION['blocked_until'])) {
      return false;
    }

    if (time() > $_SESSION['blocked_until']) {
      unset($_SESSION['blocked_until'], $_SESSION['failures']);
      return false;
    }

    return true;
  }

  private static function registerFailure(): void
  {
    $_SESSION['failures'] = ($_SESSION['failures'] ?? 0) + 1;

    if ($_SESSION['failures'] >= LOGIN_MAX_ATTEMPTS) {
      $_SESSION['blocked_until'] = time() + LOGIN_BLOCK_TIME;
    }
  }
}
