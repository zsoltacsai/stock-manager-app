<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/HttpDispatcher.php';

/**
 * PERF-01 — a többfolyamatos diszpécser (src/HttpDispatcher.php,
 * tools/http-dispatcher.php). Az integrációs tesztek VALÓDI folyamatokkal
 * futnak: a diszpécser CLI elindítja a háttér-`php -S` folyamatokat egy
 * szintetikus webrooton, és a kérések valódi TCP-n mennek át.
 */
final class HttpDispatcherTest extends TestCase
{
    private static string $root;
    private static $process;
    private static int $port;
    private static int $basePort;

    // ------------------------------------------------------------------
    // Tiszta függvények
    // ------------------------------------------------------------------

    public function testClassifyRecognisesRequestClassesRobustly(): void
    {
        $c = static fn (string $method, string $target, array $h = []) => HttpDispatcher::classify($method, $target, $h);
        $this->assertSame(HttpDispatcher::CLASS_EXCLUSIVE, $c('POST', '/api/backup-restore.php'));
        $this->assertSame(HttpDispatcher::CLASS_EXCLUSIVE, $c('POST', '/API//Backup-Restore.PHP?x=1'));
        $this->assertSame(HttpDispatcher::CLASS_EXCLUSIVE, $c('POST', '/api/%62ackup-restore.php'));
        $this->assertSame(HttpDispatcher::CLASS_EXCLUSIVE, $c('POST', '/x/../api/./backup-restore.php/extra'));
        $this->assertSame(HttpDispatcher::CLASS_EXCLUSIVE, $c('POST', '/api\\backup-restore.php.'));
        $this->assertSame(HttpDispatcher::CLASS_EXCLUSIVE, $c('POST', '/api/update-install.php'));
        $this->assertSame(HttpDispatcher::CLASS_WRITER_EXCLUSIVE, $c('POST', '/api/import-commit.php'));
        $this->assertSame(HttpDispatcher::CLASS_WRITER_EXCLUSIVE, $c('POST', '/api/stock-take-complete.php'));
        $this->assertSame(HttpDispatcher::CLASS_BACKGROUND, $c('GET', '/api/wc-queue-run.php'));
        $this->assertSame(HttpDispatcher::CLASS_BACKGROUND, $c('GET', '/api/anything.php', ['x-cron-token' => 'abc']));
        $this->assertSame(HttpDispatcher::CLASS_LONG, $c('POST', '/api/ai-agent-stream.php'));
        $this->assertSame(HttpDispatcher::CLASS_LONG, $c('POST', '/api/ai-copilot.php'));
        $this->assertSame(HttpDispatcher::CLASS_LONG, $c('GET', '/api/export-sales-csv.php?from=1'));
        $this->assertSame(HttpDispatcher::CLASS_LONG, $c('POST', '/api/backup-now.php'));
        $this->assertSame(HttpDispatcher::CLASS_LONG, $c('GET', '/api/sales-report.php?period=custom'), 'Hosszú időszakos riport (100 000 eladás/év mellett ~10 s).');
        $this->assertSame(HttpDispatcher::CLASS_LONG, $c('GET', '/api/products.php'), 'A teljes (streamelt) katalógus.');
        $this->assertSame(HttpDispatcher::CLASS_SHORT, $c('GET', '/api/products-page.php'));
        $this->assertSame(HttpDispatcher::CLASS_SHORT, $c('GET', '/api/product-search.php?q=x'));
        $this->assertSame(HttpDispatcher::CLASS_SHORT, $c('POST', '/api/sale.php'));
        $this->assertSame(HttpDispatcher::CLASS_SHORT, $c('GET', '/index.php'));
        $this->assertSame(HttpDispatcher::CLASS_SHORT, $c('GET', '/'));

        $this->assertTrue(HttpDispatcher::isWriterRequest('POST', HttpDispatcher::CLASS_SHORT));
        $this->assertTrue(HttpDispatcher::isWriterRequest('GET', HttpDispatcher::CLASS_BACKGROUND));
        $this->assertFalse(HttpDispatcher::isWriterRequest('GET', HttpDispatcher::CLASS_SHORT));
    }

