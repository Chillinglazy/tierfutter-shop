-- Tierfutter-Shop – Update: Bestellungen und Newsletter
-- Für eine bestehende Datenbank: legt nur die neuen Tabellen an.
-- Benutzer, Warenkörbe und Produkte bleiben erhalten.
-- Import in phpMyAdmin: Haus-Symbol → Importieren → update_orders.sql auswählen → Importieren.
-- (Bei einer neuen Installation reicht schema.sql, dort sind die Tabellen schon enthalten.)

USE tierfutter_shop;

-- BESTELLUNGEN: users 1 : N orders
-- user_id ist NULL bei Gastbestellungen.
-- Ausnahme ON DELETE SET NULL: Wird ein Konto gelöscht, bleibt die Bestellung erhalten.
CREATE TABLE IF NOT EXISTS orders (
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
CREATE TABLE IF NOT EXISTS order_items (
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
CREATE TABLE IF NOT EXISTS newsletter (
  newsletter_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(255) NOT NULL,
  subscribed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (newsletter_id),
  UNIQUE KEY uq_newsletter_email (email),
  CONSTRAINT chk_newsletter_email CHECK (email LIKE '%_@_%._%')
) ENGINE = InnoDB;
