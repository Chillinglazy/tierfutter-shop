<?php
// ADMIN-LOGIN: Zugang zur Datenbankverwaltung (admin.html).
// Ein normaler Shop-Login reicht nicht: Wer die Datenbankverwaltung öffnet, muss sich
// dort mit E-Mail und Passwort eines Admin-Kontos anmelden. Erst dann setzt der Server
// $_SESSION['admin_ok'], das require_admin() bei jeder Admin-Anfrage prüft.
// POST {email, password}  → anmelden
// POST {action: 'logout'} → nur den Admin-Zugang beenden (Shop-Login bleibt)
require __DIR__ . '/../../includes/config.php';

$data = read_json_post();

if (($data['action'] ?? '') === 'logout') {
  unset($_SESSION['admin_ok']);
  json_response(['ok' => true]);
}

$email = strtolower(trim((string) ($data['email'] ?? '')));
$password = (string) ($data['password'] ?? '');

$user = find_user($pdo, $email);

// Auch ohne User einen Hash prüfen, damit die Antwortzeit nichts verrät.
$hash = $user['password_hash'] ?? '$2y$10$sF.RRinyNcEd.JIu7Q9UjuQjQC6WoWjg1zK82ZKtfGpc7kzGo6QwS';
if (!password_verify($password, $hash) || !$user) {
  json_response(['error' => 'E-Mail oder Passwort ist falsch.'], 401);
}
if (!user_is_admin($pdo, (int) $user['user_id'])) {
  json_response(['error' => 'Dieses Konto hat keine Admin-Rechte.'], 403);
}

log_in($pdo, (int) $user['user_id'], $user['name'], $email);
$_SESSION['admin_ok'] = true;
json_response(['user' => ['name' => $user['name'], 'email' => $email, 'admin' => true]]);
