<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/AiProviderFactory.php';
require_once __DIR__ . '/../../src/Ai/AiDailyIntelligence.php';

// Cron-hitelesített (X-Cron-Token), OLCSÓ "esedékes-e" ellenőrző végpont —
// UGYANAZ a minta, mint update-check-run.php: a Feladatütemező gyakran
// (percenként/30 percenként) hívja, a tényleges generálás csak akkor fut
// le, ha ténylegesen esedékes (a kör 13. pontja: "Add a dedicated Server/
// Standalone AI worker/cron operation according to existing conventions" —
// NEM egy külön daemon/infinite loop/második cron-rendszer). Kliens
// node-on ez a szkript SOSE fut le — lásd _bootstrap.php $cronScripts
// (topológia-kapu) ÉS a cron-token-ellenőrzés egyaránt already blokkolja.

if (empty($appSettings['ai_daily_intelligence_enabled'])) {
    send_json(['skipped' => true, 'reason' => 'disabled']);
}
if (empty($appSettings['ai_enabled'])) {
    // A kör 31. pontja — a Daily Intelligence SOSE aktiválódik csak azért,
    // mert 'ai_enabled' igaz, DE fordítva: ha az AI asszisztens maga ki
    // van kapcsolva, a napi jelentés se generálódhat (nincs provider-
    // hozzáférés sem).
    send_json(['skipped' => true, 'reason' => 'ai_disabled']);
}

// Lásd ai-inventory.php ugyanezen soránál a részletes indoklás — a
// cron-worker itt is a PHP built-in szerverének max_execution_time-ja
// alá esne egy valódi, lassabb helyi modellnél. A szintézis-lépés ÜRES
// ToolRegistry-vel fut (lásd AiDailyIntelligence), tehát legfeljebb EGY
// valódi provider-hívást indít — élőben MEGFIGYELVE egy valódi, helyi
// modellnél ez önmagában percekig tarthatott.
set_time_limit(max(60, 2 * (int) $appSettings['ai_timeout_seconds'] + 60));

$configuredHour = max(0, min(23, (int) ($appSettings['ai_daily_intelligence_hour'] ?? 7)));
if ((int) date('G') < $configuredHour) {
    send_json(['skipped' => true, 'reason' => 'not_due', 'configured_hour' => $configuredHour]);
}

$reportDate = date('Y-m-d');
$existing = $db->getAiDailyReport($reportDate);
if ($existing !== null && $existing['status'] === 'completed') {
    send_json(['skipped' => true, 'reason' => 'already_completed', 'report_date' => $reportDate]);
}

try {
    $provider = AiProviderFactory::create($appSettings, $db);
} catch (AiProviderException $e) {
    // Ugyanaz az elv, mint az interaktív AI-végpontoknál — érvénytelen
    // provider-beállítás esetén BIZTONSÁGOSAN meghiúsul, SOSE generál
    // hamis/kitalált jelentést.
    $db->finalizeAiDailyReport($reportDate, ['status' => 'failed', 'error' => 'Érvénytelen AI-provider beállítás.']);
    send_json(['ok' => false, 'error' => 'Érvénytelen AI-provider beállítás.'], 502);
}

$intelligence = new AiDailyIntelligence($provider, $db, $appSettings, max(1, (int) $appSettings['ai_max_iterations']));

$startedAt = microtime(true);
$result = $intelligence->generateForDate($reportDate);
$durationMs = (microtime(true) - $startedAt) * 1000;

try {
    $db->logSystemEvent(
        'ai',
        'daily_intelligence_run',
        $result['status'] === 'completed' ? 'info' : ($result['status'] === 'skipped' ? 'info' : 'warning'),
        $result['status'] === 'completed' ? 'success' : ($result['status'] === 'skipped' ? 'success' : 'failure'),
        match ($result['status']) {
            'completed' => 'AI napi jelentés elkészült.',
            'skipped' => 'AI napi jelentés kihagyva (' . ($result['reason'] ?? '') . ').',
            default => 'AI napi jelentés generálása sikertelen.',
        },
        json_encode(['report_date' => $reportDate, 'provider' => $provider->name(), 'duration_ms' => (int) $durationMs, 'result' => $result], JSON_UNESCAPED_UNICODE),
        (int) ($appSettings['system_events_retention_days'] ?? 14)
    );
} catch (Throwable $e) {
    error_log('[fountaintrade] AI daily intelligence audit log sikertelen: ' . $e->getMessage());
}

$httpStatus = $result['status'] === 'failed' ? 502 : 200;
send_json(['ok' => $result['status'] !== 'failed', 'report_date' => $reportDate] + $result, $httpStatus);
