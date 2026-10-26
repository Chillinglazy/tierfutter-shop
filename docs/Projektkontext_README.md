# Projekt: Tierfutter-Online-Shop (Schulprojekt)

Dieses Dokument fasst den bisherigen Chat zusammen, damit in Claude Code nahtlos weitergearbeitet werden kann.

> **Stand 25.10.2026:** Die offenen Punkte und PHP-Dateien unten sind umgesetzt. Den aktuellen Stand zeigt die [`README.md`](../README.md). Seit 26.10.2026 liegen die Dateien in Unterordnern (`api/`, `includes/`, `database/` …), siehe Ordnerstruktur in der README.
> Korrekturen zu diesem Dokument:
> - Die Produkte standen in `script.js` (Array `products`), nicht im HTML.
> - `schema.sql` war nicht im Ordner und wurde nach dem Datenmodell unten neu erstellt.
> - Entscheidung zu Punkt 3: Als Gast bleibt der Warenkorb im `localStorage`, beim Login wird er in die Datenbank übernommen.

## Ziel
Kleiner Webshop für Tierfutter mit Registrierung, Login und Warenkorb. Es gibt nur eine Seite: die Landingpage `index.html`. Nach dem Login bleibt der User auf dieser Seite, wählt direkt Produkte aus und legt sie in den Warenkorb.

## Vorhanden
- `index.html`, CSS- und JavaScript-Dateien (fertig, Produkte stehen fest im HTML)
- `schema.sql` (fertig, siehe unten)

## Umgebung
- Windows, XAMPP Control Panel v3.3.0 (Apache + MySQL/MariaDB)
- MySQL Workbench; vorhandene Schemas: `phpmyadmin`, `test` (nicht verändern)
- Datenbank für das Projekt: `tierfutter_shop` (wird von `schema.sql` selbst angelegt)
- Projektdateien liegen in `htdocs` von XAMPP, Aufruf über `http://localhost/...`

## Anforderungen
- Registrierung mit: Name, E-Mail, Passwort
- Login mit: E-Mail + Passwort
- Passwörter nur gehasht speichern (`password_hash` / `password_verify`)
- Kardinalitäten sauber abbilden (siehe Datenmodell)
- Weitere Features (z. B. Admin-Rolle, "Passwort vergessen") eventuell am Schluss; keine Vorgaben vom Lehrer
- Technische Vorgabe: PHP mit PDO (Standard, nicht vorgegeben)

## Datenmodell (`schema.sql`)
| Tabelle | Zweck |
|---|---|
| `users` | Registrierung/Login: `user_id`, `name`, `email` (UNIQUE), `password_hash`, `registered_at` |
| `login_log` | Erfolgreiche Anmeldungen (`user_id`, `login_at`, `ip_address`) |
| `products` | Produkte: `product_id`, `name`, `description`, `price`, `image_path` |
| `carts` | Warenkorb eines Users |
| `cart_items` | Positionen: PK (`cart_id`, `product_id`), `quantity` |

Kardinalitäten:
- users → login_log: 1 : N
- users → carts: 1 : 1 (UNIQUE auf `carts.user_id`)
- carts ↔ products: N : M, aufgelöst über `cart_items` (jedes Produkt pro Warenkorb nur einmal, Menge in `quantity`)
- Alle Fremdschlüssel mit `ON DELETE CASCADE`

Geprüft: Die Logik (1:1, 1:N, N:M, Cascade, Mengen- und E-Mail-Regeln) wurde in einer Ersatzdatenbank (SQLite) getestet. Gegen echtes MySQL/MariaDB wurde die Datei noch nicht ausgeführt, das passiert beim ersten Import in Workbench.

## Offene Punkte
1. **Echte Produkte eintragen:** Die drei `INSERT`-Beispielprodukte am Ende von `schema.sql` sind nur Platzhalter. Sie müssen durch die echten Produkte aus `index.html` ersetzt werden (Name, Beschreibung, Preis, Bildpfad).
2. **Produkt-IDs im HTML:** Jedes Produkt in `index.html` braucht dieselbe ID wie in `products`, z. B. `data-product-id="1"`, damit der Warenkorb das richtige Produkt speichert.
3. **Warenkorb vor dem Login:** `carts` setzt einen eingeloggten User voraus. Zu klären: Warenkorb erst nach dem Login speichern, oder bis dahin im Browser (JavaScript/`localStorage`) halten und beim Login in die Datenbank übernehmen? Entscheidung steht noch aus; vorher prüfen, was das bestehende JavaScript bereits macht.
4. **Warenkorb anlegen:** Zu jedem User muss genau ein Eintrag in `carts` existieren (bei der Registrierung oder beim ersten Login anlegen).

## Zu erstellende PHP-Dateien
- `config.php` – PDO-Verbindung zu `tierfutter_shop` (XAMPP-Standard: Benutzer `root`, kein Passwort)
- `register.php` – Eingaben prüfen, E-Mail auf Duplikat prüfen, Passwort hashen, User und Warenkorb anlegen
- `login.php` – User per E-Mail laden, `password_verify`, Session starten (`session_regenerate_id`), Eintrag in `login_log`
- `logout.php` – Session beenden
- Warenkorb-Endpunkte (JSON, per `fetch` aus dem bestehenden JavaScript): Produkt hinzufügen, Menge ändern, entfernen, Warenkorb laden
- Anpassungen in `index.html`/JS: Login- und Registrierungsformular, Anzeige des eingeloggten Users, Warenkorb-Anbindung

## Sicherheitsregeln
- Nur Prepared Statements (PDO), keine SQL-Zusammenbau per String
- Ausgaben mit `htmlspecialchars` escapen
- Keine Klartext-Passwörter, nie `password_hash` an den Browser senden
- Sessions: `session_regenerate_id(true)` beim Login, Fehlermeldungen beim Login nicht verraten, ob die E-Mail existiert
- Warenkorb-Aktionen nur für eingeloggte User und nur für den eigenen Warenkorb (User-ID immer aus der Session, nie aus dem Request)

## Import-Anleitung
1. XAMPP: Apache und MySQL starten
2. MySQL Workbench: Verbindung `localhost` (Benutzer `root`) öffnen
3. *File → Open SQL Script* → `schema.sql` laden → Blitz-Symbol ausführen
4. Schemas aktualisieren; `tierfutter_shop` mit 5 Tabellen sollte erscheinen
5. Achtung: Das Skript löscht vorhandene Tabellen mit denselben Namen (`DROP TABLE IF EXISTS`)

## Nächster Schritt
`index.html` und die JS-Datei lesen, echte Produkte in `schema.sql` eintragen, dann die PHP-Dateien erstellen.
