<?php

declare(strict_types=1);

/**
 * PERF-01 — párhuzamos kiszolgálás a beépített PHP-szerverrel (Windows).
 *
 * A telepítő eddig egyetlen `php -S` folyamatot indított. Windows-on a
 * beépített szerver egyszálú (a PHP_CLI_SERVER_WORKERS ott nem támogatott),
 * így MINDEN kérés sorban futott: egy AI-stream, egy lassú külső hívás
 * (Számlázz.hu, NAV, WooCommerce), egy cron-futás vagy egy hosszú riport
 * alatt minden kassza várt (mérve: egy eladás 5,8–6,9 s-ot).
 *
 * Ez a diszpécser egy kis, eseményvezérelt TCP-továbbító a konfigurált
 * címen: minden bejövő kapcsolatot egy SZABAD háttér-`php -S` folyamatnak ad
 * (127.0.0.1-en, külön portokon), és a bájtokat változatlanul továbbítja
 * mindkét irányba — a streamelt válaszok (AI, SSE) és a feltöltések is
 * átlátszóan mennek át. Egy háttérfolyamat egyszerre egy kérést kap (a
 * `php -S` úgyis csak egyet szolgál ki), ezért egy hosszú kérés csak a saját
 * folyamatát foglalja. Nincs új infrastruktúra: ugyanaz a php.exe, ugyanaz a
 * webroot, a háttérfolyamatok pontosan a korábbi `php -S` parancsok.
 *
 * Ütemezés (lásd classify()):
 *   - háttér (cron, `*-run.php`): KIZÁRÓLAG a dedikált háttér-folyamaton —
 *     egy percenkénti WooCommerce/NAV-sor sose foglal pénztári folyamatot;
 *   - hosszú (AI, külső szolgáltatás, mentés, export): a pénztári folyamatok
 *     közül legfeljebb eggyel kevesebbet foglalhat, így rövid (kassza)
 *     kérésnek MINDIG marad szabad folyamat;
 *   - író-kizárólagos (import, leltárzárás): amíg fut, új író kérés (nem
 *     GET/HEAD, illetve cron) nem indul — a hosszú SQLite-író-tranzakció
 *     alatt a többi író a korábbi egyfolyamatos viselkedéshez hasonlóan
 *     VÁR, ahelyett hogy 5 s busy_timeout után „database is locked”-dal
 *     elbukna; az olvasások közben is kiszolgálhatók;
 *   - kizárólagos (visszaállítás, frissítés telepítése): megvárja, amíg
 *     minden folyamatban lévő kérés befejeződik, egyedül fut, és addig
 *     semmi új nem indul — a visszaállítás az élő adatbázis-fájlt cseréli,
 *     amit egy másik folyamat nyitott kapcsolata mellett nem lehet
 *     biztonságosan (lásd Database::closeForExternalFileReplacement()).
 *
 * Kliens-IP / HTTPS-jelzés: a háttérfolyamatok 127.0.0.1-ről látják a
 * kapcsolatot, ezért — ugyanúgy, mint egy ugyanazon a gépen futó reverse
 * proxy mögött (GeoBlocker::resolveClientIp(), Auth::isRequestHttps()) — a
 * diszpécser X-Forwarded-For fejlécben adja át a valódi címet. Nem loopback
 * kliensnél a kliens által küldött X-Forwarded-For/-Proto/-Host/-Port, X-Real-IP és Forwarded fejlécek
 * TÖRLŐDNEK (azokat eddig sem fogadta el az alkalmazás, mert a REMOTE_ADDR
 * nem loopback volt); loopback kliensnél (pl. egy helyi reverse proxy)
 * változatlanul mennek tovább, ugyanúgy, mint eddig. Így a bizalmi döntés
 * minden esetben azonos a diszpécser nélküli futtatáséval.
 */
final class HttpDispatcher
{
    public const CLASS_SHORT = 'short';
    public const CLASS_LONG = 'long';
    public const CLASS_BACKGROUND = 'background';
    public const CLASS_WRITER_EXCLUSIVE = 'writer_exclusive';
    public const CLASS_EXCLUSIVE = 'exclusive';

