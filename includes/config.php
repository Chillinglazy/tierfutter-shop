<?php
// Tierfutter-Shop – Datenbankverbindung, Session und Hilfsfunktionen.
// Wird von allen PHP-Endpunkten in api/ mit require eingebunden.

const MAX_QUANTITY = 99; // gleich wie CHECK in schema.sql

// Alle Antworten sind JSON. Fehlerdetails nie an den Browser senden.
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e) {
  error_log((string) $e);
  json_response(['error' => 'Serverfehler. Bitte später erneut versuchen.'], 500);
});

// DATENBANK-ZUGANGSDATEN: stehen in config.local.php (nicht in Git).
// Vorlage: config.local.example.php (gleicher Ordner) kopieren und in config.local.php umbenennen.
if (!is_file(__DIR__ . '/config.local.php')) {
  json_response(['error' => 'config.local.php fehlt. Siehe README, Abschnitt Installation.'], 500);
}
require __DIR__ . '/config.local.php';

$pdo = new PDO(
  'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
  DB_USER,
  DB_PASS,
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]
);

// STORED PROCEDURES: Mit DB_PROCEDURES = true in config.local.php ruft die Website
// alle Abläufe mit CALL auf (database/procedures.sql muss importiert sein, z. B. bei alwaysdata).
// Fehlt die Einstellung (InfinityFree erlaubt keine Prozeduren), gelten die SQL-Befehle in PHP.
define('USE_PROCEDURES', defined('DB_PROCEDURES') && DB_PROCEDURES === true);

// Prozedur aufrufen, z. B. db_call($pdo, 'sp_warenkorb_anzeigen(?)', [$userId]).
// Gibt alle Ergebnisse (Result Sets) als Liste zurück: [0] = erstes Ergebnis usw.
function db_call(PDO $pdo, string $call, array $params = []): array {
  $stmt = $pdo->prepare('CALL ' . $call);
  $stmt->execute($params);
  $sets = [];
  do {
    if ($stmt->columnCount() > 0) $sets[] = $stmt->fetchAll();
  } while ($stmt->nextRowset());
  $stmt->closeCursor();
  return $sets;
}

// OUT-Parameter einer Prozedur lesen, z. B. db_var($pdo, '@user_id').
function db_var(PDO $pdo, string $name): mixed {
  return $pdo->query('SELECT ' . $name)->fetchColumn();
}

// SESSION: Cookie nur für diese Seite, nicht per JavaScript lesbar.
session_set_cookie_params([
  'httponly' => true,
  'samesite' => 'Strict',
  'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();

// Antwort als JSON senden und Skript beenden.
function json_response(array $data, int $status = 200): never {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// Nur POST mit JSON-Inhalt annehmen (schützt zusätzlich vor fremden Formularen).
function read_json_post(): array {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Nur POST erlaubt.'], 405);
  }
  if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    json_response(['error' => 'JSON erwartet.'], 415);
  }
  $data = json_decode(file_get_contents('php://input'), true);
  return is_array($data) ? $data : [];
}

// User-ID immer aus der Session, nie aus dem Request.
function require_login(): int {
  if (empty($_SESSION['user_id'])) {
    json_response(['error' => 'Bitte zuerst einloggen.'], 401);
  }
  return (int) $_SESSION['user_id'];
}

// Login durchführen: neue Session-ID, Warenkorb sicherstellen, Login protokollieren.
function log_in(PDO $pdo, int $userId, string $name, string $email): void {
  session_regenerate_id(true);
  unset($_SESSION['csrf']); // neues Token für die neue Session
  $_SESSION['user_id'] = $userId;
  $_SESSION['name'] = $name;
  $_SESSION['email'] = $email; // zum Vorausfüllen des Bestellformulars

  if (USE_PROCEDURES) {
    db_call($pdo, 'sp_login_protokollieren(?, ?)', [$userId, $_SERVER['REMOTE_ADDR'] ?? null]);
    return;
  }

  // Jeder User hat genau einen Warenkorb (falls er noch fehlt, jetzt anlegen).
  $pdo->prepare('INSERT IGNORE INTO carts (user_id) VALUES (?)')->execute([$userId]);

  $pdo->prepare('INSERT INTO login_log (user_id, ip_address) VALUES (?, ?)')
    ->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? null]);
}

// USER zur E-Mail suchen (Login, Admin-Login, Registrierung). false = nicht gefunden.
function find_user(PDO $pdo, string $email): array|false {
  if (USE_PROCEDURES) {
    return (db_call($pdo, 'sp_benutzer_suchen(?)', [$email])[0] ?? [])[0] ?? false;
  }
  $stmt = $pdo->prepare('SELECT user_id, name, password_hash FROM users WHERE email = ?');
  $stmt->execute([$email]);
  return $stmt->fetch();
}

// ADMIN: Rolle bei jeder Anfrage frisch aus der Datenbank lesen (Änderungen gelten sofort).
// Fehlt die Spalte role noch (update_admin.sql nicht importiert), ist niemand Admin.
function user_is_admin(PDO $pdo, int $userId): bool {
  try {
    if (USE_PROCEDURES) {
      $rows = db_call($pdo, 'sp_benutzer_rolle(?)', [$userId])[0] ?? [];
      return ($rows[0]['role'] ?? '') === 'admin';
    }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() === 'admin';
  } catch (PDOException $e) {
    return false;
  }
}

// Zugang nur nach dem Admin-Login auf admin.html (api/admin/admin_login.php setzt admin_ok)
// und nur, solange das Konto in der Datenbank noch die Rolle admin hat.
function require_admin(PDO $pdo): int {
  if (empty($_SESSION['user_id']) || empty($_SESSION['admin_ok'])) {
    json_response(['error' => 'Admin-Login erforderlich.'], 401);
  }
  $userId = (int) $_SESSION['user_id'];
  if (!user_is_admin($pdo, $userId)) {
    unset($_SESSION['admin_ok']);
    json_response(['error' => 'Nur für Administratoren.'], 401);
  }
  return $userId;
}

// CSRF-SCHUTZ für die Admin-Funktionen: geheimes Token pro Session,
// muss bei jeder Änderung im Header X-CSRF-Token mitgeschickt werden.
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}

function check_csrf(): void {
  $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
    json_response(['error' => 'Sicherheits-Token ungültig. Bitte Seite neu laden.'], 403);
  }
}
