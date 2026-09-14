<?php

require_once __DIR__ . '/AppVersion.php';

/**
 * A GitHub Release-ből származó adatok/artifact/checksum ÖSSZES
 * validációja EGY helyen — sem a manifest.json, sem maga a letöltött ZIP
 * SOSE tekinthető önmagában megbízhatónak (lásd az osztály minden egyes
 * validate*() metódusát: mindegyik egy FÜGGETLEN forrással veti össze a
 * manifest állítását, sose csak "van benne egy mező, tehát igaz"):
 *
 *  - a manifest `version`-jét a GitHub tag NEVÉVEL vetjük össze;
 *  - a manifest `commit`-ját a GitHub Git Data API-ból FÜGGETLENÜL
 *    feloldott commit-SHA-val (lásd GitHubReleaseClient::resolveTagCommitSha());
 *  - a manifest `sha256`-ját a ténylegesen letöltött fájl valódi hash-ével;
 *  - a manifest `product`-ját AppVersion::PRODUCT-tal;
 *  - a downgrade/min-verzió szabályokat AppVersion::compare()-rel.
 *
 * Az archívum-kicsomagolás (extractArchiveSafely()) a "Zip Slip" hibaosztály
 * ellen véd: path traversal (`..`), abszolút útvonal, Windows-meghajtó-
 * betűjel, szimlink-bejegyzés — egyik sem engedélyezett egyetlen ZIP-
 * bejegyzésben sem. A kicsomagolás MINDIG egy staging könyvtárba történik,
 * amit a hívó (UpdateInstaller) validál TOVÁBB, mielőtt bármi production
 * útvonalra kerülne — ez az osztály sose ír production fájlt.
 */
final class UpdateVerifier
{
    private const REQUIRED_MANIFEST_FIELDS = ['product', 'channel', 'version', 'commit', 'artifact', 'sha256', 'min_upgradable_version'];

    /** Zip-bomba elleni védelem — a valós release-csomag ennél lényegesen kisebb. */
    private const MAX_UNCOMPRESSED_TOTAL_BYTES = 300 * 1024 * 1024;
    private const MAX_ENTRY_COUNT = 20000;

    /** A kicsomagolt release-csomagban KÖTELEZŐEN jelen kell lennie ezeknek — enélkül egy hiányos/hibás artifact "sikeresként" települne. */
    private const REQUIRED_EXTRACTED_PATHS = ['webroot/index.php', 'src/Database.php', 'schema.sql', 'schema.mysql.sql'];

    /** @throws RuntimeException hiányzó vagy hibás típusú mező esetén. */
    public function validateManifestStructure(array $manifest): void
    {
        foreach (self::REQUIRED_MANIFEST_FIELDS as $field) {
            if (!isset($manifest[$field]) || !is_string($manifest[$field]) || trim($manifest[$field]) === '') {
                throw new RuntimeException("A frissítési manifest hiányos vagy érvénytelen: hiányzó \"$field\" mező.");
            }
        }
        if (!AppVersion::isValidSemver($manifest['version'])) {
            throw new RuntimeException('A manifest "version" mezője nem érvényes SemVer: ' . $manifest['version']);
        }
        if (!AppVersion::isValidSemver($manifest['min_upgradable_version'])) {
            throw new RuntimeException('A manifest "min_upgradable_version" mezője nem érvényes SemVer: ' . $manifest['min_upgradable_version']);
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $manifest['commit'])) {
            throw new RuntimeException('A manifest "commit" mezője nem egy érvényes teljes Git commit-SHA.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $manifest['sha256'])) {
            throw new RuntimeException('A manifest "sha256" mezője nem egy érvényes SHA-256 hash.');
        }
    }

    public function validateProduct(array $manifest): void
    {
        if ($manifest['product'] !== AppVersion::PRODUCT) {
            throw new RuntimeException('A manifest más terméket jelöl (' . $manifest['product'] . '), nem ' . AppVersion::PRODUCT . '-t — a frissítés megszakítva.');
        }
    }

