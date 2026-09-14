<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/UpdateService.php';
require_once __DIR__ . '/../../src/Settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$settingsStore = new Settings(__DIR__ . '/../../data/settings.json');
$service = new UpdateService($db, $config, __DIR__ . '/../..', $settingsStore);

// Gyors, szinkron előzetes ellenőrzés — ha MÁR fut egy telepítés, ezt a
// böngésző azonnal, valós hibaként kapja vissza (409), nem egy hazug
// "elindítva" választ.
if ($db->isUpdateLockHeld()) {
    send_json(['error' => 'Már folyamatban van egy frissítés.', 'already_running' => true], 409);
}

$actor = 'admin#' . (Auth::currentStaffId() ?? 'shared');

// 24. pont: "Az update folyamat ne függjön egyetlen HTTP request
// timeoutjától." A TÉNYLEGES telepítés (letöltés, mentés, fájl-másolás,
// migráció, egészség-ellenőrzés) percekig tarthat — ha itt egyszerűen
// szinkron megvárnánk, az admin böngészője kifutna a szokásos
// kérés-timeoutból, ÉS a php-fpm saját request_terminate_timeout-ja is
// megszakíthatná félúton. Ehelyett: a klienshez AZONNAL visszaküldjük a
// "elindítva" választ, a php-fpm `fastcgi_finish_request()`-jével
// lezárjuk a TÉNYLEGES HTTP-választ, majd UGYANEBBEN a PHP-folyamatban,
// a válasz elküldése UTÁN folytatjuk a tényleges telepítést — ez a
// dokumentált production stack (nginx+php-fpm, lásd
// telepites-tavoli-szerver.txt) alatt megbízhatóan működik, semmilyen
// proc_open/exec-alapú háttérfolyamat-indításra nincs szükség (amit
// sok megosztott tárhely biztonsági okból letilt). A beépített PHP
// fejlesztői szerver (`php -S`, amin ez tesztelve is lett) NEM ismeri a
// fastcgi_finish_request()-et — ott ez a hívás egyszerűen nem történik
// meg, és a kérés a telepítés végéig szinkron blokkol, ami fejlesztői/
// teszt-környezetben elfogadható (lásd README).
//
// Felügyelet nélküli (cron-indított) automatikus telepítéshez EZ a
// végpont NEM való — ott a tools/update-install-cli.php CLI-szkriptet
// kell cron-ból hívni (lásd README), aminek végképp nincs HTTP-
// timeout-korlátja.
// SZÁNDÉKOSAN NEM send_json() — az `exit`-tel zár, ami itt megakadályozná,
// hogy a válasz elküldése UTÁN a telepítés ténylegesen elinduljon.
http_response_code(200);
echo json_encode(['triggered' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$service->install('admin', $actor);
