# Tierfutter-Shop (Schulprojekt)

Kleiner Online-Shop für Tierfutter mit Registrierung, Login und Warenkorb.
Es gibt nur eine Seite (`index.html`). Nach dem Login bleibt man auf dieser
Seite, wählt Produkte aus und legt sie in den Warenkorb.

- **Frontend:** HTML, CSS, JavaScript (ohne Framework, ohne Build)
- **Backend:** PHP 8 mit PDO
- **Datenbank:** MySQL / MariaDB (XAMPP), Datenbank `tierfutter_shop`

Hintergrund und Entscheidungen aus der Planungsphase stehen in
[`Projektkontext_README.md`](Projektkontext_README.md).

---

## Projektstand

| Bereich | Stand |
|---|---|
| Landingpage mit Produktfilter (`index.html`, `style.css`, `script.js`) | ✅ fertig |
| Produkte aus der Datenbank (`products.php`), Änderung in phpMyAdmin sofort sichtbar | ✅ fertig |
| Datenbankschema mit echten Produkten (`schema.sql`) | ✅ fertig, in MariaDB 10.4 (XAMPP) getestet |
| Registrierung (Name, E-Mail, Passwort) | ✅ fertig |
| Login / Logout mit Session und Login-Protokoll | ✅ fertig |
| Warenkorb in der Datenbank (eingeloggt) | ✅ fertig |
| Gast-Warenkorb im Browser, Übernahme beim Login | ✅ fertig |
| Anzeige des eingeloggten Users im Header | ✅ fertig |
| Bestellung / Bezahlung | ❌ nicht geplant |
| Admin-Rolle, "Passwort vergessen" | ⏳ optional, falls am Schluss noch Zeit bleibt |

---

## Installation (XAMPP)

PHP läuft nur über den Apache-Server, nicht beim Doppelklick auf
`index.html` und nicht mit "Live Server".

1. **Projekt für Apache verfügbar machen.** Entweder den Projektordner nach
   `C:\xampp\htdocs\tierfutter-shop` kopieren bzw. dorthin klonen, oder ihn an
   seinem Ort lassen und in `htdocs` nur eine Verknüpfung (Junction) darauf
   anlegen. Dann gibt es keine zweite Kopie. Einmalig in der
   Eingabeaufforderung (cmd) ausführen, `<Projektordner>` durch den eigenen
   Pfad ersetzen:
   ```
   mklink /J C:\xampp\htdocs\tierfutter-shop "<Projektordner>"
   ```
   Wird der Projektordner verschoben oder umbenannt, die Verknüpfung in
   `htdocs` löschen und neu anlegen. Beim Löschen der Verknüpfung bleibt der
   Projektordner erhalten.
2. **Zugangsdaten anlegen:** `config.local.example.php` kopieren und die
   Kopie in `config.local.php` umbenennen. Für XAMPP passen die Werte so
   (Benutzer `root`, kein Passwort). Sonst Benutzer und Passwort dort ändern.
   `config.local.php` wird nicht in Git gespeichert.
3. **XAMPP Control Panel:** Apache und MySQL starten.
4. **Datenbank importieren** mit phpMyAdmin:
   - `http://localhost/phpmyadmin` öffnen
   - Links oben auf das Haus-Symbol klicken (keine Datenbank auswählen)
   - Reiter *Importieren* → *Datei auswählen* → `schema.sql` → unten *Importieren*
   - Links erscheint `tierfutter_shop` mit 5 Tabellen, `products` enthält 7 Produkte
   - **Achtung:** Das Skript löscht die Tabellen in `tierfutter_shop` und
     legt sie neu an. Alle Benutzer und Warenkörbe gehen dabei verloren.
     Die Datenbanken `phpmyadmin` und `test` werden nicht verändert.
5. **Im Browser öffnen:** `http://localhost/tierfutter-shop/`

---

## Dateien

