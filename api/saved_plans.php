<?php
// MEINE PLÄNE: Ernährungspläne des eingeloggten Kunden speichern, anzeigen, löschen.
// Gespeichert wird nur die Auswahl (Kategorie, Tier, Name), der Inhalt kommt aus diet_plan.php.
// GET  api/saved_plans.php                                                → Liste
// POST {"action":"save","condition_id":1,"animal":"Hund","pet_name":"Bello"} → Liste
// POST {"action":"delete","plan_id":3}                                      → Liste
// Antwort: {plans: [{plan_id, condition_id, name, icon, animal, pet_name, created_at}, ...]}
require __DIR__ . '/../includes/config.php';

const SAVED_PLAN_ANIMALS = ['Hund', 'Katze']; // gleich wie chk_saved_plans_animal
const MAX_SAVED_PLANS = 20;

$userId = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  $data = read_json_post();
  $action = $data['action'] ?? '';

  if ($action === 'save') {
    // EINGABEN PRÜFEN
    $conditionId = filter_var($data['condition_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $animal = $data['animal'] ?? '';
    $petName = trim((string) ($data['pet_name'] ?? ''));
    if ($conditionId === false || !in_array($animal, SAVED_PLAN_ANIMALS, true)) {
      json_response(['error' => 'Bitte Tier und Kategorie auswählen.'], 400);
    }
    if (mb_strlen($petName) > 40) {
      json_response(['error' => 'Der Name des Tiers darf höchstens 40 Zeichen haben.'], 400);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM conditions WHERE condition_id = ?');
    $stmt->execute([$conditionId]);
    if (!$stmt->fetchColumn()) {
      json_response(['error' => 'Diese Kategorie gibt es nicht.'], 404);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM saved_plans WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() >= MAX_SAVED_PLANS) {
      json_response(['error' => 'Du kannst höchstens ' . MAX_SAVED_PLANS . ' Pläne speichern. Lösche zuerst einen alten Plan.'], 400);
    }
    $pdo->prepare('INSERT INTO saved_plans (user_id, condition_id, animal, pet_name) VALUES (?, ?, ?, ?)')
      ->execute([$userId, $conditionId, $animal, $petName === '' ? null : $petName]);
  } elseif ($action === 'delete') {
    $planId = filter_var($data['plan_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($planId === false) {
      json_response(['error' => 'Ungültiger Plan.'], 400);
    }
    // Nur eigene Pläne löschen (user_id aus der Session)
    $pdo->prepare('DELETE FROM saved_plans WHERE plan_id = ? AND user_id = ?')->execute([$planId, $userId]);
  } else {
    json_response(['error' => 'Unbekannte Aktion.'], 400);
  }
}

// LISTE: neueste zuerst
$stmt = $pdo->prepare(
  'SELECT sp.plan_id, sp.condition_id, c.name, c.icon, sp.animal, sp.pet_name, sp.created_at
   FROM saved_plans sp
   JOIN conditions c ON c.condition_id = sp.condition_id
   WHERE sp.user_id = ?
   ORDER BY sp.created_at DESC, sp.plan_id DESC'
);
$stmt->execute([$userId]);
$plans = $stmt->fetchAll();
foreach ($plans as &$plan) {
  $plan['plan_id'] = (int) $plan['plan_id'];
  $plan['condition_id'] = (int) $plan['condition_id'];
}
unset($plan);

json_response(['plans' => $plans]);
