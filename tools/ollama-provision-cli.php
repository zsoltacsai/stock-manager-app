<?php

declare(strict_types=1);

/**
 * A Windows PowerShell telepítő (install-windows.ps1, Fázis 6 Rész B)
 * BELÉPÉSI PONTJA az Ollama telepítéséhez/állapot-lekérdezéséhez/
 * modell-letöltéséhez — ugyanaz a minta, mint tools/installer-set-
 * topology.php: a telepítő PowerShell-oldalán SOSE íródik újra a
 * tényleges logika, mindig ezt a MEGLÉVŐ PHP-osztályt (OllamaProvisioner)
 * hívjuk meg egy vékony CLI-csomagolón keresztül, JSON kimenettel.
 *
 * Használat:
 *   php tools/ollama-provision-cli.php --action=status [--base-url=...] [--model=...]
 *     -> {"ok":true,"installed":bool,"version":?,"api_available":bool,
 *         "configured_model":str,"model_installed":bool}
 *   php tools/ollama-provision-cli.php --action=install
 *     -> {"ok":true,"message":"...","version":"..."} vagy {"ok":false,"error":"..."} + exit(1)
 *   php tools/ollama-provision-cli.php --action=pull-model [--base-url=...] [--model=...]
 *     -> {"ok":true,"message":"..."} vagy {"ok":false,"error":"..."} + exit(1)
 *
 * A --base-url/--model paraméterek hiányában a Settings::DEFAULTS-szal
 * MEGEGYEZŐ alapértelmezésekre esik vissza (lásd src/Settings.php
 * 'ai_local_base_url'/'ai_local_model') — telepítéskor jellemzően még
 * nincs data/settings.json, ez a tool NEM olvassa/írja azt, kizárólag
 * paraméterként kapja a célt, ugyanúgy, mint installer-set-topology.php.
 */

require_once __DIR__ . '/../src/Ai/AiProviderException.php';
require_once __DIR__ . '/../src/Ai/OllamaProvisioner.php';

function ft_ollama_output(array $data, int $exitCode = 0): void
{
    fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit($exitCode);
}

function ft_ollama_parse_argv(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $out[substr($arg, 2)] = true;
        } else {
            $out[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        }
    }
    return $out;
}

$args = ft_ollama_parse_argv($argv);
$action = (string) ($args['action'] ?? 'status');
$baseUrl = (string) ($args['base-url'] ?? 'http://127.0.0.1:11434');
$model = (string) ($args['model'] ?? 'qwen3:8b');

if ($action === 'status') {
    $status = OllamaProvisioner::detectStatus($baseUrl, $model);
    ft_ollama_output(['ok' => true] + $status);
}

if ($action === 'install') {
    $result = OllamaProvisioner::install();
    if ($result['status'] !== 'ok') {
        ft_ollama_output(['ok' => false, 'error' => $result['message'] ?? 'A telepítés sikertelen.'], 1);
    }
    ft_ollama_output(['ok' => true, 'message' => $result['message'] ?? 'Az Ollama sikeresen települt.', 'version' => $result['version'] ?? null]);
}

if ($action === 'pull-model') {
    $result = OllamaProvisioner::pullModel($baseUrl, $model, OllamaProvisioner::PULL_TIMEOUT_SECONDS);
    if ($result['status'] !== 'ok') {
        ft_ollama_output(['ok' => false, 'error' => $result['message']], 1);
    }
    ft_ollama_output(['ok' => true, 'message' => $result['message']]);
}

ft_ollama_output(['ok' => false, 'error' => "Ismeretlen --action érték: $action (csak 'status'/'install'/'pull-model')."], 1);
