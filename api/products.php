<?php
// PRODUKTE: Alle Produkte aus der Datenbank für die Website (ohne Login).
// Antwort: {products: [{product_id, name, description, price, ...}, ...]}
require __DIR__ . '/../includes/config.php';

$rows = $pdo->query(
  'SELECT product_id, name, description, price, image_path, animal, food_type, special, taste,
          size, tag, color, tint, age_min, age_max, weight_min, weight_max
   FROM products
   ORDER BY product_id'
)->fetchAll();

// DECIMAL kommt als Text aus der Datenbank, für JavaScript in Zahlen umwandeln.
foreach ($rows as &$row) {
  $row['product_id'] = (int) $row['product_id'];
  foreach (['price', 'age_min', 'age_max', 'weight_min', 'weight_max'] as $key) {
    $row[$key] = (float) $row[$key];
  }
}

json_response(['products' => $rows]);
