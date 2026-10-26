-- Tierfutter-Shop – Datenbankschema (MySQL / MariaDB)
-- Import in phpMyAdmin: Haus-Symbol → Importieren → schema.sql auswählen → Importieren.
-- ACHTUNG: Vorhandene Tabellen mit denselben Namen werden gelöscht.

CREATE DATABASE IF NOT EXISTS tierfutter_shop
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tierfutter_shop;

-- Reihenfolge egal, weil die Fremdschlüssel-Prüfung kurz ausgeschaltet ist.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS newsletter;
DROP TABLE IF EXISTS cart_items;
DROP TABLE IF EXISTS carts;
DROP TABLE IF EXISTS login_log;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- BENUTZER: Registrierung und Login. Passwort nur als Hash.
CREATE TABLE users (
  user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  registered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT chk_users_email CHECK (email LIKE '%_@_%._%'),
  CONSTRAINT chk_users_name  CHECK (CHAR_LENGTH(TRIM(name)) > 0)
) ENGINE = InnoDB;

-- LOGIN-PROTOKOLL: users 1 : N login_log
CREATE TABLE login_log (
  log_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  login_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45)  NULL, -- 45 Zeichen reichen auch für IPv6
  PRIMARY KEY (log_id),
  KEY idx_login_log_user (user_id),
  CONSTRAINT fk_login_log_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- PRODUKTE: Einzige Quelle für die Website (products.php liefert sie an script.js).
-- animal, food_type, special und taste müssen genau wie die Auswahl
-- in den Filter-Dropdowns von index.html geschrieben sein.
CREATE TABLE products (
  product_id  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100)  NOT NULL,
  description VARCHAR(255)  NULL,     -- Textzeile unter dem Namen
  price       DECIMAL(6, 2) NOT NULL,
  image_path  VARCHAR(255)  NULL,     -- NULL: Verpackung wird per CSS gezeichnet
  animal      VARCHAR(30)   NOT NULL, -- Hund, Katze
  food_type   VARCHAR(30)   NOT NULL, -- Trockenfutter, Nassfutter, Snacks
  special     VARCHAR(30)   NOT NULL, -- Getreidefrei, Sensitive, Senior
  taste       VARCHAR(30)   NOT NULL, -- Huhn, Lachs, Rind, Kräuter
  size        VARCHAR(30)   NOT NULL, -- Packungsgrösse, z. B. 2 kg
  tag         VARCHAR(50)   NULL,     -- Etikett oben links auf der Karte
  color       CHAR(7)       NOT NULL, -- Farbe der Verpackung, z. B. #587550
  tint        CHAR(7)       NOT NULL, -- Hintergrundfarbe der Karte
  age_min     DECIMAL(4, 1) NOT NULL, -- passendes Alter des Tiers in Jahren
  age_max     DECIMAL(4, 1) NOT NULL,
  weight_min  DECIMAL(5, 1) NOT NULL, -- passendes Gewicht des Tiers in kg
  weight_max  DECIMAL(5, 1) NOT NULL,
  PRIMARY KEY (product_id),
  CONSTRAINT chk_products_price  CHECK (price >= 0),
  CONSTRAINT chk_products_color  CHECK (color REGEXP '^#[0-9a-fA-F]{6}$'),
  CONSTRAINT chk_products_tint   CHECK (tint REGEXP '^#[0-9a-fA-F]{6}$'),
  CONSTRAINT chk_products_age    CHECK (age_min >= 0 AND age_min <= age_max),
  CONSTRAINT chk_products_weight CHECK (weight_min >= 0 AND weight_min <= weight_max)
) ENGINE = InnoDB;

-- WARENKORB: users 1 : 1 carts (UNIQUE auf user_id)
CREATE TABLE carts (
  cart_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cart_id),
  UNIQUE KEY uq_carts_user (user_id),
  CONSTRAINT fk_carts_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- WARENKORB-POSITIONEN: carts N : M products
