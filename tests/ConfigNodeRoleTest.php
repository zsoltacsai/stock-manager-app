<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * config/config.php node_role/client merge-viselkedése (Fázis 2). Nem a
 * valódi config/config.php-t módosítja/olvassa élesben — egy ideiglenes
 * másolatban, saját, teszt-vezérelt installer-generated.php mellett fut,
 * hogy a tényleges fejlesztői/éles installer-generated.php-t ne kelljen
 * (és sose kelljen) érinteni.
 */
final class ConfigNodeRoleTest extends TestCase
{
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/sm_config_noderole_' . bin2hex(random_bytes(6));
        mkdir(self::$tmpDir . '/config', 0775, true);
        copy(dirname(__DIR__) . '/config/config.php', self::$tmpDir . '/config/config.php');
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$tmpDir . '/config/config.php');
        @unlink(self::$tmpDir . '/config/installer-generated.php');
        @rmdir(self::$tmpDir . '/config');
        @rmdir(self::$tmpDir);
    }

    private function writeInstallerGenerated(array $content): void
    {
        file_put_contents(self::$tmpDir . '/config/installer-generated.php', '<?php return ' . var_export($content, true) . ';');
    }

    public function testMissingNodeRoleDefaultsToStandalone(): void
    {
        // Egy Fázis 2 ELŐTTI installer-generated.php pontos alakja — csak
        // 'shop'/'db', 'node_role' kulcs nélkül. Ez a valódi, mai állapota
        // minden meglévő 1.4.x telepítésnek.
        $this->writeInstallerGenerated([
            'shop' => ['name' => 'Teszt Bolt', 'address' => 'Teszt cím'],
            'db'   => ['driver' => 'sqlite', 'sqlite' => ['path' => 'x'], 'mysql' => []],
        ]);

        $config = require self::$tmpDir . '/config/config.php';

        $this->assertSame('standalone', $config['node_role'], 'Egy meglévő, node_role nélküli installer-generated.php-nál automatikusan standalone-ra kell esnie.');
        $this->assertSame(['server_url' => '', 'client_id' => '', 'client_secret' => ''], $config['client']);
        $this->assertSame('Teszt Bolt', $config['shop']['name'], 'A meglévő shop/db összeolvasztás viselkedése nem változhatott.');
    }

    public function testExplicitClientRoleIsMergedThrough(): void
    {
        $this->writeInstallerGenerated([
            'shop' => ['name' => 'Kliens Bolt', 'address' => 'X'],
            'db'   => ['driver' => 'sqlite', 'sqlite' => ['path' => 'x'], 'mysql' => []],
            'node_role' => 'client',
            'client' => [
                'server_url'    => 'http://192.168.1.10:8000',
                'client_id'     => 'cl_test123',
                'client_secret' => 'secret-value',
            ],
        ]);

        $config = require self::$tmpDir . '/config/config.php';

        $this->assertSame('client', $config['node_role']);
        $this->assertSame('http://192.168.1.10:8000', $config['client']['server_url']);
        $this->assertSame('cl_test123', $config['client']['client_id']);
        $this->assertSame('secret-value', $config['client']['client_secret']);
    }

    public function testMissingInstallerGeneratedFileDefaultsToStandalone(): void
    {
        @unlink(self::$tmpDir . '/config/installer-generated.php');
        $config = require self::$tmpDir . '/config/config.php';
        $this->assertSame('standalone', $config['node_role']);
    }
}