| Datei | Inhalt |
|---|---|
| `index.html` | Aufbau der Seite, Filter, Dialoge für Warenkorb und Login/Registrierung |
| `style.css` | Farben (oben unter `:root`), Schriften, Abstände, Smartphone-Ansicht |
| `script.js` | Produkte laden und anzeigen, Filter, Warenkorb, Login-Ablauf |
| `assets/logo.png` | Shop-Logo |
| `schema.sql` | Datenbank, Tabellen und Produkte |
| `config.php` | PDO-Verbindung, Session-Einstellungen, gemeinsame Hilfsfunktionen |
| `config.local.example.php` | Vorlage für die Datenbank-Zugangsdaten |
| `config.local.php` | Eigene Zugangsdaten (nicht in Git, aus der Vorlage erstellen) |
| `.gitignore` | Dateien, die nicht in Git gespeichert werden (z. B. `config.local.php`) |
| `register.php` | Registrierung: Eingaben prüfen, Passwort hashen, User + Warenkorb anlegen |
| `login.php` | Login: Passwort prüfen, Session starten, Eintrag in `login_log` |
| `logout.php` | Session beenden |
| `session.php` | Gibt zurück, ob und wer eingeloggt ist (beim Laden der Seite) |
| `products.php` | Liefert alle Produkte aus der Datenbank (ohne Login) |
| `cart.php` | Warenkorb laden, Produkt hinzufügen, Menge ändern, entfernen, Gast-Warenkorb übernehmen |
| `Projektkontext_README.md` | Zusammenfassung der Planung (Anforderungen, Datenmodell, Regeln) |
| `Tierfutter-Shop.code-workspace` | VS-Code-Arbeitsbereich (Einrückung 2 Leerzeichen) |

Alle PHP-Dateien antworten mit JSON und werden von `script.js` per `fetch`
aufgerufen. Es gibt keine eigenen PHP-Seiten, die Seite bleibt `index.html`.

---

## Datenmodell

| Tabelle | Zweck |
|---|---|
| `users` | `user_id`, `name`, `email` (UNIQUE), `password_hash`, `registered_at` |
| `login_log` | Erfolgreiche Logins: `user_id`, `login_at`, `ip_address` |
| `products` | `product_id`, `name`, `description`, `price`, `image_path` sowie Filter- und Anzeigedaten: `animal`, `food_type`, `special`, `taste`, `size`, `tag`, `color`, `tint`, `age_min`, `age_max`, `weight_min`, `weight_max` |
| `carts` | Ein Warenkorb pro User: `cart_id`, `user_id` (UNIQUE), `created_at` |
| `cart_items` | Positionen: PK (`cart_id`, `product_id`), `quantity` (1 bis 99) |

Kardinalitäten:
- `users` → `login_log`: **1 : N**
- `users` → `carts`: **1 : 1** (UNIQUE auf `carts.user_id`)
- `carts` ↔ `products`: **N : M**, aufgelöst über `cart_items`
- Alle Fremdschlüssel mit `ON DELETE CASCADE`

---

## Ablauf

**Seite laden:** `script.js` holt alle Produkte von `products.php` und
zeigt sie an. Danach fragt es `session.php`, ob noch jemand eingeloggt ist.

**Gast (nicht eingeloggt):** Der Warenkorb wird im Browser gespeichert
(`localStorage`, Schlüssel `tierfutter-cart`).

**Registrierung:** `register.php` legt den User und seinen Warenkorb in
einer Transaktion an und loggt ihn direkt ein.

**Login:** `login.php` prüft das Passwort mit `password_verify`, erneuert
die Session-ID und schreibt einen Eintrag in `login_log`. Danach schickt
`script.js` den Gast-Warenkorb an `cart.php` (Aktion `merge`). Die Mengen
werden zum gespeicherten Warenkorb addiert und der Gast-Warenkorb im
Browser wird gelöscht.

**Eingeloggt:** Jede Warenkorb-Änderung geht an `cart.php`. Die Antwort ist
immer der aktuelle Warenkorb aus der Datenbank.

**Logout:** `logout.php` beendet die Session. Der Warenkorb bleibt in der
Datenbank und ist beim nächsten Login wieder da.

### Warenkorb-API (`cart.php`)

