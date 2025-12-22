<?php
require_once __DIR__ . '/../../App/Bootstrap.php';

$db->prepare("
  INSERT INTO settings(key,value)
  VALUES('alerts_enabled',?)
  ON CONFLICT(key) DO UPDATE SET value=excluded.value
")->execute([(int) $_POST['enabled']]);
