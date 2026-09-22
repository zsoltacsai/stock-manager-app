<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Budapest');

// Biztonsági HTTP-fejlécek — minden API-válaszra vonatkoznak.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Content-Type: application/json; charset=utf-8');

// 1.1.1 — globális, központi "biztonsági háló" MINDEN API-végpontra: eddig
// az egyes végpontoknak EGYENKÉNT kellett try/catch-csel körbevenniük minden
// kockázatos hívást (van, amelyik ezt elmulasztotta, pl. product-save.php
// $db->saveProduct()-hívása, settings.php $settings->save()/read()-je) —
// egy ott elszabaduló, el nem kapott Throwable/fatal hiba a PHP alapértelmezett
// hibakezelőjéhez jutott volna, aminek a viselkedése (HTML-formázott hibaüzenet,
// esetleg fájlelérési úttal) KIZÁRÓLAG a szerver `display_errors` beállításától
// függ — amit ez az alkalmazás eddig SOHASEM állított be explicit módon,
// tehát a tényleges viselkedés a hoszt saját PHP-konfigurációjának (fejlesztői
// szerver, megosztott tárhely stb.) volt kiszolgáltatva. Ez itt EXPLICIT
// kikapcsolja a hibák kliens felé történő megjelenítését, és minden, egyébként
// el nem kapott hibát egységes, secret nélküli JSON-válaszra fordít — az
// 'error'-log-ba viszont a teljes részlet bekerül, diagnosztikai célra.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function send_generic_server_error(): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Váratlan szerverhiba történt. Próbáld újra, vagy értesítsd az üzemeltetőt.'], JSON_UNESCAPED_UNICODE);
}

/**
 * Ugyanaz a generikus üzenet, mint send_generic_server_error(), de egy
 * VÉGPONT SAJÁT, lokális catch blokkjából hívva (nem a globális kivétel-
 * kezelőből, ami az el nem kapott hibákat már eddig is így kezelte).
 * Regresszió (1.3.1 release-gate audit): több végpont korábban közvetlenül
 * $e->getMessage()-t adott vissza a kliensnek — ez konkrétan, reprodukálhatóan
 * SQL/séma-töredéket (pl. "UNIQUE constraint failed: coupons.code") vagy
 * szerver-oldali fájlrendszer-elérési utat (pl. mentés-fájlok elérési útja)
 * tartalmazhatott. A valódi kivétel részlete MOST is bekerül a szerver
 * naplójába (diagnosztikai célra), csak a kliens felé nem szivárog ki nyersen.
 */
function send_generic_error_response(Throwable $e, string $context, int $status = 500): void
{
    error_log('[fountaintrade] ' . $context . ': ' . get_class($e) . ': ' . $e->getMessage());
    send_json(['error' => 'Váratlan szerverhiba történt. Próbáld újra, vagy értesítsd az üzemeltetőt.'], $status);
}

set_exception_handler(function (Throwable $e): void {
    error_log('[fountaintrade] Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    send_generic_server_error();
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    // Csak a TÉNYLEGESEN végzetes hibatípusok (nem pl. egy figyelmen kívül
    // hagyható E_WARNING/E_DEPRECATED, ami minden kérés végén lefutna emiatt
    // a shutdown-handleren) — ezek azok, amiket set_exception_handler() NEM
    // fog el (nem Throwable-ként megjelenő, klasszikus PHP fatal hibák).
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[fountaintrade] Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        send_generic_server_error();
    }
});

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/WooCommerceClient.php';
require_once __DIR__ . '/../../src/SzamlazzClient.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/Auth.php';

$config = require __DIR__ . '/../../config/config.php';

