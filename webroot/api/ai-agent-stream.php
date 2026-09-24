<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/AiProviderFactory.php';
require_once __DIR__ . '/../../src/Ai/AiAuditLogger.php';
require_once __DIR__ . '/../../src/Ai/AiRateLimiter.php';
require_once __DIR__ . '/../../src/Ai/AiStreamEvent.php';
require_once __DIR__ . '/../../src/Ai/AiPricing.php';
require_once __DIR__ . '/../../src/Ai/Agents/InventoryAgent.php';
require_once __DIR__ . '/../../src/Ai/Agents/SalesAgent.php';
require_once __DIR__ . '/../../src/Ai/Agents/AnomalyAgent.php';
require_once __DIR__ . '/../../src/Ai/Agents/AiCopilot.php';

/**
 * Fázis 9 — a kör 6/7. pontja: EGYETLEN, ÁLTALÁNOS SSE-streamelő
 * végpont, amit az AI Asszisztens oldal MIND a négy agent-hez (Copilot/
 * Inventory/Sales/Anomaly) használ — UGYANAZ a szerver-oldali kiválasztási
 * fehérlista, mint amit a MEGLÉVŐ ai-copilot.php/ai-inventory.php/
 * ai-sales.php/ai-anomaly.php külön-külön végpontok is használnak (nem
 * duplikálja azok üzleti logikáját, csak a streamelt szállítási módot
 * adja hozzá — a nem-streamelt végpontok VÁLTOZATLANOK maradnak, lásd
 * a kör 9. pontja "fallback, not hard dependency" elve).
 *
 * SZÁLLÍTÁS (a kör 7. pontja): SSE-alakú (`data: <json>\n\n`) törzs
 * EGYETLEN POST-válaszban, `fetch()` + `ReadableStream`-mel olvasva a
 * böngészőben — SZÁNDÉKOSAN NEM natív `EventSource` (az csak GET-et
 * támogat, nem tudna CSRF-fejlécet/JSON-törzset küldeni, ami ELLENTÉTES
 * lenne a MEGLÉVŐ, minden más AI-végponton egységes POST+CSRF-mintával).
 *
 * A KÉRÉS KIZÁRÓLAG `agent` + `message` mezőt fogad el — SEMMILYEN
 * modell/provider/limit-paramétert NEM a böngészőtől (a kör 23. pontja:
 * "no arbitrary model selection from browser").
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$agentName = (string) ($input['agent'] ?? 'copilot');
$allowedAgents = ['copilot', 'inventory', 'sales', 'anomaly'];
if (!in_array($agentName, $allowedAgents, true)) {
    send_json(['error' => 'Érvénytelen agent.'], 400);
}

$question = trim((string) ($input['message'] ?? ''));
if ($question === '') {
    send_json(['error' => 'Adj meg egy kérdést.'], 400);
}
$maxInputChars = max(200, (int) ($appSettings['ai_max_input_chars'] ?? 4000));
if (mb_strlen($question) > $maxInputChars) {
    send_json(['error' => "A kérdés túl hosszú (legfeljebb $maxInputChars karakter)."], 400);
}

if (empty($appSettings['ai_enabled'])) {
    send_json(['error' => 'Az AI asszisztens jelenleg ki van kapcsolva.'], 503);
}

// A kör 19. pontja — "accidental abuse" védelem, lásd AiRateLimiter.php
// docblokkja (a MEGLÉVŐ audit_log-ra épül, nincs új tábla/alrendszer).
$rateCheck = AiRateLimiter::check($db, $appSettings, Auth::currentStaffId());
if (!$rateCheck['ok']) {
    send_json([
        'error' => 'Túl gyorsan érkezett a következő AI-kérés — várj néhány másodpercet.',
        'retry_after_seconds' => $rateCheck['retry_after_seconds'],
    ], 429);
}

// Lásd ai-copilot.php ugyanezen soránál a részletes indoklás — dinamikus
// korlát, hogy a PHP beépített szerverének max_execution_time-ja alá ne
// essen egy valódi, lassabb helyi modellnél. A Copilot itt is nagyobb
// szorzót kap (legfeljebb 3 beágyazott ügynök-hívás).
$isCopilot = $agentName === 'copilot';
set_time_limit($isCopilot
    ? max(60, 4 * (int) $appSettings['ai_max_iterations'] * (int) $appSettings['ai_timeout_seconds'] + 120)
    : max(60, (int) $appSettings['ai_max_iterations'] * (int) $appSettings['ai_timeout_seconds'] + 60));

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) {
    @ob_end_flush();
}

$sendEvent = static function (AiStreamEvent $event): void {
    echo 'data: ' . json_encode($event->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
};

try {
    $provider = AiProviderFactory::create($appSettings, $db, $isCopilot ? 'complex' : 'default');
} catch (AiProviderException $e) {
    $sendEvent(AiStreamEvent::error('Az AI asszisztens jelenleg nem érhető el (érvénytelen provider-beállítás).'));
    exit;
}

$configuredModel = AiProviderFactory::resolveModel(
    $appSettings,
    match ($provider->name()) { 'anthropic' => 'anthropic_model', 'openai' => 'openai_model', default => 'ai_local_model' },
    match ($provider->name()) { 'anthropic' => 'anthropic_model_complex', 'openai' => 'openai_model_complex', default => 'ai_local_model_complex' },
    $isCopilot
);

$maxIterations = max(1, (int) $appSettings['ai_max_iterations']);
$startedAt = microtime(true);

switch ($agentName) {
    case 'copilot':
        $agent = new AiCopilot($provider, $db, $appSettings, $maxIterations);
        $runResult = $agent->answerStreaming($question, $sendEvent);
        $agentsUsed = $runResult instanceof CopilotRunResult ? $runResult->agentsUsed : [];
        $toolsUsed = $runResult->toolsUsed;
        break;
    case 'inventory':
        $agent = new InventoryAgent($provider, $db, $appSettings, $maxIterations);
        $runResult = $agent->answerStreaming($question, $sendEvent);
        $agentsUsed = [];
        $toolsUsed = $runResult->toolsUsed;
        break;
    case 'sales':
        $agent = new SalesAgent($provider, $db, $appSettings, $maxIterations);
        $runResult = $agent->answerStreaming($question, $sendEvent);
        $agentsUsed = [];
        $toolsUsed = $runResult->toolsUsed;
        break;
    default:
        $agent = new AnomalyAgent($provider, $db, $appSettings, $maxIterations);
        $runResult = $agent->answerStreaming($question, $sendEvent);
        $agentsUsed = [];
        $toolsUsed = $runResult->toolsUsed;
        break;
}

$durationMs = (microtime(true) - $startedAt) * 1000;

// A kör 15. pontja — determinisztikus költség-becslés, SOSE kitalálva
// (lásd AiPricing.php docblokkja: ismeretlen árazású modellnél `null`).
$estimatedCost = null;
if ($runResult->usage !== null) {
    $estimate = AiPricing::estimate($provider->name(), $configuredModel, $runResult->usage);
    $estimatedCost = $estimate['cost'] ?? null;
}

// Fázis 10 — a kör 16. pontja ("Do not create a parallel logging
// system"): korábban ez a végpont EGY KÜLÖN, a négy nem-streamelt
// végpont (ai-inventory.php stb.) AiAuditLogger-hívásaival PÁRHUZAMOS,
// duplikált napló-logikát tartalmazott — a Fázis 9 mezők (streamed/
// tokenek/becsült költség/limit_reached/context_compacted) csak ITT
// íródtak. Most a MEGLÉVŐ, KÖZÖS AiAuditLogger::logRun()/logCopilotRun()
// hívja — ezek MOST MÁR maguktól tartalmazzák ezeket a mezőket
// (lásd AiAuditLogger::extendedFields()), plusz az ÚJ `failure_category`-t
// is, ÚJ paraméter nélkül, egyenesen a $runResult-ból.
if ($runResult instanceof CopilotRunResult) {
    AiAuditLogger::logCopilotRun($db, $appSettings, Auth::currentStaffId(), $provider->name(), $configuredModel, $question, $runResult, $durationMs);
} else {
    AiAuditLogger::logRun($db, $appSettings, Auth::currentStaffId(), $agentName, $provider->name(), $configuredModel, $question, $runResult, $durationMs);
}

// A záró esemény MINDIG a válasz VÉGÉN megy — a UI ebből tudja, hogy a
// kapcsolat lezárható, ÉS ez hordozza a usage/cost/limit-metaadatot is
// (a runStreaming() saját 'final'/'error' eseménye csak a választ,
// ezt a 'done' eseményt KIZÁRÓLAG ez a végpont küldi).
$sendEvent(AiStreamEvent::make('done', [
    'success' => $runResult->success,
    'agent' => $agentName,
    'agents_used' => $agentsUsed,
    'tools_used' => $toolsUsed,
    'provider' => $provider->name(),
    'model' => $configuredModel,
    'streamed' => $runResult->streamed,
    'duration_ms' => (int) $durationMs,
    'usage' => $runResult->usage?->toArray(),
    'estimated_cost' => $estimatedCost,
    'limit_reached' => $runResult->limitReached,
    'failure_category' => $runResult->failureCategory,
]));
