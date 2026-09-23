<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/OllamaProvisioner.php';

// Fázis 6, Rész B — a kör 18. pontja: az Ollama-telepítés/-állapot
// Kliens node-on SOSE dőlhet el helyben. A _bootstrap.php node_role-
// elágazása egy Kliens KÉRÉSÉT már ELŐBB, ennél a sornál korábban a
// Szerverre proxyzza (lásd a MEGLÉVŐ cron-script-védőháló azonos
// indoklását ugyanebben a fájlban) — ez itt egy FÜGGETLEN, a végpont
// SAJÁT szerződését is önmagában igazoló védőháló, ugyanaz a minta.
if (($config['node_role'] ?? 'standalone') === 'client') {
    send_json(['error' => 'Ez a végpont Kliens módban nem elérhető.', 'client_mode_unavailable' => true], 403);
}

require_admin($db);

$status = OllamaProvisioner::detectStatusCached(
    (string) $appSettings['ai_local_base_url'],
    (string) $appSettings['ai_local_model'],
    !empty($_GET['force'])
);

send_json(['ok' => true] + $status);
