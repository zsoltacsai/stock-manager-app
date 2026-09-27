<?php

declare(strict_types=1);

/**
 * PERF-01 — a FountainTrade webkiszolgálója Windows-on: N darab háttér-`php -S`
 * (127.0.0.1, külön portokon) + a diszpécser a konfigurált címen. Lásd
 * src/HttpDispatcher.php a működésért és az ütemezési szabályokért.
 *
 *   php tools/http-dispatcher.php --listen=localhost:8080 --webroot="C:\...\webroot"
 *       [--workers=3] [--background-workers=1] [--base-port=<listen+1>]
 *
 * A háttérfolyamatokat a diszpécser a SAJÁT figyelő socketje megnyitása
 * ELŐTT indítja: Windows-on a gyermekfolyamat örökli a szülő socketjeit, és
 * egy később indított `php -S` a diszpécser portját is nyitva tartaná.
 * Egy már futó, FountainTrade-ként válaszoló háttérfolyamatot (pl. egy
 * korábbi, leállított diszpécser után) újrahasznosít. Ha minden
 * háttérfolyamat elérhetetlenné válik, 1-es kóddal kilép — a Feladatütemező
 * újraindítási szabálya ekkor újraindítja az egészet.
 */

require_once __DIR__ . '/../src/HttpDispatcher.php';

$options = getopt('', ['listen:', 'webroot:', 'workers::', 'background-workers::', 'base-port::']);
$listen = (string) ($options['listen'] ?? '');
$webroot = (string) ($options['webroot'] ?? '');
if (!preg_match('/^(.+):(\d+)$/', $listen, $m) || !is_dir($webroot)) {
    fwrite(STDERR, "Használat: php http-dispatcher.php --listen=HOST:PORT --webroot=PATH [--workers=3] [--background-workers=1] [--base-port=N]\n");
    exit(2);
}
[$host, $port] = [trim($m[1], '[]'), (int) $m[2]];
$workers = max(1, (int) ($options['workers'] ?? 3));
$backgroundWorkers = max(0, (int) ($options['background-workers'] ?? 1));
$basePort = (int) ($options['base-port'] ?? ($port + 1));

$log = static function (string $message): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] http-dispatcher: $message\n");
};

$probe = static function (int $backendPort): bool {
    $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $body = @file_get_contents("http://127.0.0.1:$backendPort/api/server-ping.php", false, $context);
    return is_string($body) && str_contains($body, '"success":true');
};

$phpArgs = [PHP_BINARY];
$ini = php_ini_loaded_file();
if ($ini !== false) {
    $phpArgs[] = '-c';
    $phpArgs[] = $ini;
}

$backends = [];
$processes = [];
$nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
for ($i = 0; $i < $workers + $backgroundWorkers; $i++) {
    $backendPort = $basePort + $i;
    $pool = $i < $workers ? 'interactive' : 'background';
    $backends[] = ['port' => $backendPort, 'pool' => $pool];
    $existing = @stream_socket_client("tcp://127.0.0.1:$backendPort", $errno, $errstr, 0.3);
    if ($existing !== false) {
        fclose($existing);
        if (!$probe($backendPort)) {
            $log("A(z) $backendPort port foglalt, de nem FountainTrade háttérfolyamat válaszol rajta — válassz másik --base-port értéket.");
            exit(1);
        }
        $log("A(z) $backendPort porton már fut egy FountainTrade háttérfolyamat — újrahasznosítva.");
        continue;
    }
    $process = proc_open(
        array_merge($phpArgs, ['-S', "127.0.0.1:$backendPort", '-t', $webroot]),
        [['file', $nul, 'r'], ['file', $nul, 'w'], ['file', $nul, 'w']],
        $pipes,
        $webroot
    );
    if (!is_resource($process)) {
        $log("A(z) $backendPort háttérfolyamat nem indítható.");
        exit(1);
    }
    $processes[] = $process;
}

$deadline = microtime(true) + 15;
foreach ($backends as $b) {
    while (true) {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $b['port'], $errno, $errstr, 0.3);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        if (microtime(true) > $deadline) {
            $log('A(z) ' . $b['port'] . ' háttérfolyamat nem indult el 15 s alatt.');
            foreach ($processes as $p) {
                proc_terminate($p);
            }
            exit(1);
        }
        usleep(100000);
    }
}

// A név feloldása és a kötés ugyanúgy történik, mint a korábbi `php -S HOST:PORT`
// esetén (ugyanaz a PHP hálózati réteg) — pl. a 'localhost' itt is [::1]-re köt.
$bindHost = str_contains($host, ':') ? "[$host]" : $host;
$listener = @stream_socket_server("tcp://$bindHost:$port", $errno, $errstr);
if ($listener === false) {
    $log("A(z) $listen cím nem nyitható meg: $errstr");
    foreach ($processes as $p) {
        proc_terminate($p);
    }
    exit(1);
}

$dispatcher = new HttpDispatcher($listener, $backends, $log);
if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(static function () use ($dispatcher): void {
        $dispatcher->stop();
    });
}
$log(sprintf('%s címen figyel; %d pénztári + %d háttér-folyamat (%d–%d portok).', $listen, $workers, $backgroundWorkers, $basePort, $basePort + $workers + $backgroundWorkers - 1));

while (true) {
    $dispatcher->tick(0.25);
    if ($dispatcher->stats()['backends_down'] === count($backends)) {
        $log('Egyetlen háttérfolyamat sem érhető el — kilépés (a Feladatütemező újraindítja).');
        exit(1);
    }
    if (!$dispatcher->isRunning()) {
        break;
    }
}
foreach ($processes as $p) {
    proc_terminate($p);
}
