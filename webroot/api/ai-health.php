<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/OllamaHealth.php';

// Lásd a kör 13. pontja — ez a végpont KIZÁRÓLAG a cache-elt (30
// másodperces TTL, lásd OllamaHealth) állapotot adja vissza, sose indít
// felesleges hálózati hívást minden oldalbetöltéskor. Kliens node-on ez
// a kód sose fut le — a _bootstrap.php node_role-elágazása előbb a
// Szerverre proxyzza a kérést (lásd a kör 11. pontja).
if (empty($appSettings['ai_enabled'])) {
    send_json(['enabled' => false, 'status' => 'disabled']);
}

$availability = OllamaHealth::check(
    (string) $appSettings['ai_local_base_url'],
    (string) $appSettings['ai_local_model'],
    (int) $appSettings['ai_timeout_seconds'],
    !empty($_GET['force'])
);

send_json([
    'enabled' => true,
    'status' => $availability->status,
    'message' => $availability->message,
    'model' => $appSettings['ai_local_model'],
]);
