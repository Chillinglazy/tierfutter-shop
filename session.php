<?php
// SESSION: Wer ist eingeloggt? Wird beim Laden der Seite abgefragt.
require 'config.php';

if (empty($_SESSION['user_id'])) {
  json_response(['user' => null]);
}
json_response(['user' => ['name' => $_SESSION['name']]]);