    /** Szkriptnevek (a kérés útvonalának bármely szegmenseként), osztályonként. */
    public const EXCLUSIVE_SCRIPTS = ['backup-restore.php', 'update-install.php'];
    public const WRITER_EXCLUSIVE_SCRIPTS = ['import-commit.php', 'stock-take-complete.php'];
    public const LONG_SCRIPT_PATTERN = '/^(ai-[a-z0-9-]+|ollama-[a-z-]+|backup-now|import-preview|export-[a-z-]+|sales-report|top-products-report|inventory-report|stock-movements-report|products|sync-pull|nav-incoming-sync-trigger|nav-test-connection|wc-test-connection|woocommerce-sync-retry|smtp-test|company-lookup|update-check|szamlazz-[a-z-]+|nav-invoice-retry|invoice-storno|invoice-modify|webshop-order-invoice|send-receipt-email|printer-test)\.php$/';

    private const MAX_HEAD_BYTES = 65536;
    private const HEAD_TIMEOUT_SECONDS = 30.0;
    private const CONNECT_TIMEOUT_SECONDS = 3.0;
    private const BUFFER_LIMIT = 1048576;
    private const READ_CHUNK = 65536;

    /** @var resource */
    private $listener;
    /** @var list<array{port:int, pool:string, conn:?int, down:bool}> */
    private array $backends = [];
    /** @var array<int, array<string, mixed>> */
    private array $conns = [];
    /** @var list<int> a szabad háttérfolyamatra váró kapcsolatok, érkezési sorrendben */
    private array $queue = [];
    private int $nextId = 1;
    private bool $running = true;
    private float $nextProbeAt = 0.0;
    /** @var callable(string): void */
    private $log;

    /**
     * @param list<array{port:int, pool:string}> $backends 'interactive' vagy 'background' pool
     */
    public function __construct($listener, array $backends, ?callable $log = null)
    {
        $this->listener = $listener;
        stream_set_blocking($this->listener, false);
        foreach ($backends as $b) {
            $this->backends[] = ['port' => (int) $b['port'], 'pool' => $b['pool'], 'conn' => null, 'down' => false];
        }
        $this->log = $log ?? static function (string $m): void {
            fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n");
        };
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * A kérés osztálya a metódus, az útvonal és a fejlécek alapján. Az útvonal
     * normalizált (százalékos kódolás, `.`/`..` szegmensek, ismételt és
     * fordított perjel, kis-/nagybetű — a Windows-fájlrendszer nem
     * különbözteti meg), és egy szkriptnév az útvonal bármely szegmenseként
     * számít (PATH_INFO: `/api/backup-restore.php/x` is azt a szkriptet futtatja).
     *
     * @param array<string, string> $headers kisbetűs név => érték
     */
    public static function classify(string $method, string $target, array $headers): string
    {
        $segments = self::pathSegments($target);
        foreach ($segments as $segment) {
            if (in_array($segment, self::EXCLUSIVE_SCRIPTS, true)) {
                return self::CLASS_EXCLUSIVE;
            }
        }
        foreach ($segments as $segment) {
            if (in_array($segment, self::WRITER_EXCLUSIVE_SCRIPTS, true)) {
                return self::CLASS_WRITER_EXCLUSIVE;
            }
        }
        foreach ($segments as $segment) {
            if (preg_match('/-run\.php$/', $segment)) {
                return self::CLASS_BACKGROUND;
            }
        }
        if (($headers['x-cron-token'] ?? '') !== '') {
            return self::CLASS_BACKGROUND;
        }
        foreach ($segments as $segment) {
            if (preg_match(self::LONG_SCRIPT_PATTERN, $segment)) {
                return self::CLASS_LONG;
            }
        }
        return self::CLASS_SHORT;
    }

    /** @return list<string> */
    private static function pathSegments(string $target): array
    {
        $path = strtolower(rawurldecode(explode('?', $target, 2)[0]));
        $path = str_replace('\\', '/', $path);
        $out = [];
        foreach (explode('/', $path) as $segment) {
            $segment = rtrim($segment, ". \t"); // Windows: a záró pont/szóköz nem része a fájlnévnek
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }
        return $out;
    }

    public static function isWriterRequest(string $method, string $class): bool
    {
        return $class === self::CLASS_BACKGROUND || !in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * A kérés fejrészének átírása a háttérfolyamat felé: `Connection: close`
     * (a `php -S` úgyis kérésenként zár), és a továbbítási fejlécek kezelése
     * (lásd az osztály docblokkját).
     *
     * @return array{head: string, method: string, target: string, headers: array<string, string>}|null null = érvénytelen kérés
     */
    public static function rewriteHead(string $head, string $peerIp): ?array
    {
        $lines = explode("\r\n", rtrim($head, "\r\n"));
        $requestLine = array_shift($lines);
        if (!preg_match('/^([A-Z]+) (\S+) (HTTP\/1\.[01])$/', (string) $requestLine, $m)) {
            return null;
        }
        $trustForwarded = in_array($peerIp, ['127.0.0.1', '::1'], true);
        $kept = [];
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0 || $line[0] === ' ' || $line[0] === "\t") {
                return null; // érvénytelen / folytatósoros fejléc — nem továbbítjuk találgatva
            }
            $name = substr($line, 0, $colon);
            $normalized = strtolower(str_replace('_', '-', trim($name)));
            $value = trim(substr($line, $colon + 1));
            if (in_array($normalized, ['connection', 'keep-alive', 'proxy-connection'], true)) {
                continue;
            }
            if (!$trustForwarded && in_array($normalized, ['x-forwarded-for', 'x-real-ip', 'x-forwarded-proto', 'x-forwarded-host', 'x-forwarded-port', 'forwarded'], true)) {
                continue;
            }
            $headers[$normalized] = $value;
            $kept[] = $line;
        }
        if (!$trustForwarded) {
            $kept[] = 'X-Forwarded-For: ' . $peerIp;
        }
        $kept[] = 'Connection: close';
        return [
            'head' => $requestLine . "\r\n" . implode("\r\n", $kept) . "\r\n\r\n",
            'method' => $m[1],
            'target' => $m[2],
            'headers' => $headers,
        ];
    }

