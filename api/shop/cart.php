<?php
// WARENKORB-API (nur für eingeloggte User, nur der eigene Warenkorb)
//   GET                                       Warenkorb laden
//   POST {action: 'add', product_id, quantity}  Produkt hinzufügen (quantity optional, Standard 1)
//   POST {action: 'change', product_id, delta}  Menge um delta ändern, bei 0 entfernen
//   POST {action: 'remove', product_id}         Produkt entfernen
//   POST {action: 'merge', items: {id: menge}}  Gast-Warenkorb nach dem Login übernehmen
// Antwort immer: {items: {product_id: quantity, ...}}
require __DIR__ . '/../../includes/config.php';

$userId = require_login();

if (USE_PROCEDURES) {
  $cartId = (int) ((db_call($pdo, 'sp_warenkorb_id(?)', [$userId])[0] ?? [])[0]['cart_id'] ?? 0);
} else {
  $stmt = $pdo->prepare('SELECT cart_id FROM carts WHERE user_id = ?');
  $stmt->execute([$userId]);
  $cartId = (int) $stmt->fetchColumn();
}
if (!$cartId) {
  // User wurde gelöscht, Session ist aber noch offen.
  session_destroy();
  json_response(['error' => 'Bitte erneut einloggen.'], 401);
}

// Positionen des Warenkorbs als {product_id: quantity}
function cart_items(PDO $pdo, int $cartId, int $userId): object {
  if (USE_PROCEDURES) {
    $items = [];
    foreach (db_call($pdo, 'sp_warenkorb_anzeigen(?)', [$userId])[0] ?? [] as $row) {
      $items[$row['product_id']] = (int) $row['quantity'];
    }
    return (object) $items;
  }
  $stmt = $pdo->prepare('SELECT product_id, quantity FROM cart_items WHERE cart_id = ?');
  $stmt->execute([$cartId]);
  return (object) $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Ganze Zahl prüfen, sonst null
function to_int($value, int $min, int $max): ?int {
  $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
  return $n === false ? null : $n;
}

// Menge hinzufügen, höchstens MAX_QUANTITY.
// false = Produkt gibt es nicht (nur mit Prozeduren, dort meldet der Fremdschlüssel den Fehler).
function add_item(PDO $pdo, int $cartId, int $userId, int $productId, int $quantity): bool {
  if (USE_PROCEDURES) {
    try {
      db_call($pdo, 'sp_warenkorb_hinzufuegen(?, ?, ?)', [$userId, $productId, $quantity]);
      return true;
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') return false; // Fremdschlüssel: Produkt fehlt
      throw $e;
    }
  }
  $pdo->prepare(
    'INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, LEAST(?, ' . MAX_QUANTITY . '))
     ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), ' . MAX_QUANTITY . ')'
  )->execute([$cartId, $productId, $quantity]);
  return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  json_response(['items' => cart_items($pdo, $cartId, $userId)]);
}

$data = read_json_post();
$action = $data['action'] ?? '';

if ($action === 'merge') {
  $items = is_array($data['items'] ?? null) ? $data['items'] : [];
  $exists = $pdo->prepare('SELECT 1 FROM products WHERE product_id = ?');
  foreach ($items as $id => $qty) {
    $productId = to_int($id, 1, PHP_INT_MAX);
    $quantity = to_int($qty, 1, PHP_INT_MAX);
    if ($productId === null || $quantity === null) continue;
    $quantity = min($quantity, MAX_QUANTITY);
    if (USE_PROCEDURES) {
      add_item($pdo, $cartId, $userId, $productId, $quantity); // fehlende Produkte werden übersprungen
      continue;
    }
    $exists->execute([$productId]);
    if ($exists->fetchColumn()) add_item($pdo, $cartId, $userId, $productId, $quantity);
  }
  json_response(['items' => cart_items($pdo, $cartId, $userId)]);
}

$productId = to_int($data['product_id'] ?? null, 1, PHP_INT_MAX);
if ($productId === null) {
  json_response(['error' => 'Ungültige Produkt-ID.'], 422);
}

switch ($action) {
  case 'add':
    $quantity = to_int($data['quantity'] ?? 1, 1, MAX_QUANTITY);
    if ($quantity === null) json_response(['error' => 'Ungültige Menge.'], 422);
    if (!USE_PROCEDURES) {
      $stmt = $pdo->prepare('SELECT 1 FROM products WHERE product_id = ?');
      $stmt->execute([$productId]);
      if (!$stmt->fetchColumn()) json_response(['error' => 'Produkt nicht gefunden.'], 404);
    }
    if (!add_item($pdo, $cartId, $userId, $productId, $quantity)) {
      json_response(['error' => 'Produkt nicht gefunden.'], 404);
    }
    break;

  case 'change':
    $delta = to_int($data['delta'] ?? null, -MAX_QUANTITY, MAX_QUANTITY);
    if ($delta === null) json_response(['error' => 'Ungültige Mengenänderung.'], 422);
    if (USE_PROCEDURES) {
      db_call($pdo, 'sp_warenkorb_menge_aendern(?, ?, ?)', [$userId, $productId, $delta]);
      break;
    }
    // Zeile sperren, damit gleichzeitige Klicks sich nicht überschreiben.
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT quantity FROM cart_items WHERE cart_id = ? AND product_id = ? FOR UPDATE');
    $stmt->execute([$cartId, $productId]);
    $current = $stmt->fetchColumn();
    if ($current !== false) {
      $new = min((int) $current + $delta, MAX_QUANTITY);
      if ($new <= 0) {
        $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?')
          ->execute([$cartId, $productId]);
      } else {
        $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND product_id = ?')
          ->execute([$new, $cartId, $productId]);
      }
    }
    $pdo->commit();
    break;

  case 'remove':
    if (USE_PROCEDURES) {
      db_call($pdo, 'sp_warenkorb_entfernen(?, ?)', [$userId, $productId]);
      break;
    }
    $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?')
      ->execute([$cartId, $productId]);
    break;

  default:
    json_response(['error' => 'Unbekannte Aktion.'], 400);
}

json_response(['items' => cart_items($pdo, $cartId, $userId)]);
