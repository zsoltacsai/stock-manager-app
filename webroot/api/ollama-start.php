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

$result = OllamaProvisioner::startIfNotRunning((string) $appSettings['ai_local_base_url']);

if ($result['status'] !== 'ok') {
    send_json(['ok' => false, 'error' => $result['message']], 502);
}

send_json(['ok' => true, 'message' => $result['message']]);