| Anfrage | Wirkung |
|---|---|
| `GET cart.php` | Warenkorb laden |
| `POST {"action":"add","product_id":1,"quantity":1}` | Hinzufügen (`quantity` optional) |
| `POST {"action":"change","product_id":1,"delta":-1}` | Menge ändern, bei 0 entfernen |
| `POST {"action":"remove","product_id":1}` | Entfernen |
| `POST {"action":"merge","items":{"1":2,"3":1}}` | Gast-Warenkorb übernehmen |

Antwort: `{"items": {"1": 2, "3": 1}}` (Produkt-ID → Menge) oder
`{"error": "..."}` mit HTTP-Fehlercode (z. B. 401, wenn nicht eingeloggt).

---

## Sicherheit

- Nur Prepared Statements (PDO), kein SQL aus zusammengesetzten Strings
- Passwörter nur als Hash (`password_hash` / `password_verify`), 8 bis 72 Zeichen
- Der Passwort-Hash wird nie an den Browser gesendet
- `session_regenerate_id(true)` beim Login, Session-Cookie `HttpOnly` und `SameSite=Strict`
- Login-Fehler verraten nicht, ob die E-Mail existiert
- Warenkorb-Aktionen nur eingeloggt und nur für den eigenen Warenkorb
  (die User-ID kommt immer aus der Session, nie aus der Anfrage)
- Änderungen nur per POST mit JSON, fremde HTML-Formulare werden abgelehnt
- Texte aus der Datenbank werden vor der Anzeige entschärft: Produktdaten mit
  `esc()` in `script.js`, der Benutzername über `textContent`. HTML-Code in
  einem Produktnamen erscheint deshalb als Text. In PHP-Dateien, die selbst
  HTML ausgeben, wäre dafür `htmlspecialchars` nötig.
- Die Farben eines Produkts müssen das Format `#rrggbb` haben (Prüfung in der Datenbank).
- Technische Fehlermeldungen landen im PHP-Fehlerlog, nicht im Browser

---

## Bearbeiten

- **Produkte ändern:** nur in der Datenbank. In phpMyAdmin `tierfutter_shop`
  → `products` → bei der Zeile *Bearbeiten* (oder *Einfügen* für ein neues
  Produkt). Danach die Website neu laden (F5), die Änderung ist sofort da.
  - `animal`, `food_type`, `special` und `taste` genau wie im Filter-Dropdown
    von `index.html` schreiben (z. B. `Hund`, `Nassfutter`), sonst findet der
    Filter das Produkt nicht. Neue Werte zusätzlich als `<option>` in
    `index.html` eintragen.
  - `description` ist die Textzeile unter dem Produktnamen.
  - Änderungen in phpMyAdmin stehen nicht in `schema.sql`. Wer sie behalten
    will, trägt sie dort auch ein. Ein neuer Import setzt sonst alle
    Produkte auf den Stand von `schema.sql` zurück.
- **Farben:** oben in `style.css` unter `:root`
- **Adresse und E-Mail:** im Footer von `index.html`
- **Einrückung:** HTML, CSS, JavaScript und PHP mit zwei Leerzeichen.
  Einige produktspezifische Farben und Abstände stehen direkt im HTML
  beziehungsweise in den JavaScript-Vorlagen als `style`-Attribute.

---

## Testen

1. Als Gast zwei Produkte in den Warenkorb legen.
2. Registrieren: Im Header steht "Hallo, Name", der Warenkorb ist übernommen.
3. Menge im Warenkorb ändern, Seite neu laden: Der Login und der Warenkorb bleiben.
4. Logout: Warenkorb ist leer. Wieder einloggen: Der Warenkorb ist wieder da.
5. Mit falschem Passwort einloggen: "E-Mail oder Passwort ist falsch."
6. In phpMyAdmin prüfen: `users`, `carts`, `login_log`, `cart_items` sind gefüllt.
7. In phpMyAdmin den Preis eines Produkts ändern, Website neu laden: Der neue Preis erscheint.

## Hinweise

- Produkte und Preise sind Beispiele. Bestellungen und Zahlungen gibt es nicht.
- Gewicht und Alter dienen nur der beispielhaften Produktauswahl, nicht als Fütterungsempfehlung.
- Ohne Server (Datei direkt geöffnet) gibt es keine Produkte, weil sie aus
  der Datenbank kommen. Die Seite zeigt dann einen Hinweis, dass der Server fehlt.
