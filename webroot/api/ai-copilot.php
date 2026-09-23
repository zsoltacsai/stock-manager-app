<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/AiProviderFactory.php';
require_once __DIR__ . '/../../src/Ai/Agents/AiCopilot.php';
require_once __DIR__ . '/../../src/Ai/AiAuditLogger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Fázis 6 — a Copilot UGYANAZZAL az admin-jogszinthez kötéssel, mint a
// három domain-agent (lásd webroot/api/ai-inventory.php azonos
// indoklása) — a Copilot maga is csak ezeket hívja meg, nem enged
// szélesebb hozzáférést náluk.
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
    send_json(['error' => 'Az AI asszisztens jelenleg nem érhető el (érvénytelen provider-beállítás).'], 503);
}

$configuredModel = match ($provider->name()) {
    'anthropic' => (string) $appSettings['anthropic_model'],
    'openai' => (string) $appSettings['openai_model'],
    default => (string) $appSettings['ai_local_model'],
};

$copilot = new AiCopilot($provider, $db, $appSettings, max(1, (int) $appSettings['ai_max_iterations']));

$startedAt = microtime(true);
$result = $copilot->answer($question);
$durationMs = (microtime(true) - $startedAt) * 1000;

AiAuditLogger::logCopilotRun(
    $db,
    $appSettings,
    Auth::currentStaffId(),
    $provider->name(),
    $configuredModel,
    $question,
    $result,
    $durationMs
);

if (!$result->success) {
    // $result->error mindig egy előre megírt, biztonságos üzenet (lásd
    // AgentRunner/AiProviderException) — SOSE nyers kivétel-szöveg.
    send_json([
        'ok' => false,
        'agent' => AiCopilot::name(),
        'error' => $result->error,
        'agents_used' => $result->agentsUsed,
        'tools_used' => $result->toolsUsed,
    ], 502);
}

send_json([
    'ok' => true,
    'agent' => AiCopilot::name(),
    'answer' => $result->answer,
    'agents_used' => $result->agentsUsed,
    'tools_used' => $result->toolsUsed,
    'agent_results' => $result->agentResults,
]);
