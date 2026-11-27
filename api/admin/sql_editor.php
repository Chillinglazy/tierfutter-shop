<?php
// ADMIN: SQL-Editor für Stored Procedures (nur für Benutzer mit role = 'admin').
// GET                          → Liste der Prozeduren sp_... mit Parametern
// POST {sql} mit X-CSRF-Token  → genau EINEN Befehl der Form CALL sp_name(...) ausführen
//
// SICHERHEIT: Es wird nie der eingegebene Text als SQL ausgeführt. Der Server zerlegt
// den Befehl selbst: Der Prozedurname muss in der Datenbank existieren (information_schema),
// jedes Argument muss eine Zahl, ein Text in '…', NULL/TRUE/FALSE oder eine Variable @name
// sein. Werte gehen über Platzhalter (?), Variablen nur nach Prüfung mit einem Muster.
// Andere Befehle (SELECT, DELETE, DROP …) und mehrere Befehle werden abgelehnt.
//
// SONDERFÄLLE:
//   sp_passwort_aendern(id, 'neues Passwort') → der Server macht aus dem Klartext zuerst den
//     Hash (password_hash), sonst könnte sich der User nicht mehr einloggen.
//   sp_rolle_aendern(id, 'kunde')              → sich selbst die Admin-Rolle wegnehmen geht nicht.
require __DIR__ . '/../../includes/config.php';

$adminId = require_admin($pdo);

const MAX_RESULT_ROWS = 500; // mehr Zeilen pro Ergebnis werden abgeschnitten
const MAX_SQL_LENGTH = 5000;

// Spalten, deren Inhalt nie angezeigt wird (gleich wie HIDDEN_COLUMNS in admin_db.php).
const SECRET_COLUMNS = ['password_hash'];

// Alle Prozeduren sp_... der Datenbank mit ihren Parametern, Schlüssel = Name in Kleinbuchstaben.
function procedures(PDO $pdo): array {
  $procs = [];
  $stmt = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES
    WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE' AND ROUTINE_NAME LIKE 'sp\\_%'
    ORDER BY ROUTINE_NAME");
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $procs[strtolower($name)] = ['name' => $name, 'params' => []];
  }
  $stmt = $pdo->query("SELECT SPECIFIC_NAME, PARAMETER_MODE, PARAMETER_NAME, DTD_IDENTIFIER
    FROM information_schema.PARAMETERS
    WHERE SPECIFIC_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE' AND PARAMETER_MODE IS NOT NULL
    ORDER BY SPECIFIC_NAME, ORDINAL_POSITION");
  foreach ($stmt as $p) {
    $key = strtolower($p['SPECIFIC_NAME']);
    if (!isset($procs[$key])) continue;
    $procs[$key]['params'][] = [
      'mode' => $p['PARAMETER_MODE'],
      'name' => $p['PARAMETER_NAME'],
      // Nur den Typ zeigen, ohne CHARACTER SET / COLLATE
      'type' => strtolower(preg_replace('/\s+(CHARACTER SET|CHARSET|COLLATE)\b.*$/is', '', $p['DTD_IDENTIFIER'])),
    ];
  }
  return $procs;
}

// Fehler im Befehl: Meldung für das Ausgabefenster (Status 400).
function sql_error(string $text): never {
  json_response(['error' => $text], 400);
}

