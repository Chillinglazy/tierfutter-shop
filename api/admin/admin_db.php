<?php
// ADMIN: Datenbank über die Website verwalten (nur für Benutzer mit role = 'admin').
// GET  ?action=tables                      → alle Tabellen mit Anzahl Zeilen, CSRF-Token, Datentypen
// GET  ?action=table&name=products&page=1  → Spalten, Fremdschlüssel und Zeilen einer Tabelle
// POST {action, ...} mit Header X-CSRF-Token:
//   insert, update, delete               → Zeilen ändern (update/delete über den Primärschlüssel)
//   create_table, drop_table             → Tabellen anlegen / löschen
//   add_column, drop_column              → Spalten ergänzen / entfernen
//
// SICHERHEIT: Tabellen- und Spaltennamen kommen nie direkt aus dem Request ins SQL.
// Bestehende Namen werden mit information_schema abgeglichen und von dort übernommen,
// neue Namen müssen dem Muster a-z, 0-9, _ entsprechen. Alle Werte gehen über Platzhalter (?).
require __DIR__ . '/../../includes/config.php';

$adminId = require_admin($pdo);

const PAGE_SIZE = 50;
const MAX_NEW_COLUMNS = 20;

// TABELLEN DES SHOPS mit ihren Spalten: Diese Tabellen und Spalten dürfen nicht gelöscht
// werden, sonst funktioniert der Shop nicht mehr. Zeilen ändern und neue Spalten ergänzen geht.
const CORE_TABLES = [
  'users' => ['user_id', 'name', 'email', 'password_hash', 'role', 'registered_at'],
  'login_log' => ['log_id', 'user_id', 'login_at', 'ip_address'],
  'products' => ['product_id', 'name', 'description', 'price', 'image_path', 'animal', 'food_type', 'special', 'taste', 'size', 'tag', 'color', 'tint', 'age_min', 'age_max', 'weight_min', 'weight_max'],
  'carts' => ['cart_id', 'user_id', 'created_at'],
  'cart_items' => ['cart_id', 'product_id', 'quantity'],
  'orders' => ['order_id', 'user_id', 'ordered_at', 'first_name', 'last_name', 'email', 'phone', 'street', 'zip', 'city', 'payment_method', 'total', 'terms_accepted_at', 'privacy_accepted_at'],
  'order_items' => ['order_id', 'product_id', 'product_name', 'unit_price', 'quantity'],
  'newsletter' => ['newsletter_id', 'email', 'subscribed_at'],
  'conditions' => ['condition_id', 'name', 'icon', 'goal', 'sort_order'],
  'plan_steps' => ['step_id', 'condition_id', 'animal', 'label', 'text', 'sort_order'],
  'product_conditions' => ['product_id', 'condition_id'],
  'combination_notes' => ['condition_a', 'condition_b', 'note'],
  'saved_plans' => ['plan_id', 'user_id', 'animal', 'pet_name', 'created_at'],
  'saved_plan_conditions' => ['plan_id', 'condition_id'],
];

// Spalten, die nie angezeigt oder geändert werden (Passwort-Hashes).
const HIDDEN_COLUMNS = ['users' => ['password_hash']];

// ERLAUBTE DATENTYPEN für neue Spalten: Auswahl im Formular → SQL-Typ.
const COLUMN_TYPES = [
  'int' => ['label' => 'Ganzzahl', 'sql' => 'INT'],
  'decimal' => ['label' => 'Dezimalzahl (z. B. Preis)', 'sql' => 'DECIMAL(10,2)'],
  'varchar' => ['label' => 'Text (bis 255 Zeichen)', 'sql' => 'VARCHAR(255)'],
  'text' => ['label' => 'Langer Text', 'sql' => 'TEXT'],
  'date' => ['label' => 'Datum', 'sql' => 'DATE'],
  'datetime' => ['label' => 'Datum und Uhrzeit', 'sql' => 'DATETIME'],
  'bool' => ['label' => 'Ja/Nein (0 oder 1)', 'sql' => 'TINYINT(1)'],
];

// HILFSFUNKTIONEN

// Namen für SQL in Backticks setzen (nur für Namen aus information_schema oder geprüfte neue Namen).
function q(string $name): string {
  return '`' . str_replace('`', '``', $name) . '`';
}

// Bestehende Tabelle suchen. Zurück kommt der Name aus der Datenbank, nicht aus dem Request.
function existing_table(PDO $pdo, mixed $name): string {
  $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = ?");
  $stmt->execute([is_string($name) ? $name : '']);
  $table = $stmt->fetchColumn();
  if ($table === false) {
    json_response(['error' => 'Tabelle nicht gefunden.'], 404);
  }
  return $table;
}

