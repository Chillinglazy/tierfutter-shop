<?php
// WARENKORB-API (nur für eingeloggte User, nur der eigene Warenkorb)
//   GET                                       Warenkorb laden
//   POST {action: 'add', product_id, quantity}  Produkt hinzufügen (quantity optional, Standard 1)
//   POST {action: 'change', product_id, delta}  Menge um delta ändern, bei 0 entfernen
//   POST {action: 'remove', product_id}         Produkt entfernen
//   POST {action: 'merge', items: {id: menge}}  Gast-Warenkorb nach dem Login übernehmen
// Antwort immer: {items: {product_id: quantity, ...}}
require 'config.php';

$userId = require_login();

$stmt = $pdo->prepare('SELECT cart_id FROM carts WHERE user_id = ?');
$stmt->execute([$userId]);
$cartId = (int) $stmt->fetchColumn();
if (!$cartId) {
  // User wurde gelöscht, Session ist aber noch offen.
  session_destroy();
  json_response(['error' => 'Bitte erneut einloggen.'], 401);
}

// Positionen des Warenkorbs als {product_id: quantity}
function cart_items(PDO $pdo, int $cartId): object {
  $stmt = $pdo->prepare('SELECT product_id, quantity FROM cart_items WHERE cart_id = ?');
  $stmt->execute([$cartId]);
  return (object) $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Ganze Zahl prüfen, sonst null
function to_int($value, int $min, int $max): ?int {
  $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
  return $n === false ? null : $n;
}

// Menge hinzufügen, höchstens MAX_QUANTITY
function add_item(PDO $pdo, int $cartId, int $productId, int $quantity): void {
  $pdo->prepare(
    'INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, LEAST(?, ' . MAX_QUANTITY . '))
     ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), ' . MAX_QUANTITY . ')'
  )->execute([$cartId, $productId, $quantity]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  json_response(['items' => cart_items($pdo, $cartId)]);
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
    $exists->execute([$productId]);
    if ($exists->fetchColumn()) add_item($pdo, $cartId, $productId, $quantity);
  }
  json_response(['items' => cart_items($pdo, $cartId)]);
}

$productId = to_int($data['product_id'] ?? null, 1, PHP_INT_MAX);
if ($productId === null) {
  json_response(['error' => 'Ungültige Produkt-ID.'], 422);
}

switch ($action) {
  case 'add':
    $quantity = to_int($data['quantity'] ?? 1, 1, MAX_QUANTITY);
    if ($quantity === null) json_response(['error' => 'Ungültige Menge.'], 422);
    $stmt = $pdo->prepare('SELECT 1 FROM products WHERE product_id = ?');
    $stmt->execute([$productId]);
    if (!$stmt->fetchColumn()) json_response(['error' => 'Produkt nicht gefunden.'], 404);
    add_item($pdo, $cartId, $productId, $quantity);
    break;

  case 'change':
    $delta = to_int($data['delta'] ?? null, -MAX_QUANTITY, MAX_QUANTITY);
    if ($delta === null) json_response(['error' => 'Ungültige Mengenänderung.'], 422);
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
    $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?')
      ->execute([$cartId, $productId]);
    break;

  default:
    json_response(['error' => 'Unbekannte Aktion.'], 400);
}

json_response(['items' => cart_items($pdo, $cartId)]);