// BEFEHL ZERLEGEN: "CALL sp_name(1, 'Text', NULL, @id);" → Name und Liste der Argumente.
// Argument = ['value' => …] (über Platzhalter) oder ['var' => '@id'] (Variable).
function parse_call(string $sql): array {
  // Kommentarzeilen (-- …) weglassen, Leerraum und abschliessendes ; entfernen
  $lines = array_filter(preg_split('/\R/', $sql), fn($l) => !str_starts_with(ltrim($l), '--'));
  $sql = trim(implode("\n", $lines));
  $sql = rtrim($sql, " \t\r\n;");
  if ($sql === '') sql_error('Bitte einen Befehl eingeben, z. B. CALL sp_umsatz_pro_produkt();');

  if (!preg_match('/^CALL\s+`?([A-Za-z0-9_]+)`?\s*(\(|$)/i', $sql, $m)) {
    sql_error('Erlaubt ist nur CALL, z. B. CALL sp_warenkorb_anzeigen(1); Andere Befehle (SELECT, UPDATE, DELETE …) gehen in der Tabellenansicht.');
  }
  $name = $m[1];
  $pos = strlen($m[0]);
  $args = [];
  $len = strlen($sql);

  if ($m[2] === '(') {
    $expectArg = true; // nach "(" und "," muss ein Argument kommen
    while (true) {
      while ($pos < $len && ctype_space($sql[$pos])) $pos++;
      if ($pos >= $len) sql_error('Die schliessende Klammer ) fehlt.');
      $c = $sql[$pos];

      if ($c === ')' && ($expectArg === false || $args === [])) { $pos++; break; }
      if (!$expectArg) {
        if ($c !== ',') sql_error('Zwischen den Werten fehlt ein Komma (Stelle ' . ($pos + 1) . ').');
        $pos++;
        $expectArg = true;
        continue;
      }

      $rest = substr($sql, $pos);
      if ($c === "'" || $c === '"') {
        // Text: '' bzw. "" oder \ vor dem Zeichen bedeutet das Zeichen selbst
        $value = '';
        $pos++;
        while (true) {
          if ($pos >= $len) sql_error('Ein Text ist nicht geschlossen (' . $c . ' fehlt).');
          $ch = $sql[$pos];
          if ($ch === '\\' && $pos + 1 < $len) { $value .= $sql[$pos + 1]; $pos += 2; continue; }
          if ($ch === $c) {
            if ($pos + 1 < $len && $sql[$pos + 1] === $c) { $value .= $c; $pos += 2; continue; }
            $pos++;
            break;
          }
          $value .= $ch;
          $pos++;
        }
        $args[] = ['value' => $value];
      } elseif (preg_match('/^-?\d+(\.\d+)?(?![\w.])/', $rest, $n)) {
        $args[] = ['value' => isset($n[1]) ? $n[0] : (int) $n[0]];
        $pos += strlen($n[0]);
      } elseif (preg_match('/^(NULL|TRUE|FALSE)\b/i', $rest, $k)) {
        $args[] = ['value' => ['null' => null, 'true' => 1, 'false' => 0][strtolower($k[1])]];
        $pos += strlen($k[0]);
      } elseif (preg_match('/^@[A-Za-z_][A-Za-z0-9_]{0,63}\b/', $rest, $v)) {
        $args[] = ['var' => $v[0]];
        $pos += strlen($v[0]);
      } else {
        sql_error('Unbekannter Wert bei Stelle ' . ($pos + 1) . ': erlaubt sind Zahlen, Text in \'…\', NULL, TRUE, FALSE und Variablen wie @user_id.');
      }
      $expectArg = false;
    }
  }

  while ($pos < $len && ctype_space($sql[$pos])) $pos++;
  if ($pos < $len) {
    sql_error('Nach dem CALL-Befehl folgt noch Text. Bitte genau einen Befehl ausführen.');
  }
  return [$name, $args];
}

// GET: Prozeduren für die Liste im Editor
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  json_response([
    'procedures' => array_values(procedures($pdo)),
    'procedures_mode' => USE_PROCEDURES, // DB_PROCEDURES in config.local.php
    'csrf' => csrf_token(),
  ]);
}

// POST: einen CALL-Befehl ausführen
$data = read_json_post();
check_csrf();
$sql = $data['sql'] ?? '';
if (!is_string($sql) || strlen($sql) > MAX_SQL_LENGTH) sql_error('Befehl fehlt oder ist zu lang.');

[$name, $args] = parse_call($sql);

$procs = procedures($pdo);
$proc = $procs[strtolower($name)] ?? null;
if ($proc === null) {
  sql_error($procs === []
    ? 'In dieser Datenbank gibt es keine Prozeduren. Auf InfinityFree sind Stored Procedures nicht erlaubt; bei alwaysdata zuerst 2_procedures-online.sql importieren.'
    : "Die Prozedur $name gibt es nicht. Erlaubt sind nur Prozeduren sp_… aus der Liste.");
}

