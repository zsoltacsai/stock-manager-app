<?php

declare(strict_types=1);

/**
 * A FountainTrade automatikus (cron-indított) frissítési folyamatának
 * BELÉPÉSI PONTJA — lásd README "Önfrissítés" szakasza a pontos cron-
 * bejegyzésért. SZÁNDÉKOSAN egy KÜLÖN CLI-folyamat, NEM egy HTTP-végpont
 * (24. pont: "Az update folyamat ne függjön egyetlen HTTP request
 * timeoutjától") — a tényleges telepítés (letöltés, mentés, fájl-másolás,
 * migráció, egészség-ellenőrzés) percekig is tarthat, egy cron-nak viszont
 * nincs HTTP-timeout-korlátja, ha közvetlenül ezt a szkriptet hívja.
 *
 * Az admin felület "Frissítés most" gombja (webroot/api/update-install.php)
 * NEM ezt a szkriptet hívja — az `fastcgi_finish_request()`-tel oldja meg
 * ugyanezt közvetlenül a webszerver-folyamaton belül (lásd ott a
 * docblockot) —, de UGYANAZT az UpdateInstaller::install()-t futtatja.
 *
 * Futtatás (cron):
 *   php /var/www/stock-manager-app/tools/update-install-cli.php
 *
 * Csak akkor telepít, ha:
 *   - a Beállítások → Frissítések alatt az automatikus telepítés BE van
 *     kapcsolva (update_auto_install_enabled) — ez a 5. pont "Automatikus
 *     telepítés csak admin számára legyen engedélyezhető" előírása: az
 *     ADMIN kapcsolja be ezt a Beállításokban, utána futhat felügyelet
 *     nélkül;
 *   - ÉS ténylegesen van újabb, érvényes release.
 */

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/UpdateInstaller.php';
require __DIR__ . '/../src/Settings.php';

$appRoot = dirname(__DIR__);
$config = require $appRoot . '/config/config.php';
$settingsStore = new Settings($appRoot . '/data/settings.json');
$settings = $settingsStore->read();

function output(array $data): void
{
    fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_UNICODE) . "\n");
}

if (empty($settings['update_auto_install_enabled'])) {
    output(['skipped' => true, 'reason' => 'auto_install_disabled']);
    exit(0);
}

try {
    $db = new Database($config['db'], $appRoot);
    $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore);

    $checkResult = $installer->checkForUpdate();
    if (empty($checkResult['ok']) || empty($checkResult['update_available'])) {
        output(['skipped' => true, 'reason' => 'no_update_available', 'check' => $checkResult]);
        exit(0);
    }

    $result = $installer->install('cron', 'cron');
    output($result);
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    output(['ok' => false, 'error' => $e->getMessage()]);
    exit(1);
}