    public function run(): void
    {
        while ($this->running) {
            $this->tick(0.25);
        }
    }

    /** Egy eseményciklus-lépés (tesztelhetőség miatt külön). */
    public function tick(float $timeoutSeconds): void
    {
        $read = [$this->listener];
        $write = [];
        $map = [];
        foreach ($this->conns as $id => $c) {
            if ($c['phase'] === 'head') {
                $read[] = $c['client'];
                $map[(int) $c['client']] = [$id, 'client'];
            } elseif ($c['phase'] === 'pipe') {
                if (!$c['clientEof'] && strlen($c['toBackend']) < self::BUFFER_LIMIT) {
                    $read[] = $c['client'];
                }
                if (!$c['backendEof'] && strlen($c['toClient']) < self::BUFFER_LIMIT) {
                    $read[] = $c['backend'];
                }
                if ($c['toBackend'] !== '' && !$c['backendWriteClosed']) {
                    $write[] = $c['backend'];
                }
                if ($c['toClient'] !== '') {
                    $write[] = $c['client'];
                }
                $map[(int) $c['client']] = [$id, 'client'];
                $map[(int) $c['backend']] = [$id, 'backend'];
            } elseif ($c['phase'] === 'connecting') {
                $write[] = $c['backend']; // a nem-blokkoló csatlakozás befejeződése
                $map[(int) $c['backend']] = [$id, 'connect'];
            }
        }
        $except = null;
        $sec = (int) $timeoutSeconds;
        $usec = (int) (($timeoutSeconds - $sec) * 1e6);
        $ready = @stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false) {
            usleep(10000);
            $read = [];
            $write = [];
        }

        foreach ($read as $stream) {
            if ($stream === $this->listener) {
                $this->acceptAll();
                continue;
            }
            [$id, $side] = $map[(int) $stream] ?? [null, null];
            if ($id === null || !isset($this->conns[$id])) {
                continue;
            }
            $side === 'client' ? $this->onClientReadable($id) : $this->onBackendReadable($id);
        }
        foreach ($write as $stream) {
            [$id, $side] = $map[(int) $stream] ?? [null, null];
            if ($id === null || !isset($this->conns[$id])) {
                continue;
            }
            if ($side === 'connect') {
                $this->conns[$id]['phase'] = 'pipe';
                continue;
            }
            $this->flush($id, $side);
        }

