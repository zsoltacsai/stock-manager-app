<?php

declare(strict_types=1);

/**
 * A Windows PowerShell telepítő (install-windows.ps1, Fázis 2 Checkpoint 3)
 * BELÉPÉSI PONTJA a node_role/kliens-hitelesítő adatok írásához/
 * olvasásához a config/installer-generated.php-ba. SZÁNDÉKOSAN külön PHP
 * CLI-eszköz, NEM egy PowerShell-oldali, saját PHP-array-szerializálás —
 * ugyanaz az elv, mint ahogy install-windows.ps1 a GitHub Release-ellenőrzés
 * SZABÁLYAIT másolja, itt viszont MAGÁT a tényleges PHP-kódot (var_export()
 * + a valódi UrlSafety::checkServerUrl()) hívjuk meg, nem egy párhuzamos,
 * gyengébb PowerShell-újraírást — ez az EGYETLEN hely, ami valaha
 * installer-generated.php 'node_role'/'client' kulcsait írja.
 *
 * A webroot/install.php (böngészős varázsló, bolt/DB-adatok) és ez az
 * eszköz UGYANAZT a fájlt, egymást nem felülírva, MERGE-eli — pontosan
 * úgy, ahogy install-windows-lib.ps1 Get-OrCreateCronToken()-je is a
 * settings.json MEGLÉVŐ mezőit őrzi meg egy új írásnál.
 *
 * Használat:
 *   php tools/installer-set-topology.php --action=get
 *     -> {"ok":true,"node_role":"standalone","client":{...}}
 *
 *   php tools/installer-set-topology.php --action=set --node-role=server
 *   php tools/installer-set-topology.php --action=set --node-role=client \
 *       --server-url=http://192.168.1.10:8000 --client-id=cl_... --client-secret=...
 *     -> {"ok":true,"node_role":"...","previous_node_role":"...","changed":bool}
 *     -> hiba esetén: {"ok":false,"error":"..."} + exit(1)
 */

require_once __DIR__ . '/../src/UrlSafety.php';

function ft_topology_output(array $data, int $exitCode = 0): void
{
    fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit($exitCode);
}

function ft_topology_parse_argv(array $argv): array
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

$generatedPath = __DIR__ . '/../config/installer-generated.php';
$defaultClient = ['server_url' => '', 'client_id' => '', 'client_secret' => ''];

function ft_topology_read(string $path, array $defaultClient): array
{
    $existing = is_file($path) ? (require $path) : [];
    if (!is_array($existing)) {
        $existing = [];
    }
    return [
        'existing'  => $existing,
        'node_role' => $existing['node_role'] ?? 'standalone',
        'client'    => array_merge($defaultClient, is_array($existing['client'] ?? null) ? $existing['client'] : []),
    ];
}

$args = ft_topology_parse_argv($argv);
$action = (string) ($args['action'] ?? 'get');

$current = ft_topology_read($generatedPath, $defaultClient);

if ($action === 'get') {
    ft_topology_output([
        'ok'        => true,
        'node_role' => $current['node_role'],
        'client'    => $current['client'],
    ]);
}

if ($action !== 'set') {
    ft_topology_output(['ok' => false, 'error' => "Ismeretlen --action érték: $action (csak 'get' vagy 'set')."], 1);
}

$nodeRole = strtolower(trim((string) ($args['node-role'] ?? '')));
if (!in_array($nodeRole, ['standalone', 'server', 'client'], true)) {
    ft_topology_output(['ok' => false, 'error' => "Érvénytelen --node-role érték: '$nodeRole' (csak standalone/server/client)."], 1);
}

$clientConfig = $defaultClient;
if ($nodeRole === 'client') {
    $serverUrl = trim((string) ($args['server-url'] ?? ''));
    $clientId = trim((string) ($args['client-id'] ?? ''));
    $clientSecret = trim((string) ($args['client-secret'] ?? ''));

    if ($serverUrl === '' || $clientId === '' || $clientSecret === '') {
        ft_topology_output(['ok' => false, 'error' => 'Kliens módhoz --server-url, --client-id és --client-secret mind kötelező.'], 1);
    }

    // A MEGLÉVŐ UrlSafety mechanizmust hívjuk meg — nem egy párhuzamos,
    // PowerShell-oldali újraírást (lásd UrlSafety::checkServerUrl()
    // docblokkja: privát/LAN cím itt SZÁNDÉKOSAN engedélyezett, ellentétben
    // a kimenő-integrációk check()-jével).
    [$urlOk, $urlError] = UrlSafety::checkServerUrl($serverUrl);
    if (!$urlOk) {
        ft_topology_output(['ok' => false, 'error' => "A megadott szerver-cím érvénytelen: $urlError"], 1);
    }

    $clientConfig = ['server_url' => $serverUrl, 'client_id' => $clientId, 'client_secret' => $clientSecret];
}
// Nem-kliens szerepkörnél a 'client' tömb SZÁNDÉKOSAN visszaáll üresre —
// egy korábbi Kliens-módú telepítésből maradt titok egy Szerverré/Önálló
// géppé váltott node konfigurációjában értelmetlen (és felesleges kockázat)
// volna (lásd a kör 16. pontja: "ne maradjon félkonfigurált node").

$merged = $current['existing'];
$merged['node_role'] = $nodeRole;
$merged['client'] = $clientConfig;

$configDir = dirname($generatedPath);
if (!is_dir($configDir)) {
    mkdir($configDir, 0775, true);
}
$exported = var_export($merged, true);
file_put_contents($generatedPath, "<?php\nreturn $exported;\n");

ft_topology_output([
    'ok'                 => true,
    'node_role'          => $nodeRole,
    'previous_node_role' => $current['node_role'],
    'changed'            => $current['node_role'] !== $nodeRole,
]);
