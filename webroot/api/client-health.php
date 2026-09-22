<?php

declare(strict_types=1);

/**
 * Fázis 2, Checkpoint 4 — a Kliens SAJÁT connectivity/health állapotának
 * lekérdezése a böngésző (POS UI) számára. SZÁNDÉKOSAN NEM requireolja a
 * _bootstrap.php-t (ugyanaz a minta, mint install-status.php-nál) — ez egy
 * Kliens SAJÁT állapotát kérdezi le, amit a Szerver nem is ismerhetne, tehát
 * a _bootstrap.php node_role==='client' ágának proxy-továbbítását itt
 * szándékosan el sem éri (mert azt az ág CSAK azoknál a fájloknál futtatja
 * le, amik saját maguk requireolják — ez nem az egyik ilyen).
 *
 * A tényleges frissítés/gyorsítótárazás a ClientServerHealth osztályban él
 * (TTL-lel — lásd ott a docblokkja), ez a végpont csak vékony HTTP-burok
 * köré, a konfigurált (NEM titkos) server_url-lel kiegészítve a böngésző
 * felé megjelenítendő részletekhez. Standalone/Szerver node-on (ahol ennek
 * nincs értelme) egyértelmű, de nem hibás választ ad.
 */

require_once __DIR__ . '/../../src/ClientServerHealth.php';

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../../config/config.php';

if (($config['node_role'] ?? 'standalone') !== 'client') {
    echo json_encode(['applicable' => false, 'reason' => 'Ez a végpont csak Kliens módban értelmezett.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientConfig = $config['client'] ?? [];
$health = ClientServerHealth::check($clientConfig);
echo json_encode(array_merge(['applicable' => true, 'server_url' => $clientConfig['server_url'] ?? ''], $health), JSON_UNESCAPED_UNICODE);
