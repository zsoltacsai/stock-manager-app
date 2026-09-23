<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/AiProviderFactory.php';
require_once __DIR__ . '/../../src/Ai/Agents/SalesAgent.php';
require_once __DIR__ . '/../../src/Ai/AiAuditLogger.php';

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

$agent = new SalesAgent($provider, $db, $appSettings, max(1, (int) $appSettings['ai_max_iterations']));

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