// Fázis 2 — kliens/szerver architektúra. EZ a teljes Kliens-módú kódútvonal:
// ha node_role==='client', a kérés a Szerverre kerül továbbításra, MIELŐTT
// bármi más itt lentebb lefutna (Settings::read(), GeoBlocker, a helyi
// bejelentkezés-ellenőrzés, a CSRF-ellenőrzés, vagy a new Database()) — ezek
// mind a HELYI (Kliens-oldali) állapotra vonatkoznának, ami egy Kliensnél
// vagy nem is létezik (nincs helyi adatbázis), vagy nem a tényleges döntést
// hozza (a valódi bejelentkezés/CSRF/jogosultság a Szerveren dől el, lásd a
// Fázis 2 tervdokumentum §5 Authentication design szakaszát). Egyetlen
// végpont-fájl és egyetlen src/*.php üzleti logika SEM kap "if clientMode"
// elágazást — ez az EGYETLEN hely, ahol a döntés megtörténik.
//
// node_role !== 'client' esetén ez az egész blokk no-op — a meglévő
// Standalone/Szerver viselkedés bit-pontosan változatlan.
if (($config['node_role'] ?? 'standalone') === 'client') {
    require_once __DIR__ . '/../../src/ClientProxy.php';
    (new ClientProxy($config['client'] ?? []))->forward();
    exit; // a forward() már elküldte a teljes választ — ide sosem jutunk el ténylegesen
}

