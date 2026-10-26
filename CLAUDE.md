# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

German-language school project: a small pet-food web shop (Tierfutter-Shop, Bargen SH) with registration, login, cart and checkout. Plain HTML/CSS/JS frontend, PHP 8 + PDO backend, MariaDB 10.4 via XAMPP on Windows. No framework, no build step, no package manager, no automated test suite. The user communicates in German; UI texts, code comments and `README.md` are German (Swiss spelling: "ss" instead of "ß"). `README.md` is the single project document (requirements, data model with Mermaid ER diagram, deletion rules, decisions log) and must be kept in sync with code changes.

## Running and checking

- The site only works through Apache (PHP), not via file:// or Live Server: `http://localhost/tierfutter-shop/`. `C:\xampp\htdocs\tierfutter-shop` is a directory junction to this folder, so edits are live after a browser reload. Apache and MySQL must be running in the XAMPP Control Panel (the user starts them; they may be off).
- PHP and MySQL are not on PATH. Use the XAMPP binaries:
  - Lint: `/c/xampp/php/php.exe -l api/order.php` (loop over `api/*.php includes/*.php`)
  - SQL: `/c/xampp/mysql/bin/mysql.exe -uroot --default-character-set=utf8mb4` (user `root`, no password)
- The user imports SQL files themselves in phpMyAdmin. Do not re-import `database/schema.sql` into the real `tierfutter_shop` database: it drops all tables and wipes users and orders.
- Testing pattern used so far: copy the project to the scratchpad, point the copy's `includes/config.local.php` at a temporary database (`tierfutter_shop_tmp`), import a `sed`-renamed copy of `schema.sql`, serve the copy with `php.exe -S 127.0.0.1:<port> -t <copy>`, exercise the API with `curl -H 'Content-Type: application/json'` and the UI in the browser pane, then drop the temp DB. If testing against the real DB, use clearly fake accounts and delete only those rows afterwards. Native Windows binaries fail when the shell cwd path is too long; `cd /c/` first and pass paths as arguments.

## Architecture

**Single page + JSON API.** `index.html` is the whole shop (dialogs for cart, auth, checkout, "Meine Bestellungen"); `agb.html`/`datenschutz.html` are static legal pages. `js/script.js` talks to the backend only through `api(file, data)`, which prefixes `api/`, sends POST+JSON when `data` is given (GET otherwise) and throws `Error(json.error)` on non-2xx. Every file in `api/` returns JSON.

**PHP bootstrap.** Each endpoint starts with `require __DIR__ . '/../includes/config.php'`, which sets the JSON header, installs an exception handler (generic 500 message, details to error log), loads DB credentials from `includes/config.local.php` (gitignored; template `config.local.example.php`), opens `$pdo` (exceptions, no emulated prepares) and starts the session. Helpers there: `json_response()` (sends and exits), `read_json_post()` (rejects non-POST / non-JSON, which is the CSRF mitigation), `require_login()` (user id always from the session, never from the request), `log_in()` (regenerates session id, ensures the user's cart row exists, writes `login_log`).

**Products come from the database.** `api/products.php` delivers all product rows; the frontend has no product list of its own. Filter dropdown values in `index.html` must match the stored `animal`/`food_type`/`special`/`taste` strings exactly. DB text is inserted into HTML via `esc()` in `script.js` (or `textContent`); keep doing that for any new DB-sourced output.

**Two cart modes.** Guests: `basket` object `{product_id: qty}` in `localStorage` key `tierfutter-cart`. Logged in: `cart_items` table via `api/cart.php` (actions `add`/`change`/`remove`/`merge`, always answers with the full cart). On login/registration the guest cart is sent as `merge` and localStorage is cleared. Logged-in cart requests are serialized through `cartQueue` in `script.js`.

**Checkout.** `api/order.php` works for guests and users. It validates everything server-side (including the mandatory AGB/Datenschutz booleans), then in one transaction reads the items (logged in: from `cart_items` `FOR UPDATE`; guest: ids/quantities from the request), takes names and prices from `products` (never from the request), computes the total in Rappen, inserts `orders` + `order_items` (name/price snapshot), clears the user's cart and inserts the optional `newsletter` opt-in. Only the payment method is stored, never card data.

**Data model** (`database/schema.sql`, 8 tables): `users`, `login_log`, `carts` (UNIQUE `user_id`, 1:1), `cart_items`, `products`, `orders` (`user_id` NULL for guests), `order_items`, `newsletter` (standalone). FKs are `ON DELETE CASCADE` except `orders.user_id` → `SET NULL` and `order_items.product_id` → `RESTRICT`; these exceptions are deliberate and documented in the README.

## Things that must stay in sync

- `orders`, `order_items`, `newsletter` are defined twice: in `database/schema.sql` (full rebuild) and `database/update_orders.sql` (adds them to an existing DB with `CREATE TABLE IF NOT EXISTS`). Change both.
- `MAX_QUANTITY` (99) in `includes/config.php` matches the `CHECK (quantity BETWEEN 1 AND 99)` constraints; `PAYMENT_METHODS` in `api/order.php` matches `chk_orders_payment`.
- New publicly reachable files belong in the web folders (`api/`, `css/`, `js/`, `assets/`, root HTML). `includes/` and `database/` have `.htaccess` with `Require all denied`; the root `.htaccess` hides dotfiles (`.git`), `*.md` and the workspace file.
- The README's file table, folder tree, ER diagram and "Projektstand" table describe the current code.

## Conventions

- 2-space indentation everywhere; JS mostly omits trailing semicolons on the last statement of a block, matching existing code.
- Section comments in upper case labels, e.g. `// WARENKORB: ...`, `// EINGABEN PRÜFEN`.
- All SQL via prepared statements; dynamic `IN (...)` lists build only the `?` placeholders.
- Git: branch `main`, remote `origin` = `github.com/Chillinglazy/tierfutter-shop`. The repo-local `user.email` is the GitHub noreply address on purpose (keeps the school e-mail private) – don't change it. `config.local.php` and `EER-Diagramm.png` are gitignored and must stay local.