    public function testRewriteHeadStripsSpoofableForwardingHeadersFromNonLoopbackPeers(): void
    {
        $head = "POST /api/sale.php HTTP/1.1\r\nHost: bolt:8080\r\nX-Forwarded-For: 1.2.3.4\r\nX_Forwarded_For: 5.6.7.8\r\nx-real-ip: 9.9.9.9\r\n"
            . "X-Forwarded-Proto: https\r\nForwarded: for=1.1.1.1\r\nConnection: keep-alive\r\nKeep-Alive: timeout=5\r\nContent-Length: 2\r\n\r\n";
        $r = HttpDispatcher::rewriteHead($head, '192.168.1.50');
        $this->assertNotNull($r);
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/api/sale.php', $r['target']);
        $this->assertStringStartsWith("POST /api/sale.php HTTP/1.1\r\nHost: bolt:8080\r\n", $r['head']);
        $this->assertStringContainsString("Content-Length: 2\r\n", $r['head']);
        $this->assertStringContainsString("X-Forwarded-For: 192.168.1.50\r\nConnection: close\r\n\r\n", $r['head']);
        foreach (['1.2.3.4', '5.6.7.8', '9.9.9.9', 'https', 'for=1.1.1.1', 'keep-alive', 'timeout=5'] as $spoof) {
            $this->assertStringNotContainsString($spoof, $r['head']);
        }
        $this->assertSame(1, substr_count(strtolower($r['head']), 'x-forwarded-for'));
    }

    public function testRewriteHeadKeepsForwardingHeadersFromLoopbackPeerLikeBefore(): void
    {
        $head = "GET / HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-For: 203.0.113.9\r\nX-Forwarded-Proto: https\r\n\r\n";
        foreach (['127.0.0.1', '::1'] as $peer) {
            $r = HttpDispatcher::rewriteHead($head, $peer);
            $this->assertStringContainsString("X-Forwarded-For: 203.0.113.9\r\n", $r['head']);
            $this->assertStringContainsString("X-Forwarded-Proto: https\r\n", $r['head']);
            $this->assertStringNotContainsString("X-Forwarded-For: $peer", $r['head']);
        }
    }

    public function testRewriteHeadRejectsMalformedRequests(): void
    {
        $this->assertNull(HttpDispatcher::rewriteHead("GARBAGE\r\n\r\n", '127.0.0.1'));
        $this->assertNull(HttpDispatcher::rewriteHead("GET / HTTP/1.1\r\n folded: header\r\n\r\n", '127.0.0.1'));
        $this->assertNull(HttpDispatcher::rewriteHead("GET / HTTP/1.1\r\nNoColon\r\n\r\n", '127.0.0.1'));
        $this->assertNull(HttpDispatcher::rewriteHead("GET / HTTP/2.0\r\n\r\n", '127.0.0.1'));
    }

    // ------------------------------------------------------------------
    // Integráció: valódi diszpécser + 3 pénztári + 1 háttér php -S
    // ------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_dispatcher_' . bin2hex(random_bytes(5));
        mkdir(self::$root . '/webroot/api', 0775, true);
        mkdir(self::$root . '/log', 0775, true);
        $log = var_export(self::$root . '/log/events.log', true);
        $track = '<?php $name = basename(__FILE__, ".php") . "#" . ($_GET["tag"] ?? ""); file_put_contents(' . $log . ', sprintf("%.3f start %s\n", microtime(true), $name), FILE_APPEND | LOCK_EX); register_shutdown_function(function () use ($name) { file_put_contents(' . $log . ', sprintf("%.3f end %s\n", microtime(true), $name), FILE_APPEND | LOCK_EX); }); usleep((int) (1e6 * (float) ($_GET["s"] ?? 0))); echo "done";';
        $files = [
            'api/server-ping.php' => '<?php echo json_encode(["success" => true, "app" => "FountainTrade"]);',
            'api/slow.php' => $track,
            'api/fast.php' => '<?php echo "fast";',
            'api/ai-test.php' => $track,
            'api/test-run.php' => $track,
            'api/backup-restore.php' => $track,
            'api/import-commit.php' => $track,
            'api/write.php' => $track,
            'api/echo.php' => '<?php $in = file_get_contents("php://input"); echo json_encode(["remote" => $_SERVER["REMOTE_ADDR"], "xff" => $_SERVER["HTTP_X_FORWARDED_FOR"] ?? null, "proto" => $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? null, "method" => $_SERVER["REQUEST_METHOD"], "len" => strlen($in), "sha" => hash("sha256", $in), "query" => $_GET]);',
            'api/stream.php' => '<?php while (ob_get_level()) ob_end_flush(); echo str_repeat("a", 10); flush(); usleep(1500000); echo "b";',
        ];
        foreach ($files as $path => $code) {
            file_put_contents(self::$root . '/webroot/' . $path, $code);
        }

