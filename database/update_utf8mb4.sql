-- Tierfutter-Shop – Update: Symbole (Emojis) speichern können
-- Problem: Wurde die Datenbank mit Zeichensatz latin1 oder utf8 (3 Byte) angelegt, werden
-- die Symbole der Krankheiten im Ernährungsplan beim Import zu "?" bzw. "??".
-- Dieses Skript stellt alle Shop-Tabellen auf utf8mb4 um und trägt die Symbole neu ein.
-- Läuft mit MySQL 8 und MariaDB. Mehrmals ausführen schadet nicht.
-- MySQL Workbench: Datei öffnen → Blitz-Symbol. phpMyAdmin: Importieren → Datei auswählen → Importieren.

SET NAMES utf8mb4;
USE tierfutter_shop;

-- 1. ALLE TABELLEN AUF utf8mb4 UMSTELLEN (4-Byte-Zeichen wie Emojis werden speicherbar).
--    Umlaute und alle anderen Daten bleiben erhalten.
SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE login_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE carts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE cart_items CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE orders CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE order_items CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE newsletter CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE conditions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE plan_steps CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE product_conditions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE combination_notes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE saved_plans CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE saved_plan_conditions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;

-- 2. SYMBOLE DER KRANKHEITEN NEU EINTRAGEN (nur wo sie fehlen oder zu ? geworden sind).
UPDATE conditions SET icon = '⚖️' WHERE condition_id = 1 AND (icon = '' OR icon LIKE '%?%');
UPDATE conditions SET icon = '🌾' WHERE condition_id = 2 AND (icon = '' OR icon LIKE '%?%');
UPDATE conditions SET icon = '🍲' WHERE condition_id = 3 AND (icon = '' OR icon LIKE '%?%');
UPDATE conditions SET icon = '🫘' WHERE condition_id = 4 AND (icon = '' OR icon LIKE '%?%');
UPDATE conditions SET icon = '💧' WHERE condition_id = 5 AND (icon = '' OR icon LIKE '%?%');
UPDATE conditions SET icon = '🩸' WHERE condition_id = 6 AND (icon = '' OR icon LIKE '%?%');
UPDATE conditions SET icon = '🦴' WHERE condition_id = 7 AND (icon = '' OR icon LIKE '%?%');

-- Kontrolle: In der Spalte icon müssen jetzt die Symbole stehen.
SELECT condition_id, icon, name FROM conditions ORDER BY sort_order;
