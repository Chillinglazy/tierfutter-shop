-- Tierfutter-Shop – Stored Procedures
-- Die wichtigsten Abläufe des Shops als gespeicherte Prozeduren (Stored Procedures).
-- Zum Zeigen und Ausprobieren in MySQL Workbench (MySQL 8) bzw. MariaDB.
-- MySQL Workbench: Datei öffnen → Blitz-Symbol (ganzes Skript ausführen).
-- Zweimal ausführen schadet nicht (jede Prozedur wird zuerst gelöscht und neu angelegt).
-- Daten werden beim Anlegen nicht verändert; erst ein CALL ändert etwas.
--
-- WICHTIG: Die Website (PHP) ruft diese Prozeduren nicht auf. InfinityFree erlaubt
-- auf dem Gratis-Hosting keine Stored Procedures, deshalb arbeitet PHP weiterhin mit
-- normalen SQL-Befehlen (Prepared Statements). Die Prozeduren machen dasselbe wie PHP.
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

DELIMITER $$

-- PRODUKTE: nach Tier und Futterart filtern (wie die Filter auf der Website).
-- NULL bei einem Parameter = nicht filtern.
-- Beispiel: CALL sp_produkte_filtern('Hund', NULL);
CREATE PROCEDURE tierfutter_shop.sp_produkte_filtern(IN p_animal VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_food_type VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  SELECT product_id, name, price, animal, food_type, special, taste, size
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
-- Beispiel: CALL sp_login_protokollieren(1, '127.0.0.1');
CREATE PROCEDURE tierfutter_shop.sp_login_protokollieren(IN p_user_id INT UNSIGNED, IN p_ip VARCHAR(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
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

DELIMITER ;

-- KONTROLLE: Liste aller Prozeduren dieser Datenbank
SELECT ROUTINE_NAME AS prozedur
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'
ORDER BY ROUTINE_NAME;
