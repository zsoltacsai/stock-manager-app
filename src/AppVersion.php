<?php

/**
 * A FountainTrade telepített verziójának EGYETLEN központi forrása — minden
 * más helynek (system-status.php app_version mezője, az update-rendszer
 * downgrade-ellenőrzése, a release-verifikáció) ebből kell olvasnia, sose
 * saját, külön hardcodolt string-ből. A webroot/login.html lábléce
 * KIVÉTEL marad (lásd ott a docblockot) — az bejelentkezés ELŐTTI, statikus
 * oldal, nem tud biztonságosan egy hitelesített API-végponttól függeni,
 * ezért ott a verziószámot változatlanul kézzel kell szinkronban tartani
 * ezzel a fájllal minden kiadáskor.
 */
final class AppVersion
{
    public const PRODUCT = 'FountainTrade';

    /** SemVer (major.minor.patch) — ez a ténylegesen telepített kód verziója. */
    public const CURRENT = '1.4.1';

    /** Alapértelmezett update-csatorna — jelenleg csak 'stable' létezik. */
    public const DEFAULT_CHANNEL = 'stable';

    private const SEMVER_PATTERN = '/^(\d+)\.(\d+)\.(\d+)$/';

    public static function isValidSemver(string $version): bool
    {
        return preg_match(self::SEMVER_PATTERN, trim($version)) === 1;
    }

    /**
     * @return array{0:int,1:int,2:int}
     * @throws InvalidArgumentException ha $version nem érvényes SemVer.
     */
    public static function parse(string $version): array
    {
        if (!preg_match(self::SEMVER_PATTERN, trim($version), $m)) {
            throw new InvalidArgumentException("Érvénytelen verziószám (nem SemVer): $version");
        }
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * Klasszikus háromutas összehasonlítás: negatív, ha $a < $b; pozitív, ha
     * $a > $b; 0, ha egyenlő. Mindkét oldal érvényes SemVer kell legyen —
     * a hívó felelőssége az isValidSemver() előzetes ellenőrzés (lásd
     * UpdateVerifier — egy érvénytelen manifest-verzió sose jusson el idáig
     * csendben "egyenlőként" kezelve).
     */
    public static function compare(string $a, string $b): int
    {
        [$aMaj, $aMin, $aPat] = self::parse($a);
        [$bMaj, $bMin, $bPat] = self::parse($b);
        return [$aMaj, $aMin, $aPat] <=> [$bMaj, $bMin, $bPat];
    }

    public static function isDowngrade(string $installed, string $target): bool
    {
        return self::compare($target, $installed) < 0;
    }
}
