<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UX-02 (Phase 7 UX audit) — valódi HTTP-n (php -S): a POS eladás
 * (`api/sale.php`) elutasítja a fizetést, ha a kliens egy KONKRÉT
 * pénztárgépet nevez meg, azon viszont nincs nyitott műszak — akár azért,
 * mert sosem nyitották meg, akár mert időközben lezárták. Korábban ilyenkor
 * is létrejött az eladás, `cash_session_id = NULL` értékkel — nyomtalanul,
 * egyetlen kasszazárás sem számolta volna el (a "Kassza: ZÁRVA" jelző
 * tisztán dekoratív volt, semmit nem tiltott).
 *
 * Az ellenőrzés a szerveren (nem csak a UI-n) történik: egy nyers, a
 * frontendet megkerülő HTTP POST (más `cash_register_id`-vel, mint amit a
 * kliens ismerne) is ugyanígy elutasításra kerül — lásd
 * testDirectHttpRequestCannotBypassTheCheck().
 *
 * A "nincs semennyi pénztárgép" eset (a hívó EGYÁLTALÁN nem küld
 * `cash_register_id`-t — a kasszakezelést nem használó boltok visszafelé
 * kompatibilis útvonala, lásd api/sale.php eleji docblokkja) SZÁNDÉKOSAN
 * változatlan marad — ezt a Phase 7 audit sem kifogásolta, és a
 * remediation-feladat explicit tiltja az adatmodell üzleti jelentésének
 * megváltoztatását. Lásd tests/CashSessionSaleRaceConcurrencyTest.php a
 * `Database::insertSale()` primitívum saját, ettől független,
 * versenyhelyzet-toleráns viselkedéséért (ott EXPLICIT elvárás, hogy egy a
 * beszúrás pillanatában PONT bezáruló műszaknál a sor sikeresen, NULL
 * cash_session_id-vel jöjjön létre) — ez a teszt a magasabb szintű, a
 * kérés ELEJÉN ellenőrzött előfeltételt bizonyítja, nem ugyanazt a
 * primitívumot.
 */
final class CashSessionSaleEnforcementHttpTest extends TestCase
{
    private static string $root;
    private static string $baseUrl;
    /** @var resource|null */
    private static $server;
    private static Database $db;
    private static int $productId;
    private static int $registerWithSession;
    private static int $registerNeverOpened;
    private static int $openSessionId;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_ux02_http_' . bin2hex(random_bytes(6));
        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        mkdir(self::$root . '/data', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', self::$root . '/webroot'], [1 => ['file', self::$root . '/server.log', 'w'], 2 => ['file', self::$root . '/server.log', 'w']], $pipes, self::$root);
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && !@fsockopen('127.0.0.1', $port, $e, $es, 0.2)) {
            usleep(100_000);
        }
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::request('GET', '/api/auth-status.php');

        self::$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        self::$productId = self::$db->saveProduct(['name' => 'UX-02 termék', 'barcode' => 'UX02-1', 'price' => 1000, 'net_price' => 787.4, 'vat_rate' => '27']);
        self::$db->setStock(self::$productId, 1000);