        $now = microtime(true);
        foreach ($this->conns as $id => $c) {
            if ($c['phase'] === 'head' && $now > $c['deadline']) {
                if ($c['buf'] === '') {
                    $this->close($id); // pl. böngésző által előre nyitott, sosem használt kapcsolat
                } else {
                    $this->respondAndClose($id, 408, 'A kérés fejléce nem érkezett meg időben.');
                }
            } elseif ($c['phase'] === 'connecting' && $now > $c['deadline']) {
                $this->abandonBackend($id);
            }
        }
        if ($now >= $this->nextProbeAt) {
            $this->probeDownBackends();
            $this->nextProbeAt = $now + 5.0;
        }
        $this->dispatchQueue();
    }

    private function acceptAll(): void
    {
        while (($client = @stream_socket_accept($this->listener, 0, $peer)) !== false) {
            stream_set_blocking($client, false);
            $id = $this->nextId++;
            $this->conns[$id] = [
                'client' => $client,
                'peer' => self::peerIp((string) $peer),
                'phase' => 'head',
                'buf' => '',
                'deadline' => microtime(true) + self::HEAD_TIMEOUT_SECONDS,
                'backend' => null,
                'backendIndex' => null,
                'toBackend' => '',
                'toClient' => '',
                'clientEof' => false,
                'backendEof' => false,
                'backendWriteClosed' => false,
                'class' => self::CLASS_SHORT,
                'writer' => false,
            ];
        }
    }

    private static function peerIp(string $peer): string
    {
        if (str_starts_with($peer, '[')) {
            return substr($peer, 1, (int) strpos($peer, ']') - 1);
        }
        $colon = strrpos($peer, ':');
        return $colon === false ? $peer : substr($peer, 0, $colon);
    }

    private function onClientReadable(int $id): void
    {
        $c = &$this->conns[$id];
        $data = @fread($c['client'], self::READ_CHUNK);
        if ($data === false || ($data === '' && feof($c['client']))) {
            if ($c['phase'] === 'head') {
                $this->close($id);
                return;
            }
            $c['clientEof'] = true;
            if ($c['toBackend'] === '' && !$c['backendWriteClosed']) {
                @stream_socket_shutdown($c['backend'], STREAM_SHUT_WR);
                $c['backendWriteClosed'] = true;
            }
            $this->maybeFinish($id);
            return;
        }
        if ($c['phase'] === 'head') {
            $c['buf'] .= $data;
            $end = strpos($c['buf'], "\r\n\r\n");
            if ($end === false) {
                if (strlen($c['buf']) > self::MAX_HEAD_BYTES) {
                    $this->respondAndClose($id, 431, 'A kérés fejléce túl nagy.');
                }
                return;
            }
            $rewritten = self::rewriteHead(substr($c['buf'], 0, $end + 4), $c['peer']);
            if ($rewritten === null) {
                $this->respondAndClose($id, 400, 'Érvénytelen kérés.');
                return;
            }
            $c['class'] = self::classify($rewritten['method'], $rewritten['target'], $rewritten['headers']);
            $c['writer'] = self::isWriterRequest($rewritten['method'], $c['class']);
            $c['toBackend'] = $rewritten['head'] . substr($c['buf'], $end + 4);
            $c['buf'] = '';
            $c['phase'] = 'queued';
            $this->queue[] = $id;
            return;
        }
        $c['toBackend'] .= $data;
    }

    private function onBackendReadable(int $id): void
    {
        $c = &$this->conns[$id];
        $data = @fread($c['backend'], self::READ_CHUNK);
        if ($data === false || ($data === '' && feof($c['backend']))) {
            $c['backendEof'] = true;
            $this->maybeFinish($id);
            return;
        }
        $c['toClient'] .= $data;
    }

    private function flush(int $id, string $side): void
    {
        $c = &$this->conns[$id];
        $stream = $side === 'client' ? $c['client'] : $c['backend'];
        $key = $side === 'client' ? 'toClient' : 'toBackend';
        if ($c[$key] === '') {
            return;
        }
        $n = @fwrite($stream, $c[$key]);
        if ($n === false) {
            // A kliens (vagy a háttérfolyamat) bontotta a kapcsolatot — a
            // másik oldalt is zárjuk (a php -S így érzékeli a megszakítást).
            $this->close($id);
            return;
        }
        $c[$key] = (string) substr($c[$key], $n);
        if ($side === 'backend' && $c[$key] === '' && $c['clientEof'] && !$c['backendWriteClosed']) {
            @stream_socket_shutdown($c['backend'], STREAM_SHUT_WR);
            $c['backendWriteClosed'] = true;
        }
        $this->maybeFinish($id);
    }

    private function maybeFinish(int $id): void
    {
        $c = $this->conns[$id] ?? null;
        if ($c === null) {
            return;
        }
        if ($c['backendEof'] && $c['toClient'] === '') {
            $this->close($id);
        }
    }

    private function dispatchQueue(): void
    {
        if (!$this->queue) {
            return;
        }
        $remaining = [];
        $barrier = false;
        foreach ($this->queue as $id) {
            if (!isset($this->conns[$id])) {
                continue;
            }
            if ($barrier) {
                $remaining[] = $id;
                continue;
            }
            $c = $this->conns[$id];
            $index = $this->pickBackend($c['class'], $c['writer']);
            if ($index === null) {
                $remaining[] = $id;
                if ($c['class'] === self::CLASS_EXCLUSIVE) {
                    $barrier = true; // amíg a kizárólagos kérés vár, utána senki nem indul
                }
                continue;
            }
            if (!$this->connectBackend($id, $index)) {
                $remaining[] = $id;
            }
        }
        $this->queue = $remaining;
        if (!array_filter($this->backends, static fn ($b) => !$b['down'])) {
            foreach ($this->queue as $id) {
                $this->respondAndClose($id, 503, 'A szerver átmenetileg nem érhető el.');
            }
            $this->queue = [];
        }
    }

    private function activeClasses(): array
    {
        $classes = [];
        foreach ($this->backends as $b) {
            if ($b['conn'] !== null && isset($this->conns[$b['conn']])) {
                $classes[] = [$this->conns[$b['conn']]['class'], $this->conns[$b['conn']]['writer']];
            }
        }
        return $classes;
    }

    private function pickBackend(string $class, bool $writer): ?int
    {
        $active = $this->activeClasses();
        foreach ($active as [$activeClass]) {
            if ($activeClass === self::CLASS_EXCLUSIVE) {
                return null;
            }
        }
        if ($class === self::CLASS_EXCLUSIVE) {
            if ($active) {
                return null; // meg kell várni minden folyamatban lévő kérést
            }
            return $this->idleBackend('interactive') ?? $this->idleBackend('background');
        }
        $writerExclusiveActive = false;
        $longActive = 0;
        foreach ($active as [$activeClass]) {
            $writerExclusiveActive = $writerExclusiveActive || $activeClass === self::CLASS_WRITER_EXCLUSIVE;
            if ($activeClass === self::CLASS_LONG || $activeClass === self::CLASS_WRITER_EXCLUSIVE) {
                $longActive++;
            }
        }
        if ($writerExclusiveActive && $writer) {
            return null;
        }
        if ($class === self::CLASS_BACKGROUND) {
            return $this->idleBackend('background') ?? ($this->hasPool('background') ? null : $this->idleBackend('interactive'));
        }
        if ($class === self::CLASS_LONG || $class === self::CLASS_WRITER_EXCLUSIVE) {
            $interactive = count(array_filter($this->backends, static fn ($b) => $b['pool'] === 'interactive' && !$b['down']));
            if ($longActive >= max(1, $interactive - 1)) {
                return null; // egy pénztári folyamat mindig marad a rövid kéréseknek
            }
        }
        return $this->idleBackend('interactive');
    }

    /** Egy korábban elérhetetlen háttérfolyamat újra kiosztható, ha a portja ismét fogad kapcsolatot. */
    private function probeDownBackends(): void
    {
        foreach ($this->backends as $i => $b) {
            if (!$b['down']) {
                continue;
            }
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $b['port'], $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                $this->backends[$i]['down'] = false;
                ($this->log)('A(z) ' . $b['port'] . ' háttérfolyamat ismét elérhető.');
            }
        }
    }

    private function hasPool(string $pool): bool
    {
        foreach ($this->backends as $b) {
            if ($b['pool'] === $pool && !$b['down']) {
                return true;
            }
        }
        return false;
    }

    private function idleBackend(string $pool): ?int
    {
        foreach ($this->backends as $i => $b) {
            if ($b['pool'] === $pool && !$b['down'] && $b['conn'] === null) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Nem-blokkoló csatlakozás a háttérfolyamathoz: a befejeződést az
     * eseményciklus (írhatóság) jelzi. Egy időkorlátos, blokkoló
     * stream_socket_client() Windows-on kérésenként ~14 ms-ot (egy időzítő-
     * ütemet) várt volna még egy azonnal fogadó loopback porton is (mérve).
     */
    private function connectBackend(int $id, int $index): bool
    {
        $port = $this->backends[$index]['port'];
        $socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($socket === false) {
            $this->backends[$index]['down'] = true;
            ($this->log)("A(z) $port háttérfolyamat nem érhető el ($errstr) — kivéve a kiosztásból.");
            return false;
        }
        stream_set_blocking($socket, false);
        $this->backends[$index]['conn'] = $id;
        $this->conns[$id]['backend'] = $socket;
        $this->conns[$id]['backendIndex'] = $index;
        $this->conns[$id]['phase'] = 'connecting';
        $this->conns[$id]['deadline'] = microtime(true) + self::CONNECT_TIMEOUT_SECONDS;
        return true;
    }

    /** A csatlakozás nem jött létre időben: a háttérfolyamat kiesik, a kérés visszakerül a sor elejére. */
    private function abandonBackend(int $id): void
    {
        $c = $this->conns[$id];
        $index = $c['backendIndex'];
        @fclose($c['backend']);
        $this->backends[$index]['down'] = true;
        $this->backends[$index]['conn'] = null;
        ($this->log)('A(z) ' . $this->backends[$index]['port'] . ' háttérfolyamat nem fogadta a kapcsolatot — kivéve a kiosztásból.');
        $this->conns[$id]['backend'] = null;
        $this->conns[$id]['backendIndex'] = null;
        $this->conns[$id]['phase'] = 'queued';
        array_unshift($this->queue, $id);
    }

    private function respondAndClose(int $id, int $status, string $message): void
    {
        $c = $this->conns[$id] ?? null;
        if ($c === null) {
            return;
        }
        $reasons = [400 => 'Bad Request', 408 => 'Request Timeout', 431 => 'Request Header Fields Too Large', 503 => 'Service Unavailable'];
        $body = json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        $response = "HTTP/1.1 $status " . ($reasons[$status] ?? 'Error') . "\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . strlen((string) $body) . "\r\nConnection: close\r\n\r\n" . $body;
        stream_set_blocking($c['client'], true);
        stream_set_timeout($c['client'], 2);
        @fwrite($c['client'], $response);
        $this->close($id);
    }

    private function close(int $id): void
    {
        $c = $this->conns[$id] ?? null;
        if ($c === null) {
            return;
        }
        if (is_resource($c['client'])) {
            @fclose($c['client']);
        }
        if ($c['backend'] !== null && is_resource($c['backend'])) {
            @fclose($c['backend']);
        }
        if ($c['backendIndex'] !== null && $this->backends[$c['backendIndex']]['conn'] === $id) {
            $this->backends[$c['backendIndex']]['conn'] = null;
        }
        unset($this->conns[$id]);
    }

    /** @return array{active: int, queued: int, backends_down: int} diagnosztika (tesztek) */
    public function stats(): array
    {
        return [
            'active' => count(array_filter($this->backends, static fn ($b) => $b['conn'] !== null)),
            'queued' => count($this->queue),
            'backends_down' => count(array_filter($this->backends, static fn ($b) => $b['down'])),
        ];
    }
}
