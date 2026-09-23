<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/OllamaProvisioner.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

if (($config['node_role'] ?? 'standalone') === 'client') {
    send_json(['error' => 'Ez a végpont Kliens módban nem elérhető.', 'client_mode_unavailable' => true], 403);
}

require_admin($db);

// A kör 22. pontja — MINDIG a konfigurált ai_local_model a forrás
// igazság, SOSE egy a kliens JS-ből küldött/hardcodolt modellnév — a
// böngésző legfeljebb EGYETLEN, argumentum nélküli gombnyomást küld,
// magát a modellnevet a szerver oldali beállításból olvassuk.
$model = (string) $appSettings['ai_local_model'];

// A letöltés akár percekig is tarthat egy nagyobb modellnél — lásd
// OllamaProvisioner::PULL_TIMEOUT_SECONDS docblokkja a streamelt
// progress-sáv hiányáról (README "Ismert korlátok").
set_time_limit(OllamaProvisioner::PULL_TIMEOUT_SECONDS + 30);

$startedAt = microtime(true);
$result = OllamaProvisioner::pullModel((string) $appSettings['ai_local_base_url'], $model, OllamaProvisioner::PULL_TIMEOUT_SECONDS);
$durationMs = (microtime(true) - $startedAt) * 1000;

try {
    $db->logSystemEvent(
        'ai',
        'ollama_model_pull',
        $result['status'] === 'ok' ? 'info' : 'warning',
        $result['status'] === 'ok' ? 'success' : 'failure',
        $result['status'] === 'ok' ? "Ollama modell letöltve ($model)." : "Ollama modell letöltése sikertelen ($model).",
        json_encode(['model' => $model, 'duration_ms' => (int) $durationMs], JSON_UNESCAPED_UNICODE),
        (int) ($appSettings['system_events_retention_days'] ?? 14)
    );
} catch (Throwable $e) {
    error_log('[fountaintrade] Ollama pull audit log sikertelen: ' . $e->getMessage());
}

if ($result['status'] !== 'ok') {
    send_json(['ok' => false, 'error' => $result['message']], 502);
}

send_json(['ok' => true, 'message' => $result['message'], 'model' => $model]);