        $locationId = self::$db->saveLocation(['name' => 'UX-02 bolt']);
        self::$registerWithSession = self::$db->saveCashRegister(['location_id' => $locationId, 'name' => 'Kassza A', 'code' => 'UX02A']);
        self::$registerNeverOpened = self::$db->saveCashRegister(['location_id' => $locationId, 'name' => 'Kassza B', 'code' => 'UX02B']);
        self::$openSessionId = self::$db->openCashSession(self::$registerWithSession, null, 10000.0);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private static function copyDir(string $from, string $to, array $exclude): void
    {
        @mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) {
                continue;
            }
            is_dir("$from/$item") ? self::copyDir("$from/$item", "$to/$item", $exclude) : copy("$from/$item", "$to/$item");
        }
    }

    private static function sale(?int $cashRegisterId, string $idempotencyKey): array
    {
        $payload = [
            'items' => [['product_id' => self::$productId, 'qty' => 1]],
            'payment_method' => 'Készpénz',
            'idempotency_key' => $idempotencyKey,
        ];
        if ($cashRegisterId !== null) {
            $payload['cash_register_id'] = $cashRegisterId;
        }
        $csrf = self::request('GET', '/api/auth-status.php')['json']['csrf_token'];
        return self::request('POST', '/api/sale.php', $payload, ['X-CSRF-Token' => $csrf]);
    }

    private static function request(string $method, string $path, $body = null, array $headers = []): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => self::$root . '/cookies.txt', CURLOPT_COOKIEFILE => self::$root . '/cookies.txt',
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            self::fail('curl hiba: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $raw, true);
        return ['status' => $status, 'body' => $raw, 'json' => is_array($json) ? $json : null];
    }

    // ------------------------------------------------------------------
    // 1) open session → sale sikeres
    // ------------------------------------------------------------------
    public function testSaleSucceedsOnARegisterWithAnOpenSession(): void
    {
        $res = self::sale(self::$registerWithSession, 'ux02-open-' . bin2hex(random_bytes(4)));
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertArrayHasKey('sale_id', $res['json']);

        $row = self::$db->pdo()->prepare('SELECT cash_session_id FROM sales WHERE id = ?');
        $row->execute([$res['json']['sale_id']]);
        $this->assertSame(self::$openSessionId, (int) $row->fetchColumn(), 'A sikeres eladás a TÉNYLEGES nyitott műszakhoz kötődjön.');
    }

    // ------------------------------------------------------------------
    // 2) no session (a pénztárgép létezik, de sosem nyitották meg) → elutasítva
    // ------------------------------------------------------------------
    public function testSaleIsRejectedOnARegisterThatWasNeverOpened(): void
    {
        $before = (int) self::$db->pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();

        $res = self::sale(self::$registerNeverOpened, 'ux02-never-opened-' . bin2hex(random_bytes(4)));
        $this->assertSame(409, $res['status'], $res['body']);
        $this->assertSame('cash_session_required', $res['json']['code'] ?? null);
        $this->assertStringContainsString('Nincs nyitva', $res['json']['error'] ?? '');

        $after = (int) self::$db->pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();
        $this->assertSame($before, $after, 'Elutasított eladásnál egyetlen sales-sor sem jöhet létre.');
    }

    // ------------------------------------------------------------------
    // 3) closed session → elutasítva
    // ------------------------------------------------------------------
    public function testSaleIsRejectedAfterTheSessionOnThatRegisterIsClosed(): void
    {
        $locationId = self::$db->saveLocation(['name' => 'UX-02 bolt 2']);
        $registerId = self::$db->saveCashRegister(['location_id' => $locationId, 'name' => 'Kassza C', 'code' => 'UX02C']);
        $sessionId = self::$db->openCashSession($registerId, null, 5000.0);

        $openRes = self::sale($registerId, 'ux02-before-close-' . bin2hex(random_bytes(4)));
        $this->assertSame(200, $openRes['status'], 'Nyitott műszakon a fizetésnek sikeresnek kell lennie a lezárás előtt.');

        self::$db->closeCashSession($sessionId, 5000.0, ['Készpénz']);

        $before = (int) self::$db->pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();
        $res = self::sale($registerId, 'ux02-after-close-' . bin2hex(random_bytes(4)));
        $this->assertSame(409, $res['status'], $res['body']);
        $this->assertSame('cash_session_required', $res['json']['code'] ?? null);
        $after = (int) self::$db->pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();
        $this->assertSame($before, $after);
    }

    // ------------------------------------------------------------------
    // 4) direct HTTP — a UI-t megkerülő, nyers kérés sem kerülheti meg
    // ------------------------------------------------------------------
    public function testDirectHttpRequestCannotBypassTheCheck(): void
    {
        // Ugyanaz a kérés, mint amit a POS küldene — de itt közvetlenül,
        // semmilyen kliensoldali (app.js) ellenőrzésen nem megy át, ami
        // bizonyítja, hogy az elutasítás a SZERVEREN történik, nem csak a UI-n.
        $res = self::sale(self::$registerNeverOpened, 'ux02-direct-http-' . bin2hex(random_bytes(4)));
        $this->assertSame(409, $res['status'], $res['body']);
        $this->assertSame('cash_session_required', $res['json']['code'] ?? null);
    }

    // ------------------------------------------------------------------
    // 5) duplicate / retry — ugyanaz a kulcs konzisztensen, nem lazít az elutasításon
    // ------------------------------------------------------------------
    public function testRetryWithTheSameIdempotencyKeyStaysConsistentlyRejected(): void
    {
        $key = 'ux02-retry-' . bin2hex(random_bytes(4));
        $first = self::sale(self::$registerNeverOpened, $key);
        $this->assertSame(409, $first['status']);

        // Egy elutasított kérésnél NEM jön létre sales-sor, tehát a
        // findSaleByIdempotencyKey()-alapú visszajátszás sem található —
        // az újrapróbálkozás ugyanazt a friss ellenőrzést futtatja le, és
        // (mivel a műszak továbbra sincs nyitva) ugyanazt az elutasítást adja.
        $retry = self::sale(self::$registerNeverOpened, $key);
        $this->assertSame(409, $retry['status']);
        $this->assertSame('cash_session_required', $retry['json']['code'] ?? null);
    }

    // ------------------------------------------------------------------
    // 6) nincs cash_register_id — VÁLTOZATLAN, visszafelé kompatibilis útvonal
    // ------------------------------------------------------------------
    public function testSaleWithoutAnyCashRegisterIdStillSucceedsUnchanged(): void
    {
        // A kasszakezelést egyáltalán nem használó boltok útvonala — a
        // Phase 7 audit ezt nem kifogásolta, a remediation-feladat explicit
        // tiltja ennek megváltoztatását.
        $res = self::sale(null, 'ux02-no-register-' . bin2hex(random_bytes(4)));
        $this->assertSame(200, $res['status'], $res['body']);

        $row = self::$db->pdo()->prepare('SELECT cash_session_id FROM sales WHERE id = ?');
        $row->execute([$res['json']['sale_id']]);
        $this->assertNull($row->fetchColumn());
    }
}