// Alle Spalten einer Tabelle (ohne versteckte), Schlüssel = Spaltenname.
function table_columns(PDO $pdo, string $table): array {
  $stmt = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
  $stmt->execute([$table]);
  $hidden = HIDDEN_COLUMNS[$table] ?? [];
  $columns = [];
  foreach ($stmt as $c) {
    if (in_array($c['COLUMN_NAME'], $hidden, true)) continue;
    $columns[$c['COLUMN_NAME']] = [
      'name' => $c['COLUMN_NAME'],
      'type' => $c['COLUMN_TYPE'],
      'nullable' => $c['IS_NULLABLE'] === 'YES',
      // MariaDB liefert NULL als Text 'NULL', Texte in Anführungszeichen
      'has_default' => $c['COLUMN_DEFAULT'] !== null && $c['COLUMN_DEFAULT'] !== 'NULL',
      'default' => preg_replace("/^'(.*)'$/s", '$1', (string) $c['COLUMN_DEFAULT']),
      'primary' => $c['COLUMN_KEY'] === 'PRI',
      'auto_increment' => str_contains($c['EXTRA'], 'auto_increment'),
    ];
  }
  return $columns;
}

function primary_columns(array $columns): array {
  return array_keys(array_filter($columns, fn($c) => $c['primary']));
}

// Neuer Tabellen- oder Spaltenname: nur Kleinbuchstaben, Ziffern und _ (sicher fürs SQL).
function new_name(mixed $name, string $what): string {
  $name = strtolower(trim(is_string($name) ? $name : ''));
  if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
    json_response(['error' => "$what: nur Kleinbuchstaben a–z, Ziffern und _ verwenden, mit einem Buchstaben beginnen (höchstens 64 Zeichen)."], 422);
  }
  return $name;
}

// Spaltendefinition für CREATE/ALTER TABLE aus geprüftem Namen und erlaubtem Typ.
function column_sql(mixed $col): string {
  if (!is_array($col)) json_response(['error' => 'Ungültige Spalte.'], 422);
  $name = new_name($col['name'] ?? '', 'Spaltenname');
  $type = COLUMN_TYPES[$col['type'] ?? ''] ?? null;
  if (!$type) json_response(['error' => "Spalte $name: Datentyp auswählen."], 422);
  return q($name) . ' ' . $type['sql'] . (empty($col['nullable']) ? ' NOT NULL' : ' NULL');
}

// Werte aus dem Formular: nur bekannte Spalten, nur Text/Zahl/null.
// Leeres Feld = NULL (wenn erlaubt). Beim Einfügen gilt sonst der Standardwert der Spalte.
function row_values(array $columns, mixed $values, bool $insert): array {
  if (!is_array($values)) json_response(['error' => 'Keine Werte erhalten.'], 422);
  $result = [];
  foreach ($values as $name => $value) {
    $col = $columns[$name] ?? null;
    if (!$col) json_response(['error' => "Unbekannte Spalte: $name"], 422);
    if ($value !== null && !is_scalar($value)) json_response(['error' => "Ungültiger Wert in $name."], 422);
    if (is_bool($value)) $value = (int) $value;
    if ($value === '' || $value === null) {
      if ($insert && ($col['auto_increment'] || $col['has_default'])) continue;
      if ($col['nullable']) $value = null;
      elseif ($value === null) json_response(['error' => "Spalte $name darf nicht leer sein."], 422);
    }
    $result[$col['name']] = $value === null ? null : (string) $value;
  }
  return $result;
}

// WHERE-Teil über den Primärschlüssel: alle Schlüsselspalten müssen angegeben sein.
function key_where(array $columns, mixed $key): array {
  $pk = primary_columns($columns);
  if (!$pk) json_response(['error' => 'Diese Tabelle hat keinen Primärschlüssel. Zeilen können hier nicht geändert werden.'], 422);
  if (!is_array($key)) json_response(['error' => 'Schlüssel fehlt.'], 422);
  $parts = [];
  $params = [];
  foreach ($pk as $name) {
    if (!array_key_exists($name, $key) || !is_scalar($key[$name])) {
      json_response(['error' => "Schlüsselwert $name fehlt."], 422);
    }
    $parts[] = q($name) . ' = ?';
    $params[] = (string) $key[$name];
  }
  return [implode(' AND ', $parts), $params];
}

