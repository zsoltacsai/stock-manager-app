<?php

declare(strict_types=1);

/**
 * Windows-only, best-effort önjavítás: ha egy frissítés után a Windows
 * telepítő által korábban generált `tools/run-server-hidden.vbs` még a
 * régi, egyetlen `php -S` közvetlen indítást tartalmazza (mert a
 * telepítés a `tools/http-dispatcher.php` (PERF-01, Phase 6) bevezetése
 * ELŐTTI kiadásból lett frissítve), ezt a fájlt — KIZÁRÓLAG ezt, a
 * Feladatütemező-bejegyzést, a tűzfalat, a cron-feladatokat és az
 * ACL-eket NEM — újragenerálja úgy, hogy a már telepített
 * `tools/http-dispatcher.php`-t indítsa, ugyanazzal a host:port címmel,
 * amit az operátor eredetileg megadott.
 *
 * Ez biztonságos és nem igényel emelt jogosultságot, mert a
 * Feladatütemező regisztrált Action-je (`wscript.exe //B //NoLogo
 * "...\tools\run-server-hidden.vbs"`) a dispatcher bevezetése ELŐTT és
 * UTÁN is BYTE-AZONOS (lásd `install-windows.ps1`) — kizárólag e fájl
 * TARTALMA dönti el, melyik php.exe-parancs fut ténylegesen. A fájl már
 * eleve a telepítő fiókja által írható (ugyanaz a fiók futtatja ezt az
 * önfrissítést is, lásd `UpdateInstaller`), tehát egy sima fájlírásnál
 * nem kell sem a Feladatütemező API-t hívni, sem újra-elevationt kérni.
 *
 * FONTOS: a hatás csak a kiszolgáló folyamat KÖVETKEZŐ tényleges
 * (újra)indulásakor (reboot / bejelentkezés / összeomlás utáni
 * automatikus újraindítás) érvényesül — ez az osztály önmagában nem
 * állítja le/indítja újra a jelenleg futó folyamatot (az request közben
 * fut, amit ez a kérés éppen kiszolgál). Lásd `system-status.php`
 * `dispatcher_pending_restart` mezőjét a láthatóságért.
 */
final class WindowsDispatcherActivator
{
    /**
     * @return bool true, ha ténylegesen regenerálta a launcher-fájlt.
     */
    public static function activateIfNeeded(string $appRoot): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        $dispatcherScript = $appRoot . '/tools/http-dispatcher.php';
        if (!is_file($dispatcherScript)) {
            return false; // ez a kiadás még nem tartalmazza a dispatchert
        }

        $vbsPath = $appRoot . '/tools/run-server-hidden.vbs';
        if (!is_file($vbsPath)) {
            return false; // nincs telepítő-kezelt szerver-launcher ezen a gépen
        }

        $content = self::readLauncherText($vbsPath);
        if ($content === null) {
            return false;
        }
        if (str_contains($content, 'http-dispatcher.php')) {
            return false; // már aktív — idempotens no-op
        }

        $parsed = self::parseLegacyLauncher($content);
        if ($parsed === null) {
            return false; // ismeretlen/nem felismert formátum — biztonságosabb nem hozzányúlni
        }
        [$exePath, $hostPort, $webroot] = $parsed;

        $newArguments = sprintf(
            '"%s" --listen=%s --webroot="%s" --workers=3 --background-workers=1',
            $dispatcherScript,
            $hostPort,
            $webroot
        );

        return self::writeLauncher($vbsPath, $exePath, $newArguments);
    }

    /**
     * Best-effort jelzés: a jelenleg futó kiszolgáló folyamat MÉG nem a
     * launcher-fájlban beállított címen fut-e. Erre akkor kerülhet sor, ha
     * a launcher régi (`php -S`) tartalmat ír elő, vagy ha már a
     * dispatcher-tartalmat írja elő, de a kiszolgáló folyamat a frissítés
     * óta még nem indult újra (lásd az osztály docblokkja — a
     * Feladatütemező e fájlt csak a KÖVETKEZŐ tényleges induláskor olvassa
     * újra). Mindkét esetben a jelenlegi kérést kiszolgáló PHP-folyamat
     * saját portja (`$_SERVER['SERVER_PORT']`) megegyezik a launcherben
     * rögzített fő porttal — diszpécser-üzemben ugyanis az alkalmazás-
     * kódot ténylegesen egy háttér-worker (más port) szolgálja ki, a
     * diszpécser maga sosem futtat PHP-alkalmazáskódot.
     */
    public static function isPendingRestart(string $appRoot, ?string $currentServerPort = null): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }
        if (!is_file($appRoot . '/tools/http-dispatcher.php')) {
            return false;
        }
        $vbsPath = $appRoot . '/tools/run-server-hidden.vbs';
        if (!is_file($vbsPath)) {
            return false;
        }
        $content = self::readLauncherText($vbsPath);
        if ($content === null) {
            return false;
        }
        if (!preg_match('/(?:-S\s+|--listen=)([0-9A-Za-z_.\-]+:([0-9]+))/u', $content, $m)) {
            return false;
        }
        $configuredPort = $m[2];
        $currentServerPort ??= (string) ($_SERVER['SERVER_PORT'] ?? '');
        return $currentServerPort !== '' && $currentServerPort === $configuredPort;
    }

    private static function readLauncherText(string $vbsPath): ?string
    {
        $raw = @file_get_contents($vbsPath);
        if ($raw === false || $raw === '') {
            return null;
        }
        // A New-HiddenLauncherVbs (install-windows-lib.ps1) UTF-16LE + BOM
        // formátumban ír — a klasszikus VBScript-motor emiatt nem ismerné
        // fel megbízhatóan az UTF-8-at.
        if (strncmp($raw, "\xFF\xFE", 2) !== 0) {
            return null;
        }
        $decoded = @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        return $decoded !== false ? $decoded : null;
    }

    /** @return array{0:string,1:string,2:string}|null */
    private static function parseLegacyLauncher(string $content): ?array
    {
        if (!preg_match('/cmdLine\s*=\s*"((?:[^"]|"")*)"\s*&\s*"\s*"\s*&\s*"((?:[^"]|"")*)"/u', $content, $m)) {
            return null;
        }
        $exePath = str_replace('""', '"', $m[1]);
        $arguments = str_replace('""', '"', $m[2]);

        // Régi (dispatcher előtti) minta: -S <host>:<port> -t "<webroot>"
        if (!preg_match('/-S\s+(\S+)\s+-t\s+"([^"]+)"/u', $arguments, $am)) {
            return null;
        }
        return [$exePath, $am[1], $am[2]];
    }

    private static function writeLauncher(string $vbsPath, string $exePath, string $arguments): bool
    {
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
        $utf16 = @mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        if ($utf16 === false) {
            return false;
        }
        return @file_put_contents($vbsPath, "\xFF\xFE" . $utf16) !== false;
    }
}
