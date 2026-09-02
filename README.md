# Stock Manager — localhost barcode till

A small localhost PHP app for scanning products with a USB barcode
scanner, ringing up a sale, issuing a Szamlazz.hu invoice, and keeping
stock two-way synced with a WooCommerce store.

## Requirements

- PHP 8.1+ (developed and tested against **PHP 8.5**), with `curl`,
  `xmlwriter`, `fileinfo` extensions, plus `pdo_sqlite` (default) or
  `pdo_mysql` (if you switch to MySQL — see below)
- `data/`, `invoices/`, and `webroot/assets/` must be writable by the PHP
  process (SQLite file / local backups / invoice PDFs / an uploaded logo
  all live there)
- A Szamlazz.hu account with **Számla Agent** enabled (Beállítások →
  Számla Agent → kulcs generálása) to get your `szamlaagentkulcs`
- WooCommerce REST API keys (WooCommerce → Settings → Advanced → REST
  API → Add key, permissions: **Read/Write**)

By default, no Composer, no database server — everything runs off PHP's
built-in web server and a single SQLite file. See "Database: SQLite vs
MySQL" below if you outgrow that.

## Setup

1. Copy this whole folder somewhere on your machine.
2. Edit `config/config.php`:
   - `woocommerce.store_url`, `consumer_key`, `consumer_secret`
   - `woocommerce.barcode_source` — `'sku'` if you use the WooCommerce
     SKU field as your barcode, or `'meta'` (+ `barcode_meta_key`) if a
     plugin stores the barcode in a custom field
   - `szamlazz.agent_key`
   - `szamlazz.default_buyer` — used for walk-in/cash sales where you
     don't capture real customer billing details
3. Start the server from the project root:

   ```bash
   php -S localhost:8000 -t webroot
   ```

4. Open http://localhost:8000 — you'll see an empty till until you sync.
5. Click **"Sync WooCommerce-ből"** to pull your products (name, price,
   stock, and barcode/SKU) into the local database.
6. Plug in your USB barcode scanner (it behaves like a keyboard — no
   drivers needed) and click into the barcode field, then scan.

## Beszerzés (incoming stock / purchases)

Open `beszerzes.html` (linked from the top bar) to record deliveries from
suppliers:

- Scan a barcode to add an existing product to the incoming list, or use
  **"+ Új termék hozzáadása"** to create a brand-new product on the spot
  (name, unit, group, cikkszám, VAT rate, sale price, barcode, weight/
  volume, price-list/webshop visibility — the same fields as the
  "Árucikk módosítása" dialog).
- Each line shows the last known purchase (cost) price for that product,
  pre-filled and editable — update it when the supplier's price changes;
  it's saved as the new "last known cost" for next time.
- Optionally record supplier details, payment method, and paid status.
- Saving a purchase adds the received quantity to stock and pushes the
  new stock level to WooCommerce, the same way till sales do.

## Árucikkek lista (products list)

Open `termekek.html` (linked from the top bar) for a full, editable list of
every product — the local equivalent of the "Árucikk lista" screen:

- Filter by name, cikkszám, vonalkód, csoport, zero-stock only, or include
  soft-deleted articles.
- Click any row (or "Módosítás") to edit that product's full master data
  in the same modal used from the Beszerzés page.
- **"+ Új árucikk"** creates a brand-new product without needing a
  purchase or a barcode scan first.
- "Törlés" soft-deletes a product (sets `is_deleted`, hides it from the
  till and search by default) rather than removing its row — sales/
  purchase history stays intact. "Visszaállítás" undoes it.

## Termék importálás (switching over from another program)

If you've been running stock in another program (e.g. Axel Pro), the
**Importálás** tab on `beallitasok.html` (Beállítások) migrates the whole
product catalog in one pass:

1. Pick the source program from the dropdown.
2. Upload the **CSV** it exports (if you only have .xls/.xlsx, save it as CSV
   from Excel or LibreOffice first — the server can't parse binary Excel
   files directly).
