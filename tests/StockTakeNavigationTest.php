<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UX-03 (Phase 7 UX audit) — a Leltározás (`leltar.php`) egy teljesen kész,
 * működő funkció, de a Phase 7 audit szerint SEHONNAN nem volt elérve a
 * felületről (`grep -rn "leltar" webroot/*.php webroot/*.js` — a saját
 * fájlját kivéve — nulla találatot adott). Ez a teszt a pontosan FORDÍTOTT
 * ellenőrzést végzi el: a bal oldali navigációs sáv (minden oldalon
 * megjelenő `sidebarmenu.php`) most már tartalmaz egy `href="leltar.php"`
 * hivatkozást, és a link ténylegesen egy működő, bejelentkezett
 * felhasználó számára elérhető oldalra mutat — a meglévő jogosultság-
 * kezelés (a korrekció alkalmazása továbbra is csak admin — lásd
 * tests/StockTakeCountBaselineTest.php és mások) változatlan marad, ezt
 * ez a teszt nem duplikálja.
 */
final class StockTakeNavigationTest extends TestCase
{
    /** Statikus bizonyíték — pontosan az audit reprodukciós módszerének (grep) fordítottja. */
    public function testSidebarNavigationContainsALinkToStockTake(): void
    {
        $sidebar = (string) file_get_contents(dirname(__DIR__) . '/webroot/sidebarmenu.php');
        $this->assertMatchesRegularExpression(
            "/'href'\\s*=>\\s*'leltar\\.php'/",
            $sidebar,
            'A Leltározás oldalnak legyen egy bejegyzése a bal oldali navigációban (UX-03).'
        );
        // A bejegyzésnek legyen szöveges title-je is (nem csak egy csupasz href) —
        // ugyanaz a minta, mint a sidebar többi eleménél, hogy a link a natív
        // tooltipen keresztül azonosítható legyen.
        $this->assertMatchesRegularExpression(
            "/'href'\\s*=>\\s*'leltar\\.php'[^\\]]*'title'\\s*=>\\s*'[^']+'/s",
            $sidebar
        );
    }

    /** Minden más oldal (sidebarmenu.php-t includeoló .php) automatikusan örökli a linket — mintavételes ellenőrzés. */
    public function testLinkIsPresentFromAnotherPageToo(): void
    {
        $webroot = dirname(__DIR__) . '/webroot';
        foreach (['termekek.php', 'dashboard.php', 'index.php'] as $page) {
            $html = $this->renderSidebarOnly($webroot, $page);
            $this->assertStringContainsString('leltar.php', $html, "A(z) $page oldal navigációjából is elérhetőnek kell lennie a Leltározásnak.");
        }
    }

    private function renderSidebarOnly(string $webroot, string $currentScript): string
    {
        // Csak a sidebarmenu.php-t rendereljük ki, a $_SERVER['SCRIPT_NAME']
        // beállításával, hogy az "active" osztály logikája is helyesen fusson
        // — nem kell hozzá teljes oldalbetöltés/bejelentkezés.
        $_SERVER['SCRIPT_NAME'] = '/' . $currentScript;
        ob_start();
        include $webroot . '/sidebarmenu.php';
        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Élő HTTP — a link ténylegesen egy működő oldalra mutat, bejelentkezve.
    // ------------------------------------------------------------------

    private static string $root;
    private static string $baseUrl;
    /** @var resource|null */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_ux03_http_' . bin2hex(random_bytes(6));
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

    private static function get(string $path, ?string $jar = null): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15];
        if ($jar !== null) {
            $opts[CURLOPT_COOKIEJAR] = $jar;
            $opts[CURLOPT_COOKIEFILE] = $jar;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }

    public function testStockTakePageLoadsForALoggedInUserAndCanNavigateBackToDashboard(): void
    {
        $jar = self::$root . '/cookies.txt';
        // Bejelentkezés nélküli app (app_password_enabled alapból kikapcsolva)
        // — Auth::isLoggedIn() ilyenkor is igaz, mint minden más oldalnál;
        // ez a link-elérhetőséget teszteli, nem az app-jelszavas bejelentkezést.
        self::get('/api/auth-status.php', $jar);

        $res = self::get('/leltar.php', $jar);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('Leltározás', $res['body']);
        $this->assertStringContainsString('sidebar-link', $res['body'], 'A leltar.php-nak is meg kell jelenítenie a normál navigációt (vissza tud lépni bárhova).');
        $this->assertStringContainsString('href="dashboard.php"', $res['body'], 'A navigációból vissza lehessen térni a Dashboardra.');

        // A link maga (nem csak közvetlen URL-lel) is működjön — a Dashboard
        // HTML-je ténylegesen tartalmazza a rákattintható hivatkozást.
        $dashboard = self::get('/dashboard.php', $jar);
        $this->assertStringContainsString('href="leltar.php"', $dashboard['body']);
    }
}
