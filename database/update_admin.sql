-- Tierfutter-Shop – Update: Administrator-Rolle
-- Für eine bestehende Datenbank: ergänzt in der Tabelle users die Spalte role.
-- Alle bisherigen Benutzer werden Kunden (role = 'kunde'). Daten bleiben erhalten.
-- Import in phpMyAdmin: Haus-Symbol → Importieren → update_admin.sql auswählen → Importieren.
-- Zweimal importieren schadet nicht.
-- (Bei einer neuen Installation reicht schema.sql, dort ist die Spalte schon enthalten.)

USE tierfutter_shop;

-- ROLLE: kunde (Standard) oder admin. Nur admin sieht die Datenbankverwaltung (admin.html).
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS role ENUM('kunde', 'admin') NOT NULL DEFAULT 'kunde' AFTER password_hash;

-- ADMIN FESTLEGEN: E-Mail-Adresse des eigenen Kontos eintragen, Zeile ohne "-- " ausführen
-- (phpMyAdmin → Datenbank tierfutter_shop → Reiter SQL). Danach aus- und wieder einloggen.
-- UPDATE users SET role = 'admin' WHERE email = 'deine@email.ch';