// A Beállítások alatt mentett értékek (Számlázz.hu / WooCommerce fülek)
// felülírják a config.php statikus értékeit, ha be vannak állítva, így
// minden végpont, ami SzamlazzClient/WooCommerceClient-et épít, automatikusan
// ezeket kapja meg.
//
// "Fail closed": Settings::read() SZÁNDÉKOSAN kivételt dob, ha a
// settings.json LÉTEZIK, de nem olvasható/sérült — sose eshet vissza
// csendben az alapértelmezésekre, mert azok között van app_password_enabled
// => false is, ami egy már jelszóval védett telepítésen egy átmeneti
// olvasási hiba idejére mindenkit "bejelentkezettnek" mutatna. Itt,
// a VALÓDI védelmi rétegben (lásd lejjebb) ezt elkapva egyértelműen
// elutasítjuk a kérést, nem folytatjuk tovább.
try {
    $appSettings = (new Settings(__DIR__ . '/../../data/settings.json'))->read();
} catch (Throwable $e) {
    error_log('[fountaintrade] Settings::read() failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'A rendszer beállításai jelenleg nem olvashatók. Próbáld újra, vagy értesítsd az üzemeltetőt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// IP-cím / ország alapú korlátozás — még a bejelentkezés-ellenőrzés előtt fut,
// hogy egy nem engedélyezett országból/IP-ről semmilyen API-végpont (a
// bejelentkezés sem) ne legyen elérhető.
require_once __DIR__ . '/../../src/GeoBlocker.php';
$geoCheck = GeoBlocker::check($appSettings);
if (!$geoCheck['allowed']) {
    http_response_code(403);
    echo json_encode(['error' => 'Ez a rendszer erről az IP-címről/országból nem érhető el.', 'geo_blocked' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bejelentkezés-ellenőrzés — minden API-végpontra vonatkozik, kivéve a
// bejelentkezéshez és a telepítő-állapot lekérdezéséhez szükséges pár
// végpontot (ezeknek működniük kell MIELŐTT valaki be van jelentkezve).
// Ez a VALÓDI védelmi réteg: mivel minden adat és minden művelet
// kizárólag ezen az API-n keresztül érhető el, a statikus HTML-oldalak
// megtekintése önmagában nem tesz elérhetővé semmilyen valós adatot.
// Bejelentkezés előtt is elérhető végpontok. logout.php SZÁNDÉKOSAN itt
// marad (egy már lejárt/érvénytelen session-en is engedje a kijelentkezést,
// ami gyakorlatilag no-op), DE — a lenti CSRF-ellenőrzésnél — logout.php
// már NEM kap kivételt, lásd $csrfWhitelist.
$authWhitelist = ['login.php', 'logout.php', 'auth-status.php', 'install-status.php', 'receipt-detail.php', 'webhook.php'];
$csrfWhitelist = ['login.php', 'auth-status.php', 'install-status.php', 'receipt-detail.php', 'webhook.php'];
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Az automatikus mentés/szinkron (auto-backup-run.php, auto-sync-run.php)
// dokumentáltan egy rendszer cron bejegyzésről indul (lásd README/telepítési
// útmutatók), tehát session-sütire épülő bejelentkezés-ellenőrzéssel sose
// tudna lefutni — cron nem tud böngészőben bejelentkezni. Ezek a végpontok
// KIZÁRÓLAG egy dedikált, megosztott titkot fogadnak el — SOSE a normál
// böngésző-session-t (még akkor sem, ha épp be van jelentkezve valaki) —,
// hogy egy ellopott böngésző-session ne tudja ezt az (admin-jogszint
// nélküli, teljes katalógus-felülírásra/mentésre képes) utat is
// felhasználni, és fordítva. A token KIZÁRÓLAG az X-Cron-Token fejlécben
// fogadott el — SOSE query-stringben —, mert egy URL-be írt titok
// szerver-/proxy-naplókba, böngésző-előzményekbe kerülhet.
$cronScripts = ['auto-backup-run.php', 'auto-sync-run.php', 'nav-queue-run.php', 'nav-incoming-sync-run.php', 'update-check-run.php', 'wc-queue-run.php'];
$isCronScript = in_array($currentScript, $cronScripts, true);

if ($isCronScript) {
    $suppliedToken = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    $cronAuthorized = !empty($appSettings['cron_secret'])
        && $suppliedToken !== ''
        && hash_equals((string) $appSettings['cron_secret'], $suppliedToken);
    if (!$cronAuthorized) {
        http_response_code(401);
        echo json_encode(['error' => 'Érvénytelen vagy hiányzó cron-token (X-Cron-Token fejléc).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} elseif (!in_array($currentScript, $authWhitelist, true) && !Auth::isLoggedIn($appSettings)) {
    http_response_code(401);
    echo json_encode(['error' => 'Bejelentkezés szükséges.', 'auth_required' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF-védelem — a session-cookie SameSite=Strict már önmagában is erős
// védelem (lásd Auth::ensureSession()), de a szerver eddig sose ellenőrizte
// ténylegesen a már meglévő, minden bejelentkezéskor kiadott csrf_token-t
// (a kliens oldal — topbar.js window.smCsrfToken — le is kérte, csak sose
// küldte vissza egyetlen híváshoz sem). Ez itt a második, ténylegesen
// kikényszerített védelmi réteg minden állapotváltoztató (POST) hívásra —
// a $csrfWhitelist-en (bejelentkezés előtti/webhook végpontok) és a
// cron-tokennel hitelesített kéréseken kívül MINDENRE, logout.php-t is
// beleértve — ezeknél nincs (vagy nem böngésző-session-alapú) a védendő
// állapot.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($currentScript, $csrfWhitelist, true) && !$isCronScript) {
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Auth::verifyCsrf($csrfHeader)) {
        http_response_code(403);
        echo json_encode(['error' => 'Érvénytelen vagy hiányzó CSRF-token — töltsd újra az oldalt.', 'csrf_required' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!empty($appSettings['szamlazz_agent_key'])) {
    $config['szamlazz']['agent_key'] = $appSettings['szamlazz_agent_key'];
}
if (!empty($appSettings['szamlazz_default_payment'])) {
    $config['szamlazz']['payment_method'] = $appSettings['szamlazz_default_payment'];
}
if (!empty($appSettings['szamlazz_default_vat'])) {
    $config['szamlazz']['default_vat_rate'] = $appSettings['szamlazz_default_vat'];
}
$config['szamlazz']['send_email'] = !empty($appSettings['szamlazz_send_email']);

if (!empty($appSettings['wc_store_url'])) {
    $config['woocommerce']['store_url'] = $appSettings['wc_store_url'];
}
if (!empty($appSettings['wc_consumer_key'])) {
    $config['woocommerce']['consumer_key'] = $appSettings['wc_consumer_key'];
}
if (!empty($appSettings['wc_consumer_secret'])) {
    $config['woocommerce']['consumer_secret'] = $appSettings['wc_consumer_secret'];
}
if (!empty($appSettings['wc_barcode_source'])) {
    $config['woocommerce']['barcode_source'] = $appSettings['wc_barcode_source'];
}
if (!empty($appSettings['wc_barcode_meta_key'])) {
    $config['woocommerce']['barcode_meta_key'] = $appSettings['wc_barcode_meta_key'];
}
if (!empty($appSettings['wc_webhook_secret'])) {
    $config['woocommerce']['webhook_secret'] = $appSettings['wc_webhook_secret'];
}

$db = new Database($config['db'], __DIR__ . '/../..');

// Karbantartási mód (12. pont) — az UpdateInstaller kapcsolja be a
// frissítés telepítési szakaszára (lásd src/UpdateInstaller.php
// setMaintenanceMode()). Egy nem-admin (vagy be sem jelentkezett) kérés
// minden nem-fehérlistás, nem-cron végpontra 503-at kap — a "recovery/
// update admin funkciók megfelelő jogosultsággal maradjanak elérhetők"
// előírás miatt egy TÉNYLEGES admin (vagy egy olyan telepítés, ahol
// egyáltalán nincs dolgozói PIN-rendszer, tehát a bejelentkezés maga a
// tulajdonosi szint — ugyanaz a feltétel, mint require_admin()-ben)
// változatlanul mindent elér.
if (!empty($appSettings['maintenance_mode_active']) && !$isCronScript) {
    $maintenanceWhitelist = array_merge($authWhitelist, [
        'update-status.php', 'update-check.php', 'update-install.php', 'update-history.php', 'settings.php',
    ]);
    $isEffectiveAdmin = !$db->listStaff(true) || $db->isStaffAdmin(Auth::currentStaffId());
    if (!in_array($currentScript, $maintenanceWhitelist, true) && !$isEffectiveAdmin) {
        http_response_code(503);
        echo json_encode([
            'error'       => $appSettings['maintenance_mode_message'] ?: 'A FountainTrade frissítése folyamatban van.',
            'maintenance' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function send_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Központi vezetői jogosultság-kapu az infrastruktúra-szintű/érzékeny
 * végpontokhoz (Beállítások, biztonsági beállítások, mentés, WooCommerce-
 * szinkron/teszt) — ugyanazt a "csak akkor kényszerítve, ha egyáltalán van
 * dolgozói PIN-rendszer használatban" mintát követi, mint a már meglévő,
 * egyedi végpontonkénti admin-kapuk (termék-/vásárlótörlés stb.), csak
 * egy helyen, hogy ne kelljen minden érzékeny végpontban külön-külön
 * megismételni.
 */
function require_admin(Database $db): void
{
    if ($db->listStaff(true) && !$db->isStaffAdmin(Auth::currentStaffId())) {
        send_json(['error' => 'Ehhez vezetői jogszint szükséges.'], 403);
    }
}

/**
 * 1.4.0 — a `Database::logSystemEvent()` minden hívási helyén ugyanazt
 * a beállított megőrzési időt kell átadni (lásd logAudit()/
 * audit_log_retention_days pontosan ugyanezen, már bevált mintáját) —
 * ez a segédfüggvény csak a `(int) ($settings[...] ?? 14)` ismétlését
 * váltja ki minden hívási helyen.
 */
function system_event_retention_days(array $settings): int
{
    return max(1, (int) ($settings['system_events_retention_days'] ?? 14));
}

/**
 * CSV/formula-injekció elleni védelem (CWE-1236) minden CSV-exporthoz. Ha
 * egy cella (pl. termék/vásárló/beszállító neve, megjegyzés) `=`, `+`, `-`
 * vagy `@` karakterrel kezdődik, Excel/LibreOffice megnyitáskor képletként
 * értelmezheti — egy `=HYPERLINK(...)`-szerű névvel elmentett rekord
 * exportálásakor futtatható "képletet" csempészhetne be. Egy vezető
 * aposztróf hozzáfűzése Excelben "szövegként kezelendő"-t jelent, a
 * megjelenített értéket nem változtatja meg.
 */
function csv_safe($value): string
{
    $str = (string) ($value ?? '');
    if ($str !== '' && strpbrk($str[0], "=+-@") !== false) {
        return "'" . $str;
    }
    return $str;
}