3. **Előnézet (preview)**: shows how many rows will become new products vs.
   update existing ones by barcode, and flags anything suspicious (missing
   name, duplicate barcode in the file, an expected column that wasn't found).
4. **Importálás indítása (commit)**: actually creates/updates the products —
   name, cikkszám, csoport, unit, net purchase price, net and gross sale
   price (VAT rate is inferred from their ratio), barcode — and **overwrites
   stock directly** with the imported value (this models a full switch-over,
   not a purchase/delivery).

Matching is by barcode: an existing product with the same barcode gets
updated; anything else is inserted as new. Rows with no barcode always
insert as new, since there's nothing to safely match them against.

**Extending it for another program (e.g. Jutasoft):** the column mapping
lives in `src/ImportProfiles.php`, one array per program. Supporting a new
program is just adding an entry there (which column name maps to which
field) — the page, upload handling, and matching logic are already generic
and need no changes.

## How the sync works (two-way)

- **Pull (manual button, or run periodically via cron)**: reads all
  products from WooCommerce and updates the local product list —
  name, price, and stock quantity. `webroot/api/sync-pull.php`.
- **Push (automatic, after every till sale)**: once a sale is recorded
  locally, the new stock quantity for each sold product is pushed to
  WooCommerce via the REST API. See `updateStock()` calls in
  `webroot/api/sale.php`.
- **Webhook (optional, for real-time pull)**: `webroot/api/webhook.php`
  can receive a WooCommerce "Order updated" webhook and decrement local
  stock immediately when a sale happens on the website, instead of
  waiting for the next manual pull. This only works if WooCommerce can
  reach this machine over the network (same LAN, or a tunnel like
  ngrok) — on a fully offline localhost setup, rely on the manual pull
  button instead.

Because both sides can change stock independently, this is a
last-write-wins sync, not a transactional one: if the same item sells
on the website and at the till within the same sync interval, run a
pull afterwards to reconcile. For a single small shop this is usually
fine; flag it if you need stricter guarantees and we can add optimistic
locking / conflict alerts.

## Invoicing

Every till sale calls Szamlazz.hu's Számla Agent XML API
(`src/SzamlazzClient.php`) to issue a real invoice and downloads the
PDF into `invoices/`. If invoice creation fails (e.g. bad agent key,
network hiccup), the sale is still recorded locally with status
`invoice_failed` so you don't lose the transaction — you can reissue
the invoice manually from the Szamlazz.hu dashboard using the sale
details in the database.

## Database: SQLite vs MySQL

The app runs on either, chosen in `config/config.php` → `db.driver`:

```php
'db' => [
    'driver' => 'sqlite', // or 'mysql'
    'sqlite' => ['path' => __DIR__ . '/../data/stock.sqlite'],
    'mysql'  => [
        'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'stock_manager', 'username' => 'stock_manager', 'password' => '',
        'charset' => 'utf8mb4',
    ],
],
```

**SQLite** (default) needs zero setup and is genuinely fine for a single
till doing normal retail volume — WAL mode is enabled automatically, which
lets reads and writes overlap instead of blocking each other.

**MySQL 8** is worth switching to once you have real concurrent load —
several people using the app at once, a busier shop with a lot of daily
sales, or you just want a "real" database server for easier backups/
replication/monitoring on shared hosting. To switch:

1. Create a database and user in MySQL 8, then import the schema:
   ```
   mysql -u root -p -e "CREATE DATABASE stock_manager CHARACTER SET utf8mb4"
   mysql -u root -p stock_manager < schema.mysql.sql
   ```
2. Fill in the `mysql` block in `config.php` and set `driver` to `'mysql'`.
3. Make sure the `pdo_mysql` PHP extension is enabled.

The schema (`schema.mysql.sql`) uses InnoDB, `DECIMAL` for every money
column (no float rounding drift once there are years of sales history),
`JSON` for the closing-report breakdowns, and indexes on every column the
app actually filters by (`sales.created_at`, `sale_items.sale_id`,
`products.barcode`/`group_name`, etc.).

Existing SQLite installs aren't touched automatically — there's no
built-in SQLite→MySQL data migration tool here; for a one-off move at
this scale, exporting the SQLite tables to CSV and loading them into the
new MySQL schema (matching column order) is the pragmatic path.

### What was optimized for larger sales volume

- **No more N+1 queries**: the napi zárás report used to run one query per
  sale to fetch its line items; it now fetches all of a day's items in a
  single `WHERE sale_id IN (...)` query.
- **Transactions around multi-write operations**: a till sale, a beszerzés
  with many line items, a WooCommerce pull, and the CSV import are each
  wrapped in one transaction instead of committing every statement
  individually — the CSV import in particular (thousands of rows) is far
  faster this way on both SQLite and MySQL.
- **Fewer round trips per line item**: stock updates used to re-`SELECT`
  the product right after `UPDATE`ing it just to log the new quantity; the
  new value is now computed from data already in memory instead.
- **Schema/migration checks are now cached**: previously every single API
  request re-ran the full migration set (several `ALTER TABLE` attempts
  wrapped in try/catch, discarded every time). A `schema_version` table
  now makes that a single indexed `SELECT` on the fast path — migrations
  only actually run once, the first time a database needs them.
- **Indexes added** on `sales.created_at`, `sale_items.sale_id`,
  `purchase_items.purchase_id`, and `products.group_name` (present in both
  `schema.sql` and `schema.mysql.sql`).

None of this changes behavior — it's the same features, just cheaper to
run as the sales table grows into the tens or hundreds of thousands of
rows.

## Logo and automatic sync (topbar settings)

Every page's topbar has a logo (top-left), a sync icon, and a gear icon
that both link to the dedicated **`beallitasok.html`** (Beállítások) page —
settings are no longer a popup, so they're easy to bookmark or link to
directly. That page is a full-width tabbed layout:

- **Logo**: go to Beállítások → Logó tab to upload a PNG/JPG/WEBP/SVG (max 2 MB).
  It's saved to `webroot/assets/logo.<ext>`, replacing any previous upload.
  "Alaplogóra visszaállítás" removes it and falls back to the bundled
  `assets/logo-default.svg`.
- **Sync icon**: click it any time for an on-demand WooCommerce pull (same
  as the old button, just an icon now — spins while running, shows a toast
  with the result).
- **Automatic sync**: Beállítások → Szinkronizálás tab lets you turn it on and
  pick an interval. This flips a setting in `data/settings.json` — it does
  **not** run in the background by itself, since nothing keeps a PHP built-in
  server "awake" between requests. To make it actually run, add a cron job
  that hits the check-and-run endpoint (safe to call every minute — it
  no-ops until the configured interval has elapsed):

  ```
  * * * * * curl -s http://localhost:8000/api/auto-sync-run.php > /dev/null
  ```

  The last automatic run's time and result are shown in that same settings
  tab.

## Napi zárás (daily closing / sales summary)

`zaras.html` (linked from every page) shows a forgalmi összesítő for any
date:

- Totals: number of sales, gross/net revenue, VAT collected
- Breakdown by payment method (Készpénz/Átutalás/Bankkártya/...) — every
  till sale now captures a payment method (a selector next to the total,
  always visible, not just when an invoice is requested)
- Breakdown by VAT rate
- The day's individual transactions, each with a "Nyugta" link to reprint
  its receipt
- **"Nyomtatás"** prints the whole summary page via the browser (a print
  stylesheet hides the nav/buttons and keeps just the report)
- **"Napi zárás rögzítése"** stores a closing record for that date in the
  `closings` table. Re-running it for the same date overwrites the record
  (useful if a late invoice retry changed the numbers) — it's a bookkeeping
  snapshot, not something that blocks further sales on that date.

## Nyugtanyomtató (receipt printer) support

Two independent ways to print a receipt, available from `receipt.html`
(opened via "Nyugta megtekintése/nyomtatása" after checkout, or "Nyugta"
on the Napi zárás page):

- **Böngészőből (browser print)**: always available, works with literally
  any printer your OS already has a driver for — it's just `window.print()`
  on a formatted receipt view.
- **Hálózati nyomtatóra (network printer)**: sends raw ESC/POS commands
  over TCP to a network thermal printer's "raw"/port 9100 interface — the
  same mechanism used by most affordable Ethernet/WiFi receipt printers
  (Epson TM-*, Xprinter, Zjiang, etc.), no special driver needed. Configure
  the IP/port/paper width and send a test page from Beállítások → Nyomtató.

**Limitation worth knowing:** ESC/POS printers need a printer-specific
codepage command to show accented characters correctly, and that varies by
model/firmware. Rather than risk garbled text, `EscPosPrinter` transliterates
everything to plain ASCII before printing (á→a, ő→o, etc.) — accents are
lost on the network-printed receipt, but nothing prints as mojibake. The
browser-print receipt has no such limitation since it's normal HTML/CSS —
use it if accented text on the physical receipt matters to you.

USB-only (non-networked) thermal printers aren't supported directly; either
put a small print server in front of them or rely on the browser-print path
if the OS already has a driver for it.

## Automatic backups (local + Dropbox/Google Drive)

Beállítások → Mentés (linked from every page) configures automatic daily
backups of the database — works the same way regardless of which driver
you're using:

- **Mentés most**: creates a backup immediately, regardless of schedule.
- **Automatikus napi mentés**: enable it and pick a time. On **SQLite**, a
  full snapshot is taken via `VACUUM INTO` (safe even while the app is
  being used, unlike copying the raw file) — output is a `.sqlite` file.
  On **MySQL**, it shells out to `mysqldump` if available (`--single-
  transaction` for a consistent snapshot without locking tables), or
  falls back to a pure-PHP dumper that streams rows to disk if `exec()`
  is disabled — output is a plain `.sql` file either way, restorable with
  `mysql -u ... database < backup.sql`. Like auto-sync, this needs a cron
  job to actually fire while the browser isn't open:

  ```
  */15 * * * * curl -s http://localhost:8000/api/auto-backup-run.php > /dev/null
  ```

  The endpoint itself only runs once per calendar day, at or after the
  configured time — scheduling the cron more often than that is harmless.

- **Megőrzött mentések (retention)**: defaults to **7** — after each backup,
  older ones beyond that count are deleted, both locally (`data/backups/`)
  and in the cloud if a provider is configured. Change it in the same tab.

### Cloud sync

Two optional providers, chosen from the "Felhő szinkronizálás" dropdown:

- **Dropbox** — the simple path. In the [Dropbox App
  Console](https://www.dropbox.com/developers/apps), create an app, then
  under its Permissions tab enable `files.content.write` and
  `files.content.read`, then generate an access token from the app's
  settings page and paste it in. No browser OAuth flow needed for a
  single-install personal use case like this.

- **Google Drive** — no equivalent one-click token, unfortunately; Google
  requires a one-time OAuth2 setup:
  1. In [Google Cloud Console](https://console.cloud.google.com/), create a
     project, enable the **Google Drive API**, and create OAuth 2.0
     credentials of type "Desktop app" — this gives you a Client ID and
     Client Secret.
  2. Get a refresh token once using those credentials — the quickest way is
     [Google's OAuth 2.0 Playground](https://developers.google.com/oauthplayground):
     gear icon → check "Use your own OAuth credentials" → paste your Client
     ID/Secret → in step 1 select the Drive API v3 `drive.file` scope →
     authorize → in step 2 click "Exchange authorization code for tokens" →
     copy the **refresh token** shown.
  3. Paste the Client ID, Client Secret, and refresh token into Beállítások.
     (Optionally set a target folder's Drive ID too — leave blank to upload
     to your Drive root.)

  Access tokens expire hourly; `GoogleDriveProvider` re-exchanges the
  refresh token for a fresh one on every backup, so once this is set up it
  keeps working unless the refresh token itself is revoked.

Only one provider is used at a time (whichever is selected) — this isn't
meant to mirror to both simultaneously.

## Known limitations / things to decide as you go

- VAT rate is stored per product (`vat_rate`, default from config) —
  make sure it matches what's set on the WooCommerce product, since
  WooCommerce's REST API doesn't reliably expose the tax rate value
  itself.
- The buyer on the invoice defaults to a generic "cash customer" —
  wire up a real customer lookup/form if you need named invoices at
  the till.
- No authentication on the local web UI — it's meant to run on a
  single till machine on a trusted local network. Add HTTP basic auth
  in front of it (or bind PHP's server to 127.0.0.1 only) if that's
  not your setup.

## New in this round

- **Import fix**: `.xls`/`.xlsx` files now upload directly — the server
  detects binary Excel files (by file signature, not extension) and
  auto-converts them via `soffice --headless --convert-to csv` if
  LibreOffice is installed. If it isn't, you get a clear error telling
  you to convert manually instead of the old silent-garbage-parse.
- **Irányítószám → Település autofill**: typing a 4-digit irányítószám
  in the "Vevő számlát kér" form fills in the town automatically, from a
  bundled `data/irsz.csv` (3,038 postal codes).
- **NAV cégadat lekérdezés** (best-effort): a "Lekérdezés" button next to
  the adószám field can fill in company name/address via NAV's Online
  Számla `queryTaxpayer` API. **This needs your own NAV technical-user
  credentials** (Beállítások → Számlázz.hu → NAV cégadat lekérdezés) and
  hasn't been tested against a live NAV account — validate against
  `api-test.onlineszamla.nav.gov.hu` before relying on it in production.
  To get credentials: register a free "technikai felhasználó" at
  onlineszamla.nav.gov.hu (Beállítások → Technikai felhasználó
  létrehozása), which gives you a login/password plus signer and
  exchange keys.
- **Settings, not just config.php**: Számlázz.hu and WooCommerce
  credentials can now be set from Beállítások (with a short explanation
  of pull/push/webhook sync in the WooCommerce tab) — they override
  `config.php` when filled in, so you don't have to edit files by hand
  for day-to-day key rotation.
- **Alacsony készlet riasztás**: a global default threshold (Beállítások
  → Készlet riasztás), overridable per product (Árucikkek → Módosítás).
  Products at or below their threshold get a yellow badge in Árucikkek;
  an optional webhook and/or email fires right after a sale that crosses
  the line.
- **Overselling is allowed**: the till no longer blocks a sale for
  insufficient stock — it records the sale, lets stock go negative, and
  flags it in the checkout response and the cart (rows going negative
  are highlighted). Correct it on the next beszerzés.
- **Árucikkek lista**: column headers are now clickable to sort (name,
  cikkszám, csoport, vonalkód, készlet, prices) — click again to reverse.
- **Eladások** and **Beszerzések** are now their own pages: every past
  sale/purchase, searchable by date (native calendar picker), id, and
  name/cégnév, with a click-through detail view.
- **Left icon sidebar** added across every page, alongside the existing
  top nav — Kassza/Beszerzés/Árucikkek/Napi zárás plus your logo and a
  settings shortcut, for faster switching without reading link labels.
- Date fields (Napi zárás, Eladások, Beszerzések) are now native
  `<input type="date">` — click anywhere in the field for a calendar.
- The "Fizetési mód" dropdown (and all `<select>` elements generally) now
  match the app's dark theme instead of using the browser's default style.
- **Világos sablon (light theme)**: Beállítások → Megjelenés lets you switch
  between dark and light. Since colors are defined once as CSS variables
  and referenced everywhere, the light theme is a single override block in
  `style.css` — no per-page styling needed. The choice is saved both to
  `localStorage` (so it applies instantly on the next page load, before
  the stylesheet would otherwise flash dark) and to `data/settings.json`
  (so it's remembered even from a fresh browser profile).
- **Kamerás vonalkód-olvasás**: every barcode field (Kassza, Beszerzés, the
  product edit modal, and the Árucikkek search filter) now has a camera
  icon next to it that opens a live scanner using the browser's built-in
  `BarcodeDetector` API — no external library or CDN dependency. **Browser
  support caveat**: as of early 2026 this API only exists in Chromium
  browsers (Chrome, Edge, Opera, Android WebView) — Safari and Firefox
  don't implement it, so on those the button shows a clear message
  pointing back to manual entry or a USB scanner instead of failing
  silently. It also requires HTTPS or `localhost` (browsers block camera
  access on plain HTTP for any other host) — if you're running the app on
  a LAN IP over HTTP, the camera button won't work there either; the
  manual/USB-scanner input is unaffected either way.

## Beszállító-törzs (supplier master data)

A new `beszallitok.html` page manages saved suppliers (name, contact,
address, tax number, payment terms). On the Beszerzés page, picking a
saved supplier from the new dropdown auto-fills the existing free-text
fields and links `supplier_id` on the purchase record — one-off/unregistered
suppliers still work exactly as before by just typing in the fields directly.

## Törzsvásárlói / hűségpont rendszer (loyalty points)

Off by default — turn it on in Beállítások → Törzsvásárlói pontok, where
you also set the two ratios: how many Ft of spending earns 1 point, and
how much discount 1 point is worth when redeemed. Once enabled:

- The Kassza checkout gets a customer search field (by name or phone).
  Picking a customer shows their point balance and lets the cashier redeem
  some as a discount before finishing the sale; points are then earned on
  whatever's actually paid.
- `vasarlok.html` manages the customer list and shows each customer's full
  point history (every earn/redeem, with the sale it came from).
- **Known limitation**: if a customer both redeems points *and* requests a
  named invoice in the same sale, the Szamlazz.hu invoice is issued for the
  full pre-discount amount, not the reduced total actually charged —
  correctly prorating a discount across mixed VAT rates was judged not
  worth the complexity for what should be a rare combination in practice
  (walk-in loyalty redemptions and named B2B invoices don't usually overlap).

## Rendszerállapot (system status page)

`rendszerallapot.html` pulls together everything that's otherwise spread
across Beállítások tabs and the sync log into one glance: last WooCommerce
sync and backup times/results, counts of low-stock products and recent
invoice failures, whether the printer/Szamlazz.hu/loyalty features are
configured, and the last 20 sync-log entries (failures highlighted).

## Telepíthető mobil-app (PWA)

The app is installable — "Add to Home Screen" on mobile, or the install
icon in Chrome/Edge's address bar on desktop — via a manifest and service
worker (`manifest.json`, `sw.js`). What this does and doesn't give you:

- **Does**: an app icon, a standalone window (no browser chrome), and the
  UI shell (HTML/CSS/JS) loads instantly from cache even on a flaky
  connection.
- **Doesn't**: work fully offline for actual tills use. Product prices,
  stock levels, and every write (sales, purchases, sync) still need the
  server — the service worker deliberately never caches `/api/` responses,
  since showing a stale price or stock count at checkout would be worse
  than a clear "you're offline" failure. If the network drops mid-shift,
  the app shell still opens, but ringing up a sale won't work until it's
  back.
- Like the camera scanner, installability itself requires HTTPS or
  `localhost` — browsers won't register a service worker over plain HTTP
  on a LAN IP.

## Vásárlói törzs az invoice form-on (Kassza)

The `customers` table (already used for loyalty points) now also stores
billing details (zip, city, address, country), so it doubles as an
address book for invoices. On the "Vevő számlát kér" form:

- Typing in **Név / Cégnév** live-searches saved customers and shows
  matches below the field — picking one fills in the address, tax number,
  and switches to "céges" automatically if the customer has a tax number.
- The icon next to the field opens a full picker: every saved customer,
  searchable, with **Kiválasztás** (fill the form) and **Szerkesztés**
  (edit their saved details) per row, plus **+ Új vásárló** to save a new
  one — pre-filled from whatever's already typed in Név / Cégnév.
- This is the same customer list managed on `vasarlok.html` — editing or
  adding someone here updates that list too, and vice versa.

## Kézi tétel hozzáadása eladáskor

The Kassza has a "+ Kézi tétel hozzáadása" button (below the product
search) for something that isn't in the inventory at all — a service, a
delivery fee, a one-off item — but still needs to be on the customer's
receipt or invoice. A manual item:

- Has its own name, quantity, gross unit price, and VAT rate, entered
  directly at the till.
- Appears in the cart and counts toward the total exactly like a normal
  product line.
- Is saved on the sale and included on the Szamlazz.hu invoice / printed
  receipt the same way a real product line is.
- Does **not** touch stock or WooCommerce in any way — there's no product
  behind it to update.
- This needed `sale_items.product_id` to become nullable. MySQL supports
  that as a direct `ALTER TABLE ... MODIFY COLUMN`; SQLite doesn't support
  relaxing a `NOT NULL` constraint via `ALTER TABLE` at all, so upgrading
  an existing SQLite database rebuilds the `sale_items` table (copy →
  drop → rename) — the standard, documented way to do this in SQLite. This
  runs automatically and once, the first time the app starts after
  updating.

## Telepítő (first-run installer)

Opening the app for the first time redirects to `install.php` — a small,
**skippable** wizard, not a hard requirement. Since SQLite already works
with zero configuration, the installer exists for two things:

- **Choosing MySQL instead of SQLite**, with a form for host/port/database/
  credentials instead of hand-editing `config/config.php`. It tests the
  connection and creates the database (`CREATE DATABASE IF NOT EXISTS`) if
  it doesn't exist yet, before writing anything.
- **Setting the shop name/address** shown on receipts, without opening a
  code editor.

Whichever path you take (or "Kihagyás" to skip entirely and keep the
SQLite default), it writes `config/installer-generated.php` — config.php
merges this in automatically if present — and creates `data/.installed`
so the wizard never shows again. Deleting that marker file would make it
reappear, but there's normally no reason to.

**Upgrading from a version without the installer**: nothing changes for
you. Every page checks install status via a fast local file check before
anything else loads; if a working SQLite database file is already there
(or you've already hand-edited config.php), it's automatically treated as
already installed rather than interrupting a working setup.

**Why it lives at `webroot/install.php` and not the project root**: only
`webroot/` is served by the web server — `config/`, `src/`, `data/`, and
`schema*.sql` are deliberately outside it so they're never reachable by a
direct URL. The installer needs to be reachable, so it has to live inside
`webroot/` alongside `index.html`, even though what it's setting up
(`config/`, `data/`) lives one level up.

## Kedvezménykód / kupon

`kedvezmenyek.html` manages coupons — a code, a discount (percent or fixed
Ft), and optional rules (expiry date, usage limit, minimum purchase). On
the Kassza, entering a code validates and applies it live; the discount
applies against the subtotal, before loyalty points or a gift card.

## Ajándékutalvány

Same page, second tab. Unlike a coupon, a gift card carries a **balance**
rather than a one-time discount — issue one with a starting amount, and it
can be spent across multiple purchases until the balance reaches zero.
Redeeming one at the till covers as much of the remaining total as the
balance allows (after coupon and loyalty discounts are already applied),
and its full transaction history (issued, each redemption) is visible from
its row in the list.

**Discount order at checkout**: coupon → loyalty points → gift card. Each
is re-validated server-side at the moment of sale — not trusted from
whatever the till UI already showed — since this is where money actually
changes hands. Same known limitation as loyalty points: if any of these
combine with a requested named invoice in the same sale, the Szamlazz.hu
invoice is issued for the full pre-discount amount (prorating a discount
across mixed VAT rates was judged not worth the complexity for what
should be an uncommon combination in practice).

## Ártörténet

Every time a product's net or gross price changes (via the edit modal —
not via CSV import, which would otherwise flood this with bulk-import
noise), the old and new values are logged. Reopening that product's edit
modal shows the history right there, no separate page needed.

## Vonalkód-generálás + címke nyomtatás

The product edit modal has a **Generálás** button that fills the barcode
field with a fresh, valid EAN-13 code — using the "20" prefix, which GS1
reserves for internal/in-store use, so a generated code can never collide
with a real manufacturer's actual barcode later. **Címke nyomtatása** opens
a small printable label (name, price, and a scannable barcode) in a new
window. The barcode itself is rendered as SVG by `ean13.js`, a from-scratch
implementation of the EAN-13 encoding tables — no external library or CDN
dependency, consistent with the camera scanner and PWA features.

## Mentés-visszaállítás (backup restore)

Beállítások → Mentés now has a **Visszaállítás** button next to every
listed local backup, plus a file upload for restoring a backup from
somewhere else (another machine, a cloud download). Either way:

- A fresh safety backup of the **current** live data is taken automatically
  before anything is touched — so a restore is itself undoable if it turns
  out to be the wrong file.
- SQLite: the uploaded/selected file is opened and sanity-checked (a real
  SQLite file, not corrupt or unrelated) before it replaces the live
  database file.
- MySQL: prefers the `mysql` CLI binary to run the dump (matches how
  backups themselves prefer `mysqldump`); falls back to executing the
  dump's statements one by one via PHP if the CLI isn't available (common
  on shared hosting).
- There's a confirmation dialog before either restore path proceeds —
  this action overwrites live data.

## Részleges visszáru / sztornó

On an Eladások sale's detail view, "Visszáru rögzítése" reveals a quantity
input per line, capped at however much of that line hasn't already been
returned (so a second partial return on the same sale can't over-return).
Confirming restores stock for the returned items and logs the return.

**Known limitation**: the `sales` table only stores the buyer's name, not
the full billing address/tax number the original Szamlazz.hu invoice
used — so a credit note is never auto-generated. If the original sale had
an invoice, the return screen just surfaces that invoice number so you can
issue the credit note manually on Szamlazz.hu, referencing it. A
`SzamlazzClient::createCreditNote()` method exists for future use if full
buyer billing details ever get stored on the sale record, but it's
untested against Szamlazz.hu's actual credit-note behavior — verify it
against current documentation before relying on it.

## Több felhasználó / PIN-kód

`staff.html` manages staff members (name + a 4-8 digit PIN, hashed with
`password_hash`). The Kassza topbar shows who's logged in — clicking it
opens a PIN prompt; the logged-in staff member is remembered in
`localStorage` (not a real session) and attached to every sale from then
on. This is meant for accountability (who rang up what), not real access
control — anyone can open the login prompt and pick a different name if
they know a PIN, same as most small-shop till setups.

## Leltározás

`leltar.html` starts a stock take by snapshotting every active product's
current stock as the "expected" count, then lets you enter a counted
quantity per product (search to narrow a long list), showing the
difference live. Closing it optionally applies the counted quantities as
corrections to live stock — or just records the discrepancy report without
touching stock, if you leave that unchecked.

## Kimutatás / Export CSV

Eladások, Beszerzések, and Napi zárás each have an "Export CSV" button
that respects whatever filters are currently applied (date, ID, search).
The file includes a UTF-8 BOM so Excel on Windows detects the encoding
correctly instead of mangling accented Hungarian characters.

## Dashboard grafikonokkal

Rendszerállapot now has a revenue trend chart (14/30/90 days) — a plain
SVG bar chart built from scratch in `rendszerallapot.js`, no charting
library, consistent with the EAN-13 barcode renderer's philosophy. Hover
over a bar for that day's exact total and sale count.

## Digitális nyugta e-mailben

After a sale, the receipt panel has an email field (pre-filled from the
selected törzsvásárló's saved email, if any) and a "E-mail küldése"
button that sends an HTML version of the receipt.

**Important**: this uses PHP's built-in `mail()` function — no SMTP
library, no external dependency, consistent with the rest of the app. But
`mail()` only works if the server has a configured mail transport
(sendmail/postfix, common on real shared hosting). It will **not** work
out of the box on `php -S` local development or most fresh VPS installs
without separately setting up mail — the endpoint returns a clear error
explaining this rather than silently failing when `mail()` reports
failure.

## Dolgozói jogszintek

Staff members now have a role (Eladó/cashier or Vezető/admin) set on
`staff.html`. This stays an accountability tool, not a real access-control
system — as documented earlier, anyone can open the PIN prompt and pick a
different name. What's real: **product deletion is enforced server-side**
in `product-save.php` — if a staff member is logged in and isn't an admin,
the request is rejected (403), regardless of what the UI shows. If no
staff is logged in at all (PIN feature unused), this stays permissive.

## Tevékenységnapló (audit log)

`audit-log.html` shows logged actions (currently: product deletions,
with room to extend to other actions later) with who did it and when.
Retention defaults to 30 days and is configurable in Beállítások →
Tevékenységnapló — older entries are pruned automatically on the next
write, no separate cron needed.

## Hűségszintek (loyalty tiers)

On top of the existing point system, customers now also get an automatic
percentage discount based on lifetime spend (`customers.total_spent`,
tracked on every completed sale) — Bronze (no discount) → Ezüst → Arany,
with thresholds and discount percentages configurable in Beállítások →
Törzsvásárlói pontok. Applied automatically after coupon and point
discounts, before a gift card. `vasarlok.html` shows each customer's
current tier.

## Globális kereső (Ctrl+K)

Press **Ctrl+K** (or click the search icon) on any page to search
products, customers, and sales at once. This is injected into every
page's topbar from `topbar.js` rather than being added to each page's
HTML individually — `.topbar-actions` already exists consistently across
pages, so this stays a one-file change instead of touching ~15 pages.

## Értesítési központ (notification center)

A bell icon next to the search icon (same injection approach) shows a
badge when there's something to look at — low stock, sync failures,
invoice failures — pulled from the same data `rendszerallapot.html`
already surfaces. Clicking an alert jumps to the relevant page.

## Mobil UI-átvizsgálás és javítások

A teljes felület átnézésre került mobil nézetre, és a következő valós
hibák kerültek javításra:

- **Az oldalsáv fixen 72px-et foglalt keskeny képernyőn is** — most
  768px alatt eltűnik, a fejléc navigációja és az értesítési harang
  továbbra is elérhetővé teszi a legfontosabb oldalakat.
- **A Kassza fő elrendezése** (`380px + 1fr` oszlopok) összenyomódott
  vagy kifolyt volna keskeny képernyőn — 900px alatt egy oszlopba esik.
- **A `.field-row` (páros mezők, pl. irányítószám/település)** fixen
  2 oszlopos volt mindenhol az appban — 480px alatt egy oszlopba esik.
- **9 oldal táblázata** (Kedvezmények, Dolgozók, Leltár, Napló,
  Beszállítók, Vásárlók, Eladások, Beszerzések, Napi zárás) nem volt
  vízszintesen görgethető konténerbe csomagolva — mobilon ez az egész
  oldal vízszintes görgetését okozta volna sok oszlopos táblázatoknál.
- **A `.products-toolbar`** (keresőmező + gomb) most tördelődik 640px
  alatt, ahelyett hogy összenyomódna.
- **iOS Safari zoom-hiba**: 6 helyen volt 14-15px-es betűméret input/
  select/textarea elemeken — ez fókuszáláskor automatikus nagyítást vált
  ki iOS-en. Mind 16px-re javítva.
- Több inline `display:flex` sor (input+gomb kombináció adószám-
  lekérdezésnél, kupon/utalvány kódnál, leltár-lezárásnál) nem
  tördelődött — most `flex-wrap` és megfelelő `flex-basis` értékekkel
  biztonságosan tördelődnek keskeny képernyőn.
- Az újonnan épített értesítési dropdown fix szélessége/pozicionálása
  túlfuthatott volna keskeny telefonon — `min()` CSS függvénnyel
  garantáltan a viewport szélességén belül marad.

**Amit ez a kör nem fedett le**: a demó fájl (`stock-manager-demo.html`)
nem lett átvizsgálva ebben a körben, illetve funkcionális (nem UI/CSS)
hibák tesztelése sem történt.

## Rövid beépített útmutató

`utmutato.html` — a fő munkafolyamatok rövid, statikus leírása. Egy "?"
súgó ikon nyílik meg rá minden oldal fejlécéből (`topbar.js`-ből
injektálva, mint a kereső és az értesítési harang).

## QR-kód a nyomtatott nyugtán

A `receipt.html` most egy QR-kódot is tartalmaz, ami visszamutat magára a
nyugtára — a vásárló ezt beszkennelve digitálisan is elmentheti, e-mail
küldés (és így szerver-oldali levelezés-konfiguráció) nélkül.

**Tervezési döntés**: saját QR-enkóder helyett egy külső, publikus
QR-generáló képszolgáltatást (`api.qrserver.com`) használ. Egy hibás saját
implementáció (a QR hibajavítás/maszkolás sokkal összetettebb, mint az
EAN-13 vonalkód kódtáblája volt) egy nem-olvasható kódot eredményezhetne —
ez rosszabb, mint egyáltalán nem mutatni QR-kódot. Ha nincs internet a
nyugta megnyitásakor, a kép csendben eltűnik, nem dob hibát.

**Fontos korlátozás**: a QR-kód a nyugta oldalának **aktuális URL-jére**
mutat. Ha az app `localhost`-on vagy egy csak a kassza gépéről elérhető
címen fut, a vásárló telefonja nem fogja tudni megnyitni a linket — ehhez
az appnak egy ténylegesen kívülről is elérhető domainen, vagy legalább a
bolt Wi-Fi hálózatán belül mindkét fél számára elérhető IP-címen/porton
kell futnia.

## Automatikus beszerzési javaslat generálás

`beszerzesi-javaslat.html` (Rendszerállapotról linkelve) az alacsony
készletű termékeket a termék-szerkesztőben beállítható **preferált
beszállító** szerint csoportosítva mutatja, egy egyszerű javasolt
mennyiséggel (a küszöb duplájára tölti fel — nem valódi keresleti
előrejelzés, de jó kiindulópont). A "Beszerzés indítása ezzel a
beszállítóval" gomb átviszi a kiválasztott tételeket és mennyiségeket a
Beszerzés oldalra (a beszállító is automatikusan kiválasztva), ahol
tovább szerkeszthetők a tényleges rögzítés előtt.

## Több telephely / raktár kezelése

`telephelyek.html` — telephelyek felvétele, és termékenkénti
készletmozgatás köztük. **Fontos tervezési döntés**: `products.stock_qty`
marad az ELSŐDLEGES, összesített mennyiség, amit minden más funkció
(WooCommerce szinkron, alacsony készlet riasztás, leltározás stb.)
változatlanul használ — a telephelyenkénti bontás egy kiegészítő réteg
(`location_stock` tábla) felette. Ez azt jelenti:

- **Egytelephelyes boltoknál semmi nem változik** — ha nincs felvéve
  telephely, a Kasszán meg sem jelenik a telephely-választó, és minden
  pontosan úgy működik, mint korábban.
- Ha van felvéve legalább egy telephely, a Kasszán megjelenik egy
  választó — a kiválasztott telephely készlete is csökken eladáskor, az
  összesített mennyiség mellett (nem helyette).
- A telephelyek közti mozgatás nem érinti az összesített mennyiséget,
  csak a megoszlást.

## Ügyféllista (bővített vásárlói profil)

`vasarlok.html` mostantól saját oldalsáv-ikonnal elérhető menüpont (nem
csak kontextusból, a Kasszáról linkelve). A vásárló-szerkesztő modal
fülekre bontva:

- **Adatok** — a korábbi szerkesztő űrlap, változatlanul.
- **Statisztika** — hűségszint, összes elköltés, vásárlások száma,
  átlagos kosárérték, **első és utolsó vásárlás dátuma**, plusz a teljes
  pontelőzmény.
- **Vásárolt tételek** — az összes valaha megvásárolt tétel listája,
  dátum szerint csökkenő sorrendben.

Az "Adatok" fül mindig elérhető (új vásárló felvételéhez is kell); a
"Statisztika" és "Vásárolt tételek" fülek csak meglévő vásárlónál
jelennek meg, hiszen új vásárlónak még nincs előzménye.

## Teljes átvizsgálás — kód, adatbázis, reszponzív UI (2026-08-30)

**Adatbázis-teljesítmény**: a SQLite séma **10 indexet** nem tartalmazott,
amit a MySQL séma igen — köztük a `sales.customer_id`-t, amire az
Ügyféllista statisztika-lekérdezései (`getCustomerStats`,
`getCustomerPurchasedItems`) épülnek. Nagyobb adatbázisnál ez lassú,
teljes tábla-vizsgálatot okozott volna minden vásárló-részlet
megnyitásakor. Pótolva mindkét helyen (friss telepítés + migráció a
schema_version 11-es lépésével).

**Adatbázis-konzisztencia**: mind a 22 tábla oszlopai ellenőrizve és
megerősítve — pontosan egyeznek SQLite és MySQL között.

**Reszponzív UI**: néhány további `display:flex` fejléc-sor (pl.
Rendszerállapot "Bevétel trend" fejléce a napszám-választóval) kapott
biztonsági `flex-wrap`-ot a korábbi körökben már alkalmazott mintát
követve, a legkeskenyebb telefonokon esetlegesen szoros illeszkedés
elkerülésére.

## Biztonság

**A valódi védelem az, hogy minden adat és minden művelet kizárólag az
API-n keresztül érhető el.** Ez az alapréteg, ami minden más fölött áll:
minden `api/*.php` végpont (a `_bootstrap.php`-n keresztül) elutasít
minden kérést bejelentkezés nélkül. Mivel minden adat és minden művelet
kizárólag ezen az API-n keresztül érhető el, még ha valaki bármilyen úton
hozzáférne egy oldal HTML/JS forrásához, tényleges adathoz vagy
funkcióhoz nem férne hozzá — a felület önmagában üres, működés nélküli.

Erre a réteg tetejére épül két további védelem:

1. **Kliens-oldali JS** (`topbar.js`) azonnal átirányít a `login.html`
   oldalra, ha nincs érvényes munkamenet — ez a felhasználói élményt
   szolgálja (gyors, egyértelmű átirányítás egy hibaüzenet helyett).
2. **Valódi oldal-szintű védelem**: mind a 17 dolgozói oldal ténylegesen
   PHP-fájl (nem statikus HTML), aminek a legelején egy szerver-oldali
   ellenőrzés fut le — ha nincs érvényes munkamenet, a szerver
   *egyáltalán nem küldi ki* az oldal tartalmát (lásd lentebb, "Valódi
   oldal-szintű védelem" szakasz).

Ez a rétegzés azt jelenti: még ha valamelyik réteg valamiért kimaradna
vagy hibásan működne, a másik kettő önmagában is elegendő védelmet ad.

### Bejelentkezés (opcionális, kikapcsolható)

Beállítások → Biztonság fülön kapcsolható be egy alkalmazás-szintű jelszó
(alapból ki van kapcsolva — bekapcsolása után minden oldal bejelentkezést
kér). Ez **különbözik a dolgozói PIN-kódtól**: az csak elszámoltat (ki
dolgozott a Kasszánál), ez itt a teljes programhoz való hozzáférést zárja.

- Jelszó `password_hash()`-sel tárolva, sosem kerül vissza a kliensnek
  (a `settings.php` és minden más végpont explicit módon kiszűri).
- Session-cookie `HttpOnly` + `SameSite=Strict` — ez jelentősen csökkenti
  a CSRF-kockázatot anélkül, hogy minden POST-kérésbe tokent kellene
  fűzni. (Egy `Auth::csrfToken()`/`verifyCsrf()` pár is elérhető jövőbeli,
  token-alapú védelemhez, ha valaha szükség lenne rá.)
- Automatikus kijelentkezés beállítható inaktivitás után (alapból 4 óra).
- **Rate limiting** mind az alkalmazás-jelszóra, mind a dolgozói PIN-re —
  túl sok sikertelen próbálkozás után ideiglenes zárolás (fájl-alapú
  számláló, nincs szükség extra adatbázis-táblához).

**A korábbi kompromisszum feloldva**: a nyomtatott nyugtákon lévő QR-kód
mostantól bejelentkezés nélkül is biztonságosan megtekinthető, mert egy
titkos, kitalálhatatlan tokent tartalmaz (lásd lentebb, "Titkos
nyugta-token" szakasz) — nem a kitalálható eladás-sorszámra támaszkodik.

### HTTP biztonsági fejlécek

Minden API-válasz tartalmazza: `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy: same-origin`.

### XSS-védelem

Az adatbázisból származó szöveg (termék/vevő/beszállító név, jegyzetek,
kupon-kódok stb.) — ami importból, WooCommerce-ből vagy bármely
dolgozótól származhat — mindenhol egy megosztott `escapeHtml()`
függvényen megy át, mielőtt `innerHTML`-be kerülne. Ez tárolt XSS ellen
véd: egy rosszindulatú vagy hibás adat (pl. egy termék neve
`<script>`-tartalommal egy import-fájlból) nem futtatható kódként jelenik
meg, csak szövegként.

### Fájlfeltöltés

- **Logó-feltöltés**: valódi (nem a kliens által állított) MIME-típus
  ellenőrzés `finfo`-val, whitelistelt formátumok (PNG/JPG/WEBP/SVG),
  2 MB-os méretkorlát, szerver-generált fájlnév (nincs path traversal
  vagy tetszőleges fájlnév-kockázat).
- **Mentés-visszaállítás**: `basename()` védelem a fájlnév-paraméterre,
  `is_uploaded_file()` ellenőrzés a feltöltött fájlra, a visszaállítás
  előtt mindig automatikus biztonsági mentés készül.
- **CSV-import**: véletlenszerű, szerver-generált fájlnév (nincs
  köze a feltöltött fájl eredeti nevéhez), a LibreOffice-konverzió
  minden paramétere `escapeshellarg()`-gal védett shell-injekció ellen.

### Valódi oldal-szintű védelem (nem csak API-szintű)

A korábbi verzióban minden oldal statikus `.html` fájl volt, amit a
webszerver PHP-futtatás nélkül, közvetlenül kiszolgált — emiatt a
bejelentkezés-kényszer csak API-szinten érvényesült (a HTML/JS "váz"
maga mindig kiment, csak funkcionálisan volt üres bejelentkezés nélkül).

Ez mostantól más: **mind a 17 dolgozói oldal (`index.php`,
`termekek.php`, `beallitasok.php` stb.) valódi PHP-fájl**, aminek a
legelején egy szerver-oldali ellenőrzés fut le — ha nincs érvényes
munkamenet, a szerver **egyáltalán nem küldi ki az oldal tartalmát**,
hanem azonnal átirányít a bejelentkező oldalra. Ez egy valódi védelmi
réteg a korábbi, csak-API-szintű megoldás fölött.

**Ami szándékosan kivétel maradt**: `login.html` (magának a bejelentkező
oldalnak nyilvánosan elérhetőnek kell lennie), `install.php` (az
első-indításos telepítő, ami a jelszó beállítása előtt fut le),
`receipt.html` és `label-print.html` (lásd alább).

### Titkos nyugta-token (a QR-kód kompromisszum feloldása)

Minden eladáshoz egy kitalálhatatlan, véletlenszerű token generálódik
(`sales.receipt_token`). A nyomtatott nyugta QR-kódja ezt a tokent is
tartalmazza a linkben — így a `receipt.html` **bejelentkezés nélkül is
biztonságosan megtekinthető**, mert nem az eladás sorszámán (ami
kitalálható lenne), hanem ezen a titkos tokenen keresztül azonosítja
magát. Bejelentkezett dolgozó továbbra is token nélkül, közvetlenül a
munkamenetén keresztül férhet hozzá bármelyik nyugtához.

### Ami nem ebben a körben lett megoldva

Egy teljes, token-alapú CSRF-védelem (minden POST-kérésbe fűzött egyedi
token) nem került bevezetésre — ehelyett a `SameSite=Strict`
session-cookie adja a gyakorlati védelmet, mivel több tucat meglévő
API-hívás módosítása jelentős kockázattal járt volna egy ilyen nagy
kódbázisban. Az `Auth` osztály tartalmazza a szükséges építőelemeket
(`csrfToken()`, `verifyCsrf()`), ha valaha szükség lenne rá.
