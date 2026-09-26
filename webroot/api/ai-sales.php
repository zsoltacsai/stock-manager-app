<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/AiProviderFactory.php';
require_once __DIR__ . '/../../src/Ai/Agents/SalesAgent.php';
require_once __DIR__ . '/../../src/Ai/AiAuditLogger.php';
require_once __DIR__ . '/../../src/Ai/AiRateLimiter.php';
require_once __DIR__ . '/../../src/Ai/AiRunContext.php';
require_once __DIR__ . '/../../src/Ai/AiRunGuard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// A második AI-agent UGYANAZZAL az admin-jogszinthez kötéssel, mint az
// InventoryAgent (lásd webroot/api/ai-inventory.php azonos indoklása) —
// egy AI-végpont sem enged üzletileg érzékeny adatot egyszerű
// pénztárosi jogszinttel.
require_admin($db);

if (empty($appSettings['ai_enabled'])) {
    send_json(['error' => 'Az AI asszisztens jelenleg ki van kapcsolva.'], 503);
}

// Fázis 7 — lásd ai-inventory.php ugyanezen soránál a részletes indoklás
// (élő, valódi Ollamával megfigyelt hiba — a korlát DINAMIKUS, az
// ai_max_iterations/ai_timeout_seconds tényleges legrosszabb esetéhez
// igazodik).
set_time_limit(max(60, (int) $appSettings['ai_max_iterations'] * (int) $appSettings['ai_timeout_seconds'] + 60));

$input = json_input();
$question = trim((string) ($input['message'] ?? ''));
if ($question === '') {
    send_json(['error' => 'Adj meg egy kérdést.'], 400);
}
if (mb_strlen($question) > 2000) {
    send_json(['error' => 'A kérdés túl hosszú (legfeljebb 2000 karakter).'], 400);
}

try {
    $provider = AiProviderFactory::create($appSettings, $db);
} catch (AiProviderException $e) {
    // Ismeretlen/érvénytelen ai_provider beállítás — a factory már
    // naplózott (lásd AiProviderFactory::logInvalidProvider), itt csak egy
    // biztonságos, nyers kivétel-szöveg nélküli válasz megy ki.
    send_json(['error' => 'Az AI asszisztens jelenleg nem érhető el (érvénytelen provider-beállítás).'], 503);
}

$configuredModel = match ($provider->name()) {
    'anthropic' => (string) $appSettings['anthropic_model'],
    'openai' => (string) $appSettings['openai_model'],
    default => (string) $appSettings['ai_local_model'],
};

// AI-05 — szereplőnként (dolgozó + terminál) egyetlen futó AI-kérés (lásd
// AiRateLimiter; a várakozási időt — mint eddig — csak a stream-végpont kéri). A slot a
// kérés végéig él. AI-09 — a futás közben újraellenőrzött jogosultság és
// a teljes kérésre érvényes tool-keret (lásd AiRunGuard, AiRunContext).
$slotResult = AiRateLimiter::acquireRunSlot($db, $appSettings, Auth::currentStaffId(), Auth::proxiedRegisteredClientId(), false);
if (!$slotResult['ok']) {
    send_json(array_filter([
        'error' => $slotResult['error'],
        'retry_after_seconds' => $slotResult['retry_after_seconds'] ?? null,
    ], static fn ($v) => $v !== null), $slotResult['reason'] === 'unavailable' ? 503 : 429);
}
$aiRunSlot = $slotResult['slot'];
$aiRunContext = AiRunContext::fromSettings($appSettings, AiRunGuard::forCurrentRequest($db));
$agent = new SalesAgent($provider, $db, $appSettings, max(1, (int) $appSettings['ai_max_iterations']), $aiRunContext);

$startedAt = microtime(true);
$result = $agent->answer($question);
$durationMs = (microtime(true) - $startedAt) * 1000;

AiAuditLogger::logRun(
    $db,
    $appSettings,
    Auth::currentStaffId(),
    SalesAgent::name(),
    $provider->name(),
    $configuredModel,
    $question,
    $result,
    $durationMs
);

if (!$result->success) {
    // $result->error mindig egy előre megírt, biztonságos üzenet (lásd
    // AgentRunner/AiProviderException) — SOSE nyers kivétel-szöveg.
    send_json(['ok' => false, 'agent' => SalesAgent::name(), 'error' => $result->error, 'tools_used' => $result->toolsUsed], 502);
}

send_json([
    'ok' => true,
    'agent' => SalesAgent::name(),
    'answer' => $result->answer,
    'tools_used' => $result->toolsUsed,
]);
