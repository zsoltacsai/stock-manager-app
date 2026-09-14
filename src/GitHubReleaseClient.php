<?php

/**
 * A FountainTrade önfrissítő rendszerének EGYETLEN kapcsolata a külvilággal
 * (lásd README "Önfrissítés" szakasza) — kizárólag a config/config.php-ban
 * rögzített (NEM a Beállítások alól admin által módosítható) GitHub
 * repository-t szólítja meg, kizárólag olvasó (GET) hívásokkal.
 *
 * SSRF-védelem: a metaadat-hívások (releases/latest, git/ref/tags/...) MINDIG
 * a hardcodolt api.github.com host felé mennek, sose egy máshonnan
 * (manifest/release JSON) származó URL-lel — ott nincs SSRF-felület. Az
 * egyetlen hely, ahol egy KÜLSŐ forrásból (a GitHub API válaszából) jövő URL
 * felé indul kapcsolat, a downloadAsset() — ott EZÉRT explicit
 * host-fehérlista védi mind a kezdeti, mind az átirányítás UTÁNI tényleges
 * célt (lásd isAllowedDownloadHost()), ugyanazzal az elvvel, mint
 * UrlSafety::check() a WooCommerce/webhook-URL-eknél, csak itt fix
 * GitHub-hosztokra szűkítve (nem "bármilyen publikus IP", mert itt KONKRÉTAN
 * tudjuk, mi az egyetlen legitim cél).
 */
/**
 * SZÁNDÉKOSAN NEM `final` — a tesztek (lásd tests/UpdateInstallerTest.php
 * FakeGitHubReleaseClient-je) ebből származtatva cserélik le a hálózati
 * hívásokat helyi fixture-fájlokra, hogy az UpdateInstaller teljes
 * pipeline-ja valódi hálózati hívás nélkül, mégis a valódi osztály
 * felületén keresztül tesztelhető legyen.
 */
class GitHubReleaseClient
{
    private const API_BASE = 'https://api.github.com';
    private const USER_AGENT = 'FountainTrade-UpdateClient';

    /**
     * A GitHub release-asset letöltés jellemzően a github.com
     * /releases/download/... útvonalról egy aláírt, objects.githubusercontent.com
     * alatti URL-re irányít át — mindkettő legitim, VALÓDI GitHub-
     * infrastruktúra, semmi más nem engedélyezett.
     */
    private const ALLOWED_ASSET_HOSTS = ['github.com', 'objects.githubusercontent.com', 'api.github.com'];

    private string $owner;
    private string $repo;

    /** @var callable(string): array{status:int, body:string} */
    private $httpGetJson;

    public function __construct(string $owner, string $repo, ?callable $httpGetJson = null)
    {
        $this->owner = $owner;
        $this->repo = $repo;
        $this->httpGetJson = $httpGetJson ?? [$this, 'defaultHttpGet'];
    }

