-- Tierfutter-Shop – Stored Procedures (24 Prozeduren)
-- Die Abläufe des Shops als gespeicherte Prozeduren (Stored Procedures).
-- Zum Zeigen und Ausprobieren in MySQL Workbench (MySQL 8) bzw. MariaDB.
-- MySQL Workbench: Datei öffnen → Blitz-Symbol (ganzes Skript ausführen).
-- Zweimal ausführen schadet nicht (jede Prozedur wird zuerst gelöscht und neu angelegt).
-- Daten werden beim Anlegen nicht verändert; erst ein CALL ändert etwas.
--
-- WEBSITE: Ob PHP diese Prozeduren aufruft, steht in includes/config.local.php:
--   DB_PROCEDURES = true  → PHP ruft alles mit CALL auf (z. B. bei alwaysdata, das erlaubt Prozeduren)
--   fehlt / false         → PHP arbeitet mit normalen SQL-Befehlen (InfinityFree erlaubt keine Prozeduren)
-- Die Prozeduren machen dasselbe wie die SQL-Befehle in PHP.
--
-- Online (alwaysdata, phpMyAdmin): die Datei procedures-online.sql aus dem Upload-Paket nehmen.
-- Sie ist gleich, nur ohne den festen Datenbanknamen tierfutter_shop.
--
-- Voraussetzung: Datenbank tierfutter_shop mit schema.sql (oder allen update_*.sql).
-- Die Prozeduren tragen den Datenbanknamen (tierfutter_shop.sp_...), deshalb klappt das
-- Anlegen auch, wenn nur ein einzelner Block ausgeführt wird oder keine Datenbank gewählt ist.
-- Texte in den Parametern nutzen dieselbe Sortierung wie die Tabellen (utf8mb4_unicode_ci),
-- sonst meldet MySQL 8 "Illegal mix of collations".
-- Für die CALL-Beispiele vorher die Datenbank wählen: USE tierfutter_shop;

SET NAMES utf8mb4;
USE tierfutter_shop;

DROP PROCEDURE IF EXISTS tierfutter_shop.sp_produkte_filtern;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_benutzer_registrieren;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_login_protokollieren;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_warenkorb_anzeigen;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_warenkorb_hinzufuegen;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_warenkorb_entfernen;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_bestellung_aufgeben;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_bestellungen_anzeigen;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_ernaehrungsplan;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_umsatz_pro_produkt;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_benutzer_suchen;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_passwort_aendern;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_benutzer_rolle;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_warenkorb_id;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_warenkorb_menge_aendern;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_gastbestellung_anlegen;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_bestellposition_hinzufuegen;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_bestellung_total_berechnen;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_newsletter_anmelden;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_krankheiten_liste;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_kombinationshinweise;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_plan_speichern;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_plan_loeschen;
DROP PROCEDURE IF EXISTS tierfutter_shop.sp_plaene_anzeigen;

DELIMITER $$

