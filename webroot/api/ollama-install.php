<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/OllamaProvisioner.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Fázis 6, Rész B — a kör 18. pontja, ugyanaz a védőháló, mint
// ollama-status.php-ban (lásd ott a részletes indoklás).
if (($config['node_role'] ?? 'standalone') === 'client') {
    send_json(['error' => 'Ez a végpont Kliens módban nem elérhető.', 'client_mode_unavailable' => true], 403);
}

require_admin($db);

// A telepítés — letöltés + SHA-256 ellenőrzés + a MÁR ellenőrzött fájl
// csendes futtatása (lásd OllamaProvisioner::install() docblokkja) —
// percekig is tarthat (nagy fájl letöltése), ezért a PHP időkorlátot
// a végpont szintjén emeljük meg; a felület eközben egy "telepítés
// folyamatban" állapotot mutat (lásd beallitasok.js).
set_time_limit(600);

$startedAt = microtime(true);
$result = OllamaProvisioner::install();
$durationMs = (microtime(true) - $startedAt) * 1000;

try {
    $db->logSystemEvent(
        'ai',
        'ollama_install',
        $result['status'] === 'ok' ? 'info' : 'warning',
        $result['status'] === 'ok' ? 'success' : 'failure',
        $result['status'] === 'ok' ? 'Ollama telepítés sikeres.' : 'Ollama telepítés sikertelen.',
        json_encode(['duration_ms' => (int) $durationMs, 'message' => $result['message'] ?? null], JSON_UNESCAPED_UNICODE),
        (int) ($appSettings['system_events_retention_days'] ?? 14)
    );
} catch (Throwable $e) {
    error_log('[fountaintrade] Ollama install audit log sikertelen: ' . $e->getMessage());
}

if ($result['status'] !== 'ok') {
    send_json(['ok' => false, 'error' => $result['message'] ?? 'A telepítés sikertelen.'], 502);
}

send_json(['ok' => true, 'message' => $result['message'] ?? 'Az Ollama sikeresen települt.', 'version' => $result['version'] ?? null]);