    /**
     * A GitHub `/releases/latest` végpontja MAGA is kizárja a draft/
     * prerelease jelölésű release-eket (ez a "stable" csatorna tényleges
     * forrása) — nincs szükség saját szűrésre, a GitHub API-ra bízzuk.
     *
     * @return array a nyers GitHub release JSON (tag_name, name, body,
     *   published_at, target_commitish, assets[] stb.)
     */
    public function fetchLatestRelease(): array
    {
        $url = self::API_BASE . '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/releases/latest';
        $response = ($this->httpGetJson)($url);
        $this->assertSuccessfulJson($response, 'A GitHub Release-lista lekérdezése sikertelen');

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['tag_name'])) {
            throw new RuntimeException('A GitHub Release válasza érvénytelen vagy hiányos (hiányzó tag_name).');
        }
        return $data;
    }

    /**
     * A release JSON `target_commitish` mezője NEM megbízható commit-SHA —
     * jellemzően csak egy branch-nevet ad (pl. "main"), ami AZÓTA
     * tovább is mozoghatott. A tényleges, a tag pillanatában rögzített
     * commit-ot a Git-referencia API-n keresztül, FÜGGETLENÜL oldjuk fel —
     * ez a manifest.json `commit` mezőjének kereszt-ellenőrzési forrása
     * (lásd UpdateVerifier), NEM magából a manifestből vesszük készpénznek.
     */
    public function resolveTagCommitSha(string $tag): string
    {
        $url = self::API_BASE . '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/git/ref/tags/' . rawurlencode($tag);
        $response = ($this->httpGetJson)($url);
        $this->assertSuccessfulJson($response, "A(z) \"$tag\" tag Git-referenciájának feloldása sikertelen");

        $data = json_decode($response['body'], true);
        $objectType = $data['object']['type'] ?? null;
        $objectSha = $data['object']['sha'] ?? null;
        if (!is_array($data) || !$objectType || !$objectSha) {
            throw new RuntimeException("A(z) \"$tag\" tag GitHub-referenciája érvénytelen vagy hiányos.");
        }

        if ($objectType === 'commit') {
            return $objectSha;
        }

        // Annotált tag: a fenti objektum egy KÜLÖN tag-objektumra mutat,
        // aminek megvan a saját, tényleges commit-referenciája — ez a
        // különbség lightweight és annotált tag között a Git Data API-ban.
        if ($objectType === 'tag') {
            $tagUrl = self::API_BASE . '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/git/tags/' . rawurlencode($objectSha);
            $tagResponse = ($this->httpGetJson)($tagUrl);
            $this->assertSuccessfulJson($tagResponse, "A(z) \"$tag\" annotált tag feloldása sikertelen");
            $tagData = json_decode($tagResponse['body'], true);
            $commitSha = $tagData['object']['sha'] ?? null;
            if (!is_array($tagData) || !$commitSha) {
                throw new RuntimeException("A(z) \"$tag\" annotált tag commit-referenciája érvénytelen.");
            }
            return $commitSha;
        }

        throw new RuntimeException("A(z) \"$tag\" tag váratlan GitHub objektum-típusra mutat: $objectType");
    }

    public static function findAsset(array $release, string $name): ?array
    {
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === $name) {
                return $asset;
            }
        }
        return null;
    }

    /**
     * Tisztán, hálózati hívás nélkül tesztelhető házőrző — sem a manifest,
     * sem a release JSON semmilyen mezője nem befolyásolhatja ezt a
     * döntést, csak ez a fix lista (lásd az osztály docblockját).
     */
    public static function isAllowedDownloadHost(string $host): bool
    {
        return in_array(strtolower($host), self::ALLOWED_ASSET_HOSTS, true);
    }

    /**
     * Letölti $url tartalmát $destPath-ba — a hívó felelőssége, hogy
     * $destPath egy STAGING (SOSE production) útvonal legyen (lásd
     * UpdateVerifier/UpdateInstaller). Követi az átirányítást (a GitHub
     * asset-letöltés jellemzően objects.githubusercontent.com-ra irányít
     * át), DE mind a kezdeti, mind a ténylegesen elért végleges URL hosztját
     * az isAllowedDownloadHost() fehérlistájával ellenőrzi — csak HTTPS,
     * csak a valódi GitHub-infrastruktúra.
     */
    public function downloadAsset(string $url, string $destPath): void
    {
        $initialHost = parse_url($url, PHP_URL_HOST);
        if (!$initialHost || !self::isAllowedDownloadHost($initialHost)) {
            throw new RuntimeException("Nem engedélyezett letöltési cím (host: " . ($initialHost ?: '?') . ').');
        }

        $fh = @fopen($destPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException("Nem sikerült írni az ideiglenes letöltési fájlt: $destPath");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fh,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT         => 120,
            CURLOPT_HTTPHEADER      => ['User-Agent: ' . self::USER_AGENT],
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        fclose($fh);

        $effectiveHost = parse_url($effectiveUrl, PHP_URL_HOST) ?: '';

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($destPath);
            throw new RuntimeException("Az artifact letöltése sikertelen (HTTP $status" . ($err !== '' ? ", $err" : '') . ').');
        }
        if (!self::isAllowedDownloadHost($effectiveHost)) {
            // Ez elvileg sose fordulhat elő (a kezdeti host már ellenőrzött,
            // GitHub sose irányít át máshova), de védelmi mélységként a
            // TÉNYLEGESEN elért hosztot is ellenőrizzük, mielőtt a fájlt
            // megbízhatónak tekintenénk.
            @unlink($destPath);
            throw new RuntimeException("Az átirányítás nem engedélyezett hosztra mutatott: $effectiveHost");
        }
    }

    private function assertSuccessfulJson(array $response, string $context): void
    {
        if ($response['status'] === 0) {
            throw new RuntimeException("$context: hálózati hiba (elérhetetlen host, DNS-hiba vagy időtúllépés).");
        }
        if ($response['status'] === 404) {
            throw new RuntimeException("$context: a repository vagy a release nem található (404) — ellenőrizd a beállított GitHub repository nevét, vagy hogy létezik-e publikált (nem draft/prerelease) release.");
        }
        if ($response['status'] >= 500) {
            throw new RuntimeException("$context: a GitHub jelenleg nem elérhető (HTTP {$response['status']}).");
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("$context: váratlan HTTP státusz ({$response['status']}).");
        }
        if (json_decode($response['body']) === null && trim((string) $response['body']) !== 'null') {
            throw new RuntimeException("$context: érvénytelen JSON-válasz.");
        }
    }

    /** @return array{status:int, body:string} status=0 jelöli a hálózati (kapcsolódási/DNS/timeout) hibát. */
    private function defaultHttpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::USER_AGENT, 'Accept: application/vnd.github+json'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            curl_close($ch);
            return ['status' => 0, 'body' => ''];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }
}