-- PRODUKTE: nach Tier und Futterart filtern (wie die Filter auf der Website).
-- NULL bei einem Parameter = nicht filtern.
-- Beispiel: CALL sp_produkte_filtern('Hund', NULL);
CREATE PROCEDURE tierfutter_shop.sp_produkte_filtern(IN p_animal VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_food_type VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  SELECT product_id, name, description, price, image_path, animal, food_type, special, taste,
         size, tag, color, tint, age_min, age_max, weight_min, weight_max
  FROM products
  WHERE (p_animal IS NULL OR animal = p_animal)
    AND (p_food_type IS NULL OR food_type = p_food_type)
  ORDER BY product_id;
END$$

-- REGISTRIERUNG: User und Warenkorb zusammen anlegen (alles oder nichts).
-- Das Passwort kommt bereits gehasht (wie in register.php mit password_hash()).
-- Beispiel: CALL sp_benutzer_registrieren('Test', 'test@beispiel.ch', '$2y$10$...', @neue_id); SELECT @neue_id;
CREATE PROCEDURE tierfutter_shop.sp_benutzer_registrieren(
  IN p_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_password_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  OUT p_user_id INT UNSIGNED)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL; -- Fehler (z. B. E-Mail schon vorhanden) an den Aufrufer weitergeben
  END;

  START TRANSACTION;
  INSERT INTO users (name, email, password_hash) VALUES (TRIM(p_name), LOWER(TRIM(p_email)), p_password_hash);
  SET p_user_id = LAST_INSERT_ID();
  INSERT INTO carts (user_id) VALUES (p_user_id);
  COMMIT;
END$$

-- LOGIN-PROTOKOLL: erfolgreichen Login speichern (wie log_in() in config.php).
-- Fehlt der Warenkorb des Users, wird er dabei angelegt (jeder User hat genau einen).
-- Beispiel: CALL sp_login_protokollieren(1, '127.0.0.1');
CREATE PROCEDURE tierfutter_shop.sp_login_protokollieren(IN p_user_id INT UNSIGNED, IN p_ip VARCHAR(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  INSERT IGNORE INTO carts (user_id) VALUES (p_user_id);
  INSERT INTO login_log (user_id, ip_address) VALUES (p_user_id, p_ip);
END$$

-- WARENKORB ANZEIGEN: Positionen mit Preis und Zwischensumme eines Users.
-- Beispiel: CALL sp_warenkorb_anzeigen(1);
CREATE PROCEDURE tierfutter_shop.sp_warenkorb_anzeigen(IN p_user_id INT UNSIGNED)
BEGIN
  SELECT p.product_id, p.name, p.price, ci.quantity, p.price * ci.quantity AS zwischensumme
  FROM carts c
  JOIN cart_items ci ON ci.cart_id = c.cart_id
  JOIN products p ON p.product_id = ci.product_id
  WHERE c.user_id = p_user_id
  ORDER BY p.name;
END$$

-- WARENKORB: Produkt hinzufügen. Ist es schon drin, wird die Menge erhöht (höchstens 99).
-- Beispiel: CALL sp_warenkorb_hinzufuegen(1, 3, 2);
CREATE PROCEDURE tierfutter_shop.sp_warenkorb_hinzufuegen(
  IN p_user_id INT UNSIGNED, IN p_product_id INT UNSIGNED, IN p_quantity INT)
BEGIN
  DECLARE v_cart_id INT UNSIGNED;

  IF p_quantity IS NULL OR p_quantity < 1 OR p_quantity > 99 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Die Menge muss zwischen 1 und 99 liegen.';
  END IF;

  SELECT cart_id INTO v_cart_id FROM carts WHERE user_id = p_user_id;
  IF v_cart_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zu diesem User gibt es keinen Warenkorb.';
  END IF;

  INSERT INTO cart_items (cart_id, product_id, quantity)
  VALUES (v_cart_id, p_product_id, p_quantity)
  ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + p_quantity, 99);
END$$

-- WARENKORB: Produkt entfernen.
-- Beispiel: CALL sp_warenkorb_entfernen(1, 3);
CREATE PROCEDURE tierfutter_shop.sp_warenkorb_entfernen(IN p_user_id INT UNSIGNED, IN p_product_id INT UNSIGNED)
BEGIN
  DELETE ci FROM cart_items ci
  JOIN carts c ON c.cart_id = ci.cart_id
  WHERE c.user_id = p_user_id AND ci.product_id = p_product_id;
END$$

-- BESTELLEN: Warenkorb eines eingeloggten Users als Bestellung speichern (wie order.php).
-- In einer Transaktion: Warenkorb sperren, Total aus den aktuellen Preisen rechnen,
-- orders + order_items (Name und Preis als Kopie) speichern, Warenkorb leeren.
-- Beispiel:
--   CALL sp_bestellung_aufgeben(1, 'Max', 'Muster', 'max@beispiel.ch', '079 123 45 67',
--     'Hauptstrasse 1', '8213', 'Neunkirch', 'Rechnung', @bestellung);
--   SELECT @bestellung;
CREATE PROCEDURE tierfutter_shop.sp_bestellung_aufgeben(
  IN p_user_id INT UNSIGNED,
  IN p_first_name VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_last_name VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  IN p_phone VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_street VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_zip CHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_city VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  IN p_payment_method VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  OUT p_order_id INT UNSIGNED)
BEGIN
  DECLARE v_total DECIMAL(8, 2);
  DECLARE v_positionen INT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  -- Positionen sperren (FOR UPDATE), damit niemand gleichzeitig den Warenkorb ändert
  SELECT COUNT(*), SUM(p.price * ci.quantity) INTO v_positionen, v_total
  FROM cart_items ci
  JOIN carts c ON c.cart_id = ci.cart_id
  JOIN products p ON p.product_id = ci.product_id
  WHERE c.user_id = p_user_id
  FOR UPDATE;

  IF v_positionen = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Der Warenkorb ist leer.';
  END IF;

  INSERT INTO orders (user_id, first_name, last_name, email, phone, street, zip, city,
                      payment_method, total, terms_accepted_at, privacy_accepted_at)
  VALUES (p_user_id, p_first_name, p_last_name, LOWER(p_email), p_phone, p_street, p_zip, p_city,
          p_payment_method, v_total, NOW(), NOW());
  SET p_order_id = LAST_INSERT_ID();

  INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity)
  SELECT p_order_id, p.product_id, p.name, p.price, ci.quantity
  FROM cart_items ci
  JOIN carts c ON c.cart_id = ci.cart_id
  JOIN products p ON p.product_id = ci.product_id
  WHERE c.user_id = p_user_id;

  DELETE ci FROM cart_items ci
  JOIN carts c ON c.cart_id = ci.cart_id
  WHERE c.user_id = p_user_id;

  COMMIT;
END$$

-- MEINE BESTELLUNGEN: alle Bestellungen eines Users mit Positionen, neueste zuerst.
-- Beispiel: CALL sp_bestellungen_anzeigen(1);
CREATE PROCEDURE tierfutter_shop.sp_bestellungen_anzeigen(IN p_user_id INT UNSIGNED)
BEGIN
  SELECT o.order_id, o.ordered_at, o.total, o.payment_method,
         oi.product_name, oi.quantity, oi.unit_price
  FROM orders o
  JOIN order_items oi ON oi.order_id = o.order_id
  WHERE o.user_id = p_user_id
  ORDER BY o.ordered_at DESC, o.order_id DESC, oi.product_name;
END$$

-- ERNÄHRUNGSPLAN: Schritte und passende Produkte für eine Krankheit und ein Tier.
-- Gibt zwei Ergebnisse zurück: zuerst die Schritte, dann die Produkte.
-- Beispiel: CALL sp_ernaehrungsplan(1, 'Hund');
CREATE PROCEDURE tierfutter_shop.sp_ernaehrungsplan(IN p_condition_id INT UNSIGNED, IN p_animal VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  SELECT c.name AS krankheit, s.label, s.text
  FROM conditions c
  JOIN plan_steps s ON s.condition_id = c.condition_id
  WHERE c.condition_id = p_condition_id AND (s.animal IS NULL OR s.animal = p_animal)
  ORDER BY s.sort_order, s.step_id;

  SELECT p.product_id, p.name, p.price, p.special
  FROM product_conditions pc
  JOIN products p ON p.product_id = pc.product_id
  WHERE pc.condition_id = p_condition_id AND p.animal = p_animal
  ORDER BY p.special = 'Diät' DESC, p.product_id;
END$$

-- AUSWERTUNG: verkaufte Menge und Umsatz pro Produkt (für den Admin).
-- Beispiel: CALL sp_umsatz_pro_produkt();
CREATE PROCEDURE tierfutter_shop.sp_umsatz_pro_produkt()
BEGIN
  SELECT oi.product_id, oi.product_name,
         SUM(oi.quantity) AS verkaufte_menge,
         SUM(oi.quantity * oi.unit_price) AS umsatz
  FROM order_items oi
  GROUP BY oi.product_id, oi.product_name
  ORDER BY umsatz DESC;
END$$

-- ================================================================
-- WEITERE PROZEDUREN FÜR DIE WEBSITE
-- Diese braucht PHP zusätzlich, wenn in config.local.php DB_PROCEDURES = true steht
-- (z. B. bei alwaysdata). Dann ruft die Website alle Abläufe mit CALL auf.
-- ================================================================

-- BENUTZER SUCHEN: Login, Admin-Login und Prüfung "E-Mail schon registriert?".
-- Beispiel: CALL sp_benutzer_suchen('test@beispiel.ch');
CREATE PROCEDURE tierfutter_shop.sp_benutzer_suchen(IN p_email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  SELECT user_id, name, password_hash
  FROM users
  WHERE email = LOWER(TRIM(p_email));
END$$

-- PASSWORT-HASH ERSETZEN (wenn PHP einen neueren Hash-Standard verwendet).
-- Beispiel: CALL sp_passwort_aendern(1, '$2y$10$...');
CREATE PROCEDURE tierfutter_shop.sp_passwort_aendern(IN p_user_id INT UNSIGNED, IN p_password_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  UPDATE users SET password_hash = p_password_hash WHERE user_id = p_user_id;
END$$

-- ROLLE EINES USERS (kunde oder admin), wird bei jeder Admin-Anfrage frisch gelesen.
-- Beispiel: CALL sp_benutzer_rolle(1);
CREATE PROCEDURE tierfutter_shop.sp_benutzer_rolle(IN p_user_id INT UNSIGNED)
BEGIN
  SELECT role FROM users WHERE user_id = p_user_id;
END$$

-- WARENKORB-NUMMER eines Users (leer, wenn der User gelöscht wurde).
-- Beispiel: CALL sp_warenkorb_id(1);
CREATE PROCEDURE tierfutter_shop.sp_warenkorb_id(IN p_user_id INT UNSIGNED)
BEGIN
  SELECT cart_id FROM carts WHERE user_id = p_user_id;
END$$

-- WARENKORB: Menge um p_delta ändern (+1 / -1). Bei 0 oder weniger wird das Produkt entfernt.
-- Beispiel: CALL sp_warenkorb_menge_aendern(1, 3, -1);
CREATE PROCEDURE tierfutter_shop.sp_warenkorb_menge_aendern(
  IN p_user_id INT UNSIGNED, IN p_product_id INT UNSIGNED, IN p_delta INT)
BEGIN
  DECLARE v_cart_id INT UNSIGNED;
  DECLARE v_menge INT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  -- Zeile sperren, damit gleichzeitige Klicks sich nicht überschreiben
  SELECT ci.cart_id, ci.quantity INTO v_cart_id, v_menge
  FROM cart_items ci
  JOIN carts c ON c.cart_id = ci.cart_id
  WHERE c.user_id = p_user_id AND ci.product_id = p_product_id
  FOR UPDATE;

  IF v_cart_id IS NOT NULL THEN
    IF v_menge + p_delta <= 0 THEN
      DELETE FROM cart_items WHERE cart_id = v_cart_id AND product_id = p_product_id;
    ELSE
      UPDATE cart_items SET quantity = LEAST(v_menge + p_delta, 99)
      WHERE cart_id = v_cart_id AND product_id = p_product_id;
    END IF;
  END IF;
  COMMIT;
END$$

-- GASTBESTELLUNG: Kopf der Bestellung ohne User anlegen. Total ist zuerst 0,
-- danach Positionen mit sp_bestellposition_hinzufuegen und Total mit sp_bestellung_total_berechnen.
-- PHP ruft alle drei in einer Transaktion auf.
-- Beispiel: CALL sp_gastbestellung_anlegen('Max', 'Muster', 'max@beispiel.ch', '079 123 45 67',
--   'Hauptstrasse 1', '8213', 'Neunkirch', 'TWINT', @bestellung);
CREATE PROCEDURE tierfutter_shop.sp_gastbestellung_anlegen(
  IN p_first_name VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_last_name VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  IN p_phone VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_street VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_zip CHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_city VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  IN p_payment_method VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  OUT p_order_id INT UNSIGNED)
BEGIN
  INSERT INTO orders (user_id, first_name, last_name, email, phone, street, zip, city,
                      payment_method, total, terms_accepted_at, privacy_accepted_at)
  VALUES (NULL, p_first_name, p_last_name, LOWER(p_email), p_phone, p_street, p_zip, p_city,
          p_payment_method, 0, NOW(), NOW());
  SET p_order_id = LAST_INSERT_ID();
END$$

-- BESTELLPOSITION: Produkt mit aktuellem Namen und Preis aus products in die Bestellung kopieren.
-- Gibt es das Produkt nicht, passiert nichts.
-- Beispiel: CALL sp_bestellposition_hinzufuegen(@bestellung, 3, 2);
CREATE PROCEDURE tierfutter_shop.sp_bestellposition_hinzufuegen(
  IN p_order_id INT UNSIGNED, IN p_product_id INT UNSIGNED, IN p_quantity INT)
BEGIN
  INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity)
  SELECT p_order_id, product_id, name, price, p_quantity
  FROM products
  WHERE product_id = p_product_id;
END$$

-- TOTAL einer Bestellung aus den Positionen rechnen, speichern und anzeigen.
-- Beispiel: CALL sp_bestellung_total_berechnen(@bestellung);
CREATE PROCEDURE tierfutter_shop.sp_bestellung_total_berechnen(IN p_order_id INT UNSIGNED)
BEGIN
  UPDATE orders
  SET total = (SELECT COALESCE(SUM(unit_price * quantity), 0) FROM order_items WHERE order_id = p_order_id)
  WHERE order_id = p_order_id;

  SELECT o.total, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS positionen
  FROM orders o
  WHERE o.order_id = p_order_id;
END$$

-- NEWSLETTER: E-Mail eintragen (schon angemeldet: nichts tun).
-- Beispiel: CALL sp_newsletter_anmelden('max@beispiel.ch');
CREATE PROCEDURE tierfutter_shop.sp_newsletter_anmelden(IN p_email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  INSERT IGNORE INTO newsletter (email) VALUES (LOWER(TRIM(p_email)));
END$$

-- ERNÄHRUNGSPLAN: alle Krankheiten/Kategorien für die Auswahl-Kacheln.
-- Beispiel: CALL sp_krankheiten_liste();
CREATE PROCEDURE tierfutter_shop.sp_krankheiten_liste()
BEGIN
  SELECT condition_id, name, icon, goal
  FROM conditions
  ORDER BY sort_order, name;
END$$

-- ERNÄHRUNGSPLAN: Hinweise für Kombinationen der gewählten Krankheiten.
-- p_ids ist eine Liste mit Komma, z. B. '1,4'.
-- Beispiel: CALL sp_kombinationshinweise('1,4');
CREATE PROCEDURE tierfutter_shop.sp_kombinationshinweise(IN p_ids VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  SELECT a.name AS name_a, b.name AS name_b, n.note
  FROM combination_notes n
  JOIN conditions a ON a.condition_id = n.condition_a
  JOIN conditions b ON b.condition_id = n.condition_b
  WHERE FIND_IN_SET(n.condition_a, p_ids) > 0 AND FIND_IN_SET(n.condition_b, p_ids) > 0
  ORDER BY a.sort_order, b.sort_order;
END$$

-- MEINE PLÄNE: Plan mit seinen Krankheiten speichern (alles oder nichts).
-- p_ids ist eine Liste mit Komma, z. B. '1,4'. Unbekannte IDs werden übersprungen.
-- Beispiel: CALL sp_plan_speichern(1, 'Hund', 'Bello', '1,4', @plan); SELECT @plan;
CREATE PROCEDURE tierfutter_shop.sp_plan_speichern(
  IN p_user_id INT UNSIGNED, IN p_animal VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  IN p_pet_name VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_ids VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  OUT p_plan_id INT UNSIGNED)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  INSERT INTO saved_plans (user_id, animal, pet_name) VALUES (p_user_id, p_animal, NULLIF(TRIM(p_pet_name), ''));
  SET p_plan_id = LAST_INSERT_ID();
  INSERT INTO saved_plan_conditions (plan_id, condition_id)
  SELECT p_plan_id, condition_id FROM conditions WHERE FIND_IN_SET(condition_id, p_ids) > 0;
  COMMIT;
END$$

-- MEINE PLÄNE: eigenen Plan löschen (die Krankheiten werden per CASCADE mitgelöscht).
-- Beispiel: CALL sp_plan_loeschen(3, 1);
CREATE PROCEDURE tierfutter_shop.sp_plan_loeschen(IN p_plan_id INT UNSIGNED, IN p_user_id INT UNSIGNED)
BEGIN
  DELETE FROM saved_plans WHERE plan_id = p_plan_id AND user_id = p_user_id;
END$$

-- MEINE PLÄNE: alle Pläne eines Users mit ihren Krankheiten, neueste zuerst.
-- Beispiel: CALL sp_plaene_anzeigen(1);
CREATE PROCEDURE tierfutter_shop.sp_plaene_anzeigen(IN p_user_id INT UNSIGNED)
BEGIN
  SELECT sp.plan_id, sp.animal, sp.pet_name, sp.created_at, c.condition_id, c.name, c.icon
  FROM saved_plans sp
  JOIN saved_plan_conditions spc ON spc.plan_id = sp.plan_id
  JOIN conditions c ON c.condition_id = spc.condition_id
  WHERE sp.user_id = p_user_id
  ORDER BY sp.created_at DESC, sp.plan_id DESC, c.sort_order;
END$$

DELIMITER ;

-- KONTROLLE: Liste aller Prozeduren dieser Datenbank
SELECT ROUTINE_NAME AS prozedur
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'
ORDER BY ROUTINE_NAME;
