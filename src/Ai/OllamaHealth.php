<?php

declare(strict_types=1);

require_once __DIR__ . '/LocalProvider.php';
require_once __DIR__ . '/AiAvailability.php';

/**
 * Rövid életű, gép-helyi cache-elt elérhetőség-ellenőrzés a LocalProvider
 * elé — SZÁNDÉKOSAN külön osztály, ugyanazzal az indoklással, mint
 * ClientServerHealth: az UI-nak (ai-asszisztens.php topbar-jelvénye,
 * illetve a Beállítások AI fülének "Kapcsolat tesztelése" mellett élő
 * apró státusz-jelzése) NEM szabad minden oldalbetöltéskor egy valódi
 * hálózati hívást indítania — lásd a kör 13. pontja.
 */
final class OllamaHealth
{
    private const TTL_SECONDS = 30;

    public static function check(string $baseUrl, string $model, int $timeoutSeconds, bool $forceRefresh = false): AiAvailability
    {
        $cacheFile = self::cacheFile($baseUrl, $model);
        if (!$forceRefresh) {
            $cached = self::readCache($cacheFile);
            if ($cached !== null) {
                return $cached;
            }
        }

        $provider = new LocalProvider($baseUrl, $model, $timeoutSeconds);
        $availability = $provider->checkAvailability();
        self::writeCache($cacheFile, $availability);
        return $availability;
    }

    private static function cacheFile(string $baseUrl, string $model): string
    {
        $dir = sys_get_temp_dir() . '/stockmanager-ai-health';
        @mkdir($dir, 0775, true);
        return $dir . '/' . hash('sha256', $baseUrl . '|' . $model) . '.json';
    }

    private static function readCache(string $path): ?AiAvailability
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['checked_at_unix'], $data['status'])) {
            return null;
        }
        if ((time() - (int) $data['checked_at_unix']) > self::TTL_SECONDS) {
            return null;
        }
        return match ($data['status']) {
            'available' => AiAvailability::available(),
            'model_error' => AiAvailability::modelError((string) ($data['message'] ?? '')),
            'unavailable' => AiAvailability::unavailable((string) ($data['message'] ?? '')),
            default => null,
        };
    }

    private static function writeCache(string $path, AiAvailability $availability): void
    {
        $payload = json_encode([
            'status' => $availability->status,
            'message' => $availability->message,
            'checked_at_unix' => time(),
        ], JSON_UNESCAPED_UNICODE);
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return;
        }
        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $payload);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}
