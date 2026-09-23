<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/AiProviderFactory.php';
require_once __DIR__ . '/../../src/Ai/Agents/InventoryAgent.php';
require_once __DIR__ . '/../../src/Ai/AiAuditLogger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Az első AI-agent admin-jogszinthez kötött (lásd a kör 12. pontja: "admin-
// facing AI UI") — ugyanaz a szabály, mint a többi, üzletileg érzékeny
// strukturális művelet (pl. cash-register-save.php).
require_admin($db);

if (empty($appSettings['ai_enabled'])) {
    send_json(['error' => 'Az AI asszisztens jelenleg ki van kapcsolva.'], 503);
}

// Fázis 7 — élő, valódi (helyi) Ollamával végzett ellenőrzéskor élőben
// megfigyelt hiba: a PHP beépített fejlesztői szerverének alapértelmezett
// `max_execution_time` (php.ini, jellemzően 30 mp) a TELJES szkript
// futására vonatkozik, FÜGGETLENÜL attól, hogy az egyes cURL-hívások
// (lásd ai_timeout_seconds) ennél rövidebbek. Egy valós, nagyobb
// modellnél (pl. qwen3:8b) EGY agent-futás akár `ai_max_iterations` DARAB
// egymást követő, egyenként `ai_timeout_seconds`-ig tartó valódi hívást is
// indíthat (élőben MEGFIGYELVE: egy 2-körös AnomalyAgent-hívás 280 mp-es
// ai_timeout_seconds mellett önmagában meghaladt egy fix 300 mp-es
// korlátot) — ezért a korlát DINAMIKUSAN, a TÉNYLEGESEN engedélyezett
// legrosszabb esethez igazodik, nem egy találgatott fix szám. Enélkül
// PHP egy nyers "Maximum execution time exceeded" végzetes hibaként
// állítja meg a futást (a _bootstrap.php shutdown-handlere ezt ÁLTALÁNOS
// szerverhibaként adja vissza, NEM a szándékolt, biztonságos "Az
// AI-modell jelenleg nem érhető el." üzenetként).
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

$agent = new InventoryAgent($provider, $db, $appSettings, max(1, (int) $appSettings['ai_max_iterations']));

$startedAt = microtime(true);
$result = $agent->answer($question);
$durationMs = (microtime(true) - $startedAt) * 1000;

AiAuditLogger::logRun(
    $db,
    $appSettings,
    Auth::currentStaffId(),
    InventoryAgent::name(),
    $provider->name(),
    $configuredModel,
    $question,
    $result,
    $durationMs
);

if (!$result->success) {
    // $result->error mindig egy előre megírt, biztonságos üzenet (lásd
    // AgentRunner/AiProviderException) — SOSE nyers kivétel-szöveg.
    send_json(['ok' => false, 'agent' => InventoryAgent::name(), 'error' => $result->error, 'tools_used' => $result->toolsUsed], 502);
}

send_json([
    'ok' => true,
    'agent' => InventoryAgent::name(),
    'answer' => $result->answer,
    'tools_used' => $result->toolsUsed,
]);