// Fehler der Datenbank verständlich an den Admin melden (z. B. Fremdschlüssel, doppelter Wert).
function run(callable $fn): mixed {
  try {
    return $fn();
  } catch (PDOException $e) {
    $msg = $e->errorInfo[2] ?? $e->getMessage();
    $hint = match ($e->errorInfo[1] ?? 0) {
      1451 => 'Andere Zeilen verweisen noch auf diesen Datensatz (Fremdschlüssel). ',
      1452 => 'Der angegebene Fremdschlüssel-Wert existiert nicht. ',
      1062 => 'Dieser Wert ist bereits vorhanden (muss eindeutig sein). ',
      1050 => 'Diese Tabelle gibt es schon. ',
      1060 => 'Diese Spalte gibt es schon. ',
      default => '',
    };
    json_response(['error' => $hint . 'Datenbank meldet: ' . $msg], 422);
  }
}

// LESEN (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $action = $_GET['action'] ?? 'tables';

  if ($action === 'tables') {
    $names = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $tables = [];
    foreach ($names as $name) {
      $tables[] = [
        'name' => $name,
        'rows' => (int) $pdo->query('SELECT COUNT(*) FROM ' . q($name))->fetchColumn(),
        'core' => isset(CORE_TABLES[$name]),
      ];
    }
    $types = array_map(fn($t) => $t['label'], COLUMN_TYPES);
    json_response(['tables' => $tables, 'types' => $types, 'csrf' => csrf_token()]);
  }

  if ($action === 'table') {
    $table = existing_table($pdo, $_GET['name'] ?? '');
    $columns = table_columns($pdo, $table);
    $pk = primary_columns($columns);
    $total = (int) $pdo->query('SELECT COUNT(*) FROM ' . q($table))->fetchColumn();
    $pages = max(1, (int) ceil($total / PAGE_SIZE));
    $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

    $select = implode(', ', array_map('q', array_keys($columns)));
    $order = implode(', ', array_map('q', $pk ?: [array_key_first($columns)]));
    $stmt = $pdo->prepare("SELECT $select FROM " . q($table) . " ORDER BY $order LIMIT ? OFFSET ?");
    $stmt->bindValue(1, PAGE_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(2, ($page - 1) * PAGE_SIZE, PDO::PARAM_INT);
    $stmt->execute();

    // Fremdschlüssel: welche Spalte zeigt auf welche Tabelle?
    $fk = $pdo->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
      FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $fk->execute([$table]);
    $references = [];
    foreach ($fk as $r) {
      $references[$r['COLUMN_NAME']] = $r['REFERENCED_TABLE_NAME'] . '.' . $r['REFERENCED_COLUMN_NAME'];
    }

    json_response([
      'name' => $table,
      'core' => isset(CORE_TABLES[$table]),
      'core_columns' => CORE_TABLES[$table] ?? [],
      'columns' => array_values($columns),
      'primary_key' => $pk,
      'references' => (object) $references,
      'rows' => $stmt->fetchAll(),
      'total' => $total,
      'page' => $page,
      'pages' => $pages,
    ]);
  }

  json_response(['error' => 'Unbekannte Aktion.'], 400);
}

// ÄNDERN (POST mit JSON und CSRF-Token)
$data = read_json_post();
check_csrf();
$action = $data['action'] ?? '';

