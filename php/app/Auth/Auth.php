<?php
declare(strict_types=1);

namespace Auth;

/**
 * Handles authentication state and basic brute-force protection.
 *
 * Uses session-based authentication with temporary blocking
 * after repeated failed login attempts.
 */
class Auth
{
  /**
   * Checks whether the current session is authenticated.
   *
   * @return bool True if the user is authenticated.
   */
  public static function check(): bool
  {
    return ($_SESSION['auth'] ?? false) === true;
  }

  /**
   * Attempts to authenticate the user using a password.
   *
   * Applies rate-limiting by blocking authentication attempts
   * after a configured number of failures.
   *
   * @param string $password Plain-text password provided by the user.
   *
   * @return bool True on successful authentication, false otherwise.
   */
  public static function login(string $password): bool
  {
    if (self::isBlocked()) {
      return false;
    }

    if (!password_verify($password, APP_PASSWORD_HASH)) {
      self::registerFailure();
      return false;
    }

    // Regenerate session id to prevent session fixation after successful login.
    session_regenerate_id(true);

    $_SESSION['auth'] = true;
    $_SESSION['failures'] = 0;
    unset($_SESSION['blocked_until']);

    return true;
  }

  /**
   * Logs out the current user and destroys the session.
   *
   * @return void
   */
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

  /**
   * Determines whether authentication attempts are temporarily blocked.
   *
   * @return bool True if login is currently blocked.
   */
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

  /**
   * Registers a failed login attempt and applies blocking
   * when the configured threshold is reached.
   *
   * @return void
   */
  private static function registerFailure(): void
  {
    $_SESSION['failures'] = ($_SESSION['failures'] ?? 0) + 1;

    if ($_SESSION['failures'] >= LOGIN_MAX_ATTEMPTS) {
      $_SESSION['blocked_until'] = time() + LOGIN_BLOCK_TIME;
    }
  }
}
