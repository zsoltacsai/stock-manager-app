<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Release preparation (v1.6.0) — a Windows telepítőnél korábban külön
 * folyamatként le nem írt "dispatcher-aktiválás frissítéskor" rés
 * javítása. A Feladatütemező Action-je (`wscript.exe ... run-server-
 * hidden.vbs`) byte-azonos a dispatcher bevezetése előtt/után (lásd az
 * osztály docblokkja) — a tényleges `php -S` vs. `php tools/http-
 * dispatcher.php` különbség KIZÁRÓLAG e launcher-fájl TARTALMÁBAN él.
 * Ez a teszt a tartalom felismerését/regenerálását ellenőrzi, valódi
 * Feladatütemező/Windows-szolgáltatás nélkül (ez a gép maga is Windows,
 * így a PHP_OS_FAMILY-ág ténylegesen lefut, nem csak statikusan
 * olvasva van).
 */
final class WindowsDispatcherActivatorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/sm_wda_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot . '/tools', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
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

    private function writeLegacyVbs(string $exePath, string $hostPort, string $webroot): void
    {
        $arguments = "-S $hostPort -t \"$webroot\"";
        $exeLit = '"' . str_replace('"', '""', $exePath) . '"';
        $argsLit = '"' . str_replace('"', '""', $arguments) . '"';
        $lines = [
            "' FountainTrade - automatikusan generalt, rejtett ablakos inditowrapper.",
            "' NE szerkeszd kezzel - minden install-windows.ps1 futtataskor ujragheneralodik.",
            'Set objShell = CreateObject("WScript.Shell")',
            'cmdLine = ' . $exeLit . ' & " " & ' . $argsLit,
            'exitCode = objShell.Run(cmdLine, 0, True)',
            'WScript.Quit(exitCode)',
        ];
        $text = implode("\r\n", $lines) . "\r\n";
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        file_put_contents($this->tmpRoot . '/tools/run-server-hidden.vbs', "\xFF\xFE" . $utf16);
    }

    private function readVbsText(): string
    {
        $raw = file_get_contents($this->tmpRoot . '/tools/run-server-hidden.vbs');
        return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    }

    public function testLegacyLauncherIsRewrittenToDispatcherInvocationPreservingHostPortAndWebroot(): void
    {
        file_put_contents($this->tmpRoot . '/tools/http-dispatcher.php', '<?php // stub');
        $this->writeLegacyVbs('C:\\php\\php.exe', '127.0.0.1:8000', 'C:\\FountainTrade\\webroot');

        $result = WindowsDispatcherActivator::activateIfNeeded($this->tmpRoot);
        $this->assertTrue($result);

        $after = $this->readVbsText();
        $this->assertStringContainsString('http-dispatcher.php', $after);
        $this->assertStringContainsString('--listen=127.0.0.1:8000', $after);
        $this->assertStringContainsString('--webroot=', $after);
        $this->assertStringContainsString('C:\\FountainTrade\\webroot', $after);
        $this->assertStringContainsString('--workers=3', $after);
        $this->assertStringContainsString('--background-workers=1', $after);
        $this->assertStringContainsString('C:\\php\\php.exe', $after, 'A php.exe útvonalnak változatlannak kell maradnia.');
    }

    public function testAlreadyDispatcherBasedLauncherIsLeftUnchanged(): void
    {
        file_put_contents($this->tmpRoot . '/tools/http-dispatcher.php', '<?php // stub');
        $dispatcherScript = $this->tmpRoot . '/tools/http-dispatcher.php';
        $arguments = '"' . $dispatcherScript . '" --listen=127.0.0.1:8000 --webroot="C:\\FountainTrade\\webroot" --workers=3 --background-workers=1';
        $exeLit = '"C:\\php\\php.exe"';
        $argsLit = '"' . str_replace('"', '""', $arguments) . '"';
        $text = 'Set objShell = CreateObject("WScript.Shell")' . "\r\n"
            . 'cmdLine = ' . $exeLit . ' & " " & ' . $argsLit . "\r\n"
            . 'exitCode = objShell.Run(cmdLine, 0, True)' . "\r\n"
            . 'WScript.Quit(exitCode)' . "\r\n";
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        file_put_contents($this->tmpRoot . '/tools/run-server-hidden.vbs', "\xFF\xFE" . $utf16);

        $before = file_get_contents($this->tmpRoot . '/tools/run-server-hidden.vbs');
        $result = WindowsDispatcherActivator::activateIfNeeded($this->tmpRoot);
        $after = file_get_contents($this->tmpRoot . '/tools/run-server-hidden.vbs');

        $this->assertFalse($result, 'Már aktív dispatcher-launcher esetén nem szabad módosítani (idempotens no-op).');
        $this->assertSame($before, $after);
    }

    public function testNoOpWhenDispatcherScriptNotYetDeployed(): void
    {
        // tools/http-dispatcher.php szándékosan hiányzik — régebbi kiadás.
        $this->writeLegacyVbs('C:\\php\\php.exe', '127.0.0.1:8000', 'C:\\FountainTrade\\webroot');

        $result = WindowsDispatcherActivator::activateIfNeeded($this->tmpRoot);
        $this->assertFalse($result);
    }

    public function testNoOpWhenLauncherFileIsMissing(): void
    {
        file_put_contents($this->tmpRoot . '/tools/http-dispatcher.php', '<?php // stub');
        // run-server-hidden.vbs szándékosan hiányzik (pl. Kliens szerepkör).

        $result = WindowsDispatcherActivator::activateIfNeeded($this->tmpRoot);
        $this->assertFalse($result);
    }

    public function testNoOpOnUnrecognizedLauncherContent(): void
    {
        file_put_contents($this->tmpRoot . '/tools/http-dispatcher.php', '<?php // stub');
        $text = "' valami egeszen mas tartalom, nem a vart minta\r\n";
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        file_put_contents($this->tmpRoot . '/tools/run-server-hidden.vbs', "\xFF\xFE" . $utf16);

        $before = file_get_contents($this->tmpRoot . '/tools/run-server-hidden.vbs');
        $result = WindowsDispatcherActivator::activateIfNeeded($this->tmpRoot);
        $after = file_get_contents($this->tmpRoot . '/tools/run-server-hidden.vbs');

        $this->assertFalse($result, 'Ismeretlen formátumhoz biztonságosabb hozzá sem nyúlni.');
        $this->assertSame($before, $after);
    }

    public function testIsPendingRestartTrueWhenCurrentRequestPortMatchesLegacyLauncherPort(): void
    {
        file_put_contents($this->tmpRoot . '/tools/http-dispatcher.php', '<?php // stub');
        $this->writeLegacyVbs('C:\\php\\php.exe', '127.0.0.1:8000', 'C:\\FountainTrade\\webroot');

        $this->assertTrue(WindowsDispatcherActivator::isPendingRestart($this->tmpRoot, '8000'));
        $this->assertFalse(WindowsDispatcherActivator::isPendingRestart($this->tmpRoot, '8001'));
    }

    public function testIsPendingRestartTrueWhenDispatcherLauncherPortStillServesDirectly(): void
    {
        file_put_contents($this->tmpRoot . '/tools/http-dispatcher.php', '<?php // stub');
        $dispatcherScript = $this->tmpRoot . '/tools/http-dispatcher.php';
        $arguments = '"' . $dispatcherScript . '" --listen=127.0.0.1:8000 --webroot="C:\\FountainTrade\\webroot" --workers=3 --background-workers=1';
        $exeLit = '"C:\\php\\php.exe"';
        $argsLit = '"' . str_replace('"', '""', $arguments) . '"';
        $text = 'Set objShell = CreateObject("WScript.Shell")' . "\r\n"
            . 'cmdLine = ' . $exeLit . ' & " " & ' . $argsLit . "\r\n"
            . 'exitCode = objShell.Run(cmdLine, 0, True)' . "\r\n"
            . 'WScript.Quit(exitCode)' . "\r\n";
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        file_put_contents($this->tmpRoot . '/tools/run-server-hidden.vbs', "\xFF\xFE" . $utf16);

        $this->assertTrue(
            WindowsDispatcherActivator::isPendingRestart($this->tmpRoot, '8000'),
            'A fő porton kiszolgált kérés azt jelzi, hogy a diszpécser még nem fut — újraindítás szükséges az aktiváláshoz.'
        );
        $this->assertFalse(
            WindowsDispatcherActivator::isPendingRestart($this->tmpRoot, '8001'),
            'Egy háttér-worker portján kiszolgált kérés azt jelzi, hogy a diszpécser már aktívan fut.'
        );
    }

    public function testIsPendingRestartFalseWhenDispatcherScriptNotYetDeployed(): void
    {
        $this->writeLegacyVbs('C:\\php\\php.exe', '127.0.0.1:8000', 'C:\\FountainTrade\\webroot');

        $this->assertFalse(WindowsDispatcherActivator::isPendingRestart($this->tmpRoot, '8000'));
    }

    public function testIsPendingRestartFalseWhenLauncherFileIsMissing(): void
    {
        file_put_contents($this->tmpRoot . '/tools/http-dispatcher.php', '<?php // stub');

        $this->assertFalse(WindowsDispatcherActivator::isPendingRestart($this->tmpRoot, '8000'));
    }
}
