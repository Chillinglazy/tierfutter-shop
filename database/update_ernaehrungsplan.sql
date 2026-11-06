-- Tierfutter-Shop – Update: Ernährungsplan
-- Für eine bestehende Datenbank: legt die Tabellen für den Ernährungsplan, die
-- Hinweise zu Kombinationen und die gespeicherten Pläne der Kunden (auch mit
-- mehreren Krankheiten) an und ergänzt die Diät-Produkte (IDs 9 bis 22).
-- Ältere Versionen dieses Updates werden automatisch nachgerüstet.
-- Benutzer, Warenkörbe, Bestellungen und bisherige Produkte bleiben erhalten.
-- Import in phpMyAdmin: Haus-Symbol → Importieren → update_ernaehrungsplan.sql auswählen → Importieren.
-- Zweimal importieren schadet nicht (vorhandene Zeilen werden übersprungen).
-- (Bei einer neuen Installation reicht schema.sql, dort ist alles schon enthalten.)

USE tierfutter_shop;

-- KRANKHEITEN / KATEGORIEN für den Ernährungsplan
CREATE TABLE IF NOT EXISTS conditions (
  condition_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(60)  NOT NULL, -- Anzeige in der Auswahl, z. B. Übergewicht
  icon         VARCHAR(8)   NOT NULL DEFAULT '', -- Symbol auf der Auswahl-Kachel
  goal         VARCHAR(255) NOT NULL, -- Ziel des Plans
  sort_order   INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (condition_id),
  UNIQUE KEY uq_conditions_name (name)
) ENGINE = InnoDB;

-- Erste Version dieses Updates hatte noch kein Symbol: Spalte nachrüsten.
ALTER TABLE conditions ADD COLUMN IF NOT EXISTS icon VARCHAR(8) NOT NULL DEFAULT '' AFTER name;

