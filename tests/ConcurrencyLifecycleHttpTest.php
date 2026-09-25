<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * N-2 / N-3 / WooCommerce-törlés — VALÓDI TÖBBFOLYAMATOS HTTP-tesztek.
 *
 * Négy KÜLÖN PHP beépített szerver-folyamat fut ugyanazon az ideiglenes
 * repó-másolaton és ugyanazon az SQLite-adatbázison (mindegyik saját
 * bejelentkezett munkamenettel — egy közös munkamenet a PHP session-zár
 * miatt sorosítaná a kéréseket). A párhuzamos kéréseket curl_multi indítja
 * egyszerre, folyamatonként egyet.
 *
 * Determinisztikus átfedés: a teszt-folyamat a kérések indítása ELŐTT
 * író-zárat vesz (BEGIN IMMEDIATE; WAL-ban az olvasás nem blokkolódik),
 * így minden kérés átjut az idempotencia-előellenőrzésen és a saját első
 * írásánál vár. A zár (írás nélküli) elengedésekor a folyamatok valóban
 * egymással versenyeznek az írásért. Egyes tesztek zár nélkül is lefutnak
 * (természetes időzítés).
 *
 * Külső szolgáltatást nem hív: a WooCommerce-webhook helyben, HMAC-cal
 * aláírva érkezik; a WooCommerce-pusht a teszt-folyamat saját, hamis bolttal
 * futtatott WcPushQueueWorker-e hajtja végre. NEM élő integráció.
 */
final class ConcurrencyLifecycleHttpTest extends TestCase
{
    private const WEBHOOK_SECRET = 'n2-n3-lifecycle-webhook-secret';
    private const SERVERS = 4;

