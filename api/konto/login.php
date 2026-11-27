<?php
// LOGIN: E-Mail und Passwort prüfen, Session starten.
require __DIR__ . '/../../includes/config.php';

$data = read_json_post();
$email = strtolower(trim((string) ($data['email'] ?? '')));
$password = (string) ($data['password'] ?? '');

$user = find_user($pdo, $email);

// Auch ohne User einen Hash prüfen, damit die Antwortzeit nichts verrät.
$hash = $user['password_hash'] ?? '$2y$10$sF.RRinyNcEd.JIu7Q9UjuQjQC6WoWjg1zK82ZKtfGpc7kzGo6QwS';
if (!password_verify($password, $hash) || !$user) {
  // Gleiche Meldung für falsche E-Mail und falsches Passwort.
  json_response(['error' => 'E-Mail oder Passwort ist falsch.'], 401);
}

// Hash bei Bedarf auf neuen Standard aktualisieren.
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
  $newHash = password_hash($password, PASSWORD_DEFAULT);
  if (USE_PROCEDURES) {
    db_call($pdo, 'sp_passwort_aendern(?, ?)', [$user['user_id'], $newHash]);
  } else {
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([$newHash, $user['user_id']]);
  }
}

log_in($pdo, (int) $user['user_id'], $user['name'], $email);
json_response(['user' => [
  'name' => $user['name'],
  'email' => $email,
  'admin' => user_is_admin($pdo, (int) $user['user_id']),
]]);