switch ($action) {
  case 'insert':
    $table = existing_table($pdo, $data['table'] ?? '');
    if ($table === 'users') {
      json_response(['error' => 'Neue Benutzer bitte im Shop über «Registrieren» anlegen (Passwort wird verschlüsselt gespeichert).'], 422);
    }
    $values = row_values(table_columns($pdo, $table), $data['values'] ?? null, true);
    $sql = $values
      ? 'INSERT INTO ' . q($table) . ' (' . implode(', ', array_map('q', array_keys($values))) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')'
      : 'INSERT INTO ' . q($table) . ' () VALUES ()';
    run(fn() => $pdo->prepare($sql)->execute(array_values($values)));
    json_response(['ok' => true, 'message' => 'Zeile hinzugefügt.'], 201);

  case 'update':
    $table = existing_table($pdo, $data['table'] ?? '');
    $columns = table_columns($pdo, $table);
    [$where, $keyParams] = key_where($columns, $data['key'] ?? null);
    $values = row_values($columns, $data['values'] ?? null, false);
    if (!$values) json_response(['error' => 'Keine Änderungen.'], 422);
    // Sich selbst die Admin-Rolle wegnehmen würde dich aussperren.
    if ($table === 'users' && (string) ($data['key']['user_id'] ?? '') === (string) $adminId
      && array_key_exists('role', $values) && $values['role'] !== 'admin') {
      json_response(['error' => 'Du kannst dir die Admin-Rolle nicht selbst wegnehmen.'], 422);
    }
    $set = implode(', ', array_map(fn($c) => q($c) . ' = ?', array_keys($values)));
    $stmt = $pdo->prepare('UPDATE ' . q($table) . " SET $set WHERE $where LIMIT 1");
    run(fn() => $stmt->execute([...array_values($values), ...$keyParams]));
    json_response(['ok' => true, 'message' => $stmt->rowCount() ? 'Zeile gespeichert.' : 'Keine Änderung (Werte waren schon gleich).']);

  case 'delete':
    $table = existing_table($pdo, $data['table'] ?? '');
    [$where, $keyParams] = key_where(table_columns($pdo, $table), $data['key'] ?? null);
    if ($table === 'users' && (string) ($data['key']['user_id'] ?? '') === (string) $adminId) {
      json_response(['error' => 'Du kannst dein eigenes Konto hier nicht löschen.'], 422);
    }
    $stmt = $pdo->prepare('DELETE FROM ' . q($table) . " WHERE $where LIMIT 1");
    run(fn() => $stmt->execute($keyParams));
    if (!$stmt->rowCount()) json_response(['error' => 'Zeile nicht gefunden.'], 404);
    json_response(['ok' => true, 'message' => 'Zeile gelöscht.']);

  case 'create_table':
    $name = new_name($data['name'] ?? '', 'Tabellenname');
    $cols = $data['columns'] ?? [];
    if (!is_array($cols) || count($cols) > MAX_NEW_COLUMNS) {
      json_response(['error' => 'Höchstens ' . MAX_NEW_COLUMNS . ' Spalten auf einmal.'], 422);
    }
    // Jede neue Tabelle bekommt automatisch einen Primärschlüssel id.
    $defs = ['`id` INT UNSIGNED NOT NULL AUTO_INCREMENT'];
    $seen = ['id' => true];
    foreach ($cols as $col) {
      $colName = new_name($col['name'] ?? '', 'Spaltenname');
      if (isset($seen[$colName])) json_response(['error' => "Spalte $colName kommt doppelt vor (id wird automatisch angelegt)."], 422);
      $seen[$colName] = true;
      $defs[] = column_sql($col);
    }
    $defs[] = 'PRIMARY KEY (`id`)';
    run(fn() => $pdo->exec('CREATE TABLE ' . q($name) . " (\n  " . implode(",\n  ", $defs) . "\n) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"));
    json_response(['ok' => true, 'name' => $name, 'message' => "Tabelle $name erstellt."], 201);

  case 'drop_table':
    $table = existing_table($pdo, $data['table'] ?? '');
    if (isset(CORE_TABLES[$table])) {
      json_response(['error' => "$table gehört zum Shop und kann nicht gelöscht werden."], 422);
    }
    // Zur Sicherheit muss der Tabellenname noch einmal eingetippt werden.
    if (($data['confirm'] ?? '') !== $table) {
      json_response(['error' => 'Zum Löschen den Tabellennamen genau eintippen.'], 422);
    }
    run(fn() => $pdo->exec('DROP TABLE ' . q($table)));
    json_response(['ok' => true, 'message' => "Tabelle $table gelöscht."]);

  case 'add_column':
    $table = existing_table($pdo, $data['table'] ?? '');
    $def = column_sql($data['column'] ?? null);
    run(fn() => $pdo->exec('ALTER TABLE ' . q($table) . ' ADD COLUMN ' . $def));
    json_response(['ok' => true, 'message' => 'Spalte hinzugefügt.']);

  case 'drop_column':
    $table = existing_table($pdo, $data['table'] ?? '');
    $columns = table_columns($pdo, $table);
    $col = is_string($data['column'] ?? null) ? ($columns[$data['column']] ?? null) : null;
    if (!$col) json_response(['error' => 'Spalte nicht gefunden.'], 404);
    if (in_array($col['name'], CORE_TABLES[$table] ?? [], true)) {
      json_response(['error' => "Die Spalte {$col['name']} wird vom Shop gebraucht und kann nicht gelöscht werden."], 422);
    }
    if ($col['primary']) json_response(['error' => 'Der Primärschlüssel kann nicht gelöscht werden.'], 422);
    if (count($columns) < 2) json_response(['error' => 'Die letzte Spalte kann nicht gelöscht werden.'], 422);
    run(fn() => $pdo->exec('ALTER TABLE ' . q($table) . ' DROP COLUMN ' . q($col['name'])));
    json_response(['ok' => true, 'message' => "Spalte {$col['name']} gelöscht."]);
}

json_response(['error' => 'Unbekannte Aktion.'], 400);
