<?php

declare(strict_types=1);

/**
 * A FountainTrade önfrissítő rendszerének telepítés UTÁNI ellenőrző/
 * migrációs lépése — lásd src/UpdateInstaller.php::runPostDeployCheck()
 * docblockja, MIÉRT kell ennek egy KÜLÖN, frissen indított PHP-folyamatban
 * futnia (a hívó folyamat memóriájában még a RÉGI kód osztályai vannak
 * betöltve, PHP nem tölti újra őket futás közben).
 *
 * A `new Database(...)` hívás MAGA futtatja le a tényleges migrációt
 * (lásd Database::ensureSchema()) — nincs itt külön "migrációs futtató
 * logika", a MEGLÉVŐ, éles migrációs rendszert használjuk újra (14. pont).
 *
 * Kimenet: EGYETLEN JSON sor a stdout-ra, `{"success":true,...}` vagy
 * `{"success":false,"error":"..."}`, exit code 0/1 — a hívó
 * (UpdateInstaller) ezt olvassa vissza.
 *
 * Futtatás: php tools/update-post-deploy-check.php [app-gyökér-útvonal]
 * Az opcionális argumentum KIZÁRÓLAG teszteléshez való (lásd
 * tests/UpdateInstallerTest.php) — nélküle a script saját helyét
 * (dirname(__DIR__)) használja app-gyökérnek.
 */

$appRoot = $argv[1] ?? dirname(__DIR__);
$appRoot = rtrim($appRoot, '/');

function respond(array $data, int $exitCode): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE), "\n";
    exit($exitCode);
}

$requiredClasses = [
    'src/Database.php'       => 'Database',
    'src/AppVersion.php'     => 'AppVersion',
    'src/Settings.php'       => 'Settings',
    'src/BackupManager.php'  => 'BackupManager',
    'src/InvoiceService.php' => 'InvoiceService',
];
foreach ($requiredClasses as $relativeFile => $expectedClass) {
    $path = $appRoot . '/' . $relativeFile;
    if (!is_file($path)) {
        respond(['success' => false, 'error' => "Hiányzó kritikus fájl a telepített kódban: $relativeFile"], 1);
    }
    require_once $path;
    if (!class_exists($expectedClass, false)) {
        respond(['success' => false, 'error' => "A(z) $relativeFile betöltése nem hozta létre a várt $expectedClass osztályt."], 1);
    }
}

if (!is_file($appRoot . '/config/config.php')) {
    respond(['success' => false, 'error' => 'Hiányzó config/config.php.'], 1);
}
$config = require $appRoot . '/config/config.php';
if (!is_array($config) || empty($config['db'])) {
    respond(['success' => false, 'error' => 'A config/config.php nem ad érvényes adatbázis-konfigurációt.'], 1);
}

// A Database-konstruktor itt futtatja le a tényleges migrációt.
try {
    $db = new Database($config['db'], $appRoot);
    $db->pdo()->query('SELECT 1');
} catch (Throwable $e) {
    respond(['success' => false, 'error' => 'Adatbázis-kapcsolat/migráció sikertelen: ' . $e->getMessage()], 1);
}

try {
    $schemaVersionRow = $db->pdo()->query('SELECT version FROM schema_version LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $schemaVersion = $schemaVersionRow ? (int) $schemaVersionRow['version'] : null;
} catch (Throwable $e) {
    respond(['success' => false, 'error' => 'A schema_version tábla nem olvasható a migráció után: ' . $e->getMessage()], 1);
}

// "Fontos endpointok" (15. pont) — valódi HTTP-kérés nélkül azt
// ellenőrizzük, hogy a legkritikusabb végpontok és függőségi láncuk
// szintaktikailag hibátlan (php -l) a FRISSEN kimásolt fájlokon.
$criticalEndpoints = [
    'webroot/api/sale.php',
    'webroot/api/settings.php',
    'webroot/api/system-status.php',
    'webroot/api/_bootstrap.php',
];
foreach ($criticalEndpoints as $endpoint) {
    $path = $appRoot . '/' . $endpoint;
    if (!is_file($path)) {
        respond(['success' => false, 'error' => "Hiányzó kritikus végpont: $endpoint"], 1);
    }
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        respond(['success' => false, 'error' => "Szintaktikai hiba ebben a kritikus fájlban ($endpoint): " . implode(' ', $output)], 1);
    }
}

respond([
    'success'        => true,
    'version'        => AppVersion::CURRENT,
    'schema_version' => $schemaVersion,
    'php_version'    => PHP_VERSION,
], 0);
