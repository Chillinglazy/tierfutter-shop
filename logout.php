<?php
// LOGOUT: Session-Daten, Session-Cookie und Session löschen.
require 'config.php';

read_json_post();

$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', [
  'expires' => time() - 3600,
  'path' => $params['path'],
  'httponly' => true,
  'samesite' => 'Strict',
]);
session_destroy();

json_response(['ok' => true]);
