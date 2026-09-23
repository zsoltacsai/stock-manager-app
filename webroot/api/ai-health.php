<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/OllamaHealth.php';
require_once __DIR__ . '/../../src/Ai/AnthropicHealth.php';

// Lásd a kör 13. pontja — ez a végpont KIZÁRÓLAG a cache-elt (30
// másodperces TTL, lásd OllamaHealth/AnthropicHealth) állapotot adja
// vissza, sose indít felesleges hálózati hívást minden oldalbetöltéskor.
// Kliens node-on ez a kód sose fut le — a _bootstrap.php node_role-
// elágazása előbb a Szerverre proxyzza a kérést (lásd a kör 11. pontja).
if (empty($appSettings['ai_enabled'])) {
    send_json(['enabled' => false, 'status' => 'disabled']);
}

// Fázis 2 — szigorú fehérlista itt is, ugyanaz, mint AiProviderFactory-ban
// (lásd a kör 5. pontja): ismeretlen ai_provider érték sose próbál meg
// egyik provider felé sem hálózati hívást indítani.
$providerName = (string) ($appSettings['ai_provider'] ?? 'local');

if ($providerName === 'anthropic') {
    $availability = AnthropicHealth::check(
        (string) $appSettings['anthropic_base_url'],
        (string) $appSettings['anthropic_api_key'],
        (string) $appSettings['anthropic_model'],
        (int) $appSettings['anthropic_timeout_seconds'],
        !empty($_GET['force'])
    );
    $model = $appSettings['anthropic_model'];
} elseif ($providerName === 'local') {
    $availability = OllamaHealth::check(
        (string) $appSettings['ai_local_base_url'],
        (string) $appSettings['ai_local_model'],
        (int) $appSettings['ai_timeout_seconds'],
        !empty($_GET['force'])
    );
    $model = $appSettings['ai_local_model'];
} else {
    send_json(['enabled' => true, 'status' => 'unavailable', 'message' => 'Ismeretlen AI-provider beállítás.', 'model' => null]);
}

send_json([
    'enabled' => true,
    'provider' => $providerName,
    'status' => $availability->status,
    'message' => $availability->message,
    'model' => $model,
]);
