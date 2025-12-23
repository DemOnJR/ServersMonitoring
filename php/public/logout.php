<?php
declare(strict_types=1);

require_once __DIR__ . '/../App/Bootstrap.php';

use Auth\Auth;

Auth::logout();

header('Location: /login.php');
exit;
