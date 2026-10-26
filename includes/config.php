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
  $_SESSION['user_id'] = $userId;
  $_SESSION['name'] = $name;
  $_SESSION['email'] = $email; // zum Vorausfüllen des Bestellformulars

  // Jeder User hat genau einen Warenkorb (falls er noch fehlt, jetzt anlegen).
  $pdo->prepare('INSERT IGNORE INTO carts (user_id) VALUES (?)')->execute([$userId]);

  $pdo->prepare('INSERT INTO login_log (user_id, ip_address) VALUES (?, ?)')
    ->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? null]);
}
