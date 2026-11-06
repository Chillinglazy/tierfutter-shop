<?php
// ERNÄHRUNGSPLAN: Krankheiten/Kategorien und daraus erstellte Pläne (ohne Login).
// GET api/diet_plan.php
//   → {conditions: [{condition_id, name, icon, goal}, ...]}
// GET api/diet_plan.php?condition_ids=1,4&animal=Hund   (eine oder mehrere Krankheiten)
//   → {plan: {animal, conditions: [{condition_id, name, icon, goal, steps: [{label, text}], product_ids: [...]}],
//             notes: [{names: ['Übergewicht', 'Chronische Nierenerkrankung'], note}]}}
require __DIR__ . '/../includes/config.php';

const PLAN_ANIMALS = ['Hund', 'Katze']; // gleich wie chk_plan_steps_animal

// Ohne Auswahl: nur die Liste für die Kacheln
if (!isset($_GET['condition_ids'])) {
  $rows = $pdo->query('SELECT condition_id, name, icon, goal FROM conditions ORDER BY sort_order, name')->fetchAll();
  foreach ($rows as &$row) {
    $row['condition_id'] = (int) $row['condition_id'];
  }
  unset($row);
  json_response(['conditions' => $rows]);
}

// EINGABEN PRÜFEN: Liste von IDs, z. B. "1,4"
$ids = [];
foreach (explode(',', (string) $_GET['condition_ids']) as $part) {
  $id = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($id === false) {
    json_response(['error' => 'Bitte mindestens eine Krankheit auswählen.'], 400);
  }
  $ids[$id] = $id; // doppelte IDs nur einmal
}
$ids = array_values($ids);
$animal = $_GET['animal'] ?? '';
if (!$ids || count($ids) > 10 || !in_array($animal, PLAN_ANIMALS, true)) {
  json_response(['error' => 'Bitte Tier und mindestens eine Krankheit auswählen.'], 400);
}
$in = implode(',', array_fill(0, count($ids), '?'));

$stmt = $pdo->prepare("SELECT condition_id, name, icon, goal FROM conditions WHERE condition_id IN ($in) ORDER BY sort_order, name");
$stmt->execute($ids);
$conditions = [];
foreach ($stmt->fetchAll() as $row) {
  $id = (int) $row['condition_id'];
  $conditions[$id] = $row + ['steps' => [], 'product_ids' => []];
  $conditions[$id]['condition_id'] = $id;
}
if (count($conditions) !== count($ids)) {
  json_response(['error' => 'Diese Kategorie gibt es nicht.'], 404);
}

// Schritte für beide Tiere (animal NULL) und nur für das gewählte Tier
$stmt = $pdo->prepare(
  "SELECT condition_id, label, text FROM plan_steps
   WHERE condition_id IN ($in) AND (animal IS NULL OR animal = ?)
   ORDER BY sort_order, step_id"
);
$stmt->execute([...$ids, $animal]);
foreach ($stmt->fetchAll() as $row) {
  $conditions[(int) $row['condition_id']]['steps'][] = ['label' => $row['label'], 'text' => $row['text']];
}

// Passende Produkte pro Krankheit für dieses Tier (Details hat script.js schon von products.php)
$stmt = $pdo->prepare(
  "SELECT pc.condition_id, p.product_id FROM product_conditions pc
   JOIN products p ON p.product_id = pc.product_id
   WHERE pc.condition_id IN ($in) AND p.animal = ?
   ORDER BY p.special = 'Diät' DESC, p.product_id"
);
$stmt->execute([...$ids, $animal]);
foreach ($stmt->fetchAll() as $row) {
  $conditions[(int) $row['condition_id']]['product_ids'][] = (int) $row['product_id'];
}

// Hinweise für die gewählten Kombinationen
$stmt = $pdo->prepare(
  "SELECT a.name AS name_a, b.name AS name_b, n.note FROM combination_notes n
   JOIN conditions a ON a.condition_id = n.condition_a
   JOIN conditions b ON b.condition_id = n.condition_b
   WHERE n.condition_a IN ($in) AND n.condition_b IN ($in)
   ORDER BY a.sort_order, b.sort_order"
);
$stmt->execute([...$ids, ...$ids]);
$notes = array_map(fn($r) => ['names' => [$r['name_a'], $r['name_b']], 'note' => $r['note']], $stmt->fetchAll());

json_response(['plan' => [
  'animal' => $animal,
  'conditions' => array_values($conditions),
  'notes' => $notes,
]]);
