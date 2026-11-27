<?php
// REGISTRIERUNG: User und Warenkorb anlegen, danach direkt einloggen.
require __DIR__ . '/../../includes/config.php';

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
if (find_user($pdo, $email)) {
  json_response(['error' => 'Diese E-Mail-Adresse ist bereits registriert.'], 409);
}

// USER UND WARENKORB ZUSAMMEN ANLEGEN (alles oder nichts)
try {
  $hash = password_hash($password, PASSWORD_DEFAULT);
  if (USE_PROCEDURES) {
    // Die Prozedur hat ihre eigene Transaktion
    db_call($pdo, 'sp_benutzer_registrieren(?, ?, ?, @user_id)', [$name, $email, $hash]);
    $userId = (int) db_var($pdo, '@user_id');
  } else {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
      ->execute([$name, $email, $hash]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO carts (user_id) VALUES (?)')->execute([$userId]);
    $pdo->commit();
  }
} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  // 23000: UNIQUE verletzt, falls zwei Registrierungen gleichzeitig kamen.
  if ($e->getCode() === '23000') {
    json_response(['error' => 'Diese E-Mail-Adresse ist bereits registriert.'], 409);
  }
  throw $e;
}

log_in($pdo, $userId, $name, $email);
json_response(['user' => ['name' => $name, 'email' => $email]], 201);