    /**
     * A manifest `version`-jét a GitHub tag NEVÉVEL veti össze — egy `v`
     * előtag mindkét oldalon (tag-konvenció) figyelmen kívül marad. Ez zárja
     * ki, hogy egy manifest MÁST állítson magáról, mint amit a ténylegesen
     * publikált GitHub Release tagje mutat.
     */
    public function validateVersionMatchesTag(array $manifest, string $releaseTag): void
    {
        $normalizedTag = ltrim($releaseTag, 'vV');
        if (!AppVersion::isValidSemver($normalizedTag)) {
            throw new RuntimeException("A GitHub release tag (\"$releaseTag\") nem alakítható érvényes SemVer verzióvá.");
        }
        if (AppVersion::compare($manifest['version'], $normalizedTag) !== 0) {
            throw new RuntimeException("A manifest verziója ({$manifest['version']}) nem egyezik a GitHub release tag-jével ($releaseTag).");
        }
    }

    public function validateCommitMatches(array $manifest, string $resolvedCommitSha): void
    {
        if (!hash_equals(strtolower($resolvedCommitSha), strtolower($manifest['commit']))) {
            throw new RuntimeException('A manifest commit-SHA-ja nem egyezik a GitHub által a tag-hez ténylegesen feloldott commit-tal — a frissítés megszakítva.');
        }
    }

    /**
     * @throws RuntimeException ha ez downgrade lenne, VAGY a telepített
     *   verzió a manifest szerinti minimálisan frissíthető verziónél
     *   régebbi (utóbbi esetben a kliensnek előbb egy köztes release-re
     *   kell frissülnie — lásd README).
     */
    public function validateUpgradePath(string $installedVersion, array $manifest): void
    {
        if (AppVersion::isDowngrade($installedVersion, $manifest['version'])) {
            throw new RuntimeException("Downgrade nem engedélyezett: a telepített verzió ($installedVersion) újabb, mint a célverzió ({$manifest['version']}).");
        }
        if (AppVersion::compare($installedVersion, $manifest['min_upgradable_version']) < 0) {
            throw new RuntimeException(
                "A telepített verzió ($installedVersion) régebbi, mint amiről ez a release közvetlenül frissíthető " .
                "(minimum: {$manifest['min_upgradable_version']}) — előbb egy köztes release-re kell frissíteni."
            );
        }
    }