    private static string $root;
    /** @var string[] */
    private static array $baseUrls = [];
    /** @var resource[] */
    private static array $servers = [];
    /** @var string[] */
    private static array $jars = [];
    private static Database $db;
    private static string $dbPath;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_n2n3_http_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);
        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        foreach (['config', 'data', 'invoices'] as $dir) {
            mkdir(self::$root . '/' . $dir, 0775, true);
        }
        $config = str_replace("'change-me-webhook-secret'", var_export(self::WEBHOOK_SECRET, true), file_get_contents($projectRoot . '/config/config.php'));
        if (!str_contains($config, self::WEBHOOK_SECRET)) {
            self::fail('A teszt-config webhook-titka nem állítható be.');
        }
        file_put_contents(self::$root . '/config/config.php', $config);

        for ($i = 0; $i < self::SERVERS; $i++) {
            $sock = stream_socket_server('tcp://127.0.0.1:0');
            $name = stream_socket_get_name($sock, false);
            fclose($sock);
            $port = (int) substr($name, strrpos($name, ':') + 1);
            self::$baseUrls[$i] = 'http://127.0.0.1:' . $port;
            $log = self::$root . "/server$i.log";
            self::$servers[$i] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', self::$root . '/webroot'], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']], $pipes, self::$root);
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline && !@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) {
                usleep(100_000);
            }
        }

        self::request(0, 'GET', '/api/auth-status.php');
        self::$dbPath = self::$root . '/data/stock.sqlite';
        self::$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$dbPath]], self::$root);
        $setupJar = self::$root . '/cookies-setup.txt';
        $csrf = self::request(0, 'GET', '/api/auth-status.php', null, [], $setupJar)['json']['csrf_token'];
        self::request(0, 'POST', '/api/security-settings-save.php', ['app_password_enabled' => true, 'new_password' => 'n2n3-jelszo-teszt', 'new_password_confirm' => 'n2n3-jelszo-teszt'], ['X-CSRF-Token' => $csrf], $setupJar);
        for ($i = 0; $i < self::SERVERS; $i++) {
            self::$jars[$i] = self::$root . "/cookies-$i.txt";
            $login = self::request($i, 'POST', '/api/login.php', ['password' => 'n2n3-jelszo-teszt'], [], self::$jars[$i]);
            if ($login['status'] !== 200) {
                self::fail("Bejelentkezés a(z) $i. szerveren: " . $login['body']);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
        }
    }

    private static function copyDir(string $from, string $to, array $exclude): void
    {
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) {
                continue;
            }
            is_dir("$from/$item") ? self::copyDir("$from/$item", "$to/$item", $exclude) : copy("$from/$item", "$to/$item");
        }
    }

    private static function handle(int $server, string $method, string $path, $body = null, array $headers = [], ?string $jar = null, bool $raw = false)
    {
        $ch = curl_init(self::$baseUrls[$server] . $path);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $raw ? $body : json_encode($body));
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        return $ch;
    }

    private static function responseOf($ch, string $rawBody): array
    {
        $json = json_decode($rawBody, true);
        return ['status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $rawBody, 'json' => is_array($json) ? $json : null];
    }

    private static function request(int $server, string $method, string $path, $body = null, array $headers = [], ?string $jar = null, bool $raw = false): array
    {
        $ch = self::handle($server, $method, $path, $body, $headers, $jar, $raw);
        $out = (string) curl_exec($ch);
        $r = self::responseOf($ch, $out);
        curl_close($ch);
        return $r;
    }

    private function csrf(int $server): string
    {
        return self::request($server, 'GET', '/api/auth-status.php', null, [], self::$jars[$server])['json']['csrf_token'];
    }

    private function post(string $path, array $body, int $server = 0): array
    {
        return self::request($server, 'POST', $path, $body, ['X-CSRF-Token' => $this->csrf($server)], self::$jars[$server]);
    }

    /** Egy bejelentkezett API-kérés leírása a parallel() számára. */
    private function api(int $server, string $path, array $body): array
    {
        return ['server' => $server, 'path' => $path, 'body' => $body, 'headers' => ['X-CSRF-Token' => $this->csrf($server)], 'jar' => self::$jars[$server], 'raw' => false];
    }

    /** Egy aláírt WooCommerce-webhook leírása a parallel() / webhook() számára. */
    private function hook(int $server, array $order, ?string $topic = null): array
    {
        $raw = json_encode($order);
        $headers = ['X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $raw, self::WEBHOOK_SECRET, true))];
        if ($topic !== null) {
            $headers['X-WC-Webhook-Topic'] = $topic;
        }
        return ['server' => $server, 'path' => '/api/webhook.php', 'body' => $raw, 'headers' => $headers, 'jar' => null, 'raw' => true];
    }

    private function send(array $req): array
    {
        return self::request($req['server'], 'POST', $req['path'], $req['body'], $req['headers'], $req['jar'], $req['raw']);
    }

    /**
     * A kéréseket KÜLÖN szerver-folyamatokon, egyszerre indítja. $holdWriteLock
     * esetén az indítás előtt író-zárat vesz, és csak $hold másodperc után
     * engedi el (írás nélkül) — addigra minden kérés az első írásánál vár.
     * $whileBlocked (opcionális) a zár alatt, a zárat tartó kapcsolaton fut.
     *
     * @return array<int, array{status:int, body:string, json:?array}>
     */
    private function parallel(array $reqs, bool $holdWriteLock = true, float $hold = 1.5, ?callable $whileBlocked = null): array
    {
        $lock = null;
        if ($holdWriteLock) {
            $lock = new PDO('sqlite:' . self::$dbPath);
            $lock->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $lock->exec('PRAGMA busy_timeout = 5000');
            $lock->exec('BEGIN IMMEDIATE');
        }
        $multi = curl_multi_init();
        $handles = [];
        foreach ($reqs as $i => $req) {
            $handles[$i] = self::handle($req['server'], 'POST', $req['path'], $req['body'], $req['headers'], $req['jar'], $req['raw']);
            curl_multi_add_handle($multi, $handles[$i]);
        }
        if ($lock !== null) {
            $deadline = microtime(true) + $hold;
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 0.05);
            } while ($running && microtime(true) < $deadline);
            if ($whileBlocked !== null) {
                $whileBlocked($lock);
            }
            $lock->exec('COMMIT');
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.1);
        } while ($running);
        $out = [];
        foreach ($handles as $i => $ch) {
            $out[$i] = self::responseOf($ch, (string) curl_multi_getcontent($ch));
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);
        return $out;
    }

    private function scalar(string $sql, array $params = []): int
    {
        $stmt = self::$db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function linkedProduct(int $stock): array
    {
        $wcId = random_int(100000, 999999);
        $p = self::$db->saveProduct(['name' => 'N-HTTP ' . bin2hex(random_bytes(3)), 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => null]);
        if ($stock > 0) {
            self::$db->incrementStock($p, $stock);
        }
        self::$db->pdo()->prepare('UPDATE products SET wc_product_id = ?, sync_to_woocommerce = 1 WHERE id = ?')->execute([$wcId, $p]);
        return [$p, $wcId];
    }

    private function stock(int $p): int
    {
        return (int) self::$db->findProductById($p)['stock_qty'];
    }

    private function locStock(int $p, int $loc): int
    {
        return (int) (array_column(self::$db->getLocationStockForProduct($p), 'stock_qty', 'location_id')[$loc] ?? 0);
    }

    private function pushRows(int $p, ?string $trigger = null): int
    {
        return $trigger === null
            ? $this->scalar('SELECT COUNT(*) FROM wc_push_queue WHERE product_id = ?', [$p])
            : $this->scalar('SELECT COUNT(*) FROM wc_push_queue WHERE product_id = ? AND trigger_type = ?', [$p, $trigger]);
    }

    /** A teljes push-sort lefuttatja egy hamis bolttal; a bolt végső készletét adja vissza. */
    private function drainPushes(int $wcId): ?int
    {
        $store = new FakeWooStoreForLifecycleHttp();
        $worker = new WcPushQueueWorker(self::$db, $store);
        while ($worker->processDuePushes(50)['claimed'] > 0) {
        }
        return $store->stock[$wcId] ?? null;
    }

    private function assertNoRawDatabaseText(array $res): void
    {
        foreach (['SQLSTATE', 'INSERT ', 'SELECT ', 'UPDATE ', 'constraint', 'database is locked'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $res['body'], "Nyers adatbázis-szöveg a válaszban: $needle");
        }
    }

    /** Minden hívó ugyanazt a sikeres eredményt kapja; pontosan egy "első" (nem visszajátszott) válasz. */
    private function assertOneWinnerAndIdenticalResults(array $responses, string $idField): int
    {
        $ids = [];
        $firsts = 0;
        foreach ($responses as $i => $r) {
            $this->assertSame(200, $r['status'], "$i. folyamat: " . $r['body']);
            $this->assertNoRawDatabaseText($r);
            $ids[] = (int) $r['json'][$idField];
            if (empty($r['json']['replayed'])) {
                $firsts++;
            }
        }
        $this->assertCount(1, array_unique($ids), 'Minden hívó ugyanazt az eredményt kapja: ' . json_encode($ids));
        $this->assertSame(1, $firsts, 'Pontosan egy kérés hajtotta végre a műveletet, a többi visszajátszás.');
        return $ids[0];
    }

    // ==================================================================
    // N-1 — pénzmozgás vs. műszakzárás, két szerver-folyamaton
    // ==================================================================

    public function testN1AuditInterleavingOverHttpCloseWinsAndTheMovementIsRejected(): void
    {
        // Az audit sorrendje a végponton át: a mozgás-kérés átjut minden
        // ellenőrzésen, az írásnál vár; közben a műszak lezárul és commitol.
        $loc = self::$db->saveLocation(['name' => 'N1 det ' . bin2hex(random_bytes(2))]);
        $register = self::$db->saveCashRegister(['location_id' => $loc, 'name' => 'N1 det', 'code' => 'N1D' . bin2hex(random_bytes(3))]);
        $sid = self::$db->openCashSession($register, null, 10000.0);

        $res = $this->parallel([$this->api(0, '/api/cash-movement.php', ['cash_session_id' => $sid, 'type' => 'cash_out', 'amount' => 3000, 'reason' => 'N1 audit', 'idempotency_key' => 'n1-det-' . bin2hex(random_bytes(6))])], true, 1.2, function (PDO $lock) use ($sid) {
            $lock->prepare("UPDATE cash_sessions SET status = 'closed', closing_amount = 10000, expected_amount = 10000, variance = 0, closed_at = ? WHERE id = ? AND status = 'open'")
                ->execute([date('Y-m-d H:i:s'), $sid]);
        })[0];

        $this->assertSame(409, $res['status'], $res['body']);
        $this->assertSame('Ez a műszak nincs nyitva, pénzmozgás nem rögzíthető hozzá.', $res['json']['error']);
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM cash_movements WHERE cash_session_id = ?', [$sid]));
        $session = self::$db->getCashSession($sid);
        $this->assertEqualsWithDelta((float) $session['expected_amount'], self::$db->computeExpectedCash($session, ['Készpénz']), 0.001);
    }

    public function testN1MovementAndCloseRacingAcrossProcessesNeverLeaveAClosedSessionWithAMissedMovement(): void
    {
        $loc = self::$db->saveLocation(['name' => 'N1 HTTP ' . bin2hex(random_bytes(2))]);
        $register = self::$db->saveCashRegister(['location_id' => $loc, 'name' => 'N1 HTTP', 'code' => 'N1H' . bin2hex(random_bytes(3))]);
        $seen = [];
        for ($round = 0; $round < 8; $round++) {
            $sid = self::$db->openCashSession($register, null, 10000.0);
            $movement = $this->api(0, '/api/cash-movement.php', ['cash_session_id' => $sid, 'type' => 'cash_out', 'amount' => 3000, 'reason' => 'N1 verseny', 'idempotency_key' => 'n1-http-' . bin2hex(random_bytes(6))]);
            $close = $this->api(1, '/api/cash-session-close.php', ['id' => $sid, 'counted_amount' => 7000]);
            [$mRes, $cRes] = $this->parallel([$movement, $close], $round % 3 !== 2, 1.0);

            $this->assertNoRawDatabaseText($mRes);
            $this->assertNoRawDatabaseText($cRes);
            $moved = $this->scalar('SELECT COUNT(*) FROM cash_movements WHERE cash_session_id = ?', [$sid]);
            if ($mRes['status'] === 200) {
                $this->assertSame(1, $moved);
            } else {
                $this->assertSame(409, $mRes['status'], $mRes['body']);
                $this->assertStringContainsString('nincs nyitva', $mRes['json']['error'] ?? '');
                $this->assertSame(0, $moved, 'Lezárt műszakba nem íródhat mozgás.');
            }
            // A zárás vagy lefutott, vagy újrapróbálható hibával (503) elbukott — ekkor a műszak nyitva maradt.
            $this->assertContains($cRes['status'], [200, 503], $cRes['body']);
            if ($cRes['status'] === 503) {
                $this->assertSame('open', self::$db->getCashSession($sid)['status']);
                $cRes = $this->post('/api/cash-session-close.php', ['id' => $sid, 'counted_amount' => 7000], 2);
                $this->assertSame(200, $cRes['status'], $cRes['body']);
            }
            $session = self::$db->getCashSession($sid);
            $this->assertSame('closed', $session['status']);
            $this->assertEqualsWithDelta(
                (float) $session['expected_amount'],
                self::$db->computeExpectedCash($session, ['Készpénz']),
                0.001,
                "$round. kör: a lezárt műszak elvárt összege nem hagyhat ki beírt mozgást (N-1)."
            );
            $seen[$mRes['status'] === 200 ? 'movement_won' : 'close_won'] = true;
        }
        $this->assertNotEmpty($seen);
    }

    // ==================================================================
    // N-2 — készletmozgatás
    // ==================================================================

    public function testN2SameKeyNewStockAcrossFourProcessesExecutesExactlyOnce(): void
    {
        [$p, $wcId] = $this->linkedProduct(0);
        $loc = self::$db->saveLocation(['name' => 'N2 cél ' . bin2hex(random_bytes(2))]);
        $key = 'n2-par-' . bin2hex(random_bytes(6));
        $body = ['product_id' => $p, 'from_location_id' => null, 'to_location_id' => $loc, 'qty' => 10, 'idempotency_key' => $key];

        $responses = $this->parallel(array_map(fn ($s) => $this->api($s, '/api/stock-transfer.php', $body), range(0, self::SERVERS - 1)));
        $transferId = $this->assertOneWinnerAndIdenticalResults($responses, 'transfer_id');

        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM stock_transfers WHERE product_id = ?', [$p]), 'Pontosan egy mozgatás.');
        $this->assertSame(10, $this->stock($p), 'Pontosan egy készletváltozás (nem 20/30/40).');
        $this->assertSame(10, $this->locStock($p, $loc));
        $this->assertSame(1, $this->pushRows($p, 'transfer'), 'Pontosan egy WooCommerce-queue mellékhatás.');
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM wc_push_queue WHERE trigger_type = 'transfer' AND trigger_id = ?", [$transferId]));
        $this->assertSame(10, $this->drainPushes($wcId), 'A push a helyi (egyszer növelt) készletet küldi.');
    }

    public function testN2SameKeyLocationToLocationAcrossFourProcessesExecutesExactlyOnce(): void
    {
        [$p] = $this->linkedProduct(0);
        $a = self::$db->saveLocation(['name' => 'N2 A ' . bin2hex(random_bytes(2))]);
        $b = self::$db->saveLocation(['name' => 'N2 B ' . bin2hex(random_bytes(2))]);
        self::$db->transferStock($p, null, $a, 10, null);
        $pushesBefore = $this->pushRows($p);
        $key = 'n2-l2l-' . bin2hex(random_bytes(6));
        $body = ['product_id' => $p, 'from_location_id' => $a, 'to_location_id' => $b, 'qty' => 4, 'idempotency_key' => $key];

        $responses = $this->parallel(array_map(fn ($s) => $this->api($s, '/api/stock-transfer.php', $body), range(0, self::SERVERS - 1)));
        $this->assertOneWinnerAndIdenticalResults($responses, 'transfer_id');

        $this->assertSame([6, 4, 10], [$this->locStock($p, $a), $this->locStock($p, $b), $this->stock($p)]);
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM stock_transfers WHERE idempotency_key = ?', [$key]));
        $this->assertSame($pushesBefore, $this->pushRows($p), 'Telephelyek közötti mozgatás: nincs push.');
    }

    public function testN2SameKeyBurstsWithoutAnInjectedLockNeverDoubleExecute(): void
    {
        [$p] = $this->linkedProduct(0);
        $loc = self::$db->saveLocation(['name' => 'N2 burst ' . bin2hex(random_bytes(2))]);
        for ($round = 1; $round <= 5; $round++) {
            $key = "n2-burst-$round-" . bin2hex(random_bytes(4));
            $body = ['product_id' => $p, 'to_location_id' => $loc, 'qty' => 3, 'idempotency_key' => $key];
            $responses = $this->parallel(array_map(fn ($s) => $this->api($s, '/api/stock-transfer.php', $body), range(0, self::SERVERS - 1)), false);
            $this->assertOneWinnerAndIdenticalResults($responses, 'transfer_id');
            $this->assertSame(3 * $round, $this->stock($p), "$round. kör után");
        }
        $this->assertSame(5, $this->pushRows($p, 'transfer'));
    }

    public function testN2DistinctKeysInParallelAreSeparateOperations(): void
    {
        [$p] = $this->linkedProduct(0);
        $a = self::$db->saveLocation(['name' => 'N2 dk A ' . bin2hex(random_bytes(2))]);
        $b = self::$db->saveLocation(['name' => 'N2 dk B ' . bin2hex(random_bytes(2))]);

        // Új készlet, két különböző kulcs → két valódi mozgatás.
        $new = $this->parallel([
            $this->api(0, '/api/stock-transfer.php', ['product_id' => $p, 'to_location_id' => $a, 'qty' => 5, 'idempotency_key' => 'n2-dk1-' . bin2hex(random_bytes(4))]),
            $this->api(1, '/api/stock-transfer.php', ['product_id' => $p, 'to_location_id' => $a, 'qty' => 5, 'idempotency_key' => 'n2-dk2-' . bin2hex(random_bytes(4))]),
        ]);
        $this->assertSame([200, 200], array_column($new, 'status'));
        $this->assertNotSame($new[0]['json']['transfer_id'], $new[1]['json']['transfer_id']);
        $this->assertSame([10, 10], [$this->stock($p), $this->locStock($p, $a)]);

        // Telephely→telephely, két különböző kulcs, együtt több, mint a forrás:
        // az üzleti szabály (szigorú forrás-csökkentés) dönt — egy nyer.
        $l2l = $this->parallel([
            $this->api(2, '/api/stock-transfer.php', ['product_id' => $p, 'from_location_id' => $a, 'to_location_id' => $b, 'qty' => 7, 'idempotency_key' => 'n2-dk3-' . bin2hex(random_bytes(4))]),
            $this->api(3, '/api/stock-transfer.php', ['product_id' => $p, 'from_location_id' => $a, 'to_location_id' => $b, 'qty' => 7, 'idempotency_key' => 'n2-dk4-' . bin2hex(random_bytes(4))]),
        ]);
        $statuses = array_column($l2l, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses, json_encode($l2l));
        $loser = $l2l[0]['status'] === 409 ? $l2l[0] : $l2l[1];
        $this->assertStringContainsString('nincs elég készlet', $loser['json']['error']);
        $this->assertSame([3, 7, 10], [$this->locStock($p, $a), $this->locStock($p, $b), $this->stock($p)]);
        $this->assertSame(3, $this->scalar('SELECT COUNT(*) FROM stock_transfers WHERE product_id = ?', [$p]));
    }

    public function testN2ImmediateDelayedAndLostResponseReplayReturnTheFirstResult(): void
    {
        [$p] = $this->linkedProduct(0);
        $a = self::$db->saveLocation(['name' => 'N2 rp A ' . bin2hex(random_bytes(2))]);
        $b = self::$db->saveLocation(['name' => 'N2 rp B ' . bin2hex(random_bytes(2))]);
        self::$db->transferStock($p, null, $a, 4, null);
        $key = 'n2-replay-' . bin2hex(random_bytes(6));
        $body = ['product_id' => $p, 'from_location_id' => $a, 'to_location_id' => $b, 'qty' => 4, 'idempotency_key' => $key];

        $first = $this->post('/api/stock-transfer.php', $body, 0);
        $this->assertSame(200, $first['status'], $first['body']);
        $this->assertArrayNotHasKey('replayed', $first['json']);

        $immediate = $this->post('/api/stock-transfer.php', $body, 1);
        sleep(1);
        // Késleltetett / elveszett válasz utáni újraküldés: a forrás közben 0
        // lett, a visszajátszás mégis az eredeti sikert adja (nem 400-at).
        $delayed = $this->post('/api/stock-transfer.php', $body, 2);
        foreach ([$immediate, $delayed] as $r) {
            $this->assertSame(200, $r['status'], $r['body']);
            $this->assertSame(['ok' => true, 'transfer_id' => $first['json']['transfer_id'], 'replayed' => true], $r['json']);
        }
        $this->assertSame([0, 4], [$this->locStock($p, $a), $this->locStock($p, $b)]);

        $mismatch = $this->post('/api/stock-transfer.php', ['qty' => 1] + $body, 3);
        $this->assertSame(409, $mismatch['status']);
        $this->assertStringContainsString('másik mozgatáshoz', $mismatch['json']['error']);
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM stock_transfers WHERE idempotency_key = ?', [$key]));
    }

    public function testN2RollbackAfterTheKeyWasWrittenLeavesNoKeyAndNoStockChange(): void
    {
        [$p] = $this->linkedProduct(0);
        $a = self::$db->saveLocation(['name' => 'N2 rb A ' . bin2hex(random_bytes(2))]);
        $b = self::$db->saveLocation(['name' => 'N2 rb B ' . bin2hex(random_bytes(2))]);
        self::$db->transferStock($p, null, $a, 5, null);
        $key = 'n2-rb-' . bin2hex(random_bytes(6));
        $body = ['product_id' => $p, 'from_location_id' => $a, 'to_location_id' => $b, 'qty' => 5, 'idempotency_key' => $key];

        // A kérés átjut a forrás-ellenőrzésen, és a kulcs-sor írásánál vár;
        // közben a forrás kiürül → a mozgatás a kulcs beírása UTÁN bukik el.
        $res = $this->parallel([$this->api(0, '/api/stock-transfer.php', $body)], true, 1.2, function (PDO $lock) use ($p, $a) {
            $lock->prepare('UPDATE location_stock SET stock_qty = 0 WHERE product_id = ? AND location_id = ?')->execute([$p, $a]);
        })[0];
        $this->assertSame(409, $res['status'], $res['body']);
        $this->assertStringContainsString('időközben', $res['json']['error']);
        $this->assertNull(self::$db->findStockTransferByIdempotencyKey($key), 'Visszagörgetés után nem maradhat kulcs.');
        $this->assertSame([0, 0], [$this->locStock($p, $a), $this->locStock($p, $b)]);

        self::$db->pdo()->prepare('UPDATE location_stock SET stock_qty = 5 WHERE product_id = ? AND location_id = ?')->execute([$p, $a]);
        $retry = $this->post('/api/stock-transfer.php', $body, 1);
        $this->assertSame(200, $retry['status'], $retry['body']);
        $this->assertArrayNotHasKey('replayed', $retry['json'], 'A visszagörgetett kulcs nem "foglalt": a művelet most hajtódik végre.');
        $this->assertSame([0, 5], [$this->locStock($p, $a), $this->locStock($p, $b)]);
    }

    // ==================================================================
    // N-3 — részleges visszáru
    // ==================================================================

    /** Egy valódi, API-n át rögzített kasszai eladás telephelyről, WooCommerce-hez kötött termékkel. */
    private function apiSale(int $qty): array
    {
        [$p, $wcId] = $this->linkedProduct(0);
        $loc = self::$db->saveLocation(['name' => 'N3 bolt ' . bin2hex(random_bytes(2))]);
        self::$db->transferStock($p, null, $loc, 10, null);
        $sale = $this->post('/api/sale.php', ['items' => [['product_id' => $p, 'qty' => $qty]], 'payment_method' => 'Készpénz', 'location_id' => $loc, 'idempotency_key' => bin2hex(random_bytes(8))]);
        $this->assertSame(200, $sale['status'], $sale['body']);
        $saleId = (int) $sale['json']['sale_id'];
        $saleItemId = (int) self::$db->getSaleWithItems($saleId)['items'][0]['id'];
        return [$p, $wcId, $loc, $saleId, $saleItemId];
    }

    public function testN3SameKeyPartialReturnAcrossFourProcessesExecutesExactlyOnce(): void
    {
        [$p, $wcId, $loc, $saleId, $saleItemId] = $this->apiSale(3);
        $this->assertSame([7, 7], [$this->stock($p), $this->locStock($p, $loc)]);
        $returnPushesBefore = $this->pushRows($p, 'return');
        $key = 'n3-par-' . bin2hex(random_bytes(6));
        $body = ['sale_id' => $saleId, 'items' => [['sale_item_id' => $saleItemId, 'qty' => 1]], 'reason' => 'N3 párhuzamos', 'idempotency_key' => $key];

        $responses = $this->parallel(array_map(fn ($s) => $this->api($s, '/api/return-create.php', $body), range(0, self::SERVERS - 1)));
        $returnId = $this->assertOneWinnerAndIdenticalResults($responses, 'return_id');
        foreach ($responses as $r) {
            $this->assertEqualsWithDelta(1270.0, (float) $r['json']['total_refund'], 0.001);
        }

        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM returns WHERE sale_id = ?', [$saleId]), 'Pontosan egy visszáru.');
        $this->assertSame(1, $this->scalar('SELECT SUM(qty) FROM return_items WHERE return_id = ?', [$returnId]));
        $this->assertSame([8, 8], [$this->stock($p), $this->locStock($p, $loc)], 'Egyszeres készlet- és telephely-visszaírás.');
        $this->assertSame($returnPushesBefore + 1, $this->pushRows($p, 'return'), 'Pontosan egy WooCommerce-queue mellékhatás.');
        $this->assertSame(8, $this->drainPushes($wcId));

        // A mennyiségi védelem (F-03) külön rétegként megmaradt: még 2 db vihető.
        $more = $this->post('/api/return-create.php', ['sale_id' => $saleId, 'items' => [['sale_item_id' => $saleItemId, 'qty' => 3]], 'idempotency_key' => 'n3-more-' . bin2hex(random_bytes(4))]);
        $this->assertSame(400, $more['status'], $more['body']);
        $this->assertStringContainsString('legfeljebb 2 db', $more['json']['error']);
    }

    public function testN3SameKeyFullReturnAcrossFourProcessesExecutesExactlyOnce(): void
    {
        [$p, , $loc, $saleId, $saleItemId] = $this->apiSale(2);
        $key = 'n3-full-' . bin2hex(random_bytes(6));
        $body = ['sale_id' => $saleId, 'items' => [['sale_item_id' => $saleItemId, 'qty' => 2]], 'idempotency_key' => $key];

        $responses = $this->parallel(array_map(fn ($s) => $this->api($s, '/api/return-create.php', $body), range(0, self::SERVERS - 1)));
        $this->assertOneWinnerAndIdenticalResults($responses, 'return_id');
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM returns WHERE sale_id = ?', [$saleId]));
        $this->assertSame([10, 10], [$this->stock($p), $this->locStock($p, $loc)]);
    }

    public function testN3DistinctKeysInParallelAreDecidedByTheBusinessRules(): void
    {
        [$p, , , $saleId, $saleItemId] = $this->apiSale(2);
        $mk = fn (int $s) => $this->api($s, '/api/return-create.php', ['sale_id' => $saleId, 'items' => [['sale_item_id' => $saleItemId, 'qty' => 2]], 'idempotency_key' => "n3-dk$s-" . bin2hex(random_bytes(4))]);

        $responses = $this->parallel([$mk(0), $mk(1)]);
        $ok = array_values(array_filter($responses, fn ($r) => $r['status'] === 200));
        $this->assertCount(1, $ok, json_encode($responses));
        $loser = $responses[0]['status'] === 200 ? $responses[1] : $responses[0];
        // A vesztes vagy üzleti elutasítást kap (a mennyiségi szabály), vagy —
        // ha az SQLite a már elavult olvasási pillanatképéről nem válthatott
        // írásra — egy újrapróbálható 503-at; nyers DB-szöveg egyikben sincs.
        $this->assertContains($loser['status'], [409, 503], $loser['body']);
        $this->assertNoRawDatabaseText($loser);
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM returns WHERE sale_id = ?', [$saleId]));
        $this->assertSame(10, $this->stock($p));

        // Az újrapróbált vesztes (saját kulcsával) már az üzleti szabályba ütközik.
        $retry = $this->send($mk(1));
        $this->assertSame(400, $retry['status'], $retry['body']);
    }

    public function testN3ImmediateDelayedReplayAndKeyMisuse(): void
    {
        [$p, , , $saleId, $saleItemId] = $this->apiSale(3);
        $key = 'n3-replay-' . bin2hex(random_bytes(6));
        $body = ['sale_id' => $saleId, 'items' => [['sale_item_id' => $saleItemId, 'qty' => 3]], 'idempotency_key' => $key];

        $first = $this->post('/api/return-create.php', $body, 0);
        $this->assertSame(200, $first['status'], $first['body']);
        $immediate = $this->post('/api/return-create.php', $body, 1);
        sleep(1);
        $delayed = $this->post('/api/return-create.php', $body, 2); // már semmi sem vihető vissza — mégis a replay jön
        foreach ([$immediate, $delayed] as $r) {
            $this->assertSame(200, $r['status'], $r['body']);
            $this->assertSame($first['json']['return_id'], $r['json']['return_id']);
            $this->assertTrue($r['json']['replayed']);
        }
        $this->assertSame(10, $this->stock($p));

        $mismatch = $this->post('/api/return-create.php', ['items' => [['sale_item_id' => $saleItemId, 'qty' => 1]]] + $body, 3);
        $this->assertSame(409, $mismatch['status'], $mismatch['body']);
        [, , , $otherSale, $otherItem] = $this->apiSale(1);
        $foreign = $this->post('/api/return-create.php', ['sale_id' => $otherSale, 'items' => [['sale_item_id' => $otherItem, 'qty' => 1]], 'idempotency_key' => $key], 0);
        $this->assertSame(409, $foreign['status'], 'Egy másik eladás visszárujához használt kulcs.');
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM returns WHERE idempotency_key = ?', [$key]));
    }

    public function testN3FailureAfterTheKeyWasWrittenRollsBackEverythingAndTheKeyIsFree(): void
    {
        [$p, , $loc, $saleId, $saleItemId] = $this->apiSale(2);
        $key = 'n3-rb-' . bin2hex(random_bytes(6));
        $body = ['sale_id' => $saleId, 'items' => [['sale_item_id' => $saleItemId, 'qty' => 1]], 'idempotency_key' => $key];
        $pushesBefore = $this->pushRows($p);

        // Hibainjektálás a returns-sor (a kulcs) beírása UTÁNI írásra.
        self::$db->pdo()->exec("CREATE TRIGGER n3_inject_fail BEFORE INSERT ON return_items BEGIN SELECT RAISE(ABORT, 'n3 injected failure'); END");
        try {
            $res = $this->post('/api/return-create.php', $body, 1);
        } finally {
            self::$db->pdo()->exec('DROP TRIGGER n3_inject_fail');
        }
        $this->assertSame(500, $res['status'], $res['body']);
        $this->assertNoRawDatabaseText($res);
        $this->assertStringNotContainsString('n3 injected', $res['body']);
        $this->assertNull(self::$db->findReturnByIdempotencyKey($key), 'Nem maradt kulcs.');
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM returns WHERE sale_id = ?', [$saleId]));
        $this->assertSame([8, 8, $pushesBefore], [$this->stock($p), $this->locStock($p, $loc), $this->pushRows($p)], 'Nincs félkész állapot.');

        $retry = $this->post('/api/return-create.php', $body, 2);
        $this->assertSame(200, $retry['status'], $retry['body']);
        $this->assertArrayNotHasKey('replayed', $retry['json']);
        $this->assertSame(9, $this->stock($p));
    }

    // ==================================================================
    // WooCommerce order.deleted / trash — valódi webhook-végponton át
    // ==================================================================

    private function wcOrder(int $id, string $status, int $wcProductId, int $qty): array
    {
        return ['id' => $id, 'number' => (string) $id, 'status' => $status, 'billing' => ['first_name' => 'Web', 'last_name' => 'Vevő'],
            'line_items' => [['product_id' => $wcProductId, 'quantity' => $qty, 'name' => 'Törlés', 'total' => (string) (1000 * $qty), 'total_tax' => (string) (270 * $qty)]]];
    }

    private function orderId(): int
    {
        return random_int(1_000_000, 9_000_000);
    }

    private function orderRows(int $wcOrderId): array
    {
        $stmt = self::$db->pdo()->prepare('SELECT status, wc_status FROM webshop_orders WHERE wc_order_id = ?');
        $stmt->execute([$wcOrderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deleted(int $server, int $wcOrderId): array
    {
        // A WooCommerce order.deleted webhookja csak az azonosítót küldi.
        return $this->send($this->hook($server, ['id' => $wcOrderId], 'order.deleted'));
    }

    public function testDeletionDraftDeletedReleasesTheReservationOnceAndPushesLocalStock(): void
    {
        [$p, $wcId] = $this->linkedProduct(10);
        $order = $this->orderId();
        $this->assertSame(200, $this->send($this->hook(0, $this->wcOrder($order, 'processing', $wcId, 3)))['status']);
        $this->assertSame(3, self::$db->getPendingWebOrderQty($p));
        $this->assertSame(7, $this->drainPushes($wcId), 'Foglalás: helyi készlet − függő rendelés.');

        $del = $this->deleted(1, $order);
        $this->assertSame(200, $del['status'], $del['body']);
        $this->assertSame(['ok' => true, 'status' => 'deleted', 'outcome' => 'released', 'order_id' => $order], $del['json']);
        $this->assertSame(0, self::$db->getPendingWebOrderQty($p));
        $this->assertSame(1, $this->pushRows($p, 'web_cancel'));
        $this->assertSame([['status' => 'rejected', 'wc_status' => 'deleted']], $this->orderRows($order));
        $this->assertSame(10, $this->drainPushes($wcId), 'A push a helyi készletet küldi, foglalás nélkül.');
        $this->assertSame(10, $this->stock($p), 'A helyi készlet nem változott (a foglalás nem vont le).');
    }

    public function testDeletionDraftTrashReleasesTheReservation(): void
    {
        [$p, $wcId] = $this->linkedProduct(10);
        $order = $this->orderId();
        $this->send($this->hook(0, $this->wcOrder($order, 'processing', $wcId, 2)));
        $trash = $this->send($this->hook(1, $this->wcOrder($order, 'trash', $wcId, 2), 'order.updated'));
        $this->assertSame('released', $trash['json']['outcome'], $trash['body']);
        $this->assertSame(0, self::$db->getPendingWebOrderQty($p));
        $this->assertSame([['status' => 'rejected', 'wc_status' => 'trash']], $this->orderRows($order));
        $this->assertSame(10, $this->drainPushes($wcId));
    }

    public function testDeletionAfterCancellationAndDuplicateDeletionAreNoOps(): void
    {
        [$p, $wcId] = $this->linkedProduct(10);
        $order = $this->orderId();
        $this->send($this->hook(0, $this->wcOrder($order, 'processing', $wcId, 2)));
        $this->assertSame('released', $this->send($this->hook(1, $this->wcOrder($order, 'cancelled', $wcId, 2)))['json']['outcome']);
        $pushes = $this->pushRows($p);

        $this->assertSame('already_released', $this->deleted(2, $order)['json']['outcome'], 'draft→cancel→deleted');
        $this->assertSame('already_released', $this->deleted(3, $order)['json']['outcome'], 'duplikált törlés');
        $this->assertSame($pushes, $this->pushRows($p), 'A no-op törlés nem ütemez újabb pusht.');
        $this->assertSame(0, self::$db->getPendingWebOrderQty($p));
        $this->assertCount(1, $this->orderRows($order));
    }

    public function testStaleProcessingAfterDeletionOrCancellationNeverReserves(): void
    {
        [$p, $wcId] = $this->linkedProduct(10);

        // deleted → (régi) processing visszajátszás, a rendelés helyben még ismeretlen.
        $deletedFirst = $this->orderId();
        $del = $this->deleted(0, $deletedFirst);
        $this->assertSame('unknown', $del['json']['outcome'], $del['body']);
        $late = $this->send($this->hook(1, $this->wcOrder($deletedFirst, 'processing', $wcId, 4)));
        $this->assertSame(200, $late['status']);
        $this->assertTrue($late['json']['ignored'] ?? false, $late['body']);

        // cancelled → (régi) processing.
        $cancelledFirst = $this->orderId();
        $this->assertSame('unknown', $this->send($this->hook(2, $this->wcOrder($cancelledFirst, 'cancelled', $wcId, 4)))['json']['outcome']);
        $this->assertTrue($this->send($this->hook(3, $this->wcOrder($cancelledFirst, 'processing', $wcId, 4)))['json']['ignored'] ?? false);

        $this->assertSame(0, self::$db->getPendingWebOrderQty($p), 'Elavult processing nem hoz létre foglalást.');
        $this->assertSame(0, $this->pushRows($p, 'web_order'));
        $this->assertSame([['status' => 'rejected', 'wc_status' => 'deleted']], $this->orderRows($deletedFirst), 'Sírkő a meglévő rejected állapottal.');
        $this->assertSame([['status' => 'rejected', 'wc_status' => 'cancelled']], $this->orderRows($cancelledFirst));
        $this->assertSame(10, $this->stock($p));
    }

    public function testDeletionOfConfirmedOrderKeepsTheSaleAndRejectedOrderIsANoOp(): void
    {
        [$p, $wcId] = $this->linkedProduct(10);

        // processing → confirmed → deleted: a helyi eladás marad, nincs vak visszatöltés.
        $confirmed = $this->orderId();
        $draftId = (int) $this->send($this->hook(0, $this->wcOrder($confirmed, 'processing', $wcId, 2)))['json']['draft_id'];
        $this->assertSame(200, $this->post('/api/webshop-order-confirm.php', ['id' => $draftId, 'payment_method' => 'Utánvét'])['status']);
        $this->assertSame(8, $this->stock($p));
        $kept = $this->deleted(1, $confirmed);
        $this->assertSame('confirmed_kept', $kept['json']['outcome'], $kept['body']);
        $this->assertSame(8, $this->stock($p), 'Nincs vak készlet-visszaállítás.');
        $this->assertSame('confirmed', $this->orderRows($confirmed)[0]['status']);

        // Helyben elutasított rendelés törlése: no-op.
        $rejected = $this->orderId();
        $rejDraft = (int) $this->send($this->hook(2, $this->wcOrder($rejected, 'processing', $wcId, 1)))['json']['draft_id'];
        $this->assertSame(200, $this->post('/api/webshop-order-reject.php', ['id' => $rejDraft])['status']);
        $pushes = $this->pushRows($p);
        $this->assertSame('already_released', $this->deleted(3, $rejected)['json']['outcome']);
        $this->assertSame($pushes, $this->pushRows($p));

        $this->assertSame(0, self::$db->getPendingWebOrderQty($p));
        $this->assertSame(8, $this->drainPushes($wcId));
    }

    public function testDeletingOneOfSeveralPendingOrdersReleasesOnlyThatReservation(): void
    {
        [$p, $wcId] = $this->linkedProduct(10);
        $x = $this->orderId();
        $y = $x + 1;
        $this->send($this->hook(0, $this->wcOrder($x, 'processing', $wcId, 2)));
        $this->send($this->hook(1, $this->wcOrder($y, 'processing', $wcId, 3)));
        $this->assertSame(5, self::$db->getPendingWebOrderQty($p));

        $this->assertSame('released', $this->deleted(2, $x)['json']['outcome']);
        $this->assertSame(3, self::$db->getPendingWebOrderQty($p), 'A másik rendelés foglalása megmarad.');
        $this->assertSame('draft', $this->orderRows($y)[0]['status']);
        $this->assertSame(7, $this->drainPushes($wcId), 'Push: 10 helyi − 3 még függő.');
    }

    public function testOutOfOrderProcessingAndCancellationRaceAcrossProcessesNeverLeavesAReservation(): void
    {
        [$p, $wcId] = $this->linkedProduct(10);
        $outcomes = [];
        for ($round = 0; $round < 12; $round++) {
            $order = $this->orderId();
            $terminal = $round % 2 === 0
                ? $this->hook(1, $this->wcOrder($order, 'cancelled', $wcId, 2))
                : $this->hook(1, ['id' => $order], 'order.deleted');
            $processing = $this->hook(0, $this->wcOrder($order, 'processing', $wcId, 2));
            // Felváltva: a lemondás indul elsőként, ill. zárral / zár nélkül.
            $reqs = $round % 4 < 2 ? [$terminal, $processing] : [$processing, $terminal];
            $responses = $this->parallel($reqs, $round % 3 !== 0, 1.0);
            [$termRes, $procRes] = $round % 4 < 2 ? $responses : [$responses[1], $responses[0]];

            $this->assertSame(200, $termRes['status'], $termRes['body']);
            $this->assertSame(200, $procRes['status'], $procRes['body']);
            $outcome = $termRes['json']['outcome'];
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            if ($outcome === 'released') {
                $this->assertNotEmpty($procRes['json']['draft_id'] ?? null, 'A processing nyert: draft jött létre, majd felszabadult.');
            } else {
                $this->assertSame('unknown', $outcome);
                $this->assertTrue($procRes['json']['ignored'] ?? false, 'A lemondás/törlés nyert: a késő processing nem foglal. ' . $procRes['body']);
            }
            $rows = $this->orderRows($order);
            $this->assertCount(1, $rows);
            $this->assertSame('rejected', $rows[0]['status']);
            $this->assertSame(0, self::$db->getPendingWebOrderQty($p), "$round. kör: nem maradhat foglalás");
        }
        $this->assertSame(10, $this->drainPushes($wcId), 'Végső push: a teljes helyi készlet. Kimenetelek: ' . json_encode($outcomes));
        $this->assertSame(10, $this->stock($p));
    }
}

class FakeWooStoreForLifecycleHttp extends WooCommerceClient
{
    public array $stock = [];

    public function __construct()
    {
    }

    public function updateStock(int $wcProductId, int $qty): void
    {
        $this->stock[$wcProductId] = $qty;
    }

    public function pushProduct(int $wcProductId, array $fields): void
    {
    }
}
