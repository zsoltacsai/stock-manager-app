<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Budapest');

// Biztonsági HTTP-fejlécek — minden API-válaszra vonatkoznak.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/WooCommerceClient.php';
require_once __DIR__ . '/../../src/SzamlazzClient.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/Auth.php';

$config = require __DIR__ . '/../../config/config.php';

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
    error_log('[stock-manager] Settings::read() failed: ' . $e->getMessage());
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
$cronScripts = ['auto-backup-run.php', 'auto-sync-run.php', 'nav-queue-run.php'];
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
