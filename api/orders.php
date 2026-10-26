<?php
// MEINE BESTELLUNGEN: alle Bestellungen des eingeloggten Users, neueste zuerst.
// Antwort: {orders: [{order_id, ordered_at, total, payment_method, items: [{product_name, quantity, unit_price}]}]}
require __DIR__ . '/../includes/config.php';

$userId = require_login();

$stmt = $pdo->prepare(
  'SELECT o.order_id, o.ordered_at, o.total, o.payment_method,
          oi.product_name, oi.quantity, oi.unit_price
   FROM orders o
   JOIN order_items oi ON oi.order_id = o.order_id
   WHERE o.user_id = ?
   ORDER BY o.ordered_at DESC, o.order_id DESC, oi.product_name'
);
$stmt->execute([$userId]);

// Zeilen nach Bestellung gruppieren
$orders = [];
foreach ($stmt->fetchAll() as $row) {
  $id = (int) $row['order_id'];
  $orders[$id] ??= [
    'order_id' => $id,
    'ordered_at' => $row['ordered_at'],
    'total' => (float) $row['total'],
    'payment_method' => $row['payment_method'],
    'items' => [],
  ];
  $orders[$id]['items'][] = [
    'product_name' => $row['product_name'],
    'quantity' => (int) $row['quantity'],
    'unit_price' => (float) $row['unit_price'],
  ];
}

json_response(['orders' => array_values($orders)]);