    public function verifyChecksum(string $filePath, string $expectedSha256): void
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("A letöltött artifact nem található: $filePath");
        }
        $actual = hash_file('sha256', $filePath);
        if ($actual === false) {
            throw new RuntimeException('A letöltött artifact SHA-256 hash-ének kiszámítása sikertelen.');
        }
        if (!hash_equals(strtolower($expectedSha256), strtolower($actual))) {
            throw new RuntimeException("Checksum-eltérés: a letöltött artifact SHA-256 hash-e ($actual) nem egyezik a manifestben megadottal ($expectedSha256) — a fájl sérült vagy módosított lehet, a frissítés megszakítva.");
        }
    }

    /**
     * Biztonságos ZIP-kicsomagolás $destStagingDir-be (a hívó felelőssége,
     * hogy ez egy ÚJ, kizárólag erre a célra létrehozott staging könyvtár
     * legyen — SOSE production útvonal). Minden bejegyzést a kicsomagolás
     * MEGKEZDÉSE ELŐTT validál — path traversal (`..`), abszolút útvonal
     * (unix `/...` VAGY windows `C:\...`), szimlink-bejegyzés (unix
     * external-attr módbitek alapján), null-bájt a névben — egyetlen
     * érvénytelen bejegyzés esetén a TELJES kicsomagolás elmarad
     * (semmi sem íródik ki), nem csak az adott bejegyzés marad ki csendben.
     *
     * @throws RuntimeException érvénytelen archívum vagy bármely
     *   gyanús bejegyzés esetén.
     */
    public function extractArchiveSafely(string $zipPath, string $destStagingDir): void
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException("A kicsomagolandó archívum nem található: $zipPath");
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new RuntimeException("Az archívum nem nyitható meg (hibakód: $openResult) — sérült vagy nem érvényes ZIP fájl.");
        }

        $entryCount = $zip->numFiles;
        if ($entryCount > self::MAX_ENTRY_COUNT) {
            $zip->close();
            throw new RuntimeException("Az archívum gyanúsan sok bejegyzést tartalmaz ($entryCount) — a kicsomagolás megszakítva.");
        }

        $safeEntries = [];
        $totalUncompressed = 0;

        for ($i = 0; $i < $entryCount; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                throw new RuntimeException('Az archívum egy bejegyzése nem olvasható (statIndex sikertelen).');
            }
            $name = $stat['name'];
            $totalUncompressed += (int) ($stat['size'] ?? 0);

            $this->assertSafeZipEntryName($name);

            // Szimlink-detektálás: a Unix-módbitek a külső attribútum FELSŐ
            // 16 bitjében élnek (ugyanaz a konvenció, amit a `zip`/`unzip`
            // parancssori eszközök is használnak) — S_IFLNK = 0120000.
            // SZÁNDÉKOSAN getExternalAttributesIndex()-et használunk
            // statIndex()['external_attr'] helyett — ez utóbbi ezen a
            // libzip-build-en (PHP 8.3, lásd tests/UpdateVerifierTest.php)
            // NEM adja vissza az external_attr kulcsot, ami a szimlink-
            // ellenőrzést csendben, láthatatlanul hatástalanítaná.
            $externalAttr = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $externalAttr);
            $unixMode = ($externalAttr >> 16) & 0xFFFF;
            $isSymlink = ($unixMode & 0170000) === 0120000;
            if ($isSymlink) {
                $zip->close();
                throw new RuntimeException("Az archívum szimlink-bejegyzést tartalmaz (\"$name\") — ez nem engedélyezett, a kicsomagolás megszakítva.");
            }

            $safeEntries[] = ['index' => $i, 'name' => $name, 'is_dir' => str_ends_with($name, '/')];
        }

        if ($totalUncompressed > self::MAX_UNCOMPRESSED_TOTAL_BYTES) {
            $zip->close();
            throw new RuntimeException('Az archívum kicsomagolt mérete gyanúsan nagy — a kicsomagolás megszakítva (lehetséges zip-bomba).');
        }

        @mkdir($destStagingDir, 0775, true);
        $destRoot = realpath($destStagingDir);
        if ($destRoot === false) {
            $zip->close();
            throw new RuntimeException("A staging könyvtár nem hozható létre: $destStagingDir");
        }

        // Csak MIUTÁN minden bejegyzés átment a fenti ellenőrzéseken, kezdjük
        // el ténylegesen kiírni őket — egyetlen gyanús bejegyzés se juthasson
        // el idáig félig-kicsomagolt állapotot hagyva maga után.
        foreach ($safeEntries as $entry) {
            $targetPath = $destRoot . '/' . $entry['name'];
            if ($entry['is_dir']) {
                @mkdir($targetPath, 0775, true);
                continue;
            }
            @mkdir(dirname($targetPath), 0775, true);
            $stream = $zip->getStream($entry['name']);
            if ($stream === false) {
                $zip->close();
                throw new RuntimeException("Az archívum egy bejegyzése nem olvasható ki: {$entry['name']}");
            }
            $out = @fopen($targetPath, 'wb');
            if ($out === false) {
                fclose($stream);
                $zip->close();
                throw new RuntimeException("Nem sikerült írni a kicsomagolt fájlt: $targetPath");
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
        }

        $zip->close();
    }

    /** @throws RuntimeException path traversal, abszolút útvonal, meghajtóbetűjel vagy null-bájt esetén. */
    private function assertSafeZipEntryName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new RuntimeException('Az archívum egy üres vagy érvénytelen (null-bájtot tartalmazó) bejegyzésnevet tartalmaz.');
        }
        $normalized = str_replace('\\', '/', $name);
        if (str_starts_with($normalized, '/')) {
            throw new RuntimeException("Az archívum abszolút útvonalú bejegyzést tartalmaz (\"$name\") — ez nem engedélyezett.");
        }
        if (preg_match('#^[A-Za-z]:#', $normalized)) {
            throw new RuntimeException("Az archívum Windows-meghajtóbetűjeles abszolút útvonalat tartalmaz (\"$name\") — ez nem engedélyezett.");
        }
        $segments = explode('/', $normalized);
        if (in_array('..', $segments, true)) {
            throw new RuntimeException("Az archívum path traversal (\"..\") bejegyzést tartalmaz (\"$name\") — ez nem engedélyezett.");
        }
    }

    /** @throws RuntimeException ha a kicsomagolt csomagból hiányzik bármely, az alkalmazás működéséhez feltétlenül szükséges fájl. */
    public function validateExtractedStructure(string $extractedDir): void
    {
        foreach (self::REQUIRED_EXTRACTED_PATHS as $relativePath) {
            if (!is_file(rtrim($extractedDir, '/') . '/' . $relativePath)) {
                throw new RuntimeException("A kicsomagolt release-csomagból hiányzik egy kötelező fájl: $relativePath — a telepítés megszakítva.");
            }
        }
    }
}
