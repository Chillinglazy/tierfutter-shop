<?php
// BESTELLEN: für Gäste und eingeloggte User.
// POST {first_name, last_name, email, phone, street, zip, city, payment_method,
//       terms, privacy, newsletter, items: {product_id: menge}}
// Eingeloggt: Positionen kommen aus dem Warenkorb in der Datenbank, items wird ignoriert.
// Gast: Positionen aus items. Preise kommen immer aus der Datenbank, nie aus der Anfrage.
// Antwort: {order_id, total}
require __DIR__ . '/../includes/config.php';

const PAYMENT_METHODS = ['Rechnung', 'TWINT', 'Kreditkarte']; // gleich wie CHECK in schema.sql

$data = read_json_post();
$userId = empty($_SESSION['user_id']) ? null : (int) $_SESSION['user_id'];

// Text aus der Anfrage lesen und kürzen
function text_field(array $data, string $key): string {
  return trim((string) ($data[$key] ?? ''));
}

$order = [
  'first_name' => text_field($data, 'first_name'),
  'last_name' => text_field($data, 'last_name'),
  'email' => strtolower(text_field($data, 'email')),
  'phone' => text_field($data, 'phone'),
  'street' => text_field($data, 'street'),
  'zip' => text_field($data, 'zip'),
  'city' => text_field($data, 'city'),
  'payment_method' => text_field($data, 'payment_method'),
];

// EINGABEN PRÜFEN
$errors = [];
foreach (['first_name' => 'Vorname', 'last_name' => 'Nachname', 'city' => 'Ort'] as $key => $label) {
  if ($order[$key] === '' || mb_strlen($order[$key]) > 50) $errors[] = "$label fehlt oder ist zu lang.";
}
if ($order['street'] === '' || mb_strlen($order['street']) > 100) $errors[] = 'Strasse fehlt oder ist zu lang.';
if (!filter_var($order['email'], FILTER_VALIDATE_EMAIL) || strlen($order['email']) > 255) $errors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
// Telefon: Ziffern, Leerzeichen, + ( ) / -, mindestens 7 Ziffern
$digits = preg_replace('/\D/', '', $order['phone']);
if (!preg_match('/^\+?[0-9 ()\/-]+$/', $order['phone']) || strlen($digits) < 7 || strlen($order['phone']) > 30) $errors[] = 'Bitte eine gültige Telefonnummer eingeben.';
if (!preg_match('/^[1-9][0-9]{3}$/', $order['zip'])) $errors[] = 'Bitte eine gültige Schweizer PLZ (4 Ziffern) eingeben.';
if (!in_array($order['payment_method'], PAYMENT_METHODS, true)) $errors[] = 'Bitte eine Zahlungsart wählen.';
// Pflicht: AGB und Datenschutz (auch hier prüfen, nicht nur im Browser)
if (($data['terms'] ?? false) !== true || ($data['privacy'] ?? false) !== true) {
  $errors[] = 'Bitte AGB und Datenschutzerklärung akzeptieren.';
}
if ($errors) {
  json_response(['error' => implode(' ', $errors)], 422);
}

// Ab hier alles in einer Transaktion: Warenkorb lesen, Bestellung speichern,
// Warenkorb leeren, Newsletter. Geht etwas schief, wird nichts gespeichert.
$pdo->beginTransaction();

// POSITIONEN: {product_id: menge}
$items = [];
if ($userId) {
  // FOR UPDATE sperrt den Warenkorb, bis die Bestellung gespeichert ist.
  // So geht kein Produkt verloren, das gleichzeitig in einem anderen Tab hinzugefügt wird.
  $stmt = $pdo->prepare(
    'SELECT ci.product_id, ci.quantity FROM cart_items ci
     JOIN carts c ON c.cart_id = ci.cart_id WHERE c.user_id = ? FOR UPDATE'
  );
  $stmt->execute([$userId]);
  $items = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} elseif (is_array($data['items'] ?? null)) {
  foreach ($data['items'] as $id => $qty) {
    $productId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantity = filter_var($qty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => MAX_QUANTITY]]);
    if ($productId !== false && $quantity !== false) $items[$productId] = $quantity;
  }
}

// Aktuelle Namen und Preise aus der Datenbank holen
$products = [];
if ($items) {
  $placeholders = implode(',', array_fill(0, count($items), '?'));
  $stmt = $pdo->prepare("SELECT product_id, name, price FROM products WHERE product_id IN ($placeholders)");
  $stmt->execute(array_keys($items));
  foreach ($stmt->fetchAll() as $p) $products[(int) $p['product_id']] = $p;
}
if (!$products) {
  $pdo->rollBack();
  json_response(['error' => 'Dein Warenkorb ist leer.'], 422);
}

// Gesamtpreis in Rappen rechnen, damit keine Rundungsfehler entstehen
$totalCents = 0;
foreach ($products as $id => $p) {
  $totalCents += (int) round($p['price'] * 100) * $items[$id];
}
$total = $totalCents / 100;

// SPEICHERN: Bestellung, Positionen, Warenkorb leeren, Newsletter
try {
  $pdo->prepare(
    'INSERT INTO orders (user_id, first_name, last_name, email, phone, street, zip, city,
                         payment_method, total, terms_accepted_at, privacy_accepted_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
  )->execute([$userId, ...array_values($order), $total]);
  $orderId = (int) $pdo->lastInsertId();

  $insertItem = $pdo->prepare(
    'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity) VALUES (?, ?, ?, ?, ?)'
  );
  foreach ($products as $id => $p) {
    $insertItem->execute([$orderId, $id, $p['name'], $p['price'], $items[$id]]);
  }

  if ($userId) {
    $pdo->prepare('DELETE ci FROM cart_items ci JOIN carts c ON c.cart_id = ci.cart_id WHERE c.user_id = ?')
      ->execute([$userId]);
  }

  // Newsletter nur mit ausdrücklicher Zustimmung. Schon angemeldet: nichts tun.
  if (($data['newsletter'] ?? false) === true) {
    $pdo->prepare('INSERT IGNORE INTO newsletter (email) VALUES (?)')->execute([$order['email']]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

json_response(['order_id' => $orderId, 'total' => $total], 201);
