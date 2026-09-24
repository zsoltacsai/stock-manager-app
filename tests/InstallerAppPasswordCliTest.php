<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Biztonsági audit F-01 — tools/installer-set-app-password.php, a Szerver
 * szerepkörű telepítés alkalmazás-jelszavának beállítása. Valódi CLI-
 * alfolyamatként, izolált ideiglenes könyvtárban (sose az éles data/-ban).
 * A jelszó KIZÁRÓLAG stdin-en érkezik.
 */
final class InstallerAppPasswordCliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sm_app_password_cli_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0775, true);
        mkdir($this->root . '/tools', 0775, true);
        mkdir($this->root . '/data', 0775, true);
        copy(dirname(__DIR__) . '/src/Settings.php', $this->root . '/src/Settings.php');
        copy(dirname(__DIR__) . '/tools/installer-set-app-password.php', $this->root . '/tools/installer-set-app-password.php');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/{data,src,tools}/*', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        foreach (['data', 'src', 'tools'] as $d) {
            @rmdir($this->root . '/' . $d);
        }
        @rmdir($this->root);
    }

    /** @return array{exitCode:int, json:?array} */
    private function runTool(array $args, ?string $stdin = null): array
    {
        $cmd = [PHP_BINARY, $this->root . '/tools/installer-set-app-password.php'];
        foreach ($args as $a) {
            $cmd[] = $a;
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        fwrite($pipes[0], (string) $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        $json = json_decode(trim((string) $stdout), true);
        return ['exitCode' => $exitCode, 'json' => is_array($json) ? $json : null];
    }

    private function storedSettings(): array
    {
        return json_decode((string) file_get_contents($this->root . '/data/settings.json'), true);
    }

    public function testStatusOnFreshInstallReportsNoPassword(): void
    {
        $res = $this->runTool(['--action=status']);
        $this->assertSame(0, $res['exitCode']);
        $this->assertSame(['ok' => true, 'has_password' => false], $res['json']);
    }

    public function testSetFromStdinStoresVerifiableHashAndEnablesProtection(): void
    {
        $res = $this->runTool(['--action=set'], "Szerver-Jelszó-ÁÉ1\r\n");
        $this->assertSame(0, $res['exitCode']);
        $this->assertSame(['ok' => true, 'changed' => true], $res['json']);

        $stored = $this->storedSettings();
        $this->assertTrue($stored['app_password_enabled']);
        $this->assertTrue(password_verify('Szerver-Jelszó-ÁÉ1', $stored['app_password_hash']), 'A záró sortörés nem lehet a jelszó része; az UTF-8 ékezetes karaktereknek meg kell maradniuk.');
        $this->assertStringNotContainsString('Szerver-Jelszó', (string) file_get_contents($this->root . '/data/settings.json'));
        $this->assertSame(['ok' => true, 'has_password' => true], $this->runTool(['--action=status'])['json']);
    }

    public function testBase64StdinModeUsedByTheInstallerSurvivesBomAndPreservesUtf8(): void
    {
        $encoded = "\xEF\xBB\xBF" . base64_encode('Árvíztűrő-Jelszó-9') . "\r\n";
        $res = $this->runTool(['--action=set', '--stdin=base64'], $encoded);

        $this->assertSame(['ok' => true, 'changed' => true], $res['json']);
        $this->assertTrue(password_verify('Árvíztűrő-Jelszó-9', $this->storedSettings()['app_password_hash']));
    }

    public function testInvalidBase64IsRejectedAndNothingIsStored(): void
    {
        $res = $this->runTool(['--action=set', '--stdin=base64'], "ez nem base64!!\n");
        $this->assertSame(1, $res['exitCode']);
        $this->assertFileDoesNotExist($this->root . '/data/settings.json');
    }

    public function testRerunWithoutForceNeverOverwritesExistingPassword(): void
    {
        $this->runTool(['--action=set'], "elso-jelszo-123\n");
        $res = $this->runTool(['--action=set'], "masik-jelszo-456\n");

        $this->assertSame(['ok' => true, 'changed' => false], $res['json']);
        $this->assertTrue(password_verify('elso-jelszo-123', $this->storedSettings()['app_password_hash']));
    }

    public function testForceResetsPasswordForDocumentedRecovery(): void
    {
        $this->runTool(['--action=set'], "elso-jelszo-123\n");
        $res = $this->runTool(['--action=set', '--force'], "uj-helyreallitott-jelszo\n");

        $this->assertSame(['ok' => true, 'changed' => true], $res['json']);
        $this->assertTrue(password_verify('uj-helyreallitott-jelszo', $this->storedSettings()['app_password_hash']));
    }

    public function testTooShortPasswordIsRejectedAndNothingIsStored(): void
    {
        $res = $this->runTool(['--action=set'], "rovid\n");
        $this->assertSame(1, $res['exitCode']);
        $this->assertFalse($res['json']['ok']);
        $this->assertFileDoesNotExist($this->root . '/data/settings.json');
    }

    public function testUnknownActionFails(): void
    {
        $res = $this->runTool(['--action=disable']);
        $this->assertSame(1, $res['exitCode']);
        $this->assertFalse($res['json']['ok']);
    }
}
