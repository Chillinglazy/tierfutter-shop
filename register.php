<?php
// REGISTRIERUNG: User und Warenkorb anlegen, danach direkt einloggen.
require 'config.php';

$data = read_json_post();
$name = trim((string) ($data['name'] ?? ''));
$email = strtolower(trim((string) ($data['email'] ?? '')));
$password = (string) ($data['password'] ?? '');

// EINGABEN PRÜFEN
if ($name === '' || mb_strlen($name) > 100) {
  json_response(['error' => 'Bitte einen Namen mit höchstens 100 Zeichen eingeben.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
  json_response(['error' => 'Bitte eine gültige E-Mail-Adresse eingeben.'], 422);
}
// password_hash (bcrypt) berücksichtigt nur die ersten 72 Bytes.
if (strlen($password) < 8 || strlen($password) > 72) {
  json_response(['error' => 'Das Passwort muss 8 bis 72 Zeichen lang sein.'], 422);
}

// E-MAIL AUF DUPLIKAT PRÜFEN
$stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
  json_response(['error' => 'Diese E-Mail-Adresse ist bereits registriert.'], 409);
}

// USER UND WARENKORB ZUSAMMEN ANLEGEN (alles oder nichts)
$pdo->beginTransaction();
try {
  $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
  $userId = (int) $pdo->lastInsertId();
  $pdo->prepare('INSERT INTO carts (user_id) VALUES (?)')->execute([$userId]);
  $pdo->commit();
} catch (PDOException $e) {
  $pdo->rollBack();
  // 23000: UNIQUE verletzt, falls zwei Registrierungen gleichzeitig kamen.
  if ($e->getCode() === '23000') {
    json_response(['error' => 'Diese E-Mail-Adresse ist bereits registriert.'], 409);
  }
  throw $e;
}

log_in($pdo, $userId, $name);
json_response(['user' => ['name' => $name]], 201);
