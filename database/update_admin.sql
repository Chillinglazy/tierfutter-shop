-- Tierfutter-Shop – Update: Administrator-Rolle
-- Für eine bestehende Datenbank: ergänzt in der Tabelle users die Spalte role.
-- Alle bisherigen Benutzer werden Kunden (role = 'kunde'). Daten bleiben erhalten.
-- Läuft mit MySQL 8 (z. B. MySQL Workbench) und mit MariaDB (XAMPP, phpMyAdmin).
-- MySQL Workbench: Datei öffnen → Blitz-Symbol (ganzes Skript ausführen).
-- phpMyAdmin: Haus-Symbol → Importieren → update_admin.sql auswählen → Importieren.
-- Zweimal ausführen schadet nicht (die Spalte wird nur angelegt, wenn sie fehlt).
-- (Bei einer neuen Installation reicht schema.sql, dort ist die Spalte schon enthalten.)

USE tierfutter_shop;

-- ROLLE: kunde (Standard) oder admin. Nur admin sieht die Datenbankverwaltung (admin.html).
-- MySQL kennt "ADD COLUMN IF NOT EXISTS" nicht. Darum zuerst in information_schema
-- nachsehen, ob die Spalte schon da ist, und den ALTER-Befehl nur dann ausführen.
SET @fehlt = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role');
SET @sql = IF(@fehlt,
  'ALTER TABLE users ADD COLUMN role ENUM(''kunde'', ''admin'') NOT NULL DEFAULT ''kunde'' AFTER password_hash',
  'SELECT ''Spalte users.role ist schon vorhanden.'' AS hinweis');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ADMIN FESTLEGEN: E-Mail-Adresse des eigenen Kontos eintragen, Zeile ohne "-- " ausführen.
-- Danach im Shop aus- und wieder einloggen.
-- UPDATE users SET role = 'admin' WHERE email = 'deine@email.ch';
