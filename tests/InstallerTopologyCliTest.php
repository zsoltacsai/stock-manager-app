<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Windows telepítő (install-windows.ps1, Fázis 2 Checkpoint 3) által
 * meghívott tools/installer-set-topology.php CLI-eszköz — VALÓDI, önálló
 * PHP CLI-alfolyamatként futtatva (nem require-elve/osztályként), mert ez
 * maga is egy önálló, argv-alapú belépési pont, pontosan úgy, ahogy
 * PowerShell hívná. Egy izolált ideiglenes könyvtárban fut, SOSE nyúl az
 * éles config/-hoz.
 */
final class InstallerTopologyCliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sm_topology_cli_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0775, true);
        mkdir($this->root . '/src', 0775, true);
        mkdir($this->root . '/tools', 0775, true);
        copy(dirname(__DIR__) . '/src/UrlSafety.php', $this->root . '/src/UrlSafety.php');
        copy(dirname(__DIR__) . '/tools/installer-set-topology.php', $this->root . '/tools/installer-set-topology.php');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return array{ok:bool, exitCode:int, json:array|null, raw:string} */
    private function runTool(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/tools/installer-set-topology.php');
        foreach ($args as $k => $v) {
            $cmd .= ' ' . escapeshellarg("--$k=$v");
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->root);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        $json = json_decode(trim($stdout), true);
        return ['exitCode' => $exitCode, 'json' => is_array($json) ? $json : null, 'raw' => $stdout];
    }

    public function testGetOnEmptyInstallReturnsStandaloneDefaults(): void
    {
        $res = $this->runTool(['action' => 'get']);
        $this->assertSame(0, $res['exitCode']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('standalone', $res['json']['node_role']);
        $this->assertSame(['server_url' => '', 'client_id' => '', 'client_secret' => ''], $res['json']['client']);
    }

    public function testSetServerRoleSucceeds(): void
    {
        $res = $this->runTool(['action' => 'set', 'node-role' => 'server']);
        $this->assertSame(0, $res['exitCode']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('server', $res['json']['node_role']);
        $this->assertSame('standalone', $res['json']['previous_node_role']);
        $this->assertTrue($res['json']['changed']);

        $get = $this->runTool(['action' => 'get']);
        $this->assertSame('server', $get['json']['node_role']);
    }

    public function testSetClientRoleWithoutRequiredFieldsFails(): void
    {
        $res = $this->runTool(['action' => 'set', 'node-role' => 'client']);
        $this->assertSame(1, $res['exitCode']);
        $this->assertFalse($res['json']['ok']);
        $this->assertStringContainsString('kötelező', $res['json']['error']);
    }

    public function testSetClientRoleWithInvalidUrlFails(): void
    {
        $res = $this->runTool([
            'action' => 'set', 'node-role' => 'client',
            'server-url' => 'not-a-url', 'client-id' => 'cl_x', 'client-secret' => 'sekret',
        ]);
        $this->assertSame(1, $res['exitCode']);
        $this->assertFalse($res['json']['ok']);
        $this->assertStringContainsString('szerver-cím érvénytelen', $res['json']['error']);
    }

    public function testSetClientRoleWithPrivateLanUrlSucceeds(): void
    {
        // Ez a lényegi UrlSafety::checkServerUrl() integráció bizonyítéka —
        // egy privát LAN-cím itt SZÁNDÉKOSAN engedélyezett (ellentétben az
        // általános UrlSafety::check()-kel), mert ez egy valódi FountainTrade
        // Szerver LAN-címe.
        $res = $this->runTool([
            'action' => 'set', 'node-role' => 'client',
            'server-url' => 'http://192.168.1.10:8000', 'client-id' => 'cl_abc123', 'client-secret' => 'raw-secret-value',
        ]);
        $this->assertSame(0, $res['exitCode']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('client', $res['json']['node_role']);

        $get = $this->runTool(['action' => 'get']);
        $this->assertSame('client', $get['json']['node_role']);
        $this->assertSame('http://192.168.1.10:8000', $get['json']['client']['server_url']);
        $this->assertSame('cl_abc123', $get['json']['client']['client_id']);
        $this->assertSame('raw-secret-value', $get['json']['client']['client_secret']);
    }

    public function testSwitchingAwayFromClientClearsStaleCredentials(): void
    {
        $this->runTool([
            'action' => 'set', 'node-role' => 'client',
            'server-url' => 'http://192.168.1.10:8000', 'client-id' => 'cl_abc123', 'client-secret' => 'raw-secret-value',
        ]);

        $res = $this->runTool(['action' => 'set', 'node-role' => 'standalone']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('client', $res['json']['previous_node_role']);

        $get = $this->runTool(['action' => 'get']);
        $this->assertSame('standalone', $get['json']['node_role']);
        $this->assertSame(['server_url' => '', 'client_id' => '', 'client_secret' => ''], $get['json']['client'], 'Egy korábbi Kliens-titok NEM maradhat egy Önálló/Szerver node konfigurációjában.');
    }

    public function testPreservesShopAndDbFieldsWrittenByInstallPhp(): void
    {
        $existing = "<?php\nreturn " . var_export([
            'shop' => ['name' => 'Teszt Bolt', 'address' => 'Szeged'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => '/x/data/stock.sqlite'], 'mysql' => []],
        ], true) . ";\n";
        file_put_contents($this->root . '/config/installer-generated.php', $existing);

        $res = $this->runTool(['action' => 'set', 'node-role' => 'server']);
        $this->assertTrue($res['json']['ok']);

        $written = require $this->root . '/config/installer-generated.php';
        $this->assertSame('Teszt Bolt', $written['shop']['name']);
        $this->assertSame('sqlite', $written['db']['driver']);
        $this->assertSame('server', $written['node_role']);
    }

    public function testRerunningWithSameRoleIsIdempotentAndKeepsCredentials(): void
    {
        $args = [
            'action' => 'set', 'node-role' => 'client',
            'server-url' => 'http://192.168.1.10:8000', 'client-id' => 'cl_idem', 'client-secret' => 'idem-secret',
        ];
        $run1 = $this->runTool($args);
        $run2 = $this->runTool($args);
        $run3 = $this->runTool($args);

        $this->assertTrue($run1['json']['ok']);
        $this->assertTrue($run2['json']['ok']);
        $this->assertTrue($run3['json']['ok']);
        $this->assertFalse($run2['json']['changed']);
        $this->assertFalse($run3['json']['changed']);

        $get = $this->runTool(['action' => 'get']);
        $this->assertSame('cl_idem', $get['json']['client']['client_id']);
        $this->assertSame('idem-secret', $get['json']['client']['client_secret']);
    }

    public function testInvalidNodeRoleIsRejected(): void
    {
        $res = $this->runTool(['action' => 'set', 'node-role' => 'megagép']);
        $this->assertSame(1, $res['exitCode']);
        $this->assertFalse($res['json']['ok']);
    }

    public function testUnknownActionIsRejected(): void
    {
        $res = $this->runTool(['action' => 'delete-everything']);
        $this->assertSame(1, $res['exitCode']);
        $this->assertFalse($res['json']['ok']);
    }
}