// ANZAHL UND ART DER ARGUMENTE prüfen
$params = $proc['params'];
if (count($args) !== count($params)) {
  $sig = implode(', ', array_map(fn($p) => ($p['mode'] === 'IN' ? '' : $p['mode'] . ' ') . $p['name'], $params));
  sql_error("{$proc['name']} braucht " . count($params) . ' Wert(e), angegeben: ' . count($args) . ". Aufbau: {$proc['name']}($sig)");
}
$placeholders = [];
$values = [];
$vars = [];
foreach ($params as $i => $p) {
  $arg = $args[$i];
  if ($p['mode'] !== 'IN' && !isset($arg['var'])) {
    sql_error("{$p['name']} ist ein {$p['mode']}-Parameter: bitte eine Variable angeben, z. B. @" . preg_replace('/^p_/', '', $p['name']) . '.');
  }
  if (isset($arg['var'])) {
    $placeholders[] = $arg['var']; // geprüft mit Muster @[A-Za-z_][A-Za-z0-9_]*
    if (!in_array($arg['var'], $vars, true)) $vars[] = $arg['var'];
  } else {
    $placeholders[] = '?';
    $values[] = $arg['value'];
  }
}
// PASSWORT ÄNDERN: Klartext prüfen (8–72 Zeichen wie bei der Registrierung) und hashen.
// Ein fertiger bcrypt-Hash ($2y$…) wird unverändert übernommen.
$message = null;
$shownValues = $values;
$procKey = strtolower($proc['name']);
if ($procKey === 'sp_passwort_aendern' && !isset($args[0]['var']) && !isset($args[1]['var'])) {
  $userId = $args[0]['value'];
  $password = (string) $args[1]['value'];
  $stmt = $pdo->prepare('SELECT name FROM users WHERE user_id = ?');
  $stmt->execute([$userId]);
  $userName = $stmt->fetchColumn();
  if ($userName === false) sql_error("Einen User mit der Nummer $userId gibt es nicht.");
  if (!preg_match('/^\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}$/', $password)) {
    if (strlen($password) < 8 || strlen($password) > 72) {
      sql_error('Das neue Passwort muss 8 bis 72 Zeichen lang sein.');
    }
    $values[1] = password_hash($password, PASSWORD_DEFAULT);
    $message = "Neues Passwort für $userName (User $userId) gespeichert, als Hash. Der Login klappt sofort mit dem neuen Passwort.";
  } else {
    $message = "Passwort-Hash für $userName (User $userId) gespeichert.";
  }
  $shownValues[1] = '••• (Passwort versteckt)';
}

// ROLLE ÄNDERN: sich selbst nicht aussperren (wie in der Datenbankverwaltung).
if ($procKey === 'sp_rolle_aendern' && !isset($args[0]['var']) && !isset($args[1]['var'])
  && (int) $args[0]['value'] === $adminId && strtolower(trim((string) $args[1]['value'])) !== 'admin') {
  sql_error('Du kannst dir die Admin-Rolle nicht selbst wegnehmen. Das muss ein anderer Admin machen.');
}

$call = 'CALL `' . $proc['name'] . '`(' . implode(', ', $placeholders) . ')';

// AUSFÜHREN: alle Ergebnisse (Result Sets) sammeln, danach die Variablen lesen.
// Datenbankfehler (z. B. SIGNAL in einer Prozedur) als lesbare Meldung zurückgeben.
$start = microtime(true);
$results = [];
try {
  $stmt = $pdo->prepare($call);
  $stmt->execute($values);
  do {
    $count = $stmt->columnCount();
    if ($count === 0) continue;
    $columns = [];
    for ($i = 0; $i < $count; $i++) $columns[] = $stmt->getColumnMeta($i)['name'] ?? null;
    // Das letzte "Ergebnis" eines CALL ist nur die Statusmeldung ohne Spalten → überspringen
    if (in_array(null, $columns, true)) continue;
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);
    $results[] = ['columns' => $columns, 'rows' => $rows];
  } while ($stmt->nextRowset());
  $stmt->closeCursor();

  if ($vars) {
    $row = $pdo->query('SELECT ' . implode(', ', $vars))->fetch(PDO::FETCH_NUM);
    $results[] = ['columns' => $vars, 'rows' => [$row], 'variables' => true];
  }
} catch (PDOException $e) {
  $info = $e->errorInfo;
  $text = $info[2] ?? $e->getMessage();
  json_response(['error' => 'Datenbankfehler' . (isset($info[1]) ? ' ' . $info[1] : '') . ': ' . $text, 'sql' => $call], 400);
}

// Geheime Spalten unkenntlich machen, sehr grosse Ergebnisse kürzen.
foreach ($results as &$r) {
  $secret = array_keys(array_filter($r['columns'], fn($c) => in_array(strtolower($c), SECRET_COLUMNS, true)));
  $r['total'] = count($r['rows']);
  $r['rows'] = array_slice($r['rows'], 0, MAX_RESULT_ROWS);
  foreach ($r['rows'] as &$row) {
    foreach ($secret as $i) if ($row[$i] !== null) $row[$i] = '••• (versteckt)';
  }
  unset($row);
}
unset($r);

json_response([
  'sql' => $call,
  'params' => $shownValues,
  'message' => $message,
  'results' => $results,
  'ms' => (int) round((microtime(true) - $start) * 1000),
]);
