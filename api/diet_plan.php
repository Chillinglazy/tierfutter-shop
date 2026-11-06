<?php
// ERNÄHRUNGSPLAN: Krankheiten/Kategorien und daraus erstellte Pläne (ohne Login).
// GET api/diet_plan.php
//   → {conditions: [{condition_id, name}, ...]}
// GET api/diet_plan.php?condition_id=1&animal=Hund
//   → {plan: {condition_id, name, goal, animal, steps: [{label, text}], product_ids: [9, ...]}}
require __DIR__ . '/../includes/config.php';

const PLAN_ANIMALS = ['Hund', 'Katze']; // gleich wie chk_plan_steps_animal

// Ohne Auswahl: nur die Liste für das Dropdown
if (!isset($_GET['condition_id'])) {
  $rows = $pdo->query('SELECT condition_id, name FROM conditions ORDER BY sort_order, name')->fetchAll();
  foreach ($rows as &$row) {
    $row['condition_id'] = (int) $row['condition_id'];
  }
  unset($row);
  json_response(['conditions' => $rows]);
}

// EINGABEN PRÜFEN
$conditionId = filter_var($_GET['condition_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$animal = $_GET['animal'] ?? '';
if ($conditionId === false || !in_array($animal, PLAN_ANIMALS, true)) {
  json_response(['error' => 'Bitte Tier und Kategorie auswählen.'], 400);
}

$stmt = $pdo->prepare('SELECT condition_id, name, goal FROM conditions WHERE condition_id = ?');
$stmt->execute([$conditionId]);
$condition = $stmt->fetch();
if (!$condition) {
  json_response(['error' => 'Diese Kategorie gibt es nicht.'], 404);
}

// Schritte für beide Tiere (animal NULL) und nur für das gewählte Tier
$stmt = $pdo->prepare(
  'SELECT label, text FROM plan_steps
   WHERE condition_id = ? AND (animal IS NULL OR animal = ?)
   ORDER BY sort_order, step_id'
);
$stmt->execute([$conditionId, $animal]);
$steps = $stmt->fetchAll();

// Passende Produkte für dieses Tier (Details hat script.js schon von products.php)
$stmt = $pdo->prepare(
  'SELECT p.product_id FROM product_conditions pc
   JOIN products p ON p.product_id = pc.product_id
   WHERE pc.condition_id = ? AND p.animal = ?
   ORDER BY p.special = \'Diät\' DESC, p.product_id'
);
$stmt->execute([$conditionId, $animal]);
$productIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

json_response(['plan' => [
  'condition_id' => (int) $condition['condition_id'],
  'name' => $condition['name'],
  'goal' => $condition['goal'],
  'animal' => $animal,
  'steps' => $steps,
  'product_ids' => $productIds,
]]);
