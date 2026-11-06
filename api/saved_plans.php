<?php
// MEINE PLÄNE: Ernährungspläne des eingeloggten Kunden speichern, anzeigen, löschen.
// Gespeichert wird nur die Auswahl (Krankheiten, Tier, Name), der Inhalt kommt aus diet_plan.php.
// GET  api/saved_plans.php                                                        → Liste
// POST {"action":"save","condition_ids":[1,4],"animal":"Hund","pet_name":"Bello"} → Liste
// POST {"action":"delete","plan_id":3}                                              → Liste
// Antwort: {plans: [{plan_id, animal, pet_name, created_at, conditions: [{condition_id, name, icon}]}, ...]}
require __DIR__ . '/../includes/config.php';

const SAVED_PLAN_ANIMALS = ['Hund', 'Katze']; // gleich wie chk_saved_plans_animal
const MAX_SAVED_PLANS = 20;

$userId = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  $data = read_json_post();
  $action = $data['action'] ?? '';

  if ($action === 'save') {
    // EINGABEN PRÜFEN
    $ids = [];
    foreach ((array) ($data['condition_ids'] ?? []) as $value) {
      $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      if ($id === false) {
        json_response(['error' => 'Ungültige Krankheit.'], 400);
      }
      $ids[$id] = $id;
    }
    $ids = array_values($ids);
    $animal = $data['animal'] ?? '';
    $petName = trim((string) ($data['pet_name'] ?? ''));
    if (!$ids || count($ids) > 10 || !in_array($animal, SAVED_PLAN_ANIMALS, true)) {
      json_response(['error' => 'Bitte Tier und mindestens eine Krankheit auswählen.'], 400);
    }
    if (mb_strlen($petName) > 40) {
      json_response(['error' => 'Der Name des Tiers darf höchstens 40 Zeichen haben.'], 400);
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM conditions WHERE condition_id IN ($in)");
    $stmt->execute($ids);
    if ((int) $stmt->fetchColumn() !== count($ids)) {
      json_response(['error' => 'Diese Kategorie gibt es nicht.'], 404);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM saved_plans WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() >= MAX_SAVED_PLANS) {
      json_response(['error' => 'Du kannst höchstens ' . MAX_SAVED_PLANS . ' Pläne speichern. Lösche zuerst einen alten Plan.'], 400);
    }

    // Plan und seine Krankheiten zusammen speichern
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO saved_plans (user_id, animal, pet_name) VALUES (?, ?, ?)')
      ->execute([$userId, $animal, $petName === '' ? null : $petName]);
    $planId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare('INSERT INTO saved_plan_conditions (plan_id, condition_id) VALUES (?, ?)');
    foreach ($ids as $id) {
      $insert->execute([$planId, $id]);
    }
    $pdo->commit();
  } elseif ($action === 'delete') {
    $planId = filter_var($data['plan_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($planId === false) {
      json_response(['error' => 'Ungültiger Plan.'], 400);
    }
    // Nur eigene Pläne löschen (user_id aus der Session); Krankheiten werden per CASCADE mitgelöscht
    $pdo->prepare('DELETE FROM saved_plans WHERE plan_id = ? AND user_id = ?')->execute([$planId, $userId]);
  } else {
    json_response(['error' => 'Unbekannte Aktion.'], 400);
  }
}

// LISTE: neueste zuerst, Krankheiten pro Plan gruppiert
$stmt = $pdo->prepare(
  'SELECT sp.plan_id, sp.animal, sp.pet_name, sp.created_at, c.condition_id, c.name, c.icon
   FROM saved_plans sp
   JOIN saved_plan_conditions spc ON spc.plan_id = sp.plan_id
   JOIN conditions c ON c.condition_id = spc.condition_id
   WHERE sp.user_id = ?
   ORDER BY sp.created_at DESC, sp.plan_id DESC, c.sort_order'
);
$stmt->execute([$userId]);
$plans = [];
foreach ($stmt->fetchAll() as $row) {
  $id = (int) $row['plan_id'];
  $plans[$id] ??= [
    'plan_id' => $id,
    'animal' => $row['animal'],
    'pet_name' => $row['pet_name'],
    'created_at' => $row['created_at'],
    'conditions' => [],
  ];
  $plans[$id]['conditions'][] = ['condition_id' => (int) $row['condition_id'], 'name' => $row['name'], 'icon' => $row['icon']];
}

json_response(['plans' => array_values($plans)]);