-- Jedes Produkt nur einmal pro Warenkorb, die Menge steht in quantity.
CREATE TABLE cart_items (
  cart_id    INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity   INT          NOT NULL DEFAULT 1,
  PRIMARY KEY (cart_id, product_id),
  KEY idx_cart_items_product (product_id),
  CONSTRAINT chk_cart_items_quantity CHECK (quantity BETWEEN 1 AND 99),
  CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id)
    REFERENCES carts (cart_id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_product FOREIGN KEY (product_id)
    REFERENCES products (product_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- BESTELLUNGEN: users 1 : N orders
-- user_id ist NULL bei Gastbestellungen.
-- Ausnahme ON DELETE SET NULL: Wird ein Konto gelöscht, bleibt die Bestellung erhalten.
CREATE TABLE orders (
  order_id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id             INT UNSIGNED  NULL,
  ordered_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  first_name          VARCHAR(50)   NOT NULL,
  last_name           VARCHAR(50)   NOT NULL,
  email               VARCHAR(255)  NOT NULL,
  phone               VARCHAR(30)   NOT NULL,
  street              VARCHAR(100)  NOT NULL,
  zip                 CHAR(4)       NOT NULL, -- Schweizer PLZ
  city                VARCHAR(50)   NOT NULL,
  payment_method      VARCHAR(20)   NOT NULL, -- nur die Zahlungsart, keine Kartendaten
  total               DECIMAL(8, 2) NOT NULL,
  terms_accepted_at   DATETIME      NOT NULL, -- Nachweis: AGB akzeptiert
  privacy_accepted_at DATETIME      NOT NULL, -- Nachweis: Datenschutzerklärung akzeptiert
  PRIMARY KEY (order_id),
  KEY idx_orders_user (user_id),
  CONSTRAINT chk_orders_payment CHECK (payment_method IN ('Rechnung', 'TWINT', 'Kreditkarte')),
  CONSTRAINT chk_orders_zip     CHECK (zip REGEXP '^[1-9][0-9]{3}$'),
  CONSTRAINT chk_orders_email   CHECK (email LIKE '%_@_%._%'),
  CONSTRAINT chk_orders_total   CHECK (total >= 0),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE = InnoDB;

-- BESTELL-POSITIONEN: orders N : M products
-- Name und Preis werden kopiert, damit alte Bestellungen den Preis vom Bestelltag behalten.
-- Ausnahme ON DELETE RESTRICT: Ein bestelltes Produkt kann nicht gelöscht werden.
CREATE TABLE order_items (
  order_id     INT UNSIGNED  NOT NULL,
  product_id   INT UNSIGNED  NOT NULL,
  product_name VARCHAR(100)  NOT NULL,
  unit_price   DECIMAL(6, 2) NOT NULL,
  quantity     INT           NOT NULL,
  PRIMARY KEY (order_id, product_id),
  KEY idx_order_items_product (product_id),
  CONSTRAINT chk_order_items_quantity CHECK (quantity BETWEEN 1 AND 99),
  CONSTRAINT chk_order_items_price    CHECK (unit_price >= 0),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id)
    REFERENCES orders (order_id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id)
    REFERENCES products (product_id) ON DELETE RESTRICT
) ENGINE = InnoDB;

-- NEWSLETTER: Anmeldungen (auch von Gästen), jede E-Mail nur einmal.
CREATE TABLE newsletter (
  newsletter_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(255) NOT NULL,
  subscribed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (newsletter_id),
  UNIQUE KEY uq_newsletter_email (email),
  CONSTRAINT chk_newsletter_email CHECK (email LIKE '%_@_%._%')
) ENGINE = InnoDB;

-- PRODUKTE: Neue Produkte hier oder direkt in phpMyAdmin eintragen. ID 4 gibt es nicht.
INSERT INTO products
  (product_id, name, description, price, image_path, animal, food_type, special, taste, size, tag, color, tint, age_min, age_max, weight_min, weight_max)
VALUES
  (1, 'Huhn & Natur',       'Getreidefrei · 2 kg',      24.90, NULL, 'Hund',  'Trockenfutter', 'Getreidefrei', 'Huhn',  '2 kg',      'Liebling im Napf',       '#587550', '#edf1e7', 1, 8,  1, 90),
  (2, 'Feiner Lachs',       'Sensitive · 6 × 200 g',    18.90, NULL, 'Katze', 'Nassfutter',    'Sensitive',    'Lachs', '6 × 200 g', 'Für sensible Bäuche',    '#ca9c81', '#f5eee8', 1, 18, 1, 15),
  (3, 'Rind & Vital',       'Senior · 2 kg',            27.90, NULL, 'Hund',  'Trockenfutter', 'Senior',       'Rind',  '2 kg',      'Für die goldenen Jahre', '#869184', '#edf0eb', 7, 25, 1, 90),
  (5, 'Huhn & Sanft',       'Getreidefrei · 1.5 kg',    22.90, NULL, 'Katze', 'Trockenfutter', 'Getreidefrei', 'Huhn',  '1.5 kg',    'Kleine Feinschmecker',   '#94a873', '#f0f4e9', 1, 18, 1, 15),
  (6, 'Zarte Rindhäppchen', 'Sensitive · 150 g',         6.90, NULL, 'Hund',  'Snacks',        'Sensitive',    'Rind',  '150 g',     'Ein kleines Dankeschön', '#ba947c', '#f4ede6', 1, 20, 1, 90),
  (7, 'Lachs & Balance',    'Getreidefrei · 6 × 400 g', 29.90, NULL, 'Hund',  'Nassfutter',    'Getreidefrei', 'Lachs', '6 × 400 g', 'Abwechslung im Napf',    '#658377', '#eaf2ee', 1, 15, 1, 90),
  (8, 'Huhn & Geborgen',    'Senior · 6 × 200 g',       19.90, NULL, 'Katze', 'Nassfutter',    'Senior',       'Huhn',  '6 × 200 g', 'Sanft geniessen',        '#a2a581', '#f2f3e9', 7, 25, 1, 15);