        self::$port = self::freePort();
        self::$basePort = self::freePortRange(4);
        self::$process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/http-dispatcher.php', '--listen=127.0.0.1:' . self::$port, '--webroot=' . self::$root . '/webroot', '--workers=3', '--background-workers=1', '--base-port=' . self::$basePort],
            [['pipe', 'r'], ['file', self::$root . '/log/dispatcher.out', 'a'], ['file', self::$root . '/log/dispatcher.err', 'a']],
            $pipes
        );
        for ($i = 0; $i < 150; $i++) {
            $c = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 0.2);
            if ($c) {
                fclose($c);
                return;
            }
            usleep(100000);
        }
        self::fail('A diszpécser nem indult el: ' . @file_get_contents(self::$root . '/log/dispatcher.err'));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            $pid = proc_get_status(self::$process)['pid'];
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /T /PID $pid 2>NUL");
            } else {
                exec("pkill -P $pid 2>/dev/null");
                proc_terminate(self::$process);
            }
            proc_close(self::$process);
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir(self::$root);
    }

    protected function setUp(): void
    {
        @unlink(self::$root . '/log/events.log');
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        return $port;
    }

    private static function freePortRange(int $count): int
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $base = random_int(20000, 40000);
            $ok = true;
            for ($i = 0; $i < $count; $i++) {
                $s = @stream_socket_server('tcp://127.0.0.1:' . ($base + $i));
                if ($s === false) {
                    $ok = false;
                    break;
                }
                fclose($s);
            }
            if ($ok) {
                return $base;
            }
        }
        throw new RuntimeException('Nincs szabad porttartomány.');
    }

    private function handle(string $method, string $path, ?string $body = null, array $headers = []): CurlHandle
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        $h = ['Expect:'];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        return $ch;
    }

    /**
     * Kérések párhuzamosan, megadott késleltetéssel indítva.
     *
     * @param list<array{0: float, 1: CurlHandle}> $plan [indítás ennyi s múlva, kérés]
     * @return list<array{elapsed: float, finished_at: float, status: int, body: string}> a terv sorrendjében
     */
    private function runPlan(array $plan): array
    {
        $mh = curl_multi_init();
        $t0 = microtime(true);
        $started = [];
        $results = [];
        do {
            foreach ($plan as $i => [$delay, $ch]) {
                if (!isset($started[$i]) && microtime(true) - $t0 >= $delay) {
                    curl_multi_add_handle($mh, $ch);
                    $started[$i] = microtime(true);
                }
            }
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.01);
            while ($info = curl_multi_info_read($mh)) {
                foreach ($plan as $i => [, $ch]) {
                    if ($ch === $info['handle']) {
                        $results[$i] = ['elapsed' => microtime(true) - $started[$i], 'finished_at' => microtime(true) - $t0, 'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => (string) curl_multi_getcontent($ch)];
                    }
                }
            }
        } while (count($results) < count($plan));
        ksort($results);
        return array_values($results);
    }

    /** @return array<string, array{start: float, end: float}> */
    private function events(): array
    {
        $out = [];
        foreach (file(self::$root . '/log/events.log', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$ts, $kind, $name] = explode(' ', $line, 3);
            $out[$name][$kind] = (float) $ts;
        }
        return $out;
    }

    public function testConcurrentRequestsAreServedInParallelNotHeadOfLine(): void
    {
        $r = $this->runPlan([
            [0.0, $this->handle('GET', '/api/slow.php?s=3&tag=a')],
            [0.3, $this->handle('GET', '/api/fast.php')],
            [0.3, $this->handle('POST', '/api/echo.php', 'x')],
        ]);
        $this->assertSame([200, 200, 200], array_column($r, 'status'));
        $this->assertLessThan(1.0, $r[1]['elapsed'], 'Egy gyors kérés nem várhat a 3 s-os kérésre (head-of-line).');
        $this->assertLessThan(1.0, $r[2]['elapsed']);
        $this->assertGreaterThan(2.5, $r[0]['elapsed']);
    }

    public function testLongRequestsCanNeverTakeTheLastInteractiveWorker(): void
    {
        $r = $this->runPlan([
            [0.0, $this->handle('POST', '/api/ai-test.php?s=2&tag=1')],
            [0.0, $this->handle('POST', '/api/ai-test.php?s=2&tag=2')],
            [0.2, $this->handle('POST', '/api/ai-test.php?s=2&tag=3')],
            [0.5, $this->handle('POST', '/api/echo.php', 'sale')],
        ]);
        $this->assertSame([200, 200, 200, 200], array_column($r, 'status'));
        $this->assertLessThan(1.0, $r[3]['elapsed'], 'Két hosszú kérés mellett a harmadik pénztári folyamat a rövid kéréseké.');
        $e = $this->events();
        $this->assertGreaterThanOrEqual(min($e['ai-test#1']['end'], $e['ai-test#2']['end']) - 0.05, $e['ai-test#3']['start'], 'A harmadik hosszú kérés csak egy hosszú kérés végén indulhat.');
    }

    public function testCronRequestsRunOnlyOnTheBackgroundWorker(): void
    {
        $r = $this->runPlan([
            [0.0, $this->handle('GET', '/api/test-run.php?s=1.5&tag=1')],
            [0.1, $this->handle('GET', '/api/test-run.php?s=1.5&tag=2')],
            [0.3, $this->handle('GET', '/api/fast.php')],
            [0.3, $this->handle('GET', '/api/fast.php')],
            [0.3, $this->handle('GET', '/api/fast.php')],
        ]);
        $this->assertSame([200, 200, 200, 200, 200], array_column($r, 'status'));
        foreach ([2, 3, 4] as $i) {
            $this->assertLessThan(1.0, $r[$i]['elapsed'], 'A cron nem foglalhat pénztári folyamatot.');
        }
        $e = $this->events();
        $this->assertGreaterThanOrEqual($e['test-run#1']['end'] - 0.05, $e['test-run#2']['start'], 'A cron-kérések az egyetlen háttérfolyamaton sorban futnak.');
    }

    public function testExclusiveRequestWaitsForInFlightRequestsAndRunsAlone(): void
    {
        $r = $this->runPlan([
            [0.0, $this->handle('POST', '/api/ai-test.php?s=1.5&tag=inflight')],
            [0.3, $this->handle('POST', '/api/backup-restore.php?s=1&tag=restore')],
            [0.6, $this->handle('POST', '/api/write.php?tag=during')],
            [0.6, $this->handle('GET', '/api/test-run.php?tag=cron')],
        ]);
        $this->assertSame([200, 200, 200, 200], array_column($r, 'status'));
        $e = $this->events();
        $this->assertGreaterThanOrEqual($e['ai-test#inflight']['end'] - 0.05, $e['backup-restore#restore']['start'], 'A visszaállítás megvárja a folyamatban lévő kéréseket.');
        $this->assertGreaterThanOrEqual($e['backup-restore#restore']['end'] - 0.05, $e['write#during']['start'], 'A visszaállítás alatt új kérés nem indul.');
        $this->assertGreaterThanOrEqual($e['backup-restore#restore']['end'] - 0.05, $e['test-run#cron']['start'], 'A visszaállítás alatt cron sem indul.');
    }

    public function testWriterExclusiveImportHoldsWritersButServesReads(): void
    {
        $r = $this->runPlan([
            [0.0, $this->handle('POST', '/api/import-commit.php?s=1.5&tag=import')],
            [0.3, $this->handle('GET', '/api/fast.php')],
            [0.3, $this->handle('POST', '/api/write.php?tag=sale')],
        ]);
        $this->assertSame([200, 200, 200], array_column($r, 'status'));
        $this->assertLessThan(1.0, $r[1]['elapsed'], 'Import közben az olvasás kiszolgálható.');
        $e = $this->events();
        $this->assertGreaterThanOrEqual($e['import-commit#import']['end'] - 0.05, $e['write#sale']['start'], 'Import közben egy új író kérés vár (nem bukik el lock-hibával).');
    }

    public function testBodiesQueriesAndForwardingPassThroughUnchanged(): void
    {
        $payload = random_bytes(3 * 1024 * 1024 + 7);
        $r = $this->runPlan([[0.0, $this->handle('POST', '/api/echo.php?a=1&b=%C3%A1', $payload, ['Content-Type' => 'application/octet-stream', 'X-Forwarded-For' => '203.0.113.5', 'X-Forwarded-Proto' => 'https'])]]);
        $this->assertSame(200, $r[0]['status']);
        $json = json_decode($r[0]['body'], true);
        $this->assertSame(strlen($payload), $json['len']);
        $this->assertSame(hash('sha256', $payload), $json['sha']);
        $this->assertSame(['a' => '1', 'b' => 'á'], $json['query']);
        $this->assertSame('127.0.0.1', $json['remote']);
        // Loopback kliens: a fejlécek ugyanúgy továbbmennek, mint a diszpécser nélkül.
        $this->assertSame('203.0.113.5', $json['xff']);
        $this->assertSame('https', $json['proto']);
    }

    public function testNonLoopbackClientCannotSpoofForwardingHeaders(): void
    {
        $lanIp = gethostbyname(gethostname());
        if (!filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || str_starts_with($lanIp, '127.')) {
            $this->markTestSkipped('Nincs nem-loopback helyi IPv4-cím a teszthez.');
        }
        $port = self::freePort();
        $base = self::freePortRange(2);
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/http-dispatcher.php', "--listen=0.0.0.0:$port", '--webroot=' . self::$root . '/webroot', '--workers=1', '--background-workers=0', "--base-port=$base"],
            [['pipe', 'r'], ['file', self::$root . '/log/lan.err', 'a'], ['file', self::$root . '/log/lan.err', 'a']],
            $pipes
        );
        try {
            $body = false;
            for ($i = 0; $i < 100 && $body === false; $i++) {
                usleep(100000);
                $ctx = stream_context_create(['http' => ['header' => "X-Forwarded-For: 6.6.6.6\r\nX-Real-IP: 6.6.6.7\r\nX-Forwarded-Proto: https\r\n", 'timeout' => 3]]);
                $body = @file_get_contents("http://$lanIp:$port/api/echo.php", false, $ctx);
            }
            $this->assertIsString($body, 'A diszpécser nem érhető el a helyi LAN-címen.');
            $json = json_decode($body, true);
            $this->assertSame($lanIp, $json['xff'], 'A háttérfolyamat a valódi kliens-IP-t kapja X-Forwarded-For-ban.');
            $this->assertNull($json['proto'], 'Nem loopback kliens X-Forwarded-Proto fejléce nem jut tovább.');
        } finally {
            $pid = proc_get_status($proc)['pid'];
            PHP_OS_FAMILY === 'Windows' ? exec("taskkill /F /T /PID $pid 2>NUL") : proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function testStreamedResponsesAreForwardedIncrementally(): void
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 2);
        fwrite($socket, "GET /api/stream.php HTTP/1.1\r\nHost: localhost\r\n\r\n");
        stream_set_timeout($socket, 5);
        $t0 = microtime(true);
        $received = '';
        while (!str_contains($received, 'aaaaaaaaaa') && !feof($socket)) {
            $received .= fread($socket, 8192);
        }
        $firstChunkAfter = microtime(true) - $t0;
        $received .= stream_get_contents($socket);
        fclose($socket);
        $this->assertLessThan(1.0, $firstChunkAfter, 'A streamelt válasz első része nem várhat a válasz végére.');
        $this->assertStringEndsWith('aaaaaaaaaab', $received);
        $this->assertStringContainsString("Connection: close", $received);
    }

    public function testDispatcherAddsNegligibleLatency(): void
    {
        // Egy blokkoló, időkorlátos backend-csatlakozás Windows-on kérésenként
        // ~14 ms-ot (időzítő-ütem) adott hozzá — a nem-blokkoló csatlakozással
        // a többletidő ~1 ms alatti (mérve).
        $times = [];
        for ($i = 0; $i < 30; $i++) {
            $t = microtime(true);
            $r = $this->runPlan([[0.0, $this->handle('GET', '/api/fast.php')]]);
            $times[] = microtime(true) - $t;
            $this->assertSame(200, $r[0]['status']);
        }
        sort($times);
        $this->assertLessThan(0.008, $times[15], 'A diszpécser medián többletideje nem érheti el egy Windows-időzítő-ütemet.');
    }

    public function testRequestsFailOverWhenABackendProcessDies(): void
    {
        $port = self::freePort();
        $base = self::freePortRange(2);
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/http-dispatcher.php', "--listen=127.0.0.1:$port", '--webroot=' . self::$root . '/webroot', '--workers=2', '--background-workers=0', "--base-port=$base"],
            [['pipe', 'r'], ['file', self::$root . '/log/failover.err', 'a'], ['file', self::$root . '/log/failover.err', 'a']],
            $pipes
        );
        try {
            $ok = false;
            for ($i = 0; $i < 100 && !$ok; $i++) {
                usleep(100000);
                $ok = @file_get_contents("http://127.0.0.1:$port/api/fast.php") === 'fast';
            }
            $this->assertTrue($ok, 'A diszpécser nem indult el.');
            // Az első háttérfolyamat leállítása (a port szerint azonosítva).
            $killed = false;
            if (PHP_OS_FAMILY === 'Windows') {
                exec('netstat -ano', $lines);
                foreach ($lines as $line) {
                    if (preg_match('/^\s*TCP\s+127\.0\.0\.1:' . $base . '\s+\S+\s+LISTENING\s+(\d+)/', $line, $m)) {
                        exec('taskkill /F /PID ' . (int) $m[1] . ' 2>NUL');
                        $killed = true;
                    }
                }
            }
            if (!$killed) {
                $this->markTestSkipped('A háttérfolyamat nem azonosítható ezen a platformon.');
            }
            usleep(300000);
            for ($i = 0; $i < 6; $i++) {
                $ctx = stream_context_create(['http' => ['timeout' => 10]]);
                $this->assertSame('fast', @file_get_contents("http://127.0.0.1:$port/api/fast.php", false, $ctx), "A(z) $i. kérés a megmaradt háttérfolyamatra kerül.");
            }
            $this->assertStringContainsString("$base", (string) file_get_contents(self::$root . '/log/failover.err'), 'A kiesés naplózva.');
        } finally {
            $pid = proc_get_status($proc)['pid'];
            PHP_OS_FAMILY === 'Windows' ? exec("taskkill /F /T /PID $pid 2>NUL") : proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function testMalformedAndOversizedHeadsAreRejectedWithoutReachingABackend(): void
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 2);
        fwrite($socket, "NOT HTTP\r\n\r\n");
        stream_set_timeout($socket, 5);
        $this->assertStringStartsWith('HTTP/1.1 400', (string) stream_get_contents($socket));
        fclose($socket);

        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 2);
        fwrite($socket, "GET / HTTP/1.1\r\nX-Big: " . str_repeat('a', 70000));
        stream_set_timeout($socket, 5);
        $this->assertStringStartsWith('HTTP/1.1 431', (string) stream_get_contents($socket));
        fclose($socket);
    }
}
