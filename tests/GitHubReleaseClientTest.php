<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GitHubReleaseClient.php';

use PHPUnit\Framework\TestCase;

final class GitHubReleaseClientTest extends TestCase
{
    private function clientWithTransport(callable $transport): GitHubReleaseClient
    {
        return new GitHubReleaseClient('zsoltacsai', 'stock-manager-app', $transport);
    }

    // ---- fetchLatestRelease ----

    public function testFetchLatestReleaseParsesValidResponse(): void
    {
        $client = $this->clientWithTransport(function (string $url) {
            $this->assertStringContainsString('/repos/zsoltacsai/stock-manager-app/releases/latest', $url);
            return ['status' => 200, 'body' => json_encode(['tag_name' => 'v1.1.0', 'body' => 'notes', 'assets' => []])];
        });
        $release = $client->fetchLatestRelease();
        $this->assertSame('v1.1.0', $release['tag_name']);
    }

    public function testFetchLatestReleaseThrowsOnNetworkFailure(): void
    {
        $client = $this->clientWithTransport(fn() => ['status' => 0, 'body' => '']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/hálózati hiba/i');
        $client->fetchLatestRelease();
    }

    public function testFetchLatestReleaseThrowsOn404(): void
    {
        $client = $this->clientWithTransport(fn() => ['status' => 404, 'body' => '{}']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nem található/i');
        $client->fetchLatestRelease();
    }

    public function testFetchLatestReleaseThrowsOn5xx(): void
    {
        $client = $this->clientWithTransport(fn() => ['status' => 503, 'body' => '']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nem elérhető/i');
        $client->fetchLatestRelease();
    }

    public function testFetchLatestReleaseThrowsOnInvalidJson(): void
    {
        $client = $this->clientWithTransport(fn() => ['status' => 200, 'body' => 'not json{{{']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/JSON/');
        $client->fetchLatestRelease();
    }

    public function testFetchLatestReleaseThrowsWhenTagNameMissing(): void
    {
        $client = $this->clientWithTransport(fn() => ['status' => 200, 'body' => json_encode(['name' => 'Release'])]);
        $this->expectException(RuntimeException::class);
        $client->fetchLatestRelease();
    }

    // ---- resolveTagCommitSha ----

    public function testResolveTagCommitShaHandlesLightweightTag(): void
    {
        $sha = str_repeat('a', 40);
        $client = $this->clientWithTransport(fn() => ['status' => 200, 'body' => json_encode(['object' => ['type' => 'commit', 'sha' => $sha]])]);
        $this->assertSame($sha, $client->resolveTagCommitSha('v1.1.0'));
    }

    public function testResolveTagCommitShaHandlesAnnotatedTag(): void
    {
        $tagObjectSha = str_repeat('b', 40);
        $commitSha = str_repeat('a', 40);
        $calls = 0;
        $client = $this->clientWithTransport(function (string $url) use (&$calls, $tagObjectSha, $commitSha) {
            $calls++;
            if ($calls === 1) {
                $this->assertStringContainsString('/git/ref/tags/', $url);
                return ['status' => 200, 'body' => json_encode(['object' => ['type' => 'tag', 'sha' => $tagObjectSha]])];
            }
            $this->assertStringContainsString('/git/tags/' . $tagObjectSha, $url);
            return ['status' => 200, 'body' => json_encode(['object' => ['type' => 'commit', 'sha' => $commitSha]])];
        });
        $this->assertSame($commitSha, $client->resolveTagCommitSha('v1.1.0'));
        $this->assertSame(2, $calls);
    }

    public function testResolveTagCommitShaThrowsOnUnexpectedObjectType(): void
    {
        $client = $this->clientWithTransport(fn() => ['status' => 200, 'body' => json_encode(['object' => ['type' => 'blob', 'sha' => str_repeat('a', 40)]])]);
        $this->expectException(RuntimeException::class);
        $client->resolveTagCommitSha('v1.1.0');
    }

    // ---- findAsset ----

    public function testFindAssetLocatesByExactName(): void
    {
        $release = ['assets' => [['name' => 'manifest.json', 'browser_download_url' => 'https://x'], ['name' => 'fountaintrade-1.1.0.zip', 'browser_download_url' => 'https://y']]];
        $asset = GitHubReleaseClient::findAsset($release, 'fountaintrade-1.1.0.zip');
        $this->assertSame('https://y', $asset['browser_download_url']);
    }

    public function testFindAssetReturnsNullWhenMissing(): void
    {
        $this->assertNull(GitHubReleaseClient::findAsset(['assets' => []], 'missing.zip'));
    }

    // ---- SSRF host-allowlist (a tényleges biztonsági határ — tiszta, hálózat nélküli teszt) ----

    public function testIsAllowedDownloadHostAcceptsRealGitHubInfrastructure(): void
    {
        $this->assertTrue(GitHubReleaseClient::isAllowedDownloadHost('github.com'));
        $this->assertTrue(GitHubReleaseClient::isAllowedDownloadHost('objects.githubusercontent.com'));
        $this->assertTrue(GitHubReleaseClient::isAllowedDownloadHost('api.github.com'));
        $this->assertTrue(GitHubReleaseClient::isAllowedDownloadHost('GITHUB.COM')); // case-insensitive
    }

    public function testIsAllowedDownloadHostRejectsArbitraryHosts(): void
    {
        $this->assertFalse(GitHubReleaseClient::isAllowedDownloadHost('evil.com'));
        $this->assertFalse(GitHubReleaseClient::isAllowedDownloadHost('github.com.evil.com'));
        $this->assertFalse(GitHubReleaseClient::isAllowedDownloadHost('githubusercontent.com')); // hiányzó "objects." előtag
        $this->assertFalse(GitHubReleaseClient::isAllowedDownloadHost('169.254.169.254')); // felhő metaadat-cím
        $this->assertFalse(GitHubReleaseClient::isAllowedDownloadHost('localhost'));
        $this->assertFalse(GitHubReleaseClient::isAllowedDownloadHost('127.0.0.1'));
    }

    public function testDownloadAssetRejectsNonAllowedHostBeforeAnyNetworkCall(): void
    {
        $client = new GitHubReleaseClient('zsoltacsai', 'stock-manager-app');
        $dest = sys_get_temp_dir() . '/ft_download_test_' . bin2hex(random_bytes(6)) . '.zip';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nem engedélyezett/i');
        try {
            $client->downloadAsset('https://evil.example.com/malware.zip', $dest);
        } finally {
            $this->assertFileDoesNotExist($dest);
            @unlink($dest);
        }
    }

    /**
     * Valódi (loopback) HTTP-kapcsolattal bizonyítja, hogy a host-ellenőrzés
     * TÉNYLEGESEN a hálózati réteget is védi, nem csak a tiszta segédfüggvény
     * szintjén létezik — egy valós, elérhető, de NEM engedélyezett hoszt
     * (127.0.0.1) felé induló letöltést is elutasít.
     */
    public function testDownloadAssetRejectsRealLocalServerNotOnAllowlist(): void
    {
        $client = new GitHubReleaseClient('zsoltacsai', 'stock-manager-app');
        $dest = sys_get_temp_dir() . '/ft_download_test_' . bin2hex(random_bytes(6)) . '.zip';
        $this->expectException(RuntimeException::class);
        try {
            // Nincs is szükség egy ténylegesen futó szerverre — a
            // host-ellenőrzés MÉG A KAPCSOLÓDÁS ELŐTT elutasítja.
            $client->downloadAsset('http://127.0.0.1:1/artifact.zip', $dest);
        } finally {
            @unlink($dest);
        }
    }
}
