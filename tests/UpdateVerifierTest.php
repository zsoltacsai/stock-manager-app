<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/UpdateVerifier.php';

use PHPUnit\Framework\TestCase;

final class UpdateVerifierTest extends TestCase
{
    private UpdateVerifier $verifier;
    /** @var string[] */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        $this->verifier = new UpdateVerifier();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/ft_update_test_' . bin2hex(random_bytes(6));
        mkdir($path, 0775, true);
        $this->tempPaths[] = $path;
        return $path;
    }

    private function tempFile(string $suffix = '.zip'): string
    {
        $path = sys_get_temp_dir() . '/ft_update_test_' . bin2hex(random_bytes(6)) . $suffix;
        $this->tempPaths[] = $path;
        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function validManifest(array $overrides = []): array
    {
        return array_merge([
            'product'                => 'FountainTrade',
            'channel'                => 'stable',
            'version'                => '1.1.0',
            'commit'                 => str_repeat('a', 40),
            'artifact'               => 'fountaintrade-1.1.0.zip',
            'sha256'                 => str_repeat('b', 64),
            'min_upgradable_version' => '1.0.0',
        ], $overrides);
    }

    // ---- Manifest structure ----

    public function testValidManifestStructurePassesWithoutException(): void
    {
        $this->verifier->validateManifestStructure($this->validManifest());
        $this->addToAssertionCount(1);
    }

    public function testManifestStructureRejectsMissingField(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['sha256']);
        $this->expectException(RuntimeException::class);
        $this->verifier->validateManifestStructure($manifest);
    }

    public function testManifestStructureRejectsInvalidVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->verifier->validateManifestStructure($this->validManifest(['version' => 'not-semver']));
    }

    public function testManifestStructureRejectsInvalidCommitShape(): void
    {
        $this->expectException(RuntimeException::class);
        $this->verifier->validateManifestStructure($this->validManifest(['commit' => 'too-short']));
    }

    public function testManifestStructureRejectsInvalidChecksumShape(): void
    {
        $this->expectException(RuntimeException::class);
        $this->verifier->validateManifestStructure($this->validManifest(['sha256' => 'not-a-hash']));
    }

    // ---- Product / repository identity ----

    public function testValidateProductAcceptsFountainTrade(): void
    {
        $this->verifier->validateProduct($this->validManifest());
        $this->addToAssertionCount(1);
    }

    public function testValidateProductRejectsWrongProduct(): void
    {
        $this->expectException(RuntimeException::class);
        $this->verifier->validateProduct($this->validManifest(['product' => 'SomeOtherApp']));
    }

    public function testValidateVersionMatchesTagAcceptsVPrefixedTag(): void
    {
        $this->verifier->validateVersionMatchesTag($this->validManifest(), 'v1.1.0');
        $this->addToAssertionCount(1);
    }

    public function testValidateVersionMatchesTagRejectsMismatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->verifier->validateVersionMatchesTag($this->validManifest(['version' => '1.1.0']), 'v1.2.0');
    }

    public function testValidateCommitMatchesAcceptsCaseInsensitiveMatch(): void
    {
        $sha = str_repeat('a', 40);
        $this->verifier->validateCommitMatches($this->validManifest(['commit' => $sha]), strtoupper($sha));
        $this->addToAssertionCount(1);
    }

    public function testValidateCommitMatchesRejectsMismatchedCommit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->verifier->validateCommitMatches($this->validManifest(['commit' => str_repeat('a', 40)]), str_repeat('c', 40));
    }

    // ---- Downgrade / min-version ----

    public function testValidateUpgradePathAllowsNormalUpgrade(): void
    {
        $this->verifier->validateUpgradePath('1.0.0', $this->validManifest(['version' => '1.1.0', 'min_upgradable_version' => '1.0.0']));
        $this->addToAssertionCount(1);
    }

    public function testValidateUpgradePathBlocksDowngrade(): void
    {
        $this->expectException(RuntimeException::class);
        $this->verifier->validateUpgradePath('1.1.0', $this->validManifest(['version' => '1.0.0', 'min_upgradable_version' => '1.0.0']));
    }

    public function testValidateUpgradePathBlocksBelowMinimumUpgradableVersion(): void
    {
        // installed = 1.0.0, target = 1.1.0, min_upgradable_version = 1.1.0 -> blocked (pontosan a kérés 10. pontjának példája)
        $this->expectException(RuntimeException::class);
        $this->verifier->validateUpgradePath('1.0.0', $this->validManifest(['version' => '1.1.0', 'min_upgradable_version' => '1.1.0']));
    }

    // ---- Checksum ----

    public function testVerifyChecksumAcceptsMatchingHash(): void
    {
        $file = $this->tempFile('.bin');
        file_put_contents($file, 'hello world');
        $this->verifier->verifyChecksum($file, hash_file('sha256', $file));
        $this->addToAssertionCount(1);
    }

    public function testVerifyChecksumRejectsMismatch(): void
    {
        $file = $this->tempFile('.bin');
        file_put_contents($file, 'hello world');
        $this->expectException(RuntimeException::class);
        $this->verifier->verifyChecksum($file, str_repeat('0', 64));
    }

    public function testVerifyChecksumRejectsMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->verifier->verifyChecksum('/nonexistent/path/file.zip', str_repeat('0', 64));
    }

    // ---- Archívum-kicsomagolás biztonsága ----

    private function buildZip(array $entries): string
    {
        $zipPath = $this->tempFile('.zip');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($entries as $entry) {
            $zip->addFromString($entry['name'], $entry['content'] ?? 'content');
            if (!empty($entry['symlink'])) {
                $mode = 0120777; // S_IFLNK | 0777
                $zip->setExternalAttributesIndex($zip->locateName($entry['name']), ZipArchive::OPSYS_UNIX, $mode << 16);
            }
        }
        $zip->close();
        return $zipPath;
    }

    public function testExtractArchiveSafelyExtractsValidArchiveCorrectly(): void
    {
        $zip = $this->buildZip([
            ['name' => 'webroot/index.php', 'content' => '<?php echo "hi";'],
            ['name' => 'src/Foo.php', 'content' => '<?php class Foo {}'],
        ]);
        $dest = $this->tempDir() . '/extracted';
        $this->tempPaths[] = $dest;

        $this->verifier->extractArchiveSafely($zip, $dest);

        $this->assertFileExists($dest . '/webroot/index.php');
        $this->assertFileExists($dest . '/src/Foo.php');
        $this->assertSame('<?php echo "hi";', file_get_contents($dest . '/webroot/index.php'));
    }

    public function testExtractArchiveSafelyRejectsPathTraversalEntry(): void
    {
        $zip = $this->buildZip([
            ['name' => 'webroot/index.php', 'content' => 'ok'],
            ['name' => '../../etc/passwd', 'content' => 'evil'],
        ]);
        $dest = $this->tempDir() . '/extracted';
        $this->tempPaths[] = $dest;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/path traversal/i');
        $this->verifier->extractArchiveSafely($zip, $dest);

        // Semmi ne kerüljön ki, ha a validáció elbukik (nem csak a gyanús bejegyzés marad ki).
        $this->assertFileDoesNotExist($dest . '/webroot/index.php');
    }

    public function testExtractArchiveSafelyRejectsAbsoluteUnixPathEntry(): void
    {
        $zip = $this->buildZip([
            ['name' => '/etc/passwd', 'content' => 'evil'],
        ]);
        $dest = $this->tempDir() . '/extracted';
        $this->tempPaths[] = $dest;

        $this->expectException(RuntimeException::class);
        $this->verifier->extractArchiveSafely($zip, $dest);
    }

    public function testExtractArchiveSafelyRejectsWindowsDriveLetterEntry(): void
    {
        $zip = $this->buildZip([
            ['name' => 'C:\\Windows\\System32\\evil.dll', 'content' => 'evil'],
        ]);
        $dest = $this->tempDir() . '/extracted';
        $this->tempPaths[] = $dest;

        $this->expectException(RuntimeException::class);
        $this->verifier->extractArchiveSafely($zip, $dest);
    }

    public function testExtractArchiveSafelyRejectsSymlinkEntry(): void
    {
        $zip = $this->buildZip([
            ['name' => 'webroot/evil-link', 'content' => '/etc/passwd', 'symlink' => true],
        ]);
        $dest = $this->tempDir() . '/extracted';
        $this->tempPaths[] = $dest;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/szimlink/i');
        $this->verifier->extractArchiveSafely($zip, $dest);
    }

    public function testExtractArchiveSafelyRejectsCorruptArchive(): void
    {
        $zip = $this->tempFile('.zip');
        file_put_contents($zip, 'not actually a zip file');
        $dest = $this->tempDir() . '/extracted';
        $this->tempPaths[] = $dest;

        $this->expectException(RuntimeException::class);
        $this->verifier->extractArchiveSafely($zip, $dest);
    }

    public function testValidateExtractedStructureAcceptsCompletePackage(): void
    {
        $dest = $this->tempDir();
        foreach (['webroot/index.php', 'src/Database.php', 'schema.sql', 'schema.mysql.sql'] as $required) {
            @mkdir(dirname($dest . '/' . $required), 0775, true);
            file_put_contents($dest . '/' . $required, 'x');
        }
        $this->verifier->validateExtractedStructure($dest);
        $this->addToAssertionCount(1);
    }

    public function testValidateExtractedStructureRejectsMissingRequiredFile(): void
    {
        $dest = $this->tempDir();
        @mkdir($dest . '/webroot', 0775, true);
        file_put_contents($dest . '/webroot/index.php', 'x');
        // src/Database.php szándékosan hiányzik

        $this->expectException(RuntimeException::class);
        $this->verifier->validateExtractedStructure($dest);
    }
}