-- PLAN-SCHRITTE: conditions 1 : N plan_steps
-- animal NULL = gilt für Hund und Katze, sonst nur für dieses Tier.
CREATE TABLE IF NOT EXISTS plan_steps (
  step_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condition_id INT UNSIGNED NOT NULL,
  animal       VARCHAR(30)  NULL,
  label        VARCHAR(30)  NOT NULL, -- z. B. Futter, Tagesablauf, Kontrolle, Wichtig
  text         VARCHAR(500) NOT NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (step_id),
  KEY idx_plan_steps_condition (condition_id),
  CONSTRAINT chk_plan_steps_animal CHECK (animal IS NULL OR animal IN ('Hund', 'Katze')),
  CONSTRAINT fk_plan_steps_condition FOREIGN KEY (condition_id)
    REFERENCES conditions (condition_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- PASSENDE PRODUKTE: products N : M conditions
CREATE TABLE IF NOT EXISTS product_conditions (
  product_id   INT UNSIGNED NOT NULL,
  condition_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, condition_id),
  KEY idx_product_conditions_condition (condition_id),
  CONSTRAINT fk_product_conditions_product FOREIGN KEY (product_id)
    REFERENCES products (product_id) ON DELETE CASCADE,
  CONSTRAINT fk_product_conditions_condition FOREIGN KEY (condition_id)
    REFERENCES conditions (condition_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- KOMBINATIONEN: Hinweise, wenn zwei Krankheiten zusammen gewählt werden
-- (conditions N : M conditions). condition_a ist immer die kleinere ID.
CREATE TABLE IF NOT EXISTS combination_notes (
  condition_a INT UNSIGNED NOT NULL,
  condition_b INT UNSIGNED NOT NULL,
  note        VARCHAR(500) NOT NULL,
  PRIMARY KEY (condition_a, condition_b),
  KEY idx_combination_notes_b (condition_b),
  CONSTRAINT chk_combination_notes_order CHECK (condition_a < condition_b),
  CONSTRAINT fk_combination_notes_a FOREIGN KEY (condition_a)
    REFERENCES conditions (condition_id) ON DELETE CASCADE,
  CONSTRAINT fk_combination_notes_b FOREIGN KEY (condition_b)
    REFERENCES conditions (condition_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- GESPEICHERTE PLÄNE: users 1 : N saved_plans
-- Eingeloggte Kunden speichern ihre erstellten Pläne ("Meine Pläne").
-- Der Inhalt kommt immer aktuell aus plan_steps, gespeichert wird nur die Auswahl.
CREATE TABLE IF NOT EXISTS saved_plans (
  plan_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  animal     VARCHAR(30)  NOT NULL,
  pet_name   VARCHAR(40)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (plan_id),
  KEY idx_saved_plans_user (user_id),
  CONSTRAINT chk_saved_plans_animal CHECK (animal IN ('Hund', 'Katze')),
  CONSTRAINT fk_saved_plans_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- KRANKHEITEN EINES GESPEICHERTEN PLANS: saved_plans N : M conditions
-- Ein Tier kann mehrere Krankheiten haben.
CREATE TABLE IF NOT EXISTS saved_plan_conditions (
  plan_id      INT UNSIGNED NOT NULL,
  condition_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (plan_id, condition_id),
  KEY idx_saved_plan_conditions_condition (condition_id),
  CONSTRAINT fk_saved_plan_conditions_plan FOREIGN KEY (plan_id)
    REFERENCES saved_plans (plan_id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_plan_conditions_condition FOREIGN KEY (condition_id)
    REFERENCES conditions (condition_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- Ältere Version: saved_plans hatte eine Spalte condition_id (nur 1 Krankheit).
-- Vorhandene Pläne nach saved_plan_conditions kopieren, dann die Spalte entfernen.
SET @old_column = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saved_plans' AND COLUMN_NAME = 'condition_id');
SET @copy_sql = IF(@old_column > 0,
  'INSERT IGNORE INTO saved_plan_conditions (plan_id, condition_id) SELECT plan_id, condition_id FROM saved_plans',
  'DO 0');
PREPARE copy_stmt FROM @copy_sql;
EXECUTE copy_stmt;
DEALLOCATE PREPARE copy_stmt;
ALTER TABLE saved_plans DROP FOREIGN KEY IF EXISTS fk_saved_plans_condition;
ALTER TABLE saved_plans DROP INDEX IF EXISTS idx_saved_plans_condition;
ALTER TABLE saved_plans DROP COLUMN IF EXISTS condition_id;

-- DIÄT-PRODUKTE: gleiches Format wie die übrigen Produkte, special = 'Diät'.
INSERT IGNORE INTO products
  (product_id, name, description, price, image_path, animal, food_type, special, taste, size, tag, color, tint, age_min, age_max, weight_min, weight_max)
VALUES
  (9,  'Leicht & Fit',     'Diät · Gewichtsreduktion · 2 kg',     26.90, NULL, 'Hund',  'Trockenfutter', 'Diät', 'Huhn',  '2 kg',      'Schritt für Schritt leichter', '#7a9a6b', '#eef3ea', 1, 20, 1, 90),
  (10, 'Leicht & Fit',     'Diät · Gewichtsreduktion · 1.5 kg',   24.90, NULL, 'Katze', 'Trockenfutter', 'Diät', 'Huhn',  '1.5 kg',    'Schritt für Schritt leichter', '#8fa77e', '#f0f4eb', 1, 25, 1, 15),
  (11, 'Hypo Protein',     'Diät · hydrolysiertes Protein · 2 kg', 34.90, NULL, 'Hund',  'Trockenfutter', 'Diät', 'Lachs', '2 kg',      'Für empfindliche Haut',        '#6f8794', '#ebf0f2', 1, 20, 1, 90),
  (12, 'Hypo Protein',     'Diät · hydrolysiertes Protein · 1.5 kg', 31.90, NULL, 'Katze', 'Trockenfutter', 'Diät', 'Lachs', '1.5 kg',  'Für empfindliche Haut',        '#8399a3', '#eef2f4', 1, 25, 1, 15),
  (13, 'Sanfte Verdauung', 'Diät · leicht verdaulich · 6 × 400 g', 28.90, NULL, 'Hund',  'Nassfutter',    'Diät', 'Huhn',  '6 × 400 g', 'Wohltuend für den Bauch',      '#b49a6e', '#f5f0e6', 1, 20, 1, 90),
  (14, 'Sanfte Verdauung', 'Diät · leicht verdaulich · 6 × 200 g', 21.90, NULL, 'Katze', 'Nassfutter',    'Diät', 'Huhn',  '6 × 200 g', 'Wohltuend für den Bauch',      '#c2a97f', '#f6f2e9', 1, 25, 1, 15),
  (15, 'Nieren Balance',   'Diät · phosphorreduziert · 2 kg',     36.90, NULL, 'Hund',  'Trockenfutter', 'Diät', 'Huhn',  '2 kg',      'Sanft zu den Nieren',          '#8a7d96', '#f0edf3', 1, 25, 1, 90),
  (16, 'Nieren Balance',   'Diät · phosphorreduziert · 6 × 200 g', 23.90, NULL, 'Katze', 'Nassfutter',    'Diät', 'Rind',  '6 × 200 g', 'Sanft zu den Nieren',          '#9c8fa6', '#f2eff4', 1, 25, 1, 15),
  (17, 'Urinary Care',     'Diät · Harnwege · 6 × 400 g',         31.90, NULL, 'Hund',  'Nassfutter',    'Diät', 'Lachs', '6 × 400 g', 'Viel Flüssigkeit im Napf',     '#6c8fa0', '#eaf1f4', 1, 20, 1, 90),
  (18, 'Urinary Care',     'Diät · Harnwege · 6 × 200 g',         22.90, NULL, 'Katze', 'Nassfutter',    'Diät', 'Lachs', '6 × 200 g', 'Viel Flüssigkeit im Napf',     '#7f9fae', '#edf3f6', 1, 25, 1, 15),
  (19, 'Glyco Balance',    'Diät · Diabetes · 2 kg',              35.90, NULL, 'Hund',  'Trockenfutter', 'Diät', 'Rind',  '2 kg',      'Gleichmässig durch den Tag',   '#9a8a6a', '#f3f0e8', 1, 20, 1, 90),
  (20, 'Glyco Balance',    'Diät · kohlenhydratarm · 6 × 200 g',  23.90, NULL, 'Katze', 'Nassfutter',    'Diät', 'Huhn',  '6 × 200 g', 'Gleichmässig durch den Tag',   '#ab9b7a', '#f5f2ea', 1, 25, 1, 15),
  (21, 'Gelenk Aktiv',     'Diät · mit Omega-3 · 2 kg',           32.90, NULL, 'Hund',  'Trockenfutter', 'Diät', 'Lachs', '2 kg',      'Für jeden Schritt',            '#5f8a7a', '#e9f2ef', 1, 25, 1, 90),
  (22, 'Gelenk Aktiv',     'Diät · mit Omega-3 · 1.5 kg',         29.90, NULL, 'Katze', 'Trockenfutter', 'Diät', 'Lachs', '1.5 kg',    'Für jeden Schritt',            '#729b8b', '#ecf4f1', 1, 25, 1, 15);

-- KRANKHEITEN / KATEGORIEN
INSERT IGNORE INTO conditions (condition_id, name, icon, goal, sort_order) VALUES
  (1, 'Übergewicht', '⚖️', 'Langsam Gewicht verlieren und ausreichend Nährstoffe erhalten.', 1),
  (2, 'Futtermittelallergie oder Unverträglichkeit', '🌾', 'Auslösende Bestandteile erkennen und vermeiden.', 2),
  (3, 'Magen-Darm-Erkrankungen', '🍲', 'Verdauung und ausreichende Nährstoffaufnahme unterstützen.', 3),
  (4, 'Chronische Nierenerkrankung', '🫘', 'Die Ernährung an die eingeschränkte Nierenfunktion anpassen.', 4),
  (5, 'Harnsteine oder Harnwegserkrankungen', '💧', 'Die Behandlung unterstützen und das Risiko weiterer Steine reduzieren.', 5),
  (6, 'Diabetes', '🩸', 'Möglichst gleichmässige Blutzuckerwerte unterstützen.', 6),
  (7, 'Gelenkprobleme / Arthrose', '🦴', 'Ein gesundes Körpergewicht halten und die Beweglichkeit unterstützen.', 7);

-- Symbole für Datenbanken aus der ersten Version dieses Updates
UPDATE conditions SET icon = '⚖️' WHERE condition_id = 1 AND icon = '';
UPDATE conditions SET icon = '🌾' WHERE condition_id = 2 AND icon = '';
UPDATE conditions SET icon = '🍲' WHERE condition_id = 3 AND icon = '';
UPDATE conditions SET icon = '🫘' WHERE condition_id = 4 AND icon = '';
UPDATE conditions SET icon = '💧' WHERE condition_id = 5 AND icon = '';
UPDATE conditions SET icon = '🩸' WHERE condition_id = 6 AND icon = '';
UPDATE conditions SET icon = '🦴' WHERE condition_id = 7 AND icon = '';

-- HINWEISE ZU KOMBINATIONEN (kleinere ID zuerst)
INSERT IGNORE INTO combination_notes (condition_a, condition_b, note) VALUES
  (1, 2, 'Während einer Ausschlussdiät nur die vereinbarte Diät füttern. Ob und wie das Tier dabei abnehmen soll, mit der Tierarztpraxis besprechen.'),
  (1, 4, 'Abnehmen darf bei einer Nierenerkrankung nicht zu Futterverweigerung oder Muskelabbau führen. Gewicht nur unter tierärztlicher Kontrolle reduzieren.'),
  (1, 6, 'Abnehmen kann den Insulinbedarf verändern. Futtermenge und Insulin nur zusammen mit der Tierarztpraxis anpassen.'),
  (2, 3, 'Ausschlussdiät und Magen-Darm-Diät nicht selbst kombinieren. Die Tierarztpraxis wählt ein Futter, das zu beidem passt.'),
  (2, 4, 'Ausschlussdiät und Nierendiät nicht selbst kombinieren. Die Tierarztpraxis wählt ein Futter, das zu beidem passt.'),
  (2, 5, 'Ausschlussdiät und Harnwegsdiät nicht selbst kombinieren. Die Tierarztpraxis wählt ein Futter, das zu beidem passt.'),
  (2, 6, 'Ausschlussdiät und Diabetes-Fütterung nicht selbst kombinieren. Die Tierarztpraxis wählt ein Futter, das zu beidem passt.'),
  (2, 7, 'Auch Gelenk-Ergänzungsmittel können Auslöser enthalten. Während der Ausschlussdiät nur nach Rücksprache geben.'),
  (4, 5, 'Nieren- und Harnwegsdiäten unterscheiden sich zum Beispiel bei Phosphor, Eiweiss und Mineralstoffen. Welche Diät passt, entscheidet die Tierarztpraxis.'),
  (4, 6, 'Bei Diabetes wird oft eiweissreich gefüttert, bei einer Nierenerkrankung eher eiweiss- und phosphorreduziert. Die Tierarztpraxis legt fest, was Vorrang hat.'),
  (4, 7, 'Omega-3-Fettsäuren und Gelenk-Ergänzungsmittel bei einer Nierenerkrankung nur nach Rücksprache geben.');

-- PLAN-SCHRITTE (animal NULL = Hund und Katze)
INSERT IGNORE INTO plan_steps (step_id, condition_id, animal, label, text, sort_order) VALUES
  -- 1 Übergewicht
  (1,  1, NULL,    'Futter',      'Vollwertiges Diätfutter zur Gewichtsreduktion.', 1),
  (2,  1, NULL,    'Tagesablauf', 'Tagesration abwiegen und auf mehrere kleine Mahlzeiten verteilen.', 2),
  (3,  1, NULL,    'Leckerlis',   'In die Tagesration einrechnen; beispielsweise einen Teil des abgewogenen Futters als Belohnung verwenden.', 3),
  (4,  1, NULL,    'Kontrolle',   'Gewicht etwa alle zwei Wochen überprüfen und die Menge nach Rücksprache anpassen.', 4),
  (5,  1, 'Katze', 'Wichtig',     'Katzen niemals hungern lassen.', 5),
  (6,  1, NULL,    'Wichtig',     'Normales Futter einfach stark zu reduzieren, kann zu Nährstoffmangel führen.', 6),
  -- 2 Futtermittelallergie oder Unverträglichkeit
  (7,  2, NULL,    'Futter',      'Tierärztlich ausgewählte Ausschlussdiät, beispielsweise mit hydrolysiertem Protein oder einer bisher nicht gefütterten Proteinquelle.', 1),
  (8,  2, NULL,    'Tagesablauf', 'Ausschliesslich die vereinbarte Diät und Wasser anbieten.', 2),
  (9,  2, NULL,    'Leckerlis',   'Keine anderen Snacks, Kauartikel oder Essensreste. Auch aromatisierte Medikamente mit der Tierarztpraxis abklären.', 3),
  (10, 2, NULL,    'Kontrolle',   'Juckreiz, Hautveränderungen und Verdauung dokumentieren. Dauer und Auswertung der Diät gemeinsam festlegen.', 4),
  (11, 2, NULL,    'Wichtig',     '«Getreidefrei» bedeutet nicht automatisch «für Allergiker geeignet».', 5),
  -- 3 Magen-Darm-Erkrankungen
  (12, 3, NULL,    'Futter',      'Leicht verdauliches, vollwertiges Magen-Darm-Diätfutter nach Diagnose.', 1),
  (13, 3, NULL,    'Tagesablauf', 'Wenn das Tier fressen kann, mehrere kleine Portionen anbieten.', 2),
  (14, 3, NULL,    'Vermeiden',   'Essensreste, häufige Futterwechsel und ungeeignete Snacks.', 3),
  (15, 3, NULL,    'Kontrolle',   'Appetit, Erbrechen und Kotbeschaffenheit beobachten.', 4),
  (16, 3, NULL,    'Wichtig',     'Fett- und Ballaststoffgehalt müssen zur Erkrankung passen. Es gibt keinen einheitlichen Plan für alle Verdauungsprobleme.', 5),
  -- 4 Chronische Nierenerkrankung
  (17, 4, NULL,    'Futter',      'Tierärztlich verordnete Nierendiät mit reduziertem Phosphorgehalt.', 1),
  (18, 4, NULL,    'Tagesablauf', 'Auf mehrere gut akzeptierte Portionen verteilen; frisches Wasser jederzeit bereitstellen.', 2),
  (19, 4, NULL,    'Vermeiden',   'Zusätzliche Mineralstoffe oder Nahrungsergänzungen ohne Rücksprache.', 3),
  (20, 4, NULL,    'Kontrolle',   'Futteraufnahme, Gewicht und Blutwerte regelmässig prüfen lassen.', 4),
  (21, 4, NULL,    'Wichtig',     'Die passende Zusammensetzung hängt vom Krankheitsstadium ab. Bei Futterverweigerung die Tierarztpraxis kontaktieren.', 5),
  -- 5 Harnsteine oder Harnwegserkrankungen
  (22, 5, NULL,    'Futter',      'Eine zur diagnostizierten Steinart beziehungsweise Erkrankung passende Diät.', 1),
  (23, 5, NULL,    'Tagesablauf', 'Verordnete Tagesration verteilen und die Wasseraufnahme fördern, beispielsweise mit geeignetem Nassfutter.', 2),
  (24, 5, NULL,    'Vermeiden',   'Eigenständiges Ansäuern des Urins und nicht abgestimmte Zusatzprodukte.', 3),
  (25, 5, NULL,    'Kontrolle',   'Urinuntersuchungen nach tierärztlicher Vorgabe.', 4),
  (26, 5, NULL,    'Wichtig',     'Ein einziges «Urinary»-Futter passt nicht zu allen Harnsteinen.', 5),
  -- 6 Diabetes
  (27, 6, 'Hund',  'Futter',      'Gleichbleibende Futterart und Portionsgrössen; eine angepasste Ballaststoffmenge kann hilfreich sein.', 1),
  (28, 6, 'Katze', 'Futter',      'Häufig eignet sich ein kohlenhydratarmes, proteinreiches Nassfutter, sofern andere Erkrankungen nicht dagegen sprechen.', 1),
  (29, 6, NULL,    'Tagesablauf', 'Fütterungszeiten und Mengen genau mit der Insulinbehandlung abstimmen.', 2),
  (30, 6, NULL,    'Kontrolle',   'Futteraufnahme, Gewicht und Blutzucker nach dem Behandlungsplan überwachen.', 3),
  (31, 6, NULL,    'Wichtig',     'Futter oder Insulindosis nicht eigenständig ändern. Frisst das Tier nicht, vor der nächsten Insulingabe die Tierarztpraxis kontaktieren.', 4),
  -- 7 Gelenkprobleme / Arthrose
  (32, 7, NULL,    'Futter',      'Vollwertiges Futter für ein gesundes Körpergewicht; gegebenenfalls eine tierärztlich empfohlene Gelenkdiät mit Omega-3-Fettsäuren.', 1),
  (33, 7, NULL,    'Tagesablauf', 'Abgewogene Portionen; zusätzliche Snacks berücksichtigen.', 2),
  (34, 7, NULL,    'Kontrolle',   'Gewicht, Beweglichkeit und Muskelzustand beobachten.', 3),
  (35, 7, NULL,    'Wichtig',     'Ergänzungsmittel und ihre Dosierung abklären. Futter ersetzt keine Schmerzbehandlung.', 4);

-- PASSENDE PRODUKTE: Hund und Katze werden beim Anzeigen nach dem gewählten Tier gefiltert.
-- Bisherige Produkte nur dort, wo sie wirklich passen (z. B. nicht «Getreidefrei» bei Allergie).
INSERT IGNORE INTO product_conditions (product_id, condition_id) VALUES
  (9, 1), (10, 1),
  (11, 2), (12, 2),
  (13, 3), (14, 3),
  (15, 4), (16, 4),
  (17, 5), (18, 5),
  (19, 6), (20, 6),
  (21, 7), (22, 7), (3, 7), (8, 7);
